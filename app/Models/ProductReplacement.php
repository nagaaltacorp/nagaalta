<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReplacement extends Model
{
    protected $fillable = [
        'replacement_number',
        'sale_number',
        'original_sale_id',
        'new_sale_id',
        'original_product_id',
        'new_product_id',
        'original_quantity',
        'new_quantity',
        'original_unit_type',
        'new_unit_type',
        'original_line_total',
        'new_line_total',
        'already_paid',
        'additional_payment',
        'payment_method',
        'processed_by_user_id',
    ];

    protected $casts = [
        'original_quantity' => 'decimal:4',
        'new_quantity' => 'decimal:4',
        'original_line_total' => 'decimal:2',
        'new_line_total' => 'decimal:2',
        'already_paid' => 'decimal:2',
        'additional_payment' => 'decimal:2',
        'processed_by_user_id' => 'integer',
    ];

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function newSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'new_sale_id');
    }

    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id');
    }

    public function newProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'new_product_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
