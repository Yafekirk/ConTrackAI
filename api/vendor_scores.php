<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_vendor_score.php";

$sessionUser = require_session();
$role = normalize_role((string)($sessionUser["role"] ?? "vendor"));
$actorId = get_request_user_id();
$method = request_method();
$view = strtolower(trim((string)($_GET["view"] ?? "list")));
$isStaff = vendor_score_is_staff_role($role);
$isVendor = vendor_score_is_vendor_role($role);

const VENDOR_SCORES_EVAL_COLUMNS = "id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at,updated_at";

/** Load an evaluation by a validated UUID, answering 400 / 404 when it is not usable. */
function vendor_scores_require_eval(string $id): array
{
    if (!vendor_score_is_uuid($id)) {
        json_error("A valid evaluation id is required.");
    }
    $result = supabase_request("GET", "vendor_evaluations?id=eq." . urlencode($id) . "&select=" . VENDOR_SCORES_EVAL_COLUMNS . "&limit=1");
    if (!$result["ok"] || !is_array($result["data"][0] ?? null)) {
        json_error("Evaluation not found.", 404);
    }
    return $result["data"][0];
}

function vendor_scores_assert_vendor_user(int $vendorId): array
{
    $result = supabase_request("GET", "users?id=eq.{$vendorId}&select=id,name,email,role,supplier_type,contact_number,is_disabled&limit=1");
    $user = ($result["ok"] && is_array($result["data"][0] ?? null)) ? $result["data"][0] : json_error("Vendor not found.", 404);
    if (normalize_role((string)($user["role"] ?? "")) !== "vendor") {
        json_error("That account is not a vendor.");
    }
    return $user;
}

/** Admins and the CEO may edit any evaluation; other staff only their own. */
function vendor_scores_can_write(string $role, array $eval, ?int $actorId): bool
{
    return in_array($role, ["admin", "ceo"], true) || ($actorId !== null && (int)($eval["evaluator_id"] ?? 0) === $actorId);
}

/** Admins may delete any evaluation; other staff only their own. */
function vendor_scores_can_delete(string $role, array $eval, ?int $actorId): bool
{
    return $role === "admin" || ($actorId !== null && (int)($eval["evaluator_id"] ?? 0) === $actorId);
}

function vendor_scores_persist_lines(string $evaluationId, array $lines): bool
{
    supabase_request("DELETE", "vendor_evaluation_scores?evaluation_id=eq." . urlencode($evaluationId));
    $payload = array_map(static fn(array $line): array => [
        "evaluation_id" => $evaluationId,
        "criterion_id" => (int)$line["criterion_id"],
        "score" => $line["score"],
        "weighted_score" => $line["weighted_score"],
    ], array_values($lines));
    return $payload === [] || (bool)supabase_request("POST", "vendor_evaluation_scores", $payload)["ok"];
}

/** Validate remarks and the evaluation date shared by create and update. */
function vendor_scores_remarks_and_date(string $remarks, string $evaluatedAt): array
{
    if (strlen($remarks) > 2000) {
        json_error("Remarks must be 2000 characters or fewer.");
    }
    if ($evaluatedAt === "") {
        return [$remarks, gmdate("c")];
    }
    $ts = strtotime($evaluatedAt);
    return [$remarks, $ts === false ? json_error("Evaluation date is invalid.") : gmdate("c", $ts)];
}

function vendor_scores_respond_eval(array $eval): never
{
    $attached = vendor_score_attach_people([$eval])[0];
    json_response(vendor_score_public_row($attached, vendor_score_load_lines((string)$eval["id"])));
}

if ($method === "GET" && $view === "criteria") {
    $result = supabase_request(
        "GET",
        "vendor_score_criteria?" . ($isStaff ? "" : "is_active=eq.true&") . "select=id,code,name,description,weight,sort_order,is_active&order=sort_order.asc"
    );
    json_result($result, "Failed to load criteria");
}

