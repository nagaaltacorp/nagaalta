<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_sales_reports')) {
            Schema::create('daily_sales_reports', function (Blueprint $table) {
                $table->id();
                $table->string('report_number', 32)->unique();
                $table->date('report_date');
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('cashier_name')->nullable();
                $table->string('branch_name')->nullable();
                $table->unsignedInteger('receipt_count')->default(0);
                $table->unsignedInteger('line_count')->default(0);
                $table->decimal('total_sales', 12, 2)->default(0);
                $table->decimal('total_vat', 12, 2)->default(0);
                $table->decimal('total_discount', 12, 2)->default(0);
                $table->decimal('replacement_extra', 12, 2)->default(0);
                $table->decimal('cash_counted', 12, 2)->nullable();
                $table->string('notes', 500)->nullable();
                $table->json('payment_breakdown')->nullable();
                $table->json('items')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['submitted_by_user_id', 'branch_id', 'report_date'],
                    'daily_sales_reports_user_branch_date_unique',
                );
                $table->index(['branch_id', 'report_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sales_reports');
    }
};
