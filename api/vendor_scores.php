<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_vendor_score.php";

$sessionUser = require_session();
$role = normalize_role((string)($sessionUser["role"] ?? "vendor"));
$actorId = get_request_user_id();
$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$view = strtolower(trim((string)($_GET["view"] ?? "list")));

function vendor_scores_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit;
}

function vendor_scores_load_eval(string $id): ?array
{
    $result = supabase_request(
        "GET",
        "vendor_evaluations?id=eq." . urlencode($id) . "&select=id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at,updated_at&limit=1"
    );
    if (!$result["ok"] || !is_array($result["data"][0] ?? null)) {
        return null;
    }
    return $result["data"][0];
}

function vendor_scores_assert_vendor_user(int $vendorId): array
{
    $result = supabase_request(
        "GET",
        "users?id=eq.{$vendorId}&select=id,name,email,role,supplier_type,contact_number,is_disabled&limit=1"
    );
    $user = ($result["ok"] && is_array($result["data"][0] ?? null)) ? $result["data"][0] : null;
    if ($user === null) {
        vendor_scores_json_error(404, "Vendor not found.");
    }
    $role = normalize_role((string)($user["role"] ?? ""));
    if ($role !== "vendor") {
        vendor_scores_json_error(400, "That account is not a vendor.");
    }
    return $user;
}

function vendor_scores_can_write(string $role, ?array $eval, ?int $actorId): bool
{
    if (!vendor_score_is_staff_role($role)) {
        return false;
    }
    if ($eval === null) {
        return true;
    }
    if ($role === "admin" || $role === "ceo") {
        return true;
    }
    return $actorId !== null && (int)($eval["evaluator_id"] ?? 0) === $actorId;
}

function vendor_scores_can_delete(string $role, array $eval, ?int $actorId): bool
{
    if ($role === "admin") {
        return true;
    }
    if (!vendor_score_is_staff_role($role)) {
        return false;
    }
    return $actorId !== null && (int)($eval["evaluator_id"] ?? 0) === $actorId;
}

function vendor_scores_persist_lines(string $evaluationId, array $lines): bool
{
    supabase_request("DELETE", "vendor_evaluation_scores?evaluation_id=eq." . urlencode($evaluationId));
    $payload = [];
    foreach ($lines as $line) {
        $payload[] = [
            "evaluation_id" => $evaluationId,
            "criterion_id" => (int)$line["criterion_id"],
            "score" => $line["score"],
            "weighted_score" => $line["weighted_score"],
        ];
    }
    if ($payload === []) {
        return true;
    }
    $saved = supabase_request("POST", "vendor_evaluation_scores", $payload);
    return (bool)$saved["ok"];
}

function vendor_scores_respond_eval(array $eval): never
{
    $attached = vendor_score_attach_people([$eval])[0];
    $lines = vendor_score_load_lines((string)$eval["id"]);
    echo json_encode(vendor_score_public_row($attached, $lines));
    exit;
}

if ($method === "GET" && $view === "criteria") {
    $staff = vendor_score_is_staff_role($role);
    $path = $staff
        ? "vendor_score_criteria?select=id,code,name,description,weight,sort_order,is_active&order=sort_order.asc"
        : "vendor_score_criteria?is_active=eq.true&select=id,code,name,description,weight,sort_order,is_active&order=sort_order.asc";
    $result = supabase_request("GET", $path);
    http_response_code($result["status"]);
    echo json_encode($result["ok"] ? ($result["data"] ?? []) : ["error" => $result["raw"] ?? "Failed to load criteria"]);
    exit;
}

