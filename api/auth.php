<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_login_guard.php";

$method = request_method();
if ($method === "GET") {
    json_response(login_guard_payload());
}
require_method("POST");
login_guard_reject_if_locked();

$input = json_body();
$email = strtolower(trim((string)($input["email"] ?? "")));
$password = (string)($input["password"] ?? "");
if ($email === "" || $password === "") {
    json_error("Email and password are required");
}

// The account lookup runs alongside the lock checks; its result is only used once no lock applies.
// Terms status is a separate query so a database without the terms columns still signs in.
$lookups = login_guard_reject_if_server_locked($email, [
    "user" => ["GET", "users?email=eq." . urlencode($email) . "&select=id,name,company_name,email,role,password,is_disabled,approval_status,last_login_at,last_seen_at&limit=1"],
    "terms" => ["GET", "users?email=eq." . urlencode($email) . "&select=terms_accepted_at&limit=1"],
]);
$result = $lookups["user"];
$termsRow = ($lookups["terms"]["ok"] ?? false) && is_array($lookups["terms"]["data"] ?? null) ? ($lookups["terms"]["data"][0] ?? null) : null;
$termsAccepted = is_array($termsRow) && is_string($termsRow["terms_accepted_at"] ?? null) && $termsRow["terms_accepted_at"] !== "";

if (!$result["ok"]) {
    $hint = $result["error"] ?? null;
    $raw = is_string($result["raw"] ?? null) ? $result["raw"] : "";
    $message = "Authentication backend request failed";
    // Configuration hints are only shown when CONTRACK_DEBUG=true; the detail is always logged.
    $debug = env_flag("CONTRACK_DEBUG", false);
    if (stripos($raw, "Invalid API key") !== false || stripos($raw, "JWT") !== false) {
        error_log("auth: Supabase key rejected: " . substr($raw, 0, 300));
        $message = $debug ? "Supabase key is invalid. Check SUPABASE_SERVICE_ROLE_KEY in your PowerShell session." : $message;
    } elseif (stripos($raw, "column") !== false && stripos($raw, "does not exist") !== false) {
        error_log("auth: schema outdated: " . substr($raw, 0, 300));
        $message = $debug ? "Database schema is outdated. Run sql/schema_phase1.sql in Supabase (users.is_disabled / audit_logs)." : $message;
    } elseif ($hint) {
        error_log("auth: backend error: " . substr((string)$hint, 0, 300));
        $message = $debug ? $hint : $message;
    }
    json_error($message, $result["status"] >= 400 ? $result["status"] : 500);
}

$user = is_array($result["data"]) ? ($result["data"][0] ?? null) : null;
$userId = (int)($user["id"] ?? 0);
$needsUpgrade = false;
$failure = match (true) {
    $user === null => "unknown_email",
    !empty($user["is_disabled"]) => "account_disabled",
    !contrack_password_matches((string)($user["password"] ?? ""), $password, $needsUpgrade) => "bad_password",
    default => null,
};
if ($failure !== null) {
    audit_log_event("login_failed", ["reason" => $failure, "email" => $email], $user === null ? null : $userId);
    login_guard_reject_auth_failure();
}

$approvalStatus = (string)($user["approval_status"] ?? "approved");
if ($approvalStatus !== "approved") {
    audit_log_event("login_blocked", ["reason" => "approval_{$approvalStatus}", "email" => $email], $userId);
    json_response([
        "approval_status" => $approvalStatus,
        "error" => $approvalStatus === "pending"
            ? "Your account is still waiting for CEO approval. You will receive an email once it is approved."
            : "Your account request was not approved. Please contact the company for details.",
    ], 403);
}

// Auto-upgrade legacy plain text passwords (or outdated hashes) on successful login.
$now = gmdate("c");
$idEnc = urlencode((string)$user["id"]);
$writes = ["seen" => ["PATCH", "users?id=eq.{$idEnc}", ["last_login_at" => $now, "last_seen_at" => $now]]];
if ($needsUpgrade) {
    $writes["upgrade"] = ["PATCH", "users?id=eq.{$idEnc}", ["password" => password_hash($password, PASSWORD_DEFAULT)]];
}

unset($user["password"], $user["approval_status"]);
login_guard_clear();
contrack_start_authenticated_session($user);
$writes["audit"] = audit_log_request("login_success", ["email" => $user["email"] ?? $email], (int)$user["id"]);
$written = supabase_request_many($writes);
if (!$written["audit"]["ok"]) {
    error_log("audit_logs insert failed: " . (string)($written["audit"]["raw"] ?? $written["audit"]["error"] ?? ""));
}
json_response(["user" => $user] + ($termsAccepted ? ["terms_accepted" => true] : []));
