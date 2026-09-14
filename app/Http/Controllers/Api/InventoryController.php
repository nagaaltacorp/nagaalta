<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPurchaseManagement;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryRevenueLog;
use App\Services\ManagerBranchScope;
use App\Support\SaleQuantity;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    use AuthorizesPurchaseManagement;
    public function index(Request $request)
    {
        $inventories = ManagerBranchScope::scopeInventories(
            Inventory::query()->forBranches()->with([
                'product',
                'branch:id,name,location',
            ]),
            $request->user(),
        )
            ->latest()
            ->get();

        return response()->json(['data' => $inventories]);
    }

    public function revenueLogs(Request $request)
    {
        $logs = ManagerBranchScope::scopeInventories(
            InventoryRevenueLog::with([
                'branch:id,name,location',
                'product:id,name,unit,price',
            ]),
            $request->user(),
        )
            ->latest()
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function store(Request $request)
    {
        $this->assertManagerOrAdmin(
            'Unauthorized. Only managers and admins can add inventory adjustments.',
        );

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:0'],
        ], [
            'product_id.exists' => 'This product must exist in Main Inventory before it can be added to a branch.',
        ]);

        if (!$this->productExistsInMain((int) $validated['product_id'])) {
            return response()->json([
                'message' => 'This product must be added in Main Inventory before it can be stocked at a branch.',
            ], 422);
        }

        if (!ManagerBranchScope::ensureBranchAllowed($request->user(), (int) $validated['branch_id'])) {
            return response()->json([
                'message' => 'You can only manage inventory for your assigned branch.',
            ], 403);
        }

        return DB::transaction(function () use ($validated) {
            $quantity = (int) $validated['quantity'];

            $this->adjustMainStock((int) $validated['product_id'], -$quantity);

            $status = $quantity === 0 ? 'out_of_stock' : 'in_stock';

            $inventory = Inventory::create([
                'batch_number' => $this->generateBatchNumber(
                    (int) $validated['branch_id'],
                    (int) $validated['product_id'],
                ),
                'branch_id' => $validated['branch_id'],
                'product_id' => $validated['product_id'],
                'quantity' => $quantity,
                'status' => $status,
            ]);

            $this->logRevenue($inventory, 'created');

            return response()->json([
                'message' => 'Inventory saved successfully.',
                'data' => $inventory->load(['product', 'branch:id,name,location']),
            ], 201);
        });
    }

    public function update(Request $request, int $id)
    {
        $inventory = Inventory::findOrFail($id);

        if ($inventory->branch_id === null) {
            return response()->json([
                'message' => 'Use Main Inventory to update this record.',
            ], 422);
        }

        if (!ManagerBranchScope::ensureBranchAllowed($request->user(), (int) $inventory->branch_id)) {
            return response()->json([
                'message' => 'You can only manage inventory for your assigned branch.',
            ], 403);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:0'],
        ], [
            'product_id.exists' => 'This product must exist in Main Inventory before it can be added to a branch.',
        ]);

        if (!$this->productExistsInMain((int) $validated['product_id'])) {
            return response()->json([
                'message' => 'This product must be added in Main Inventory before it can be stocked at a branch.',
            ], 422);
        }

        if (
            $inventory->branch_id !== (int) $validated['branch_id'] ||
            $inventory->product_id !== (int) $validated['product_id']
        ) {
            $validated['batch_number'] = $this->generateBatchNumber(
                (int) $validated['branch_id'],
                (int) $validated['product_id'],
            );
        }

        $validated['status'] = (int) $validated['quantity'] === 0
            ? 'out_of_stock'
            : 'in_stock';

        return DB::transaction(function () use ($inventory, $validated) {
            $locked = Inventory::query()
                ->forBranches()
                ->lockForUpdate()
                ->findOrFail($inventory->id);

            $oldProductId = (int) $locked->product_id;
            $oldQuantity = (int) $locked->quantity;
            $oldRemainder = (float) ($locked->retail_remainder ?? 0);
            $newProductId = (int) $validated['product_id'];
            $newQuantity = (int) $validated['quantity'];

            if ($oldProductId === $newProductId) {
                $this->adjustMainStock($newProductId, $oldQuantity - $newQuantity);
            } else {
                $this->adjustMainStock($oldProductId, $oldQuantity);
                $this->adjustMainRetailRemainder($oldProductId, $oldRemainder);
                $this->adjustMainStock($newProductId, -$newQuantity);
                $validated['retail_remainder'] = 0;
            }

            $locked->update($validated);

            return response()->json([
                'message' => 'Inventory updated successfully.',
                'data' => $locked->load(['product', 'branch:id,name,location']),
            ]);
        });
    }

    public function destroy(int $id)
    {
        $inventory = Inventory::findOrFail($id);

        if ($inventory->branch_id === null) {
            return response()->json([
                'message' => 'Use Main Inventory to delete this record.',
            ], 422);
        }

        if (!ManagerBranchScope::ensureBranchAllowed(request()->user(), (int) $inventory->branch_id)) {
            return response()->json([
                'message' => 'You can only manage inventory for your assigned branch.',
            ], 403);
        }

        return DB::transaction(function () use ($inventory) {
            $locked = Inventory::query()
                ->forBranches()
                ->lockForUpdate()
                ->findOrFail($inventory->id);

            $this->adjustMainStock((int) $locked->product_id, (int) $locked->quantity);
            $this->adjustMainRetailRemainder(
                (int) $locked->product_id,
                (float) ($locked->retail_remainder ?? 0),
            );
            $locked->delete();

            return response()->json([
                'message' => 'Inventory deleted successfully.',
            ]);
        });
    }

    public function mainIndex()
    {
        $inventories = Inventory::query()
            ->main()
            ->with('product')
            ->latest()
            ->get();

        return response()->json(['data' => $inventories]);
    }

    public function storeMain(Request $request)
    {
        $this->assertManagerOrAdmin(
            'Unauthorized. Only managers and admins can add main inventory.',
        );

        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:0'],
        ], [
            'product_id.exists' => 'Select a product from Products Setup first.',
        ]);

        $alreadyAdded = Inventory::query()
            ->main()
            ->where('product_id', $validated['product_id'])
            ->exists();

        if ($alreadyAdded) {
            return response()->json([
                'message' => 'This product is already in Main Inventory.',
            ], 422);
        }

        $status = (int) $validated['quantity'] === 0 ? 'out_of_stock' : 'in_stock';

        $inventory = Inventory::create([
            'batch_number' => $this->generateBatchNumber(
                null,
                (int) $validated['product_id'],
            ),
            'branch_id' => null,
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
            'status' => $status,
        ]);

        $this->logRevenue($inventory, 'created');

        return response()->json([
            'message' => 'Product added to Main Inventory.',
            'data' => $inventory->load('product'),
        ], 201);
    }

    public function updateMain(Request $request, int $id)
    {
        $this->assertManagerOrAdmin(
            'Unauthorized. Only managers and admins can update main inventory.',
        );

        $inventory = Inventory::query()->main()->findOrFail($id);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $inventory->update([
            'quantity' => $validated['quantity'],
            'status' => (int) $validated['quantity'] === 0
                ? 'out_of_stock'
                : 'in_stock',
        ]);

        return response()->json([
            'message' => 'Main Inventory updated.',
            'data' => $inventory->load('product'),
        ]);
    }

    public function destroyMain(int $id)
    {
        $this->assertManagerOrAdmin(
            'Unauthorized. Only managers and admins can delete main inventory.',
        );

        $inventory = Inventory::query()->main()->findOrFail($id);

        $hasBranchStock = Inventory::query()
            ->forBranches()
            ->where('product_id', $inventory->product_id)
            ->exists();

        if ($hasBranchStock) {
            return response()->json([
                'message' => 'Remove this product from Branch Inventory first.',
            ], 422);
        }

        $inventory->delete();

        return response()->json([
            'message' => 'Product removed from Main Inventory.',
        ]);
    }

    private function productExistsInMain(int $productId): bool
    {
        return Inventory::query()
            ->main()
            ->where('product_id', $productId)
            ->exists();
    }

    private function adjustMainStock(int $productId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $main = Inventory::query()
            ->main()
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$main) {
            $this->failValidation(
                'This product must be added in Main Inventory before it can be stocked at a branch.',
            );
        }

        $newQuantity = (int) $main->quantity + $delta;

        if ($newQuantity < 0) {
            $this->failValidation(
                'Not enough stock in Main Inventory. Available: '.(int) $main->quantity.'.',
            );
        }

        $main->update([
            'quantity' => $newQuantity,
            'status' => $newQuantity === 0 && SaleQuantity::isZero((float) ($main->retail_remainder ?? 0))
                ? 'out_of_stock'
                : 'in_stock',
        ]);
    }

    private function adjustMainRetailRemainder(int $productId, float $delta): void
    {
        if (SaleQuantity::isZero($delta)) {
            return;
        }

        $main = Inventory::query()
            ->main()
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$main) {
            return;
        }

        $newRemainder = max(0, SaleQuantity::round((float) ($main->retail_remainder ?? 0) + $delta));
        $quantity = (int) $main->quantity;

        $main->update([
            'retail_remainder' => $newRemainder,
            'status' => $quantity === 0 && SaleQuantity::isZero($newRemainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }

    private function failValidation(string $message): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], 422));
    }

    private function logRevenue(Inventory $inventory, string $action): void
    {
        $inventory->loadMissing('product');

        $price = (float) ($inventory->product?->price ?? 0);
        $quantity = (int) ($inventory->quantity ?? 0);

        InventoryRevenueLog::create([
            'inventory_id' => $inventory->id,
            'branch_id' => $inventory->branch_id,
            'product_id' => $inventory->product_id,
            'batch_number' => $inventory->batch_number,
            'quantity' => $quantity,
            'price' => $price,
            'expected_revenue' => $price * $quantity,
            'action' => $action,
        ]);
    }

    private function generateBatchNumber(?int $branchId, int $productId): string
    {
        $date = now()->format('Ymd');
        $branchCode = $branchId
            ? 'BR-' . str_pad((string) $branchId, 3, '0', STR_PAD_LEFT)
            : 'MAIN';
        $productCode = 'PR-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $numbers = '0123456789';

        do {
            $suffix =
                $letters[random_int(0, strlen($letters) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)];
            $batchNumber = $date . '-' . $branchCode . '-' . $productCode . '-' . $suffix;
        } while (Inventory::where('batch_number', $batchNumber)->exists());

        return $batchNumber;
    }
}
