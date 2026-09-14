<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailySalesReport;
use App\Models\User;
use App\Services\DailySalesReportService;
use App\Services\ManagerBranchScope;
use Illuminate\Http\Request;

class DailySalesReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = DailySalesReport::query()
            ->with([
                'branch:id,name,location',
                'submittedBy:id,name,user_name',
            ])
            ->latest('report_date')
            ->latest('id');

        $branchIds = ManagerBranchScope::branchIdsFor($request->user());

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        if (!empty($validated['branch_id'])) {
            $branchId = (int) $validated['branch_id'];

            if (!ManagerBranchScope::ensureBranchAllowed($request->user(), $branchId)) {
                return response()->json(['data' => []]);
            }

            $query->where('branch_id', $branchId);
        }

        if (!empty($validated['date'])) {
            $query->whereDate('report_date', $validated['date']);
        }

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('report_number', 'like', "%{$search}%")
                    ->orWhere('cashier_name', 'like', "%{$search}%")
                    ->orWhere('branch_name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $report = $this->findVisible($request, $id);

        return response()->json(['data' => $report]);
    }

    public function pdf(Request $request, int $id)
    {
        $report = $this->findVisible($request, $id);
        $filename = $report->report_number.'.pdf';

        return response(DailySalesReportService::toPdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function flutterPreview(Request $request)
    {
        $user = $this->flutterUser($request);
        $date = $this->reportDate($request);

        return response()->json([
            'data' => DailySalesReportService::preview($user, $date),
        ]);
    }

    public function flutterIndex(Request $request)
    {
        $user = $this->flutterUser($request);

        $reports = DailySalesReport::query()
            ->where('submitted_by_user_id', $user->id)
            ->latest('report_date')
            ->limit(60)
            ->get();

        return response()->json(['data' => $reports]);
    }

    public function flutterStore(Request $request)
    {
        $user = $this->flutterUser($request);
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'cash_counted' => ['nullable', 'numeric', 'min:0'],
        ]);

        $date = $validated['date'] ?? now(DailySalesReportService::TIMEZONE)->toDateString();
        $report = DailySalesReportService::submit(
            $user,
            $date,
            $validated['notes'] ?? null,
            $validated['cash_counted'] ?? null,
        );

        return response()->json([
            'message' => 'Daily sales report sent to admin.',
            'data' => $report,
        ], 201);
    }

    private function findVisible(Request $request, int $id): DailySalesReport
    {
        $report = DailySalesReport::query()
            ->with([
                'branch:id,name,location',
                'submittedBy:id,name,user_name',
            ])
            ->findOrFail($id);

        if (!ManagerBranchScope::ensureBranchAllowed($request->user(), (int) $report->branch_id)) {
            abort(403, 'You cannot view this branch report.');
        }

        return $report;
    }

    private function flutterUser(Request $request): User
    {
        if ($request->user()) {
            return $request->user()->loadMissing([
                'employee.branch:id,name,location',
                'managedBranch:id,name,location',
            ]);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::with([
            'employee.branch:id,name,location',
            'managedBranch:id,name,location',
        ])->find($validated['user_id']);

        if (!$user) {
            abort(401, 'Unauthorized');
        }

        return $user;
    }

    private function reportDate(Request $request): string
    {
        $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        return $request->input('date')
            ?: $request->query('date')
            ?: now(DailySalesReportService::TIMEZONE)->toDateString();
    }
}
