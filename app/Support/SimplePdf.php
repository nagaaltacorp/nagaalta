<?php

namespace App\Support;

class SimplePdf
{
    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private array $pages = [];

    private string $stream = '';

    private float $y = 800;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->stream !== '') {
            $this->pages[] = $this->stream;
        }

        $this->stream = '';
        $this->y = 800;
    }

    public function ensureSpace(float $needed = 24): void
    {
        if ($this->y - $needed < 50) {
            $this->addPage();
        }
    }

    public function title(string $text): void
    {
        $this->write(50, $this->y, $text, 16, true);
        $this->y -= 26;
    }

    public function heading(string $text): void
    {
        $this->ensureSpace(28);
        $this->y -= 8;
        $this->write(50, $this->y, $text, 12, true);
        $this->y -= 18;
    }

    public function line(string $label, string $value): void
    {
        $this->ensureSpace(16);
        $this->write(50, $this->y, $label, 10, true);
        $this->write(220, $this->y, $value, 10, false);
        $this->y -= 14;
    }

    public function text(string $text): void
    {
        $this->ensureSpace(16);
        $this->write(50, $this->y, $text, 10, false);
        $this->y -= 14;
    }

    public function row(array $columns, array $widths, bool $bold = false): void
    {
        $this->ensureSpace(16);
        $x = 50;

        foreach ($columns as $index => $column) {
            $this->write($x, $this->y, (string) $column, 9, $bold);
            $x += $widths[$index] ?? 80;
        }

        $this->y -= 13;
    }

    public function gap(float $size = 10): void
    {
        $this->y -= $size;
    }

    public function output(): string
    {
        if ($this->stream !== '') {
            $this->pages[] = $this->stream;
            $this->stream = '';
        }

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $pageCount = count($this->pages);
        $pageIds = [];
        $nextId = 3;

        foreach ($this->pages as $index => $unused) {
            $pageIds[$index] = $nextId;
            $nextId += 2;
        }

        $fontRegularId = $nextId;
        $fontBoldId = $nextId + 1;

        $kids = implode(' ', array_map(fn (int $id) => "{$id} 0 R", $pageIds));
        $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pageCount} >>";

        foreach ($this->pages as $index => $content) {
            $pageId = $pageIds[$index];
            $contentId = $pageId + 1;
            $objects[$pageId - 1] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
                $fontRegularId,
                $fontBoldId,
            );
            $objects[$contentId - 1] = '<< /Length '.strlen($content).' >> stream'."\n".$content."\n".'endstream';
        }

        $objects[$fontRegularId - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldId - 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1).' 0 obj '.$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer << /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function write(float $x, float $y, string $text, int $size, bool $bold): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->stream .= sprintf(
            "BT /%s %d Tf %.2f %.2f Td %s Tj ET\n",
            $font,
            $size,
            $x,
            $y,
            $this->literal($text),
        );
    }

    private function literal(string $text): string
    {
        $text = str_replace(['₱', '—', '–'], ['PHP ', '-', '-'], $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }

        $escaped = strtr($converted, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
        ]);

        return '('.$this->truncate($escaped, 90).')';
    }

    private function truncate(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3).'...';
    }
}
