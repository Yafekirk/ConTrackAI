<?php
declare(strict_types=1);

/**
 * Original vendor PDFs. The durable copy lives in the contract_pdf_files table (base64, written
 * through the same REST connection as everything else) because the hosted container's disk is
 * wiped on every deploy or restart. storage/contract_pdfs/<sanitized id>.pdf on disk is only a cache.
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

function contract_pdf_remote_upload(string $contractId, string $raw): bool
{
    $id = rawurlencode($contractId);
    supabase_request("DELETE", "contract_pdf_files?contract_id=eq.{$id}");
    $res = supabase_request("POST", "contract_pdf_files?select=contract_id", [[
        "contract_id" => $contractId,
        "pdf_base64" => base64_encode($raw),
        "size_bytes" => strlen($raw),
    ]]);
    if (!$res["ok"]) {
        error_log("contract PDF save to database failed: " . substr((string)($res["raw"] ?? $res["error"] ?? ""), 0, 500));
        return false;
    }
    return true;
}

function contract_pdf_remote_download(string $contractId): ?string
{
    $id = rawurlencode($contractId);
    $res = supabase_request("GET", "contract_pdf_files?contract_id=eq.{$id}&select=pdf_base64&limit=1");
    $b64 = ($res["ok"] && is_array($res["data"][0] ?? null)) ? (string)($res["data"][0]["pdf_base64"] ?? "") : "";
    if ($b64 === "") {
        return null;
    }
    $raw = base64_decode($b64, true);
    return ($raw !== false && str_starts_with($raw, "%PDF")) ? $raw : null;
}

/** Save a PDF for the contract: durable copy in storage plus a local cache. True when either copy was written. */
function contract_pdf_store(string $contractId, string $raw): bool
{
    if (strlen($raw) < 8 || substr($raw, 0, 4) !== "%PDF" || strlen($raw) > 26 * 1024 * 1024) {
        return false;
    }
    $local = @file_put_contents(contract_pdf_absolute_path($contractId), $raw, LOCK_EX) !== false;
    $remote = contract_pdf_remote_upload($contractId, $raw);
    return $remote || $local;
}

/** The stored PDF bytes, from the local cache or else from storage (then cached). Null when none exists. */
function contract_pdf_load(string $contractId): ?string
{
    $path = contract_pdf_absolute_path($contractId);
    if (is_file($path) && is_readable($path)) {
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== "") {
            return $raw;
        }
    }
    $raw = contract_pdf_remote_download($contractId);
    if ($raw !== null) {
        @file_put_contents($path, $raw, LOCK_EX);
    }
    return $raw;
}

/**
 * Decode base64 PDF body, validate magic/size, and store it for the contract.
 */
function contract_pdf_save_base64(string $contractId, string $base64): bool
{
    $trimmed = preg_replace("/\s+/", "", $base64);
    $raw = base64_decode($trimmed, true);
    if ($raw === false) {
        return false;
    }
    return contract_pdf_store($contractId, $raw);
}

function contract_pdf_file_exists(string $contractId): bool
{
    $path = contract_pdf_absolute_path($contractId);
    return is_file($path) && is_readable($path);
}
