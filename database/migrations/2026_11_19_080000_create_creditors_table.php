<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('creditors')) {
            Schema::create('creditors', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('phone', 40)->nullable();
                $table->string('phone_digits', 64)->nullable()->unique();
                $table->string('valid_id_path', 255)->nullable();
                $table->timestamps();
            });
        }

        if (
            Schema::getConnection()->getDriverName() === 'mysql'
            && Schema::hasTable('creditors')
            && Schema::hasColumn('creditors', 'phone_digits')
        ) {
            DB::statement('ALTER TABLE creditors MODIFY phone_digits VARCHAR(64) NULL');
        }

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'creditor_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreignId('creditor_id')
                    ->nullable()
                    ->constrained('creditors')
                    ->nullOnDelete();
            });
        }

        $this->backfillFromSales();
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'creditor_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropConstrainedForeignId('creditor_id');
            });
        }

        Schema::dropIfExists('creditors');
    }

    private function backfillFromSales(): void
    {
        if (
            !Schema::hasTable('creditors')
            || !Schema::hasColumn('sales', 'borrower_name')
            || !Schema::hasColumn('sales', 'creditor_id')
        ) {
            return;
        }

        $hasPhone = Schema::hasColumn('sales', 'borrower_phone');
        $hasValidId = Schema::hasColumn('sales', 'valid_id_path');
        $columns = ['id', 'borrower_name'];

        if ($hasPhone) {
            $columns[] = 'borrower_phone';
        }

        if ($hasValidId) {
            $columns[] = 'valid_id_path';
        }

        $sales = DB::table('sales')
            ->whereRaw('LOWER(payment_method) = ?', ['utang'])
            ->whereNotNull('borrower_name')
            ->orderBy('id')
            ->get($columns);

        foreach ($sales as $sale) {
            $name = trim((string) $sale->borrower_name);

            if ($name === '') {
                continue;
            }

            $phone = $hasPhone ? trim((string) ($sale->borrower_phone ?? '')) : '';
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $digits = strlen($digits) >= 10 ? $digits : null;
            $path = $hasValidId ? trim((string) ($sale->valid_id_path ?? '')) : '';
            $path = $path !== '' ? $path : null;

            $creditor = null;

            if ($digits !== null) {
                $creditor = DB::table('creditors')->where('phone_digits', $digits)->first();
            }

            if (!$creditor) {
                $creditor = DB::table('creditors')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->whereNull('phone_digits')
                    ->first();
            }

            if (!$creditor) {
                $creditorId = DB::table('creditors')->insertGetId([
                    'name' => mb_substr($name, 0, 150),
                    'phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null,
                    'phone_digits' => $digits,
                    'valid_id_path' => $path,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $creditorId = $creditor->id;
                $updates = [];

                if ($digits !== null && !$creditor->phone_digits) {
                    $updates['phone'] = mb_substr($phone, 0, 40);
                    $updates['phone_digits'] = $digits;
                }

                if ($path !== null && !$creditor->valid_id_path) {
                    $updates['valid_id_path'] = $path;
                }

                if ($updates !== []) {
                    $updates['updated_at'] = now();
                    DB::table('creditors')->where('id', $creditorId)->update($updates);
                }
            }

            DB::table('sales')->where('id', $sale->id)->update([
                'creditor_id' => $creditorId,
            ]);
        }
    }
};