if ($method === "GET" && $view === "vendors") {
    if (!vendor_score_is_staff_role($role)) {
        vendor_scores_json_error(403, "Only managers, the CEO, and admins can list vendors for scoring.");
    }
    $users = supabase_request(
        "GET",
        "users?or=(role.eq.vendor,role.eq.client,role.eq.vendor_client)&select=id,name,company_name,email,role,supplier_type,contact_number,is_disabled&order=name.asc"
    );
    if (!$users["ok"]) {
        vendor_scores_json_error($users["status"] ?: 500, $users["raw"] ?? "Failed to load vendors.");
    }
    $latest = vendor_score_latest_by_vendor();
    $out = [];
    foreach (is_array($users["data"]) ? $users["data"] : [] as $u) {
        if (!is_array($u) || normalize_role((string)($u["role"] ?? "")) !== "vendor") {
            continue;
        }
        $vid = (int)$u["id"];
        $ev = $latest[$vid] ?? null;
        $overall = $ev !== null ? (float)$ev["overall_score"] : null;
        $rating = $ev !== null ? (string)$ev["rating"] : null;
        $out[] = [
            "id" => $vid,
            "name" => contrack_public_name($u),
            "company_name" => $u["company_name"] ?? "",
            "email" => $u["email"] ?? "",
            "supplier_type" => $u["supplier_type"] ?? null,
            "contact_number" => $u["contact_number"] ?? null,
            "is_disabled" => !empty($u["is_disabled"]),
            "latest_evaluation_id" => $ev["id"] ?? null,
            "latest_score" => $overall,
            "latest_star_rating" => $overall !== null ? vendor_star_rating($overall) : null,
            "latest_rating" => $rating,
            "latest_rating_label" => $rating ? vendor_score_rating_label($rating) : null,
            "latest_evaluated_at" => $ev["evaluated_at"] ?? null,
        ];
    }
    echo json_encode($out);
    exit;
}

if ($method === "GET" && ($view === "list" || $view === "summary")) {
    $vendorId = isset($_GET["vendor_id"]) ? (int)$_GET["vendor_id"] : 0;
    $path = "vendor_evaluations?select=id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at,updated_at&order=evaluated_at.desc&limit=200";
    if (vendor_score_is_vendor_role($role)) {
        if ($actorId === null) {
            vendor_scores_json_error(401, "Sign in required");
        }
        $path .= "&vendor_id=eq.{$actorId}";
    } elseif ($vendorId > 0) {
        $path .= "&vendor_id=eq.{$vendorId}";
    } elseif (!vendor_score_is_staff_role($role)) {
        vendor_scores_json_error(403, "Forbidden");
    }

    $result = supabase_request("GET", $path);
    if (!$result["ok"]) {
        vendor_scores_json_error($result["status"] ?: 500, $result["raw"] ?? "Failed to load evaluations.");
    }
    $rows = vendor_score_attach_people(is_array($result["data"]) ? $result["data"] : []);
    if ($view === "summary") {
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $vid = (int)($row["vendor_id"] ?? 0);
            if ($vid <= 0 || isset($seen[$vid])) {
                continue;
            }
            $seen[$vid] = true;
            $unique[] = vendor_score_public_row($row, []);
        }
        echo json_encode($unique);
        exit;
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = vendor_score_public_row($row, []);
    }
    echo json_encode($out);
    exit;
}

if ($method === "GET" && ($view === "get" || $view === "one")) {
    $id = trim((string)($_GET["id"] ?? ""));
    if (!vendor_score_is_uuid($id)) {
        vendor_scores_json_error(400, "A valid evaluation id is required.");
    }
    $eval = vendor_scores_load_eval($id);
    if ($eval === null) {
        vendor_scores_json_error(404, "Evaluation not found.");
    }
    if (vendor_score_is_vendor_role($role) && (int)$eval["vendor_id"] !== (int)$actorId) {
        vendor_scores_json_error(403, "You can only view your own evaluations.");
    } elseif (!vendor_score_is_staff_role($role) && !vendor_score_is_vendor_role($role)) {
        vendor_scores_json_error(403, "Forbidden");
    }
    vendor_scores_respond_eval($eval);
}

