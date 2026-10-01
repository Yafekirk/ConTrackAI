<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if (!in_array($method, ["GET", "POST"], true)) {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$sessionUser = require_session();

// Lets any signed-in user read their own profile without admin rights.
if ($method === "GET") {
    $selfId = urlencode((string)($sessionUser["id"] ?? 0));
    $me = supabase_request(
        "GET",
        "users?id=eq.{$selfId}&select=id,name,company_name,email,role,contact_number,supplier_type,created_at,last_login_at,notify_alerts&limit=1"
    );
    if (!$me["ok"] || !is_array($me["data"][0] ?? null)) {
        http_response_code(500);
        echo json_encode(["error" => "Unable to load your profile"]);
        exit;
    }
    echo json_encode($me["data"][0]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request body"]);
    exit;
}

// Self-service profile update. Role and is_disabled stay admin-only (api/users.php).
if (($input["action"] ?? "") === "profile") {
    $selfId = (int)($sessionUser["id"] ?? 0);
    $patch = [];

    if (array_key_exists("company_name", $input)) {
        $company = trim((string)$input["company_name"]);
        $companyError = contrack_company_error($company, true);
        if ($companyError !== null) {
            http_response_code(400);
            echo json_encode(["error" => $companyError]);
            exit;
        }
        $patch["company_name"] = $company;
        $patch["name"] = $company;
    } elseif (array_key_exists("name", $input)) {
        $name = trim((string)$input["name"]);
        $nameError = contrack_name_error($name);
        if ($nameError !== null) {
            http_response_code(400);
            echo json_encode(["error" => $nameError]);
            exit;
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
                http_response_code(400);
                echo json_encode(["error" => $contactError]);
                exit;
            }
            $patch["contact_number"] = $contact;
        }
    }

    if ($patch === []) {
        http_response_code(400);
        echo json_encode(["error" => "No profile fields to update"]);
        exit;
    }

    $updated = supabase_request("PATCH", "users?id=eq." . urlencode((string)$selfId), $patch);
    if (!$updated["ok"]) {
        http_response_code($updated["status"] ?: 500);
        echo json_encode(["error" => contrack_upstream_error($updated, "Failed to update profile")]);
        exit;
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

$current = (string)($input["current_password"] ?? "");
$new = (string)($input["new_password"] ?? "");
if ($current === "" || $new === "") {
    http_response_code(400);
    echo json_encode(["error" => "current_password and new_password are required"]);
    exit;
}

$passwordError = contrack_password_error($new);
if ($passwordError !== null) {
    http_response_code(400);
    echo json_encode(["error" => $passwordError]);
    exit;
}

$userId = (int)($sessionUser["id"] ?? 0);

// Throttle guessing of the current password from a hijacked session: 5 misses in 15 minutes
// pause further attempts for 60 seconds. A lookup failure never blocks a legitimate change.
$recentMisses = supabase_request(
    "GET",
    "audit_logs?event_type=eq.password_change_failed&actor_user_id=eq.{$userId}"
    . "&created_at=gte." . rawurlencode(gmdate("c", time() - 900))
    . "&select=created_at&order=created_at.desc&limit=5"
);
if ($recentMisses["ok"] && is_array($recentMisses["data"]) && count($recentMisses["data"]) >= 5) {
    $lastMiss = strtotime((string)($recentMisses["data"][0]["created_at"] ?? ""));
    if ($lastMiss !== false && (time() - $lastMiss) < 60) {
        http_response_code(429);
        echo json_encode(["error" => "Too many incorrect attempts. Try again in a minute."]);
        exit;
    }
}

$result = supabase_request(
    "GET",
    "users?id=eq." . urlencode((string)$userId) . "&select=id,password&limit=1"
);

if (!$result["ok"] || !is_array($result["data"]) || count($result["data"]) === 0) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to load user record"]);
    exit;
}

$row = $result["data"][0];
$stored = (string)($row["password"] ?? "");
$valid = contrack_password_matches($stored, $current);
if (!$valid) {
    audit_log_event("password_change_failed", [], $userId);
    http_response_code(401);
    echo json_encode(["error" => "Current password is incorrect"]);
    exit;
}

$hashed = password_hash($new, PASSWORD_DEFAULT);
$patch = supabase_request(
    "PATCH",
    "users?id=eq." . urlencode((string)$userId),
    ["password" => $hashed]
);

if (!$patch["ok"]) {
    http_response_code($patch["status"] ?: 500);
    echo json_encode(["error" => contrack_upstream_error($patch, "Failed to update password")]);
    exit;
}

audit_log_event("password_changed", [], $userId);
echo json_encode(["ok" => true]);
