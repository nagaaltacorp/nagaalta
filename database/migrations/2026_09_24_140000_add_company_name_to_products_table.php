<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'company_name')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('company_name', 150)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'company_name')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('company_name');
        });
    }
};
