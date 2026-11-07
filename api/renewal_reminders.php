<?php
declare(strict_types=1);

/**
 * Sends the renewal reminder emails that are due (see lib_renewal_reminders.php).
 *   - A scheduler (cron-job.org, Render cron, Task Scheduler) calls it with the secret:
 *       POST /api/renewal_reminders.php   header  X-Cron-Key: <CONTRACK_CRON_SECRET>
 *   - A signed-in CEO or admin can POST to run it immediately.
 */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_renewal_reminders.php";

if (request_method() !== "POST") {
    json_error("Method not allowed", 405);
}

$secret = trim((string)env_value("CONTRACK_CRON_SECRET", ""));
$given = trim((string)($_SERVER["HTTP_X_CRON_KEY"] ?? ""));
if ($secret !== "" && $given !== "" && hash_equals($secret, $given)) {
    $actorId = null;
    $source = "scheduler";
} else {
    require_roles(["ceo", "admin"]);
    $actorId = require_user_id();
    $source = "manual";
}

$result = contrack_run_renewal_reminders($actorId, $source);
if (!$result["ok"]) {
    json_error((string)($result["error"] ?? "Could not send renewal reminders."), 502);
}
json_response($result);
