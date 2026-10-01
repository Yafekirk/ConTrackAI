<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method === "GET") {
    $user = get_session_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(["error" => "No active session"]);
        exit;
    }

    $idEnc = urlencode((string)$user["id"]);
    $fresh = supabase_request(
        "GET",
        "users?id=eq.{$idEnc}&select=id,name,company_name,email,role,is_disabled&limit=1"
    );
    if ($fresh["ok"] && is_array($fresh["data"])) {
        $row = $fresh["data"][0] ?? null;
        if (isset($_SESSION["contrack_user"]) && (!is_array($row) || !empty($row["is_disabled"]))) {
            // Account was disabled or removed: end the session instead of refreshing it.
            contrack_destroy_session();
            http_response_code(401);
            echo json_encode(["error" => "No active session"]);
            exit;
        }
        if (is_array($row)) {
            set_session_user($row);
            $user = get_session_user();
        }
    }

    // Lightweight heartbeat: bumps last_seen_at for defensible "recent activity" (not faux concurrent users).
    if (isset($_GET["heartbeat"]) && $_GET["heartbeat"] !== "0") {
        supabase_request("PATCH", "users?id=eq.{$idEnc}", ["last_seen_at" => gmdate("c")]);
    }

    echo json_encode(["user" => $user]);
    exit;
}

if ($method === "DELETE" || ($method === "POST" && ($_GET["action"] ?? "") === "logout")) {
    contrack_destroy_session();
    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);

