<?php
declare(strict_types=1);

/**
 * Manual vendor scoring. The backend is the only source of overall/weighted totals.
 *
 * Weighted Score = Criterion Score × Criterion Weight
 * Overall Score  = Sum of weighted scores (weights sum to 1.0 → 0–100 scale)
 */

/**
 * Stars = 1 + 4 × (score ÷ 100), one decimal. 85 → 4.4. Matches assets/js/star-rating.js.
 */
function vendor_star_rating(float $score): float
{
    $clamped = max(0.0, min(100.0, $score));
    return round(1 + 4 * ($clamped / 100), 1);
}

function vendor_score_rating(float $overall): string
{
    if ($overall >= 90) {
        return "excellent";
    }
    if ($overall >= 80) {
        return "good";
    }
    if ($overall >= 60) {
        return "satisfactory";
    }
    return "needs_improvement";
}

function vendor_score_rating_label(string $rating): string
{
    return match ($rating) {
        "excellent" => "Excellent",
        "good" => "Good",
        "satisfactory" => "Satisfactory",
        "needs_improvement" => "Needs improvement",
        default => $rating,
    };
}

function vendor_score_is_staff_role(string $role): bool
{
    $role = normalize_role($role);
    return in_array($role, ["manager", "ceo", "admin"], true);
}

function vendor_score_is_vendor_role(string $role): bool
{
    return normalize_role($role) === "vendor";
}

/**
 * @return array<int, array<string, mixed>>
 */
function vendor_score_active_criteria(): array
{
    $result = supabase_request(
        "GET",
        "vendor_score_criteria?is_active=eq.true&select=id,code,name,description,weight,sort_order,is_active&order=sort_order.asc"
    );
    if (!$result["ok"] || !is_array($result["data"])) {
        return [];
    }
    return $result["data"];
}

/**
 * @param array<int, array<string, mixed>> $criteria
 * @param array<int|string, mixed> $scoresById  criterion_id => 0–100
 * @return array{ok:bool,error:?string,overall:float,lines:array<int,array<string,mixed>>}
 */
function vendor_score_calculate(array $criteria, array $scoresById): array
{
    if ($criteria === []) {
        return ["ok" => false, "error" => "No active scoring criteria are configured.", "overall" => 0.0, "lines" => []];
    }

    $weightSum = 0.0;
    foreach ($criteria as $c) {
        $weightSum += (float)($c["weight"] ?? 0);
    }
    if ($weightSum <= 0) {
        return ["ok" => false, "error" => "Scoring criteria weights are invalid.", "overall" => 0.0, "lines" => []];
    }

    $lines = [];
    $weightedSum = 0.0;
    foreach ($criteria as $c) {
        $id = (int)($c["id"] ?? 0);
        if ($id <= 0) {
            return ["ok" => false, "error" => "A scoring criterion is missing an id.", "overall" => 0.0, "lines" => []];
        }
        if (!array_key_exists($id, $scoresById) && !array_key_exists((string)$id, $scoresById)) {
            $name = (string)($c["name"] ?? $c["code"] ?? "criterion");
            return ["ok" => false, "error" => "A score is required for {$name}.", "overall" => 0.0, "lines" => []];
        }
        $raw = $scoresById[$id] ?? $scoresById[(string)$id];
        if (!is_numeric($raw)) {
            return ["ok" => false, "error" => "Scores must be numbers between 0 and 100.", "overall" => 0.0, "lines" => []];
        }
        $score = round((float)$raw, 2);
        if ($score < 0 || $score > 100) {
            return ["ok" => false, "error" => "Each criterion score must be between 0 and 100.", "overall" => 0.0, "lines" => []];
        }
        $weight = (float)$c["weight"];
        $weighted = round($score * $weight, 4);
        $weightedSum += $weighted;
        $lines[] = [
            "criterion_id" => $id,
            "code" => (string)($c["code"] ?? ""),
            "name" => (string)($c["name"] ?? ""),
            "weight" => $weight,
            "score" => $score,
            "weighted_score" => $weighted,
        ];
    }

    // Normalize if weights do not sum to exactly 1 (admin-edited weights).
    $overall = round($weightedSum / $weightSum, 2);
    $overall = max(0.0, min(100.0, $overall));

    return ["ok" => true, "error" => null, "overall" => $overall, "lines" => $lines];
}

/**
 * Latest evaluation per vendor_id (staff dashboards / vendor summary).
 *
 * @return array<int, array<string, mixed>>
 */
function vendor_score_latest_by_vendor(): array
{
    $result = supabase_request(
        "GET",
        "vendor_evaluations?select=id,vendor_id,evaluator_id,evaluated_at,overall_score,rating,remarks,created_at,updated_at&order=evaluated_at.desc&limit=2000"
    );
    if (!$result["ok"] || !is_array($result["data"])) {
        return [];
    }
    $out = [];
    foreach ($result["data"] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $vid = (int)($row["vendor_id"] ?? 0);
        if ($vid <= 0 || isset($out[$vid])) {
            continue;
        }
        $out[$vid] = $row;
    }
    return $out;
}

/**
 * @return array<string, float>
 */
function vendor_score_breakdown_map(array $scoreRows): array
{
    $map = [];
    foreach ($scoreRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $crit = is_array($row["vendor_score_criteria"] ?? null) ? $row["vendor_score_criteria"] : [];
        $code = (string)($crit["code"] ?? "");
        if ($code === "") {
            continue;
        }
        $map[$code] = (float)($row["score"] ?? 0);
    }
    return $map;
}

