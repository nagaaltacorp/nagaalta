<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ManagerBranchScope;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $branchIds = ManagerBranchScope::branchIdsFor($request->user());

        if ($branchIds !== null && $branchIds === []) {
            return response()->json(['data' => $this->emptyDashboardPayload('this_year')]);
        }

        [$rangeKey, $startDate, $endDate] = $this->resolveDateRange($request);

        $salesByBranch = $this->buildSalesByBranchSummary($startDate, $endDate, $branchIds);
        $branchSalesChart = $this->buildBranchSalesChart($startDate, $endDate, $salesByBranch, $branchIds);
        $monthlyDailyBarChart = $this->buildMonthlyDailyBarChart($branchIds);
        $latestSales = $this->buildLatestSales(7, $branchIds);
        $topSellingProductsPie = $this->buildTopSellingProductsPieChart($startDate, $endDate, $branchIds);

        return response()->json([
            'data' => [
                'total_products' => $this->countProducts($branchIds),
                'total_sales' => $this->sumSales($branchIds),
                'total_users' => $this->countUsers($branchIds),
                'inventory_count' => $this->sumInventoryQuantity($branchIds),
                'sales_by_branch' => $salesByBranch,
                'branch_sales_chart' => [
                    'range' => $rangeKey,
                    'date_from' => $startDate->toDateString(),
                    'date_to' => $endDate->toDateString(),
                    'labels' => $branchSalesChart['labels'],
                    'series' => $branchSalesChart['series'],
                ],
                'monthly_daily_bar_chart' => [
                    'date_from' => $monthlyDailyBarChart['date_from'],
                    'date_to' => $monthlyDailyBarChart['date_to'],
                    'labels' => $monthlyDailyBarChart['labels'],
                    'series' => $monthlyDailyBarChart['series'],
                ],
                'latest_sales' => $latestSales,
                'top_selling_products_pie' => [
                    'labels' => $topSellingProductsPie['labels'],
                    'series' => $topSellingProductsPie['series'],
                    'total_quantity' => $topSellingProductsPie['total_quantity'],
                ],
            ],
        ]);
    }

    private function emptyDashboardPayload(string $rangeKey): array
    {
        $monthStart = now()->copy()->startOfMonth()->startOfDay();
        $monthEnd = now()->copy()->endOfDay();

        return [
            'total_products' => 0,
            'total_sales' => 0,
            'total_users' => 0,
            'inventory_count' => 0,
            'sales_by_branch' => [],
            'branch_sales_chart' => [
                'range' => $rangeKey,
                'date_from' => now()->copy()->startOfYear()->toDateString(),
                'date_to' => now()->copy()->endOfYear()->toDateString(),
                'labels' => [],
                'series' => [],
            ],
            'monthly_daily_bar_chart' => [
                'date_from' => $monthStart->toDateString(),
                'date_to' => $monthEnd->toDateString(),
                'labels' => [],
                'series' => [],
            ],
            'latest_sales' => [],
            'top_selling_products_pie' => [
                'labels' => [],
                'series' => [],
                'total_quantity' => 0,
            ],
        ];
    }

    private function countProducts(?array $branchIds): int
    {
        if ($branchIds === null) {
            return Product::count();
        }

        return Product::query()
            ->whereHas('inventories', function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds);
            })
            ->count();
    }

    private function sumSales(?array $branchIds): float
    {
        $query = Sale::query();

        if ($branchIds !== null) {
            $query->whereHas('processedBy.employee', function ($employeeQuery) use ($branchIds) {
                $employeeQuery->whereIn('branch_id', $branchIds);
            });
        }

        return (float) $query->collected()->sum('total_price');
    }

    private function countUsers(?array $branchIds): int
    {
        if ($branchIds === null) {
            return User::count();
        }

        return User::query()
            ->whereHas('employee', function ($query) use ($branchIds) {
                $query->whereIn('branch_id', $branchIds);
            })
            ->count();
    }

    private function sumInventoryQuantity(?array $branchIds): int
    {
        $query = Inventory::query();

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        return (int) $query->sum('quantity');
    }

    private function salesJoinQuery(?array $branchIds)
    {
        $query = Sale::query()
            ->leftJoin('users', 'sales.processed_by_user_id', '=', 'users.id')
            ->leftJoin('employees', 'users.employee_id', '=', 'employees.id')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id');

        return ManagerBranchScope::constrainJoinedSales($query, $branchIds)->collected();
    }

    private function resolveDateRange(Request $request): array
    {
        $selectedRange = (string) $request->query('range', 'this_year');

        $allowedDayRanges = [
            '30d' => 30,
            '90d' => 90,
            '180d' => 180,
        ];

        if (array_key_exists($selectedRange, $allowedDayRanges)) {
            $days = $allowedDayRanges[$selectedRange];

            return [
                $selectedRange,
                now()->startOfDay()->subDays($days - 1),
                now()->endOfDay(),
            ];
        }

        if ($selectedRange === 'this_year') {
            return [
                'this_year',
                now()->copy()->startOfYear()->startOfDay(),
                now()->copy()->endOfYear()->endOfDay(),
            ];
        }

        if ($selectedRange === 'last_year') {
            $lastYear = now()->copy()->subYear();

            return [
                'last_year',
                $lastYear->copy()->startOfYear()->startOfDay(),
                $lastYear->copy()->endOfYear()->endOfDay(),
            ];
        }

        if ($selectedRange === 'custom') {
            $dateFromRaw = (string) $request->query('date_from', '');
            $dateToRaw = (string) $request->query('date_to', '');

            try {
                $dateFrom = Carbon::createFromFormat('Y-m-d', $dateFromRaw)->startOfDay();
                $dateTo = Carbon::createFromFormat('Y-m-d', $dateToRaw)->endOfDay();

                if ($dateFrom->greaterThan($dateTo)) {
                    [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
                }

                return ['custom', $dateFrom, $dateTo];
            } catch (\Throwable $exception) {
                return [
                    'this_year',
                    now()->copy()->startOfYear()->startOfDay(),
                    now()->copy()->endOfYear()->endOfDay(),
                ];
            }
        }

        return [
            'this_year',
            now()->copy()->startOfYear()->startOfDay(),
            now()->copy()->endOfYear()->endOfDay(),
        ];
    }

    private function buildSalesByBranchSummary(Carbon $startDate, Carbon $endDate, ?array $branchIds): array
    {
        return $this->salesJoinQuery($branchIds)
            ->whereRaw(Sale::recognizedAtSql().' BETWEEN ? AND ?', [$startDate, $endDate])
            ->selectRaw('branches.id as branch_id')
            ->selectRaw('branches.name as branch_name')
            ->selectRaw('COUNT(sales.id) as sale_count')
            ->selectRaw('COALESCE(SUM(sales.total_price), 0) as total_sales')
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('total_sales')
            ->get()
            ->map(function ($row) {
                return [
                    'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                    'branch_name' => $row->branch_name ?: 'Unassigned',
                    'sale_count' => (int) $row->sale_count,
                    'total_sales' => (float) $row->total_sales,
                ];
            })
            ->values()
            ->all();
    }

    private function buildBranchSalesChart(
        Carbon $startDate,
        Carbon $endDate,
        array $salesByBranch,
        ?array $branchIds,
    ): array {
        $selectedBranches = collect($salesByBranch)
            ->take($branchIds !== null ? 1 : 5)
            ->map(function (array $branch) {
                $key = $branch['branch_id'] !== null
                    ? 'branch_' . $branch['branch_id']
                    : 'unassigned';

                return [
                    'key' => $key,
                    'branch_id' => $branch['branch_id'],
                    'branch_name' => $branch['branch_name'],
                ];
            })
            ->values();

        if ($selectedBranches->isEmpty()) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }

        $dailyRows = $this->salesJoinQuery($branchIds)
            ->whereRaw(Sale::recognizedAtSql().' BETWEEN ? AND ?', [$startDate, $endDate])
            ->selectRaw('DATE('.Sale::recognizedAtSql().') as sale_date')
            ->selectRaw('branches.id as branch_id')
            ->selectRaw('COALESCE(SUM(sales.total_price), 0) as total_sales')
            ->groupByRaw('DATE('.Sale::recognizedAtSql().'), branches.id')
            ->orderBy('sale_date')
            ->get();

        $chartStartDate = $dailyRows->isNotEmpty()
            ? Carbon::parse((string) $dailyRows->first()->sale_date)->startOfDay()
            : $startDate->copy()->startOfDay();

        $dates = collect(CarbonPeriod::create(
            $chartStartDate,
            '1 day',
            $endDate->copy()->startOfDay(),
        ));

        $labels = $dates
            ->map(fn (Carbon $date) => $date->format('M j'))
            ->values()
            ->all();

        $dateIndex = $dates
            ->mapWithKeys(fn (Carbon $date, int $index) => [$date->format('Y-m-d') => $index])
            ->all();

        $seriesData = [];

        foreach ($selectedBranches as $branch) {
            $seriesData[$branch['key']] = array_fill(0, count($labels), 0.0);
        }

        foreach ($dailyRows as $row) {
            $key = $row->branch_id !== null
                ? 'branch_' . (int) $row->branch_id
                : 'unassigned';

            if (!array_key_exists($key, $seriesData)) {
                continue;
            }

            $saleDate = (string) $row->sale_date;

            if (!array_key_exists($saleDate, $dateIndex)) {
                continue;
            }

            $index = $dateIndex[$saleDate];
            $seriesData[$key][$index] = round((float) $row->total_sales, 2);
        }

        $series = $selectedBranches
            ->map(function (array $branch) use ($seriesData, $labels) {
                return [
                    'name' => $branch['branch_name'],
                    'data' => $seriesData[$branch['key']] ?? array_fill(0, count($labels), 0.0),
                ];
            })
            ->values()
            ->all();

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    private function buildMonthlyDailyBarChart(?array $branchIds): array
    {
        $monthStart = now()->copy()->startOfMonth()->startOfDay();
        $monthEnd = now()->copy()->endOfDay();

        $dates = collect(CarbonPeriod::create(
            $monthStart,
            '1 day',
            $monthEnd->copy()->startOfDay(),
        ));

        $labels = $dates
            ->map(fn (Carbon $date) => $date->format('M j'))
            ->values()
            ->all();

        $dateIndex = $dates
            ->mapWithKeys(fn (Carbon $date, int $index) => [$date->format('Y-m-d') => $index])
            ->all();

        $selectedBranches = $this->salesJoinQuery($branchIds)
            ->whereRaw(Sale::recognizedAtSql().' BETWEEN ? AND ?', [$monthStart, $monthEnd])
            ->selectRaw('branches.id as branch_id')
            ->selectRaw('branches.name as branch_name')
            ->selectRaw('COALESCE(SUM(sales.total_price), 0) as total_sales')
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('total_sales')
            ->limit($branchIds !== null ? 1 : 2)
            ->get()
            ->map(function ($row) {
                $branchId = $row->branch_id !== null ? (int) $row->branch_id : null;

                return [
                    'key' => $branchId !== null ? 'branch_' . $branchId : 'unassigned',
                    'branch_id' => $branchId,
                    'branch_name' => $row->branch_name ?: 'Unassigned',
                ];
            })
            ->values();

        if ($selectedBranches->isEmpty()) {
            return [
                'date_from' => $monthStart->toDateString(),
                'date_to' => $monthEnd->toDateString(),
                'labels' => $labels,
                'series' => [],
            ];
        }

        $dailyRows = $this->salesJoinQuery($branchIds)
            ->whereRaw(Sale::recognizedAtSql().' BETWEEN ? AND ?', [$monthStart, $monthEnd])
            ->selectRaw('DATE('.Sale::recognizedAtSql().') as sale_date')
            ->selectRaw('branches.id as branch_id')
            ->selectRaw('COALESCE(SUM(sales.total_price), 0) as total_sales')
            ->groupByRaw('DATE('.Sale::recognizedAtSql().'), branches.id')
            ->orderBy('sale_date')
            ->get();

        $seriesData = [];

        foreach ($selectedBranches as $branch) {
            $seriesData[$branch['key']] = array_fill(0, count($labels), 0.0);
        }

        foreach ($dailyRows as $row) {
            $key = $row->branch_id !== null
                ? 'branch_' . (int) $row->branch_id
                : 'unassigned';

            if (!array_key_exists($key, $seriesData)) {
                continue;
            }

            $saleDate = (string) $row->sale_date;

            if (!array_key_exists($saleDate, $dateIndex)) {
                continue;
            }

            $index = $dateIndex[$saleDate];
            $seriesData[$key][$index] = round((float) $row->total_sales, 2);
        }

        $series = $selectedBranches
            ->map(function (array $branch) use ($seriesData, $labels) {
                return [
                    'name' => $branch['branch_name'],
                    'data' => $seriesData[$branch['key']] ?? array_fill(0, count($labels), 0.0),
                ];
            })
            ->values()
            ->all();

        return [
            'date_from' => $monthStart->toDateString(),
            'date_to' => $monthEnd->toDateString(),
            'labels' => $labels,
            'series' => $series,
        ];
    }

    private function buildLatestSales(int $limit, ?array $branchIds): array
    {
        $query = Sale::query()
            ->with([
                'product:id,name',
                'processedBy:id,name,user_name',
            ]);

        if ($branchIds !== null) {
            $query->whereHas('processedBy.employee', function ($employeeQuery) use ($branchIds) {
                $employeeQuery->whereIn('branch_id', $branchIds);
            });
        }

        return $query
            ->collected()
            ->orderByRaw(Sale::recognizedAtSql().' DESC')
            ->limit($limit)
            ->get()
            ->map(function (Sale $sale) {
                $processorName = $sale->processedBy?->name
                    ?: $sale->processedBy?->user_name
                    ?: 'Unassigned';

                return [
                    'id' => (int) $sale->id,
                    'sale_number' => (string) ($sale->sale_number ?: 'N/A'),
                    'product_name' => (string) ($sale->product?->name ?: 'Unknown product'),
                    'quantity' => (float) $sale->quantity,
                    'quantity_display' => $sale->quantity_display,
                    'total_price' => round((float) $sale->total_price, 2),
                    'processed_by' => $processorName,
                    'sold_at' => $sale->recognizedAt()?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildTopSellingProductsPieChart(
        Carbon $startDate,
        Carbon $endDate,
        ?array $branchIds,
    ): array {
        $query = Sale::query()
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->leftJoin('users', 'sales.processed_by_user_id', '=', 'users.id')
            ->leftJoin('employees', 'users.employee_id', '=', 'employees.id')
            ->whereRaw(Sale::recognizedAtSql().' BETWEEN ? AND ?', [$startDate, $endDate]);

        $query = ManagerBranchScope::constrainJoinedSales($query, $branchIds);

        $rows = $query
            ->selectRaw('products.id as product_id')
            ->selectRaw('products.name as product_name')
            ->selectRaw('COALESCE(SUM(sales.quantity), 0) as total_quantity')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'labels' => [],
                'series' => [],
                'total_quantity' => 0,
            ];
        }

        $topRows = $rows->take(4);
        $otherQuantity = (float) $rows
            ->slice(4)
            ->sum(fn ($row) => (float) $row->total_quantity);

        $labels = $topRows
            ->map(fn ($row) => (string) ($row->product_name ?: 'Unknown product'))
            ->values()
            ->all();

        $series = $topRows
            ->map(fn ($row) => (float) $row->total_quantity)
            ->values()
            ->all();

        if ($otherQuantity > 0) {
            $labels[] = 'Other';
            $series[] = $otherQuantity;
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'total_quantity' => round((float) array_sum($series), 4),
        ];
    }
}
