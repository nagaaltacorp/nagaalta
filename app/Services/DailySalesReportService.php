<?php

namespace App\Services;

use App\Models\DailySalesReport;
use App\Models\Sale;
use App\Models\User;
use App\Support\SaleQuantity;
use App\Support\SimplePdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DailySalesReportService
{
    public const TIMEZONE = 'Asia/Manila';

    public static function resolveCashierContext(User $user): array
    {
        $user->loadMissing([
            'employee.branch:id,name,location',
            'managedBranch:id,name,location',
        ]);

        $branch = $user->employee?->branch ?? $user->managedBranch;
        $branchId = $branch?->id;

        if ($branchId === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'This cashier is not assigned to a branch.',
            ], 422));
        }

        $cashierName = trim(implode(' ', array_filter([
            $user->employee?->first_name,
            $user->employee?->last_name,
        ])));

        if ($cashierName === '') {
            $cashierName = $user->name ?: $user->user_name ?: 'Cashier';
        }

        return [
            'user' => $user,
            'branch_id' => (int) $branchId,
            'branch_name' => $branch->name,
            'cashier_name' => $cashierName,
        ];
    }

    public static function preview(User $user, string $date): array
    {
        $context = self::resolveCashierContext($user);
        $snapshot = self::buildSnapshot((int) $user->id, $context['branch_id'], $date);
        $existing = DailySalesReport::query()
            ->where('submitted_by_user_id', $user->id)
            ->where('branch_id', $context['branch_id'])
            ->whereDate('report_date', $date)
            ->first();

        return [
            'report_date' => $date,
            'timezone' => self::TIMEZONE,
            'branch_id' => $context['branch_id'],
            'branch_name' => $context['branch_name'],
            'cashier_name' => $context['cashier_name'],
            'already_submitted' => $existing !== null,
            'submitted_report_id' => $existing?->id,
            'submitted_at' => optional($existing?->submitted_at)?->toIso8601String(),
            ...$snapshot,
        ];
    }

    public static function submit(User $user, string $date, ?string $notes, mixed $cashCounted): DailySalesReport
    {
        $context = self::resolveCashierContext($user);
        $snapshot = self::buildSnapshot((int) $user->id, $context['branch_id'], $date);
        $values = [
            'cashier_name' => $context['cashier_name'],
            'branch_name' => $context['branch_name'],
            'receipt_count' => $snapshot['receipt_count'],
            'line_count' => $snapshot['line_count'],
            'total_sales' => $snapshot['total_sales'],
            'total_vat' => $snapshot['total_vat'],
            'total_discount' => $snapshot['total_discount'],
            'replacement_extra' => $snapshot['replacement_extra'],
            'cash_counted' => $cashCounted === null || $cashCounted === ''
                ? null
                : round((float) $cashCounted, 2),
            'notes' => $notes ? substr(trim($notes), 0, 500) : null,
            'payment_breakdown' => $snapshot['payment_breakdown'],
            'items' => $snapshot['items'],
            'submitted_at' => now(),
        ];

        $report = DailySalesReport::query()
            ->where('submitted_by_user_id', $user->id)
            ->where('branch_id', $context['branch_id'])
            ->whereDate('report_date', $date)
            ->first();

        if ($report) {
            $report->update($values);
        } else {
            $report = DailySalesReport::create([
                ...$values,
                'submitted_by_user_id' => $user->id,
                'branch_id' => $context['branch_id'],
                'report_date' => $date,
                'report_number' => 'TMP-'.Str::upper(Str::random(12)),
            ]);

            $report->report_number = sprintf(
                'DSR-%s-%06d',
                Carbon::parse($date)->format('Ymd'),
                (int) $report->id,
            );
            $report->save();
        }

        return $report->fresh(['branch:id,name,location', 'submittedBy:id,name,user_name']);
    }

    public static function toPdf(DailySalesReport $report): string
    {
        $pdf = new SimplePdf();
        $pdf->title('Naga Alta Agri Corp');
        $pdf->text('Daily Cashier Sales Report');
        $pdf->gap(6);
        $pdf->line('Report no.', (string) $report->report_number);
        $pdf->line('Date', $report->report_date?->format('F j, Y') ?? '-');
        $pdf->line('Branch', (string) ($report->branch_name ?: $report->branch?->name ?: '-'));
        $pdf->line('Cashier', (string) ($report->cashier_name ?: '-'));
        $pdf->line('Submitted', $report->submitted_at?->timezone(self::TIMEZONE)->format('M j, Y g:i A') ?? '-');

        $pdf->heading('Totals');
        $pdf->line('Receipts', (string) $report->receipt_count);
        $pdf->line('Sold lines', (string) $report->line_count);
        $pdf->line('Total sales', self::money($report->total_sales));
        $pdf->line('VAT', self::money($report->total_vat));
        $pdf->line('Discounts', self::money($report->total_discount));
        $pdf->line('Replacement extra', self::money($report->replacement_extra));

        if ($report->cash_counted !== null) {
            $pdf->line('Cash counted', self::money($report->cash_counted));
        }

        $pdf->heading('Payments');
        $payments = $report->payment_breakdown ?? [];

        if ($payments === []) {
            $pdf->text('No payments recorded.');
        } else {
            $pdf->row(['Method', 'Count', 'Amount'], [160, 80, 120], true);

            foreach ($payments as $payment) {
                $pdf->row([
                    strtoupper((string) ($payment['method'] ?? 'cash')),
                    (string) ($payment['count'] ?? 0),
                    self::money($payment['amount'] ?? 0),
                ], [160, 80, 120]);
            }
        }

        $pdf->heading('Products');
        $items = $report->items ?? [];

        if ($items === []) {
            $pdf->text('No products sold.');
        } else {
            $pdf->row(['Product', 'Unit', 'Qty', 'Amount'], [220, 70, 70, 90], true);

            foreach ($items as $item) {
                $name = (string) ($item['name'] ?? 'Product');

                if (!empty($item['is_replacement'])) {
                    $name .= ' (replacement)';
                }

                $pdf->row([
                    $name,
                    (string) ($item['unit_type'] ?? '-'),
                    (string) ($item['quantity_display'] ?? $item['quantity'] ?? '0'),
                    self::money($item['amount'] ?? 0),
                ], [220, 70, 70, 90]);
            }
        }

        if ($report->notes) {
            $pdf->heading('Notes');
            $pdf->text((string) $report->notes);
        }

        $pdf->gap(18);
        $pdf->text('This report was sent by the cashier from the POS app.');

        return $pdf->output();
    }

    private static function buildSnapshot(int $userId, int $branchId, string $date): array
    {
        $sales = self::salesForDay($userId, $branchId, $date);
        $receipts = $sales
            ->pluck('sale_number')
            ->filter()
            ->unique()
            ->count();

        $payments = $sales
            ->groupBy(fn (Sale $sale) => strtolower(trim((string) ($sale->payment_method ?: 'cash'))) ?: 'cash')
            ->map(fn (Collection $group, string $method) => [
                'method' => $method,
                'count' => $group->pluck('sale_number')->filter()->unique()->count() ?: $group->count(),
                'amount' => round((float) $group->sum('total_price'), 2),
            ])
            ->values()
            ->all();

        $items = $sales
            ->groupBy(fn (Sale $sale) => $sale->product_id.'|'.strtolower((string) $sale->unit_type).'|'.((int) $sale->is_replacement))
            ->map(function (Collection $group) {
                $first = $group->first();
                $quantity = round((float) $group->sum('quantity'), 4);

                return [
                    'product_id' => $first?->product_id,
                    'name' => $first?->product?->name ?? 'Product',
                    'unit_type' => $first?->unit_type,
                    'quantity' => $quantity,
                    'quantity_display' => SaleQuantity::display($quantity),
                    'amount' => round((float) $group->sum('total_price'), 2),
                    'is_replacement' => (bool) $first?->is_replacement,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'receipt_count' => $receipts,
            'line_count' => $sales->count(),
            'total_sales' => round((float) $sales->sum('total_price'), 2),
            'total_vat' => round((float) $sales->sum('vat_amount'), 2),
            'total_discount' => round((float) $sales->sum('discount_amount'), 2),
            'replacement_extra' => round(
                (float) $sales->where('is_replacement', true)->sum('total_price'),
                2,
            ),
            'payment_breakdown' => $payments,
            'items' => $items,
            'first_sale_at' => optional($sales->min('created_at'))?->toIso8601String(),
            'last_sale_at' => optional($sales->max('created_at'))?->toIso8601String(),
        ];
    }

    private static function salesForDay(int $userId, int $branchId, string $date): Collection
    {
        [$start, $end] = self::dayBounds($date);

        return Sale::query()
            ->with(['product:id,name,unit'])
            ->where('processed_by_user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function dayBounds(string $date): array
    {
        $day = Carbon::parse($date, self::TIMEZONE);

        return [
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        ];
    }

    private static function money(mixed $value): string
    {
        return 'PHP '.number_format((float) $value, 2);
    }
}
