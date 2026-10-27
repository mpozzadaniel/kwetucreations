<?php
/**
 * GET admin/attachment.php?file=<id>
 *
 * The only way an uploaded design file ever reaches a browser. It is looked up
 * by its own database id, so a stranger cannot name a file.
 *
 * Photographs, scans and PDFs are shown in the browser; everything else is
 * forced to download. The split is decided by re-reading the bytes on disk,
 * never by the name: an .svg is always a download, because rendering one would
 * run whatever script it carries, on this site's own origin, with the reviewer
 * already signed in.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once dirname(__DIR__) . '/backend/uploads.php';

kwetu_admin_login_required();

$id = (int) ($_GET['file'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No such attachment.';
    exit;
}

$pdo = kwetu_db();

$stmt = $pdo->prepare(
    'SELECT stored_name, original_name FROM enquiry_files WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file || preg_match('/^[0-9a-f]{32}\.[a-z0-9]{1,8}$/', (string) $file['stored_name']) !== 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No such attachment.';
    exit;
}

// The folder comes from the same setting the writer used, so a private
// storage path outside the published site works here without a second edit.
try {
    $dir = kwetu_storage_dir();
} catch (Throwable $missingStorage) {
    error_log('kwetu attachment storage unavailable: ' . $missingStorage->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That file is no longer on this server.';
    exit;
}

$path = realpath($dir . '/' . $file['stored_name']);

// The stored name is generated, so this should never trip. It stays because a
// file path from a database row is still a file path from a database row.
if ($path === false || strncmp($path, $dir . DIRECTORY_SEPARATOR, strlen($dir) + 1) !== 0
    || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That file is no longer on this server.';
    exit;
}

// A download name for the person reading the inbox. Anything awkward in the
// name the visitor's browser sent is replaced, not trusted.
$original = (string) $file['original_name'];
$extension = strtolower(pathinfo((string) $file['stored_name'], PATHINFO_EXTENSION));
$stem = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $original);
$stem = trim((string) $stem);

if ($stem === '' || $stem === '.') {
    $stem = 'attachment-' . $id;
}

if (strtolower(pathinfo($stem, PATHINFO_EXTENSION)) !== $extension) {
    $stem .= '.' . $extension;
}

$stem = mb_substr($stem, 0, 120);
$size = filesize($path);

// What the bytes actually are, checked now rather than trusted from the row
// written at upload time.
$detected = 'application/octet-stream';
$finfo = finfo_open(FILEINFO_MIME_TYPE);

if ($finfo !== false) {
    $found = finfo_file($finfo, $path);
    finfo_close($finfo);

    if (is_string($found) && $found !== '') {
        $detected = $found;
    }
}

// Only these are ever shown inside the browser. SVG is deliberately absent.
$viewable = [
    'image/jpeg' => 'image/jpeg',
    'image/png' => 'image/png',
    'image/webp' => 'image/webp',
    'image/gif' => 'image/gif',
    'application/pdf' => 'application/pdf',
];

$safeType = $viewable[$detected] ?? 'application/octet-stream';
$disposition = isset($viewable[$detected]) ? 'inline' : 'attachment';

header('Content-Type: ' . $safeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $stem . '"');
header('Content-Length: ' . (string) $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($path);
