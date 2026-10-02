<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Send a JSON error response and stop. */
function account_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit;
}

// ---------------------------------------------------------------------------
// GET: read own profile
// ---------------------------------------------------------------------------

// Lets any signed-in user read their own profile without admin rights.
function account_handle_get(array $sessionUser): void
{
    $selfId = urlencode((string)($sessionUser["id"] ?? 0));
    $me = supabase_request(
        "GET",
        "users?id=eq.{$selfId}&select=id,name,company_name,email,role,contact_number,supplier_type,created_at,last_login_at,notify_alerts&limit=1"
    );
    if (!$me["ok"] || !is_array($me["data"][0] ?? null)) {
        account_fail(500, "Unable to load your profile");
    }
    echo json_encode($me["data"][0]);
    exit;
}

// ---------------------------------------------------------------------------
// POST action=profile: self-service profile update
// Role and is_disabled stay admin-only (api/users.php).
// ---------------------------------------------------------------------------

/** Validate the input and build the column patch. Exits on validation error. */
function account_build_profile_patch(array $input): array
{
    $patch = [];

    if (array_key_exists("company_name", $input)) {
        $company = trim((string)$input["company_name"]);
        $companyError = contrack_company_error($company, true);
        if ($companyError !== null) {
            account_fail(400, $companyError);
        }
        $patch["company_name"] = $company;
        $patch["name"] = $company;
    } elseif (array_key_exists("name", $input)) {
        $name = trim((string)$input["name"]);
        $nameError = contrack_name_error($name);
        if ($nameError !== null) {
            account_fail(400, $nameError);
        }
        $patch["name"] = $name;
    }

    if (array_key_exists("notify_alerts", $input)) {
        $patch["notify_alerts"] = filter_var($input["notify_alerts"], FILTER_VALIDATE_BOOLEAN);
    }

    if (array_key_exists("contact_number", $input)) {
        $contact = contrack_normalize_contact((string)$input["contact_number"]);
        if ($contact === "") {
            $patch["contact_number"] = null;
        } else {
            $contactError = contrack_contact_error($contact, false);
            if ($contactError !== null) {
                account_fail(400, $contactError);
            }
            $patch["contact_number"] = $contact;
        }
    }

    if ($patch === []) {
        account_fail(400, "No profile fields to update");
    }

    return $patch;
}

function account_handle_profile_update(array $sessionUser, array $input): void
{
    $selfId = (int)($sessionUser["id"] ?? 0);
    $patch = account_build_profile_patch($input);

    $updated = supabase_request("PATCH", "users?id=eq." . urlencode((string)$selfId), $patch);
    if (!$updated["ok"]) {
        account_fail($updated["status"] ?: 500, contrack_upstream_error($updated, "Failed to update profile"));
    }

    if (isset($patch["name"])) {
        $_SESSION["contrack_user"]["name"] = $patch["name"];
    }
    if (isset($patch["company_name"])) {
        $_SESSION["contrack_user"]["company_name"] = $patch["company_name"];
    }

    audit_log_event("profile_updated", ["fields" => array_keys($patch)], $selfId);
    echo json_encode([
        "ok" => true,
        "user" => get_session_user(),
        "contact_number" => $patch["contact_number"] ?? null,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// POST (default): change password
// ---------------------------------------------------------------------------

/**
 * Throttle guessing of the current password from a hijacked session: 5 misses in 15 minutes
 * pause further attempts for 60 seconds. A lookup failure never blocks a legitimate change.
 */
function account_enforce_password_throttle(int $userId): void
{
    $recentMisses = supabase_request(
        "GET",
        "audit_logs?event_type=eq.password_change_failed&actor_user_id=eq.{$userId}"
        . "&created_at=gte." . rawurlencode(gmdate("c", time() - 900))
        . "&select=created_at&order=created_at.desc&limit=5"
    );
    if ($recentMisses["ok"] && is_array($recentMisses["data"]) && count($recentMisses["data"]) >= 5) {
        $lastMiss = strtotime((string)($recentMisses["data"][0]["created_at"] ?? ""));
        if ($lastMiss !== false && (time() - $lastMiss) < 60) {
            account_fail(429, "Too many incorrect attempts. Try again in a minute.");
        }
    }
}

function account_handle_password_change(array $sessionUser, array $input): void
{
    $current = (string)($input["current_password"] ?? "");
    $new = (string)($input["new_password"] ?? "");
    if ($current === "" || $new === "") {
        account_fail(400, "current_password and new_password are required");
    }

    $passwordError = contrack_password_error($new);
    if ($passwordError !== null) {
        account_fail(400, $passwordError);
    }

    $userId = (int)($sessionUser["id"] ?? 0);

    account_enforce_password_throttle($userId);

    $result = supabase_request(
        "GET",
        "users?id=eq." . urlencode((string)$userId) . "&select=id,password&limit=1"
    );
    if (!$result["ok"] || !is_array($result["data"]) || count($result["data"]) === 0) {
        account_fail(500, "Unable to load user record");
    }

    $row = $result["data"][0];
    $stored = (string)($row["password"] ?? "");
    if (!contrack_password_matches($stored, $current)) {
        audit_log_event("password_change_failed", [], $userId);
        account_fail(401, "Current password is incorrect");
    }

    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $patch = supabase_request(
        "PATCH",
        "users?id=eq." . urlencode((string)$userId),
        ["password" => $hashed]
    );
    if (!$patch["ok"]) {
        account_fail($patch["status"] ?: 500, contrack_upstream_error($patch, "Failed to update password"));
    }

    audit_log_event("password_changed", [], $userId);
    echo json_encode(["ok" => true]);
    exit;
}

// ---------------------------------------------------------------------------
// Request dispatch
// ---------------------------------------------------------------------------

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if (!in_array($method, ["GET", "POST"], true)) {
    account_fail(405, "Method not allowed");
}

$sessionUser = require_session();

if ($method === "GET") {
    account_handle_get($sessionUser);
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    account_fail(400, "Invalid request body");
}

if (($input["action"] ?? "") === "profile") {
    account_handle_profile_update($sessionUser, $input);
}

account_handle_password_change($sessionUser, $input);
