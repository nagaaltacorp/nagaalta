<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\SaleQuantity;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category',
        'unit',
        'price',
        'description',
        'image',
        'is_vatable',
        'vat_rate',
        'retail_enabled',
        'retail_unit',
        'retail_qty_per_unit',
        'retail_price',
        'retail_allowed_loss',
    ];

    protected $appends = [
        'vat_amount',
        'price_with_vat',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_vatable' => 'boolean',
        'vat_rate' => 'decimal:2',
        'retail_enabled' => 'boolean',
        'retail_qty_per_unit' => 'integer',
        'retail_price' => 'decimal:2',
        'retail_allowed_loss' => 'decimal:4',
    ];

    public function resolveVatRate(): float
    {
        return $this->is_vatable ? (float) $this->vat_rate : 0.0;
    }

    public function vatAmountForQuantity(int $quantity): float
    {
        $subtotal = (float) $this->price * max(0, $quantity);

        return round($subtotal * ($this->resolveVatRate() / 100), 2);
    }

    public function usesRetailConversion(): bool
    {
        return (bool) $this->retail_enabled
            && (int) $this->retail_qty_per_unit > 0
            && trim((string) $this->retail_unit) !== '';
    }

    public static function normalizeComparableUnit(?string $unit): string
    {
        $normalized = strtolower(trim((string) $unit));

        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, 'kilo') || $normalized === 'kg') {
            return 'kg';
        }

        if (in_array($normalized, ['pc', 'pcs', 'piece', 'pieces'], true)) {
            return 'pcs';
        }

        if (in_array($normalized, ['g', 'gram', 'grams'], true)) {
            return 'g';
        }

        if (in_array($normalized, ['liter', 'litre', 'liters', 'litres', 'l'], true)) {
            return 'liter';
        }

        return $normalized;
    }

    public function saleIsWholesale(?string $unitType): bool
    {
        $saleUnit = self::normalizeComparableUnit($unitType);
        $wholesaleUnit = self::normalizeComparableUnit($this->unit);

        return $saleUnit === '' || $saleUnit === $wholesaleUnit;
    }

    public function saleUsesRetailConversion(?string $unitType): bool
    {
        if ($this->saleIsWholesale($unitType) || !$this->usesRetailConversion()) {
            return false;
        }

        return self::normalizeComparableUnit($unitType)
            === self::normalizeComparableUnit($this->retail_unit);
    }

    public function saleNeedsRetailSetup(?string $unitType): bool
    {
        return !$this->saleIsWholesale($unitType)
            && !$this->saleUsesRetailConversion($unitType);
    }

    public function unitPriceForSale(?string $unitType): float
    {
        if ($this->saleUsesRetailConversion($unitType) && $this->usesRetailConversion()) {
            return (float) $this->retail_price;
        }

        return (float) $this->price;
    }

    public function vatAmountForSale(float $quantity, ?string $unitType): float
    {
        $subtotal = $this->unitPriceForSale($unitType) * max(0, $quantity);

        return round($subtotal * ($this->resolveVatRate() / 100), 2);
    }

    public function physicalRetailQuantity(int $wholesaleQuantity, float $remainder): float
    {
        if (!$this->usesRetailConversion()) {
            return 0;
        }

        return SaleQuantity::round(
            (max(0, $wholesaleQuantity) * (int) $this->retail_qty_per_unit)
            + max(0, $remainder)
        );
    }

    public function allowedRetailLoss(): float
    {
        if (!$this->usesRetailConversion()) {
            return 0;
        }

        return SaleQuantity::round(max(0, (float) $this->retail_allowed_loss));
    }

    public function availableRetailQuantity(int $wholesaleQuantity, float $remainder): float
    {
        $physical = $this->physicalRetailQuantity($wholesaleQuantity, $remainder);

        return SaleQuantity::round(max(0, $physical - $this->allowedRetailLoss()));
    }

    public function getVatAmountAttribute(): float
    {
        return $this->vatAmountForQuantity(1);
    }

    public function getPriceWithVatAttribute(): float
    {
        return round((float) $this->price + $this->vat_amount, 2);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
