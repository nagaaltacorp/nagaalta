<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'retail_allowed_loss')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('retail_allowed_loss', 12, 4)->default(0)->after('retail_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'retail_allowed_loss')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('retail_allowed_loss');
            });
        }
    }
};
