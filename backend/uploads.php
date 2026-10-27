<?php
/**
 * Uploaded design files, handled as the dangerous input it is.
 *
 * A browser tells you anything it likes about a file's name and type, so
 * nothing from $_FILES is trusted here:
 *
 *   - the extension must be on the allowlist;
 *   - the actual bytes must match a recognised type for that extension,
 *     checked with finfo rather than believed;
 *   - the name on disk is generated, so "../", ".php" and a duplicate name
 *     are all impossible by construction;
 *   - the file lands in the storage folder named by local-config.php, which
 *     is not served at all (backend/storage with its own deny rule when that
 *     setting is left alone), so it can never be executed or fetched by a
 *     stranger;
 *   - it is only ever handed back through admin/attachment.php, to someone
 *     with a signed-in session, as a download — never inline.
 *
 * That last point matters for SVG. An SVG can carry script, so it is stored
 * like every other file and served as an attachment, which stops the browser
 * from running it.
 */

declare(strict_types=1);

/** extension => the detected MIME types that extension is allowed to really be */
function kwetu_upload_allowlist(): array
{
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
        'svg'  => ['image/svg+xml'],
        'psd'  => ['image/vnd.adobe.photoshop'],
        'ai'   => ['application/postscript', 'application/pdf'],
        'eps'  => ['application/postscript'],
        'zip'  => ['application/zip'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
}

/**
 * The folder uploaded design files are written to.
 *
 * backend/local-config.php chooses it with 'upload_dir'. Left empty it is
 * backend/storage — the folder this site has always used, and what local
 * XAMPP should keep. On real hosting it should point somewhere the web server
 * does not publish at all, so no address can name a file even if the server
 * configuration turns out to be wrong.
 *
 * Throws when the folder cannot be made usable. The caller gives the visitor
 * one plain sentence; the path only ever reaches the server log.
 */
function kwetu_storage_dir(): string
{
    $configured = trim((string) (kwetu_config()['upload_dir'] ?? ''));

    if ($configured === '') {
        $configured = __DIR__ . '/storage';
    }

    // A path that is already rooted (C:\..., /home/..., \\server\share) is
    // taken as given; anything else is relative to the site root.
    $dir = preg_match('#^([A-Za-z]:)?[\\\\/]#', $configured) === 1
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . $configured;

    $real = realpath($dir);

    if ($real === false) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The upload folder could not be created at ' . $dir . '.');
        }

        $real = realpath($dir);
    }

    if ($real === false || !is_dir($real) || !is_writable($real)) {
        throw new RuntimeException('The configured upload folder is not a writable directory: ' . $dir . '.');
    }

    // Cheap insurance for a folder that does sit inside the published site:
    // a deny rule costs nothing where no address reaches it anyway.
    $guard = $real . '/.htaccess';

    if (!is_file($guard)) {
        @file_put_contents(
            $guard,
            "# Uploaded client files. Never served to a browser.\n"
                . "# An authorised administrator reads them through admin/attachment.php.\n\n"
                . "Require all denied\n"
        );
    }

    return $real;
}

/**
 * @return array{saved: array<int, array{stored:string,original:string,type:string,size:int}>, errors: array<int,string>}
 */
function kwetu_receive_uploads(array $field, int $maxFiles, int $maxBytes): array
{
    $saved = [];
    $errors = [];

    if ($field === [] || !isset($field['name'])) {
        return [$saved, $errors];
    }

    // Normalise PHP's multi-file shape into a list of single-file arrays.
    $files = is_array($field['name']) ? kwetu_zip_file_arrays($field) : [$field];

    if (count($files) > $maxFiles) {
        return [$saved, ['Please attach at most ' . $maxFiles . ' files.']];
    }

    $allowlist = kwetu_upload_allowlist();
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        return [$saved, ['This server cannot inspect uploaded files, so uploads are disabled.']];
    }

    foreach ($files as $file) {
        // A name submitted with no file at all is the empty upload field the
        // browser sends when nobody chose anything; it is not a mistake.
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            && (string) ($file['name'] ?? '') === '') {
            continue;
        }

        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $errors[] = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    '“' . kwetu_display_name((string) $file['name']) . '” is larger than this server accepts.',
                UPLOAD_ERR_PARTIAL =>
                    '“' . kwetu_display_name((string) $file['name']) . '” only arrived part of the way. Try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                    'The server could not write the upload. Nothing was attached.',
                UPLOAD_ERR_EXTENSION =>
                    'A PHP extension on this server blocked the upload. Nothing was attached.',
                default => '“' . kwetu_display_name((string) $file['name']) . '” could not be received.',
            };
            continue;
        }

        $size = (int) $file['size'];

        if ($size <= 0) {
            $errors[] = '“' . kwetu_display_name((string) $file['name']) . '” arrived empty.';
            continue;
        }

        if ($size > $maxBytes) {
            $errors[] = '“' . kwetu_display_name((string) $file['name']) . '” is over the '
                . round($maxBytes / 1048576, 1) . ' MB limit.';
            continue;
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset($allowlist[$extension])) {
            $errors[] = 'A .' . $extension . ' file cannot be attached. Allowed: '
                . implode(', ', array_keys($allowlist)) . '.';
            continue;
        }

        $path = (string) $file['tmp_name'];
        $detected = (string) finfo_file($finfo, $path);

        if (!in_array($detected, $allowlist[$extension], true)) {
            // The label and the bytes disagree. That is the case this check
            // exists for — a .png that is really a .php.
            $errors[] = '“' . kwetu_display_name((string) $file['name']) . '” is not actually a '
                . strtoupper($extension) . ' file, so it was not stored.';
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!move_uploaded_file($path, kwetu_storage_dir() . '/' . $stored)) {
            $errors[] = 'The server could not save “' . kwetu_display_name((string) $file['name']) . '.”';
            continue;
        }

        @chmod(kwetu_storage_dir() . '/' . $stored, 0644);

        $saved[] = [
            'stored' => $stored,
            'original' => kwetu_display_name((string) $file['name']),
            'type' => $detected,
            'size' => $size,
        ];
    }

    finfo_close($finfo);

    return [$saved, $errors];
}

/** Turn PHP's parallel-array upload shape into one array per file. */
function kwetu_zip_file_arrays(array $field): array
{
    $out = [];
    $count = is_array($field['name']) ? count($field['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => $field['name'][$i],
            'type' => $field['type'][$i],
            'tmp_name' => $field['tmp_name'][$i],
            'error' => $field['error'][$i],
            'size' => $field['size'][$i],
        ];
    }

    return $out;
}

/**
 * A display name only. Stripped of any path, of control characters, and
 * capped, because it comes from a browser and will be shown to a person.
 */
function kwetu_display_name(string $raw): string
{
    $base = str_replace('\\', '/', $raw);
    $last = strrpos($base, '/');

    if ($last !== false) {
        $base = substr($base, $last + 1);
    }

    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $base);

    return mb_substr(trim((string) $clean), 0, 200);
}

/**
 * Delete files recorded for an enquiry, used when the enquiry is deleted.
 */
function kwetu_delete_stored(array $storedNames): void
{
    $dir = kwetu_storage_dir();

    foreach ($storedNames as $name) {
        if (preg_match('/^[0-9a-f]{32}\.[a-z0-9]{1,8}$/', $name) === 1) {
            @unlink($dir . '/' . $name);
        }
    }
}
