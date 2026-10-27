<?php
/*
  Puts the shared navigation and footer into the HTML of every page.

      php tools/bake-chrome.php            write the blocks
      php tools/bake-chrome.php --check    report drift, write nothing
      php tools/bake-chrome.php --unset    empty the slots again

  Why this exists: script.js fetches includes/header.html and
  includes/footer.html and drops them into two empty divs. A visitor with the
  script running sees the navigation, but the HTML a search engine crawler
  reads first contains no navigation, no footer, and no link from any page back
  to the homepage - the whole site's internal linking depends on JavaScript
  executing and a second request succeeding. This tool puts the same markup in
  the page itself so the links are there before any script runs.

  Nothing about the design changes. The markup written here is byte-for-byte
  includes/header.html and includes/footer.html, and script.js now leaves a
  filled slot alone instead of fetching over it - so a page is never given the
  navigation twice, and a page whose block has been removed by --unset still
  gets its navigation from the fetch exactly as before.

  includes/ stays the single source of truth: edit the nav there, re-run this,
  and every page is rewritten between the markers. --check says whether any
  page has fallen behind.
*/

declare(strict_types=1);

/* A build-time command, not a page. It rewrites the site's own navigation, so
   a browser must never be able to invoke it or read it back. tools/.htaccess
   denies the folder one level earlier when Apache can. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$unset = in_array('--unset', $args, true);

$parts = [
    'header' => $root . '/includes/header.html',
    'footer' => $root . '/includes/footer.html',
];

foreach ($parts as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Error: {$name} source is missing at {$path}\n");
        exit(1);
    }
}

$pages = [];

foreach (scandir($root) as $name) {
    if (is_file($root . '/' . $name) && preg_match('/\.html$/i', $name)) {
        $pages[] = $name;
    }
}

sort($pages, SORT_FLAG_CASE | SORT_STRING);

/**
 * The two markers name one slot's contents, so a re-run replaces what it wrote
 * before instead of stacking a second copy of the navigation inside the first.
 *
 * The baked pattern spans the whole block on purpose. The footer holds its own
 * <div>s, and a match that stops at the first </div> would cut the copy short
 * and leave the rest of it orphaned in the page.
 */
function bakedPattern(string $id): string
{
    $open = preg_quote($id, '/');
    $upper = strtoupper($id);

    return '/<div id="' . $open . '">\s*'
        . '<!-- KWETU:CHROME:' . $upper . ':BEGIN -->[\s\S]*?'
        . '<!-- KWETU:CHROME:' . $upper . ':END -->\s*<\/div>/i';
}

/**
 * A slot that holds no markup of its own. Comments and whitespace are allowed
 * because --unset leaves one behind; a nested <div> is not, because that means
 * a page put its own content in the slot and a human has to decide what it is.
 */
function emptyPattern(string $id): string
{
    $open = preg_quote($id, '/');

    return '/<div id="' . $open . '">((?:(?!<\/?div\b)[\s\S])*?)<\/div>/i';
}

function markers(string $id, string $inner): string
{
    $inner = trim($inner);

    if ($inner === '') {
        return '<div id="' . $id . '"></div>';
    }

    // Indented by two so the block sits where the page's own markup sits.
    $padded = rtrim(preg_replace('/^/m', '  ', $inner));

    return '<div id="' . $id . '">' . "\n"
        . '  <!-- KWETU:CHROME:' . strtoupper($id) . ':BEGIN -->' . "\n"
        . $padded . "\n"
        . '  <!-- KWETU:CHROME:' . strtoupper($id) . ':END -->' . "\n"
        . '</div>';
}

$changed = 0;
$skipped = [];

foreach ($pages as $file) {
    $path = $root . '/' . $file;
    $html = (string) file_get_contents($path);
    $output = $html;
    $states = [];

    foreach ($parts as $name => $sourcePath) {
        $id = 'site-' . $name;
        $content = $unset ? '' : trim((string) file_get_contents($sourcePath));
        $replacement = markers($id, $content);

        if (preg_match(bakedPattern($id), $output) === 1) {
            $isBaked = true;
            $output = preg_replace_callback(
                bakedPattern($id),
                static fn (array $m): string => $replacement,
                $output,
                1
            );
        } elseif (preg_match(emptyPattern($id), $output, $match) === 1) {
            $isBaked = false;

            // A slot holding page markup of its own is refused rather than
            // quietly deleted, and the reason has to be said out loud.
            if (trim(preg_replace('/<!--[^>]*-->/s', '', $match[1])) !== '') {
                $states[$name] = 'REFUSED';
                $skipped[] = "{$file}: the {$name} slot is not empty and has no markers - left untouched";
                continue;
            }

            $output = preg_replace_callback(
                emptyPattern($id),
                static fn (array $m): string => $replacement,
                $output,
                1
            );
        } else {
            $states[$name] = 'no slot';
            $skipped[] = "{$file}: no <div id=\"{$id}\"> this tool can rewrite";
            continue;
        }

        // In check mode the write never happens, so report the slot as it was
        // found rather than as it would be left.
        $states[$name] = $check
            ? ($isBaked ? 'baked' : 'empty')
            : ($unset ? 'cleared' : ($isBaked ? 'refreshed' : 'baked'));
    }

    $same = $output === $html;

    if ($check) {
        printf("%-28s %s\n", $file, implode(' / ', $states) . ($same ? '' : '  <-- behind includes/'));
    } elseif (!$same) {
        if (file_put_contents($path, $output) === false) {
            fwrite(STDERR, "Error: {$path} could not be written\n");
            exit(1);
        }

        printf("%-28s %s\n", $file, implode(' / ', $states));
    } else {
        printf("%-28s %s (already current)\n", $file, implode(' / ', $states));
    }

    if (!$same) {
        $changed++;
    }
}

printf(
    "\n%s: %d of %d page(s) %s\n",
    $check ? 'check' : ($unset ? 'cleared' : 'written'),
    $changed,
    count($pages),
    $check ? 'differ from includes/' : ($unset ? 'emptied' : 'updated')
);

if ($skipped !== []) {
    echo "\nnot written:\n";

    foreach (array_unique($skipped) as $line) {
        echo '  ' . $line . "\n";
    }
}

if (!$check && !$unset) {
    echo "\nnext: the pages now carry their own navigation. script.js skips the\n";
    echo "fetch for any slot already filled, so nothing loads twice.\n";
}
