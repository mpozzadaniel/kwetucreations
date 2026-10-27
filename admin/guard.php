<?php
/**
 * Every page in admin/ includes this first. It decides whether the person
 * looking is allowed to be here and holds the shared pieces the screens are
 * built from: the sign-in throttle, their token, the escaping helper, the live
 * counts. The HTML those screens are wrapped in lives in shell.php, which this
 * file hands over to at the bottom, so the layout can change without touching
 * the rules about who is allowed to see it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

function kwetu_admin_hash_file(): string
{
    return dirname(__DIR__) . '/backend/admin-hash.php';
}

function kwetu_admin_is_configured(): bool
{
    return is_readable(kwetu_admin_hash_file());
}

function kwetu_admin_password_ok(string $candidate): bool
{
    if (!kwetu_admin_is_configured()) {
        return false;
    }

    $hash = include kwetu_admin_hash_file();

    return is_string($hash) && password_verify($candidate, $hash);
}

/**
 * When the password file last changed.
 *
 * Every signed-in session remembers this value. Change the password — from
 * the Change Password screen or by running the terminal script — and the
 * value moves, so every other open session is refused the next time it asks
 * for anything. It is the file's own clock rather than a table of sessions
 * because there is no session index to maintain here, and a list of live
 * identifiers would be a second thing to leak.
 */
function kwetu_admin_credential_stamp(): int
{
    clearstatcache(true, kwetu_admin_hash_file());

    return (int) @filemtime(kwetu_admin_hash_file());
}

/**
 * The shortest password worth accepting, in characters.
 *
 * One number for both doors: the screen and the terminal script refuse the
 * same way, so nothing can be set short here and then quietly reset long.
 */
function kwetu_admin_password_min_length(): int
{
    $config = kwetu_config();

    return max(8, (int) ($config['admin_password_min_length'] ?? 12));
}

/**
 * Why this password is not acceptable, or '' when it is.
 *
 * The upper bound is not caution about length for its own sake: bcrypt stops
 * reading after 72 bytes, so a longer answer would be stored as a truncated
 * one and the owner would be typing something only partly remembered. Refusing
 * it is honest; accepting it would not be.
 */
function kwetu_admin_password_problem(string $candidate): string
{
    $minimum = kwetu_admin_password_min_length();

    if ($candidate === '') {
        return 'Type the new password.';
    }

    if (trim($candidate) !== $candidate) {
        return 'A password cannot start or end with a space — they are easy to lose when typing it again.';
    }

    if (strlen($candidate) < $minimum) {
        return 'A password needs at least ' . $minimum . ' characters.';
    }

    if (strlen($candidate) > 72) {
        return 'A password cannot be longer than 72 characters. PHP would store only the first 72 of them.';
    }

    return '';
}

/**
 * Replace the stored hash. The plain password is used and dropped here; what
 * goes into the file is password_hash() output and nothing else.
 */
function kwetu_admin_password_write(string $plain): bool
{
    $target = kwetu_admin_hash_file();
    $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));

    // Write-then-rename, so a crash halfway cannot leave the desk with a
    // half-written hash and no way in.
    $written = file_put_contents(
        $temporary,
        "<?php /* review-screen password hash; never print or publish this file */ return "
            . var_export(password_hash($plain, PASSWORD_DEFAULT), true) . ";\n",
        LOCK_EX
    );

    if ($written === false) {
        @unlink($temporary);

        return false;
    }

    @chmod($temporary, 0600);

    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        error_log('kwetu admin: the new password hash could not be put in place.');

        return false;
    }

    return true;
}

/**
 * Private screens must never sit in a browser's memory.
 *
 * PHP's default session cache limiter already sends something similar on this
 * machine, but it is a php.ini setting - a host that ships with
 * session.cache_limiter = "" would happily let a signed-in inbox land in the
 * cache of the laptop it was opened on. Saying it here removes the dependency.
 */
