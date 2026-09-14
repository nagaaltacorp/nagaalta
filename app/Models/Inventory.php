<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    protected $fillable = [
        'batch_number',
        'branch_id',
        'product_id',
        'quantity',
        'retail_remainder',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'retail_remainder' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function revenueLogs(): HasMany
    {
        return $this->hasMany(InventoryRevenueLog::class);
    }

    public function scopeMain($query)
    {
        return $query->whereNull('branch_id');
    }

    public function scopeForBranches($query)
    {
        return $query->whereNotNull('branch_id');
    }
}
