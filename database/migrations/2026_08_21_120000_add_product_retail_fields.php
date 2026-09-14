<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'retail_enabled')) {
                    $table->boolean('retail_enabled')->default(false)->after('vat_rate');
                }

                if (!Schema::hasColumn('products', 'retail_unit')) {
                    $table->string('retail_unit', 20)->default('kg')->after('retail_enabled');
                }

                if (!Schema::hasColumn('products', 'retail_qty_per_unit')) {
                    $table->unsignedInteger('retail_qty_per_unit')->nullable()->after('retail_unit');
                }

                if (!Schema::hasColumn('products', 'retail_price')) {
                    $table->decimal('retail_price', 12, 2)->nullable()->after('retail_qty_per_unit');
                }
            });
        }

        if (Schema::hasTable('inventories') && !Schema::hasColumn('inventories', 'retail_remainder')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedInteger('retail_remainder')->default(0)->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                foreach (['retail_price', 'retail_qty_per_unit', 'retail_unit', 'retail_enabled'] as $column) {
                    if (Schema::hasColumn('products', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('inventories') && Schema::hasColumn('inventories', 'retail_remainder')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropColumn('retail_remainder');
            });
        }
    }
};
