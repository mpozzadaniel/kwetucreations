<?php
/**
 * Shared plumbing for the intake endpoints: session, CSRF, JSON output and
 * the validation routines that decide whether a submission is acceptable.
 *
 * Nothing in here prints HTML. Endpoints are pure JSON so the static pages
 * can talk to them without being converted to PHP.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// A PHP notice or warning printed ahead of a JSON body breaks the answer the
// form waits for, and shows the visitor a file path they have no business
// reading. The text still goes to the server log, where it can be read.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
}

/**
 * Is this request arriving over an encrypted connection?
 *
 * $_SERVER['HTTPS'] alone is not enough on much of the web: the certificate
 * often ends at the provider's proxy, which hands PHP a plain http request
 * and says what the visitor actually used in X-Forwarded-Proto. The session
 * cookie has to be marked Secure in that case too. Plain http on this machine
 * stays plain http — nothing here forces a certificate that does not exist.
 */
function kwetu_request_is_secure(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');

    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }

    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

    if ($forwarded !== '') {
        // A chain writes "https, http"; the visitor's own hop is the first.
        return trim(explode(',', $forwarded)[0]) === 'https';
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function kwetu_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => kwetu_request_is_secure(),
    ]);

    session_name('kwetu_intake');
    session_start();
}

function kwetu_csrf_token(): string
{
    kwetu_session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * A token is only good for an hour, so an abandoned tab cannot hoard one.
 */
function kwetu_csrf_is_valid(?string $candidate): bool
{
    kwetu_session_start();

    if ($candidate === null || $candidate === '' || empty($_SESSION['csrf'])) {
        return false;
    }

    $issued = (int) ($_SESSION['csrf_at'] ?? 0);

    if ($issued > 0 && (time() - $issued) > 3600) {
        return false;
    }

    return hash_equals($_SESSION['csrf'], $candidate);
}

function kwetu_issue_csrf(): void
{
    kwetu_session_start();

    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_at'] = time();
}

function kwetu_respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kwetu_reject_json(string $reason, string $field = ''): never
{
    kwetu_respond(422, [
        'saved' => false,
        'reason' => $reason,
        'field' => $field,
    ]);
}

/**
 * The form is static HTML, so a bot can post straight to the endpoint the
 * same way a browser can. Same-origin headers are a cheap first filter; the
 * CSRF token behind them is the part that actually counts.
 *
 * The host name is compared whole, not as a piece of the Origin string: an
 * address like http://kwetucreations.com.attacker.example or
 * http://not-kwetucreations.com used to pass a substring test.
 */
function kwetu_same_origin(array $headers): bool
{
    $origin = trim((string) ($headers['origin'] ?? ''));
    $site = strtolower(trim((string) ($headers['sec-fetch-site'] ?? '')));
    $hostHeader = trim((string) ($headers['host'] ?? ''));

    if ($site === 'cross-site') {
        return false;
    }

    // A POST with no Origin at all is legal (an older browser, a plain curl
    // run), so it is not refused here. It still has to carry a CSRF token
    // issued to this very session, which is the check that counts.
    if ($origin === '') {
        return $hostHeader !== '';
    }

    $originParts = parse_url($origin);
    $hostParts = parse_url('//' . $hostHeader);

    if ($originParts === false || $hostParts === false
        || empty($originParts['host']) || empty($hostParts['host'])) {
        return false;
    }

    $scheme = strtolower((string) ($originParts['scheme'] ?? ''));

    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    if (strcasecmp((string) $originParts['host'], (string) $hostParts['host']) !== 0) {
        return false;
    }

    // Only when both sides name a port: a browser leaves the port out of an
    // Origin on the default one, and so does a Host header, so an absent port
    // says nothing either way.
    if (isset($originParts['port'], $hostParts['port'])
        && (int) $originParts['port'] !== (int) $hostParts['port']) {
        return false;
    }

    return true;
}

function kwetu_headers(): array
{
    $out = [];

    foreach ($_SERVER as $key => $value) {
        if (strncmp($key, 'HTTP_', 5) === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $out[$name] = is_string($value) ? $value : '';
        }
    }

    return $out;
}

/**
 * Fingerprint of the visitor's address. The raw IP is never kept, and the
 * salt makes the stored hashes useless to anyone who only reads the database.
 */
function kwetu_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return hash('sha256', kwetu_config()['ip_salt'] . '|' . $ip);
}

/**
 * Collapse whatever a visitor typed into something safe to store: no control
 * characters, no runaway whitespace, a hard character count rather than a byte
 * count so a two-byte accented name is not cut in half.
 */
function kwetu_clean_text(string $raw, int $limit): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized);
    $collapsed = preg_replace('/[ \t]+/', ' ', (string) $stripped);
    $trimmed = trim((string) $collapsed);

    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, $limit);
    }

    return substr($trimmed, 0, $limit);
}

/**
 * Mirrors the browser-side rules in form-guard.js. The browser check is for
 * politeness; this one is the only check that actually matters, because a
 * determined client can skip the browser entirely.
 *
 * Returns [errors, values]. errors is keyed by field name.
 */
