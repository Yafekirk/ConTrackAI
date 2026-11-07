<?php
declare(strict_types=1);

/**
 * Original vendor PDFs. The durable copy lives in a private Supabase Storage bucket
 * (CONTRACK_PDF_BUCKET, default "contract-pdfs", created on first upload) because the hosted
 * container's disk is wiped on every deploy or restart. storage/contract_pdfs/ on disk is only a
 * cache. Object and file name = sanitized contract id + ".pdf".
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

function contract_pdf_bucket(): string
{
    $bucket = preg_replace("/[^a-z0-9\-_]/", "", strtolower(trim((string)env_value("CONTRACK_PDF_BUCKET", "contract-pdfs"))));
    return $bucket !== "" ? $bucket : "contract-pdfs";
}

/**
 * One Supabase Storage API call.
 * @return array{status:int, body:string}
 */
function contract_pdf_storage_call(string $method, string $path, ?string $body = null, string $contentType = "application/json", array $extraHeaders = []): array
{
    if (!function_exists("curl_init")) {
        return ["status" => 0, "body" => "PHP cURL is not enabled."];
    }
    $base = rtrim((string)env_value("SUPABASE_URL", ""), "/");
    $key = (string)env_value("SUPABASE_SERVICE_ROLE_KEY", "");
    if ($base === "" || $key === "") {
        return ["status" => 0, "body" => "Supabase is not configured."];
    }
    $ch = curl_init($base . "/storage/v1/" . ltrim($path, "/"));
    $headers = array_merge(["apikey: {$key}", "Authorization: Bearer {$key}", "Content-Type: {$contentType}"], $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $tlsInsecure = env_flag("SUPABASE_TLS_INSECURE", false);
    $caBundle = $tlsInsecure ? null : resolve_supabase_ca_bundle();
    if ($caBundle !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$tlsInsecure);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $tlsInsecure ? 0 : 2);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ["status" => $status, "body" => is_string($response) ? $response : $error];
}

function contract_pdf_remote_upload(string $contractId, string $raw): bool
{
    $object = rawurlencode(contract_pdf_bucket()) . "/" . contract_pdf_safe_id($contractId) . ".pdf";
    $upload = static fn(): array => contract_pdf_storage_call("POST", "object/{$object}", $raw, "application/pdf", ["x-upsert: true"]);
    $res = $upload();
    if ($res["status"] >= 400 && stripos($res["body"], "bucket") !== false) {
        // First upload on this project: create the private bucket, then retry once.
        contract_pdf_storage_call("POST", "bucket", (string)json_encode([
            "id" => contract_pdf_bucket(),
            "name" => contract_pdf_bucket(),
            "public" => false,
        ]));
        $res = $upload();
    }
    if ($res["status"] < 200 || $res["status"] >= 300) {
        error_log("contract PDF upload to storage failed ({$res["status"]}): " . substr($res["body"], 0, 500));
        return false;
    }
    return true;
}

function contract_pdf_remote_download(string $contractId): ?string
{
    $object = rawurlencode(contract_pdf_bucket()) . "/" . contract_pdf_safe_id($contractId) . ".pdf";
    $res = contract_pdf_storage_call("GET", "object/authenticated/{$object}", null, "application/json");
    if ($res["status"] === 200 && str_starts_with($res["body"], "%PDF")) {
        return $res["body"];
    }
    if ($res["status"] !== 404 && $res["status"] !== 400) {
        error_log("contract PDF download from storage failed ({$res["status"]}): " . substr($res["body"], 0, 300));
    }
    return null;
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
