<?php

namespace App\Support;

class SimplePdf
{
    private float $pageWidth = 595.28;

    private float $pageHeight = 841.89;

    private array $pages = [];

    private string $stream = '';

    private float $y = 800;

    public function __construct(bool $landscape = false)
    {
        if ($landscape) {
            $this->pageWidth = 841.89;
            $this->pageHeight = 595.28;
        }

        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->stream !== '') {
            $this->pages[] = $this->stream;
        }

        $this->stream = '';
        $this->y = $this->pageHeight - 42;
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

    public function row(array $columns, array $widths, bool $bold = false, int $size = 8): void
    {
        $wrapped = [];
        $maxLines = 1;

        foreach ($columns as $index => $column) {
            $width = max(12, ($widths[$index] ?? 80) - 6);
            $lines = $this->wrap((string) $column, $width, $size, $bold);
            $wrapped[] = $lines;
            $maxLines = max($maxLines, count($lines));
        }

        $lineHeight = $size + 3;
        $rowHeight = ($maxLines * $lineHeight) + 3;
        $this->ensureSpace($rowHeight + 2);

        $x = 24;

        foreach ($wrapped as $index => $lines) {
            $lineY = $this->y;

            foreach ($lines as $line) {
                $this->write($x, $lineY, $line, $size, $bold);
                $lineY -= $lineHeight;
            }

            $x += $widths[$index] ?? 80;
        }

        $this->y -= $rowHeight;
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
                $this->pageWidth,
                $this->pageHeight,
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

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $width, int $size, bool $bold): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($text === '') {
            return [''];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textWidth($candidate, $size, $bold) <= $width) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if ($this->textWidth($word, $size, $bold) <= $width) {
                $current = $word;
                continue;
            }

            $chunk = '';

            foreach (str_split($word) as $char) {
                $next = $chunk.$char;

                if ($chunk !== '' && $this->textWidth($next, $size, $bold) > $width) {
                    $lines[] = $chunk;
                    $chunk = $char;
                    continue;
                }

                $chunk = $next;
            }

            $current = $chunk;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines === [] ? [''] : $lines, 0, 3);
    }

    private function textWidth(string $text, int $size, bool $bold): float
    {
        $width = 0.0;
        $factor = $bold ? 1.05 : 1.0;
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $width += $this->charWidth($text[$index]) * $size * $factor;
        }

        return $width;
    }

    private function charWidth(string $char): float
    {
        return match ($char) {
            ' ', '.', ',', ':', ';', '\'', '|' => 0.30,
            'i', 'l', 'I', 'j', 'f', 't', 'r', '-' => 0.36,
            'm', 'w', 'M', 'W' => 0.84,
            default => ctype_digit($char) ? 0.62 : (ctype_upper($char) ? 0.72 : 0.56),
        };
    }
}
