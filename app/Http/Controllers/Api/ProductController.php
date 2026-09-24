<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\ManagerBranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $managerBranchIds = ManagerBranchScope::branchIdsFor($request->user());

        if ($request->boolean('catalog')) {
            $productsQuery = Product::query()->with([
                'inventory',
                'sales',
                'inventories' => function ($query) use ($managerBranchIds) {
                    $query->select(['id', 'product_id', 'branch_id', 'quantity', 'retail_remainder'])
                        ->with('branch:id,name');

                    if ($managerBranchIds !== null) {
                        if ($managerBranchIds === []) {
                            $query->whereRaw('1 = 0');
                        } else {
                            $query->whereIn('branch_id', $managerBranchIds);
                        }
                    }
                },
            ]);

            $products = $productsQuery->orderBy('name')->get();
            $this->appendBranchQuantities($products);

            return response()->json(['data' => $products]);
        }

        if ($managerBranchIds !== null) {
            if ($managerBranchIds === []) {
                return response()->json(['data' => []]);
            }

            $products = Product::query()
                ->whereHas('inventories', function ($query) use ($managerBranchIds) {
                    $query->whereIn('branch_id', $managerBranchIds);
                })
                ->with([
                    'inventories' => function ($query) use ($managerBranchIds) {
                        $query->whereIn('branch_id', $managerBranchIds);
                        $query->select(['id', 'product_id', 'branch_id', 'quantity', 'retail_remainder'])
                            ->with('branch:id,name');
                    },
                    'sales',
                ])
                ->latest()
                ->get();

            $this->appendBranchQuantities($products);

            return response()->json(['data' => $products]);
        }

        $branchId = null;

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->query('branch_id');
        } elseif ($request->filled('user_id')) {
            $user = User::with('employee:id,branch_id')->find($request->query('user_id'));
            $branchId = $user?->employee?->branch_id;

            if ($user && $branchId === null) {
                return response()->json(['data' => []]);
            }
        }

        $productsQuery = Product::query()->with([
            'inventory',
            'sales',
            'inventories' => function ($query) {
                $query->select(['id', 'product_id', 'branch_id', 'quantity', 'retail_remainder'])
                    ->with('branch:id,name');
            },
        ]);

        if ($branchId) {
            $productsQuery
                ->whereHas('inventories', function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                })
                ->with([
                    'inventories' => function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                        $query->select(['id', 'product_id', 'branch_id', 'quantity', 'retail_remainder'])
                            ->with('branch:id,name');
                    },
                ]);
        }

        $products = $productsQuery->latest()->get();

        $this->appendBranchQuantities($products);

        return response()->json(['data' => $products]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image'] = Storage::url($path);
        }

        $product = Product::create($validated);

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('image')) {
            $this->deletePublicFileFromUrl($product->image);

            $path = $request->file('image')->store('products', 'public');
            $validated['image'] = Storage::url($path);
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $product,
        ]);
    }

    public function destroy(int $id)
    {
        $product = Product::findOrFail($id);

        $this->deletePublicFileFromUrl($product->image);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    private function appendBranchQuantities($products): void
    {
        $products->each(function (Product $product) {
            $branchQuantities = $product->inventories
                ->groupBy('branch_id')
                ->map(function ($items) use ($product) {
                    $firstItem = $items->first();
                    $branch = $firstItem?->branch;
                    $quantity = (int) $items->sum('quantity');
                    $remainder = (float) $items->sum('retail_remainder');
                    $physical = $product->physicalRetailQuantity($quantity, $remainder);

                    return [
                        'branch_id' => $firstItem?->branch_id,
                        'branch_name' => $branch?->name,
                        'quantity' => $quantity,
                        'retail_remainder' => $remainder,
                        'physical_retail_quantity' => $physical,
                        'allowed_loss' => $product->allowedRetailLoss(),
                        'available_retail_quantity' => $product->availableRetailQuantity(
                            $quantity,
                            $remainder,
                        ),
                    ];
                })
                ->values();

            $product->setAttribute('branch_quantities', $branchQuantities);
            $product->setAttribute(
                'available_retail_quantity',
                (float) $branchQuantities->sum('available_retail_quantity'),
            );
        });
    }

    private function deletePublicFileFromUrl(?string $fileUrl): void
    {
        if (!$fileUrl) {
            return;
        }

        $path = parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl;

        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, strlen('/storage/'));
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $path = ltrim($path, '/');

        if ($path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
