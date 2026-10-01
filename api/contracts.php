<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_contract_pdf.php";
require_once __DIR__ . "/lib_contract_intel.php";
require_once __DIR__ . "/lib_python_nlp.php";
require_once __DIR__ . "/lib_vendor_score.php";

$view = $_GET["view"] ?? "stats";
require_session();
$role = normalize_role(get_request_role());
$userId = get_request_user_id();

if ($view === "nlp_extract") {
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }

    $text = "";
    $pdfTmp = null;
    $contentType = (string)($_SERVER["CONTENT_TYPE"] ?? "");
    if (stripos($contentType, "multipart/form-data") !== false) {
        $text = trim((string)($_POST["contract_text"] ?? ""));
        $err = (int)($_FILES["contract_pdf"]["error"] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_OK) {
            $name = strtolower((string)($_FILES["contract_pdf"]["name"] ?? ""));
            $tmp = (string)($_FILES["contract_pdf"]["tmp_name"] ?? "");
            $head = ($tmp !== "" && is_file($tmp)) ? (string)@file_get_contents($tmp, false, null, 0, 5) : "";
            $isPdf = str_ends_with($name, ".pdf") || str_starts_with($head, "%PDF");
            $uploadSize = (int)($_FILES["contract_pdf"]["size"] ?? 0);
            if ($isPdf && $tmp !== "" && is_uploaded_file($tmp) && $uploadSize > 0 && $uploadSize <= 26 * 1024 * 1024 && ($err === UPLOAD_ERR_OK)) {
                $pdfTmp = $tmp;
            }
        }
    } else {
        $input = json_decode(file_get_contents("php://input"), true);
        $text = trim((string)($input["contract_text"] ?? ""));
    }

    $nlpStarted = microtime(true);
    $extracted = python_nlp_extract($text, $pdfTmp);
    if ($extracted === null) {
        if ($pdfTmp !== null && $text === "") {
            $text = "";
        }
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
    $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
    $payload = json_encode(["extracted" => $extracted], $flags);
    if ($payload === false) {
        echo json_encode(["error" => "Could not encode extraction result"]);
        exit;
    }
    echo $payload;
    exit;
}

