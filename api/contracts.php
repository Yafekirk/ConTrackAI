<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_contract_pdf.php";
require_once __DIR__ . "/lib_contract_duplicates.php";
require_once __DIR__ . "/lib_contract_intel.php";
require_once __DIR__ . "/lib_python_nlp.php";
require_once __DIR__ . "/lib_vendor_score.php";

/** Rows from a successful Supabase read; answers with the upstream error otherwise. */
function contracts_rows(array $result): array
{
    if (!$result["ok"]) {
        json_error(contrack_upstream_error($result, "Supabase request failed"), (int)$result["status"]);
    }
    return is_array($result["data"]) ? $result["data"] : [];
}

/** JSON body that names a contract, answering 400 "id is required" otherwise. */
function contracts_input_with_id(): array
{
    $input = json_input();
    if ($input === null || empty($input["id"])) {
        json_error("id is required");
    }
    return $input;
}

/** "CODE · " prefix for alert messages, empty when the contract has no code yet. */
function contracts_code_label(array $row): string
{
    $code = trim((string)($row["contract_code"] ?? ""));
    return $code !== "" ? "{$code} · " : "";
}

$view = $_GET["view"] ?? "stats";
require_session();
$role = normalize_role(get_request_role());
$userId = get_request_user_id();
$isVendor = $role === "vendor";
// Vendors only ever see their own contracts.
$vendorScope = $isVendor ? "&vendor_id=eq." . $userId : "";

if ($isVendor && $userId === null && in_array($view, ["performance", "stats", "list", "renewals"], true)) {
    json_error("Vendor/client requests require user_id");
}

if ($view === "duplicate_check") {
    require_method("POST");
    $input = json_input() ?? [];
    $pdfSize = isset($input["pdf_size"]) ? (int)$input["pdf_size"] : null;
    $match = $userId !== null
        ? contract_duplicate_match_pdf(contract_duplicate_vendor_rows($userId), (string)($input["pdf_sha256"] ?? ""), $pdfSize)
        : null;
    if ($match === null) {
        json_response(["duplicate" => false]);
    }
    json_response([
        "duplicate" => true,
        "reason" => "pdf",
        "error" => contract_duplicate_message($match, "pdf"),
        "duplicate_of" => contract_duplicate_summary($match),
    ]);
}

