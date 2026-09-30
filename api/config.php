<?php
declare(strict_types=1);

ini_set("display_errors", "0");
ini_set("html_errors", "0");
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // The session is the only credential the portals hold, so give it a full work day
    // instead of PHP's 24-minute default.
    $sessionLifetime = 8 * 60 * 60;
    ini_set("session.gc_maxlifetime", (string)$sessionLifetime);
    session_set_cookie_params([
        "lifetime" => $sessionLifetime,
        "path" => "/",
        "httponly" => true,
        "samesite" => "Lax",
        // Set CONTRACK_HTTPS=true once the site is served over TLS.
        "secure" => env_flag("CONTRACK_HTTPS", false),
    ]);
    session_start();
}

if (!defined("CONTRACK_SKIP_JSON_HEADER") || CONTRACK_SKIP_JSON_HEADER !== true) {
    header("Content-Type: application/json; charset=utf-8");
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === "") {
        return $default;
    }
    return $value;
}

function require_env(string $key): string
{
    $value = env_value($key);
    if ($value === null) {
        http_response_code(500);
        echo json_encode(["error" => "Missing required environment variable: {$key}"]);
        exit;
    }
    return $value;
}

function env_flag(string $key, bool $default = false): bool
{
    $value = env_value($key);
    if ($value === null) {
        return $default;
    }
    $normalized = strtolower(trim($value));
    return in_array($normalized, ["1", "true", "yes", "on"], true);
}

/**
 * Resolve a CA bundle for cURL TLS verification (Windows PHP often has none configured).
 */
function resolve_supabase_ca_bundle(): ?string
{
    $explicit = env_value("SUPABASE_CURL_CA_BUNDLE");
    if ($explicit !== null && is_readable($explicit)) {
        return $explicit;
    }

    $projectRoot = dirname(__DIR__);
    $bundled = $projectRoot . DIRECTORY_SEPARATOR . "certs" . DIRECTORY_SEPARATOR . "cacert.pem";
    if (is_readable($bundled)) {
        return $bundled;
    }

    foreach (["curl.cainfo", "openssl.cafile"] as $iniKey) {
        $iniPath = ini_get($iniKey);
        if (is_string($iniPath) && $iniPath !== "" && is_readable($iniPath)) {
            return $iniPath;
        }
    }

    if (defined("PHP_BINARY")) {
        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . DIRECTORY_SEPARATOR . "extras" . DIRECTORY_SEPARATOR . "ssl" . DIRECTORY_SEPARATOR . "cacert.pem",
            $phpDir . DIRECTORY_SEPARATOR . "cacert.pem",
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Dev-only escape hatch: trust X-User-Role / X-User-Id (or ?role= / ?user_id=) when no
 * session exists. Never enable in production — it lets any caller claim any role.
 */
function contrack_header_auth_allowed(): bool
{
    return env_flag("CONTRACK_DEV_HEADER_AUTH", false);
}

function get_request_role(): string
{
    $user = get_session_user();
    $role = strtolower(trim((string)($user["role"] ?? "")));
    $allowed = ["system_admin", "admin", "manager", "ceo", "vendor", "client", "vendor_client"];
    return in_array($role, $allowed, true) ? $role : "vendor";
}

function normalize_role(string $role): string
{
    if ($role === "system_admin") {
        return "admin";
    }
    if ($role === "vendor_client" || $role === "client") {
        return "vendor";
    }
    return $role;
}

/**
 * Every data endpoint must run behind a real login; role headers alone are not identity.
 */
function require_session(): array
{
    $user = get_session_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(["error" => "Sign in required"]);
        exit;
    }
    return $user;
}

function require_roles(array $roles): string
{
    require_session();
    $role = normalize_role(get_request_role());
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        echo json_encode(["error" => "Forbidden for role: {$role}"]);
        exit;
    }
    return $role;
}

