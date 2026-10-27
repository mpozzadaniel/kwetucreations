<?php
/*
  One command turns this local copy into a launchable site:

      php tools/set-domain.php https://www.kwetucreations.com

  It writes the things that cannot exist until a real domain does —
  <link rel="canonical">, the Open Graph URL and image, the absolute @id, url
  and image inside the LocalBusiness node, robots.txt and sitemap.xml — and
  refuses to guess. Run it again with --unset to take them back out (the pages
  then return to the "no domain yet" state).

  This is the same tool that used to be tools/set-domain.js. It is PHP now
  because PHP is what this project ships with; node is not installed here.

  Nothing here claims the site is indexed. Submitting the sitemap to Google
  Search Console and Google Business Profile is still a manual step; see
  LAUNCH-CHECKLIST.md.
*/

declare(strict_types=1);

/* A build-time command, not a page. This refuses anything that arrives over
   HTTP before a single line of the file can run, echo or raise an error: the
   tool rewrites the site's own canonical blocks, robots.txt and sitemap.xml,
   so a browser must never be able to invoke it or read it back. The matching
   tools/.htaccess denies the folder one level earlier when Apache can. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

$root = dirname(__DIR__);

/* The file every visitor should land on. The root .htaccess now serves
   index3.html for a bare folder address and redirects the long
   /index.html and /index3.html forms onto it, so the canonical address of the
   homepage is the root URL, not /index3.html. */
const HOME_AT_ROOT = true;

const HOME = 'index3.html';

/* The branded share cover built from the logo drop by
   tools/make-logo-assets.php - 1200x630 is what Facebook and WhatsApp crop
   nicely. Override with --og=images/webp/x.webp to send a project photo
   instead. */
$ogImage = 'images/logo/og-1200x630.webp';

// The marker may have been left by the old JavaScript version of this tool,
// so both file names are accepted when looking for it.
$regionPattern = '/([ \t]*)<!-- SEO:DOMAIN:(?:BEGIN -->| tools\/set-domain\.(?:js|php) inserts)[\s\S]*?(?:<!-- SEO:DOMAIN:END -->|-->)/';
$filledPattern = '/([ \t]*)<!-- SEO:DOMAIN:BEGIN -->[\s\S]*?<!-- SEO:DOMAIN:END -->/';
$noindexPattern = '/name=["\']robots["\'][^>]*noindex/i';

$args = array_slice($argv, 1);
$unset = in_array('--unset', $args, true);

$flag = function (string $name) use ($args): ?string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
};

$pages = [];

foreach (scandir($root) as $name) {
    if (is_file($root . '/' . $name) && preg_match('/\.html$/i', $name)) {
        $pages[] = $name;
    }
}

sort($pages, SORT_FLAG_CASE | SORT_STRING);

function fail(string $message): never
{
    fwrite(STDERR, 'Error: ' . $message . "\n");
    exit(1);
}

/** Site root only, https required unless --allow-insecure, no machine names. */
function validate(string $candidate, bool $allowInsecure): string
{
    if ($candidate === '') {
        fail('give the domain to set. Example: php tools/set-domain.php https://www.kwetucreations.com');
    }

    $parts = parse_url($candidate);

    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        fail("\"{$candidate}\" is not a URL. Example: php tools/set-domain.php https://www.kwetucreations.com");
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);

    if ($scheme !== 'https' && !$allowInsecure) {
        fail("{$candidate}: use https:// (pass --allow-insecure only for a staging host)");
    }

    $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false
        || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;

    if ($isIpAddress || preg_match('/^(localhost|127\.|.*\.(?:local|localhost))$/i', $host)) {
        fail("{$host} is a machine name or an address, not a public domain — a sitemap must not point at it.");
    }

    if (rtrim($parts['path'] ?? '', '/') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
        fail('give the site root only, with no folder, query or fragment');
    }

    $origin = $scheme . '://' . $host;

    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
}

function urlFor(string $origin, string $file): string
{
    if (HOME_AT_ROOT && $file === HOME) {
        return $origin . '/';
    }

    return $origin . '/' . $file;
}

function le(string $bytes, int $offset, int $width): int
{
    $value = 0;

    for ($i = $width - 1; $i >= 0; $i--) {
        $value = ($value << 8) | ord($bytes[$offset + $i]);
    }

    return $value;
}

/**
 * WebP carries its canvas size in the first chunk, in three different layouts
 * depending on which codec produced the file. Read directly, because this
 * machine's PHP has no image extension loaded.
 *
 * @return array{width:int,height:int}
 */
function webpSize(string $path): array
{
    $bytes = (string) file_get_contents($path, false, null, 0, 30);

    if (strlen($bytes) < 30 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        return ['width' => 0, 'height' => 0];
    }

    $tag = substr($bytes, 12, 4);

    if ($tag === 'VP8X') {
        return ['width' => 1 + le($bytes, 24, 3), 'height' => 1 + le($bytes, 27, 3)];
    }

    if ($tag === 'VP8 ') {
        return ['width' => le($bytes, 26, 2) & 0x3fff, 'height' => le($bytes, 28, 2) & 0x3fff];
    }

    if ($tag === 'VP8L') {
        $bits = le($bytes, 21, 4);

        return ['width' => 1 + ($bits & 0x3fff), 'height' => 1 + (($bits >> 14) & 0x3fff)];
    }

    return ['width' => 0, 'height' => 0];
}