/**
 * Every active criterion, with the saved score when this evaluation has one.
 *
 * @return array<int, array<string, mixed>>
 */
function vendor_score_criteria_view(array $scoreRows): array
{
    $saved = [];
    foreach ($scoreRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $crit = is_array($row["vendor_score_criteria"] ?? null) ? $row["vendor_score_criteria"] : [];
        $code = (string)($crit["code"] ?? "");
        if ($code === "") {
            continue;
        }
        $score = (float)($row["score"] ?? 0);
        $saved[$code] = [
            "code" => $code,
            "name" => (string)($crit["name"] ?? $code),
            "score" => $score,
            "star_rating" => vendor_star_rating($score),
            "sort_order" => (int)($crit["sort_order"] ?? 0),
        ];
    }
    foreach (vendor_score_active_criteria() as $c) {
        $code = (string)($c["code"] ?? "");
        if ($code === "" || isset($saved[$code])) {
            continue;
        }
        $saved[$code] = [
            "code" => $code,
            "name" => (string)($c["name"] ?? $code),
            "score" => null,
            "star_rating" => null,
            "sort_order" => (int)($c["sort_order"] ?? 0),
        ];
    }
    $list = array_values($saved);
    usort($list, static fn (array $a, array $b): int => ($a["sort_order"] <=> $b["sort_order"]));
    return $list;
}

function vendor_score_load_lines(string $evaluationId): array
{
    $path = "vendor_evaluation_scores?evaluation_id=eq." . urlencode($evaluationId)
        . "&select=id,evaluation_id,criterion_id,score,weighted_score,vendor_score_criteria(id,code,name,weight,sort_order)";
    $result = supabase_request("GET", $path);
    if (!$result["ok"] || !is_array($result["data"])) {
        return [];
    }
    $rows = $result["data"];
    usort($rows, static function (array $a, array $b): int {
        $oa = (int)($a["vendor_score_criteria"]["sort_order"] ?? 0);
        $ob = (int)($b["vendor_score_criteria"]["sort_order"] ?? 0);
        return $oa <=> $ob;
    });
    return $rows;
}

function vendor_score_public_row(array $eval, array $scoreRows = []): array
{
    $overall = (float)($eval["overall_score"] ?? 0);
    $rating = (string)($eval["rating"] ?? vendor_score_rating($overall));
    $lines = [];
    foreach ($scoreRows as $row) {
        $crit = is_array($row["vendor_score_criteria"] ?? null) ? $row["vendor_score_criteria"] : [];
        $lines[] = [
            "criterion_id" => (int)($row["criterion_id"] ?? $crit["id"] ?? 0),
            "code" => (string)($crit["code"] ?? ""),
            "name" => (string)($crit["name"] ?? ""),
            "weight" => (float)($crit["weight"] ?? 0),
            "score" => (float)($row["score"] ?? 0),
            "star_rating" => vendor_star_rating((float)($row["score"] ?? 0)),
            "weighted_score" => (float)($row["weighted_score"] ?? 0),
        ];
    }
    return [
        "id" => $eval["id"] ?? null,
        "vendor_id" => (int)($eval["vendor_id"] ?? 0),
        "vendor_name" => $eval["vendor_name"] ?? null,
        "vendor_email" => $eval["vendor_email"] ?? null,
        "supplier_type" => $eval["supplier_type"] ?? null,
        "evaluator_id" => (int)($eval["evaluator_id"] ?? 0),
        "evaluator_name" => $eval["evaluator_name"] ?? null,
        "evaluated_at" => $eval["evaluated_at"] ?? null,
        "overall_score" => $overall,
        "star_rating" => vendor_star_rating($overall),
        "rating" => $rating,
        "rating_label" => vendor_score_rating_label($rating),
        "remarks" => $eval["remarks"] ?? null,
        "created_at" => $eval["created_at"] ?? null,
        "updated_at" => $eval["updated_at"] ?? null,
        "scores" => $lines,
        "criteria" => vendor_score_criteria_view($scoreRows),
        "breakdown" => vendor_score_breakdown_map($scoreRows),
    ];
}

function vendor_score_attach_people(array $evals): array
{
    $ids = [];
    foreach ($evals as $e) {
        $ids[] = (int)($e["vendor_id"] ?? 0);
        $ids[] = (int)($e["evaluator_id"] ?? 0);
    }
    $ids = array_values(array_unique(array_filter($ids, static fn (int $n): bool => $n > 0)));
    if ($ids === []) {
        return $evals;
    }
    $in = implode(",", $ids);
    $users = supabase_request("GET", "users?id=in.({$in})&select=id,name,company_name,email,supplier_type,role");
    $byId = [];
    if ($users["ok"] && is_array($users["data"])) {
        foreach ($users["data"] as $u) {
            $byId[(int)$u["id"]] = $u;
        }
    }
    foreach ($evals as &$e) {
        $v = $byId[(int)($e["vendor_id"] ?? 0)] ?? null;
        $ev = $byId[(int)($e["evaluator_id"] ?? 0)] ?? null;
        $e["vendor_name"] = is_array($v) ? contrack_public_name($v) : null;
        $e["vendor_email"] = $v["email"] ?? null;
        $e["supplier_type"] = $v["supplier_type"] ?? null;
        $e["evaluator_name"] = $ev["name"] ?? null;
    }
    unset($e);
    return $evals;
}

function vendor_score_parse_scores_input(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item["criterion_id"] ?? $item["id"] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = $item["score"] ?? null;
    }
    return $out;
}

function vendor_score_is_uuid(string $id): bool
{
    return (bool)preg_match(
        "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i",
        $id
    );
}
