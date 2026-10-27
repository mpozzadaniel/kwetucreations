<?php
/**
 * GET backend/csrf-token.php
 *
 * The pages on this site are static HTML, so they cannot bake a token into
 * the form the way a PHP page would. The form asks for a token when it is
 * ready and sends it back with the submission.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    kwetu_respond(405, ['error' => 'Use GET for this address.']);
}

kwetu_issue_csrf();

kwetu_respond(200, [
    'token' => kwetu_csrf_token(),
    'expires_in' => 3600,
]);
