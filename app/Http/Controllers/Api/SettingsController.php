<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $settings = Setting::firstOrCreate([], [
            'company_name' => 'Admin Dashboard',
            'support_email' => 'support@example.com',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'low_stock_threshold' => 10,
            'default_vat_rate' => 12.00,
        ]);

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'max:20'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        $settings = Setting::firstOrCreate([]);
        $settings->update($validated);

        return response()->json([
            'message' => 'Settings updated successfully.',
            'data' => $settings,
        ]);
    }
}