function kwetu_admin_no_store(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * One message that survives a redirect, so a screen can answer a POST by
 * sending the browser somewhere else and still tell the reviewer what happened.
 */
function kwetu_admin_flash_put(string $text, string $kind = 'bad'): void
{
    kwetu_session_start();
    $_SESSION['admin_flash'] = ['text' => $text, 'kind' => $kind];
}

function kwetu_admin_flash_take(): array
{
    kwetu_session_start();

    $flash = $_SESSION['admin_flash'] ?? null;

    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : ['text' => '', 'kind' => 'bad'];
}

function kwetu_admin_login_required(): void
{
    if (!kwetu_admin_ip_allowed()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "This address is not on the list allowed to reach the review screens.\n";
        echo "If you are the site owner, add it to admin_allow_from in backend/local-config.php.\n";
        exit;
    }

    kwetu_admin_no_store();
    kwetu_session_start();

    if (empty($_SESSION['admin_ok'])) {
        header('Location: login.php');
        exit;
    }

    $config = kwetu_config();
    $now = time();
    $idle = max(300, (int) ($config['admin_session_idle_seconds'] ?? 1800));
    $absolute = max(900, (int) ($config['admin_session_absolute_seconds'] ?? 28800));
    $signedInAt = (int) ($_SESSION['admin_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['admin_seen'] ?? $signedInAt);

    $outdated = $signedInAt === 0
        ? 'That session was started before sign-in timing was recorded.'
        : (($now - $lastSeen) > $idle
            ? 'You were signed out because the screen sat unused for ' . intdiv($idle, 60) . ' minutes.'
            : 'You were signed out after ' . intdiv($absolute, 3600) . ' hours, however busy the tab was.');

    if ($signedInAt === 0
        || ($now - $lastSeen) > $idle
        || ($now - $signedInAt) > $absolute) {
        // The reviewer's own identity in the session goes, the identifier is
        // issued again so the old one cannot be replayed, and only the
        // explanation is carried across - nothing they had read stays behind.
        $_SESSION = ['admin_flash' => ['text' => $outdated, 'kind' => 'bad']];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    // The password is the reason this session exists. If it has been changed
    // since the session was signed in — here, or from a terminal — the session
    // is over, wherever it was opened.
    $stamp = kwetu_admin_credential_stamp();

    if ($stamp === 0 || (int) ($_SESSION['admin_cred'] ?? -1) !== $stamp) {
        $_SESSION = [
            'admin_flash' => [
                'text' => $stamp === 0
                    ? 'That session could not be checked against the current password, so it ended.'
                    : 'You were signed out because the review password was changed.',
                'kind' => 'bad',
            ],
        ];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    $_SESSION['admin_seen'] = $now;
}

/**
 * Optional source-address restriction, set in backend/local-config.php.
 *
 * An empty list means the password is the only gate. That is acceptable over
 * HTTPS with a strong password; it is not acceptable over plain HTTP, because
 * the password then travels in the clear and anyone on the same wifi can read
 * it. Nothing here can detect that for you — check the address bar says https.
 *
 * Exact IPv4 and IPv6 addresses match directly; CIDR ranges are IPv4 only.
 */
function kwetu_admin_ip_allowed(): bool
{
    $rules = (array) (kwetu_config()['admin_allow_from'] ?? []);

    if ($rules === []) {
        return true;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if ($ip === '') {
        return false;
    }

    foreach ($rules as $rule) {
        $rule = trim((string) $rule);

        if ($rule === '') {
            continue;
        }

        if (strpos($rule, '/') === false) {
            if ($ip === $rule) {
                return true;
            }

            continue;
        }

        if (kwetu_admin_ip_in_cidr($ip, $rule)) {
            return true;
        }
    }

    return false;
}

function kwetu_admin_ip_in_cidr(string $ip, string $cidr): bool
{
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '');

    $ipPacked = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
    $netPacked = filter_var($network, FILTER_VALIDATE_IP) === false ? false : inet_pton((string) $network);

    if ($ipPacked === false || $netPacked === false || strlen($ipPacked) !== strlen($netPacked)) {
        return false;
    }

    $bits = (int) $bits;
    $width = strlen($ipPacked) * 8;

    if ($bits < 0 || $bits > $width) {
        return false;
    }

    $mask = str_repeat("\xff", intdiv($bits, 8));
    $remainder = $bits % 8;

    if ($remainder !== 0) {
        $mask .= chr((0xff << (8 - $remainder)) & 0xff);
    }

    $mask = str_pad($mask, strlen($ipPacked), "\x00");

    return ($ipPacked & $mask) === ($netPacked & $mask);
}

/**
 * Seconds left on a sign-in lockout for this address, or 0 when it is free.
 *
 * Counted against the same salted address fingerprint the forms use, so it
 * survives someone closing their cookies — which is exactly what the session
 * counter on its own could not do once these pages were reachable from
 * anywhere.
 */
function kwetu_admin_lock_seconds(PDO $pdo, string $ipHash): int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS left_seconds
               FROM admin_login_attempts
              WHERE ip_hash = :ip'
        );
        $stmt->execute([':ip' => $ipHash]);
        $left = $stmt->fetchColumn();
    } catch (Throwable $error) {
        // Usually means backend/setup-database.php has not been run since this
        // table was added. The session counter below still applies.
        error_log('kwetu admin throttle unavailable: ' . $error->getMessage());

        return 0;
    }

    return $left === false || $left === null ? 0 : max(0, (int) $left);
}

/** Record one wrong password against this address. */
function kwetu_admin_record_failure(PDO $pdo, string $ipHash): void
{
    $config = kwetu_config();

    // Both are integers from this installation's own settings, so they are
    // interpolated rather than bound — MySQL cannot take a bound parameter
    // for an interval. Everything user-supplied still goes through a placeholder.
    $window = max(60, (int) $config['login_window_seconds']);
    $maximum = max(3, (int) $config['login_max_failures']);
    $lockout = max(60, (int) $config['login_lockout_seconds']);

    try {
        $stmt = $pdo->prepare(
            'SELECT hits FROM admin_login_attempts
              WHERE ip_hash = :ip AND window_started_at > (NOW() - INTERVAL ' . $window . ' SECOND)'
        );
        $stmt->execute([':ip' => $ipHash]);
        $found = $stmt->fetchColumn();

        if ($found === false) {
            $pdo->prepare(
                'REPLACE INTO admin_login_attempts (ip_hash, hits, window_started_at, locked_until)
                     VALUES (:ip, 1, NOW(), NULL)'
            )->execute([':ip' => $ipHash]);

            return;
        }

        $hits = (int) $found + 1;

        // Two statements instead of one CASE expression: with emulated prepares
        // off, MySQL refuses a named parameter that appears twice in one
        // statement, which is what stopped this counter from ever reaching the
        // threshold. The interval is an integer from this installation's own
        // settings, so it is cast and interpolated rather than bound.
        $lockout = (int) $lockout;

        if ($hits >= $maximum) {
            $pdo->prepare(
                'UPDATE admin_login_attempts
                    SET hits = ?, locked_until = DATE_ADD(NOW(), INTERVAL ' . $lockout . ' SECOND)
                  WHERE ip_hash = ?'
            )->execute([$hits, $ipHash]);
        } else {
            $pdo->prepare(
                'UPDATE admin_login_attempts
                    SET hits = ?, locked_until = NULL
                  WHERE ip_hash = ?'
            )->execute([$hits, $ipHash]);
        }
    } catch (Throwable $error) {
        error_log('kwetu admin throttle could not record a failure: ' . $error->getMessage());
    }
}

/** A correct password wipes the address's counter. */
function kwetu_admin_clear_failures(PDO $pdo, string $ipHash): void
{
    try {
        $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip')
            ->execute([':ip' => $ipHash]);
    } catch (Throwable $error) {
        error_log('kwetu admin throttle could not clear: ' . $error->getMessage());
    }
}

/**
 * The review screens keep their own token, in their own session slot.
 *
 * The public forms cannot bake a token into static HTML, so they fetch one
 * from backend/csrf-token.php, which re-issues $_SESSION['csrf'] every time.
 * Without this separate slot, loading contact.html or testimonials.html in
 * another tab wiped the token the sign-in page had already been given, and
 * the reviewer was told the form had expired however often they reloaded.
 *
 * An aged token is replaced here, on the page load, rather than left where it
 * is. kwetu_admin_csrf_valid() refuses anything older than the window below,
 * so a stale value that survived this function would be handed straight back
 * to the form on every reload: the reviewer would be told the form had
 * expired, reload, be told again, and the only way out would be to clear the
 * session cookie by hand. That is the dead end this renews its way out of.
 */
const KWETU_ADMIN_CSRF_SECONDS = 3600;

function kwetu_admin_csrf(): string
{
    kwetu_session_start();

    $issued = (int) ($_SESSION['admin_csrf_at'] ?? 0);

    if (empty($_SESSION['admin_csrf']) || (time() - $issued) > KWETU_ADMIN_CSRF_SECONDS) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['admin_csrf_at'] = time();
    }

    return (string) $_SESSION['admin_csrf'];
}

function kwetu_admin_csrf_valid(?string $candidate): bool
{
    $stored = kwetu_admin_csrf();

    if ($candidate === null || $candidate === '') {
        return false;
    }

    if (time() - (int) ($_SESSION['admin_csrf_at'] ?? 0) > KWETU_ADMIN_CSRF_SECONDS) {
        return false;
    }

    return hash_equals($stored, $candidate);
}

function kwetu_admin_csrf_reset(): void
{
    kwetu_session_start();
    unset($_SESSION['admin_csrf'], $_SESSION['admin_csrf_at']);
}

function kwetu_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Stars drawn from the rating the client actually gave. Nothing in admin/
 * can produce a five-star mark that nobody awarded.
 */
function kwetu_stars(int $rating): string
{
    $rating = max(0, min(5, $rating));

    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}

/**
 * Live totals for the sidebar badges and the dashboard cards.
 *
 * Counted from the same two tables the screens list, so a number on the
 * dashboard can never disagree with the queue behind it.
 *
 * A status with no rows is counted as zero, not left blank: a GROUP BY answer
 * that never mentions "rejected" means nothing was rejected, which is a
 * different statement from the database being unreadable. Blanks - drawn as
 * dashes - only appear when the query itself could not run.
 */
function kwetu_admin_counts(): array
{
    $unknown = [
        'readable' => false,
        'contact' => ['new' => null, 'handled' => null, 'archived' => null],
        'quote' => ['new' => null, 'handled' => null, 'archived' => null],
        'testimonial' => ['pending' => null, 'approved' => null, 'rejected' => null],
        'attachments' => null,
    ];

    try {
        $pdo = kwetu_db();

        $counts = $unknown;
        $counts['readable'] = true;
        $counts['contact'] = ['new' => 0, 'handled' => 0, 'archived' => 0];
        $counts['quote'] = ['new' => 0, 'handled' => 0, 'archived' => 0];
        $counts['testimonial'] = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

        $enquiries = $pdo->query(
            'SELECT kind, status, COUNT(*) hits FROM enquiries GROUP BY kind, status'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($enquiries as $row) {
            $group = $row['kind'] === 'quote' ? 'quote' : 'contact';

            if (array_key_exists($row['status'], $counts[$group])) {
                $counts[$group][$row['status']] = (int) $row['hits'];
            }
        }

        $testimonials = $pdo->query(
            'SELECT status, COUNT(*) hits FROM testimonial_submissions GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($testimonials as $row) {
            if (array_key_exists($row['status'], $counts['testimonial'])) {
                $counts['testimonial'][$row['status']] = (int) $row['hits'];
            }
        }

        $counts['attachments'] = (int) $pdo->query('SELECT COUNT(*) FROM enquiry_files')->fetchColumn();

        return $counts;
    } catch (Throwable $failure) {
        error_log('kwetu admin counts: ' . $failure->getMessage());

        return $unknown;
    }
}

require_once __DIR__ . '/shell.php';
