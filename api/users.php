<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$role = require_roles(["admin"]);

if ($method === "GET") {
    $result = supabase_request(
        "GET",
        "users?select=id,name,company_name,email,role,contact_number,supplier_type,created_at,last_login_at,last_seen_at,is_disabled&order=created_at.desc"
    );
    http_response_code($result["status"]);
    echo json_encode($result["ok"] ? ($result["data"] ?? []) : ["error" => $result["raw"] ?? "Failed to fetch users"]);
    exit;
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid request body"]);
        exit;
    }

    $name = trim((string)($input["name"] ?? $input["full_name"] ?? ""));
    $email = strtolower(trim((string)($input["email"] ?? "")));
    $password = (string)($input["password"] ?? "");
    $userRole = strtolower((string)($input["role"] ?? "client"));
    if ($userRole === "vendor/client") {
        $userRole = "vendor";
    }
    if ($userRole === "system_admin") {
        $userRole = "admin";
    }

    $nameError = contrack_name_error($name);
    $emailError = contrack_email_error($email);
    $passwordError = contrack_password_error($password);
    if ($nameError !== null || $emailError !== null || $passwordError !== null) {
        http_response_code(400);
        echo json_encode(["error" => $nameError ?? $emailError ?? $passwordError]);
        exit;
    }

    $allowedRoles = ["admin", "manager", "ceo", "client", "vendor"];
    if (!in_array($userRole, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid role"]);
        exit;
    }

    $payload = [[
        "name" => $name,
        "email" => $email,
        "password" => password_hash($password, PASSWORD_DEFAULT),
        "role" => $userRole,
        "is_disabled" => false,
    ]];
    $result = supabase_request("POST", "users", $payload);
    http_response_code($result["status"]);
    if ($result["ok"] && is_array($result["data"]) && isset($result["data"][0])) {
        $created = $result["data"][0];
        $newId = (int)($created["id"] ?? 0);
        audit_log_event(
            "user_created",
            [
                "email" => $created["email"] ?? $email,
                "role" => $userRole,
            ],
            get_request_user_id()
        );
        unset($created["password"]);
        echo json_encode($created);
    } else {
        echo json_encode(["error" => $result["raw"] ?? "Failed to create user"]);
    }
    exit;
}

if ($method === "PATCH") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input) || empty($input["id"])) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }

    $targetId = (int)$input["id"];
    $actorId = get_request_user_id();
    if ($actorId !== null && $actorId === $targetId) {
        // Use account.php for self password; still allow self name updates.
        if (array_key_exists("is_disabled", $input) || array_key_exists("role", $input)) {
            http_response_code(400);
            echo json_encode(["error" => "Cannot change your own role or disabled flag here"]);
            exit;
        }
    }

    $patch = [];
    if (array_key_exists("name", $input)) {
        $patchedName = trim((string)$input["name"]);
        $nameError = contrack_name_error($patchedName);
        if ($nameError !== null) {
            http_response_code(400);
            echo json_encode(["error" => $nameError]);
            exit;
        }
        $patch["name"] = $patchedName;
    }
    if (array_key_exists("role", $input)) {
        $r = strtolower((string)$input["role"]);
        if ($r === "system_admin") {
            $r = "admin";
        }
        $allowed = ["admin", "manager", "ceo", "client", "vendor"];
        if (!in_array($r, $allowed, true)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid role"]);
            exit;
        }
        $patch["role"] = $r;
    }
    if (array_key_exists("is_disabled", $input)) {
        $patch["is_disabled"] = (bool)$input["is_disabled"];
    }

    if ($patch === []) {
        http_response_code(400);
        echo json_encode(["error" => "No fields to update"]);
        exit;
    }

    $idEnc = urlencode((string)$targetId);
    $result = supabase_request("PATCH", "users?id=eq.{$idEnc}", $patch);
    http_response_code($result["status"]);
    if ($result["ok"]) {
        audit_log_event(
            "user_updated",
            [
                "target_user_id" => $targetId,
                "fields" => array_keys($patch),
            ],
            get_request_user_id()
        );
        echo json_encode($result["data"] ?? ["ok" => true]);
    } else {
        echo json_encode(["error" => $result["raw"] ?? "Failed to update user"]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