if ($method === "GET" && $view === "vendors") {
    if (!$isStaff) {
        json_error("Only managers, the CEO, and admins can list vendors for scoring.", 403);
    }
    $users = supabase_request(
        "GET",
        "users?or=(role.eq.vendor,role.eq.client,role.eq.vendor_client)&select=id,name,company_name,email,role,supplier_type,contact_number,is_disabled&order=name.asc"
    );
    if (!$users["ok"]) {
        json_error(contrack_upstream_error($users, "Failed to load vendors."), $users["status"] ?: 500);
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
    json_response($out);
}

if ($method === "GET" && ($view === "list" || $view === "summary")) {
    $vendorId = isset($_GET["vendor_id"]) ? (int)$_GET["vendor_id"] : 0;
    $path = "vendor_evaluations?select=" . VENDOR_SCORES_EVAL_COLUMNS . "&order=evaluated_at.desc&limit=200";
    if ($isVendor) {
        $path .= "&vendor_id=eq." . ($actorId ?? json_error("Sign in required", 401));
    } elseif ($vendorId > 0) {
        $path .= "&vendor_id=eq.{$vendorId}";
    } elseif (!$isStaff) {
        json_error("Forbidden", 403);
    }

    $result = supabase_request("GET", $path);
    if (!$result["ok"]) {
        json_error(contrack_upstream_error($result, "Failed to load evaluations."), $result["status"] ?: 500);
    }
    $out = [];
    foreach (vendor_score_attach_people(is_array($result["data"]) ? $result["data"] : []) as $row) {
        if ($view === "list") {
            $out[] = vendor_score_public_row($row, []);
            continue;
        }
        // The summary keeps only the newest evaluation per vendor.
        $vid = (int)($row["vendor_id"] ?? 0);
        if ($vid > 0 && !isset($out[$vid])) {
            $out[$vid] = vendor_score_public_row($row, []);
        }
    }
    json_response(array_values($out));
}

if ($method === "GET" && ($view === "get" || $view === "one")) {
    $eval = vendor_scores_require_eval(trim((string)($_GET["id"] ?? "")));
    if ($isVendor && (int)$eval["vendor_id"] !== (int)$actorId) {
        json_error("You can only view your own evaluations.", 403);
    } elseif (!$isStaff && !$isVendor) {
        json_error("Forbidden", 403);
    }
    vendor_scores_respond_eval($eval);
}

if ($method === "POST" && $view === "criteria") {
    require_roles(["admin"]);
    $input = json_body();
    $id = (int)($input["id"] ?? 0);
    if ($id <= 0) {
        json_error("criterion id is required");
    }
    $patch = [];
    if (array_key_exists("weight", $input)) {
        if (!is_numeric($input["weight"])) {
            json_error("Weight must be a number greater than 0 and at most 1.");
        }
        $w = (float)$input["weight"];
        if ($w <= 0 || $w > 1) {
            json_error("Weight must be greater than 0 and at most 1.");
        }
        $patch["weight"] = round($w, 4);
    }
    if (array_key_exists("is_active", $input)) {
        $patch["is_active"] = (bool)$input["is_active"];
    }
    if (array_key_exists("name", $input)) {
        $name = trim((string)$input["name"]);
        if ($name === "" || strlen($name) > 80) {
            json_error("Criterion name must be 1–80 characters.");
        }
        $patch["name"] = $name;
    }
    if (array_key_exists("description", $input)) {
        $patch["description"] = substr(trim((string)$input["description"]), 0, 400);
    }
    if ($patch === []) {
        json_error("No criterion fields to update.");
    }
    $patch["updated_at"] = gmdate("c");
    $result = supabase_request("PATCH", "vendor_score_criteria?id=eq.{$id}", $patch);
    if (!$result["ok"]) {
        json_error(contrack_upstream_error($result, "Failed to update criterion."), $result["status"] ?: 500);
    }
    audit_log_event("vendor_score_criteria_updated", ["id" => $id, "fields" => array_keys($patch)], $actorId);
    json_response($result["data"][0] ?? ["ok" => true]);
}

// Every remaining POST view writes evaluations and is staff-only.
if ($method === "POST" && in_array($view, ["create", "update", "delete"], true) && (!$isStaff || $actorId === null)) {
    json_error([
        "create" => "Only managers, the CEO, and admins can record vendor scores.",
        "update" => "Only managers, the CEO, and admins can update vendor scores.",
        "delete" => "Forbidden",
    ][$view], 403);
}

if ($method === "POST" && $view === "create") {
    $input = json_body();
    $vendorId = (int)($input["vendor_id"] ?? 0);
    if ($vendorId <= 0) {
        json_error("Select an existing vendor.");
    }
    vendor_scores_assert_vendor_user($vendorId);
    $calc = vendor_score_calculate(vendor_score_active_criteria(), vendor_score_parse_scores_input($input["scores"] ?? []));
    if (!$calc["ok"]) {
        json_error((string)$calc["error"]);
    }
    [$remarks, $evaluatedAt] = vendor_scores_remarks_and_date(trim((string)($input["remarks"] ?? "")), trim((string)($input["evaluated_at"] ?? "")));

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
        json_error(contrack_upstream_error($insert, "Could not save the evaluation."), $insert["status"] ?: 500);
    }
    $eval = $insert["data"][0];
    if (!vendor_scores_persist_lines((string)$eval["id"], $calc["lines"])) {
        supabase_request("DELETE", "vendor_evaluations?id=eq." . urlencode((string)$eval["id"]));
        json_error("Could not save criterion scores.", 500);
    }

    audit_log_event("vendor_evaluation_created", ["evaluation_id" => $eval["id"], "vendor_id" => $vendorId, "overall_score" => $overall], $actorId);
    create_user_alert(
        $vendorId,
        "Vendor score recorded",
        "Your performance rating is " . number_format(vendor_star_rating($overall), 1) . " out of 5 (" . vendor_score_rating_label($rating) . ").",
        "announcement"
    );
    vendor_scores_respond_eval($eval);
}

