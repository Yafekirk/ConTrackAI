<?php
declare(strict_types=1);

define("CONTRACK_SKIP_JSON_HEADER", true);
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_contract_pdf.php";

$fail = static function (int $status, string $message): never {
    http_response_code($status);
    header("Content-Type: text/plain; charset=utf-8");
    echo $message;
    exit;
};

$contractId = trim(trim((string)($_GET["id"] ?? "")), "\"'");
if ($contractId === "") {
    $fail(400, "Missing contract id");
}
if (get_session_user() === null || !contrack_session_revalidate()) {
    $fail(401, "Sign in required");
}

$role = normalize_role(get_request_role());
$userReqId = get_request_user_id();

$encId = rawurlencode($contractId);
// Prefer all columns; retry without submission_text if that column is not migrated yet (otherwise PostgREST errors).
$result = supabase_request("GET", "vendor_contracts?id=eq.{$encId}&select=*&limit=1");
if (!$result["ok"]) {
    $result = supabase_request("GET", "vendor_contracts?id=eq.{$encId}&select=id,vendor_id&limit=1");
}
if (!$result["ok"]) {
    $fail(502, "Unable to load contract (database error).");
}
if (!is_array($result["data"]) || !isset($result["data"][0])) {
    $fail(404, "Contract not found");
}

$row = $result["data"][0];
$vendorId = isset($row["vendor_id"]) ? (int)$row["vendor_id"] : 0;
$allowed = $role === "vendor"
    ? $userReqId !== null && $vendorId === (int)$userReqId
    : in_array($role, ["manager", "ceo", "admin"], true);
if (!$allowed) {
    $fail(403, "Forbidden");
}

$path = contract_pdf_absolute_path($contractId);
if (!is_file($path) || !is_readable($path)) {
    $sub = trim((string)($row["submission_text"] ?? ""));
    if ($sub === "") {
        $fail(404, "Original PDF is not on file for this contract.");
    }
    header("Content-Type: text/html; charset=utf-8");
    header("Content-Disposition: inline");
    header("Cache-Control: private, max-age=300");
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">";
    echo "<title>Contract submission</title>";
    echo "<style>body{font-family:system-ui,-apple-system,sans-serif;margin:24px;line-height:1.5;color:#222}";
    echo ".banner{background:#fff8e6;border:1px solid #e6d6a8;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px}";
    echo "pre{white-space:pre-wrap;word-break:break-word;background:#f6f6f6;padding:16px;border-radius:8px;border:1px solid #ddd;font-size:13px}</style></head><body>";
    echo "<h1 style=\"font-size:18px;margin:0 0 8px\">Submitted contract</h1>";
    echo "<div class=\"banner\">No PDF file was stored for this contract (often fixed by resubmitting with the PDF attached). Showing extracted or pasted text.</div><pre>";
    echo htmlspecialchars($sub, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    echo "</pre></body></html>";
    exit;
}

$size = filesize($path);
if ($size === false) {
    $fail(500, "Unable to read file");
}
header("Content-Type: application/pdf");
header("Content-Length: " . (string)$size);
header("Content-Disposition: inline; filename=\"contract-" . contract_pdf_safe_id($contractId) . ".pdf\"");
header("Cache-Control: private, max-age=3600");
header("X-Content-Type-Options: nosniff");
readfile($path);
