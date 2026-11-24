<?php

namespace App\Support;

/**
 * Tiny dependency-free PDF writer (A4, Helvetica) — enough for payslips.
 * Each line: [text, x, y, size, bold?]. Rectangles: [x, y, w, h, grayFill].
 */
class SimplePdf
{
    private array $lines = [];
    private array $rects = [];

    public function text(string $text, float $x, float $y, float $size = 11, bool $bold = false): static
    {
        $this->lines[] = [$text, $x, $y, $size, $bold];

        return $this;
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray = 0.93): static
    {
        $this->rects[] = [$x, $y, $w, $h, $gray];

        return $this;
    }

    private static function esc(string $s): string
    {
        $s = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: $s;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function output(): string
    {
        $h = 842; // A4 height in points; we take y from the top
        $stream = '';
        foreach ($this->rects as [$x, $y, $w, $hh, $g]) {
            $stream .= sprintf("%.2f g %.2f %.2f %.2f %.2f re f 0 g\n", $g, $x, $h - $y - $hh, $w, $hh);
        }
        foreach ($this->lines as [$t, $x, $y, $size, $bold]) {
            $stream .= sprintf("BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $h - $y, self::esc($t));
        }

        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        return $pdf."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