/**
 * The LocalBusiness node carried by the homepage, About and Contact states only
 * facts the site already publishes. Google wants that entity's identity (@id)
 * and website (url) written as absolute addresses, so the same rule that
 * refuses to publish a localhost canonical decides these too, and --unset takes
 * them back to the relative form the pages ship with.
 *
 * A block that does not parse is left exactly as it is: a wrong URL is a
 * weaker problem than broken JSON, which would take the whole node away.
 */
function rewriteStructuredData(string $html, ?string $origin, ?string $homeUrl, ?string $imageUrl, string &$state): string
{
    if (stripos($html, '<!-- SEO:JSON-LD:BEGIN -->') === false) {
        $state = 'no structured data';

        return $html;
    }

    $output = preg_replace_callback(
        '/(<script type="application\/ld\+json">)([\s\S]*?)(<\/script>)/i',
        static function (array $m) use ($origin, $homeUrl, $imageUrl, &$state): string {
            $data = json_decode($m[2], true);

            if (!is_array($data)) {
                $state = 'structured data REFUSED (does not parse)';

                return $m[0];
            }

            $id = (string) ($data['@id'] ?? '');
            $at = strpos($id, '#');
            $fragment = $at === false ? '#kwetu-creations' : substr($id, (int) $at);

            if ($origin === null) {
                unset($data['url'], $data['image']);
                $data['@id'] = $fragment;
                $state = 'structured data local';
            } else {
                $data['@id'] = (string) $homeUrl . $fragment;
                $data['url'] = (string) $homeUrl;
                $data['image'] = (string) $imageUrl;
                $state = 'structured data absolute';
            }

            $json = rtrim(preg_replace(
                '/^/m',
                '  ',
                (string) json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ));

            return $m[1] . "\n" . $json . "\n  " . $m[3];
        },
        $html,
        1
    );

    return is_string($output) ? $output : $html;
}

$origin = null;
$dimensions = ['width' => 0, 'height' => 0];

if (!$unset) {
    $domain = '';

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            $domain = $arg;
            break;
        }
    }

    $origin = validate($domain, in_array('--allow-insecure', $args, true));

    if ($flag('og') !== null) {
        $ogImage = (string) $flag('og');
    }

    if (!is_file($root . '/' . $ogImage)) {
        fail("{$ogImage} does not exist — pick an image that ships with the site, or add one.");
    }

    $dimensions = webpSize($root . '/' . $ogImage);
}

// Only a genuinely large image earns the wide social card.
$wide = $dimensions['width'] >= 1200
    && $dimensions['height'] > 0
    && $dimensions['width'] / $dimensions['height'] >= 1.5;

/* Nothing is written until every structured-data block on the site parses, so
   a refusal cannot leave half the pages carrying an absolute address. */
foreach ($pages as $file) {
    $html = (string) file_get_contents($root . '/' . $file);

    if (stripos($html, '<!-- SEO:JSON-LD:BEGIN -->') === false) {
        continue;
    }

    if (preg_match('/<script type="application\/ld\+json">([\s\S]*?)<\/script>/i', $html, $block) !== 1
        || !is_array(json_decode($block[1], true))) {
        fail("{$file}: its structured data does not parse — fix that before setting the domain");
    }
}

$inserted = [];
$structured = [];

