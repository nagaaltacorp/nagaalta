<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && !Schema::hasColumn('settings', 'replacement_window_days')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->unsignedInteger('replacement_window_days')->default(7)->after('discount_password_hash');
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'replaces_sale_id')) {
                    $table->unsignedBigInteger('replaces_sale_id')->nullable()->after('payment_method');
                    $table->index('replaces_sale_id');
                }

                if (!Schema::hasColumn('sales', 'replaced_by_sale_id')) {
                    $table->unsignedBigInteger('replaced_by_sale_id')->nullable()->after('replaces_sale_id');
                    $table->index('replaced_by_sale_id');
                }
            });
        }

        if (!Schema::hasTable('product_replacements')) {
            Schema::create('product_replacements', function (Blueprint $table) {
                $table->id();
                $table->string('replacement_number', 32)->unique();
                $table->string('sale_number', 32)->index();
                $table->foreignId('original_sale_id')->constrained('sales')->cascadeOnDelete();
                $table->foreignId('new_sale_id')->constrained('sales')->cascadeOnDelete();
                $table->foreignId('original_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('new_product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('original_quantity', 12, 4);
                $table->decimal('new_quantity', 12, 4);
                $table->string('original_unit_type', 20)->nullable();
                $table->string('new_unit_type', 20)->nullable();
                $table->decimal('original_line_total', 12, 2)->default(0);
                $table->decimal('new_line_total', 12, 2)->default(0);
                $table->decimal('already_paid', 12, 2)->default(0);
                $table->decimal('additional_payment', 12, 2)->default(0);
                $table->string('payment_method', 20)->nullable();
                $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_replacements');

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'replaced_by_sale_id')) {
                    $table->dropIndex(['replaced_by_sale_id']);
                    $table->dropColumn('replaced_by_sale_id');
                }

                if (Schema::hasColumn('sales', 'replaces_sale_id')) {
                    $table->dropIndex(['replaces_sale_id']);
                    $table->dropColumn('replaces_sale_id');
                }
            });
        }

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'replacement_window_days')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('replacement_window_days');
            });
        }
    }
};
