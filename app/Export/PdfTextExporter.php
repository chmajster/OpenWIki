<?php

declare(strict_types=1);

namespace OpenWiki\Export;

final class PdfTextExporter
{
    public function build(string $title, string $text): string
    {
        $plain = trim($title) . "\n\n" . trim($text);
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $plain);
        if (!is_string($encoded)) {
            $encoded = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $plain) ?? $plain;
        }

        $lines = [];
        foreach (preg_split('/\R/', $encoded) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $lines[] = '';
                continue;
            }

            foreach (explode("\n", wordwrap($line, 92, "\n", true)) as $wrapped) {
                $lines[] = $wrapped;
            }
        }

        if ($lines === []) {
            $lines = [''];
        }

        $pages = array_chunk($lines, 52);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $kids = [];
        foreach ($pages as $index => $pageLines) {
            $pageObject = 4 + ($index * 2);
            $contentObject = $pageObject + 1;
            $kids[] = $pageObject . ' 0 R';

            $stream = "BT\n/F1 10 Tf\n50 792 Td\n14 TL\n";
            foreach ($pageLines as $line) {
                $stream .= '(' . $this->escapePdfText($line) . ") Tj\nT*\n";
            }
            $stream .= "ET\n";

            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                . '/Resources << /Font << /F1 3 0 R >> >> /Contents '
                . $contentObject . ' 0 R >>';
            $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n"
                . $stream . "endstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count '
            . count($pages) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i < $size; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private function escapePdfText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('(', '\\(', $value);
        $value = str_replace(')', '\\)', $value);
        return str_replace(["\r", "\n"], '', $value);
    }
}
