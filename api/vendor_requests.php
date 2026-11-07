<?php
declare(strict_types=1);

/**
 * In-app vendor account approvals for the CEO.
 *   GET            -> {pending: [...], decided: [...]} (decided = last 50 decisions)
 *   GET ?count=1   -> {pending: <number>}
 *   POST {id, decision: "approve"|"reject"}
 */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_vendor_approval.php";

require_roles(["ceo"]);
$method = request_method();

if ($method === "GET") {
    if (!empty($_GET["count"])) {
        $result = supabase_request("GET", "users?role=eq.vendor&approval_status=eq.pending&select=id");
        if (!$result["ok"]) {
            json_error(contrack_upstream_error($result, "Could not load vendor requests."), 502);
        }
        json_response(["pending" => is_array($result["data"]) ? count($result["data"]) : 0]);
    }

    $fields = CONTRACK_VENDOR_APPROVAL_FIELDS . ",approval_decided_at";
    [$pending, $decided] = supabase_request_many([
        ["GET", "users?role=eq.vendor&approval_status=eq.pending&select={$fields}&order=created_at.asc"],
        ["GET", "users?role=eq.vendor&approval_status=in.(approved,rejected)&approval_decided_at=not.is.null"
            . "&select={$fields}&order=approval_decided_at.desc&limit=50"],
    ]);
    if (!$pending["ok"]) {
        json_error(contrack_upstream_error($pending, "Could not load vendor requests."), 502);
    }
    json_response([
        "pending" => is_array($pending["data"]) ? $pending["data"] : [],
        "decided" => ($decided["ok"] && is_array($decided["data"])) ? $decided["data"] : [],
    ]);
}

if ($method === "POST") {
    $ceoId = require_user_id();
    $input = json_input() ?? [];
    $vendorId = (int)($input["id"] ?? 0);
    $decision = strtolower(trim((string)($input["decision"] ?? "")));
    if ($vendorId <= 0) {
        json_error("Choose a vendor request.");
    }
    $outcome = contrack_decide_vendor($vendorId, $decision, $ceoId, "in_app");
    if (!$outcome["ok"]) {
        $error = (string)($outcome["error"] ?? "Could not save the decision.");
        json_error($error, str_contains($error, "already") ? 409 : 400);
    }
    contrack_bump_pulse();
    json_response(["ok" => true, "vendor" => $outcome["vendor"]]);
}

json_error("Method not allowed", 405);