foreach ($pages as $file) {
    $full = $root . '/' . $file;
    $html = (string) file_get_contents($full);

    if (!preg_match($regionPattern, $html, $region)) {
        echo "{$file}: no SEO:DOMAIN marker — skipped\n";
        continue;
    }

    $indent = $region[1];
    $noindex = preg_match($noindexPattern, $html) === 1;
    $lines = [];

    if ($unset) {
        $lines[] = "{$indent}<!-- SEO:DOMAIN: tools/set-domain.php inserts the canonical link, og:url and og:image here -->";
    } elseif ($noindex) {
        // Duplicates and noindex pages still need a canonical so they hand
        // their ranking to the page that is actually listed.
        $lines = [
            "{$indent}<!-- SEO:DOMAIN:BEGIN -->",
            "{$indent}<link rel=\"canonical\" href=\"" . urlFor((string) $origin, HOME) . "\">",
            "{$indent}<!-- SEO:DOMAIN:END -->",
        ];
    } else {
        $url = urlFor((string) $origin, $file);
        $lines = [
            "{$indent}<!-- SEO:DOMAIN:BEGIN -->",
            "{$indent}<link rel=\"canonical\" href=\"{$url}\">",
            "{$indent}<meta property=\"og:url\" content=\"{$url}\">",
            "{$indent}<meta property=\"og:image\" content=\"" . urlFor((string) $origin, $ogImage) . "\">",
        ];

        if ($dimensions['width']) {
            $lines[] = "{$indent}<meta property=\"og:image:width\" content=\"{$dimensions['width']}\">";
        }

        if ($dimensions['height']) {
            $lines[] = "{$indent}<meta property=\"og:image:height\" content=\"{$dimensions['height']}\">";
        }

        $lines[] = "{$indent}<!-- SEO:DOMAIN:END -->";
    }

    $replacement = implode("\n", $lines);
    $output = preg_replace_callback($regionPattern, static fn (): string => $replacement, $html, 1);

    if (!is_string($output)) {
        fail("{$file}: the domain block could not be rewritten");
    }

    if (!$unset && preg_match($filledPattern, $output) !== 1) {
        fail("{$file}: the domain block was written without its sentinels");
    }

    if (!$unset && $wide) {
        $output = str_replace(
            '<meta name="twitter:card" content="summary">',
            '<meta name="twitter:card" content="summary_large_image">',
            $output
        );
    }

    if ($unset) {
        // The wide card was chosen because of the og:image this run removes,
        // so it goes back too — otherwise --unset leaves a large card with no
        // large image behind it.
        $output = str_replace(
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:card" content="summary">',
            $output
        );
    }

    $structure = '';
    $output = rewriteStructuredData(
        $output,
        $unset ? null : $origin,
        $unset ? null : urlFor((string) $origin, HOME),
        $unset ? null : urlFor((string) $origin, $ogImage),
        $structure
    );

    if ($structure !== 'no structured data') {
        $structured[] = $file . ': ' . $structure;
    }

    file_put_contents($full, $output);
    $inserted[] = $file;
}

if ($unset) {
    @unlink($root . '/robots.txt');
    @unlink($root . '/sitemap.xml');

    printf(
        "\ncanonical, og:url and og:image removed from %d page(s); the structured data\n"
        . "node is back to its relative form; robots.txt and sitemap.xml deleted.\n",
        count($inserted)
    );
    exit(0);
}

$origin = (string) $origin;

/* --- robots.txt --- */

/*
 * The public pages stay open and the working parts of the site are closed to
 * crawlers: the admin screens, the form endpoints, this tool, and pre-fix-files/,
 * whose dated copies of whole pages a crawler would read as duplicates.
 *
 * Stylesheets, scripts, photographs and includes/ are not blocked. Google needs
 * the first three to render a page it is judging, and a crawler fetching
 * includes/header.html costs the site nothing.
 */
file_put_contents($root . '/robots.txt', implode("\n", [
    'User-agent: *',
    'Allow: /',
    '',
    '# Working parts of the site, not pages.',
    'Disallow: /admin/',
    'Disallow: /backend/',
    'Disallow: /tools/',
    'Disallow: /pre-fix-files/',
    '',
    "Sitemap: {$origin}/sitemap.xml",
    '',
]));

/* --- sitemap.xml --- */
$entries = [];

foreach ($pages as $file) {
    if ($file === '404.html') {
        continue;
    }

    $html = (string) file_get_contents($root . '/' . $file);

    if (preg_match($noindexPattern, $html) === 1) {
        continue;
    }

    $entries[] = [
        'loc' => urlFor($origin, $file),
        'lastmod' => date('Y-m-d', (int) filemtime($root . '/' . $file)),
        'priority' => $file === HOME ? '1.0' : '0.7',
    ];
}

$body = [];

foreach ($entries as $entry) {
    $body[] = "  <url>\n    <loc>{$entry['loc']}</loc>\n    <lastmod>{$entry['lastmod']}</lastmod>\n"
        . "    <changefreq>monthly</changefreq>\n    <priority>{$entry['priority']}</priority>\n  </url>";
}

file_put_contents($root . '/sitemap.xml', implode("\n", array_merge(
    ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'],
    $body,
    ['</urlset>', '']
)));

foreach ($pages as $file) {
    if (preg_match('/127\.0\.0\.1|localhost|:8731/', (string) file_get_contents($root . '/' . $file)) === 1) {
        echo "WARNING: {$file} still contains a local address\n";
    }
}

printf("\n%s\n", $origin);
printf(
    "og:image  %s %sx%s%s\n",
    $ogImage,
    $dimensions['width'] ?: '?',
    $dimensions['height'] ?: '?',
    $wide ? ' (large card)' : ' (small card)'
);
printf("pages     %d updated\n", count($inserted));
printf("jsonld    %d structured-data node(s) now speak as %s\n", count($structured), $origin);
printf("sitemap   %d urls -> %s/sitemap.xml\n", count($entries), $origin);
printf("robots    -> %s/robots.txt\n", $origin);
echo "\nnext: upload the whole folder, including backend/ and admin/,\n";
echo "then open the sitemap in the browser and submit it in Google Search\n";
echo "Console. Also set the form recipients in the page heads if\n";
echo "info@kwetucreations.com changes, and follow LAUNCH-CHECKLIST.md for\n";
echo "the database, the review password and HTTPS.\n";
