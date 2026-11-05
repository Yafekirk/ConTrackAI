<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_vendor_approval.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
require_roles(["ceo"]);

if ($method === "GET") {
    $result = supabase_request(
        "GET",
        "users?approval_status=eq.pending&select=" . CONTRACK_VENDOR_APPROVAL_FIELDS . "&order=created_at.desc"
    );
    if (!$result["ok"]) {
        http_response_code($result["status"] ?: 500);
        echo json_encode(["error" => contrack_upstream_error($result, "Could not load vendor requests")]);
        exit;
    }
    echo json_encode(is_array($result["data"]) ? $result["data"] : []);
    exit;
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $vendorId = is_array($input) ? (int)($input["id"] ?? 0) : 0;
    $decision = is_array($input) ? strtolower((string)($input["decision"] ?? "")) : "";
    if ($vendorId <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }
    $result = contrack_decide_vendor($vendorId, $decision, get_request_user_id(), "ceo_portal");
    if (!$result["ok"]) {
        http_response_code(409);
        echo json_encode(["error" => $result["error"] ?? "Could not save the decision."]);
        exit;
    }
    echo json_encode(["ok" => true, "vendor" => $result["vendor"]]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
