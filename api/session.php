<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

$method = request_method();

if ($method === "GET") {
    $user = get_session_user() ?? json_error("No active session", 401);
    $idEnc = urlencode((string)$user["id"]);
    $fresh = supabase_request("GET", "users?id=eq.{$idEnc}&select=id,name,company_name,email,role,is_disabled,theme_preference&limit=1");
    $theme = null;
    if ($fresh["ok"] && is_array($fresh["data"])) {
        $row = $fresh["data"][0] ?? null;
        if (isset($_SESSION["contrack_user"]) && (!is_array($row) || !empty($row["is_disabled"]))) {
            // Account was disabled or removed: end the session instead of refreshing it.
            contrack_destroy_session();
            json_error("No active session", 401);
        }
        if (is_array($row)) {
            set_session_user($row);
            $user = get_session_user();
            $theme = ($row["theme_preference"] ?? "") === "dark" ? "dark" : "light";
        }
    }
    contrack_release_session();

    // Lightweight heartbeat: bumps last_seen_at for defensible "recent activity" (not faux concurrent users).
    if (isset($_GET["heartbeat"]) && $_GET["heartbeat"] !== "0") {
        supabase_request("PATCH", "users?id=eq.{$idEnc}", ["last_seen_at" => gmdate("c")]);
    }
    if (is_array($user) && $theme !== null) {
        $user["theme_preference"] = $theme;
    }
    json_response(["user" => $user]);
}

if ($method === "DELETE" || ($method === "POST" && ($_GET["action"] ?? "") === "logout")) {
    contrack_destroy_session();
    json_response(["ok" => true]);
}

json_error("Method not allowed", 405);
