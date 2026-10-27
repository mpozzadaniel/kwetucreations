<?php
/**
 * Is the site's mail account ready, and does it actually send?
 *
 * Run from a terminal, never from a browser:
 *
 *     php tools/check-mail.php          read-only, sends nothing
 *     php tools/check-mail.php --send   also posts one test note to mail.to
 *
 * The read-only pass exists because the alternative was editing
 * backend/local-config.php, submitting a fake enquiry through the form and
 * reading the Apache log to find out which line was blank. Nothing here
 * prints a password, and nothing here connects unless a host is named.
 *
 * --send goes through kwetu_notify(), the same function a real submission
 * uses, so a pass means the team would have been told about that submission.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

require_once dirname(__DIR__) . '/backend/config.php';
require_once dirname(__DIR__) . '/backend/notify.php';

$send = in_array('--send', array_slice($argv ?? [], 1), true);
$fail = 0;

function line(string $mark, string $text): void
{
    global $fail;
    if ($mark === 'FAIL') {
        $fail++;
    }
    printf("%-5s %s\n", $mark, $text);
}

echo "Kwetu Creations mail check\n";
echo str_repeat('-', 62) . "\n";

// Does the real config file exist yet?
$path = dirname(__DIR__) . '/backend/local-config.php';
if (!is_readable($path)) {
    line('FAIL', 'backend/local-config.php is missing. Copy backend/local-config.sample.php to it first.');
    exit(1);
}
line('OK', 'backend/local-config.php is readable.');

$mail = kwetu_config()['mail'] ?? [];
if ($mail === []) {
    line('FAIL', 'local-config.php has no "mail" block at all. Add one from the sample file.');
    exit(1);
}

// Secrets are described by length only. A password that is wrong is nearly as
// expensive to find as one that is missing, so the count helps without leaking.
foreach (['host', 'port', 'security', 'username', 'password', 'from'] as $key) {
    $value = trim((string) ($mail[$key] ?? ''));

    if ($key === 'password') {
        line($value === '' ? 'FAIL' : 'OK', $value === ''
            ? 'mail.password is empty — use an app password from the mail provider, not the login password.'
            : 'mail.password is set (' . strlen($value) . ' characters, not shown).');
        continue;
    }

    line($value === '' ? 'FAIL' : 'OK', 'mail.' . $key . ' = ' . ($value === '' ? '(empty)' : $value));
}

$from   = trim((string) ($mail['from'] ?? ''));
$to     = kwetu_mail_recipients();

if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
    line('FAIL', 'mail.from is not a valid address.');
}
if ($to === []) {
    line('FAIL', 'mail.to holds no valid address, so there is nobody to notify.');
} else {
    line('OK', 'mail.to = ' . implode(', ', $to));
}

// Must mirror kwetu_mail_ready(), which is what the live endpoints consult.
$enabled = !empty($mail['enabled']);
line($enabled ? 'OK' : 'FAIL', 'mail.enabled = ' . ($enabled ? 'true' : 'false — submissions are saved but nobody is emailed.'));

$secure = strtolower(trim((string) ($mail['security'] ?? '')));
if ($secure === 'starttls' || $secure === 'ssl') {
    line('OK', 'mail.security is a TLS transport, so the login is encrypted.');
} else {
    line('FAIL', 'mail.security must be starttls (port 587) or ssl (port 465). Plain SMTP puts the password on the wire.');
}

if (!function_exists('curl_init')) {
    line('FAIL', 'This PHP has no cURL extension; notify.php cannot reach any server without it.');
}

// Only reach outward when a host is actually named, so an untouched sample
// cannot trigger a connection to smtp.example.com.
$host = trim((string) ($mail['host'] ?? ''));
if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && !checkdnsrr($host, 'A')) {
    line('FAIL', $host . ' does not resolve. Check the hostname the provider gave you.');
} elseif ($host !== '') {
    $port   = (int) ($mail['port'] ?? 0);
    $socket = @fsockopen($host, $port, $errorNumber, $errorString, 6.0);

    if ($socket === false) {
        line('FAIL', 'Cannot open ' . $host . ':' . $port . ' — ' . $errorString
            . ' (a blocked port 587/465 is usually the hosting provider, not you).');
    } else {
        stream_set_timeout($socket, 4);
        $banner = trim((string) fgets($socket, 512));
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        line($banner !== '' && str_starts_with($banner, '220') ? 'OK' : 'WARN',
            $host . ':' . $port . ' answered: ' . ($banner === '' ? '(no greeting, but the port is open)' : $banner));
    }
}

echo str_repeat('-', 62) . "\n";

if (!$send) {
    line('INFO', 'Nothing was sent. Run again with --send to post one test note to mail.to.');
    exit($fail === 0 ? 0 : 1);
}

if (!$enabled || $host === '' || trim((string) ($mail['password'] ?? '')) === '') {
    line('FAIL', '--send refuses to run while the checks above have failed.');
    exit(1);
}

$sent = kwetu_notify(
    'Kwetu website mail check (test)',
    "This is the test message from php tools/check-mail.php --send.\n\n"
        . "If you can read this, the website can email the team when a contact form,\n"
        . "quote request or testimonial arrives. Delete it; it is not an enquiry.\n"
);

line($sent ? 'OK' : 'FAIL', $sent
    ? 'kwetu_notify() returned true — the server took the message for ' . implode(', ', $to) . '.'
    : 'kwetu_notify() returned false. The Apache error log has the reason, and a submission would still have been saved.');

exit($fail === 0 && $sent ? 0 : 1);
