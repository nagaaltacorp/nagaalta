<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVatController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'default_vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'products' => ['nullable', 'array'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.is_vatable' => ['required', 'boolean'],
            'products.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $defaultVatRate = round((float) $validated['default_vat_rate'], 2);
        $productUpdates = $validated['products'] ?? [];

        $settings = DB::transaction(function () use ($defaultVatRate, $productUpdates) {
            $settings = Setting::firstOrCreate([], [
                'company_name' => 'Admin Dashboard',
                'support_email' => 'support@example.com',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'low_stock_threshold' => 10,
                'default_vat_rate' => $defaultVatRate,
            ]);

            $settings->update(['default_vat_rate' => $defaultVatRate]);

            foreach ($productUpdates as $item) {
                Product::where('id', $item['id'])->update([
                    'is_vatable' => (bool) $item['is_vatable'],
                    'vat_rate' => round((float) $item['vat_rate'], 2),
                ]);
            }

            return $settings->fresh();
        });

        $productIds = collect($productUpdates)->pluck('id')->all();
        $products = $productIds === []
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get();

        return response()->json([
            'message' => 'Product VAT settings saved successfully.',
            'data' => [
                'default_vat_rate' => $settings->default_vat_rate,
                'products' => $products,
            ],
        ]);
    }
}
