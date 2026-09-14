<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountOption;
use App\Models\Setting;
use App\Services\CartDiscount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DiscountSetupController extends Controller
{
    public function show()
    {
        $options = DiscountOption::query()->orderBy('percent')->get();

        return response()->json([
            'data' => [
                'password_configured' => CartDiscount::passwordIsConfigured(),
                'applies_to' => 'entire_cart',
                'options' => $options,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        $configured = CartDiscount::passwordIsConfigured();

        $validated = $request->validate([
            'current_password' => [$configured ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:4', 'max:50', 'confirmed'],
        ]);

        if ($configured && !CartDiscount::verifyPassword($validated['current_password'] ?? null)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current discount password is incorrect.',
            ]);
        }

        $settings = Setting::firstOrCreate([]);
        $settings->discount_password_hash = Hash::make($validated['password']);
        $settings->save();

        return response()->json([
            'message' => 'Discount password saved.',
            'data' => [
                'password_configured' => true,
            ],
        ]);
    }

    public function storeOption(Request $request)
    {
        $validated = $request->validate([
            'percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $percent = CartDiscount::normalizePercent($validated['percent']);
        $existing = DiscountOption::query()
            ->get()
            ->first(fn (DiscountOption $option) => CartDiscount::normalizePercent($option->percent) === $percent);

        if ($existing) {
            if ($existing->is_active) {
                throw ValidationException::withMessages([
                    'percent' => 'That discount percent is already added.',
                ]);
            }

            $existing->update(['is_active' => true]);

            return response()->json([
                'message' => 'Discount percent enabled.',
                'data' => $existing->fresh(),
            ]);
        }

        $option = DiscountOption::create([
            'percent' => $percent,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Discount percent added.',
            'data' => $option,
        ], 201);
    }

    public function updateOption(Request $request, int $id)
    {
        $option = DiscountOption::findOrFail($id);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $option->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'message' => $option->is_active ? 'Discount percent enabled.' : 'Discount percent disabled.',
            'data' => $option,
        ]);
    }

    public function destroyOption(int $id)
    {
        $option = DiscountOption::findOrFail($id);
        $option->delete();

        return response()->json([
            'message' => 'Discount percent removed.',
        ]);
    }

    public function flutterIndex()
    {
        return response()->json([
            'data' => [
                'applies_to' => 'entire_cart',
                'password_required' => true,
                'password_configured' => CartDiscount::passwordIsConfigured(),
                'discounts' => CartDiscount::activePercents(),
                'example' => '10% off the whole checkout, not each product.',
            ],
        ]);
    }

    public function flutterUnlock(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
        ]);

        if (!CartDiscount::passwordIsConfigured()) {
            return response()->json([
                'message' => 'Discount password is not set. Ask an administrator.',
            ], 422);
        }

        if (!CartDiscount::verifyPassword($validated['password'])) {
            return response()->json([
                'message' => 'Incorrect discount password.',
            ], 422);
        }

        $percent = isset($validated['percent'])
            ? CartDiscount::normalizePercent($validated['percent'])
            : null;

        if ($percent !== null && !CartDiscount::percentIsAllowed($percent)) {
            return response()->json([
                'message' => 'That discount percent is not available.',
            ], 422);
        }

        $token = CartDiscount::issueToken();

        return response()->json([
            'unlocked' => true,
            'token' => $token,
            'expires_in' => CartDiscount::TOKEN_TTL_SECONDS,
            'percent' => $percent,
            'applies_to' => 'entire_cart',
            'message' => 'Discount unlocked. Apply one percent to the whole sale.',
        ]);
    }
}
