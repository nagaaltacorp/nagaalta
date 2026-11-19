<?php

namespace App\Services;

use App\Models\Creditor;

class CreditorDirectory
{
    public static function remember(
        ?int $creditorId,
        string $name,
        ?string $phone,
        ?string $validIdPath,
    ): Creditor {
        $name = trim($name);
        $phone = trim((string) $phone);
        $phone = $phone !== '' ? $phone : null;
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $digits = strlen($digits) >= 10 ? $digits : null;
        $validIdPath = trim((string) $validIdPath);
        $validIdPath = $validIdPath !== '' ? $validIdPath : null;

        $creditor = null;

        if ($creditorId) {
            $creditor = Creditor::query()->find($creditorId);
        }

        if (!$creditor && $digits !== null) {
            $creditor = Creditor::query()->where('phone_digits', $digits)->first();
        }

        if (!$creditor && $name !== '') {
            $creditor = Creditor::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->whereNull('phone_digits')
                ->first();
        }

        if (!$creditor) {
            return Creditor::query()->create([
                'name' => $name !== '' ? mb_substr($name, 0, 150) : 'Customer',
                'phone' => $phone !== null ? mb_substr($phone, 0, 40) : null,
                'phone_digits' => $digits,
                'valid_id_path' => $validIdPath,
            ]);
        }

        $updates = [];

        if ($digits !== null && !$creditor->phone_digits) {
            $updates['phone'] = mb_substr((string) $phone, 0, 40);
            $updates['phone_digits'] = $digits;
        }

        if ($validIdPath !== null && !$creditor->valid_id_path) {
            $updates['valid_id_path'] = $validIdPath;
        }

        if ($updates !== []) {
            $creditor->fill($updates)->save();
        }

        return $creditor;
    }
}
