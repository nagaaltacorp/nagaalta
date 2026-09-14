<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Sale extends Model
{
    protected $fillable = [
        'sale_number',
        'idempotency_key',
        'product_id',
        'processed_by_user_id',
        'unit_type',
        'quantity',
        'quantity_label',
        'vat_rate',
        'vat_amount',
        'discount_percent',
        'discount_amount',
        'total_price',
        'payment_method',
        'replaces_sale_id',
        'replaced_by_sale_id',
        'created_at',
        'updated_at',
    ];

    protected $appends = [
        'quantity_display',
        'is_replaced',
        'is_replacement',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'quantity' => 'decimal:4',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'processed_by_user_id' => 'integer',
        'replaces_sale_id' => 'integer',
        'replaced_by_sale_id' => 'integer',
    ];

    public function getQuantityDisplayAttribute(): string
    {
        return \App\Support\SaleQuantity::display(
            (float) $this->quantity,
            $this->quantity_label,
        );
    }

    protected static function booted(): void
    {
        static::created(function (Sale $sale): void {
            if (!empty($sale->sale_number)) {
                return;
            }

            $timestamps = $sale->timestamps;
            $sale->timestamps = false;

            try {
                $sale->forceFill([
                    'sale_number' => self::generateSaleNumber(
                        (int) $sale->id,
                        self::resolveDatePart($sale->created_at)
                    ),
                ])->saveQuietly();
            } finally {
                $sale->timestamps = $timestamps;
            }
        });
    }

    private static function resolveDatePart(mixed $createdAt): string
    {
        if ($createdAt instanceof \DateTimeInterface) {
            return $createdAt->format('Ymd');
        }

        if (is_string($createdAt) && trim($createdAt) !== '') {
            return Carbon::parse($createdAt)->format('Ymd');
        }

        return now()->format('Ymd');
    }

    private static function generateSaleNumber(int $saleId, ?string $datePart = null): string
    {
        return sprintf('SAL-%s-%06d', $datePart ?? now()->format('Ymd'), $saleId);
    }

    public function getIsReplacedAttribute(): bool
    {
        return $this->replaced_by_sale_id !== null;
    }

    public function getIsReplacementAttribute(): bool
    {
        return $this->replaces_sale_id !== null;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}