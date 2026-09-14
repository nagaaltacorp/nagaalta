<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProductReplacementService;
use App\Support\SaleQuantity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductReplacementController extends Controller
{
    public function show()
    {
        return response()->json([
            'data' => [
                'replacement_window_days' => ProductReplacementService::windowDays(),
                'replacements' => ProductReplacementService::recent(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'replacement_window_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $days = ProductReplacementService::setWindowDays((int) $validated['replacement_window_days']);

        return response()->json([
            'message' => 'Replacement period saved.',
            'data' => [
                'replacement_window_days' => $days,
            ],
        ]);
    }

    public function flutterSettings()
    {
        return response()->json([
            'data' => [
                'replacement_window_days' => ProductReplacementService::windowDays(),
                'no_refund_if_cheaper' => true,
                'receipt_keeps_same_number' => true,
            ],
        ]);
    }

    public function flutterReceipt(Request $request)
    {
        $validated = $request->validate([
            'sale_number' => ['required', 'string', 'max:32'],
            'user_id' => ['nullable', 'exists:users,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        $receipt = ProductReplacementService::lookupReceipt(
            $validated['sale_number'],
            $this->resolveBranchId($request, $validated),
        );

        return response()->json(['data' => $receipt]);
    }

    public function flutterQuote(Request $request)
    {
        $payload = $this->validatedReplacement($request);

        return response()->json([
            'data' => ProductReplacementService::quote(
                $payload,
                $this->resolveBranchId($request, $payload),
            ),
        ]);
    }

    public function flutterStore(Request $request)
    {
        $payload = $this->validatedReplacement($request);

        $result = ProductReplacementService::replace(
            $payload,
            $this->resolveUserId($request, $payload),
            $this->resolveBranchId($request, $payload),
        );

        return response()->json($result, 201);
    }

    private function validatedReplacement(Request $request): array
    {
        $validated = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'sale_number' => ['nullable', 'string', 'max:32'],
            'new_product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required'],
            'unit_type' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'user_id' => ['nullable', 'exists:users,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        [$quantity, $quantityLabel] = SaleQuantity::parse($validated['quantity']);

        if ($quantity === null) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter a valid quantity such as 1, 0.25, or 1/4.',
            ]);
        }

        $validated['quantity'] = $quantity;
        $validated['quantity_label'] = $quantityLabel;

        return $validated;
    }

    private function resolveUserId(Request $request, array $validated): ?int
    {
        return $request->user()?->id
            ?? (isset($validated['user_id']) ? (int) $validated['user_id'] : null);
    }

    private function resolveBranchId(Request $request, array $validated): ?int
    {
        if (!empty($validated['branch_id'])) {
            return (int) $validated['branch_id'];
        }

        $userId = $this->resolveUserId($request, $validated);

        if ($userId === null && $request->filled('user_id')) {
            $userId = (int) $request->query('user_id');
        }

        if ($userId === null) {
            return null;
        }

        $user = User::with([
            'employee:id,branch_id',
            'managedBranch:id',
        ])->find($userId);

        return $user?->employee?->branch_id
            ?? $user?->managedBranch?->id;
    }
}
