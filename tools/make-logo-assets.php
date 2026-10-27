<?php
declare(strict_types=1);

/* Turns the big logo drop in images/ into the handful of small files the site
   actually serves:

       php -d extension=gd tools/make-logo-assets.php [source.png]

   Default source is images/final.png - the same chrome logo, supplied already
   keyed out (transparent ground) on 2026-10-27, so the tool detects that and
   skips its own key-out. Do not "help" it by re-keying: in this file the white
   is lettering (the K, the KWETU CREATIONS bar, the tagline), not background.
   Earlier sources, kept in images/ and still usable as an argument:
   images/kwetu-logo-3d.png (2048x2048 artwork on a solid white sheet, chosen
   2026-10-27 over images/kwetu-logo-metal-black-holes-v2.png because it measures
   3.54:1 against the #151515 chrome where the metal-black one manages only
   1.66:1; images/kwetu-------logo-3d.png is a byte-identical duplicate of it),
   which the tool keys out itself. The Apple touch icon and the share cover are
   composited onto white on purpose - iOS does not blend a transparent
   home-screen tile, and a share card needs a ground. The tab icon is not: since
   2026-10-27 it is transparent, because a white plate is precisely what a
   browser tab strip makes obvious. What it writes:

     images/logo/mark.webp          header badge, 120px (shows at ~40px)
     images/logo/logo-480.webp      About page, 480px
     images/logo/favicon-32.png     tab icon
     images/logo/apple-touch-icon.png  180px, iOS home screen
     images/logo/og-1200x630.webp   social share cover on white

   After rebuilding, bump the "?v=" token on the URLs the pages use
   (includes/header.html then php tools/bake-chrome.php, the About image, and the
   two icon links in all 13 heads). .htaccess caches images for 30 days and the
   file names never change, so without a new token every returning browser keeps
   showing the old artwork - which is how a removed white background appeared to
   still be there on 2026-10-27.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "run it as:  php -d extension=gd tools/make-logo-assets.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = $root . '/' . ltrim($argv[1] ?? 'images/final.png', '/');
$outDir = $root . '/images/logo';
if (!is_file($src)) {
    fwrite(STDERR, "no such file: $src\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "could not create $outDir\n");
    exit(1);
}

$im = imagecreatefrompng($src);
imagealphablending($im, false);
imagesavealpha($im, true);
$w = imagesx($im);
$h = imagesy($im);

/* A source that arrives already keyed out must not be keyed again: in
   images/final.png the white is the artwork (the K, the KWETU CREATIONS bar and
   the tagline are all white lettering), so keyOutWhite() would erase exactly the
   parts that make the logo readable. Detect it by asking whether the canvas is
   already mostly clear at its edges. */
$clearCorners = 0;
foreach ([[2, 2], [$w - 3, 2], [2, $h - 3], [$w - 3, $h - 3]] as $p) {
    if (((imagecolorat($im, $p[0], $p[1]) >> 24) & 0x7F) > 100) {
        $clearCorners++;
    }
}
$alreadyKeyed = $clearCorners === 4;

/* The drop is a white square with the artwork inside it. Find the artwork by
   looking for the first and last rows and columns that are not essentially
   white, so every derivative can be a tight crop instead of a small mark
   floating in a lot of white. */
$threshold = 246;
$minX = $w; $maxX = -1; $minY = $h; $maxY = -1;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        $a = 127 - (($c >> 24) & 0x7F);
        if ($a < 20) {
            continue; // transparent counts as background too
        }
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        if (!$alreadyKeyed && $r >= $threshold && $g >= $threshold && $b >= $threshold) {
            continue; // on an un-keyed sheet, white is ground, not artwork
        }
        if ($x < $minX) $minX = $x;
        if ($x > $maxX) $maxX = $x;
        if ($y < $minY) $minY = $y;
        if ($y > $maxY) $maxY = $y;
    }
}
if ($maxX < 0) {
    fwrite(STDERR, "the whole image looks white; nothing to crop\n");
    exit(1);
}

// Pad the crop back out to a square with breathing room around the mark, but
// never past the canvas: this drop already fills its square, so asking for
// padding would push the crop off the bottom and leave a white band.
$bw = $maxX - $minX + 1;
$bh = $maxY - $minY + 1;
$side = (int) min(ceil(max($bw, $bh) * 1.08), min($w, $h));
$cx = intdiv($minX + $maxX, 2);
$cy = intdiv($minY + $maxY, 2);
$x0 = max(0, min($w - $side, $cx - intdiv($side, 2)));
$y0 = max(0, min($h - $side, $cy - intdiv($side, 2)));
printf(
    "source %dx%d, artwork bbox %dx%d at (%d,%d), square crop %dpx from (%d,%d)\n",
    $w,
    $h,
    $bw,
    $bh,
    $minX,
    $minY,
    $side,
    $x0,
    $y0
);

