<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

$userId = require_user_id();
$method = request_method();

if ($method === "GET") {
    $limit = max(1, min(100, (int)($_GET["limit"] ?? 50)));
    $result = supabase_request("GET", "alerts?user_id=eq.{$userId}&select=id,user_id,title,message,status,created_at&order=created_at.desc&limit={$limit}");
    json_result($result, "Failed to fetch alerts");
}

if ($method === "PATCH") {
    $input = json_body();
    $status = strtolower(trim((string)($input["status"] ?? "read")));
    $status = in_array($status, ["read", "unread"], true) ? $status : "read";

    if (!empty($input["mark_all"])) {
        $result = supabase_request("PATCH", "alerts?user_id=eq.{$userId}&status=eq.unread", ["status" => $status]);
        json_response($result["ok"] ? ["ok" => true] : ["error" => contrack_upstream_error($result, "Failed to update alerts")], (int)$result["status"]);
    }

    $id = (int)($input["id"] ?? 0);
    if ($id <= 0) {
        json_error("id is required");
    }
    $result = supabase_request("PATCH", "alerts?id=eq.{$id}&user_id=eq.{$userId}", ["status" => $status]);
    json_result($result, "Failed to update alert", ["ok" => true]);
}

json_error("Method not allowed", 405);
