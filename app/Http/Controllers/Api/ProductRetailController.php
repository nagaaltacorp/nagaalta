<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\SaleQuantity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductRetailController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'products' => ['required', 'array'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.retail_enabled' => ['required', 'boolean'],
            'products.*.retail_unit' => ['nullable', 'string', 'max:20'],
            'products.*.retail_qty_per_unit' => ['nullable', 'integer', 'min:1'],
            'products.*.retail_price' => ['nullable', 'numeric', 'min:0'],
            'products.*.retail_allowed_loss' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                if ($value === null || $value === '') {
                    return;
                }

                if (is_numeric($value)) {
                    if ((float) $value < 0) {
                        $fail('Allowed loss cannot be negative.');
                    }

                    return;
                }

                [$quantity] = SaleQuantity::parse($value);

                if ($quantity === null) {
                    $fail('Allowed loss must be a number or fraction such as 0.25 or 1/4.');
                }
            }],
        ]);

        $productUpdates = $validated['products'];

        $request->validate([
            'products' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($productUpdates) {
                    foreach ($productUpdates as $item) {
                        if (!($item['retail_enabled'] ?? false)) {
                            continue;
                        }

                        if (empty($item['retail_unit'])) {
                            $fail('Enabled retail products need a retail unit.');

                            return;
                        }

                        if (empty($item['retail_qty_per_unit']) || (int) $item['retail_qty_per_unit'] < 1) {
                            $fail('Enabled retail products need how many retail units are in 1 wholesale unit.');

                            return;
                        }

                        if (!isset($item['retail_price']) || $item['retail_price'] === '' || $item['retail_price'] === null) {
                            $fail('Enabled retail products need a retail price.');

                            return;
                        }
                    }
                },
            ],
        ]);

        $products = DB::transaction(function () use ($productUpdates) {
            foreach ($productUpdates as $item) {
                $enabled = (bool) $item['retail_enabled'];

                Product::where('id', $item['id'])->update([
                    'retail_enabled' => $enabled,
                    'retail_unit' => $this->normalizeRetailUnit($item['retail_unit'] ?? null),
                    'retail_qty_per_unit' => $enabled
                        ? (int) $item['retail_qty_per_unit']
                        : ($item['retail_qty_per_unit'] ?? null),
                    'retail_price' => isset($item['retail_price']) && $item['retail_price'] !== '' && $item['retail_price'] !== null
                        ? round((float) $item['retail_price'], 2)
                        : null,
                    'retail_allowed_loss' => $this->parseAllowedLoss($item['retail_allowed_loss'] ?? null),
                ]);
            }

            $ids = collect($productUpdates)->pluck('id')->all();

            return Product::query()->whereIn('id', $ids)->orderBy('name')->get();
        });

        return response()->json([
            'message' => 'Retail settings saved successfully.',
            'data' => [
                'products' => $products,
            ],
        ]);
    }

    private function parseAllowedLoss(mixed $raw): float
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        [$quantity] = SaleQuantity::parse($raw);

        if ($quantity === null) {
            return 0;
        }

        return SaleQuantity::round(max(0, $quantity));
    }

    private function normalizeRetailUnit(?string $unit): string
    {
        $normalized = strtolower(trim((string) $unit));

        if ($normalized === '' || str_contains($normalized, 'kilo')) {
            return 'kg';
        }

        return substr($normalized, 0, 20);
    }
}
