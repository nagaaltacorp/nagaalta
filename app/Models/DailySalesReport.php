<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySalesReport extends Model
{
    protected $fillable = [
        'report_number',
        'report_date',
        'branch_id',
        'submitted_by_user_id',
        'cashier_name',
        'branch_name',
        'receipt_count',
        'line_count',
        'total_sales',
        'total_vat',
        'total_discount',
        'replacement_extra',
        'cash_counted',
        'notes',
        'payment_breakdown',
        'items',
        'submitted_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'receipt_count' => 'integer',
        'line_count' => 'integer',
        'total_sales' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_discount' => 'decimal:2',
        'replacement_extra' => 'decimal:2',
        'cash_counted' => 'decimal:2',
        'payment_breakdown' => 'array',
        'items' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
