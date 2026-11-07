<?php
declare(strict_types=1);

/**
 * Cheap change signal for live sync (assets/js/live-sync.js).
 * Returns the time of the last workflow write on this server (see contrack_bump_pulse() in config.php).
 * It loads no session and calls no database, so open tabs can ask every few seconds; when the value moves
 * they ask api/changes.php, which checks whether the change is visible to the signed-in user.
 * The value only says "something changed", so a session cookie is required but not validated here.
 */

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

if (empty($_COOKIE[session_name()])) {
    http_response_code(401);
    echo '{"error":"Sign in required"}';
    exit;
}

$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "contrack_pulse_" . substr(sha1(dirname(__DIR__)), 0, 12);
$stamp = is_readable($file) ? trim((string)@file_get_contents($file)) : "";

echo json_encode(["pulse" => $stamp === "" ? "0" : $stamp]);
