<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'company_name',
        'support_email',
        'timezone',
        'currency',
        'low_stock_threshold',
        'default_vat_rate',
    ];

    protected $casts = [
        'low_stock_threshold' => 'integer',
        'default_vat_rate' => 'decimal:2',
    ];

    protected $hidden = [
        'discount_password_hash',
    ];
}
