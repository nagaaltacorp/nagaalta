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
        'borrower_name',
        'due_date',
        'paid_at',
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
        'due_date' => 'date',
        'paid_at' => 'datetime',
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

    public function scopeCollected($query)
    {
        return $query->where(function ($collected) {
            $collected
                ->where(function ($notUtang) {
                    $notUtang
                        ->whereNull('sales.payment_method')
                        ->orWhereRaw('LOWER(sales.payment_method) != ?', ['utang']);
                })
                ->orWhereNotNull('sales.paid_at');
        });
    }

    public function isUnpaidUtang(): bool
    {
        return strtolower(trim((string) $this->payment_method)) === 'utang'
            && $this->paid_at === null;
    }

    public static function recognizedAtSql(): string
    {
        return "CASE WHEN LOWER(sales.payment_method) = 'utang' THEN sales.paid_at ELSE sales.created_at END";
    }

    public function recognizedAt(): ?Carbon
    {
        if (strtolower(trim((string) $this->payment_method)) === 'utang') {
            return $this->paid_at;
        }

        return $this->created_at;
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