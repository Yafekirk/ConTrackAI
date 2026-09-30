<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_session();
$userId = get_request_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(["error" => "Sign in required"]);
    exit;
}

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));

if ($method === "GET") {
    $limit = (int)($_GET["limit"] ?? 50);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    $path = "alerts?user_id=eq.{$userId}&select=id,user_id,title,message,status,created_at&order=created_at.desc&limit={$limit}";
    $result = supabase_request("GET", $path);
    http_response_code($result["status"]);
    echo json_encode($result["ok"] ? ($result["data"] ?? []) : ["error" => $result["raw"] ?? "Failed to fetch alerts"]);
    exit;
}

if ($method === "PATCH") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid request body"]);
        exit;
    }
    $status = strtolower(trim((string)($input["status"] ?? "read")));
    if (!in_array($status, ["read", "unread"], true)) {
        $status = "read";
    }
    if (!empty($input["mark_all"])) {
        $result = supabase_request(
            "PATCH",
            "alerts?user_id=eq.{$userId}&status=eq.unread",
            ["status" => $status]
        );
        http_response_code($result["status"]);
        echo json_encode($result["ok"] ? ["ok" => true] : ["error" => $result["raw"] ?? "Failed to update alerts"]);
        exit;
    }
    $id = (int)($input["id"] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }
    $result = supabase_request(
        "PATCH",
        "alerts?id=eq.{$id}&user_id=eq.{$userId}",
        ["status" => $status]
    );
    http_response_code($result["status"]);
    echo json_encode($result["ok"] ? ($result["data"] ?? ["ok" => true]) : ["error" => $result["raw"] ?? "Failed to update alert"]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