function get_request_user_id(): ?int
{
    $user = get_session_user();
    $raw = $user["id"] ?? null;
    if ($raw === null || $raw === "") {
        return null;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || (int)$value <= 0) {
        return null;
    }
    return (int)$value;
}

function set_session_user(array $user): void
{
    $_SESSION["contrack_user"] = [
        "id" => (int)($user["id"] ?? 0),
        "name" => (string)($user["name"] ?? ""),
        "company_name" => (string)($user["company_name"] ?? ""),
        "email" => (string)($user["email"] ?? ""),
        "role" => normalize_role((string)($user["role"] ?? "vendor")),
    ];
}

/** Vendor dashboards show the company. Staff keep their personal name. */
function contrack_public_name(array $user): string
{
    $role = normalize_role((string)($user["role"] ?? ""));
    $company = trim((string)($user["company_name"] ?? ""));
    if (($role === "vendor" || $role === "") && $company !== "") {
        return $company;
    }
    $name = trim((string)($user["name"] ?? ""));
    if ($name !== "") {
        return $name;
    }
    return $company !== "" ? $company : "Vendor";
}

function get_session_user(): ?array
{
    $user = $_SESSION["contrack_user"] ?? null;
    if (is_array($user) && !empty($user["id"])) {
        return $user;
    }
    if (!contrack_header_auth_allowed()) {
        return null;
    }
    $headerRole = $_SERVER["HTTP_X_USER_ROLE"] ?? $_GET["role"] ?? null;
    $headerId = $_SERVER["HTTP_X_USER_ID"] ?? $_GET["user_id"] ?? null;
    if ($headerRole === null && $headerId === null) {
        return null;
    }
    $id = filter_var($headerId, FILTER_VALIDATE_INT);
    return [
        "id" => $id === false ? 0 : (int)$id,
        "name" => "",
        "email" => "",
        "role" => normalize_role(strtolower(trim((string)($headerRole ?? "vendor")))),
    ];
}

function supabase_request(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists("curl_init")) {
        return [
            "ok" => false,
            "status" => 500,
            "error" => "PHP cURL extension is not enabled. Enable extension=curl in php.ini and restart PHP server.",
            "raw" => null,
        ];
    }

    $url = rtrim(require_env("SUPABASE_URL"), "/") . "/rest/v1/" . ltrim($path, "/");
    $serviceRoleKey = require_env("SUPABASE_SERVICE_ROLE_KEY");

    $ch = curl_init($url);
    $headers = [
        "apikey: {$serviceRoleKey}",
        "Authorization: Bearer {$serviceRoleKey}",
        "Content-Type: application/json",
        "Accept: application/json",
        "Prefer: return=representation",
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // TLS settings for Windows/dev environments:
    // - Prefer strict verification with a CA bundle when available.
    // - Allow explicit insecure mode only when SUPABASE_TLS_INSECURE=true.
    $tlsInsecure = env_flag("SUPABASE_TLS_INSECURE", false);
    $caBundlePath = resolve_supabase_ca_bundle();
    if ($caBundlePath !== null && !$tlsInsecure) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundlePath);
    }
    if ($tlsInsecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        $isTlsCertError = stripos($error, "unable to get local issuer certificate") !== false
            || stripos($error, "SSL certificate problem") !== false
            || stripos($error, "certificate verify failed") !== false;
        if ($isTlsCertError) {
            $bundled = dirname(__DIR__) . DIRECTORY_SEPARATOR . "certs" . DIRECTORY_SEPARATOR . "cacert.pem";
            $hint = "TLS certificate validation failed. Ensure {$bundled} exists (run: Invoke-WebRequest -Uri https://curl.se/ca/cacert.pem -OutFile certs/cacert.pem), set SUPABASE_CURL_CA_BUNDLE, or set SUPABASE_TLS_INSECURE=true for local dev only.";
            return ["ok" => false, "status" => 500, "error" => $hint];
        }
        return ["ok" => false, "status" => 500, "error" => $error];
    }

    $decoded = json_decode((string)$raw, true);
    return [
        "ok" => $status >= 200 && $status < 300,
        "status" => $status,
        "data" => $decoded,
        "raw" => $raw,
    ];
}

