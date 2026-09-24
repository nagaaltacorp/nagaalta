<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'borrower_name')) {
                $table->string('borrower_name', 150)->nullable()->after('payment_method');
            }

            if (!Schema::hasColumn('sales', 'due_date')) {
                $table->date('due_date')->nullable()->after('borrower_name');
            }

            if (!Schema::hasColumn('sales', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('due_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            foreach (['paid_at', 'due_date', 'borrower_name'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
