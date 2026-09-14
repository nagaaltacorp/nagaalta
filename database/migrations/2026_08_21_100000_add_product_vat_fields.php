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
                if (!Schema::hasColumn('products', 'is_vatable')) {
                    $table->boolean('is_vatable')->default(false)->after('price');
                }

                if (!Schema::hasColumn('products', 'vat_rate')) {
                    $table->decimal('vat_rate', 5, 2)->default(12.00)->after('is_vatable');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'vat_rate')) {
                    $table->decimal('vat_rate', 5, 2)->default(0)->after('quantity');
                }

                if (!Schema::hasColumn('sales', 'vat_amount')) {
                    $table->decimal('vat_amount', 12, 2)->default(0)->after('vat_rate');
                }
            });
        }

        if (Schema::hasTable('settings') && !Schema::hasColumn('settings', 'default_vat_rate')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->decimal('default_vat_rate', 5, 2)->default(12.00)->after('low_stock_threshold');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'vat_rate')) {
                    $table->dropColumn('vat_rate');
                }

                if (Schema::hasColumn('products', 'is_vatable')) {
                    $table->dropColumn('is_vatable');
                }
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'vat_amount')) {
                    $table->dropColumn('vat_amount');
                }

                if (Schema::hasColumn('sales', 'vat_rate')) {
                    $table->dropColumn('vat_rate');
                }
            });
        }

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'default_vat_rate')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('default_vat_rate');
            });
        }
    }
};
