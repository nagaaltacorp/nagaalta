<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }

    public function down(): void
    {
        // Intentionally empty: purchase and supplier features were removed.
    }
};
