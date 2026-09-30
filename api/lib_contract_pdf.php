<?php
declare(strict_types=1);

/**
 * Original vendor PDFs stored on disk (project storage/, not committed).
 * Filename = sanitized contract id + ".pdf".
 */

function contract_pdf_safe_id(string $id): string
{
    $safe = preg_replace("/[^a-zA-Z0-9\-]/", "", $id);
    return $safe !== "" ? $safe : "invalid";
}

function contract_pdf_storage_dir(): string
{
    $base = dirname(__DIR__) . "/storage/contract_pdfs";
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

function contract_pdf_absolute_path(string $contractId): string
{
    return contract_pdf_storage_dir() . "/" . contract_pdf_safe_id($contractId) . ".pdf";
}

/**
 * Decode base64 PDF body, validate magic/size, write next to contract id.
 */
function contract_pdf_save_base64(string $contractId, string $base64): bool
{
    $trimmed = preg_replace("/\s+/", "", $base64);
    $raw = base64_decode($trimmed, true);
    if ($raw === false || strlen($raw) < 8) {
        return false;
    }
    if (substr($raw, 0, 4) !== "%PDF") {
        return false;
    }
    $maxBytes = 26 * 1024 * 1024;
    if (strlen($raw) > $maxBytes) {
        return false;
    }
    $path = contract_pdf_absolute_path($contractId);
    return file_put_contents($path, $raw, LOCK_EX) !== false;
}

function contract_pdf_file_exists(string $contractId): bool
{
    $path = contract_pdf_absolute_path($contractId);
    return is_file($path) && is_readable($path);
}
