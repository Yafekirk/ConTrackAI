<?php
declare(strict_types=1);

/**
 * Scheduled renewal reminders. Each approved, unarchived contract gets one reminder per threshold
 * (days before its end date); only the tightest threshold already reached is sent, so a contract
 * first seen 10 days out gets the 14-day reminder, not every earlier one. Sent reminders are
 * recorded in audit_logs ("renewal_reminder_sent"), which also keeps reruns from sending twice.
 * Renewing a contract changes its end date, which starts a fresh set of reminders.
 */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_contract_intel.php";

const CONTRACK_RENEWAL_THRESHOLDS = [90, 60, 30, 14, 7, 1, 0];
const CONTRACK_AUTO_RENEWAL_THRESHOLDS = [120, 90, 60, 30, 14, 7, 1, 0];

/** The tightest reminder threshold the contract has reached, or null when no reminder is due. */
function contrack_renewal_threshold(int $daysLeft, bool $autoRenew): ?int
{
    $thresholds = $autoRenew ? CONTRACK_AUTO_RENEWAL_THRESHOLDS : CONTRACK_RENEWAL_THRESHOLDS;
    if ($daysLeft < 0 || $daysLeft > max($thresholds)) {
        return null;
    }
    $hit = null;
    foreach ($thresholds as $t) {
        if ($daysLeft <= $t) {
            $hit = $t;
        }
    }
    return $hit;
}

function contrack_renewal_when(int $daysLeft, string $endDate): string
{
    if ($daysLeft === 0) {
        return "ends today ({$endDate})";
    }
    if ($daysLeft === 1) {
        return "ends tomorrow ({$endDate})";
    }
    return "ends in {$daysLeft} days ({$endDate})";
}

/**
 * Send every renewal reminder that is due. Safe to call often: a lock stops overlapping runs and
 * the audit log stops repeats.
 * @return array{ok:bool,checked:int,sent:int,reminders:list<array<string,mixed>>,error?:string,skipped?:string}
 */