if ($view === "nlp_extract") {
    require_method("POST");
    $text = "";
    $pdfTmp = null;
    if (stripos((string)($_SERVER["CONTENT_TYPE"] ?? ""), "multipart/form-data") !== false) {
        $text = trim((string)($_POST["contract_text"] ?? ""));
        if ((int)($_FILES["contract_pdf"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $name = strtolower((string)($_FILES["contract_pdf"]["name"] ?? ""));
            $tmp = (string)($_FILES["contract_pdf"]["tmp_name"] ?? "");
            $head = ($tmp !== "" && is_file($tmp)) ? (string)@file_get_contents($tmp, false, null, 0, 5) : "";
            $isPdf = str_ends_with($name, ".pdf") || str_starts_with($head, "%PDF");
            $uploadSize = (int)($_FILES["contract_pdf"]["size"] ?? 0);
            if ($isPdf && $tmp !== "" && is_uploaded_file($tmp) && $uploadSize > 0 && $uploadSize <= 26 * 1024 * 1024) {
                $pdfTmp = $tmp;
            }
        }
    } else {
        $text = trim((string)(json_input()["contract_text"] ?? ""));
    }

    $nlpStarted = microtime(true);
    $extracted = python_nlp_extract($text, $pdfTmp);
    if ($extracted === null) {
        $extracted = extract_contract_fields($text);
        $extracted["engine"] = "php-regex";
    }
    $uploadName = (string)($_FILES["contract_pdf"]["name"] ?? "");
    nlp_usage_from_extract("extract", $extracted, $nlpStarted, [
        "user_id" => $userId,
        "source_file_name" => $uploadName !== "" ? basename($uploadName) : null,
        "input_kind" => nlp_input_kind($pdfTmp, $text),
        "input_chars" => strlen($text),
    ]);
    $payload = json_encode(["extracted" => $extracted], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        json_error("Could not encode extraction result", 200);
    }
    echo $payload;
    exit;
}

if ($view === "performance") {
    $result = supabase_request("GET", "vendor_contracts?select=id,status,contract_value,uploaded_at,contract_title,start_date,end_date,payment_terms,submission_text,scope_of_work,classification,renewal_terms,penalty_clause,financial_obligations" . $vendorScope);
    if (!$result["ok"]) {
        // Older schemas may lack submission_text / NLP columns — retry with a minimal select.
        $result = supabase_request("GET", "vendor_contracts?select=id,status,contract_value,uploaded_at,contract_title,start_date,end_date,payment_terms" . $vendorScope);
    }
    $rows = contracts_rows($result);
    $metrics = build_vendor_metrics($rows);

    $latestEval = null;
    $evalHistory = [];
    $breakdown = [];
    $criteriaView = vendor_score_criteria_view([]);
    if ($isVendor) {
        $evals = supabase_request(
            "GET",
            "vendor_evaluations?vendor_id=eq.{$userId}&select=id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at&order=evaluated_at.desc&limit=24"
        );
        if ($evals["ok"] && is_array($evals["data"])) {
            $attached = vendor_score_attach_people($evals["data"]);
            foreach ($attached as $ev) {
                $score = (float)($ev["overall_score"] ?? 0);
                $evalHistory[] = [
                    "id" => $ev["id"] ?? null,
                    "evaluated_at" => $ev["evaluated_at"] ?? null,
                    "overall_score" => $score,
                    "star_rating" => vendor_star_rating($score),
                    "rating" => $ev["rating"] ?? null,
                    "rating_label" => vendor_score_rating_label((string)($ev["rating"] ?? "")),
                    "evaluator_name" => $ev["evaluator_name"] ?? null,
                    "remarks" => $ev["remarks"] ?? null,
                ];
            }
            if (isset($attached[0]["id"])) {
                $latestEval = $attached[0];
                $scoreLines = vendor_score_load_lines((string)$attached[0]["id"]);
                $breakdown = vendor_score_breakdown_map($scoreLines);
                $criteriaView = vendor_score_criteria_view($scoreLines);
            }
        }
    }

    $history = array_map(static fn(array $row): array => [
        "id" => $row["id"] ?? null,
        "contract_title" => $row["contract_title"] ?? "",
        "start_date" => $row["start_date"] ?? null,
        "end_date" => $row["end_date"] ?? null,
        "contract_value" => $row["contract_value"] ?? 0,
        "status" => $row["status"] ?? "",
        "monitor_status" => $row["monitor_status"] ?? "",
        "risk" => $row["risk"] ?? "low",
    ], enrich_contract_rows($rows));

    $overall = $latestEval !== null ? (float)$latestEval["overall_score"] : 0.0;
    $rating = $latestEval !== null ? (string)$latestEval["rating"] : null;
    json_response([
        "overall_score" => $overall,
        "star_rating" => $latestEval !== null ? vendor_star_rating($overall) : null,
        "rating" => $rating,
        "rating_label" => $rating ? vendor_score_rating_label($rating) : null,
        "evaluated_at" => $latestEval["evaluated_at"] ?? null,
        "evaluator_name" => $latestEval["evaluator_name"] ?? null,
        "remarks" => $latestEval["remarks"] ?? null,
        "has_manual_score" => $latestEval !== null,
        "criteria" => $criteriaView,
        "breakdown" => $breakdown,
        "contract_totals" => $metrics["totals"],
        "renewal_due" => $metrics["renewal_due"],
        "history" => $evalHistory,
        "contracts" => $history,
    ]);
}

if ($view === "stats") {
    $rows = contracts_rows(supabase_request("GET", "vendor_contracts?select=id,status,contract_value,end_date,uploaded_at" . $vendorScope));
    $today = new DateTimeImmutable("today");
    $todayPlus30 = $today->add(new DateInterval("P30D"));
    $count = array_fill_keys(["pending", "rejected", "renegotiation", "terminated", "approved", "active", "expired", "expiring"], 0);
    $value = array_fill_keys(["all", "active", "pending", "rejected", "expiring"], 0.0);

    foreach ($rows as $row) {
        $status = strtolower((string)($row["status"] ?? ""));
        $amount = (float)($row["contract_value"] ?? 0);
        $value["all"] += $amount;
        if (in_array($status, ["pending", "rejected", "renegotiation", "terminated"], true)) {
            $count[$status]++;
            if (isset($value[$status])) {
                $value[$status] += $amount;
            }
            continue;
        }
        if ($status !== "approved") {
            continue;
        }
        $count["approved"]++;
        // Approved contracts with no (or an unreadable) end date count as active.
        $endDay = null;
        $endRaw = $row["end_date"] ?? null;
        if ($endRaw !== null && $endRaw !== "") {
            try {
                $endDay = (new DateTimeImmutable((string)$endRaw))->setTime(0, 0, 0);
            } catch (Exception) {
                $endDay = null;
            }
        }
        if ($endDay !== null && $endDay < $today) {
            $count["expired"]++;
            continue;
        }
        $count["active"]++;
        $value["active"] += $amount;
        if ($endDay !== null && $endDay <= $todayPlus30) {
            $count["expiring"]++;
            $value["expiring"] += $amount;
        }
    }

    json_response([
        "total_contracts" => count($rows),
        "approved" => $count["approved"],
        "active_contracts" => $count["active"],
        "expired_approved" => $count["expired"],
        "pending" => $count["pending"],
        "rejected" => $count["rejected"],
        "renegotiation" => $count["renegotiation"],
        "terminated" => $count["terminated"],
        "expiring_30_days" => $count["expiring"],
        "total_contract_value" => round($value["all"], 2),
        "total_contract_value_active" => round($value["active"], 2),
        "value_pending" => round($value["pending"], 2),
        "value_rejected" => round($value["rejected"], 2),
        "value_expiring" => round($value["expiring"], 2),
        "archived_contracts" => $count["expired"] + $count["terminated"] + $count["rejected"],
        "renewal_due" => $count["expiring"] + $count["renegotiation"],
        "role_scope" => $role,
    ]);
}

if ($view === "vendor_summary") {
    require_roles(["ceo", "manager", "admin"]);
    $batch = supabase_request_many([
        "contracts" => ["GET", "vendor_contracts?select=vendor_id,vendor_name,contract_type,contract_value,status,end_date"],
        "users" => ["GET", "users?role=eq.vendor&select=id,name,company_name,email,role,created_at&order=name.asc"],
        "scores" => ["GET", VENDOR_SCORE_LATEST_PATH],
    ]);
    $contractRows = contracts_rows($batch["contracts"]);
    $userRows = ($batch["users"]["ok"] && is_array($batch["users"]["data"])) ? $batch["users"]["data"] : [];

    $userById = [];
    foreach ($userRows as $u) {
        $uid = (int)($u["id"] ?? 0);
        if ($uid > 0) {
            $userById[$uid] = $u;
        }
    }

    // Group contracts per vendor, keeping the longest vendor name seen on any of them.
    $agg = [];
    foreach ($contractRows as $row) {
        $vid = (int)($row["vendor_id"] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $agg[$vid] ??= ["vendor_name" => "", "contracts" => []];
        $agg[$vid]["contracts"][] = $row;
        $vn = trim((string)($row["vendor_name"] ?? ""));
        if (strlen($vn) > strlen($agg[$vid]["vendor_name"])) {
            $agg[$vid]["vendor_name"] = $vn;
        }
    }
    foreach ($userById as $vid => $u) {
        $agg[$vid] ??= ["vendor_name" => contrack_public_name($u), "contracts" => []];
    }

    $latestScores = vendor_score_latest_by_vendor($batch["scores"]);
    $out = [];
    foreach ($agg as $vid => $bundle) {
        $name = $bundle["vendor_name"];
        $email = "";
        if (isset($userById[$vid])) {
            $email = (string)($userById[$vid]["email"] ?? "");
            $display = contrack_public_name($userById[$vid]);
            if (($display !== "" && $display !== "Vendor") || $name === "") {
                $name = $display;
            }
        }
        $name = $name !== "" ? $name : "Vendor #" . (string)$vid;

        $contracts = $bundle["contracts"];
        $statusCount = ["approved" => 0, "pending" => 0, "rejected" => 0];
        $typeCounts = [];
        $sumVal = 0.0;
        foreach ($contracts as $c) {
            $sumVal += (float)($c["contract_value"] ?? 0);
            $st = strtolower((string)($c["status"] ?? ""));
            if (isset($statusCount[$st])) {
                $statusCount[$st]++;
            }
            $ct = trim((string)($c["contract_type"] ?? ""));
            if ($ct !== "") {
                $typeCounts[$ct] = ($typeCounts[$ct] ?? 0) + 1;
            }
        }
        arsort($typeCounts);
        $metrics = build_vendor_metrics($contracts);
        $scoreRow = $latestScores[$vid] ?? null;
        $overall = $scoreRow !== null ? (float)$scoreRow["overall_score"] : null;
        $rating = $scoreRow !== null ? (string)$scoreRow["rating"] : null;

        $out[] = [
            "vendor_id" => $vid,
            "vendor_name" => $name,
            "vendor_email" => $email,
            "contract_type" => $typeCounts !== [] ? (string)array_key_first($typeCounts) : "—",
            "contract_count" => count($contracts),
            "total_value" => round($sumVal, 2),
            "vendor_score" => $overall,
            "ai_score" => $overall,
            "star_rating" => $overall !== null ? vendor_star_rating($overall) : null,
            "rating" => $rating,
            "rating_label" => $rating ? vendor_score_rating_label($rating) : null,
            "evaluated_at" => $scoreRow["evaluated_at"] ?? null,
            "has_manual_score" => $scoreRow !== null,
            "risk" => $metrics["risk"],
            "risk_score" => $metrics["risk_score"],
            "recommendations" => $metrics["recommendations"],
            "breakdown" => [],
            "approved_count" => $statusCount["approved"],
            "pending_count" => $statusCount["pending"],
            "rejected_count" => $statusCount["rejected"],
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = $b["total_value"] <=> $a["total_value"];
        return $cmp !== 0 ? $cmp : strcmp((string)$a["vendor_name"], (string)$b["vendor_name"]);
    });
    json_response($out);
}

if ($view === "pending_approvals") {
    require_roles(["ceo", "manager", "admin"]);
    // CEO queue = pending contracts already reviewed by a manager (system flow).
    // Managers/admins may still list all pending when opening the same view.
    $columns = "id,contract_code,vendor_id,vendor_name,contract_title,contract_type,contract_value,end_date,status,uploaded_at,reviewed_at,reviewed_by,scope_of_work,payment_terms,manager_notes,recommended_action,manager_reviewed_at,manager_reviewed_by";
    $path = $role === "ceo"
        ? "vendor_contracts?status=eq.pending&manager_reviewed_at=not.is.null&select={$columns}&order=manager_reviewed_at.desc"
        : "vendor_contracts?status=eq.pending&select={$columns}&order=uploaded_at.desc";
    json_response(enrich_contract_rows(contracts_rows(supabase_request("GET", $path))));
}

if ($view === "manager_pending" || $view === "manager_mine") {
    require_roles(["manager", "admin"]);
    if ($userId === null && ($view === "manager_mine" || $role !== "admin")) {
        json_error("Session user id required");
    }
    // manager_pending: the queue not yet reviewed by any manager. manager_mine: contracts this manager reviewed.
    $path = $view === "manager_pending"
        ? "vendor_contracts?status=eq.pending&manager_reviewed_at=is.null&select=*&order=uploaded_at.desc"
        : "vendor_contracts?manager_reviewed_by=eq." . $userId . "&select=*&order=manager_reviewed_at.desc";
    json_response(enrich_contract_rows(contracts_rows(supabase_request("GET", $path))));
}

if ($view === "list") {
    $rows = enrich_contract_rows(contracts_rows(supabase_request("GET", "vendor_contracts?select=*&order=uploaded_at.desc" . $vendorScope)));
    $filters = [
        "archived" => static fn(array $r): bool => !empty($r["is_archived"]),
        "renewals" => static fn(array $r): bool => !empty($r["renewal_due"]),
        "active" => static fn(array $r): bool => empty($r["is_archived"]),
    ];
    $filter = $filters[strtolower(trim((string)($_GET["filter"] ?? "")))] ?? null;
    json_response($filter !== null ? array_values(array_filter($rows, $filter)) : $rows);
}

if ($view === "renewals") {
    $notices = [];
    foreach (enrich_contract_rows(contracts_rows(supabase_request("GET", "vendor_contracts?select=*&order=end_date.asc" . $vendorScope))) as $row) {
        $notice = renewal_notice_for_row($row);
        if ($notice !== null && empty($row["is_archived"])) {
            $notices[] = $notice;
        }
    }
    json_response($notices);
}

if ($view === "archive") {
    require_roles(["ceo", "manager", "admin"]);
    require_method("POST");
    $input = contracts_input_with_id();
    $contractId = urlencode((string)$input["id"]);
    $fetch = supabase_request("GET", "vendor_contracts?id=eq.{$contractId}&select=*&limit=1");
    $row = ($fetch["ok"] && is_array($fetch["data"]) && isset($fetch["data"][0])) ? enrich_contract_row($fetch["data"][0]) : json_error("Contract not found", 404);
    $archived = ["ok" => true, "id" => $input["id"], "is_archived" => true, "status" => $row["status"] ?? null];
    if (!empty($row["is_archived"])) {
        json_response($archived);
    }
    if (!in_array(strtolower((string)($row["status"] ?? "")), ["rejected", "terminated"], true) && ($row["monitor_status"] ?? "") !== "expired") {
        json_error("Only inactive (expired, rejected, or terminated) contracts can be archived");
    }
    // status is constrained to pending/approved/rejected/terminated/renegotiation — archiving is the is_archived flag.
    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}", ["is_archived" => true, "archived_at" => gmdate("c")]);
    if (!$result["ok"]) {
        json_error(contrack_upstream_error($result, "Failed to archive"), (int)$result["status"]);
    }
    audit_log_event("contract_archived", ["contract_id" => (string)$input["id"]], $userId);
    json_response($archived);
}

if ($view === "manager_review") {
    require_roles(["manager", "admin"]);
    require_method("POST");
    if ($userId === null) {
        json_error("Session user id required");
    }
    $input = contracts_input_with_id();
    $contractId = urlencode((string)$input["id"]);
    $notes = trim((string)($input["manager_notes"] ?? ""));
    $action = trim((string)($input["recommended_action"] ?? ""));
    if (!in_array($action, ["escalate_to_ceo", "reject", "modification"], true)) {
        json_error("Choose approve, reject, or modify");
    }
    if ($action !== "escalate_to_ceo" && $notes === "") {
        json_error("Add manager notes before rejecting or requesting a modification");
    }

    $payload = [
        "manager_notes" => $notes !== "" ? $notes : null,
        "recommended_action" => $action,
        "manager_reviewed_at" => gmdate("c"),
        "manager_reviewed_by" => $userId,
    ];
    if ($action === "modification") {
        $payload["status"] = "modification";
    }
    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}&status=eq.pending", $payload);
    if ($result["ok"] && is_array($result["data"]) && $result["data"] === []) {
        // The guarded update matched nothing (wrong id, or no longer pending): do not audit or notify.
        json_error("This contract is no longer pending review.", 409);
    }
    if (!$result["ok"]) {
        json_result($result, "Supabase request failed");
    }

    $reviewedRow = is_array($result["data"][0] ?? null) ? $result["data"][0] : [];
    if ($reviewedRow === []) {
        $lookup = supabase_request("GET", "vendor_contracts?id=eq.{$contractId}&select=id,vendor_id,vendor_name,contract_title,status,manager_reviewed_by&limit=1");
        if ($lookup["ok"] && is_array($lookup["data"][0] ?? null)) {
            $reviewedRow = $lookup["data"][0];
        }
    }
    audit_log_event("contract_manager_review", ["contract_id" => (string)$input["id"], "recommended_action" => $action], $userId);

    $title = (string)($reviewedRow["contract_title"] ?? "Contract");
    $vendorLabel = (string)($reviewedRow["vendor_name"] ?? "Vendor");
    $codeLabel = contracts_code_label($reviewedRow);
    $vendorOwner = isset($reviewedRow["vendor_id"]) ? (int)$reviewedRow["vendor_id"] : 0;
    if ($action === "modification") {
        create_user_alert($vendorOwner, "Changes requested", "{$codeLabel}{$title} needs modification. Update it and resubmit for review.", "contract", true);
    } else {
        $actionLabel = $action === "reject" ? "recommend rejection" : "recommend approval";
        notify_role_users(
            "ceo",
            "Ready for CEO decision",
            "{$codeLabel}{$title} from {$vendorLabel} was reviewed by a manager ({$actionLabel}). Open Approvals to decide.",
            true
        );
        create_user_alert($vendorOwner, "Manager reviewed your contract", "{$codeLabel}{$title} is awaiting CEO decision.", "contract", true);
    }
    json_result($result, "Supabase request failed", ["ok" => true]);
}

if ($view === "vendor_resubmit") {
    require_roles(["vendor"]);
    require_method("POST");
    if ($userId === null) {
        json_error("Session user id required");
    }
    $input = contracts_input_with_id();
    $contractId = urlencode((string)$input["id"]);
    $fetch = supabase_request("GET", "vendor_contracts?id=eq.{$contractId}&select=id,vendor_id,status,contract_title,contract_code&limit=1");
    $row = ($fetch["ok"] && is_array($fetch["data"][0] ?? null)) ? $fetch["data"][0] : json_error("Contract not found", 404);
    if ((int)($row["vendor_id"] ?? 0) !== $userId) {
        json_error("You can only resubmit your own contract", 403);
    }
    if (strtolower((string)($row["status"] ?? "")) !== "modification") {
        json_error("Only contracts sent back for modification can be resubmitted");
    }

    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}&status=eq.modification", [
        "status" => "pending",
        "manager_reviewed_at" => null,
        "manager_reviewed_by" => null,
        "recommended_action" => null,
    ]);
    if (!$result["ok"]) {
        json_error(contrack_upstream_error($result, "Failed to resubmit"), $result["status"] ?: 500);
    }
    $title = (string)($row["contract_title"] ?? "Contract");
    notify_role_users("manager", "Contract resubmitted", contracts_code_label($row) . "{$title} is back in the review queue.", true);
    audit_log_event("contract_resubmitted", ["contract_id" => (string)$input["id"], "contract_code" => trim((string)($row["contract_code"] ?? ""))], $userId);
    json_response(["ok" => true, "id" => $input["id"], "status" => "pending"]);
}