if ($method === "POST" && $view === "criteria") {
    require_roles(["admin"]);
    $input = json_decode((string)file_get_contents("php://input"), true);
    if (!is_array($input)) {
        vendor_scores_json_error(400, "Invalid request body");
    }
    $id = (int)($input["id"] ?? 0);
    if ($id <= 0) {
        vendor_scores_json_error(400, "criterion id is required");
    }
    $patch = [];
    if (array_key_exists("weight", $input)) {
        if (!is_numeric($input["weight"])) {
            vendor_scores_json_error(400, "Weight must be a number greater than 0 and at most 1.");
        }
        $w = (float)$input["weight"];
        if ($w <= 0 || $w > 1) {
            vendor_scores_json_error(400, "Weight must be greater than 0 and at most 1.");
        }
        $patch["weight"] = round($w, 4);
    }
    if (array_key_exists("is_active", $input)) {
        $patch["is_active"] = (bool)$input["is_active"];
    }
    if (array_key_exists("name", $input)) {
        $name = trim((string)$input["name"]);
        if ($name === "" || strlen($name) > 80) {
            vendor_scores_json_error(400, "Criterion name must be 1–80 characters.");
        }
        $patch["name"] = $name;
    }
    if (array_key_exists("description", $input)) {
        $patch["description"] = substr(trim((string)$input["description"]), 0, 400);
    }
    if ($patch === []) {
        vendor_scores_json_error(400, "No criterion fields to update.");
    }
    $patch["updated_at"] = gmdate("c");
    $result = supabase_request("PATCH", "vendor_score_criteria?id=eq.{$id}", $patch);
    if (!$result["ok"]) {
        vendor_scores_json_error($result["status"] ?: 500, $result["raw"] ?? "Failed to update criterion.");
    }
    audit_log_event("vendor_score_criteria_updated", ["id" => $id, "fields" => array_keys($patch)], $actorId);
    echo json_encode($result["data"][0] ?? ["ok" => true]);
    exit;
}

if ($method === "POST" && $view === "create") {
    if (!vendor_score_is_staff_role($role) || $actorId === null) {
        vendor_scores_json_error(403, "Only managers, the CEO, and admins can record vendor scores.");
    }
    $input = json_decode((string)file_get_contents("php://input"), true);
    if (!is_array($input)) {
        vendor_scores_json_error(400, "Invalid request body");
    }
    $vendorId = (int)($input["vendor_id"] ?? 0);
    if ($vendorId <= 0) {
        vendor_scores_json_error(400, "Select an existing vendor.");
    }
    $vendor = vendor_scores_assert_vendor_user($vendorId);
    $criteria = vendor_score_active_criteria();
    $calc = vendor_score_calculate($criteria, vendor_score_parse_scores_input($input["scores"] ?? []));
    if (!$calc["ok"]) {
        vendor_scores_json_error(400, (string)$calc["error"]);
    }
    $remarks = trim((string)($input["remarks"] ?? ""));
    if (strlen($remarks) > 2000) {
        vendor_scores_json_error(400, "Remarks must be 2000 characters or fewer.");
    }
    $evaluatedAt = trim((string)($input["evaluated_at"] ?? ""));
    if ($evaluatedAt === "") {
        $evaluatedAt = gmdate("c");
    } else {
        $ts = strtotime($evaluatedAt);
        if ($ts === false) {
            vendor_scores_json_error(400, "Evaluation date is invalid.");
        }
        $evaluatedAt = gmdate("c", $ts);
    }

    $overall = $calc["overall"];
    $rating = vendor_score_rating($overall);
    $insert = supabase_request("POST", "vendor_evaluations", [[
        "vendor_id" => $vendorId,
        "evaluator_id" => $actorId,
        "evaluated_at" => $evaluatedAt,
        "overall_score" => $overall,
        "rating" => $rating,
        "remarks" => $remarks === "" ? null : $remarks,
    ]]);
    if (!$insert["ok"] || !is_array($insert["data"][0] ?? null)) {
        vendor_scores_json_error($insert["status"] ?: 500, $insert["raw"] ?? "Could not save the evaluation.");
    }
    $eval = $insert["data"][0];
    if (!vendor_scores_persist_lines((string)$eval["id"], $calc["lines"])) {
        supabase_request("DELETE", "vendor_evaluations?id=eq." . urlencode((string)$eval["id"]));
        vendor_scores_json_error(500, "Could not save criterion scores.");
    }

    audit_log_event(
        "vendor_evaluation_created",
        ["evaluation_id" => $eval["id"], "vendor_id" => $vendorId, "overall_score" => $overall],
        $actorId
    );
    create_user_alert(
        $vendorId,
        "Vendor score recorded",
        "Your performance rating is " . number_format(vendor_star_rating($overall), 1) . " out of 5 (" . vendor_score_rating_label($rating) . ")."
    );

    vendor_scores_respond_eval($eval);
}

