<?php

namespace App\Services;

use App\Models\DiscountOption;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CartDiscount
{
    public const TOKEN_TTL_SECONDS = 900;

    public static function normalizePercent(mixed $raw): float
    {
        return round(max(0, (float) $raw), 2);
    }

    public static function passwordIsConfigured(): bool
    {
        $hash = Setting::query()->value('discount_password_hash');

        return is_string($hash) && trim($hash) !== '';
    }

    public static function verifyPassword(?string $plain): bool
    {
        if ($plain === null || $plain === '') {
            return false;
        }

        $hash = Setting::query()->value('discount_password_hash');

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return Hash::check($plain, $hash);
    }

    public static function activePercents(): array
    {
        return DiscountOption::query()
            ->active()
            ->orderBy('percent')
            ->get()
            ->map(fn (DiscountOption $option) => [
                'id' => $option->id,
                'percent' => self::normalizePercent($option->percent),
                'label' => rtrim(rtrim(number_format((float) $option->percent, 2, '.', ''), '0'), '.').'%',
            ])
            ->values()
            ->all();
    }

    public static function percentIsAllowed(float $percent): bool
    {
        $percent = self::normalizePercent($percent);

        if ($percent <= 0) {
            return true;
        }

        return DiscountOption::query()
            ->active()
            ->get()
            ->contains(fn (DiscountOption $option) => self::normalizePercent($option->percent) === $percent);
    }

    public static function issueToken(): string
    {
        $token = Str::random(40);

        Cache::put(self::tokenCacheKey($token), true, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    public static function tokenIsValid(?string $token): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        return Cache::get(self::tokenCacheKey($token)) === true;
    }

    public static function authorize(float $percent, ?string $password, ?string $token): void
    {
        $percent = self::normalizePercent($percent);

        if ($percent <= 0) {
            return;
        }

        if (!self::percentIsAllowed($percent)) {
            throw new HttpResponseException(response()->json([
                'message' => 'That discount percent is not available. Ask an administrator to add it in Discount Setup.',
            ], 422));
        }

        if (!self::passwordIsConfigured()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Discount password is not set. Ask an administrator.',
            ], 422));
        }

        if (self::tokenIsValid($token) || self::verifyPassword($password)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Enter the discount password to apply this discount to the whole sale.',
        ], 422));
    }

    public static function assertSamePercentOnTicket(?string $saleNumber, float $percent): void
    {
        if ($saleNumber === null || trim($saleNumber) === '') {
            return;
        }

        $existing = Sale::query()
            ->where('sale_number', $saleNumber)
            ->orderBy('id')
            ->value('discount_percent');

        if ($existing === null) {
            return;
        }

        if (self::normalizePercent($existing) !== self::normalizePercent($percent)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Discount applies to the whole sale, not per product. Use the same percent on every item in this checkout.',
            ], 422));
        }
    }

    /**
     * @return array{percent: float, amount: float, total: float}
     */
    public static function applyToTotal(float $grossTotal, float $percent): array
    {
        $percent = self::normalizePercent($percent);
        $grossTotal = round(max(0, $grossTotal), 2);

        if ($percent <= 0) {
            return [
                'percent' => 0.0,
                'amount' => 0.0,
                'total' => $grossTotal,
            ];
        }

        $amount = round($grossTotal * ($percent / 100), 2);

        return [
            'percent' => $percent,
            'amount' => $amount,
            'total' => round(max(0, $grossTotal - $amount), 2),
        ];
    }

    private static function tokenCacheKey(string $token): string
    {
        return 'discount.unlock.'.trim($token);
    }
}
