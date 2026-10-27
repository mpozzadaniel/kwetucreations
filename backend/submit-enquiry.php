<?php
/**
 * POST backend/submit-enquiry.php
 *
 * Receives both enquiry forms — contact.html (kind=contact) and
 * quote-request.html (kind=quote) — and stores them for a person to read.
 *
 * A 201 here means a row exists in the enquiries table, and, for a quote,
 * that every attached file was accepted too. If a file is refused the whole
 * submission is refused, because "your message arrived" next to a silently
 * dropped artwork file is not an answer anyone could act on.
 *
 * Nothing is sent anywhere automatically. No mail is queued, no autoresponder
 * runs. The team reads this table in admin/inbox.php and replies themselves.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/notify.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    header('Allow: POST');
    kwetu_respond(405, [
        'saved' => false,
        'reason' => 'This address only accepts a form submission sent with POST.',
    ]);
}

$config = kwetu_config();

// A request bigger than post_max_size arrives with no POST data at all, which
// would otherwise look like an expired session rather than an oversized one.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
    $cap = (string) (ini_get('post_max_size') ?: '40M');

    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'That submission was larger than this server accepts (' . $cap . ' at most). '
            . 'Please remove some attachments and send it again.',
    ]);
}

$headers = kwetu_headers();

if (!kwetu_same_origin($headers)) {
    kwetu_respond(403, [
        'saved' => false,
        'reason' => 'This form can only be submitted from the kwetucreations site.',
    ]);
}

if (!kwetu_csrf_is_valid($_POST['csrf_token'] ?? null)) {
    kwetu_respond(403, [
        'saved' => false,
        'field' => 'csrf_token',
        'reason' => 'Your session expired before you finished. Ask for a fresh form and send it again.',
    ]);
}

$kind = (string) ($_POST['kind'] ?? '');

if ($kind !== 'contact' && $kind !== 'quote') {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'This endpoint only takes a contact enquiry or a quote request.',
    ]);
}

// A field real visitors never see. Anything that fills it is a bot reading
// the markup, so the submission is discarded — and the reply still says
// nothing was saved, because nothing was.
if (kwetu_clean_text((string) ($_POST['company_website'] ?? ''), 200) !== '') {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'Your submission was not saved.',
    ]);
}

$started = (int) ($_POST['form_started_at'] ?? 0);

if ($started > 0 && (time() - $started) < (int) $config['minimum_fill_seconds']) {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'That was faster than a person types. Please fill the form in yourself.',
    ]);
}

[$errors, $values] = kwetu_validate_enquiry($kind, $_POST);

if ($errors !== []) {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'Some answers still need attention.',
        'fields' => $errors,
    ]);
}

// The connection and the count sit in their own try because everything after
// this point already reports a failure honestly. A database that will not open
// must produce one plain JSON sentence, never a PHP error page.
try {
    $pdo = kwetu_db();
} catch (Throwable $couldNotConnect) {
    error_log('kwetu enquiry not stored: ' . $couldNotConnect->getMessage());

    kwetu_respond(500, [
        'saved' => false,
        'reason' => $kind === 'quote'
            ? 'We could not store your quote request. Please try again, or write to info@kwetucreations.com.'
            : 'We could not store your message. Please try again, or write to info@kwetucreations.com.',
    ]);
}

$ipHash = kwetu_ip_hash();
$limit = (int) $config['enquiry_daily_limit_per_ip'];

try {
    $recent = kwetu_recent_count($pdo, $ipHash, 'enquiries');
} catch (Throwable $couldNotCount) {
    error_log('kwetu enquiry count failed: ' . $couldNotCount->getMessage());
    $recent = $limit;
}

if ($recent >= $limit) {
    kwetu_respond(429, [
        'saved' => false,
        'reason' => 'This device has already sent several enquiries today. Please write to '
            . 'info@kwetucreations.com or call +256 787 738 999 instead.',
    ]);
}

// Attachments first, so a refused file stops everything before a row is
// written. Files that did land are removed again if anything else fails.
$savedFiles = [];
$fileErrors = [];

if ($kind === 'quote' && isset($_FILES['designFiles'])) {
    // A storage folder that cannot be written is a server problem, not the
    // visitor's: the answer says plainly that nothing was stored, and the
    // reason, which contains a path, only goes to the log.
    try {
        [$savedFiles, $fileErrors] = kwetu_receive_uploads(
            $_FILES['designFiles'],
            (int) $config['max_upload_files'],
            (int) $config['max_upload_bytes']
        );
    } catch (Throwable $couldNotStoreFiles) {
        error_log('kwetu upload storage unavailable: ' . $couldNotStoreFiles->getMessage());
        kwetu_respond(500, [
            'saved' => false,
            'reason' => 'We could not attach your files or store your quote request. Please try again, or write to info@kwetucreations.com.',
        ]);
    }
}

if ($fileErrors !== []) {
    kwetu_delete_stored(array_column($savedFiles, 'stored'));
    kwetu_respond(422, [
        'saved' => false,
        'field' => 'designFiles',
        'reason' => 'Your enquiry was not saved because of an attachment: '
            . implode(' ', $fileErrors) . ' Nothing else was stored.',
    ]);
}

$id = 0;

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT INTO enquiries
            (kind, full_name, business, email, phone, address, topic, message, status, ip_hash)
         VALUES
            (:kind, :full_name, :business, :email, :phone, :address, :topic, :message, \'new\', :ip)'
    );

    $insert->execute([
        ':kind' => $values['kind'],
        ':full_name' => $values['full_name'],
        ':business' => $values['business'],
        ':email' => $values['email'],
        ':phone' => $values['phone'],
        ':address' => $values['address'],
        ':topic' => $values['topic'],
        ':message' => $values['message'],
        ':ip' => $ipHash,
    ]);

    $id = (int) $pdo->lastInsertId();

    if ($id === 0) {
        throw new RuntimeException('No enquiry id returned.');
    }

    if ($savedFiles !== []) {
        $fileInsert = $pdo->prepare(
            'INSERT INTO enquiry_files
                (enquiry_id, stored_name, original_name, detected_type, size_bytes)
             VALUES
                (:enquiry_id, :stored, :original, :type, :size)'
        );

        foreach ($savedFiles as $file) {
            $fileInsert->execute([
                ':enquiry_id' => $id,
                ':stored' => $file['stored'],
                ':original' => $file['original'],
                ':type' => $file['type'],
                ':size' => $file['size'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    kwetu_delete_stored(array_column($savedFiles, 'stored'));

    error_log('kwetu enquiry rejected: ' . $error->getMessage());

    kwetu_respond(500, [
        'saved' => false,
        'reason' => $kind === 'quote'
            ? 'We could not store your quote request. Please try again, or write to info@kwetucreations.com.'
            : 'We could not store your message. Please try again, or write to info@kwetucreations.com.',
    ]);
}

// The enquiry is stored from here on, so nothing below may turn a success into
// an error page — including the count that fills in remaining_today.
try {
    $remaining = max(0, $limit - kwetu_recent_count($pdo, $ipHash, 'enquiries'));
} catch (Throwable $couldNotCount) {
    error_log('kwetu enquiry remaining count failed: ' . $couldNotCount->getMessage());
    $remaining = 0;
}

$attachments = count($savedFiles);

if ($kind === 'contact') {
    $message = 'Saved. Your message is stored for the team to read. Nothing is published or sent automatically.';
} elseif ($attachments === 0) {
    $message = 'Saved, with no files attached. Your quote request is stored for the team to read.';
} else {
    $message = 'Saved, with ' . $attachments . ' file' . ($attachments === 1 ? '' : 's')
        . ' attached. Your quote request is stored for the team to read.';
}

// The team's own heads-up that there is something to read. Off unless a mail
// account is configured, and a failure here changes nothing about the answer
// above: the enquiry is stored, and that is the whole claim being made.
try {
    kwetu_notify_submission([
        'Subject name' => ($kind === 'quote' ? 'Quote request' : 'Website enquiry')
            . ' #' . $id . ' - ' . $values['full_name'],
        'Name' => $values['full_name'],
        'Business' => $values['business'],
        'Email' => $values['email'],
        'Phone' => $values['phone'],
        'Address' => $values['address'],
        'Service' => $kind === 'quote' ? $values['topic'] : null,
        'Topic' => $kind === 'contact' ? $values['topic'] : null,
        'Message' => $values['message'],
    ], $kind === 'quote'
        ? 'New quote request from the website'
        : 'New enquiry from the contact page', [
        'Attached files' => $attachments === 0
            ? null
            : implode(', ', array_column($savedFiles, 'original')),
    ]);
} catch (Throwable $error) {
    error_log('kwetu notify skipped: ' . $error->getMessage());
}

kwetu_respond(201, [
    'saved' => true,
    'id' => $id,
    'kind' => $kind,
    'status' => 'new',
    'attachments' => $attachments,
    'message' => $message,
    'remaining_today' => $remaining,
]);
