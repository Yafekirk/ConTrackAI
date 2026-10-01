<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server:  php -S localhost:8000 router.php
 *
 * The built-in server serves every file under the document root. This router keeps the public
 * site (HTML, assets, api/*.php endpoints) reachable and hides everything else: source history,
 * SQL, maintenance scripts, stored contract PDFs, certificates, the NLP code and dotfiles.
 * Stored PDFs must only be reachable through api/contract_pdf.php, which checks the session and owner.
 */

$requestPath = (string)parse_url((string)($_SERVER["REQUEST_URI"] ?? "/"), PHP_URL_PATH);
$decoded = rawurldecode($requestPath);

if (strpos($decoded, "\0") !== false) {
    http_response_code(400);
    exit;
}

$normalized = "/" . ltrim(str_replace("\\", "/", $decoded), "/");
$segments = array_values(array_filter(explode("/", $normalized), static fn(string $s): bool => $s !== ""));

$deny = static function (): never {
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    echo "Not found";
    exit;
};

foreach ($segments as $segment) {
    if ($segment === ".." || $segment === ".") {
        $deny();
    }
    // Dotfiles and dot-folders (.git, .env, .cursor, ...). Domain-verification folders stay reachable.
    if ($segment[0] === "." && $segment !== ".well-known") {
        $deny();
    }
}

$privateFolders = ["sql", "scripts", "storage", "certs", "nlp", "vendor", "node_modules"];
if ($segments !== [] && in_array(strtolower($segments[0]), $privateFolders, true)) {
    $deny();
}

$file = $segments === [] ? "" : strtolower((string)end($segments));
if (in_array($file, ["dockerfile", "router.php", "composer.json", "composer.lock"], true)) {
    $deny();
}
if (preg_match('/\.(sql|py|md|log|env|ya?ml|pem|ps1|bak|dist|ini)$/', $file) === 1) {
    $deny();
}
// Shared PHP libraries are include-only; only the endpoint files are meant to be called directly.
if (($segments[0] ?? "") === "api" && strpos($file, "lib_") === 0) {
    $deny();
}

// Anything else: let the built-in server serve the file or run the endpoint as usual.
return false;
