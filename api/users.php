<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

$method = request_method();
require_roles(["admin"]);

const USERS_ALLOWED_ROLES = ["admin", "manager", "ceo", "client", "vendor"];
const USERS_ROLE_ALIASES = ["vendor/client" => "vendor", "system_admin" => "admin"];

if ($method === "GET") {
    $result = supabase_request(
        "GET",
        "users?select=id,name,company_name,email,role,contact_number,supplier_type,created_at,last_login_at,last_seen_at,is_disabled&order=created_at.desc"
    );
    json_result($result, "Failed to fetch users");
}

if ($method === "POST") {
    $input = json_body();
    $name = trim((string)($input["name"] ?? $input["full_name"] ?? ""));
    $email = strtolower(trim((string)($input["email"] ?? "")));
    $password = (string)($input["password"] ?? "");
    $userRole = strtolower((string)($input["role"] ?? "client"));
    $userRole = USERS_ROLE_ALIASES[$userRole] ?? $userRole;

    $error = contrack_name_error($name) ?? contrack_email_error($email) ?? contrack_password_error($password);
    if ($error !== null) {
        json_error($error);
    }
    if (!in_array($userRole, USERS_ALLOWED_ROLES, true)) {
        json_error("Invalid role");
    }

    $result = supabase_request("POST", "users", [[
        "name" => $name,
        "email" => $email,
        "password" => password_hash($password, PASSWORD_DEFAULT),
        "role" => $userRole,
        "is_disabled" => false,
    ]]);
    if ($result["ok"] && is_array($result["data"]) && isset($result["data"][0])) {
        $created = $result["data"][0];
        audit_log_event("user_created", ["email" => $created["email"] ?? $email, "role" => $userRole], get_request_user_id());
        unset($created["password"]);
        json_response($created, (int)$result["status"]);
    }
    $rawBody = (string)($result["raw"] ?? "");
    $duplicate = stripos($rawBody, "duplicate key") !== false || stripos($rawBody, "23505") !== false;
    json_error($duplicate ? "Email is already registered" : contrack_upstream_error($result, "Failed to create user"), (int)$result["status"]);
}

if ($method === "PATCH") {
    $input = json_input();
    if ($input === null || empty($input["id"])) {
        json_error("id is required");
    }

    $targetId = (int)$input["id"];
    $actorId = get_request_user_id();
    // Use account.php for self password; still allow self name updates.
    if ($actorId !== null && $actorId === $targetId && (array_key_exists("is_disabled", $input) || array_key_exists("role", $input))) {
        json_error("Cannot change your own role or disabled flag here");
    }

    $patch = [];
    if (array_key_exists("name", $input)) {
        $patch["name"] = trim((string)$input["name"]);
        if (($nameError = contrack_name_error($patch["name"])) !== null) {
            json_error($nameError);
        }
    }
    if (array_key_exists("role", $input)) {
        $r = strtolower((string)$input["role"]);
        $patch["role"] = $r === "system_admin" ? "admin" : $r;
        if (!in_array($patch["role"], USERS_ALLOWED_ROLES, true)) {
            json_error("Invalid role");
        }
    }
    if (array_key_exists("is_disabled", $input)) {
        $patch["is_disabled"] = filter_var($input["is_disabled"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? json_error("is_disabled must be true or false");
    }
    if ($patch === []) {
        json_error("No fields to update");
    }

    $result = supabase_request("PATCH", "users?id=eq." . urlencode((string)$targetId), $patch);
    if ($result["ok"]) {
        audit_log_event("user_updated", ["target_user_id" => $targetId, "fields" => array_keys($patch)], get_request_user_id());
    }
    json_result($result, "Failed to update user", ["ok" => true]);
}

json_error("Method not allowed", 405);
