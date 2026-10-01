<?php
declare(strict_types=1);

/**
 * Sends mail through the Gmail API when Google OAuth values are set (works on Render's free plan
 * and is accepted by Gmail). Otherwise Brevo, then Gmail SMTP. Render's free plan blocks SMTP.
 * Returns null on success, or a short error the caller can show.
 */
function contrack_send_mail(string $to, string $subject, string $body): ?string
{
    $refresh = trim((string)env_value("GOOGLE_REFRESH_TOKEN", ""));
    $clientId = trim((string)env_value("GOOGLE_CLIENT_ID", ""));
    $clientSecret = trim((string)env_value("GOOGLE_CLIENT_SECRET", ""));
    if ($refresh !== "" && $clientId !== "" && $clientSecret !== "") {
        return contrack_send_mail_gmail_api($to, $subject, $body, $clientId, $clientSecret, $refresh);
    }
    $brevoKey = trim((string)env_value("BREVO_API_KEY", ""));
    if ($brevoKey !== "") {
        return contrack_send_mail_brevo($to, $subject, $body, $brevoKey);
    }
    $user = trim((string)env_value("MAIL_SMTP_USER", ""));
    $pass = str_replace(" ", "", trim((string)env_value("MAIL_SMTP_PASSWORD", "")));
    $fromName = trim((string)env_value("MAIL_FROM_NAME", "ConTrack AI"));
    if ($fromName === "") {
        $fromName = "ConTrack AI";
    }
    if ($user === "" || $pass === "") {
        return "Gmail is not set up yet. Add MAIL_SMTP_USER and MAIL_SMTP_PASSWORD, then restart the server.";
    }
    if (!function_exists("stream_socket_enable_crypto")) {
        return "This PHP build cannot open a secure connection to Gmail.";
    }

    $ca = resolve_supabase_ca_bundle();
    if ($ca === null) {
        return "Cannot verify Gmail's certificate (cacert.pem is missing).";
    }

    $errno = 0;
    $errstr = "";
    $context = stream_context_create([
        "ssl" => [
            "verify_peer" => true,
            "verify_peer_name" => true,
            "cafile" => $ca,
            "peer_name" => "smtp.gmail.com",
        ],
    ]);
    $fp = @stream_socket_client(
        "tcp://smtp.gmail.com:587",
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!is_resource($fp)) {
        return "Could not reach Gmail.";
    }
    stream_set_timeout($fp, 20);

    $fail = static function ($fp, string $message): string {
        fclose($fp);
        return $message;
    };

    $banner = contrack_smtp_read($fp);
    if ($banner[0] !== 220) {
        return $fail($fp, "Gmail did not accept the connection.");
    }

    $step = contrack_smtp_cmd($fp, "EHLO contrack.local", [250]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the greeting.");
    }
    $step = contrack_smtp_cmd($fp, "STARTTLS", [220]);
    if ($step !== null) {
        return $fail($fp, "Gmail refused a secure connection.");
    }
    $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($crypto !== true) {
        return $fail($fp, "Could not secure the connection to Gmail.");
    }
    $step = contrack_smtp_cmd($fp, "EHLO contrack.local", [250]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the secure greeting.");
    }
    $step = contrack_smtp_cmd($fp, "AUTH LOGIN", [334]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the sign-in.");
    }
    $step = contrack_smtp_cmd($fp, base64_encode($user), [334]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the sender address.");
    }
    $step = contrack_smtp_cmd($fp, base64_encode($pass), [235]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the app password. Create a new one in your Google Account and restart the server.");
    }

    $step = contrack_smtp_cmd($fp, "MAIL FROM:<{$user}>", [250]);
    if ($step !== null) {
        return $fail($fp, "Gmail rejected the sender address.");
    }
    $step = contrack_smtp_cmd($fp, "RCPT TO:<{$to}>", [250, 251]);
    if ($step !== null) {
        return $fail($fp, "Gmail would not deliver to that address.");
    }
    $step = contrack_smtp_cmd($fp, "DATA", [354]);
    if ($step !== null) {
        return $fail($fp, "Gmail refused the message.");
    }

    $encodedName = "=?UTF-8?B?" . base64_encode($fromName) . "?=";
    $safeSubject = str_replace(["\r", "\n"], "", $subject);
    $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $stuffed = [];
    foreach ($lines as $line) {
        $stuffed[] = (isset($line[0]) && $line[0] === ".") ? "." . $line : $line;
    }
    $message = "From: {$encodedName} <{$user}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$safeSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . implode("\r\n", $stuffed)
        . "\r\n.";
    fwrite($fp, $message . "\r\n");
    $sent = contrack_smtp_read($fp);
    contrack_smtp_cmd($fp, "QUIT", [221]);
    fclose($fp);
    if ($sent[0] !== 250) {
        return "Gmail did not accept the message.";
    }
    return null;
}

