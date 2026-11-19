<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a category name.',
            ]);
        }

        $existing = ProductCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'This category already exists.',
            ]);
        }

        $category = ProductCategory::query()->create([
            'name' => mb_substr($name, 0, 100),
        ]);

        return response()->json([
            'message' => 'Category added.',
            'data' => $category,
        ], 201);
    }

    public function destroy(int $id)
    {
        $category = ProductCategory::query()->findOrFail($id);
        $used = Product::query()
            ->whereRaw('LOWER(category) = ?', [mb_strtolower($category->name)])
            ->exists();

        if ($used) {
            return response()->json([
                'message' => 'This category is used by a product. Change those products before removing it.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category removed.',
        ]);
    }
}