if ($view === "performance") {
    if ($role === "vendor" && $userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Vendor/client requests require user_id"]);
        exit;
    }
    $path = "vendor_contracts?select=id,status,contract_value,uploaded_at,contract_title,start_date,end_date,payment_terms,submission_text,scope_of_work,classification,renewal_terms,penalty_clause,financial_obligations";
    if ($role === "vendor") {
        $path .= "&vendor_id=eq." . $userId;
    }
    $result = supabase_request("GET", $path);
    if (!$result["ok"]) {
        // Older schemas may lack submission_text / NLP columns — retry with a minimal select.
        $fallback = "vendor_contracts?select=id,status,contract_value,uploaded_at,contract_title,start_date,end_date,payment_terms";
        if ($role === "vendor") {
            $fallback .= "&vendor_id=eq." . $userId;
        }
        $result = supabase_request("GET", $fallback);
    }
    if (!$result["ok"]) {
        http_response_code($result["status"]);
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
        exit;
    }
    $rows = is_array($result["data"]) ? $result["data"] : [];
    $metrics = build_vendor_metrics($rows);

    $evalVendorId = $role === "vendor" ? $userId : null;
    $latestEval = null;
    $evalHistory = [];
    $breakdown = [];
    $criteriaView = vendor_score_criteria_view([]);
    if ($evalVendorId !== null) {
        $evals = supabase_request(
            "GET",
            "vendor_evaluations?vendor_id=eq.{$evalVendorId}&select=id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at&order=evaluated_at.desc&limit=24"
        );
        if ($evals["ok"] && is_array($evals["data"])) {
            $attached = vendor_score_attach_people($evals["data"]);
            foreach ($attached as $ev) {
                $evalHistory[] = [
                    "id" => $ev["id"] ?? null,
                    "evaluated_at" => $ev["evaluated_at"] ?? null,
                    "overall_score" => (float)($ev["overall_score"] ?? 0),
                    "star_rating" => vendor_star_rating((float)($ev["overall_score"] ?? 0)),
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

    $history = [];
    foreach (enrich_contract_rows($rows) as $row) {
        $history[] = [
            "id" => $row["id"] ?? null,
            "contract_title" => $row["contract_title"] ?? "",
            "start_date" => $row["start_date"] ?? null,
            "end_date" => $row["end_date"] ?? null,
            "contract_value" => $row["contract_value"] ?? 0,
            "status" => $row["status"] ?? "",
            "monitor_status" => $row["monitor_status"] ?? "",
            "risk" => $row["risk"] ?? "low",
        ];
    }

    $overall = $latestEval !== null ? (float)$latestEval["overall_score"] : 0.0;
    $rating = $latestEval !== null ? (string)$latestEval["rating"] : null;
    echo json_encode([
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
    exit;
}

if ($view === "stats") {
    if ($role === "vendor" && $userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Vendor/client requests require user_id"]);
        exit;
    }

    $path = "vendor_contracts?select=id,status,contract_value,end_date,uploaded_at";
    if ($role === "vendor") {
        $path .= "&vendor_id=eq." . $userId;
    }
    $result = supabase_request("GET", $path);

    if (!$result["ok"]) {
        http_response_code($result["status"]);
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
        exit;
    }

    $rows = is_array($result["data"]) ? $result["data"] : [];
    $today = new DateTimeImmutable("today");
    $todayPlus30 = $today->add(new DateInterval("P30D"));

    $pending = 0;
    $rejected = 0;
    $approvedStatusTotal = 0;
    $activeContracts = 0;
    $expiredApproved = 0;
    $expiringApproved30 = 0;
    $renegotiation = 0;
    $terminated = 0;

    $totalValueAll = 0.0;
    $totalValueActive = 0.0;
    $valuePending = 0.0;
    $valueRejected = 0.0;
    $valueExpiring = 0.0;

    foreach ($rows as $row) {
        $status = strtolower((string)($row["status"] ?? ""));
        $value = (float)($row["contract_value"] ?? 0);
        $totalValueAll += $value;

        if ($status === "pending") {
            $pending++;
            $valuePending += $value;
            continue;
        }
        if ($status === "rejected") {
            $rejected++;
            $valueRejected += $value;
            continue;
        }
        if ($status === "renegotiation") {
            $renegotiation++;
            continue;
        }
        if ($status === "terminated") {
            $terminated++;
            continue;
        }
        if ($status !== "approved") {
            continue;
        }

        $approvedStatusTotal++;
        $endRaw = $row["end_date"] ?? null;
        if ($endRaw === null || $endRaw === "") {
            $activeContracts++;
            $totalValueActive += $value;
            continue;
        }

        try {
            $endDate = new DateTimeImmutable((string)$endRaw);
            $endDay = $endDate->setTime(0, 0, 0);
            if ($endDay < $today) {
                $expiredApproved++;
            } else {
                $activeContracts++;
                $totalValueActive += $value;
                if ($endDay <= $todayPlus30) {
                    $expiringApproved30++;
                    $valueExpiring += $value;
                }
            }
        } catch (Exception $exception) {
            $activeContracts++;
            $totalValueActive += $value;
        }
    }

    echo json_encode([
        "total_contracts" => count($rows),
        "approved" => $approvedStatusTotal,
        "active_contracts" => $activeContracts,
        "expired_approved" => $expiredApproved,
        "pending" => $pending,
        "rejected" => $rejected,
        "renegotiation" => $renegotiation,
        "terminated" => $terminated,
        "expiring_30_days" => $expiringApproved30,
        "total_contract_value" => round($totalValueAll, 2),
        "total_contract_value_active" => round($totalValueActive, 2),
        "value_pending" => round($valuePending, 2),
        "value_rejected" => round($valueRejected, 2),
        "value_expiring" => round($valueExpiring, 2),
        "archived_contracts" => $expiredApproved + $terminated + $rejected,
        "renewal_due" => $expiringApproved30 + $renegotiation,
        "role_scope" => $role,
    ]);
    exit;
}

if ($view === "vendor_summary") {
    require_roles(["ceo", "manager", "admin"]);
    $cRes = supabase_request(
        "GET",
        "vendor_contracts?select=vendor_id,vendor_name,contract_type,contract_value,status,end_date"
    );
    $uRes = supabase_request(
        "GET",
        "users?role=eq.vendor&select=id,name,company_name,email,role,created_at&order=name.asc"
    );
    if (!$cRes["ok"]) {
        http_response_code($cRes["status"]);
        echo json_encode(["error" => contrack_upstream_error($cRes, "Supabase request failed")]);
        exit;
    }

    $contractRows = is_array($cRes["data"]) ? $cRes["data"] : [];
    $userRows = ($uRes["ok"] && is_array($uRes["data"])) ? $uRes["data"] : [];

    $userById = [];
    foreach ($userRows as $u) {
        $uid = (int)($u["id"] ?? 0);
        if ($uid > 0) {
            $userById[$uid] = $u;
        }
    }

    $agg = [];
    foreach ($contractRows as $row) {
        $vid = (int)($row["vendor_id"] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        if (!isset($agg[$vid])) {
            $agg[$vid] = [
                "vendor_id" => $vid,
                "vendor_name" => trim((string)($row["vendor_name"] ?? "")),
                "contracts" => [],
            ];
        }
        $agg[$vid]["contracts"][] = $row;
        $vn = trim((string)($row["vendor_name"] ?? ""));
        if ($vn !== "" && strlen($vn) > strlen((string)$agg[$vid]["vendor_name"])) {
            $agg[$vid]["vendor_name"] = $vn;
        }
    }

    foreach ($userById as $vid => $u) {
        if (!isset($agg[$vid])) {
            $agg[$vid] = [
                "vendor_id" => $vid,
                "vendor_name" => contrack_public_name($u),
                "contracts" => [],
            ];
        }
    }

    foreach ($agg as $vid => &$bundle) {
        if (isset($userById[$vid])) {
            $u = $userById[$vid];
            $bundle["vendor_email"] = (string)($u["email"] ?? "");
            $display = contrack_public_name($u);
            if ($display !== "" && $display !== "Vendor") {
                $bundle["vendor_name"] = $display;
            } elseif ($bundle["vendor_name"] === "") {
                $bundle["vendor_name"] = $display;
            }
        } else {
            $bundle["vendor_email"] = $bundle["vendor_email"] ?? "";
        }
        if ($bundle["vendor_name"] === "") {
            $bundle["vendor_name"] = "Vendor #" . (string)$vid;
        }
    }
    unset($bundle);

    $latestScores = vendor_score_latest_by_vendor();
    $out = [];
    foreach ($agg as $vid => $bundle) {
        $contracts = $bundle["contracts"];
        $total = count($contracts);
        $sumVal = 0.0;
        $approved = 0;
        $pend = 0;
        $rej = 0;
        $typeCounts = [];
        $maxVal = 0.0;
        foreach ($contracts as $c) {
            $v = (float)($c["contract_value"] ?? 0);
            $sumVal += $v;
            $maxVal = max($maxVal, $v);
            $st = strtolower((string)($c["status"] ?? ""));
            if ($st === "approved") {
                $approved++;
            } elseif ($st === "pending") {
                $pend++;
            } elseif ($st === "rejected") {
                $rej++;
            }
            $ct = trim((string)($c["contract_type"] ?? ""));
            if ($ct !== "") {
                $typeCounts[$ct] = ($typeCounts[$ct] ?? 0) + 1;
            }
        }
        $primaryType = "—";
        if ($typeCounts !== []) {
            arsort($typeCounts);
            $primaryType = (string)array_key_first($typeCounts);
        }
        $metrics = build_vendor_metrics($contracts);
        $scoreRow = $latestScores[$vid] ?? null;
        $overall = $scoreRow !== null ? (float)$scoreRow["overall_score"] : null;
        $rating = $scoreRow !== null ? (string)$scoreRow["rating"] : null;

        $out[] = [
            "vendor_id" => $vid,
            "vendor_name" => $bundle["vendor_name"],
            "vendor_email" => $bundle["vendor_email"] ?? "",
            "contract_type" => $primaryType,
            "contract_count" => $total,
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
            "approved_count" => $approved,
            "pending_count" => $pend,
            "rejected_count" => $rej,
        ];
    }

    usort(
        $out,
        static function (array $a, array $b): int {
            $cmp = ($b["total_value"] <=> $a["total_value"]);
            return $cmp !== 0 ? $cmp : strcmp((string)$a["vendor_name"], (string)$b["vendor_name"]);
        }
    );

    echo json_encode($out);
    exit;
}

if ($view === "pending_approvals") {
    require_roles(["ceo", "manager", "admin"]);
    // CEO queue = pending contracts already reviewed by a manager (system flow).
    // Managers/admins may still list all pending when opening the same view.
    $ceoPath = "vendor_contracts?status=eq.pending&manager_reviewed_at=not.is.null&select=id,contract_code,vendor_id,vendor_name,contract_title,contract_type,contract_value,end_date,status,uploaded_at,reviewed_at,reviewed_by,scope_of_work,payment_terms,manager_notes,recommended_action,manager_reviewed_at,manager_reviewed_by&order=manager_reviewed_at.desc";
    $allPendingPath = "vendor_contracts?status=eq.pending&select=id,contract_code,vendor_id,vendor_name,contract_title,contract_type,contract_value,end_date,status,uploaded_at,reviewed_at,reviewed_by,scope_of_work,payment_terms,manager_notes,recommended_action,manager_reviewed_at,manager_reviewed_by&order=uploaded_at.desc";
    $result = supabase_request("GET", $role === "ceo" ? $ceoPath : $allPendingPath);
    http_response_code($result["status"]);
    if ($result["ok"]) {
        echo json_encode(enrich_contract_rows(is_array($result["data"]) ? $result["data"] : []));
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "manager_pending") {
    require_roles(["manager", "admin"]);
    if ($userId === null && $role !== "admin") {
        http_response_code(400);
        echo json_encode(["error" => "Session user id required"]);
        exit;
    }
    // Pending contracts not yet reviewed by a manager (queue).
    $path = "vendor_contracts?status=eq.pending&manager_reviewed_at=is.null&select=*&order=uploaded_at.desc";
    $result = supabase_request("GET", $path);
    http_response_code($result["status"]);
    if ($result["ok"]) {
        echo json_encode(enrich_contract_rows(is_array($result["data"]) ? $result["data"] : []));
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "manager_mine") {
    require_roles(["manager", "admin"]);
    if ($userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Session user id required"]);
        exit;
    }
    $path = "vendor_contracts?manager_reviewed_by=eq." . $userId . "&select=*&order=manager_reviewed_at.desc";
    $result = supabase_request("GET", $path);
    http_response_code($result["status"]);
    if ($result["ok"]) {
        echo json_encode(enrich_contract_rows(is_array($result["data"]) ? $result["data"] : []));
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "list") {
    if ($role === "vendor" && $userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Vendor/client requests require user_id"]);
        exit;
    }
    $path = "vendor_contracts?select=*&order=uploaded_at.desc";
    if ($role === "vendor") {
        $path .= "&vendor_id=eq." . $userId;
    }
    $result = supabase_request("GET", $path);
    http_response_code($result["status"]);
    if ($result["ok"]) {
        $rows = enrich_contract_rows(is_array($result["data"]) ? $result["data"] : []);
        $filter = strtolower(trim((string)($_GET["filter"] ?? "")));
        if ($filter === "archived") {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => !empty($r["is_archived"])));
        } elseif ($filter === "renewals") {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => !empty($r["renewal_due"])));
        } elseif ($filter === "active") {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => empty($r["is_archived"])));
        }
        echo json_encode($rows);
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "renewals") {
    if ($role === "vendor" && $userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Vendor/client requests require user_id"]);
        exit;
    }
    $path = "vendor_contracts?select=*&order=end_date.asc";
    if ($role === "vendor") {
        $path .= "&vendor_id=eq." . $userId;
    }
    $result = supabase_request("GET", $path);
    if (!$result["ok"]) {
        http_response_code($result["status"]);
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
        exit;
    }
    $notices = [];
    foreach (enrich_contract_rows(is_array($result["data"]) ? $result["data"] : []) as $row) {
        $notice = renewal_notice_for_row($row);
        if ($notice !== null && empty($row["is_archived"])) {
            $notices[] = $notice;
        }
    }
    echo json_encode($notices);
    exit;
}

if ($view === "archive") {
    require_roles(["ceo", "manager", "admin"]);
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input) || empty($input["id"])) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }
    $contractId = urlencode((string)$input["id"]);
    $fetch = supabase_request("GET", "vendor_contracts?id=eq.{$contractId}&select=*&limit=1");
    $row = ($fetch["ok"] && is_array($fetch["data"]) && isset($fetch["data"][0])) ? enrich_contract_row($fetch["data"][0]) : null;
    if ($row === null) {
        http_response_code(404);
        echo json_encode(["error" => "Contract not found"]);
        exit;
    }
    if (!empty($row["is_archived"])) {
        echo json_encode(["ok" => true, "id" => $input["id"], "is_archived" => true, "status" => $row["status"] ?? null]);
        exit;
    }
    if (!in_array(strtolower((string)($row["status"] ?? "")), ["rejected", "terminated"], true) && ($row["monitor_status"] ?? "") !== "expired") {
        http_response_code(400);
        echo json_encode(["error" => "Only inactive (expired, rejected, or terminated) contracts can be archived"]);
        exit;
    }
    // status is constrained to pending/approved/rejected/terminated/renegotiation — archiving is the is_archived flag.
    $payload = ["is_archived" => true, "archived_at" => gmdate("c")];
    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}", $payload);
    if (!$result["ok"]) {
        http_response_code($result["status"]);
        echo json_encode(["error" => contrack_upstream_error($result, "Failed to archive")]);
        exit;
    }
    audit_log_event("contract_archived", ["contract_id" => (string)$input["id"]], $userId);
    echo json_encode(["ok" => true, "id" => $input["id"], "is_archived" => true, "status" => $row["status"] ?? null]);
    exit;
}

if ($view === "manager_review") {
    require_roles(["manager", "admin"]);
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }
    if ($userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Session user id required"]);
        exit;
    }
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input) || empty($input["id"])) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }
    $contractId = urlencode((string)$input["id"]);
    $notes = trim((string)($input["manager_notes"] ?? ""));
    $action = trim((string)($input["recommended_action"] ?? ""));
    $allowedActions = ["escalate_to_ceo", "reject", "modification"];
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(400);
        echo json_encode(["error" => "Choose approve, reject, or modify"]);
        exit;
    }
    if ($action !== "escalate_to_ceo" && $notes === "") {
        http_response_code(400);
        echo json_encode(["error" => "Add manager notes before rejecting or requesting a modification"]);
        exit;
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
        http_response_code(409);
        echo json_encode(["error" => "This contract is no longer pending review."]);
        exit;
    }
    http_response_code($result["status"]);
    if ($result["ok"]) {
        $reviewedRow = is_array($result["data"][0] ?? null) ? $result["data"][0] : [];
        if ($reviewedRow === []) {
            $lookup = supabase_request(
                "GET",
                "vendor_contracts?id=eq.{$contractId}&select=id,vendor_id,vendor_name,contract_title,status,manager_reviewed_by&limit=1"
            );
            if ($lookup["ok"] && is_array($lookup["data"][0] ?? null)) {
                $reviewedRow = $lookup["data"][0];
            }
        }
        audit_log_event(
            "contract_manager_review",
            [
                "contract_id" => (string)$input["id"],
                "recommended_action" => $action,
            ],
            $userId
        );
        $title = (string)($reviewedRow["contract_title"] ?? "Contract");
        $vendorLabel = (string)($reviewedRow["vendor_name"] ?? "Vendor");
        $code = trim((string)($reviewedRow["contract_code"] ?? ""));
        $codeLabel = $code !== "" ? "{$code} · " : "";
        $vendorOwner = isset($reviewedRow["vendor_id"]) ? (int)$reviewedRow["vendor_id"] : 0;
        if ($action === "modification") {
            if ($vendorOwner > 0) {
                create_user_alert(
                    $vendorOwner,
                    "Changes requested",
                    "{$codeLabel}{$title} needs modification. Update it and resubmit for review."
                );
            }
        } else {
            $actionLabel = $action === "reject" ? "recommend rejection" : "recommend approval";
            notify_role_users(
                "ceo",
                "Ready for CEO decision",
                "{$codeLabel}{$title} from {$vendorLabel} was reviewed by a manager ({$actionLabel}). Open Approvals to decide."
            );
            if ($vendorOwner > 0) {
                create_user_alert(
                    $vendorOwner,
                    "Manager reviewed your contract",
                    "{$codeLabel}{$title} is awaiting CEO decision."
                );
            }
        }
        echo json_encode($result["data"] ?? ["ok" => true]);
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "vendor_resubmit") {
    require_roles(["vendor"]);
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }
    if ($userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Session user id required"]);
        exit;
    }
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input) || empty($input["id"])) {
        http_response_code(400);
        echo json_encode(["error" => "id is required"]);
        exit;
    }
    $contractId = urlencode((string)$input["id"]);
    $fetch = supabase_request(
        "GET",
        "vendor_contracts?id=eq.{$contractId}&select=id,vendor_id,status,contract_title,contract_code&limit=1"
    );
    $row = ($fetch["ok"] && is_array($fetch["data"][0] ?? null)) ? $fetch["data"][0] : null;
    if ($row === null) {
        http_response_code(404);
        echo json_encode(["error" => "Contract not found"]);
        exit;
    }
    if ((int)($row["vendor_id"] ?? 0) !== $userId) {
        http_response_code(403);
        echo json_encode(["error" => "You can only resubmit your own contract"]);
        exit;
    }
    if (strtolower((string)($row["status"] ?? "")) !== "modification") {
        http_response_code(400);
        echo json_encode(["error" => "Only contracts sent back for modification can be resubmitted"]);
        exit;
    }

    $result = supabase_request("PATCH", "vendor_contracts?id=eq.{$contractId}&status=eq.modification", [
        "status" => "pending",
        "manager_reviewed_at" => null,
        "manager_reviewed_by" => null,
        "recommended_action" => null,
    ]);
    if (!$result["ok"]) {
        http_response_code($result["status"] ?: 500);
        echo json_encode(["error" => contrack_upstream_error($result, "Failed to resubmit")]);
        exit;
    }
    $title = (string)($row["contract_title"] ?? "Contract");
    $code = trim((string)($row["contract_code"] ?? ""));
    $codeLabel = $code !== "" ? "{$code} · " : "";
    notify_role_users(
        "manager",
        "Contract resubmitted",
        "{$codeLabel}{$title} is back in the review queue."
    );
    audit_log_event("contract_resubmitted", ["contract_id" => (string)$input["id"], "contract_code" => $code], $userId);
    echo json_encode(["ok" => true, "id" => $input["id"], "status" => "pending"]);
    exit;
}

