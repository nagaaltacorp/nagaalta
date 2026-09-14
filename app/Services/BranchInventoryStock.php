<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Support\SaleQuantity;
use Illuminate\Http\Exceptions\HttpResponseException;

class BranchInventoryStock
{
    public static function decrement(Inventory $inventory, Product $product, float $quantity, ?string $unitType): void
    {
        $quantity = SaleQuantity::round($quantity);

        if ($product->saleUsesRetailConversion($unitType)) {
            self::decrementRetail($inventory, $product, $quantity);

            return;
        }

        $newQty = max(0, (int) $inventory->quantity - (int) round($quantity));
        $remainder = (float) ($inventory->retail_remainder ?? 0);

        $inventory->update([
            'quantity' => $newQty,
            'status' => $newQty === 0 && SaleQuantity::isZero($remainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }

    public static function restore(Inventory $inventory, Product $product, float $quantity, ?string $unitType): void
    {
        $quantity = SaleQuantity::round($quantity);

        if ($product->saleUsesRetailConversion($unitType)) {
            self::restoreRetail($inventory, $product, $quantity);

            return;
        }

        $newQty = (int) $inventory->quantity + (int) round($quantity);
        $remainder = (float) ($inventory->retail_remainder ?? 0);

        $inventory->update([
            'quantity' => $newQty,
            'status' => $newQty === 0 && SaleQuantity::isZero($remainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }

    private static function decrementRetail(Inventory $inventory, Product $product, float $qtyToSell): void
    {
        $kgPerUnit = (int) $product->retail_qty_per_unit;
        $retailUnit = $product->retail_unit ?: 'kg';

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

    private static function restoreRetail(Inventory $inventory, Product $product, float $qtyToReturn): void
    {
        $kgPerUnit = (int) $product->retail_qty_per_unit;

        if ($kgPerUnit <= 0) {
            $kgPerUnit = 1;
        }

        $sacks = (int) $inventory->quantity;
        $remainder = SaleQuantity::round((float) ($inventory->retail_remainder ?? 0) + $qtyToReturn);

        while ($remainder + 0.00005 >= $kgPerUnit) {
            $remainder = SaleQuantity::round($remainder - $kgPerUnit);
            $sacks += 1;
        }

        $inventory->update([
            'quantity' => $sacks,
            'retail_remainder' => max(0, $remainder),
            'status' => $sacks === 0 && SaleQuantity::isZero($remainder) ? 'out_of_stock' : 'in_stock',
        ]);
    }
}
