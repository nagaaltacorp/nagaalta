<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class ProductSellingController extends Controller
{
    public function index(Request $request)
    {
        $products = $this->productsForRequest($request);
        $payload = $products->map(fn (Product $product) => $this->productPayload($product));

        return response()->json([
            'data' => $payload->values(),
            'wholesale' => $payload
                ->map(fn (array $item) => $item['wholesale'])
                ->filter()
                ->values(),
            'retail' => $payload
                ->map(fn (array $item) => $item['retail'])
                ->filter()
                ->values(),
        ]);
    }

    public function wholesale(Request $request)
    {
        $products = $this->productsForRequest($request);

        return response()->json([
            'mode' => 'wholesale',
            'data' => $products
                ->map(fn (Product $product) => $this->modePayload($product, 'wholesale'))
                ->filter()
                ->values(),
        ]);
    }

    public function retail(Request $request)
    {
        $products = $this->productsForRequest($request);

        return response()->json([
            'mode' => 'retail',
            'data' => $products
                ->map(fn (Product $product) => $this->modePayload($product, 'retail'))
                ->filter()
                ->values(),
        ]);
    }

    private function productsForRequest(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $query = Product::query()
            ->with([
                'inventories' => function ($inventories) use ($branchId) {
                    $inventories->select([
                        'id',
                        'product_id',
                        'branch_id',
                        'quantity',
                        'retail_remainder',
                    ]);

                    if ($branchId) {
                        $inventories->where('branch_id', $branchId);
                    } else {
                        $inventories->whereNotNull('branch_id');
                    }
                },
            ])
            ->orderBy('name');

        if ($branchId) {
            $query->whereHas('inventories', function ($inventories) use ($branchId) {
                $inventories->where('branch_id', $branchId);
            });
        }

        return $query->get();
    }

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->filled('branch_id')) {
            return (int) $request->query('branch_id');
        }

        if (!$request->filled('user_id')) {
            return null;
        }

        $user = User::with([
            'employee:id,branch_id',
            'managedBranch:id',
        ])->find($request->query('user_id'));

        return $user?->employee?->branch_id
            ?? $user?->managedBranch?->id;
    }

    private function productPayload(Product $product): array
    {
        $stock = $this->stockSummary($product);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category,
            'company_name' => $product->company_name,
            'image' => $product->image,
            'description' => $product->description,
            'is_vatable' => (bool) $product->is_vatable,
            'vat_rate' => (float) $product->resolveVatRate(),
            'has_retail' => $product->usesRetailConversion(),
            'conversion' => $this->conversionLabel($product),
            'wholesale' => $this->modePayload($product, 'wholesale', $stock),
            'retail' => $this->modePayload($product, 'retail', $stock),
        ];
    }

    private function modePayload(Product $product, string $mode, ?array $stock = null): ?array
    {
        $stock ??= $this->stockSummary($product);
        $isRetail = $mode === 'retail';

        if ($isRetail && !$product->usesRetailConversion()) {
            return null;
        }

        $unit = $isRetail
            ? ($product->retail_unit ?: 'kg')
            : $product->unit_label;
        $price = $isRetail
            ? (float) $product->retail_price
            : (float) $product->price;
        $vatAmount = $this->vatAmountForPrice($product, $price);
        $available = $isRetail
            ? $stock['available_retail_quantity']
            : $stock['wholesale_quantity'];
        $name = $product->name;
        if (!$isRetail && $product->unit_size !== null && !str_contains(strtolower($name), strtolower($product->unit_label))) {
            $name .= ' ('.$product->unit_label.')';
        }

        return [
            'id' => $product->id,
            'product_id' => $product->id,
            'name' => $name,
            'category' => $product->category,
            'company_name' => $product->company_name,
            'image' => $product->image,
            'description' => $product->description,
            'mode' => $mode,
            'unit' => $unit,
            'unit_type' => $unit,
            'unit_size' => $isRetail ? null : $product->unit_size,
            'unit_label' => $unit,
            'price' => round($price, 2),
            'vat_rate' => (float) $product->resolveVatRate(),
            'vat_amount' => $vatAmount,
            'price_with_vat' => round($price + $vatAmount, 2),
            'available_quantity' => $available,
            'wholesale_unit' => $product->unit_label,
            'wholesale_quantity' => $stock['wholesale_quantity'],
            'retail_unit' => $product->retail_unit ?: 'kg',
            'retail_qty_per_unit' => $product->usesRetailConversion()
                ? (int) $product->retail_qty_per_unit
                : null,
            'retail_remainder' => $stock['retail_remainder'],
            'physical_retail_quantity' => $stock['physical_retail_quantity'],
            'allowed_loss' => $stock['allowed_loss'],
            'available_retail_quantity' => $stock['available_retail_quantity'],
            'conversion' => $this->conversionLabel($product),
            'has_retail' => $product->usesRetailConversion(),
            'allows_fractional' => $isRetail,
            'example_quantities' => $isRetail ? ['1/4', '1/2', '3/4', '1'] : ['1'],
        ];
    }

    private function stockSummary(Product $product): array
    {
        $wholesaleQuantity = (int) $product->inventories->sum('quantity');
        $retailRemainder = (float) $product->inventories->sum('retail_remainder');

        $physical = $product->physicalRetailQuantity(
            $wholesaleQuantity,
            $retailRemainder,
        );

        return [
            'wholesale_quantity' => $wholesaleQuantity,
            'retail_remainder' => $retailRemainder,
            'physical_retail_quantity' => $physical,
            'allowed_loss' => $product->allowedRetailLoss(),
            'available_retail_quantity' => $product->availableRetailQuantity(
                $wholesaleQuantity,
                $retailRemainder,
            ),
        ];
    }

    private function conversionLabel(Product $product): ?string
    {
        if (!$product->usesRetailConversion()) {
            return null;
        }

        $wholesale = $product->unit ?: 'unit';
        $retail = $product->retail_unit ?: 'kg';
        $qtyPer = (int) $product->retail_qty_per_unit;

        return "1 {$wholesale} = {$qtyPer} {$retail}";
    }

    private function vatAmountForPrice(Product $product, float $price): float
    {
        return round($price * ($product->resolveVatRate() / 100), 2);
    }
}
