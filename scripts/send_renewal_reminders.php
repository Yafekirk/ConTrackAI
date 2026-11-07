<?php
// Sends the renewal reminder emails that are due. Run it once a day from a scheduler, e.g.
//   Linux cron:            0 8 * * *  php /app/scripts/send_renewal_reminders.php
//   Windows Task Scheduler: php C:\path\to\ConTrackAI\scripts\send_renewal_reminders.php
// Reruns on the same day send nothing new.
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

const CONTRACK_SKIP_JSON_HEADER = true;
require_once __DIR__ . "/../api/lib_renewal_reminders.php";

$result = contrack_run_renewal_reminders(null, "cli");
if (!$result["ok"]) {
    fwrite(STDERR, "Renewal reminders failed: " . ($result["error"] ?? "unknown error") . PHP_EOL);
    exit(1);
}
if (!empty($result["skipped"])) {
    echo $result["skipped"], PHP_EOL;
    exit(0);
}
echo "Checked {$result["checked"]} approved contract(s); sent {$result["sent"]} reminder(s).", PHP_EOL;
foreach ($result["reminders"] as $r) {
    echo " - {$r["label"]} ({$r["vendor_name"]}): {$r["days_left"]} day(s) left, ends {$r["end_date"]}", PHP_EOL;
}
