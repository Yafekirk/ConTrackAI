<?php
declare(strict_types=1);

/**
 * Sends mail through Gmail SMTP (smtp.gmail.com:587, STARTTLS, app password).
 * Returns null on success, or a short error the caller can show.
 */
function contrack_send_mail(string $to, string $subject, string $body): ?string
{
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
