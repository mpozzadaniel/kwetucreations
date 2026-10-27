<?php
/**
 * GET backend/approved-testimonials.php
 *
 * Read-only feed of approved testimonials for the public pages. Pending and
 * rejected rows are never reachable through this address: the WHERE clause is
 * the whole access control, so a missing review simply means nothing shows.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    kwetu_respond(405, ['error' => 'Use GET for this address.']);
}

$limit = max(1, min(40, (int) ($_GET['limit'] ?? 24)));

// Every database touch on this address is inside one guard: on a dead or
// refusing server the visitor gets a plain JSON answer and the placeholder
// cards stay on the page, instead of a fatal error naming a file path.
try {
    $pdo = kwetu_db();

    $stmt = $pdo->prepare(
        'SELECT client_name, business_name, rating, testimonial, reviewed_at
           FROM testimonial_submissions
          WHERE status = \'approved\'
            AND publication_consent = 1
          ORDER BY reviewed_at DESC, id DESC
          LIMIT ' . $limit
    );

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $couldNotRead) {
    error_log('kwetu testimonial feed failed: ' . $couldNotRead->getMessage());
    kwetu_respond(500, [
        'count' => 0,
        'testimonials' => [],
        'error' => 'The testimonials are temporarily unavailable.',
    ]);
}

$items = array_map(static function (array $row): array {
    return [
        'name' => (string) $row['client_name'],
        'business' => $row['business_name'] !== null ? (string) $row['business_name'] : '',
        'rating' => (int) $row['rating'],
        'text' => (string) $row['testimonial'],
        'approvedAt' => $row['reviewed_at'],
    ];
}, $rows);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'count' => count($items),
    'testimonials' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
