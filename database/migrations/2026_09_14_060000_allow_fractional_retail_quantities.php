<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'quantity')) {
            DB::statement('ALTER TABLE sales MODIFY quantity DECIMAL(12,4) NOT NULL DEFAULT 1');
        }

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'quantity_label')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('quantity_label', 20)->nullable()->after('quantity');
            });
        }

        if (Schema::hasTable('inventories') && Schema::hasColumn('inventories', 'retail_remainder')) {
            DB::statement('ALTER TABLE inventories MODIFY retail_remainder DECIMAL(12,4) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'quantity_label')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('quantity_label');
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'quantity')) {
            DB::statement('ALTER TABLE sales MODIFY quantity INT UNSIGNED NOT NULL');
        }

        if (Schema::hasTable('inventories') && Schema::hasColumn('inventories', 'retail_remainder')) {
            DB::statement('ALTER TABLE inventories MODIFY retail_remainder INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