/**
 * The drop is artwork on a solid white sheet, so the white has to be keyed out
 * before the mark can sit on the dark chrome. Only the background is pure
 * white - the histogram of this file puts 46% of its pixels at min(r,g,b) >=
 * 250 and barely 0.4% between 215 and 249 - so the cut is clean, and the
 * feather band only has to catch anti-aliased edges.
 *
 * The colour is then un-matted out of the white (orig = a*colour + (1-a)*255),
 * which is what stops the edges carrying a pale halo once they are over a dark
 * background.
 */
function keyOutWhite(GdImage $im): void
{
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $w = imagesx($im);
    $h = imagesy($im);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $mn = min($r, $g, $b);
            if ($mn < 238) {
                continue; // real artwork, leave it alone
            }
            $a = max(0.0, min(1.0, (250 - $mn) / 12));
            $alphaByte = (int) round((1 - $a) * 127);
            if ($a <= 0.0) {
                imagesetpixel($im, $x, $y, (127 << 24));
                continue;
            }
            $fix = static function (int $v) use ($a): int {
                $out = (int) round(($v - (1 - $a) * 255) / $a);
                return max(0, min(255, $out));
            };
            imagesetpixel($im, $x, $y, ($alphaByte << 24) | ($fix($r) << 16) | ($fix($g) << 8) | $fix($b));
        }
    }
}

/** Square crop of the mark at $size. $ground null keeps the keyed alpha. */
function mark(int $size, int $x0, int $y0, int $side, GdImage $srcIm, ?array $ground): GdImage
{
    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    if ($ground === null) {
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, ...$ground));
        imagealphablending($out, true);
    }
    imagecopyresampled($out, $srcIm, 0, 0, $x0, $y0, $size, $size, $side, $side);
    return $out;
}

if ($alreadyKeyed) {
    echo "source already has a transparent ground - skipping the white key-out\n";
} else {
    keyOutWhite($im);
}

$files = [];

$mark120 = mark(120, $x0, $y0, $side, $im, null);
$files['images/logo/mark.webp'] = [imagewebp($mark120, $outDir . '/mark.webp', 92), true];
imagedestroy($mark120);

$mark480 = mark(480, $x0, $y0, $side, $im, null);
$files['images/logo/logo-480.webp'] = [imagewebp($mark480, $outDir . '/logo-480.webp', 90), true];
imagedestroy($mark480);

/* The tab icon is transparent too: on 2026-10-27 the white plate behind it was
   reported as "the nav bar of chrome still has the other logo with the white
   squre" - Chrome's tab strip is exactly the surface where a white square is
   most obvious. The Apple touch icon keeps its white ground because iOS does not
   composite a transparent one onto the home screen. */
$favicon = mark(32, $x0, $y0, $side, $im, null);
$files['images/logo/favicon-32.png'] = [imagepng($favicon, $outDir . '/favicon-32.png'), true];
imagedestroy($favicon);

$touch = mark(180, $x0, $y0, $side, $im, [255, 255, 255]);
$files['images/logo/apple-touch-icon.png'] = [imagepng($touch, $outDir . '/apple-touch-icon.png'), true];
imagedestroy($touch);

/* Social cover: 1200x630 is what Facebook and WhatsApp crop well. White all
   over, so there is no box edge to see and the dark mark stays legible. */
$og = imagecreatetruecolor(1200, 630);
imagefill($og, 0, 0, imagecolorallocate($og, 255, 255, 255));
imagealphablending($og, true);
$markH = 500;
$markW = $markH;
imagecopyresampled(
    $og,
    $im,
    intdiv(1200 - $markW, 2),
    intdiv(630 - $markH, 2),
    $x0,
    $y0,
    $markW,
    $markH,
    $side,
    $side
);
$files['images/logo/og-1200x630.webp'] = [imagewebp($og, $outDir . '/og-1200x630.webp', 88), true];
imagedestroy($og);

foreach ($files as $rel => [$ok]) {
    if (!$ok) {
        fwrite(STDERR, "encode failed: $rel\n");
        continue;
    }
    printf("%-36s %s KB\n", $rel, round(filesize($root . '/' . $rel) / 1024, 1));
}
imagedestroy($im);
