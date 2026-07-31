<?php

declare(strict_types=1);

function pdfEscapeString(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    $text = str_replace(["\r", "\n"], ['',' '], $text);
    return $text;
}

function pdfBuildStream(array $lines): string
{
    $output = '';
    $x = 40;
    $y = 780;
    foreach ($lines as $index => $line) {
        $fontSize = $index === 0 ? 18 : 12;
        $output .= "BT /F1 {$fontSize} Tf 1 0 0 1 {$x} {$y} Tm (" . pdfEscapeString($line) . ") Tj ET\n";
        $y -= $fontSize + 8;
    }
    return $output;
}

function pdfBuildDocument(array $pageStreams): string
{
    $objects = [];
    $nextId = 1;

    $fontObject = "{$nextId} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $fontId = $nextId++;
    $objects[] = $fontObject;

    $pageIds = [];
    $contentIds = [];

    foreach ($pageStreams as $pageStream) {
        $contentId = $nextId++;
        $contentLength = strlen($pageStream);
        $contentObject = "{$contentId} 0 obj\n<< /Length {$contentLength} >>\nstream\n{$pageStream}endstream\nendobj\n";
        $contentIds[] = $contentId;
        $objects[] = $contentObject;

        $pageId = $nextId++;
        $pageObject = "{$pageId} 0 obj\n<< /Type /Page /Parent %PARENT% 0 R /MediaBox [0 0 595 842] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>\nendobj\n";
        $pageIds[] = $pageId;
        $objects[] = $pageObject;
    }

    $pagesId = $nextId++;
    $kids = implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", $pageIds));
    $pagesObject = "{$pagesId} 0 obj\n<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>\nendobj\n";
    $objects[] = $pagesObject;

    $catalogId = $nextId++;
    $catalogObject = "{$catalogId} 0 obj\n<< /Type /Catalog /Pages {$pagesId} 0 R >>\nendobj\n";
    $objects[] = $catalogObject;

    // Replace the page parent placeholders
    foreach ($pageIds as $index => $pageId) {
        $objects[2 + ($index * 2)] = str_replace('%PARENT%', (string) $pagesId, $objects[2 + ($index * 2)]);
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xref = "xref\n0 " . ($nextId) . "\n";
    $xref .= sprintf('%010d 65535 f \n', 0);
    foreach ($offsets as $offset) {
        $xref .= sprintf('%010d 00000 n \n', $offset);
    }

    $pdf .= $xref;
    $trailer = "trailer\n<< /Size {$nextId} /Root {$catalogId} 0 R >>\nstartxref\n" . strlen($pdf) . "\n%%EOF";
    $pdf .= $trailer;

    return $pdf;
}

function pdfChunkLines(array $lines, int $maxLinesPerPage = 38): array
{
    return array_chunk($lines, $maxLinesPerPage);
}

function outputSimplePdf(string $fileName, string $title, array $headers, array $rows): void
{
    $lines = [];
    $lines[] = $title;
    $lines[] = '';
    $lines[] = implode(' | ', $headers);
    $lines[] = str_repeat('-', 120);

    foreach ($rows as $row) {
        $lineValues = array_map(static fn($value) => is_array($value) ? implode(' ', $value) : (string) $value, $row);
        $lines[] = implode(' | ', $lineValues);
    }

    $pageGroups = pdfChunkLines($lines);
    $streams = array_map(static fn(array $group) => pdfBuildStream($group), $pageGroups);
    $pdf = pdfBuildDocument($streams);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
