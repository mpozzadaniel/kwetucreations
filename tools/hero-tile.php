<?php
declare(strict_types=1);

/* One-shot: turn a JPEG in images/ into the 640px-wide WebP tile the homepage
   mosaic uses. Run with the bundled gd extension, which is present but left
   commented out in C:\xampp\php\php.ini:

       php -d extension=gd tools/hero-tile.php images/abc.jpeg images/das.jpeg

   Existing hero tiles are 640px wide and 12-40 KB, so quality 78 is the
   starting point; --q= overrides it.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    fwrite(STDERR, "gd with WebP support is required: run as  php -d extension=gd  " . __FILE__ . "\n");
    exit(1);
}

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

$quality = 78;
$targets = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--q=')) {
        $quality = max(1, min(100, (int) substr($arg, 4)));
        continue;
    }
    $targets[] = ltrim(str_replace('\\', '/', $arg), '/');
}
if ($targets === []) {
    fwrite(STDERR, "usage: php -d extension=gd tools/hero-tile.php images/x.jpeg [--q=78]\n");
    exit(1);
}

const TILE_WIDTH = 640;

foreach ($targets as $rel) {
    $src = $root . '/' . $rel;
    if (!is_file($src)) {
        fwrite(STDERR, "no such file: $rel\n");
        continue;
    }
    $info = @getimagesize($src);
    if ($info === false) {
        fwrite(STDERR, "not a readable image: $rel\n");
        continue;
    }
    [$w, $h] = [$info[0], $info[1]];

    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        default        => false,
    };
    if ($im === false) {
        fwrite(STDERR, "could not decode: $rel\n");
        continue;
    }

    // A phone-saved JPEG can be stored sideways with an EXIF hint that gd
    // ignores, so rotate the pixels to match what the camera intended.
    $orientation = 1;
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $orientation = (int) ($exif['Orientation'] ?? 1);
    }
    if ($orientation > 1) {
        $rotated = match ($orientation) {
            3 => imagerotate($im, 180, 0),
            6 => imagerotate($im, -90, 0),
            8 => imagerotate($im, 90, 0),
            default => $im,
        };
        if ($rotated !== false) {
            imagedestroy($im);
            $im = $rotated;
            if ($orientation === 5 || $orientation === 6 || $orientation === 7 || $orientation === 8) {
                [$w, $h] = [$h, $w];
            }
        }
    }

    if ($w <= TILE_WIDTH) {
        $tile = imagecreatetruecolor($w, $h);
        imagecopy($tile, $im, 0, 0, 0, 0, $w, $h);
        $tw = $w;
    } else {
        $tw = TILE_WIDTH;
        $th = (int) round($h * $tw / $w);
        $tile = imagecreatetruecolor($tw, $th);
        imagecopyresampled($tile, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    }

    $name = pathinfo($rel, PATHINFO_FILENAME);
    $out = $root . '/images/webp/hero/' . $name . '.webp';
    if (!is_dir(dirname($out))) {
        fwrite(STDERR, "no such folder: " . dirname($out) . "\n");
        continue;
    }
    if (!imagewebp($tile, $out, $quality)) {
        fwrite(STDERR, "encode failed: $out\n");
        continue;
    }
    printf(
        "%s -> %s  %dx%d  %s KB  (source %dx%d %s KB, exif orientation %d, quality %d)\n",
        $rel,
        'images/webp/hero/' . $name . '.webp',
        imagesx($tile),
        imagesy($tile),
        round(filesize($out) / 1024, 1),
        $w,
        $h,
        round(filesize($src) / 1024, 1),
        $orientation,
        $quality
    );
    imagedestroy($tile);
    imagedestroy($im);
}