if ($view === "create") {
    require_method("POST");
    if ($isVendor && $userId === null) {
        json_error("Vendor/client requests require user_id");
    }

    $maxPdfBytes = 26 * 1024 * 1024;
    $pdfTmpPath = null;
    $pdfBase64 = "";
    if (stripos((string)($_SERVER["CONTENT_TYPE"] ?? ""), "multipart/form-data") !== false) {
        $input = $_POST;
        $uploadErr = (int)($_FILES["contract_pdf"]["error"] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
            if (preg_match("/\\.(doc|docx|xls|xlsx|txt|rtf)$/", strtolower((string)($_FILES["contract_pdf"]["name"] ?? "")))) {
                json_error("Only PDF files are accepted. Word and other document types are not allowed.");
            }
            if ($uploadErr !== UPLOAD_ERR_OK) {
                json_error("PDF upload failed. Please try again with a PDF file.");
            }
            $tmp = (string)($_FILES["contract_pdf"]["tmp_name"] ?? "");
            $size = (int)($_FILES["contract_pdf"]["size"] ?? 0);
            if ($tmp !== "" && is_uploaded_file($tmp) && $size > 0 && $size <= $maxPdfBytes) {
                $head = @file_get_contents($tmp, false, null, 0, 5);
                if (is_string($head) && strlen($head) >= 4 && substr($head, 0, 4) === "%PDF") {
                    $pdfTmpPath = $tmp;
                }
            }
            if ($pdfTmpPath === null) {
                json_error("Only PDF files are accepted.");
            }
        }
    } else {
        $input = json_body();
        $pdfBase64 = trim((string)($input["pdf_base64"] ?? ""));
        // 26 MB of PDF is about 34.7 MB of base64; refuse anything larger before decoding or running NLP.
        if (strlen($pdfBase64) > 36 * 1024 * 1024) {
            json_error("PDF is too large (26 MB maximum).", 413);
        }
    }

    $contractTextRaw = trim((string)($input["contract_text"] ?? ""));
    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        $submissionText = mb_strlen($contractTextRaw) > 500000 ? mb_substr($contractTextRaw, 0, 500000) : $contractTextRaw;
    } else {
        $submissionText = substr($contractTextRaw, 0, 500000);
    }

    $nlpStarted = microtime(true);
    // The upload page already ran the extractor on this PDF (view=nlp_extract) and the vendor
    // reviewed the result, so a second Python run here only repeats several seconds of work.
    // The fast PHP pass still fills any optional field the form left empty.
    $clientEngine = (string)($input["nlp_engine"] ?? "");
    $clientExtracted = in_array($clientEngine, ["python-ml", "php-regex"], true)
        && trim((string)($input["contract_title"] ?? "")) !== ""
        && trim((string)($input["contract_type"] ?? "")) !== ""
        && (float)($input["contract_value"] ?? 0) > 0
        && trim((string)($input["end_date"] ?? "")) !== "";
    $nlp = $clientExtracted ? extract_contract_fields($contractTextRaw) : python_nlp_extract($contractTextRaw, $pdfTmpPath);
    if ($clientExtracted) {
        $nlp["engine"] = $clientEngine;
    } elseif (!is_array($nlp)) {
        $nlp = extract_contract_fields($contractTextRaw);
        $nlp["engine"] = "php-regex";
    }
    $nlpUsageCtx = [
        "user_id" => $userId,
        "duration_ms" => (int)round((microtime(true) - $nlpStarted) * 1000),
        "source_file_name" => trim((string)($input["source_file_name"] ?? "")),
        "input_kind" => nlp_input_kind($pdfTmpPath, $contractTextRaw),
        "input_chars" => strlen($contractTextRaw),
    ];
    $rejectSubmit = static function (string $message, int $status = 400, array $extra = []) use ($nlp, $nlpStarted, $nlpUsageCtx): never {
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, $nlpUsageCtx);
        json_response(["error" => $message] + $extra, $status);
    };

    // Form value first, extracted value when the form left it empty.
    $pick = static function (string $key) use ($input, $nlp): string {
        $value = trim((string)($input[$key] ?? ""));
        return $value !== "" ? $value : trim((string)($nlp[$key] ?? ""));
    };
    // Form value whenever it was sent (even empty), extracted value otherwise.
    $sent = static fn(string $key): string => trim((string)($input[$key] ?? ($nlp[$key] ?? "")));
    $orNull = static fn(string $value): ?string => $value !== "" ? $value : null;

    $contractTitle = $pick("contract_title");
    $contractType = $pick("contract_type");
    $startDate = $pick("start_date");
    $endDate = $pick("end_date");
    $scope = $pick("scope");
    $renewalTerms = $pick("renewal_terms");
    $penaltyClause = $pick("penalty_clause");
    $financialObligations = $pick("financial_obligations");
    $terminationClause = $pick("termination_clause");
    $classification = $sent("classification");
    $contractValue = isset($input["contract_value"]) ? (float)$input["contract_value"] : 0.0;
    if ($contractValue <= 0 && isset($nlp["contract_value"])) {
        $contractValue = (float)$nlp["contract_value"];
    }
    $paymentTerms = format_payment_terms_duration(trim((string)($input["payment_terms"] ?? "")));
    if ($paymentTerms === "") {
        $paymentTerms = format_payment_terms_duration(trim((string)($nlp["payment_terms"] ?? "")));
    }
    $vendorName = trim((string)($input["vendor_name"] ?? ""));
    if ($vendorName === "") {
        $vendorName = trim((string)($nlp["vendor_name"] ?? contrack_public_name(get_session_user() ?? [])));
    }
    $currency = strtoupper(trim((string)($input["currency"] ?? "")));
    if ($currency === "") {
        $currency = strtoupper(trim((string)($nlp["currency"] ?? "PHP")));
    }
    $currency = preg_match('/\b(PHP|USD|EUR)\b/', $currency, $cm) ? strtoupper($cm[1]) : "PHP";

    if ($contractTitle === "" || $contractType === "" || $contractValue <= 0 || $endDate === "") {
        $rejectSubmit("contract_title, contract_type, contract_value, and end_date are required");
    }
    $endTs = strtotime($endDate);
    $startTs = $startDate !== "" ? strtotime($startDate) : null;
    $inputError = match (true) {
        $endTs === false => "end_date is not a valid date",
        $startTs === false => "start_date is not a valid date",
        $startTs !== null && $startTs > $endTs => "start_date cannot be after end_date",
        $contractValue > 1e13 => "contract_value is too large",
        default => null,
    };
    if ($inputError !== null) {
        $rejectSubmit($inputError);
    }
    if ($isVendor && $pdfTmpPath === null && $pdfBase64 === "") {
        $rejectSubmit("A PDF file is required. Word documents are not accepted.");
    }

    if ($userId !== null) {
        $existingRows = contract_duplicate_vendor_rows($userId);
        $duplicateReason = "pdf";
        $duplicateRow = null;
        if ($pdfTmpPath !== null) {
            $duplicateRow = contract_duplicate_match_pdf($existingRows, (string)@hash_file("sha256", $pdfTmpPath), (int)@filesize($pdfTmpPath));
        } elseif ($pdfBase64 !== "") {
            $decodedPdf = base64_decode((string)preg_replace('/\s+/', "", $pdfBase64), true);
            if ($decodedPdf !== false) {
                $duplicateRow = contract_duplicate_match_pdf($existingRows, hash("sha256", $decodedPdf), strlen($decodedPdf));
            }
        }
        if ($duplicateRow === null) {
            $duplicateReason = "terms";
            $duplicateRow = contract_duplicate_match_terms($existingRows, [
                "contract_title" => $contractTitle,
                "contract_type" => $contractType,
                "contract_value" => $contractValue,
                "start_date" => $startDate,
                "end_date" => $endDate,
                "payment_terms" => $paymentTerms,
                "scope_of_work" => $scope,
                "renewal_terms" => $renewalTerms,
                "penalty_clause" => $penaltyClause,
            ]);
        }
        if ($duplicateRow !== null) {
            audit_log_event("contract_duplicate_blocked", [
                "duplicate_of" => (string)($duplicateRow["id"] ?? ""),
                "reason" => $duplicateReason,
                "title" => $contractTitle,
            ], $userId);
            $rejectSubmit(contract_duplicate_message($duplicateRow, $duplicateReason), 409, [
                "duplicate" => true,
                "reason" => $duplicateReason,
                "duplicate_of" => contract_duplicate_summary($duplicateRow),
            ]);
        }
    }

    $srcFile = trim((string)($input["source_file_name"] ?? ""));
    $storedBaseName = $srcFile !== "" ? basename($srcFile) : "contract-upload.pdf";
    // Many Supabase schemas mark file_name / file_path NOT NULL even when files are not stored server-side yet.
    $storedPath = "/vendor-uploads/pending/" . preg_replace('/[^a-zA-Z0-9._-]/', "_", $storedBaseName);

    $submissionText = pack_submission_text($submissionText, array_merge($nlp, [
        "renewal_terms" => $renewalTerms !== "" ? $renewalTerms : ($nlp["renewal_terms"] ?? null),
        "penalty_clause" => $penaltyClause !== "" ? $penaltyClause : ($nlp["penalty_clause"] ?? null),
        "financial_obligations" => $financialObligations !== "" ? $financialObligations : ($nlp["financial_obligations"] ?? null),
        "classification" => $classification !== "" ? $classification : ($nlp["classification"] ?? null),
        "payment_terms" => $orNull($paymentTerms),
        "start_date" => $orNull($startDate),
        "end_date" => $endDate,
        "client_name" => $orNull($sent("client_name")),
        "vendor_name" => $orNull($vendorName),
        "vendor_address" => $orNull($sent("vendor_address")),
        "currency" => $currency,
        "termination_clause" => $orNull($terminationClause),
        "client_signatory" => $orNull($sent("client_signatory")),
        "vendor_signatory" => $orNull($sent("vendor_signatory")),
        "signed_date" => $orNull($sent("signed_date")),
    ]));

    $payload = [[
        "vendor_id" => $userId,
        "vendor_name" => $vendorName,
        "contract_title" => $contractTitle,
        "contract_type" => $contractType,
        "contract_value" => $contractValue,
        "start_date" => $orNull($startDate),
        "end_date" => $endDate,
        "payment_terms" => $orNull($paymentTerms),
        "scope_of_work" => $orNull($scope),
        "status" => "pending",
        "uploaded_at" => gmdate("c"),
        "source_file_name" => $srcFile,
        "file_name" => $storedBaseName,
        "file_path" => $storedPath,
        "currency" => $currency,
        "renewal_terms" => $orNull($renewalTerms),
        "penalty_clause" => $orNull($penaltyClause),
        "financial_obligations" => $orNull($financialObligations),
        "classification" => $orNull($classification),
    ]];
    if ($submissionText !== "") {
        $payload[0]["submission_text"] = $submissionText;
    }

    // Older databases may lack the optional columns: drop the ones the error names, then one at a time.
    $optionalKeys = ["renewal_terms", "penalty_clause", "financial_obligations", "classification", "submission_text"];
    $submittedOptionalKeys = array_keys($payload[0]);
    $result = supabase_request("POST", "vendor_contracts", $payload);
    if (!$result["ok"]) {
        $rawErr = (string)($result["raw"] ?? "");
        $stripped = false;
        foreach ($optionalKeys as $key) {
            if (isset($payload[0][$key]) && $rawErr !== "" && (stripos($rawErr, $key) !== false || stripos($rawErr, "schema cache") !== false || stripos($rawErr, "column") !== false)) {
                unset($payload[0][$key]);
                $stripped = true;
            }
        }
        if ($stripped) {
            $result = supabase_request("POST", "vendor_contracts", $payload);
        }
        foreach (["submission_text", "renewal_terms", "penalty_clause", "financial_obligations", "classification"] as $key) {
            if (!$result["ok"] && isset($payload[0][$key])) {
                unset($payload[0][$key]);
                $result = supabase_request("POST", "vendor_contracts", $payload);
            }
        }
    }
    $droppedNonEmpty = array_values(array_intersect(array_diff($optionalKeys, array_keys($payload[0])), $submittedOptionalKeys));
    if ($droppedNonEmpty !== []) {
        // Older database schema: the insert only succeeded after removing these columns. Run sql/schema_phase1.sql.
        error_log("contract create: saved without columns missing from the database: " . implode(",", $droppedNonEmpty));
    }

    if (!$result["ok"] || !is_array($result["data"]) || !isset($result["data"][0])) {
        $rejectSubmit(contrack_upstream_error($result, "Supabase request failed"), (int)$result["status"]);
    }

    $row = $result["data"][0];
    $newId = (string)($row["id"] ?? "");
    if ($newId !== "") {
        $savedPdf = false;
        if ($pdfTmpPath !== null && is_uploaded_file($pdfTmpPath)) {
            $bin = @file_get_contents($pdfTmpPath);
            if ($bin !== false && substr($bin, 0, 4) === "%PDF") {
                $savedPdf = file_put_contents(contract_pdf_absolute_path($newId), $bin, LOCK_EX) !== false;
            }
        } elseif ($pdfBase64 !== "") {
            $savedPdf = contract_pdf_save_base64($newId, $pdfBase64);
        }
        if ($savedPdf) {
            supabase_request("PATCH", "vendor_contracts?id=eq." . urlencode($newId), ["original_pdf_stored" => true]);
            $row["original_pdf_stored"] = true;
        }
    }
    audit_log_event("contract_submitted", [
        "contract_id" => (string)($row["id"] ?? ""),
        "vendor_id" => (string)($row["vendor_id"] ?? ""),
        "title" => $contractTitle,
    ], $userId);
    notify_role_users("manager", "New contract submitted", "{$contractTitle} from {$vendorName} is waiting in the manager review queue.", true);
    notify_role_users("admin", "New contract submitted", "{$contractTitle} from {$vendorName} was submitted by a vendor.");
    create_user_alert($userId, "Contract submitted", "{$contractTitle} was received and is now awaiting manager review.", "contract", true);
    nlp_usage_from_extract("submit", $nlp, $nlpStarted, array_merge($nlpUsageCtx, [
        "contract_id" => (string)($row["id"] ?? ""),
        "source_file_name" => $srcFile !== "" ? basename($srcFile) : $storedBaseName,
    ]));
    json_response($row, (int)$result["status"]);
}