if ($method === "POST" && $view === "update") {
    $input = json_body();
    $id = trim((string)($input["id"] ?? ""));
    $eval = vendor_scores_require_eval($id);
    if (!vendor_scores_can_write($role, $eval, $actorId)) {
        json_error("You can only edit evaluations you recorded.", 403);
    }
    $calc = vendor_score_calculate(vendor_score_active_criteria(), vendor_score_parse_scores_input($input["scores"] ?? []));
    if (!$calc["ok"]) {
        json_error((string)$calc["error"]);
    }
    [$remarks, $evaluatedAt] = vendor_scores_remarks_and_date(
        array_key_exists("remarks", $input) ? trim((string)$input["remarks"]) : (string)($eval["remarks"] ?? ""),
        trim((string)($input["evaluated_at"] ?? $eval["evaluated_at"] ?? ""))
    );

    $overall = $calc["overall"];
    $rating = vendor_score_rating($overall);
    $evalPath = "vendor_evaluations?id=eq." . urlencode($id);
    // Keep the saved version so a failed write can be rolled back instead of leaving a half-updated score.
    $previousLines = vendor_score_load_lines($id);
    $previousHeader = [];
    foreach (["overall_score", "rating", "remarks", "evaluated_at", "updated_at"] as $key) {
        $previousHeader[$key] = $eval[$key] ?? null;
    }
    $patch = supabase_request("PATCH", $evalPath, [
        "overall_score" => $overall,
        "rating" => $rating,
        "remarks" => $remarks === "" ? null : $remarks,
        "evaluated_at" => $evaluatedAt,
        "updated_at" => gmdate("c"),
    ]);
    if (!$patch["ok"] || !is_array($patch["data"][0] ?? null)) {
        json_error(contrack_upstream_error($patch, "Could not update the evaluation."), $patch["status"] ?: 500);
    }
    if (!vendor_scores_persist_lines($id, $calc["lines"])) {
        supabase_request("PATCH", $evalPath, $previousHeader);
        $restored = vendor_scores_persist_lines($id, array_map(static fn(array $l): array => [
            "criterion_id" => $l["criterion_id"] ?? 0,
            "score" => $l["score"] ?? 0,
            "weighted_score" => $l["weighted_score"] ?? 0,
        ], $previousLines));
        if (!$restored) {
            error_log("vendor score update rollback failed for evaluation {$id}");
        }
        json_error("Could not save criterion scores.", 500);
    }
    audit_log_event("vendor_evaluation_updated", ["evaluation_id" => $id, "overall_score" => $overall], $actorId);
    vendor_scores_respond_eval($patch["data"][0]);
}

if ($method === "POST" && $view === "delete") {
    $id = trim((string)(json_input()["id"] ?? $_GET["id"] ?? ""));
    $eval = vendor_scores_require_eval($id);
    if (!vendor_scores_can_delete($role, $eval, $actorId)) {
        json_error("You can only delete evaluations you recorded (admins may delete any).", 403);
    }
    $del = supabase_request("DELETE", "vendor_evaluations?id=eq." . urlencode($id));
    if (!$del["ok"]) {
        json_error(contrack_upstream_error($del, "Could not delete the evaluation."), $del["status"] ?: 500);
    }
    audit_log_event("vendor_evaluation_deleted", ["evaluation_id" => $id, "vendor_id" => $eval["vendor_id"] ?? null], $actorId);
    json_response(["ok" => true, "id" => $id]);
}

json_error("Method or view not allowed", 405);