function contrack_send_mail_gmail_api(
    string $to,
    string $subject,
    string $body,
    string $clientId,
    string $clientSecret,
    string $refreshToken
): ?string {
    $fromEmail = trim((string)env_value("MAIL_SMTP_USER", ""));
    if ($fromEmail === "" || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        return "Set MAIL_SMTP_USER to the Gmail account that sends the codes.";
    }
    $fromName = trim((string)env_value("MAIL_FROM_NAME", "ConTrack AI"));
    if ($fromName === "") {
        $fromName = "ConTrack AI";
    }

    $tokenBody = http_build_query([
        "client_id" => $clientId,
        "client_secret" => $clientSecret,
        "refresh_token" => $refreshToken,
        "grant_type" => "refresh_token",
    ]);
    $token = contrack_https_json("POST", "https://oauth2.googleapis.com/token", $tokenBody, [
        "content-type: application/x-www-form-urlencoded",
    ]);
    if ($token["status"] !== 200) {
        return "Google rejected the mail sign-in. Check the client id, secret, and refresh token.";
    }
    $access = (string)($token["json"]["access_token"] ?? "");
    if ($access === "") {
        return "Google did not return a mail sign-in.";
    }

    $encodedName = "=?UTF-8?B?" . base64_encode($fromName) . "?=";
    $safeSubject = str_replace(["\r", "\n"], "", $subject);
    $rfc822 = "From: {$encodedName} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$safeSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . str_replace(["\r\n", "\r"], "\n", $body);
    $raw = rtrim(strtr(base64_encode($rfc822), "+/", "-_"), "=");
    $sent = contrack_https_json(
        "POST",
        "https://gmail.googleapis.com/gmail/v1/users/me/messages/send",
        json_encode(["raw" => $raw]) ?: "{}",
        [
            "content-type: application/json",
            "authorization: Bearer {$access}",
        ]
    );
    if ($sent["status"] === 200) {
        return null;
    }
    return "Gmail did not accept the message.";
}

/** @return array{status:int,json:array<string,mixed>} */
function contrack_https_json(string $method, string $url, string $body, array $headers): array
{
    if (!function_exists("curl_init")) {
        return ["status" => 0, "json" => []];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $ca = resolve_supabase_ca_bundle();
    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode(is_string($raw) ? $raw : "", true);
    return ["status" => $status, "json" => is_array($decoded) ? $decoded : []];
}

function contrack_send_mail_brevo(string $to, string $subject, string $body, string $apiKey): ?string
{
    $fromName = trim((string)env_value("MAIL_FROM_NAME", "ConTrack AI"));
    if ($fromName === "") {
        $fromName = "ConTrack AI";
    }
    $fromEmail = trim((string)env_value("BREVO_SENDER_EMAIL", ""));
    if ($fromEmail === "") {
        $fromEmail = trim((string)env_value("MAIL_SMTP_USER", ""));
    }
    if ($fromEmail === "" || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        return "Set MAIL_SMTP_USER to the Gmail address you verified in Brevo.";
    }
    if (!function_exists("curl_init")) {
        return "PHP cURL is required to send mail.";
    }

    $payload = json_encode([
        "sender" => ["name" => $fromName, "email" => $fromEmail],
        "to" => [["email" => $to]],
        "subject" => $subject,
        "textContent" => $body,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return "Could not prepare the email.";
    }

    $ch = curl_init("https://api.brevo.com/v3/smtp/email");
    $headers = [
        "accept: application/json",
        "content-type: application/json",
        "api-key: {$apiKey}",
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $ca = resolve_supabase_ca_bundle();
    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return $curlError !== "" ? "Could not reach Brevo." : "Could not reach Brevo.";
    }
    if ($status === 201 || $status === 202) {
        return null;
    }

    $decoded = json_decode((string)$raw, true);
    $message = is_array($decoded) ? (string)($decoded["message"] ?? "") : "";
    if ($status === 401) {
        return "Brevo rejected the API key. Check BREVO_API_KEY on the server.";
    }
    if (stripos($message, "sender") !== false) {
        return "Brevo rejected the sender. Use the same Gmail address you verified in Brevo.";
    }
    return "Brevo did not accept the message.";
}

/** @return array{0:int,1:string} */
function contrack_smtp_read($fp): array
{
    $lines = [];
    while (($line = fgets($fp, 1024)) !== false) {
        $trim = rtrim($line, "\r\n");
        $lines[] = $trim;
        if (strlen($trim) < 4 || $trim[3] === " ") {
            break;
        }
    }
    $last = $lines === [] ? "" : $lines[count($lines) - 1];
    return [(int)substr($last, 0, 3), implode(" | ", $lines)];
}

function contrack_smtp_cmd($fp, string $command, array $ok): ?string
{
    fwrite($fp, $command . "\r\n");
    [$code] = contrack_smtp_read($fp);
    if (!in_array($code, $ok, true)) {
        return "smtp {$code}";
    }
    return null;
}
