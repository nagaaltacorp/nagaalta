<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\ManagerBranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UtangController extends Controller
{
    public function index(Request $request)
    {
        $sales = ManagerBranchScope::scopeSales($this->utangQuery(), $request->user())
            ->latest()
            ->get();

        return response()->json(['data' => $sales]);
    }

    public function flutterIndex(Request $request)
    {
        if (!$request->user() && !$request->filled('branch_id') && !$request->filled('user_id')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $this->utangQuery()->latest();

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->query('branch_id');
            $query->whereHas('processedBy.employee', function ($employee) use ($branchId) {
                $employee->where('branch_id', $branchId);
            });
        } elseif ($request->filled('user_id')) {
            $query->where('processed_by_user_id', (int) $request->query('user_id'));
        }

        if ($request->query('status') === 'unpaid') {
            $query->whereNull('paid_at');
        } elseif ($request->query('status') === 'paid') {
            $query->whereNotNull('paid_at');
        }

        return response()->json(['data' => $query->limit(500)->get()]);
    }

    public function markPaid(Request $request)
    {
        $validated = $request->validate([
            'sale_number' => ['required', 'string', 'max:32'],
        ]);

        $saleNumber = trim($validated['sale_number']);
        $updated = Sale::query()
            ->where('sale_number', $saleNumber)
            ->whereRaw('LOWER(payment_method) = ?', ['utang'])
            ->whereNull('paid_at')
            ->update([
                'paid_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'No unpaid utang was found for that receipt.',
            ], 422);
        }

        return response()->json([
            'message' => 'Utang marked as paid.',
            'sale_number' => $saleNumber,
            'paid_lines' => $updated,
        ]);
    }

    private function utangQuery()
    {
        return Sale::query()
            ->with([
                'product:id,name,unit,category,company_name,price',
                'processedBy:id,name,user_name,email',
            ])
            ->whereRaw('LOWER(payment_method) = ?', ['utang']);
    }
}
