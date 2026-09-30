<?php
declare(strict_types=1);

/**
 * Write a printable PDF for the PolarVault template sample without Python.
 * Run: php scripts/publish_template_lease_sample.php
 */

$root = dirname(__DIR__);
$txtPath = $root . "/samples/template-style-north-luzon-cold-storage-lease.txt";
$pdfPath = $root . "/samples/template-style-north-luzon-cold-storage-lease.pdf";
$text = file_get_contents($txtPath);
if ($text === false) {
    fwrite(STDERR, "Missing {$txtPath}\n");
    exit(1);
}

function pdf_escape(string $s): string
{
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $s);
}

function wrap_line(string $line, int $max = 92): array
{
    $line = trim($line);
    if ($line === "") {
        return [""];
    }
    $words = preg_split("/\s+/", $line) ?: [];
    $rows = [];
    $cur = "";
    foreach ($words as $word) {
        $try = $cur === "" ? $word : $cur . " " . $word;
        if (strlen($try) > $max && $cur !== "") {
            $rows[] = $cur;
            $cur = $word;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== "") {
        $rows[] = $cur;
    }
    return $rows !== [] ? $rows : [""];
}

$lines = [];
foreach (preg_split("/\R/", $text) as $raw) {
    foreach (wrap_line($raw) as $wrapped) {
        $lines[] = $wrapped;
    }
}

$ops = [];
$ops[] = "0.478 0.059 0.078 rg";
$ops[] = "0 770 612 22 re f";
$ops[] = "0.894 0.757 0.612 rg";
$ops[] = "BT /F1 11 Tf 36 776 Td (" . pdf_escape("ConTrack AI  |  CONTRACT MANAGEMENT SYSTEM") . ") Tj ET";
$ops[] = "1 1 1 rg";
$ops[] = "BT /F1 8 Tf 36 766 Td (" . pdf_escape("Official template sample  -  labeled for NLP extraction") . ") Tj ET";
$ops[] = "0.478 0.059 0.078 rg";
$ops[] = "BT /F2 14 Tf 36 742 Td (" . pdf_escape("North Luzon Cold Storage Facility Lease Agreement 2026") . ") Tj ET";
$ops[] = "0.35 0.35 0.35 rg";
$ops[] = "BT /F1 9 Tf 36 728 Td (" . pdf_escape("Lease Agreement  -  Effective 2026-07-01 to 2028-06-30  -  PHP 4,860,000.00") . ") Tj ET";
$ops[] = "0.894 0.757 0.612 RG 1.2 w 36 722 m 576 722 l S";

$y = 704;
$pageStreams = [];
$current = $ops;

foreach ($lines as $line) {
    if ($y < 56) {
        $pageStreams[] = implode("\n", $current);
        $current = [];
        $y = 750;
    }
    if (preg_match('/^\d+\.\s+/', $line)) {
        $current[] = "0.976 0.933 0.945 rg";
        $current[] = sprintf("36 %.2f 540 14 re f", $y - 3);
        $current[] = "0.478 0.059 0.078 rg";
        $current[] = sprintf("BT /F2 10 Tf 42 %.2f Td (%s) Tj ET", $y, pdf_escape($line));
        $y -= 18;
        continue;
    }
    if ($line === "") {
        $y -= 8;
        continue;
    }
    $current[] = "0.1 0.1 0.1 rg";
    $current[] = sprintf("BT /F1 10 Tf 36 %.2f Td (%s) Tj ET", $y, pdf_escape($line));
    $y -= 14;
}
$pageStreams[] = implode("\n", $current);

$objects = [];
$objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
$pageCount = count($pageStreams);
$pageIds = [];
for ($i = 0; $i < $pageCount; $i++) {
    $pageIds[] = 3 + $i;
}
$kids = implode(" ", array_map(static fn($id) => "{$id} 0 R", $pageIds));
$objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pageCount} >>";

$contentIds = [];
for ($i = 0; $i < $pageCount; $i++) {
    $contentIds[] = 3 + $pageCount + $i;
}
$fontId = 3 + ($pageCount * 2);
$fontBoldId = $fontId + 1;

for ($i = 0; $i < $pageCount; $i++) {
    $cid = $contentIds[$i];
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$cid} 0 R /Resources << /Font << /F1 {$fontId} 0 R /F2 {$fontBoldId} 0 R >> >> >>";
}
foreach ($pageStreams as $stream) {
    $len = strlen($stream);
    $objects[] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
}
$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
$objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $i => $body) {
    $offsets[] = strlen($pdf);
    $num = $i + 1;
    $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
}
$xrefPos = strlen($pdf);
$count = count($objects) + 1;
$pdf .= "xref\n0 {$count}\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i < $count; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}
$pdf .= "trailer << /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

file_put_contents($pdfPath, $pdf);
echo "Wrote samples/template-style-north-luzon-cold-storage-lease.pdf (" . strlen($pdf) . " bytes)\n";
