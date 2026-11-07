<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

// ---------------------------------------------------------------------------
// GET: read own profile (any signed-in user, no admin rights needed)
// ---------------------------------------------------------------------------

function account_handle_get(array $sessionUser): never
{
    $selfId = urlencode((string)($sessionUser["id"] ?? 0));
    $me = supabase_request(
        "GET",
        "users?id=eq.{$selfId}&select=id,name,company_name,email,role,contact_number,supplier_type,created_at,last_login_at,"
        . "notify_alerts,notify_announcements,position_title,department,theme_preference&limit=1"
    );
    if (!$me["ok"] || !is_array($me["data"][0] ?? null)) {
        json_error("Unable to load your profile", 500);
    }
    json_response($me["data"][0]);
}

// ---------------------------------------------------------------------------
// POST action=profile: self-service profile update
// Role and is_disabled stay admin-only (api/users.php).
// ---------------------------------------------------------------------------

/** Optional short label (position, department). Empty clears it. Exits on validation error. */
function account_optional_label(array $input, string $key, string $label): ?string
{
    $value = trim((string)$input[$key]);
    if ($value === "") {
        return null;
    }
    if ((function_exists("mb_strlen") ? mb_strlen($value) : strlen($value)) > 80) {
        json_error("{$label} must be 80 characters or fewer.");
    }
    if (preg_match("/[\\x00-\\x1F\\x7F]/", $value)) {
        json_error("{$label} cannot include line breaks.");
    }
    return $value;
}

/** Validate the input and build the column patch. Exits on validation error. */
function account_build_profile_patch(array $input): array
{
    $patch = [];
    $has = static fn(string $key): bool => array_key_exists($key, $input);

    if ($has("company_name")) {
        $company = trim((string)$input["company_name"]);
        if (($error = contrack_company_error($company, true)) !== null) {
            json_error($error);
        }
        $patch["company_name"] = $company;
        $patch["name"] = $company;
    } elseif ($has("name")) {
        $name = trim((string)$input["name"]);
        if (($error = contrack_name_error($name)) !== null) {
            json_error($error);
        }
        $patch["name"] = $name;
    }

    foreach (["notify_alerts", "notify_announcements"] as $key) {
        if ($has($key)) {
            $patch[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }
    }
    foreach (["position_title" => "Position", "department" => "Department"] as $key => $label) {
        if ($has($key)) {
            $patch[$key] = account_optional_label($input, $key, $label);
        }
    }

    if ($has("theme_preference")) {
        $theme = strtolower(trim((string)$input["theme_preference"]));
        if (!in_array($theme, ["light", "dark"], true)) {
            json_error("Appearance must be light or dark.");
        }
        $patch["theme_preference"] = $theme;
    }

    if ($has("contact_number")) {
        $contact = contrack_normalize_contact((string)$input["contact_number"]);
        if ($contact !== "" && ($error = contrack_contact_error($contact, false)) !== null) {
            json_error($error);
        }
        $patch["contact_number"] = $contact === "" ? null : $contact;
    }

    return $patch !== [] ? $patch : json_error("No profile fields to update");
}

function account_handle_profile_update(array $sessionUser, array $input): never
{
    $selfId = (int)($sessionUser["id"] ?? 0);
    $patch = account_build_profile_patch($input);

    $updated = supabase_request("PATCH", "users?id=eq." . urlencode((string)$selfId), $patch);
    if (!$updated["ok"]) {
        json_error(contrack_upstream_error($updated, "Failed to update profile"), $updated["status"] ?: 500);
    }

    contrack_session_reopen();
    foreach (["name", "company_name"] as $key) {
        if (isset($patch[$key])) {
            $_SESSION["contrack_user"][$key] = $patch[$key];
        }
    }

    audit_log_event("profile_updated", ["fields" => array_keys($patch)], $selfId);
    json_response([
        "ok" => true,
        "user" => get_session_user(),
        "contact_number" => $patch["contact_number"] ?? null,
    ]);
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
            json_error("Too many incorrect attempts. Try again in a minute.", 429);
        }
    }
}

function account_handle_password_change(array $sessionUser, array $input): never
{
    $current = (string)($input["current_password"] ?? "");
    $new = (string)($input["new_password"] ?? "");
    if ($current === "" || $new === "") {
        json_error("current_password and new_password are required");
    }
    if (($passwordError = contrack_password_error($new)) !== null) {
        json_error($passwordError);
    }

    $userId = (int)($sessionUser["id"] ?? 0);
    account_enforce_password_throttle($userId);

    $result = supabase_request("GET", "users?id=eq." . urlencode((string)$userId) . "&select=id,password&limit=1");
    if (!$result["ok"] || !is_array($result["data"]) || count($result["data"]) === 0) {
        json_error("Unable to load user record", 500);
    }

    $stored = (string)($result["data"][0]["password"] ?? "");
    if (!contrack_password_matches($stored, $current)) {
        audit_log_event("password_change_failed", [], $userId);
        json_error("Current password is incorrect", 401);
    }
    if (contrack_password_was_used($userId, $stored, $new)) {
        json_error("You already used this password before. Choose a new one.");
    }

    $patch = contrack_set_password($userId, $stored, $new);
    if (!$patch["ok"]) {
        json_error(contrack_upstream_error($patch, "Failed to update password"), $patch["status"] ?: 500);
    }
    audit_log_event("password_changed", [], $userId);
    json_response(["ok" => true]);
}

// ---------------------------------------------------------------------------
// Request dispatch
// ---------------------------------------------------------------------------

$method = request_method();
if (!in_array($method, ["GET", "POST"], true)) {
    json_error("Method not allowed", 405);
}

$sessionUser = require_session();
if ($method === "GET") {
    account_handle_get($sessionUser);
}

$input = json_body();
if (($input["action"] ?? "") === "profile") {
    account_handle_profile_update($sessionUser, $input);
}
account_handle_password_change($sessionUser, $input);