function kwetu_validate_testimonial(array $post): array
{
    $errors = [];

    $name = kwetu_clean_text((string) ($post['client_name'] ?? ''), 100);
    $business = kwetu_clean_text((string) ($post['business_name'] ?? ''), 150);
    $text = kwetu_clean_text((string) ($post['testimonial'] ?? ''), 2000);
    $consent = ($post['publication_consent'] ?? '') === 'yes';

    if ($name === '') {
        $errors['client_name'] = 'Your name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors['client_name'] = 'Please write your full name.';
    }

    if ($business === '') {
        $business = null;
    }

    $ratingRaw = (string) ($post['rating'] ?? '');

    if ($ratingRaw === '') {
        $errors['rating'] = 'Please choose a rating.';
        $rating = 0;
    } else {
        $rating = (int) $ratingRaw;

        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'The rating must be between 1 and 5.';
        }
    }

    $length = mb_strlen($text);

    if ($text === '') {
        $errors['testimonial'] = 'Please tell us about your experience.';
    } elseif ($length < 10) {
        $errors['testimonial'] = 'Please write at least 10 characters.';
    }

    if (!$consent) {
        $errors['publication_consent'] = 'Please confirm this is your genuine experience and allow us to publish it.';
    }

    return [$errors, [
        'client_name' => $name,
        'business_name' => $business,
        'rating' => $rating,
        'testimonial' => $text,
        'publication_consent' => $consent ? 1 : 0,
    ]];
}

/**
 * Submissions per fingerprint in the last 24 hours.
 *
 * $table is checked against a fixed list before it reaches the SQL, because a
 * table name cannot be passed as a bound parameter.
 */
function kwetu_recent_count(PDO $pdo, string $ipHash, string $table = 'testimonial_submissions'): int
{
    $allowed = ['testimonial_submissions', 'enquiries'];

    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unknown intake table: ' . $table);
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE ip_hash = :ip AND created_at > (NOW() - INTERVAL 1 DAY)'
    );
    $stmt->execute([':ip' => $ipHash]);

    return (int) $stmt->fetchColumn();
}

/**
 * The two enquiry forms ask for different things under different names, so
 * one map turns each form's field names into the shared columns. Both forms
 * land in the same table; the "kind" column is what tells them apart.
 */
function kwetu_enquiry_field_map(string $kind): array
{
    if ($kind === 'quote') {
        return [
            'full_name' => 'clientName',
            'business' => 'clientBusiness',
            'email' => 'clientEmail',
            'phone' => 'clientPhone',
            'address' => 'clientAddress',
            'topic' => 'service',
            'message' => 'projectDetails',
        ];
    }

    return [
        'full_name' => 'fullName',
        'business' => 'company',
        'email' => 'email',
        'phone' => 'phone',
        'address' => null,
        'topic' => 'subject',
        'message' => 'message',
    ];
}

/** Topics a form is allowed to send. Anything else is a hand-built POST. */
function kwetu_enquiry_topics(string $kind): array
{
    if ($kind === 'quote') {
        return [
            'Logo and brand identity',
            'Signage and door stickers',
            'Website design',
            'Printing and graphic design',
            'Marketing materials',
            'Other creative project',
        ];
    }

    return [
        'Branding',
        'Laser Services',
        'Signage',
        'Fabrication',
        'Interior Solutions',
        'Website design',
        'General Enquiry',
        'Other',
    ];
}

/**
 * Server-side rules for a contact or quote enquiry. The browser checks the
 * same things, but only this run counts.
 *
 * Returns [errors, values]; errors is keyed by the form's own field name so
 * the page can put each message beside the right control.
 */
function kwetu_validate_enquiry(string $kind, array $post): array
{
    $map = kwetu_enquiry_field_map($kind);
    $errors = [];

    $name = kwetu_clean_text((string) ($post[$map['full_name']] ?? ''), 120);
    $business = kwetu_clean_text((string) ($post[$map['business']] ?? ''), 150);
    $email = kwetu_clean_text((string) ($post[$map['email']] ?? ''), 190);
    $phone = kwetu_clean_text((string) ($post[$map['phone']] ?? ''), 40);
    $address = $map['address'] === null
        ? null
        : kwetu_clean_text((string) ($post[$map['address']] ?? ''), 250);
    $topic = kwetu_clean_text((string) ($post[$map['topic']] ?? ''), 60);
    $message = kwetu_clean_text((string) ($post[$map['message']] ?? ''), 5000);

    if ($name === '') {
        $errors[$map['full_name']] = 'Your name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors[$map['full_name']] = 'Please write your full name.';
    }

    if ($business === '') {
        $business = null;
    }

    if ($email === '') {
        $errors[$map['email']] = 'An email address is required so we can reply.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
        $errors[$map['email']] = 'That email address does not look valid.';
    }

    if ($kind === 'quote') {
        if ($business === null) {
            $errors[$map['business']] = 'Please give the business this quote is for.';
        }

        if ($phone === '') {
            $errors[$map['phone']] = 'A contact number is required for a quote request.';
        }
    }

    if ($phone !== '' && preg_match('/^[+0-9][0-9 ()\/.-]{5,39}$/', $phone) !== 1) {
        $errors[$map['phone']] = 'Please use digits, spaces and + ( ) - only.';
    }

    if (mb_strlen($address ?? '') > 250) {
        $errors[$map['address']] = 'Please keep the address under 250 characters.';
    }

    if (!in_array($topic, kwetu_enquiry_topics($kind), true)) {
        $errors[$map['topic']] = 'Please choose one of the listed options.';
    }

    $length = mb_strlen($message);

    if ($message === '') {
        $errors[$map['message']] = 'Please tell us what you need.';
    } elseif ($length < 10) {
        $errors[$map['message']] = 'Please write at least 10 characters.';
    }

    return [$errors, [
        'kind' => $kind,
        'full_name' => $name,
        'business' => $business === '' ? null : $business,
        'email' => $email,
        'phone' => $phone === '' ? null : $phone,
        'address' => ($address ?? '') === '' ? null : $address,
        'topic' => $topic,
        'message' => $message,
    ]];
}
