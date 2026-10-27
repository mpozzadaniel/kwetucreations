<?php
/**
 * Sets the review password from a terminal. This is the recovery route, and
 * the only one: there is no forgotten-password page and no reset by email,
 * because a public endpoint that changes this password without proving who is
 * asking would be a second door into the desk. Whoever can open a terminal on
 * the machine that hosts the site is the owner of it.
 *
 *   php admin/set-password.php            generates a strong one and prints it once
 *   php admin/set-password.php --choose   type your own (it shows as you type)
 *   php admin/set-password.php --password=whatever
 *
 * Same rules as the Change Password screen, from the same two helpers: the
 * minimum length comes from backend/config.php and only ever a password_hash()
 * result is written, atomically, to backend/admin-hash.php — a file the
 * .htaccess in backend/ refuses to serve.
 *
 * Running it also ends every review session that is open, on any device: each
 * session remembers when that file last changed, and this replaces it. That is
 * the safety net for a password that was written down somewhere it should not
 * have been. If only the password needs changing and you are already signed in,
 * use the Change Password screen instead — it is the same write.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script is command-line only.\n";
    echo "Run it from a terminal:  php admin/set-password.php\n";
    exit(1);
}

require_once __DIR__ . '/guard.php';

$args = array_slice($argv, 1);
$password = null;

foreach ($args as $arg) {
    if (strncmp($arg, '--password=', 11) === 0) {
        $password = substr($arg, 11);
    }
}

if ($password === null && in_array('--choose', $args, true)) {
    fwrite(STDOUT, 'Type a password for the review screen (it will be visible as you type): ');
    $password = rtrim((string) fgets(STDIN), "\r\n");
}

$generated = false;

if ($password === null) {
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%*';
    $chars = [];

    for ($i = 0; $i < max(18, kwetu_admin_password_min_length()); $i++) {
        $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $password = implode('', $chars);
    $generated = true;
}

if ($password === '') {
    fwrite(STDERR, "Refused: nothing was given as a password.\n");
    exit(1);
}

$problem = kwetu_admin_password_problem($password);

if ($problem !== '') {
    fwrite(STDERR, 'Refused: ' . $problem . "\n");
    exit(1);
}

if (!kwetu_admin_password_write($password)) {
    fwrite(STDERR, "Could not write the password hash. Check that backend/ is writable by whoever runs this.\n");
    exit(1);
}

fwrite(STDOUT, "\nPassword hash written to backend/admin-hash.php\n\n");
fwrite(STDOUT, 'Password: ' . $password . "\n");

if ($generated) {
    fwrite(STDOUT, "Copy it now. It is not stored anywhere and cannot be recovered,\n");
    fwrite(STDOUT, "only replaced by running this script again.\n");
}

fwrite(STDOUT, "\nEvery review screen that is signed in is now signed out.\n");
