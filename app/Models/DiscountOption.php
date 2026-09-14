<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountOption extends Model
{
    protected $fillable = [
        'percent',
        'is_active',
    ];

    protected $casts = [
        'percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
