<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_login_guard.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method === "GET") {
    echo json_encode(login_guard_payload());
    exit;
}

if ($method !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

login_guard_reject_if_locked();

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request body"]);
    exit;
}

$email = strtolower(trim((string)($input["email"] ?? "")));
$password = (string)($input["password"] ?? "");

if ($email === "" || $password === "") {
    http_response_code(400);
    echo json_encode(["error" => "Email and password are required"]);
    exit;
}

login_guard_reject_if_server_locked($email);

$result = supabase_request(
    "GET",
    "users?email=eq." . urlencode($email) . "&select=id,name,company_name,email,role,password,is_disabled,last_login_at,last_seen_at&limit=1"
);

if (!$result["ok"]) {
    http_response_code($result["status"] >= 400 ? $result["status"] : 500);
    $hint = $result["error"] ?? null;
    $raw = is_string($result["raw"] ?? null) ? $result["raw"] : "";
    $message = "Authentication backend request failed";
    // Configuration hints are only shown when CONTRACK_DEBUG=true; the detail is always logged.
    $debug = env_flag("CONTRACK_DEBUG", false);
    if (stripos($raw, "Invalid API key") !== false || stripos($raw, "JWT") !== false) {
        error_log("auth: Supabase key rejected: " . substr($raw, 0, 300));
        if ($debug) {
            $message = "Supabase key is invalid. Check SUPABASE_SERVICE_ROLE_KEY in your PowerShell session.";
        }
    } elseif (stripos($raw, "column") !== false && stripos($raw, "does not exist") !== false) {
        error_log("auth: schema outdated: " . substr($raw, 0, 300));
        if ($debug) {
            $message = "Database schema is outdated. Run sql/schema_phase1.sql in Supabase (users.is_disabled / audit_logs).";
        }
    } elseif ($hint) {
        error_log("auth: backend error: " . substr((string)$hint, 0, 300));
        if ($debug) {
            $message = $hint;
        }
    }
    echo json_encode(["error" => $message]);
    exit;
}

if (!is_array($result["data"]) || count($result["data"]) === 0) {
    audit_log_event("login_failed", ["reason" => "unknown_email", "email" => $email], null);
    login_guard_reject_auth_failure();
}

$user = $result["data"][0];
if (!empty($user["is_disabled"])) {
    audit_log_event("login_failed", ["reason" => "account_disabled", "email" => $email], (int)($user["id"] ?? 0));
    login_guard_reject_auth_failure();
}

$stored = (string)($user["password"] ?? "");
$needsUpgrade = false;
$valid = contrack_password_matches($stored, $password, $needsUpgrade);

if (!$valid) {
    audit_log_event("login_failed", ["reason" => "bad_password", "email" => $email], (int)($user["id"] ?? 0));
    login_guard_reject_auth_failure();
}

// Auto-upgrade legacy plain text passwords (or outdated hashes) on successful login.
if ($needsUpgrade) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $id = urlencode((string)$user["id"]);
    supabase_request("PATCH", "users?id=eq.{$id}", ["password" => $hashed]);
}

$now = gmdate("c");
$idEnc = urlencode((string)$user["id"]);
supabase_request(
    "PATCH",
    "users?id=eq.{$idEnc}",
    [
        "last_login_at" => $now,
        "last_seen_at" => $now,
    ]
);

unset($user["password"]);
login_guard_clear();
contrack_start_authenticated_session($user);

audit_log_event(
    "login_success",
    ["email" => $user["email"] ?? $email],
    (int)$user["id"]
);

echo json_encode(["user" => $user]);
