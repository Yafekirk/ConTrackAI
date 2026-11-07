<?php
// Checks GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REFRESH_TOKEN from .env against Google.
// Usage:  php scripts/test_gmail_oauth.php

$envFile = __DIR__ . "/../.env";
$vars = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#" || strpos($line, "=") === false) {
            continue;
        }
        [$k, $v] = explode("=", $line, 2);
        $vars[trim($k)] = trim(trim($v), "\"'");
    }
}

$get = fn(string $k): string => trim((string)($vars[$k] ?? getenv($k) ?: ""));
$clientId = $get("GOOGLE_CLIENT_ID");
$clientSecret = $get("GOOGLE_CLIENT_SECRET");
$refresh = $get("GOOGLE_REFRESH_TOKEN");
$sender = $get("MAIL_SMTP_USER");

foreach (["GOOGLE_CLIENT_ID" => $clientId, "GOOGLE_CLIENT_SECRET" => $clientSecret, "GOOGLE_REFRESH_TOKEN" => $refresh] as $k => $v) {
    if ($v === "") {
        fwrite(STDERR, "Missing {$k} in .env\n");
        exit(1);
    }
}
if (!str_ends_with($clientId, ".apps.googleusercontent.com")) {
    echo "Warning: GOOGLE_CLIENT_ID does not end in .apps.googleusercontent.com\n";
}
if (str_starts_with($refresh, "ya29.")) {
    echo "Warning: GOOGLE_REFRESH_TOKEN looks like an ACCESS token (ya29.). Use the one starting with 1//\n";
}

$ch = curl_init("https://oauth2.googleapis.com/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        "client_id" => $clientId,
        "client_secret" => $clientSecret,
        "refresh_token" => $refresh,
        "grant_type" => "refresh_token",
    ]),
    CURLOPT_TIMEOUT => 20,
]);
$ca = __DIR__ . "/../certs/cacert.pem";
if (is_file($ca)) {
    curl_setopt($ch, CURLOPT_CAINFO, $ca);
}
$raw = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    fwrite(STDERR, "Could not reach Google: {$err}\n");
    exit(1);
}
$json = json_decode($raw, true) ?: [];
if ($status !== 200) {
    echo "Google rejected the credentials (HTTP {$status}):\n";
    echo "  error: " . ($json["error"] ?? "?") . "\n";
    echo "  description: " . ($json["error_description"] ?? "?") . "\n";
    exit(1);
}

echo "Token exchange OK. Scopes granted: " . ($json["scope"] ?? "?") . "\n";
if (strpos((string)($json["scope"] ?? ""), "gmail.send") === false && strpos((string)($json["scope"] ?? ""), "mail.google.com") === false) {
    echo "Warning: token lacks the gmail.send scope; Gmail will refuse to send.\n";
}

$ch = curl_init("https://gmail.googleapis.com/gmail/v1/users/me/profile");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["authorization: Bearer " . $json["access_token"]],
    CURLOPT_TIMEOUT => 20,
]);
if (is_file($ca)) {
    curl_setopt($ch, CURLOPT_CAINFO, $ca);
}
$profile = json_decode((string)curl_exec($ch), true) ?: [];
curl_close($ch);
if (isset($profile["emailAddress"])) {
    echo "Token belongs to: {$profile["emailAddress"]}\n";
    if ($sender !== "" && strcasecmp($sender, $profile["emailAddress"]) !== 0) {
        echo "Warning: MAIL_SMTP_USER ({$sender}) differs from the token's account.\n";
    }
}
echo "Credentials are good. If the live site still fails, the values on your host differ from these.\n";
