<?php
/**
 * POST backend/submit-testimonial.php
 *
 * The only place a testimonial enters the system. It stores, or refuses to
 * store, and then says exactly which of those two happened. A success reply
 * here means a row exists in the database and nothing more — publication is
 * decided later, by a person, in admin/.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notify.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    header('Allow: POST');
    kwetu_respond(405, [
        'saved' => false,
        'reason' => 'This address only accepts a form submission sent with POST.',
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

// A field real visitors never see. Anything that fills it is a bot reading
// the markup, so the submission is discarded. The reply still says nothing
// was saved, because nothing was.
if (kwetu_clean_text((string) ($_POST['company_website'] ?? ''), 200) !== '') {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'Your submission was not saved.',
    ]);
}

$config = kwetu_config();
$started = (int) ($_POST['form_started_at'] ?? 0);

if ($started > 0 && (time() - $started) < (int) $config['minimum_fill_seconds']) {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'That was faster than a person types. Please fill the form in yourself.',
    ]);
}

[$errors, $values] = kwetu_validate_testimonial($_POST);

if ($errors !== []) {
    kwetu_respond(422, [
        'saved' => false,
        'reason' => 'Some answers still need attention.',
        'fields' => $errors,
    ]);
}

// Opening the connection, counting today's submissions and writing the row all
// live inside one try: whichever of them fails because the database is down or
// misconfigured, the visitor gets the same plain sentence and the reason goes
// only to the server log.
$id = 0;

try {
    $pdo = kwetu_db();
    $ipHash = kwetu_ip_hash();

    if (kwetu_recent_count($pdo, $ipHash) >= (int) $config['daily_limit_per_ip']) {
        kwetu_respond(429, [
            'saved' => false,
            'reason' => 'This device has already sent several testimonials today. Please write to info@kwetucreations.com instead.',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO testimonial_submissions
            (client_name, business_name, rating, testimonial, publication_consent, status, ip_hash)
         VALUES
            (:client_name, :business_name, :rating, :testimonial, :consent, \'pending\', :ip)'
    );

    $stmt->execute([
        ':client_name' => $values['client_name'],
        ':business_name' => $values['business_name'],
        ':rating' => $values['rating'],
        ':testimonial' => $values['testimonial'],
        ':consent' => $values['publication_consent'],
        ':ip' => $ipHash,
    ]);

    $id = (int) $pdo->lastInsertId();
} catch (Throwable $couldNotStore) {
    error_log('kwetu testimonial not stored: ' . $couldNotStore->getMessage());

    kwetu_respond(500, [
        'saved' => false,
        'reason' => 'We could not store your testimonial. Please try again, or write to info@kwetucreations.com.',
    ]);
}

if ($id === 0) {
    kwetu_respond(500, [
        'saved' => false,
        'reason' => 'We could not store your testimonial. Please try again, or write to info@kwetucreations.com.',
    ]);
}

// Same rule as the enquiry endpoint: the team gets a heads-up if a mail
// account is configured, and whether that arrives or not, the row is stored
// and the answer given to the client stays the same.
try {
    kwetu_notify_submission([
        'Subject name' => 'New testimonial #' . $id . ' - ' . $values['client_name'],
        'Name' => $values['client_name'],
        'Business' => $values['business_name'],
        'Rating' => $values['rating'] . ' out of 5',
        'Publication consent given' => $values['publication_consent'] === 1 ? 'yes' : 'no',
        'Testimonial' => $values['testimonial'],
    ], 'New testimonial waiting for review');
} catch (Throwable $error) {
    error_log('kwetu notify skipped: ' . $error->getMessage());
}

// The row is stored from here on, so the count that fills remaining_today must
// not be able to turn a success into an error page.
try {
    $remaining = max(0, (int) $config['daily_limit_per_ip'] - kwetu_recent_count($pdo, $ipHash));
} catch (Throwable $couldNotCount) {
    error_log('kwetu testimonial remaining count failed: ' . $couldNotCount->getMessage());
    $remaining = 0;
}

kwetu_respond(201, [
    'saved' => true,
    'id' => $id,
    'status' => 'pending',
    'message' => 'Thank you for your feedback!',
    'detail' => 'Your testimonial has been submitted successfully. We appreciate you sharing your experience with Kwetu Creations.',
    'remaining_today' => $remaining,
]);
