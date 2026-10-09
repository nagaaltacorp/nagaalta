<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CartDiscount;
use App\Services\DailySalesReportService;
use App\Services\ManagerBranchScope;
use App\Services\SalesReportExport;
use App\Support\SaleQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $sales = ManagerBranchScope::scopeSales(
            Sale::with([
                'product',
                'processedBy:id,name,user_name,email',
                'processedBy.employee.branch:id,name,location',
            ]),
            $request->user(),
        )
            ->latest()
            ->get();

        return response()->json(['data' => $sales]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            abort(404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'payment' => ['nullable', 'in:all,cash,utang'],
        ]);

        $sales = $this->salesForReport($request, $validated);
        $stamp = now()->timezone(DailySalesReportService::TIMEZONE)->format('Ymd-Hi');

        if ($format === 'pdf') {
            return response(SalesReportExport::pdf($sales, $validated), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="sales-report-'.$stamp.'.pdf"',
            ]);
        }

        return response(SalesReportExport::excel($sales), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="sales-report-'.$stamp.'.xlsx"',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateSalePayload($request);
        [$sale, $created] = $this->createSale($request, $validated);

        return response()->json([
            'message' => $created
                ? 'Sale processed successfully.'
                : 'Sale already processed.',
            'data' => $sale,
        ], $created ? 201 : 200);
    }

    public function storeFromFlutter(Request $request)
    {
        $validated = $this->validateSalePayload($request);
        [$sale, $created] = $this->createSale($request, $validated, true);

        return response()->json([
            'message' => $created
                ? 'Flutter sale saved successfully.'
                : 'Flutter sale already processed.',
            'sale_number' => $sale->sale_number,
            'data' => $sale,
        ], $created ? 201 : 200);
    }

    public function historyFromFlutter(Request $request)
    {
        // Allow unauthenticated access if branch_id is provided
        if (!$request->user() && !$request->has('branch_id')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'sale_number' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'payment_method' => ['nullable', 'string', 'max:20'],
        ]);

        $query = Sale::with([
            'product:id,name,price,unit,category,image,is_vatable,vat_rate,retail_enabled,retail_unit,retail_qty_per_unit,retail_price',
            'processedBy:id,name,user_name,email,employee_id',
            'processedBy.employee:id,branch_id,first_name,last_name',
            'processedBy.employee.branch:id,name,location',
        ])->latest();

        // Filter by branch if provided
        if (isset($validated['branch_id'])) {
            $branchId = $validated['branch_id'];
            $query->whereHas('processedBy.employee', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        // Filter by payment method if provided
        if (isset($validated['payment_method'])) {
            $paymentMethod = trim((string) $validated['payment_method']);
            if ($paymentMethod !== '') {
                $query->where('payment_method', $paymentMethod);
            }
        }

        if (isset($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        if (isset($validated['sale_number'])) {
            $saleNumber = trim((string) $validated['sale_number']);

            if ($saleNumber !== '') {
                $query->where('sale_number', substr($saleNumber, 0, 32));
            }
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $validated['from']));
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $validated['to']));
        }

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 200;
        $sales = $query->limit($limit)->get();

        return response()->json(['data' => $sales]);
    }

    private function salesForReport(Request $request, array $filters)
    {
        $query = ManagerBranchScope::scopeSales(
            Sale::with([
                'product',
                'processedBy:id,name,user_name,email',
            ])->collected(),
            $request->user(),
        );

        $timezone = DailySalesReportService::TIMEZONE;

        if (! empty($filters['from'])) {
            $from = Carbon::parse((string) $filters['from'], $timezone)->startOfDay();
            $query->whereRaw(Sale::recognizedAtSql().' >= ?', [$from]);
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse((string) $filters['to'], $timezone)->endOfDay();
            $query->whereRaw(Sale::recognizedAtSql().' <= ?', [$to]);
        }

        $payment = (string) ($filters['payment'] ?? 'all');

        if ($payment === 'utang') {
            $query->whereRaw('LOWER(sales.payment_method) = ?', ['utang']);
        } elseif ($payment === 'cash') {
            $query->where(function ($cash) {
                $cash
                    ->whereNull('sales.payment_method')
                    ->orWhereRaw('LOWER(sales.payment_method) != ?', ['utang']);
            });
        }

        $sales = $query->latest()->get();
        $keyword = strtolower(trim((string) ($filters['search'] ?? '')));

        if ($keyword === '') {
            return $sales;
        }

        return $sales->filter(function (Sale $sale) use ($keyword) {
            $recognized = $sale->recognizedAt()?->timezone(DailySalesReportService::TIMEZONE);
            $haystack = strtolower(implode(' ', array_filter([
                $sale->sale_number,
                $sale->product?->name,
                $sale->unit_type,
                $sale->product?->unit,
                $sale->quantity_display,
                number_format((float) $sale->total_price, 2, '.', ''),
                (float) $sale->discount_percent > 0
                    ? number_format((float) $sale->discount_percent, 2, '.', '').'%'
                    : '',
                $sale->processedBy?->name,
                $sale->processedBy?->user_name,
                $sale->processedBy?->email,
                $sale->payment_method,
                $recognized?->format('M j, Y g:i A'),
                $recognized?->format('Y-m-d'),
            ])));

            return str_contains($haystack, $keyword);
        })->values();
    }

    private function validateSalePayload(Request $request): array
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required'],
            'processed_by_user_id' => ['nullable', 'exists:users,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'unit_type' => ['nullable', 'string', 'max:50'],
            'mode' => ['nullable', 'string', 'max:20'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_password' => ['nullable', 'string', 'max:50'],
            'discount_token' => ['nullable', 'string', 'max:80'],
            'sale_number' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'borrower_name' => ['nullable', 'string', 'max:150'],
            'due_date' => ['nullable', 'date'],
            'processed_at_utc' => ['nullable', 'date'],
            'processed_at' => ['nullable', 'date'],
            'sold_at' => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
        ]);

        [$quantity, $quantityLabel] = SaleQuantity::parse($validated['quantity']);

        if ($quantity === null) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter a valid quantity such as 1, 0.25, or 1/4.',
            ]);
        }

        $validated['quantity'] = $quantity;
        $validated['quantity_label'] = $quantityLabel;
        $validated['payment_method'] = $this->normalizePaymentMethod($validated['payment_method'] ?? null);

        if ($validated['payment_method'] === 'utang') {
            $borrowerName = trim((string) ($validated['borrower_name'] ?? ''));
            $dueDate = trim((string) ($validated['due_date'] ?? ''));

            if ($borrowerName === '') {
                throw ValidationException::withMessages([
                    'borrower_name' => 'Enter the name of the person who will pay this utang.',
                ]);
            }

            if ($dueDate === '') {
                throw ValidationException::withMessages([
                    'due_date' => 'Enter the date this utang is due.',
                ]);
            }

            $validated['borrower_name'] = $borrowerName;
            $validated['due_date'] = Carbon::parse($dueDate)->toDateString();
            $validated['borrower_phone'] = $this->resolveBorrowerPhone($request);
            $validated['valid_id_bytes'] = $this->validIdBytes($request);

            if ($validated['borrower_phone'] === null && $validated['valid_id_bytes'] === null) {
                throw ValidationException::withMessages([
                    'borrower_phone' => 'Enter a phone number or attach a valid ID for this utang.',
                ]);
            }
        } else {
            $validated['borrower_phone'] = null;
            $validated['valid_id_bytes'] = null;
        }

        return $validated;
    }

    private function createSale(Request $request, array $validated, bool $useClientTimestamp = false): array
    {
        $processedByUserId = $request->user()?->id
            ?? ($validated['processed_by_user_id'] ?? $validated['user_id'] ?? null);
        $idempotencyKey = isset($validated['idempotency_key'])
            ? trim((string) $validated['idempotency_key'])
            : null;
        $clientProcessedAt = $useClientTimestamp
            ? $this->resolveClientProcessedAt($validated)
            : null;
        $requestedSaleNumber = $this->resolveRequestedSaleNumber($validated);

        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        if ($idempotencyKey !== null) {
            $existingSale = Sale::with([
                'product',
                'processedBy:id,name,user_name,email',
            ])->where('idempotency_key', $idempotencyKey)->first();

            if ($existingSale) {
                return [$this->ensureSaleNumber($existingSale), false];
            }
        }

        $product = Product::findOrFail($validated['product_id']);
        $quantity = SaleQuantity::round((float) $validated['quantity']);
        $sellingMode = strtolower(trim((string) ($validated['mode'] ?? '')));
        $unitType = $this->resolveUnitType(
            $validated['unit_type'] ?? null,
            $product->unit ?? null,
        );

        if ($sellingMode === 'wholesale') {
            $unitType = $this->normalizeUnitType($product->unit) ?? $unitType;
        }

        if ($product->saleNeedsRetailSetup($unitType)) {
            $wholesaleUnit = $product->unit ?: 'unit';
            $saleUnit = $unitType ?: 'retail';
            $retailUnit = $product->retail_unit ?: 'retail unit';

            $message = $product->retail_enabled
                ? "This sale uses {$saleUnit}, but retail is set to {$retailUnit} per {$wholesaleUnit}."
                : "Set up retail for this product first (how many {$saleUnit} are in 1 {$wholesaleUnit}).";

            throw new HttpResponseException(response()->json([
                'message' => $message,
            ], 422));
        }

        if ($product->saleUsesRetailConversion($unitType) && $product->retail_price === null) {
            $retailUnit = $product->retail_unit ?: 'retail unit';

            throw new HttpResponseException(response()->json([
                'message' => "Set a retail price per {$retailUnit} in Retail Setup before selling this product that way.",
            ], 422));
        }

        if (!$product->saleUsesRetailConversion($unitType) && !SaleQuantity::isWhole($quantity)) {
            $retailUnit = $product->retail_unit ?: 'kg';

            throw new HttpResponseException(response()->json([
                'message' => "Wholesale sales must be whole units. Sell fractions such as 1/4 {$retailUnit} from the retail screen.",
            ], 422));
        }

        $subtotal = $product->unitPriceForSale($unitType) * $quantity;
        $vatRate = $product->resolveVatRate();
        $vatAmount = $product->vatAmountForSale($quantity, $unitType);
        $totalPrice = round($subtotal + $vatAmount, 2);

        $ticketSaleNumber = $requestedSaleNumber;

        if ($ticketSaleNumber === null && $useClientTimestamp && $clientProcessedAt !== null) {
            $ticketSaleNumber = $this->findGroupedSaleNumber($processedByUserId, $clientProcessedAt);
        }

        $discountPercent = CartDiscount::normalizePercent($validated['discount_percent'] ?? 0);
        CartDiscount::assertSamePercentOnTicket($ticketSaleNumber, $discountPercent);
        CartDiscount::authorize(
            $discountPercent,
            $validated['discount_password'] ?? null,
            $validated['discount_token'] ?? null,
        );
        $discount = CartDiscount::applyToTotal($totalPrice, $discountPercent);
        $totalPrice = $discount['total'];
        $paymentMethod = $validated['payment_method'] ?? 'cash';
        $this->assertSameUtangOnTicket(
            $ticketSaleNumber,
            $paymentMethod,
            $validated['borrower_name'] ?? null,
            $validated['due_date'] ?? null,
            $validated['borrower_phone'] ?? null,
        );

        $validIdPath = null;

        if ($paymentMethod === 'utang') {
            $validIdPath = $this->existingValidIdPath($ticketSaleNumber);
            $validIdBytes = $validated['valid_id_bytes'] ?? null;

            if ($validIdPath === null && is_string($validIdBytes) && $validIdBytes !== '') {
                $validIdPath = $this->storeValidId($validIdBytes, $ticketSaleNumber);
            }
        }

        $attributes = [
            'product_id' => $product->id,
            'processed_by_user_id' => $processedByUserId,
            'unit_type' => $unitType,
            'quantity' => $quantity,
            'quantity_label' => $validated['quantity_label'] ?? null,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'discount_percent' => $discount['percent'],
            'discount_amount' => $discount['amount'],
            'total_price' => $totalPrice,
            'payment_method' => $paymentMethod,
            'borrower_name' => $paymentMethod === 'utang' ? $validated['borrower_name'] : null,
            'borrower_phone' => $paymentMethod === 'utang' ? ($validated['borrower_phone'] ?? null) : null,
            'valid_id_path' => $paymentMethod === 'utang' ? $validIdPath : null,
            'due_date' => $paymentMethod === 'utang' ? $validated['due_date'] : null,
        ];

        if ($ticketSaleNumber !== null) {
            $attributes['sale_number'] = $ticketSaleNumber;
        }

        if ($idempotencyKey !== null) {
            $attributes['idempotency_key'] = $idempotencyKey;
        }

        if ($useClientTimestamp && $clientProcessedAt !== null) {
            $attributes['created_at'] = $clientProcessedAt;
            $attributes['updated_at'] = $clientProcessedAt;
        }

        try {
            $sale = DB::transaction(function () use ($attributes, $processedByUserId, $request) {
                $sale = Sale::create($attributes)->load([
                    'product',
                    'processedBy:id,name,user_name,email',
                ]);

                $sale = $this->ensureSaleNumber($sale);
                $this->decrementInventoryForSale($sale, $processedByUserId, $request->user());

                return $sale;
            });

            return [$sale, true];
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null && $this->isDuplicateKeyException($exception)) {
                $existingSale = Sale::with([
                    'product',
                    'processedBy:id,name,user_name,email',
                ])->where('idempotency_key', $idempotencyKey)->first();

                if ($existingSale) {
                    $existingSale = $this->ensureSaleNumber($existingSale);

                    return [$existingSale, false];
                }
            }

            throw $exception;
        }
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        return in_array($sqlState, ['23000', '23505'], true);
    }

    private function decrementInventoryForSale(Sale $sale, ?int $processedByUserId, ?User $requestUser): void
    {
        if ($processedByUserId === null && $requestUser === null) {
            return;
        }

        $branchId = $this->resolveSaleBranchId($processedByUserId, $requestUser);

        if ($branchId === null) {
            return;
        }

        $sale->loadMissing('product');

        $inventory = Inventory::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $sale->product_id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (!$inventory) {
            if ($sale->product?->saleUsesRetailConversion($sale->unit_type)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'No branch inventory found for this product.',
                ], 422));
            }

            return;
        }

        $product = $sale->product ?? Product::find($sale->product_id);

        if ($product && $product->saleUsesRetailConversion($sale->unit_type)) {
            $this->decrementRetailInventory($inventory, $product, (float) $sale->quantity);

            return;
        }

        $newQty = max(0, (int) $inventory->quantity - (int) round((float) $sale->quantity));
        $remainder = (float) ($inventory->retail_remainder ?? 0);

        $inventory->update([
            'quantity' => $newQty,
            'status' => $newQty === 0 && SaleQuantity::isZero($remainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }

    private function decrementRetailInventory(Inventory $inventory, Product $product, float $qtyToSell): void
    {
        $kgPerUnit = (int) $product->retail_qty_per_unit;
        $retailUnit = $product->retail_unit ?: 'kg';
        $qtyToSell = SaleQuantity::round($qtyToSell);

        if ($kgPerUnit <= 0) {
            throw new HttpResponseException(response()->json([
                'message' => 'This product is missing retail quantity per wholesale unit in Retail Setup.',
            ], 422));
        }

        $sacks = (int) $inventory->quantity;
        $remainder = SaleQuantity::round((float) ($inventory->retail_remainder ?? 0));
        $available = $product->availableRetailQuantity($sacks, $remainder);

        if ($qtyToSell > $available + 0.00005) {
            throw new HttpResponseException(response()->json([
                'message' => 'Not enough retail stock after allowed display loss. Available to sell: '.SaleQuantity::display($available)." {$retailUnit}.",
            ], 422));
        }

        $fromRemainder = min($remainder, $qtyToSell);
        $remainder = SaleQuantity::round($remainder - $fromRemainder);
        $stillNeeded = SaleQuantity::round($qtyToSell - $fromRemainder);

        if ($stillNeeded > 0.00005) {
            $unitsToOpen = (int) ceil($stillNeeded / $kgPerUnit - 0.0000001);
            $sacks -= $unitsToOpen;
            $remainder = SaleQuantity::round(($unitsToOpen * $kgPerUnit) - $stillNeeded + $remainder);
        }

        $inventory->update([
            'quantity' => $sacks,
            'retail_remainder' => max(0, $remainder),
            'status' => $sacks === 0 && SaleQuantity::isZero($remainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }

    private function resolveSaleBranchId(?int $processedByUserId, ?User $requestUser): ?int
    {
        $resolvedUser = $requestUser;

        if ($processedByUserId !== null && (!$resolvedUser || $resolvedUser->id !== $processedByUserId)) {
            $resolvedUser = User::with([
                'employee:id,branch_id',
                'managedBranch:id',
            ])->find($processedByUserId);
        } elseif ($resolvedUser) {
            $resolvedUser->loadMissing([
                'employee:id,branch_id',
                'managedBranch:id',
            ]);
        }

        return $resolvedUser?->employee?->branch_id
            ?? $resolvedUser?->managedBranch?->id;
    }

    private function resolveUnitType(?string $requestedUnitType, ?string $productUnit): ?string
    {
        $normalizedRequestUnitType = $this->normalizeUnitType($requestedUnitType);

        if ($normalizedRequestUnitType !== null) {
            return $normalizedRequestUnitType;
        }

        return $this->normalizeUnitType($productUnit);
    }

    private function normalizeUnitType(?string $unitType): ?string
    {
        if ($unitType === null) {
            return null;
        }

        $normalizedUnitType = strtolower(trim($unitType));

        if ($normalizedUnitType === '') {
            return null;
        }

        if (str_contains($normalizedUnitType, 'bag')) {
            return 'bag';
        }

        if (str_contains($normalizedUnitType, 'sack')) {
            return 'sack';
        }

        // Keep the admin's unit, including "1 kg". Do not rewrite it to "kilo",
        // or a wholesale sale no longer matches and asks for retail setup.
        return substr($normalizedUnitType, 0, 50);
    }

    private function normalizePaymentMethod(?string $paymentMethod): string
    {
        $method = strtolower(trim((string) $paymentMethod));

        if (in_array($method, ['utang', 'borrow', 'barrow', 'debt'], true)) {
            return 'utang';
        }

        return $method !== '' ? $method : 'cash';
    }

    private function assertSameUtangOnTicket(
        ?string $saleNumber,
        string $paymentMethod,
        ?string $borrowerName,
        ?string $dueDate,
        ?string $borrowerPhone = null,
    ): void {
        if ($saleNumber === null || trim($saleNumber) === '') {
            return;
        }

        $existing = Sale::query()
            ->where('sale_number', $saleNumber)
            ->first();

        if (!$existing) {
            return;
        }

        $existingMethod = strtolower(trim((string) ($existing->payment_method ?: 'cash')));

        if ($existingMethod !== $paymentMethod) {
            throw ValidationException::withMessages([
                'payment_method' => 'Use the same payment for every item on this receipt. Do not mix utang with cash.',
            ]);
        }

        if ($paymentMethod !== 'utang') {
            return;
        }

        $existingDue = optional($existing->due_date)?->toDateString();

        $existingPhone = $this->borrowerPhoneDigits($existing->borrower_phone);
        $incomingPhone = $this->borrowerPhoneDigits($borrowerPhone);

        if (
            strcasecmp(trim((string) $existing->borrower_name), trim((string) $borrowerName)) !== 0
            || $existingDue !== $dueDate
            || $existingPhone !== $incomingPhone
        ) {
            throw ValidationException::withMessages([
                'borrower_name' => 'Use the same borrower name, phone, and due date on every item in this utang.',
            ]);
        }
    }

    private function resolveBorrowerPhone(Request $request): ?string
    {
        $phone = trim((string) $request->input('borrower_phone', ''));

        if ($phone === '') {
            return null;
        }

        $digits = $this->borrowerPhoneDigits($phone);

        if (strlen($digits) < 10) {
            throw ValidationException::withMessages([
                'borrower_phone' => 'Enter a phone number with at least 10 digits.',
            ]);
        }

        if (strlen($phone) > 40) {
            $phone = substr($digits, 0, 40);
        }

        return $phone;
    }

    private function borrowerPhoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    private function validIdBytes(Request $request): ?string
    {
        $upload = $request->file('valid_id');

        if ($upload instanceof UploadedFile && $upload->isValid()) {
            try {
                $bytes = $this->jpegBytes($upload->getContent());
            } catch (\Throwable) {
                $bytes = null;
            }

            if ($bytes !== null) {
                return $bytes;
            }
        }

        return $this->jpegBytesFromBase64($request->input('valid_id_image'));
    }

    private function jpegBytes(mixed $contents): ?string
    {
        if (!is_string($contents) || strlen($contents) < 3) {
            return null;
        }

        return str_starts_with($contents, "\xFF\xD8\xFF") ? $contents : null;
    }

    private function jpegBytesFromBase64(mixed $encoded): ?string
    {
        if (!is_string($encoded)) {
            return null;
        }

        $payload = trim($encoded);

        if ($payload === '') {
            return null;
        }

        if (str_contains($payload, ',')) {
            $payload = substr($payload, (int) strrpos($payload, ',') + 1);
        }

        $payload = preg_replace('/\s+/', '', $payload) ?? '';
        $decoded = base64_decode($payload, true);

        return $this->jpegBytes($decoded);
    }

    private function existingValidIdPath(?string $saleNumber): ?string
    {
        $saleNumber = trim((string) $saleNumber);

        if ($saleNumber === '') {
            return null;
        }

        $path = Sale::query()
            ->where('sale_number', $saleNumber)
            ->whereNotNull('valid_id_path')
            ->orderBy('id')
            ->value('valid_id_path');

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    private function storeValidId(string $bytes, ?string $saleNumber): string
    {
        $path = $this->validIdStoragePath($saleNumber);
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    private function validIdStoragePath(?string $saleNumber): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string) $saleNumber)) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'sale-'.now()->format('YmdHis');
        }

        return 'utang-ids/'.substr($name, 0, 80).'.jpg';
    }

    private function resolveClientProcessedAt(array $validated): ?Carbon
    {
        $rawTimestampUtc = $validated['processed_at_utc'] ?? null;

        if ($rawTimestampUtc !== null && trim((string) $rawTimestampUtc) !== '') {
            return Carbon::parse((string) $rawTimestampUtc)
                ->utc()
                ->setMicrosecond(0);
        }

        $rawTimestamp = $validated['processed_at']
            ?? $validated['sold_at']
            ?? $validated['created_at']
            ?? null;

        if ($rawTimestamp === null || trim((string) $rawTimestamp) === '') {
            return null;
        }

        return Carbon::parse((string) $rawTimestamp)->setMicrosecond(0);
    }

    private function resolveRequestedSaleNumber(array $validated): ?string
    {
        $saleNumber = isset($validated['sale_number'])
            ? trim((string) $validated['sale_number'])
            : '';

        if ($saleNumber === '') {
            return null;
        }

        return substr($saleNumber, 0, 32);
    }

    private function findGroupedSaleNumber(mixed $processedByUserId, Carbon $processedAt): ?string
    {
        $query = Sale::query()
            ->whereNotNull('sale_number')
            ->where('created_at', $processedAt->copy()->toDateTimeString());

        if ($processedByUserId === null) {
            $query->whereNull('processed_by_user_id');
        } else {
            $query->where('processed_by_user_id', $processedByUserId);
        }

        return $query->orderBy('id')->value('sale_number');
    }

    private function ensureSaleNumber(Sale $sale): Sale
    {
        if (!empty($sale->sale_number)) {
            return $sale;
        }

        $timestamps = $sale->timestamps;
        $sale->timestamps = false;

        try {
            $sale->forceFill([
                'sale_number' => $this->buildSaleNumber($sale),
            ])->saveQuietly();
        } finally {
            $sale->timestamps = $timestamps;
        }

        return $sale->fresh([
            'product',
            'processedBy:id,name,user_name,email',
        ]) ?? $sale;
    }

    private function buildSaleNumber(Sale $sale): string
    {
        $rawCreatedAt = $sale->created_at;

        if ($rawCreatedAt instanceof \DateTimeInterface) {
            $datePart = $rawCreatedAt->format('Ymd');
        } elseif (is_string($rawCreatedAt) && trim($rawCreatedAt) !== '') {
            $datePart = Carbon::parse($rawCreatedAt)->format('Ymd');
        } else {
            $datePart = now()->format('Ymd');
        }

        return sprintf('SAL-%s-%06d', $datePart, (int) $sale->id);
    }
}
