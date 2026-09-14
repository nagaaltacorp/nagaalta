<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductReplacement;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Support\SaleQuantity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductReplacementService
{
    public static function windowDays(): int
    {
        return max(0, (int) (Setting::query()->value('replacement_window_days') ?? 7));
    }

    public static function setWindowDays(int $days): int
    {
        $settings = Setting::firstOrCreate([]);
        $settings->replacement_window_days = max(0, $days);
        $settings->save();

        return (int) $settings->replacement_window_days;
    }

    public static function lookupReceipt(string $saleNumber, ?int $branchId): array
    {
        $saleNumber = self::normalizeSaleNumber($saleNumber);
        $windowDays = self::windowDays();

        $sales = Sale::query()
            ->with([
                'product:id,name,category,unit,price,image,is_vatable,vat_rate,retail_enabled,retail_unit,retail_qty_per_unit,retail_price',
                'processedBy:id,name,user_name,email,employee_id',
                'processedBy.employee:id,branch_id',
            ])
            ->where('sale_number', $saleNumber)
            ->orderBy('id')
            ->get();

        if ($sales->isEmpty()) {
            throw new HttpResponseException(response()->json([
                'message' => 'No receipt found for that number.',
            ], 404));
        }

        if ($branchId !== null) {
            $receiptBranchId = self::branchIdFromSale($sales->first());

            if ($receiptBranchId !== null && $receiptBranchId !== $branchId) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This receipt was not sold at this branch.',
                ], 422));
            }
        }

        $roots = $sales->whereNull('replaces_sale_id')->values();
        $items = $roots->map(function (Sale $root) use ($sales, $windowDays) {
            $current = self::currentSale($root, $sales);
            $eligibleUntil = self::eligibleUntil($root, $windowDays);
            $canReplace = now()->lte($eligibleUntil);
            $alreadyPaid = self::alreadyPaidForSlot($root, $sales);

            return [
                'sale_id' => $current->id,
                'root_sale_id' => $root->id,
                'sale_number' => $root->sale_number,
                'product' => self::productPayload($current->product),
                'original_product' => self::productPayload($root->product),
                'was_replaced' => $current->id !== $root->id,
                'quantity' => (float) $current->quantity,
                'quantity_display' => $current->quantity_display,
                'unit_type' => $current->unit_type,
                'line_total' => (float) $current->total_price,
                'original_paid' => (float) $root->total_price,
                'already_paid' => $alreadyPaid,
                'sold_at' => optional($root->created_at)?->toIso8601String(),
                'eligible_until' => $eligibleUntil->toIso8601String(),
                'can_replace' => $canReplace,
                'ineligible_reason' => $canReplace
                    ? null
                    : 'This item is past the replacement period set by admin.',
            ];
        })->values();

        $first = $sales->first();

        return [
            'sale_number' => $saleNumber,
            'sold_at' => optional($first?->created_at)?->toIso8601String(),
            'processed_by' => $first?->processedBy?->name
                ?? $first?->processedBy?->user_name,
            'replacement_window_days' => $windowDays,
            'eligible_until' => self::eligibleUntil($first, $windowDays)->toIso8601String(),
            'items' => $items,
        ];
    }

    public static function quote(array $input, ?int $branchId): array
    {
        $current = self::saleForReplacement((int) $input['sale_id'], $input['sale_number'] ?? null, $branchId);
        $newProduct = Product::findOrFail((int) $input['new_product_id']);
        $quantity = (float) $input['quantity'];
        $unitType = self::resolveUnitType($input['unit_type'] ?? null, $newProduct);

        self::assertCanReplace($current);

        if ($newProduct->saleNeedsRetailSetup($unitType)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Set up retail for the new product before replacing into that unit.',
            ], 422));
        }

        if (!$newProduct->saleUsesRetailConversion($unitType) && !SaleQuantity::isWhole($quantity)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Wholesale replacement quantity must be a whole number.',
            ], 422));
        }

        $newGross = self::lineGross($newProduct, $quantity, $unitType);
        $alreadyPaid = self::alreadyPaidForSlot(self::rootSale($current));
        $additional = round(max(0, $newGross - $alreadyPaid), 2);

        return [
            'sale_number' => $current->sale_number,
            'sale_id' => $current->id,
            'same_product' => (int) $current->product_id === (int) $newProduct->id,
            'from' => [
                'product' => self::productPayload($current->product),
                'quantity' => (float) $current->quantity,
                'quantity_display' => $current->quantity_display,
                'unit_type' => $current->unit_type,
                'already_paid' => $alreadyPaid,
            ],
            'to' => [
                'product' => self::productPayload($newProduct),
                'quantity' => $quantity,
                'quantity_display' => SaleQuantity::display($quantity, $input['quantity_label'] ?? null),
                'unit_type' => $unitType,
                'line_total' => $newGross,
            ],
            'additional_payment' => $additional,
            'needs_additional_payment' => $additional > 0,
            'no_refund_if_cheaper' => true,
            'receipt_number' => $current->sale_number,
        ];
    }

    public static function replace(array $input, ?int $userId, ?int $branchId): array
    {
        $quote = self::quote($input, $branchId);
        $additional = (float) $quote['additional_payment'];
        $paymentMethod = $input['payment_method'] ?? null;

        if ($additional > 0 && ($paymentMethod === null || trim((string) $paymentMethod) === '')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Collect the additional payment, then send payment_method (cash, gcash, or similar).',
            ], 422));
        }

        return DB::transaction(function () use ($input, $quote, $additional, $paymentMethod, $userId, $branchId) {
            $current = Sale::query()->lockForUpdate()->findOrFail($quote['sale_id']);
            $current = self::currentSale($current);
            $current->loadMissing('product');

            if ($current->replaced_by_sale_id) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This item was already replaced. Look up the receipt again.',
                ], 422));
            }

            $newProduct = Product::findOrFail((int) $input['new_product_id']);
            $quantity = (float) $input['quantity'];
            $unitType = $quote['to']['unit_type'];
            $newGross = (float) $quote['to']['line_total'];
            $root = self::rootSale($current);

            if ($branchId === null) {
                $branchId = self::branchIdFromSale($current);
            }

            if ($branchId === null) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Cannot determine the branch for this replacement.',
                ], 422));
            }

            $oldInventory = self::lockInventory($branchId, (int) $current->product_id);
            $newInventory = (int) $current->product_id === (int) $newProduct->id
                ? $oldInventory
                : self::lockInventory($branchId, (int) $newProduct->id, true);

            if (!$oldInventory) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Cannot return the original product to branch inventory.',
                ], 422));
            }

            BranchInventoryStock::restore(
                $oldInventory,
                $current->product,
                (float) $current->quantity,
                $current->unit_type,
            );

            if (!$newInventory) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The replacement product is not in this branch inventory.',
                ], 422));
            }

            if ((int) $current->product_id === (int) $newProduct->id) {
                $newInventory->refresh();
            }

            BranchInventoryStock::decrement($newInventory, $newProduct, $quantity, $unitType);

            $newSale = Sale::create([
                'sale_number' => $current->sale_number,
                'product_id' => $newProduct->id,
                'processed_by_user_id' => $userId,
                'unit_type' => $unitType,
                'quantity' => $quantity,
                'quantity_label' => $input['quantity_label'] ?? SaleQuantity::labelFor($quantity),
                'vat_rate' => $newProduct->resolveVatRate(),
                'vat_amount' => $newProduct->vatAmountForSale($quantity, $unitType),
                'discount_percent' => 0,
                'discount_amount' => 0,
                'total_price' => $additional,
                'payment_method' => $additional > 0 ? $paymentMethod : ($current->payment_method ?? 'cash'),
                'replaces_sale_id' => $current->id,
            ]);

            $current->update(['replaced_by_sale_id' => $newSale->id]);

            $replacement = ProductReplacement::create([
                'replacement_number' => 'TMP-'.Str::upper(Str::random(12)),
                'sale_number' => $current->sale_number,
                'original_sale_id' => $current->id,
                'new_sale_id' => $newSale->id,
                'original_product_id' => $current->product_id,
                'new_product_id' => $newProduct->id,
                'original_quantity' => $current->quantity,
                'new_quantity' => $quantity,
                'original_unit_type' => $current->unit_type,
                'new_unit_type' => $unitType,
                'original_line_total' => $current->total_price,
                'new_line_total' => $newGross,
                'already_paid' => $quote['from']['already_paid'],
                'additional_payment' => $additional,
                'payment_method' => $additional > 0 ? $paymentMethod : null,
                'processed_by_user_id' => $userId,
            ]);

            $replacement->replacement_number = sprintf(
                'REP-%s-%06d',
                now()->format('Ymd'),
                (int) $replacement->id,
            );
            $replacement->save();

            $receipt = self::lookupReceipt((string) $current->sale_number, $branchId);

            return [
                'message' => $additional > 0
                    ? 'Replacement saved. Collect the additional payment and reprint the same receipt number.'
                    : 'Replacement saved. Reprint the same receipt number with the new product.',
                'replacement' => [
                    'id' => $replacement->id,
                    'replacement_number' => $replacement->replacement_number,
                    'sale_number' => $replacement->sale_number,
                    'additional_payment' => $additional,
                    'payment_method' => $replacement->payment_method,
                    'same_product' => $quote['same_product'],
                    'from' => $quote['from'],
                    'to' => $quote['to'],
                ],
                'receipt' => $receipt,
            ];
        });
    }

    public static function recent(int $limit = 30): Collection
    {
        return ProductReplacement::query()
            ->with([
                'originalProduct:id,name',
                'newProduct:id,name',
                'processedBy:id,name,user_name',
            ])
            ->latest()
            ->limit($limit)
            ->get();
    }

    private static function assertCanReplace(Sale $current): void
    {
        $root = self::rootSale($current);
        $eligibleUntil = self::eligibleUntil($root, self::windowDays());

        if (now()->gt($eligibleUntil)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This receipt is past the replacement period set by admin. Eligible until '.$eligibleUntil->toDayDateTimeString().'.',
            ], 422));
        }
    }

    private static function saleForReplacement(int $saleId, ?string $saleNumber, ?int $branchId): Sale
    {
        $sale = Sale::with(['product', 'processedBy.employee'])->find($saleId);

        if (!$sale) {
            throw new HttpResponseException(response()->json([
                'message' => 'That receipt item was not found.',
            ], 404));
        }

        $sale = self::currentSale($sale);

        if ($saleNumber) {
            $normalized = self::normalizeSaleNumber($saleNumber);

            if ($sale->sale_number !== $normalized) {
                throw new HttpResponseException(response()->json([
                    'message' => 'That item does not belong to this receipt number.',
                ], 422));
            }
        }

        if ($branchId !== null) {
            $receiptBranchId = self::branchIdFromSale($sale);

            if ($receiptBranchId !== null && $receiptBranchId !== $branchId) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This receipt was not sold at this branch.',
                ], 422));
            }
        }

        return $sale;
    }

    private static function currentSale(Sale $sale, ?Collection $ticketSales = null): Sale
    {
        $guard = 0;

        while ($sale->replaced_by_sale_id && $guard < 20) {
            $next = $ticketSales
                ? $ticketSales->firstWhere('id', $sale->replaced_by_sale_id)
                : Sale::with('product')->find($sale->replaced_by_sale_id);

            if (!$next) {
                break;
            }

            $sale = $next;
            $guard++;
        }

        $sale->loadMissing('product');

        return $sale;
    }

    private static function rootSale(Sale $sale): Sale
    {
        $guard = 0;

        while ($sale->replaces_sale_id && $guard < 20) {
            $previous = Sale::find($sale->replaces_sale_id);

            if (!$previous) {
                break;
            }

            $sale = $previous;
            $guard++;
        }

        return $sale;
    }

    private static function alreadyPaidForSlot(Sale $root, ?Collection $ticketSales = null): float
    {
        $paid = (float) $root->total_price;
        $cursor = $root;
        $guard = 0;

        while ($cursor->replaced_by_sale_id && $guard < 20) {
            $next = $ticketSales
                ? $ticketSales->firstWhere('id', $cursor->replaced_by_sale_id)
                : Sale::find($cursor->replaced_by_sale_id);

            if (!$next) {
                break;
            }

            $paid += (float) $next->total_price;
            $cursor = $next;
            $guard++;
        }

        return round($paid, 2);
    }

    private static function eligibleUntil(?Sale $sale, int $days): Carbon
    {
        $soldAt = $sale?->created_at?->copy() ?? now();

        if ($days <= 0) {
            return $soldAt->endOfDay();
        }

        return $soldAt->addDays($days);
    }

    private static function lineGross(Product $product, float $quantity, ?string $unitType): float
    {
        $subtotal = $product->unitPriceForSale($unitType) * $quantity;
        $vat = $product->vatAmountForSale($quantity, $unitType);

        return round($subtotal + $vat, 2);
    }

    private static function resolveUnitType(?string $requested, Product $product): string
    {
        $normalized = strtolower(trim((string) $requested));

        if ($normalized === '') {
            $normalized = strtolower(trim((string) $product->unit));
        }

        if (str_contains($normalized, 'kilo') || $normalized === 'kg') {
            $normalized = 'kilo';
        }

        return substr($normalized, 0, 20);
    }

    private static function lockInventory(int $branchId, int $productId, bool $required = false): ?Inventory
    {
        $inventory = Inventory::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($required && !$inventory) {
            return null;
        }

        return $inventory;
    }

    private static function branchIdFromSale(Sale $sale): ?int
    {
        $sale->loadMissing([
            'processedBy.employee:id,branch_id',
            'processedBy.managedBranch:id',
        ]);

        return $sale->processedBy?->employee?->branch_id
            ?? $sale->processedBy?->managedBranch?->id;
    }

    private static function normalizeSaleNumber(string $saleNumber): string
    {
        return substr(strtoupper(trim($saleNumber)), 0, 32);
    }

    private static function productPayload(?Product $product): ?array
    {
        if (!$product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category,
            'unit' => $product->unit,
            'image' => $product->image,
            'price' => (float) $product->price,
            'retail_enabled' => (bool) $product->retail_enabled,
            'retail_unit' => $product->retail_unit,
            'retail_price' => $product->retail_price !== null ? (float) $product->retail_price : null,
        ];
    }
}
