<?php
declare(strict_types=1);

/** Returned by contrack_send_mail() when the mail service refuses the recipient address itself. */
const CONTRACK_MAIL_BAD_RECIPIENT = "That email address cannot receive mail.";

/**
 * Site address used in emailed links: CONTRACK_PUBLIC_URL, else the address Render provides.
 * The Host header is client-controlled, so it is only trusted for localhost.
 */
function contrack_public_base_url(): ?string
{
    foreach (["CONTRACK_PUBLIC_URL", "RENDER_EXTERNAL_URL"] as $key) {
        $configured = trim((string)env_value($key, ""));
        if ($configured !== "") {
            return rtrim($configured, "/");
        }
    }
    $host = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));
    if (preg_match('/^(localhost|127\.0\.0\.1)(:\d{1,5})?$/', $host) === 1) {
        return (env_flag("CONTRACK_HTTPS", false) ? "https" : "http") . "://" . $host;
    }
    return null;
}

/**
 * Sends mail through the Gmail API when Google OAuth values are set (works on Render's free plan
 * and is accepted by Gmail). Otherwise Brevo, then Gmail SMTP. Render's free plan blocks SMTP.
 * MAIL_PROVIDER=brevo forces Brevo, so mail can come from a verified company domain
 * (BREVO_SENDER_EMAIL) instead of a personal Gmail address, which Gmail is quicker to mark as spam.
 * Returns null on success, or a short error the caller can show.
 */
function contrack_send_mail(string $to, string $subject, string $body): ?string
{
    $provider = strtolower(trim((string)env_value("MAIL_PROVIDER", "")));
    $brevoKey = trim((string)env_value("BREVO_API_KEY", ""));
    if ($provider === "brevo") {
        if ($brevoKey === "") {
            return "MAIL_PROVIDER is brevo but BREVO_API_KEY is not set.";
        }
        return contrack_send_mail_brevo($to, $subject, $body, $brevoKey);
    }
    $refresh = trim((string)env_value("GOOGLE_REFRESH_TOKEN", ""));
    $clientId = trim((string)env_value("GOOGLE_CLIENT_ID", ""));
    $clientSecret = trim((string)env_value("GOOGLE_CLIENT_SECRET", ""));
    if ($refresh !== "" && $clientId !== "" && $clientSecret !== "") {
        return contrack_send_mail_gmail_api($to, $subject, $body, $clientId, $clientSecret, $refresh);
    }
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
        return $fail($fp, CONTRACK_MAIL_BAD_RECIPIENT);
    }
    $step = contrack_smtp_cmd($fp, "DATA", [354]);
    if ($step !== null) {
        return $fail($fp, "Gmail refused the message.");
    }

    $lines = preg_split("/\r\n|\n|\r/", rtrim(contrack_mime_message($fromName, $user, $to, $subject, $body), "\r\n")) ?: [];
    $stuffed = [];
    foreach ($lines as $line) {
        $stuffed[] = (isset($line[0]) && $line[0] === ".") ? "." . $line : $line;
    }
    $message = implode("\r\n", $stuffed) . "\r\n.";
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

    // One access token serves every email sent during the same request.
    static $access = "";
    if ($access === "") {
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
            $code = preg_replace('/[^a-z_]/', '', (string)($token["json"]["error"] ?? "unknown"));
            error_log("Gmail token exchange failed ({$token["status"]}): " . json_encode($token["json"]));
            return "Google rejected the mail sign-in ({$code}). Check the client id, secret, and refresh token.";
        }
        $access = (string)($token["json"]["access_token"] ?? "");
        if ($access === "") {
            return "Google did not return a mail sign-in.";
        }
    }

    $rfc822 = contrack_mime_message($fromName, $fromEmail, $to, $subject, $body);
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
    $reason = (string)($sent["json"]["error"]["message"] ?? "");
    error_log("Gmail API send failed ({$sent["status"]}): {$reason}");
    if ($sent["status"] === 400 && preg_match('/invalid to|to header|recipient/i', $reason) === 1) {
        return CONTRACK_MAIL_BAD_RECIPIENT;
    }
    return "Gmail did not accept the message.";
}

