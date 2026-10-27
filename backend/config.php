<?php
/**
 * Loads this installation's private settings. Every backend file includes
 * this one instead of reading local-config.php directly.
 */

declare(strict_types=1);

function kwetu_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/local-config.php';

    if (!is_readable($path)) {
        throw new RuntimeException(
            'backend/local-config.php is missing. Copy backend/local-config.sample.php to '
            . 'backend/local-config.php and set the database login before using the intake form.'
        );
    }

    $loaded = include $path;

    if (!is_array($loaded)) {
        throw new RuntimeException('backend/local-config.php must return a settings array.');
    }

    $defaults = [
        'db' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'kwetu_testimonials',
            'user' => 'root',
            'password' => '',
        ],
        'ip_salt' => '',
        'admin_password_hash' => null,

        // Submissions allowed per salted IP fingerprint per rolling 24 hours.
        // These are anti-spam ceilings, not security: every other guard
        // (CSRF, same-origin, honeypot, fill time, file type checks) stays in
        // force whatever these say. A shared office address or a mobile
        // carrier's exit IP is ONE fingerprint, so a cap that is too tight
        // turns real clients away — these two numbers are set for that.
        //
        // TESTIMONIALS: 8 per fingerprint per day.
        'daily_limit_per_ip' => 8,
        'minimum_fill_seconds' => 4,
        // ENQUIRIES (contact and quote together): 10 per fingerprint per day.
        'enquiry_daily_limit_per_ip' => 10,

        // Attachments on a quote request: four files, 8 MB each.
        //
        // PHP answers for itself before this application sees a file, so
        // these limits only mean something when php.ini is at least as
        // generous. The three settings required to accept 4 files of 8 MB:
        //     upload_max_filesize >= 8M
        //     post_max_size       >= 40M   (4 x 8M of files plus the form text)
        //     max_file_uploads    >= 4     (PHP ships with 20)
        // If the host allows less, PHP rejects the file first and the visitor
        // gets "larger than this server accepts" — lower these numbers to
        // match the host rather than raising them and hoping.
        'max_upload_files' => 4,
        'max_upload_bytes' => 8388608,

        // Where uploaded design files are written.
        //
        // Empty means backend/storage, which is the folder this project has
        // always used and is what local XAMPP should keep: nothing has to be
        // created elsewhere and backend/storage/.htaccess already refuses to
        // serve it.
        //
        // On real hosting, point this outside the folder the web server
        // publishes, so no address can name a file at all. Typical shapes:
        //     '../kwetu-uploads'      (a sibling of public_html)
        //     '/home/ACCOUNT/kwetu-uploads'
        // A relative path is read from the site root. The folder is created
        // if it is missing and must be writable by PHP.
        'upload_dir' => '',

        // Empty means the review screens accept a sign-in from any address and
        // rely on the password alone. Fill it with exact IPv4/IPv6 addresses or
        // IPv4 ranges in CIDR form to narrow that down.
        'admin_allow_from' => [],
        'login_max_failures' => 8,
        'login_window_seconds' => 900,
        'login_lockout_seconds' => 900,

        // Shortest password the review desk will accept, from either door:
        // the Change Password screen and admin/set-password.php both read this
        // one number, so a short password cannot slip in through the terminal.
        'admin_password_min_length' => 12,

        // How long a signed-in review screen stays open for.
        //
        // PHP's own garbage collector would drop the session file after about
        // 24 quiet minutes, but that is a side effect of a setting meant for
        // disk cleanup — it does not bound the total time, so a reviewer who
        // keeps clicking could stay signed in for a week on a shared laptop.
        // These two are checked in PHP on every admin page instead: the first
        // closes an unattended tab, the second forces a fresh sign-in once a
        // day however active the tab has been.
        'admin_session_idle_seconds' => 1800,
        'admin_session_absolute_seconds' => 28800,

        // Email notification of new submissions. Off until someone puts real
        // account details here, because nothing is guessed on your behalf.
        'mail' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'security' => 'starttls',
            'username' => '',
            'password' => '',
            'from' => '',
            'from_name' => 'Kwetu Creations website',
            'to' => [],
        ],
    ];

    $config = array_replace_recursive($defaults, $loaded);

    if ($config['ip_salt'] === '') {
        throw new RuntimeException(
            'Set a private ip_salt in backend/local-config.php. Run: '
            . 'php backend/make-secret.php'
        );
    }

    return $config;
}

function kwetu_dsn(array $db): string
{
    return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']);
}