if ($view === "create") {
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }
    if ($role === "vendor" && $userId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Vendor/client requests require user_id"]);
        exit;
    }

    $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
    $isMultipart = stripos($contentType, "multipart/form-data") !== false;
    $pdfTmpPath = null;
    if ($isMultipart) {
        $input = $_POST;
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid multipart body"]);
            exit;
        }
        $uploadErr = (int)($_FILES["contract_pdf"]["error"] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
            $origName = strtolower((string)($_FILES["contract_pdf"]["name"] ?? ""));
            if (preg_match("/\\.(doc|docx|xls|xlsx|txt|rtf)$/", $origName)) {
                http_response_code(400);
                echo json_encode(["error" => "Only PDF files are accepted. Word and other document types are not allowed."]);
                exit;
            }
            if ($uploadErr !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(["error" => "PDF upload failed. Please try again with a PDF file."]);
                exit;
            }
            $tmp = (string)($_FILES["contract_pdf"]["tmp_name"] ?? "");
            $size = (int)($_FILES["contract_pdf"]["size"] ?? 0);
            $maxBytes = 26 * 1024 * 1024;
            if ($tmp !== "" && is_uploaded_file($tmp) && $size > 0 && $size <= $maxBytes) {
                $head = @file_get_contents($tmp, false, null, 0, 5);
                if (is_string($head) && strlen($head) >= 4 && substr($head, 0, 4) === "%PDF") {
                    $pdfTmpPath = $tmp;
                }
            }
            if ($pdfTmpPath === null) {
                http_response_code(400);
                echo json_encode(["error" => "Only PDF files are accepted."]);
                exit;
            }
        }
        $pdfBase64 = "";
    } else {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid request body"]);
            exit;
        }
        $pdfBase64 = trim((string)($input["pdf_base64"] ?? ""));
        // 26 MB of PDF is about 34.7 MB of base64; refuse anything larger before decoding or running NLP.
        if (strlen($pdfBase64) > 36 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(["error" => "PDF is too large (26 MB maximum)."]);
            exit;
        }
    }

    $contractTextRaw = trim((string)($input["contract_text"] ?? ""));
    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        $submissionText = mb_strlen($contractTextRaw) > 500000 ? mb_substr($contractTextRaw, 0, 500000) : $contractTextRaw;
    } else {
        $submissionText = strlen($contractTextRaw) > 500000 ? substr($contractTextRaw, 0, 500000) : $contractTextRaw;
    }

    $nlpStarted = microtime(true);
    $nlp = python_nlp_extract($contractTextRaw, $pdfTmpPath);
    if (!is_array($nlp)) {
        $nlp = extract_contract_fields($contractTextRaw);
        $nlp["engine"] = "php-regex";
    }
    $nlpDurationMs = (int)round((microtime(true) - $nlpStarted) * 1000);
    $nlpUsageCtx = [
        "user_id" => $userId,
        "duration_ms" => $nlpDurationMs,
        "source_file_name" => trim((string)($input["source_file_name"] ?? "")),
        "input_kind" => nlp_input_kind($pdfTmpPath, $contractTextRaw),
        "input_chars" => strlen($contractTextRaw),
    ];

    $contractTitle = trim((string)($input["contract_title"] ?? ""));
    if ($contractTitle === "") {
        $contractTitle = trim((string)($nlp["contract_title"] ?? ""));
    }
    $contractType = trim((string)($input["contract_type"] ?? ""));
    if ($contractType === "") {
        $contractType = trim((string)($nlp["contract_type"] ?? ""));
    }
    $contractValue = isset($input["contract_value"]) ? (float)$input["contract_value"] : 0.0;
    if ($contractValue <= 0 && isset($nlp["contract_value"])) {
        $contractValue = (float)$nlp["contract_value"];
    }
    $startDate = trim((string)($input["start_date"] ?? ""));
    if ($startDate === "") {
        $startDate = trim((string)($nlp["start_date"] ?? ""));
    }
    $endDate = trim((string)($input["end_date"] ?? ""));
    if ($endDate === "") {
        $endDate = trim((string)($nlp["end_date"] ?? ""));
    }
    $paymentTerms = format_payment_terms_duration(trim((string)($input["payment_terms"] ?? "")));
    if ($paymentTerms === "") {
        $paymentTerms = format_payment_terms_duration(trim((string)($nlp["payment_terms"] ?? "")));
    }
    $scope = trim((string)($input["scope"] ?? ""));
    if ($scope === "") {
        $scope = trim((string)($nlp["scope"] ?? ""));
    }
    $renewalTerms = trim((string)($input["renewal_terms"] ?? ""));
    if ($renewalTerms === "") {
        $renewalTerms = trim((string)($nlp["renewal_terms"] ?? ""));
    }
    $penaltyClause = trim((string)($input["penalty_clause"] ?? ""));
    if ($penaltyClause === "") {
        $penaltyClause = trim((string)($nlp["penalty_clause"] ?? ""));
    }
    $financialObligations = trim((string)($input["financial_obligations"] ?? ""));
    if ($financialObligations === "") {
        $financialObligations = trim((string)($nlp["financial_obligations"] ?? ""));
    }
    $classification = trim((string)($input["classification"] ?? ($nlp["classification"] ?? "")));
    $vendorName = trim((string)($input["vendor_name"] ?? ""));
    if ($vendorName === "") {
        $sessionUser = get_session_user() ?? [];
        $vendorName = trim((string)($nlp["vendor_name"] ?? contrack_public_name($sessionUser)));
    }
    $currency = strtoupper(trim((string)($input["currency"] ?? "")));
    if ($currency === "") {
        $currency = strtoupper(trim((string)($nlp["currency"] ?? "PHP")));
    }
    if (preg_match('/\b(PHP|USD|EUR)\b/', $currency, $cm)) {
        $currency = strtoupper($cm[1]);
    } else {
        $currency = "PHP";
    }
    $terminationClause = trim((string)($input["termination_clause"] ?? ""));
    if ($terminationClause === "") {
        $terminationClause = trim((string)($nlp["termination_clause"] ?? ""));
    }
    $clientName = trim((string)($input["client_name"] ?? ($nlp["client_name"] ?? "")));
    $vendorAddress = trim((string)($input["vendor_address"] ?? ($nlp["vendor_address"] ?? "")));
    $clientSignatory = trim((string)($input["client_signatory"] ?? ($nlp["client_signatory"] ?? "")));
    $vendorSignatory = trim((string)($input["vendor_signatory"] ?? ($nlp["vendor_signatory"] ?? "")));
    $signedDate = trim((string)($input["signed_date"] ?? ($nlp["signed_date"] ?? "")));

    if ($contractTitle === "" || $contractType === "" || $contractValue <= 0 || $endDate === "") {
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, $nlpUsageCtx);
        http_response_code(400);
        echo json_encode(["error" => "contract_title, contract_type, contract_value, and end_date are required"]);
        exit;
    }

    $endTs = strtotime($endDate);
    $startTs = $startDate !== "" ? strtotime($startDate) : null;
    $inputError = null;
    if ($endTs === false) {
        $inputError = "end_date is not a valid date";
    } elseif ($startDate !== "" && $startTs === false) {
        $inputError = "start_date is not a valid date";
    } elseif ($startTs !== null && $startTs > $endTs) {
        $inputError = "start_date cannot be after end_date";
    } elseif ($contractValue > 1e13) {
        $inputError = "contract_value is too large";
    }
    if ($inputError !== null) {
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, $nlpUsageCtx);
        http_response_code(400);
        echo json_encode(["error" => $inputError]);
        exit;
    }

    $hasPdf = $pdfTmpPath !== null || $pdfBase64 !== "";
    if ($role === "vendor" && !$hasPdf) {
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, $nlpUsageCtx);
        http_response_code(400);
        echo json_encode(["error" => "A PDF file is required. Word documents are not accepted."]);
        exit;
    }

    $srcFile = trim((string)($input["source_file_name"] ?? ""));
    $storedBaseName = $srcFile !== "" ? basename($srcFile) : "contract-upload.pdf";
    // Many Supabase schemas mark file_name / file_path NOT NULL even when files are not stored server-side yet.
    $storedPath = "/vendor-uploads/pending/" . preg_replace('/[^a-zA-Z0-9._-]/', "_", $storedBaseName);

    $nlpPacked = array_merge($nlp, [
        "renewal_terms" => $renewalTerms !== "" ? $renewalTerms : ($nlp["renewal_terms"] ?? null),
        "penalty_clause" => $penaltyClause !== "" ? $penaltyClause : ($nlp["penalty_clause"] ?? null),
        "financial_obligations" => $financialObligations !== "" ? $financialObligations : ($nlp["financial_obligations"] ?? null),
        "classification" => $classification !== "" ? $classification : ($nlp["classification"] ?? null),
        "payment_terms" => $paymentTerms !== "" ? $paymentTerms : null,
        "start_date" => $startDate !== "" ? $startDate : null,
        "end_date" => $endDate,
        "client_name" => $clientName !== "" ? $clientName : null,
        "vendor_name" => $vendorName !== "" ? $vendorName : null,
        "vendor_address" => $vendorAddress !== "" ? $vendorAddress : null,
        "currency" => $currency,
        "termination_clause" => $terminationClause !== "" ? $terminationClause : null,
        "client_signatory" => $clientSignatory !== "" ? $clientSignatory : null,
        "vendor_signatory" => $vendorSignatory !== "" ? $vendorSignatory : null,
        "signed_date" => $signedDate !== "" ? $signedDate : null,
    ]);
    if ($submissionText !== "") {
        $submissionText = pack_submission_text($submissionText, $nlpPacked);
    } else {
        $submissionText = pack_submission_text("", $nlpPacked);
    }

    $payload = [[
        "vendor_id" => $userId,
        "vendor_name" => $vendorName,
        "contract_title" => $contractTitle,
        "contract_type" => $contractType,
        "contract_value" => $contractValue,
        "start_date" => $startDate !== "" ? $startDate : null,
        "end_date" => $endDate,
        "payment_terms" => $paymentTerms !== "" ? $paymentTerms : null,
        "scope_of_work" => $scope !== "" ? $scope : null,
        "status" => "pending",
        "uploaded_at" => gmdate("c"),
        "source_file_name" => $srcFile,
        "file_name" => $storedBaseName,
        "file_path" => $storedPath,
        "currency" => $currency,
        "renewal_terms" => $renewalTerms !== "" ? $renewalTerms : null,
        "penalty_clause" => $penaltyClause !== "" ? $penaltyClause : null,
        "financial_obligations" => $financialObligations !== "" ? $financialObligations : null,
        "classification" => $classification !== "" ? $classification : null,
    ]];
    if ($submissionText !== "") {
        $payload[0]["submission_text"] = $submissionText;
    }
    $submittedOptionalKeys = array_keys($payload[0]);
    $result = supabase_request("POST", "vendor_contracts", $payload);
    if (!$result["ok"]) {
        $rawErr = (string)($result["raw"] ?? "");
        $optionalKeys = ["renewal_terms", "penalty_clause", "financial_obligations", "classification", "submission_text"];
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
        if (!$result["ok"] && isset($payload[0]["submission_text"])) {
            unset($payload[0]["submission_text"]);
            $result = supabase_request("POST", "vendor_contracts", $payload);
        }
        foreach (["renewal_terms", "penalty_clause", "financial_obligations", "classification"] as $key) {
            if (!$result["ok"] && isset($payload[0][$key])) {
                unset($payload[0][$key]);
                $result = supabase_request("POST", "vendor_contracts", $payload);
            }
        }
    }
    $droppedColumns = array_values(array_diff(
        ["renewal_terms", "penalty_clause", "financial_obligations", "classification", "submission_text"],
        array_keys($payload[0])
    ));
    $droppedNonEmpty = array_values(array_filter($droppedColumns, static fn(string $k): bool => in_array($k, $submittedOptionalKeys, true)));
    if ($droppedNonEmpty !== []) {
        // Older database schema: the insert only succeeded after removing these columns. Run sql/schema_phase1.sql.
        error_log("contract create: saved without columns missing from the database: " . implode(",", $droppedNonEmpty));
    }
    http_response_code($result["status"]);
    if ($result["ok"] && is_array($result["data"]) && isset($result["data"][0])) {
        $row = $result["data"][0];
        $newId = (string)($row["id"] ?? "");
        $savedPdf = false;
        if ($newId !== "") {
            if ($pdfTmpPath !== null && is_uploaded_file($pdfTmpPath)) {
                $bin = @file_get_contents($pdfTmpPath);
                if ($bin !== false && substr($bin, 0, 4) === "%PDF") {
                    $savedPdf = file_put_contents(contract_pdf_absolute_path($newId), $bin, LOCK_EX) !== false;
                }
            } elseif ($pdfBase64 !== "") {
                $savedPdf = contract_pdf_save_base64($newId, $pdfBase64);
            }
            if ($savedPdf) {
                $idEnc = urlencode($newId);
                supabase_request("PATCH", "vendor_contracts?id=eq.{$idEnc}", ["original_pdf_stored" => true]);
                $row["original_pdf_stored"] = true;
            }
        }
        audit_log_event(
            "contract_submitted",
            [
                "contract_id" => (string)($row["id"] ?? ""),
                "vendor_id" => (string)($row["vendor_id"] ?? ""),
                "title" => $contractTitle,
            ],
            $userId
        );
        notify_role_users(
            "manager",
            "New contract submitted",
            "{$contractTitle} from {$vendorName} is waiting in the manager review queue."
        );
        notify_role_users(
            "admin",
            "New contract submitted",
            "{$contractTitle} from {$vendorName} was submitted by a vendor."
        );
        create_user_alert(
            $userId,
            "Contract submitted",
            "{$contractTitle} was received and is now awaiting manager review."
        );
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, array_merge($nlpUsageCtx, [
            "contract_id" => (string)($row["id"] ?? ""),
            "source_file_name" => $srcFile !== "" ? basename($srcFile) : $storedBaseName,
        ]));
        echo json_encode($row);
    } else {
        nlp_usage_from_extract("submit", $nlp, $nlpStarted, $nlpUsageCtx);
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

if ($view === "decision") {
    require_roles(["ceo"]);
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        exit;
    }

    $sessionUser = get_session_user();
    $ceoId = $sessionUser["id"] ?? $userId;
    if ($ceoId === null || (int)$ceoId <= 0) {
        http_response_code(401);
        echo json_encode(["error" => "Active session required"]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input) || empty($input["id"]) || empty($input["status"])) {
        http_response_code(400);
        echo json_encode(["error" => "id and status are required"]);
        exit;
    }

    $status = strtolower((string)$input["status"]);
    $allowedStatus = ["approved", "rejected", "terminated", "renegotiation"];
    if (!in_array($status, $allowedStatus, true)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid decision status"]);
        exit;
    }

    $contractId = urlencode((string)$input["id"]);
    $current = supabase_request(
        "GET",
        "vendor_contracts?id=eq.{$contractId}&select=id,status,manager_reviewed_at&limit=1"
    );
    if (!$current["ok"] || !is_array($current["data"][0] ?? null)) {
        http_response_code(404);
        echo json_encode(["error" => "Contract not found"]);
        exit;
    }
    $currentStatus = strtolower((string)($current["data"][0]["status"] ?? ""));
    // Approve/reject belongs to the pending queue; terminate/renegotiate may also act on a live contract.
    $decidableFrom = in_array($status, ["terminated", "renegotiation"], true)
        ? ["pending", "approved"]
        : ["pending"];
    if (!in_array($currentStatus, $decidableFrom, true)) {
        http_response_code(409);
        echo json_encode(["error" => "This contract is already marked {$currentStatus} and cannot be decided again."]);
        exit;
    }
    if (in_array($status, ["approved", "rejected"], true) && empty($current["data"][0]["manager_reviewed_at"])) {
        http_response_code(409);
        echo json_encode(["error" => "A manager must review this contract before a CEO decision."]);
        exit;
    }

    $ceoNotesRaw = $input["ceo_notes"] ?? null;
    $ceoNotes = is_scalar($ceoNotesRaw) ? substr(trim((string)$ceoNotesRaw), 0, 4000) : "";
    $ceoNotes = $ceoNotes === "" ? null : $ceoNotes;

    $reviewedBy = "ceo_user:" . (string)(int)$ceoId;
    $payload = [
        "status" => $status,
        "ceo_notes" => $ceoNotes,
        "reviewed_at" => gmdate("c"),
        "reviewed_by" => $reviewedBy,
    ];
    $result = supabase_request(
        "PATCH",
        "vendor_contracts?id=eq.{$contractId}&status=in.(" . implode(",", $decidableFrom) . ")",
        $payload
    );
    http_response_code($result["status"]);
    if ($result["ok"]) {
        audit_log_event(
            "contract_decision",
            [
                "contract_id" => (string)$input["id"],
                "decision" => $status,
            ],
            (int)$ceoId
        );
        $decided = is_array($result["data"][0] ?? null) ? $result["data"][0] : [];
        $title = (string)($decided["contract_title"] ?? "Contract");
        $vendorOwner = isset($decided["vendor_id"]) ? (int)$decided["vendor_id"] : 0;
        if ($vendorOwner > 0) {
            create_user_alert(
                $vendorOwner,
                "CEO decision: " . strtoupper($status),
                "{$title} was marked {$status} by the CEO."
            );
        }
        $managerId = isset($decided["manager_reviewed_by"]) ? (int)$decided["manager_reviewed_by"] : 0;
        if ($managerId > 0) {
            create_user_alert(
                $managerId,
                "CEO decided on a contract you reviewed",
                "{$title} was marked {$status}."
            );
        }
        echo json_encode($result["data"] ?? ["ok" => true]);
    } else {
        echo json_encode(["error" => contrack_upstream_error($result, "Supabase request failed")]);
    }
    exit;
}

http_response_code(400);
echo json_encode(["error" => "Unsupported view"]);
