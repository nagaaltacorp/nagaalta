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
            if (!Schema::hasColumn('sales', 'borrower_phone')) {
                $table->string('borrower_phone', 40)->nullable()->after('borrower_name');
            }

            if (!Schema::hasColumn('sales', 'valid_id_path')) {
                $table->string('valid_id_path', 255)->nullable()->after('borrower_phone');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            foreach (['valid_id_path', 'borrower_phone'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