function contrack_run_renewal_reminders(?int $actorId, string $source): array
{
    $lock = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "contrack_renewal_" . substr(sha1(dirname(__DIR__)), 0, 12) . ".lock", "c");
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ["ok" => true, "checked" => 0, "sent" => 0, "reminders" => [], "skipped" => "Another reminder run is in progress."];
    }
    try {
        return contrack_run_renewal_reminders_locked($actorId, $source);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function contrack_run_renewal_reminders_locked(?int $actorId, string $source): array
{
    $since = gmdate("c", time() - 200 * 86400);
    [$contracts, $sentLog] = supabase_request_many([
        ["GET", "vendor_contracts?status=eq.approved&end_date=not.is.null"
            . "&select=id,contract_code,vendor_id,vendor_name,contract_title,end_date,renewal_terms,is_archived,archived_at,manager_reviewed_by"
            . "&order=end_date.asc"],
        ["GET", "audit_logs?event_type=eq.renewal_reminder_sent&created_at=gte." . rawurlencode($since) . "&select=details&limit=10000"],
    ]);
    if (!$contracts["ok"] || !is_array($contracts["data"])) {
        return ["ok" => false, "checked" => 0, "sent" => 0, "reminders" => [], "error" => contrack_upstream_error($contracts, "Could not load contracts.")];
    }
    if (!$sentLog["ok"]) {
        // Without the log every reminder could go out again, so do not send anything.
        return ["ok" => false, "checked" => 0, "sent" => 0, "reminders" => [], "error" => contrack_upstream_error($sentLog, "Could not read the reminder log.")];
    }

    $alreadySent = [];
    foreach ((array)$sentLog["data"] as $log) {
        $d = is_array($log["details"] ?? null) ? $log["details"] : [];
        $alreadySent[($d["contract_id"] ?? "") . "|" . ($d["threshold"] ?? "") . "|" . ($d["end_date"] ?? "")] = true;
    }

    $due = [];
    foreach ($contracts["data"] as $row) {
        if (!is_array($row) || !empty($row["is_archived"]) || !empty($row["archived_at"])) {
            continue;
        }
        $endDate = substr((string)($row["end_date"] ?? ""), 0, 10);
        $days = days_until_end($endDate);
        if ($days === null) {
            continue;
        }
        $autoRenew = str_contains(strtolower((string)($row["renewal_terms"] ?? "")), "auto");
        $threshold = contrack_renewal_threshold($days, $autoRenew);
        if ($threshold === null || isset($alreadySent[(string)$row["id"] . "|{$threshold}|{$endDate}"])) {
            continue;
        }
        $due[] = ["row" => $row, "days" => $days, "threshold" => $threshold, "end_date" => $endDate];
    }

    $reminders = [];
    foreach ($due as $item) {
        $row = $item["row"];
        $title = (string)($row["contract_title"] ?? "Contract");
        $code = trim((string)($row["contract_code"] ?? ""));
        $label = ($code !== "" ? "{$code} · " : "") . $title;
        $when = contrack_renewal_when($item["days"], $item["end_date"]);
        $terms = trim((string)($row["renewal_terms"] ?? ""));
        $termsLine = $terms !== "" ? " Renewal terms: {$terms}." : "";

        create_user_alert(
            isset($row["vendor_id"]) ? (int)$row["vendor_id"] : null,
            "Renewal reminder: {$title}",
            "Your contract {$label} {$when}.{$termsLine} Please contact the company if you plan to renew.",
            "contract",
            true
        );
        $managerId = isset($row["manager_reviewed_by"]) ? (int)$row["manager_reviewed_by"] : 0;
        if ($managerId > 0) {
            create_user_alert($managerId, "Renewal reminder: {$title}", "{$label} ({$row["vendor_name"]}) {$when}.{$termsLine}", "contract", false);
        }
        audit_log_event("renewal_reminder_sent", [
            "contract_id" => (string)$row["id"],
            "threshold" => $item["threshold"],
            "end_date" => $item["end_date"],
            "days_left" => $item["days"],
            "source" => $source,
        ], $actorId);
        $reminders[] = [
            "contract_id" => $row["id"],
            "label" => $label,
            "vendor_name" => (string)($row["vendor_name"] ?? ""),
            "days_left" => $item["days"],
            "end_date" => $item["end_date"],
        ];
    }

    if ($reminders !== []) {
        $lines = array_map(
            static fn(array $r): string => "- {$r["label"]} ({$r["vendor_name"]}): " . contrack_renewal_when((int)$r["days_left"], (string)$r["end_date"]),
            $reminders
        );
        $count = count($reminders);
        $digest = "{$count} contract(s) are coming up for renewal:\n" . implode("\n", $lines);
        notify_role_users("ceo", "Renewal reminders: {$count} contract(s)", $digest, true);
        notify_role_users("manager", "Renewal reminders: {$count} contract(s)", $digest, true);
        contrack_bump_pulse();
    }

    return ["ok" => true, "checked" => count($contracts["data"]), "sent" => count($reminders), "reminders" => $reminders];
}

/** Marker file holding the date of the last automatic run. */
function contrack_renewal_marker_file(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "contrack_renewal_" . substr(sha1(dirname(__DIR__)), 0, 12) . ".day";
}

/**
 * Run the reminders at most once per calendar day, piggybacking on normal portal traffic, so they
 * still go out when no external scheduler is set up. Disable with CONTRACK_AUTO_RENEWAL_REMINDERS=false.
 */
function contrack_maybe_run_daily_renewal_reminders(): void
{
    if (!env_flag("CONTRACK_AUTO_RENEWAL_REMINDERS", true)) {
        return;
    }
    $marker = contrack_renewal_marker_file();
    $today = date("Y-m-d");
    if (@file_get_contents($marker) === $today) {
        return;
    }
    // Write the marker first so concurrent requests do not all start a run. A failed run is retried
    // tomorrow; the threshold logic then sends whatever was missed.
    @file_put_contents($marker, $today, LOCK_EX);
    $result = contrack_run_renewal_reminders(null, "daily_auto");
    if (!$result["ok"]) {
        error_log("daily renewal reminders failed: " . (string)($result["error"] ?? ""));
    }
}