/** Optional Reply-To (MAIL_REPLY_TO) so replies reach a monitored inbox; null when unset or invalid. */
function contrack_mail_reply_to(): ?string
{
    $replyTo = trim((string)env_value("MAIL_REPLY_TO", ""));
    return filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false ? $replyTo : null;
}

/** RFC 2047 encoding for header text that is not plain ASCII (company names, accented subjects). */
function contrack_mail_header_text(string $text): string
{
    $text = str_replace(["\r", "\n"], "", $text);
    return preg_match('/[^\x20-\x7E]/', $text) === 1 ? "=?UTF-8?B?" . base64_encode($text) . "?=" : $text;
}

/**
 * HTML twin of a plain-text email: escaped, links made clickable, line breaks kept.
 * Spam filters trust a text + HTML pair more than a bare text message.
 */
function contrack_mail_html(string $subject, string $text): string
{
    $escaped = htmlspecialchars(str_replace(["\r\n", "\r"], "\n", $text), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $linked = preg_replace('~https?://[^\s<]+~', '<a href="$0" style="color:#7A0C0C">$0</a>', $escaped) ?? $escaped;
    $title = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"UTF-8\"><title>{$title}</title></head>"
        . "<body style=\"margin:0;padding:24px;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#222\">"
        . "<div style=\"max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;font-size:15px;line-height:1.55\">"
        . nl2br($linked, false)
        . "</div></body></html>";
}

/** Complete RFC 5322 message (headers + multipart text/HTML body) for the SMTP and Gmail API senders. */
function contrack_mime_message(string $fromName, string $fromEmail, string $to, string $subject, string $text): string
{
    $domain = substr((string)strrchr($fromEmail, "@"), 1) ?: "contrack.local";
    $boundary = "contrack-" . bin2hex(random_bytes(12));
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $headers = [
        "From: " . contrack_mail_header_text($fromName) . " <{$fromEmail}>",
        "To: <{$to}>",
        "Subject: " . contrack_mail_header_text($subject),
        "Date: " . date(DATE_RFC2822),
        "Message-ID: <" . bin2hex(random_bytes(16)) . "@{$domain}>",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    ];
    $replyTo = contrack_mail_reply_to();
    if ($replyTo !== null) {
        $headers[] = "Reply-To: <{$replyTo}>";
    }
    $part = static fn(string $type, string $content): string => "--{$boundary}\r\n"
        . "Content-Type: {$type}; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($content), 76, "\r\n");
    return implode("\r\n", $headers) . "\r\n\r\n"
        . $part("text/plain", str_replace("\n", "\r\n", $text))
        . $part("text/html", contrack_mail_html($subject, $text))
        . "--{$boundary}--\r\n";
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
        return "Set BREVO_SENDER_EMAIL to the sender address you verified in Brevo.";
    }
    if (!function_exists("curl_init")) {
        return "PHP cURL is required to send mail.";
    }

    $message = [
        "sender" => ["name" => $fromName, "email" => $fromEmail],
        "to" => [["email" => $to]],
        "subject" => $subject,
        "textContent" => $body,
        "htmlContent" => contrack_mail_html($subject, $body),
    ];
    $replyTo = contrack_mail_reply_to();
    if ($replyTo !== null) {
        $message["replyTo"] = ["email" => $replyTo];
    }
    $payload = json_encode($message, JSON_UNESCAPED_UNICODE);
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
    error_log("Brevo send failed ({$status}): " . substr((string)$raw, 0, 300));
    if ($status === 401) {
        return "Brevo rejected the API key. Check BREVO_API_KEY on the server.";
    }
    if ($status === 400 && preg_match('/recipient|\b(to|email)\b.*\b(invalid|not valid)\b|\b(invalid|not valid)\b.*\b(to|email)\b/i', $message) === 1
        && stripos($message, "sender") === false) {
        return CONTRACK_MAIL_BAD_RECIPIENT;
    }
    if (stripos($message, "sender") !== false) {
        return "Brevo rejected the sender. Set BREVO_SENDER_EMAIL to an address on the domain you verified in Brevo.";
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
