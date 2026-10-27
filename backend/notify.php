<?php
/**
 * Optional email notification to the team when something is stored.
 *
 * Off until backend/local-config.php has a real mail account in it, because
 * nothing here guesses a provider, an address or a password. When it is off,
 * or when the send fails, the submission is still saved and still reported as
 * saved: a missed notification is a note in the server log, not a lie told to
 * the visitor and not a reason to throw their typing away.
 *
 * SMTP goes through cURL rather than PHP's mail() because mail() on Windows
 * needs its own registry setup, cannot authenticate, and is the first thing a
 * spammer looks at on a shared host.
 */

declare(strict_types=1);

/**
 * Is notification switched on and complete enough to try?
 *
 * Every rule here exists so that a half-configured mail account stays silent
 * rather than sending anything: no login, no password, no recipient, no
 * server name, or any transport that is not TLS all mean "do not send".
 * A submission is still saved in every one of those cases.
 */
function kwetu_mail_ready(): bool
{
    $mail = kwetu_config()['mail'] ?? [];

    if (empty($mail['enabled'])) {
        return false;
    }

    foreach (['host', 'from', 'username', 'password'] as $key) {
        if (trim((string) ($mail[$key] ?? '')) === '') {
            error_log('kwetu notify: mail is enabled but ' . $key . ' is not set in backend/local-config.php, so nothing was sent.');

            return false;
        }
    }

    $secure = strtolower(trim((string) ($mail['security'] ?? '')));

    if ($secure !== 'starttls' && $secure !== 'ssl') {
        error_log('kwetu notify: refusing to send. Set mail.security to starttls (port 587) or ssl (port 465); "'
            . ($secure === '' ? 'unset' : $secure) . '" would put the login and the enquiry on the wire unreadable.');

        return false;
    }

    $recipients = kwetu_mail_recipients();

    if ($recipients === []) {
        error_log('kwetu notify: mail is enabled but mail.to holds no valid address, so nothing was sent.');

        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('kwetu notify: this PHP has no cURL, so it cannot reach the mail server.');

        return false;
    }

    return true;
}

/** @return string[] */
function kwetu_mail_recipients(): array
{
    $to = kwetu_config()['mail']['to'] ?? [];

    if (is_string($to)) {
        $to = [$to];
    }

    $out = [];

    foreach ((array) $to as $address) {
        $address = trim((string) $address);

        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
            $out[] = $address;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Anything going into a header line, stripped of the characters that would
 * otherwise let a visitor add headers of their own.
 */
function kwetu_header_value(string $raw, int $limit = 200): string
{
    $oneLine = preg_replace('/[\r\n\x00]+/', ' ', $raw);

    return mb_substr(trim((string) $oneLine), 0, $limit);
}

/**
 * Send one plain-text message. Returns true only when the server accepted it.
 *
 * Deliberately never throws: callers are on the way out of a successful
 * submission and must not have their answer changed by a mail problem.
 */
function kwetu_notify(string $subject, string $body, ?string $replyTo = null): bool
{
    if (!kwetu_mail_ready()) {
        return false;
    }

    $mail = kwetu_config()['mail'];

    $from = kwetu_header_value((string) $mail['from'], 190);
    $fromName = kwetu_header_value((string) ($mail['from_name'] ?? 'Kwetu Creations website'), 120);
    $recipients = kwetu_mail_recipients();

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: "' . str_replace('"', '', $fromName) . '" <' . $from . '>',
        'To: ' . implode(', ', $recipients),
        'Subject: ' . kwetu_header_value($subject, 180),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@kwetucreations.local>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
        'X-Kwetu: website-intake',
    ];

    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body);

    $secure = strtolower((string) ($mail['security'] ?? 'starttls'));
    $port = (int) ($mail['port'] ?? ($secure === 'ssl' ? 465 : 587));
    $scheme = $secure === 'ssl' ? 'smtps' : 'smtp';

    $handle = curl_init($scheme . '://' . $mail['host'] . ':' . $port);

    if ($handle === false) {
        error_log('kwetu notify: cURL could not open the SMTP connection.');
        return false;
    }

    $options = [
        CURLOPT_MAIL_FROM => $from,
        CURLOPT_MAIL_RCPT => $recipients,
        CURLOPT_POSTFIELDS => $message,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    $options[CURLOPT_USERNAME] = (string) $mail['username'];
    $options[CURLOPT_PASSWORD] = (string) $mail['password'];
    $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;

    // kwetu_mail_ready() only lets as far as starttls or ssl, so the login is
    // always carried over an encrypted connection. An unencrypted SMTP send
    // is not offered as a choice any more.
    $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;

    curl_setopt_array($handle, $options);

    $sent = curl_exec($handle);
    $error = curl_error($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    curl_close($handle);

    if ($sent === false) {
        error_log('kwetu notify failed: ' . ($error !== '' ? $error : 'SMTP response ' . $code));

        return false;
    }

    return true;
}

/**
 * The message the team receives. Plain text, because that is what survives
 * every mail client and cannot hide a link someone did not mean to click.
 */
function kwetu_notify_submission(array $row, string $heading, array $extra = []): bool
{
    $subject = trim((string) ($row['Subject name'] ?? '')) !== ''
        ? (string) $row['Subject name']
        : preg_replace('/\s+/', ' ', mb_substr($heading, 0, 90));

    unset($row['Subject name']);

    $lines = [$heading, ''];

    foreach ($row as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $lines[] = $label . ': ' . str_replace("\n", ' ', (string) $value);
    }

    foreach ($extra as $label => $value) {
        $lines[] = $label . ': ' . str_replace("\n", ' ', (string) $value);
    }

    return kwetu_notify(
        (string) $subject,
        implode("\r\n", $lines) . "\r\n\r\n-- \r\nRead and answer it in admin/: "
            . 'the enquiries inbox lists everything stored on this server.',
        isset($row['Email']) && filter_var($row['Email'], FILTER_VALIDATE_EMAIL) !== false
            ? (string) $row['Email']
            : null
    );
}