if ($method === "POST" && $view === "update") {
    if (!vendor_score_is_staff_role($role) || $actorId === null) {
        vendor_scores_json_error(403, "Only managers, the CEO, and admins can update vendor scores.");
    }
    $input = json_decode((string)file_get_contents("php://input"), true);
    if (!is_array($input)) {
        vendor_scores_json_error(400, "Invalid request body");
    }
    $id = trim((string)($input["id"] ?? ""));
    if (!vendor_score_is_uuid($id)) {
        vendor_scores_json_error(400, "A valid evaluation id is required.");
    }
    $eval = vendor_scores_load_eval($id);
    if ($eval === null) {
        vendor_scores_json_error(404, "Evaluation not found.");
    }
    if (!vendor_scores_can_write($role, $eval, $actorId)) {
        vendor_scores_json_error(403, "You can only edit evaluations you recorded.");
    }

    $criteria = vendor_score_active_criteria();
    $calc = vendor_score_calculate($criteria, vendor_score_parse_scores_input($input["scores"] ?? []));
    if (!$calc["ok"]) {
        vendor_scores_json_error(400, (string)$calc["error"]);
    }
    $remarks = array_key_exists("remarks", $input) ? trim((string)$input["remarks"]) : (string)($eval["remarks"] ?? "");
    if (strlen($remarks) > 2000) {
        vendor_scores_json_error(400, "Remarks must be 2000 characters or fewer.");
    }
    $evaluatedAt = trim((string)($input["evaluated_at"] ?? $eval["evaluated_at"] ?? ""));
    if ($evaluatedAt !== "") {
        $ts = strtotime($evaluatedAt);
        if ($ts === false) {
            vendor_scores_json_error(400, "Evaluation date is invalid.");
        }
        $evaluatedAt = gmdate("c", $ts);
    } else {
        $evaluatedAt = gmdate("c");
    }

    $overall = $calc["overall"];
    $rating = vendor_score_rating($overall);
    $patch = supabase_request("PATCH", "vendor_evaluations?id=eq." . urlencode($id), [
        "overall_score" => $overall,
        "rating" => $rating,
        "remarks" => $remarks === "" ? null : $remarks,
        "evaluated_at" => $evaluatedAt,
        "updated_at" => gmdate("c"),
    ]);
    if (!$patch["ok"] || !is_array($patch["data"][0] ?? null)) {
        vendor_scores_json_error($patch["status"] ?: 500, $patch["raw"] ?? "Could not update the evaluation.");
    }
    if (!vendor_scores_persist_lines($id, $calc["lines"])) {
        vendor_scores_json_error(500, "Could not save criterion scores.");
    }
    audit_log_event(
        "vendor_evaluation_updated",
        ["evaluation_id" => $id, "overall_score" => $overall],
        $actorId
    );
    vendor_scores_respond_eval($patch["data"][0]);
}

if ($method === "POST" && $view === "delete") {
    if (!vendor_score_is_staff_role($role) || $actorId === null) {
        vendor_scores_json_error(403, "Forbidden");
    }
    $input = json_decode((string)file_get_contents("php://input"), true);
    $id = trim((string)($input["id"] ?? $_GET["id"] ?? ""));
    if (!vendor_score_is_uuid($id)) {
        vendor_scores_json_error(400, "A valid evaluation id is required.");
    }
    $eval = vendor_scores_load_eval($id);
    if ($eval === null) {
        vendor_scores_json_error(404, "Evaluation not found.");
    }
    if (!vendor_scores_can_delete($role, $eval, $actorId)) {
        vendor_scores_json_error(403, "You can only delete evaluations you recorded (admins may delete any).");
    }
    $del = supabase_request("DELETE", "vendor_evaluations?id=eq." . urlencode($id));
    if (!$del["ok"]) {
        vendor_scores_json_error($del["status"] ?: 500, $del["raw"] ?? "Could not delete the evaluation.");
    }
    audit_log_event("vendor_evaluation_deleted", ["evaluation_id" => $id, "vendor_id" => $eval["vendor_id"] ?? null], $actorId);
    echo json_encode(["ok" => true, "id" => $id]);
    exit;
}

vendor_scores_json_error(405, "Method or view not allowed");
