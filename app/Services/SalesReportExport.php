<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\SimplePdf;
use App\Support\SimpleXlsx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SalesReportExport
{
    /**
     * @param  Collection<int, Sale>  $sales
     * @param  array{search?: string, from?: string, to?: string, payment?: string}  $filters
     */
    public static function pdf(Collection $sales, array $filters = []): string
    {
        $pdf = new SimplePdf(true);
        $pdf->title('Naga Alta Agri Corp');
        $pdf->text('Sales Report');
        $pdf->text('Generated '.now()->timezone(DailySalesReportService::TIMEZONE)->format('M j, Y g:i A'));
        $pdf->text(self::filterSummary($filters, $sales->count()));
        $pdf->gap(8);
        $pdf->row(self::headers(), self::widths(), true, 8);

        $total = 0.0;

        foreach ($sales as $sale) {
            $row = self::row($sale);
            $total += (float) $sale->total_price;
            $pdf->row([
                $row['sale_number'],
                $row['product'],
                $row['unit'],
                $row['quantity'],
                $row['vat'],
                $row['discount'],
                number_format((float) $sale->total_price, 2),
                $row['processed_by'],
                $row['date'],
            ], self::widths(), false, 8);
        }

        $pdf->gap(8);
        $pdf->text('Rows: '.$sales->count());
        $pdf->text('Grand total: PHP '.number_format($total, 2));

        return $pdf->output();
    }

    /**
     * @param  Collection<int, Sale>  $sales
     */
    public static function excel(Collection $sales): string
    {
        $rows = [];
        $total = 0.0;

        foreach ($sales as $sale) {
            $row = self::row($sale);
            $total += (float) $sale->total_price;
            $rows[] = [
                $row['sale_number'],
                $row['product'],
                $row['unit'],
                $row['quantity'],
                (float) $sale->vat_rate,
                (float) $sale->vat_amount,
                (float) $sale->discount_percent,
                (float) $sale->discount_amount,
                (float) $sale->total_price,
                $row['payment'],
                $row['note'],
                $row['processed_by'],
                $row['date'],
            ];
        }

        $rows[] = ['', '', '', '', '', '', '', 'Grand total', round($total, 2), '', '', '', ''];

        return SimpleXlsx::make([
            'Sale Number',
            'Product',
            'Unit Type',
            'Quantity',
            'VAT Rate',
            'VAT Amount',
            'Discount %',
            'Discount Amount',
            'Total Price',
            'Payment',
            'Note',
            'Processed By',
            'Date',
        ], $rows);
    }

    /**
     * @return array<int, string>
     */
    private static function headers(): array
    {
        return [
            'Sale Number',
            'Product',
            'Unit',
            'Qty',
            'VAT',
            'Discount',
            'Total',
            'Processed By',
            'Date',
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function widths(): array
    {
        return [108, 168, 46, 32, 78, 78, 52, 108, 108];
    }

    /**
     * @return array<string, string>
     */
    private static function row(Sale $sale): array
    {
        $payment = strtolower(trim((string) ($sale->payment_method ?: 'cash')));
        $notes = [];

        if ($payment === 'utang') {
            $notes[] = $sale->paid_at ? 'Utang paid' : 'Utang';
        }

        if ($sale->is_replacement) {
            $notes[] = 'Replacement';
        } elseif ($sale->is_replaced) {
            $notes[] = 'Replaced';
        }

        $product = $sale->product?->name ?: '-';

        if ($notes !== []) {
            $product .= ' ('.implode(', ', $notes).')';
        }

        $vat = (float) $sale->vat_amount > 0
            ? number_format((float) $sale->vat_rate, 2).'% / '.number_format((float) $sale->vat_amount, 2)
            : '-';
        $discount = (float) $sale->discount_percent > 0
            ? number_format((float) $sale->discount_percent, 2).'% / '.number_format((float) $sale->discount_amount, 2)
            : '-';
        $recognized = $sale->recognizedAt()?->timezone(DailySalesReportService::TIMEZONE);

        return [
            'sale_number' => (string) ($sale->sale_number ?: '-'),
            'product' => $product,
            'unit' => self::unitLabel($sale),
            'quantity' => (string) $sale->quantity_display,
            'vat' => $vat,
            'discount' => $discount,
            'payment' => $payment === 'utang' ? 'Utang' : ucfirst($payment),
            'note' => $notes === [] ? '' : implode(', ', $notes),
            'processed_by' => $sale->processedBy?->name
                ?: $sale->processedBy?->user_name
                ?: $sale->processedBy?->email
                ?: '-',
            'date' => $recognized?->format('M j, Y g:i A') ?? '-',
        ];
    }

    private static function unitLabel(Sale $sale): string
    {
        $unitType = trim((string) $sale->unit_type);

        if ($unitType !== '') {
            return ucfirst($unitType);
        }

        return trim((string) ($sale->product?->unit ?? '')) ?: '-';
    }

    /**
     * @param  array{search?: string, from?: string, to?: string, payment?: string}  $filters
     */
    private static function filterSummary(array $filters, int $count): string
    {
        $parts = [$count.' sale line(s)'];
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $payment = (string) ($filters['payment'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        if ($from !== '' || $to !== '') {
            $parts[] = trim(($from !== '' ? $from : 'start').' to '.($to !== '' ? $to : 'today'));
        }

        if ($payment === 'utang') {
            $parts[] = 'Utang paid';
        } elseif ($payment === 'cash') {
            $parts[] = 'Cash and other';
        }

        if ($search !== '') {
            $parts[] = 'Search: '.$search;
        }

        return implode(' | ', $parts);
    }
}