if ($view === "decision") {
    require_roles(["ceo"]);
    require_method("POST");
    $ceoId = get_session_user()["id"] ?? $userId;
    if ($ceoId === null || (int)$ceoId <= 0) {
        json_error("Active session required", 401);
    }
    $input = json_input();
    if ($input === null || empty($input["id"]) || empty($input["status"])) {
        json_error("id and status are required");
    }
    $status = strtolower((string)$input["status"]);
    if (!in_array($status, ["approved", "rejected", "terminated", "renegotiation"], true)) {
        json_error("Invalid decision status");
    }

    $contractId = urlencode((string)$input["id"]);
    $current = supabase_request("GET", "vendor_contracts?id=eq.{$contractId}&select=id,status,manager_reviewed_at&limit=1");
    $currentRow = ($current["ok"] && is_array($current["data"][0] ?? null)) ? $current["data"][0] : json_error("Contract not found", 404);
    $currentStatus = strtolower((string)($currentRow["status"] ?? ""));
    // Approve/reject belongs to the pending queue; terminate/renegotiate may also act on a live contract.
    $decidableFrom = in_array($status, ["terminated", "renegotiation"], true) ? ["pending", "approved"] : ["pending"];
    if (!in_array($currentStatus, $decidableFrom, true)) {
        json_error("This contract is already marked {$currentStatus} and cannot be decided again.", 409);
    }
    if (in_array($status, ["approved", "rejected"], true) && empty($currentRow["manager_reviewed_at"])) {
        json_error("A manager must review this contract before a CEO decision.", 409);
    }

    $ceoNotesRaw = $input["ceo_notes"] ?? null;
    $ceoNotes = is_scalar($ceoNotesRaw) ? substr(trim((string)$ceoNotesRaw), 0, 4000) : "";
    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}&status=in.(" . implode(",", $decidableFrom) . ")", [
        "status" => $status,
        "ceo_notes" => $ceoNotes === "" ? null : $ceoNotes,
        "reviewed_at" => gmdate("c"),
        "reviewed_by" => "ceo_user:" . (string)(int)$ceoId,
    ]);
    if ($result["ok"]) {
        audit_log_event("contract_decision", ["contract_id" => (string)$input["id"], "decision" => $status], (int)$ceoId);
        $decided = is_array($result["data"][0] ?? null) ? $result["data"][0] : [];
        $title = (string)($decided["contract_title"] ?? "Contract");
        create_user_alert((int)($decided["vendor_id"] ?? 0), "CEO decision: " . strtoupper($status), "{$title} was marked {$status} by the CEO.", "contract", true);
        create_user_alert((int)($decided["manager_reviewed_by"] ?? 0), "CEO decided on a contract you reviewed", "{$title} was marked {$status}.", "contract", true);
    }
    json_result($result, "Supabase request failed", ["ok" => true]);
}

json_error("Unsupported view");