function client_ip(): ?string
{
    $headers = [
        "HTTP_CF_CONNECTING_IP",
        "HTTP_X_FORWARDED_FOR",
        "HTTP_X_REAL_IP",
        "REMOTE_ADDR",
    ];
    foreach ($headers as $key) {
        $raw = $_SERVER[$key] ?? null;
        if (!is_string($raw) || $raw === "") {
            continue;
        }
        if ($key === "HTTP_X_FORWARDED_FOR") {
            $parts = array_map("trim", explode(",", $raw));
            $raw = $parts[0] ?? $raw;
        }
        return substr($raw, 0, 128);
    }
    return null;
}

/**
 * Best-effort audit row; ignores failures so auth flows never break if table is missing.
 */
function audit_log_event(string $event_type, array $details = [], ?int $actor_user_id = null): void
{
    $payload = [[
        "event_type" => $event_type,
        "details" => $details,
        "actor_user_id" => $actor_user_id,
        "ip_address" => client_ip(),
        "created_at" => gmdate("c"),
    ]];
    $result = supabase_request("POST", "audit_logs", $payload);
    if (!$result["ok"]) {
        error_log("audit_logs insert failed: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    }
}

/**
 * In-app alerts stay on unless the user turned them off in Settings.
 * A lookup failure keeps alerts on so a schema miss does not silence the portal.
 */
function user_wants_alerts(int $userId, ?bool $remember = null): bool
{
    static $cache = [];
    if ($remember !== null) {
        $cache[$userId] = $remember;
        return $remember;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $result = supabase_request("GET", "users?id=eq.{$userId}&select=notify_alerts&limit=1");
    if (!$result["ok"] || !is_array($result["data"][0] ?? null)) {
        $cache[$userId] = true;
        return true;
    }
    $cache[$userId] = ($result["data"][0]["notify_alerts"] ?? true) !== false;
    return $cache[$userId];
}

/**
 * Best-effort in-app alert. Failures are logged and ignored.
 */
function create_user_alert(?int $userId, string $title, string $message): void
{
    if ($userId === null || $userId <= 0 || !user_wants_alerts($userId)) {
        return;
    }
    $payload = [[
        "user_id" => $userId,
        "title" => substr(trim($title), 0, 200),
        "message" => substr(trim($message), 0, 2000),
        "status" => "unread",
        "created_at" => gmdate("c"),
    ]];
    $result = supabase_request("POST", "alerts", $payload);
    if (!$result["ok"]) {
        error_log("alerts insert failed: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    }
}

/**
 * Notify every active user with the given role (manager / ceo / admin / vendor).
 */
function notify_role_users(string $role, string $title, string $message): void
{
    $role = normalize_role($role);
    $roles = [$role];
    if ($role === "admin") {
        $roles[] = "system_admin";
    }
    if ($role === "vendor") {
        $roles[] = "client";
        $roles[] = "vendor_client";
    }
    $ors = implode(",", array_map(static fn(string $r): string => "role.eq.{$r}", $roles));
    $path = "users?select=id,notify_alerts&is_disabled=eq.false&or=({$ors})";
    $result = supabase_request("GET", $path);
    if (!$result["ok"] || !is_array($result["data"])) {
        error_log("notify_role_users failed for {$role}: " . (string)($result["raw"] ?? $result["error"] ?? ""));
        return;
    }
    foreach ($result["data"] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row["id"]) ? (int)$row["id"] : 0;
        $wants = ($row["notify_alerts"] ?? true) !== false;
        if ($id > 0) {
            user_wants_alerts($id, $wants);
        }
        if ($id > 0 && $wants) {
            create_user_alert($id, $title, $message);
        }
    }
}

