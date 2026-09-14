<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && !Schema::hasColumn('settings', 'discount_password_hash')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->string('discount_password_hash')->nullable()->after('default_vat_rate');
            });
        }

        if (!Schema::hasTable('discount_options')) {
            Schema::create('discount_options', function (Blueprint $table) {
                $table->id();
                $table->decimal('percent', 5, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('percent');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'discount_percent')) {
                    $table->decimal('discount_percent', 5, 2)->default(0)->after('vat_amount');
                }

                if (!Schema::hasColumn('sales', 'discount_amount')) {
                    $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'discount_amount')) {
                    $table->dropColumn('discount_amount');
                }

                if (Schema::hasColumn('sales', 'discount_percent')) {
                    $table->dropColumn('discount_percent');
                }
            });
        }

        Schema::dropIfExists('discount_options');

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'discount_password_hash')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('discount_password_hash');
            });
        }
    }
};
