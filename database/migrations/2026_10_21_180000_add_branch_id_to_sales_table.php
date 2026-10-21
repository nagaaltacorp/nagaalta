<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales') || Schema::hasColumn('sales', 'branch_id')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('product_id')
                ->constrained('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales') || !Schema::hasColumn('sales', 'branch_id')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
