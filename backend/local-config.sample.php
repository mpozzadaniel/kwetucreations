<?php
/**
 * Copy this file to local-config.php and fill it in. local-config.php holds
 * this installation's private settings and must never be shared, committed or
 * published — the .htaccess in this folder blocks it over HTTP as well.
 */

return [
    // MySQL login. XAMPP's built-in root account has no password, which is
    // fine on a machine that never accepts outside connections and is not
    // fine anywhere else. On real hosting, create a dedicated database user
    // with access to only this database.
    // The one database holds every intake table: testimonials, enquiries and
    // the index rows for attached files. The files themselves are written to
    // the folder named by 'upload_dir' below, which is not served.
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kwetu_testimonials',
        'user' => 'root',
        'password' => '',
    ],

    // Changing this invalidates every stored IP fingerprint and every
    // session. Set it once, then leave it alone.
    'ip_salt' => 'REPLACE-WITH-RANDOM-TEXT',

    // Left as null until you run admin/set-password.php. No password is
    // guessed on your behalf.
    'admin_password_hash' => null,

    // Anti-spam ceilings, per salted IP fingerprint, over a rolling 24 hours.
    // Not security: the CSRF token, same-origin check, honeypot, fill-time
    // and file-type checks all stay in force regardless of these numbers.
    // One office or one mobile carrier's exit address counts as one
    // fingerprint, so a cap that is too tight turns away real clients.
    'daily_limit_per_ip' => 8,

    // Fastest a human can plausibly complete this form, in seconds.
    'minimum_fill_seconds' => 4,

    // Contact and quote enquiries allowed per fingerprint per day.
    'enquiry_daily_limit_per_ip' => 10,

    // Attachments on the quote request: four files of 8 MB each. PHP's own
    // php.ini answers for itself before this site sees a file, so the host
    // must allow at least:
    //     upload_max_filesize >= 8M
    //     post_max_size       >= 40M
    //     max_file_uploads    >= 4
    // Where the host allows less, lower these two to match it. On this
    // machine the measured values are 40M, 40M and 20, so 8 MB and 4 files
    // pass comfortably.
    'max_upload_files' => 4,
    'max_upload_bytes' => 8388608,

    // Where those attachments are written.
    //
    // '' means backend/storage, the folder this machine already uses. Leave
    // it empty for XAMPP: the folder exists, its own .htaccess refuses to
    // serve it, and files already stored there are indexed in the database.
    //
    // On real hosting, point it OUTSIDE the folder you publish so that no web
    // address can name a file. A path with no drive letter and no leading
    // slash is read from the site root, so either shape works:
    //     '../kwetu-uploads'
    //     '/home/YOURACCOUNT/kwetu-uploads'
    // PHP must be able to create and write there. Changing this does not move
    // files already stored — keep the old folder readable until the inbox is
    // emptied, or copy the files across.
    'upload_dir' => '',

    // Restrict who can even reach the sign-in page. An empty list means the
    // password is the only gate, which is fine over HTTPS with a strong
    // password and reckless over plain HTTP. Exact addresses and IPv4 CIDR
    // ranges both work, eg. ['255.122.77.8', '196.43.10.0/24'].
    'admin_allow_from' => [],

    // Email to the team when a contact enquiry, quote request or testimonial
    // is stored. Leave 'enabled' false until every line below is real: a half
    // filled account sends nothing, and the server log says which line was
    // missing. The submission is saved either way.
    //
    // Use an account whose only job is sending these. An app password from
    // the mail provider, not your login password. This file is already
    // blocked from the web by backend/.htaccess — do not move these values
    // anywhere else, and never commit it.
    'mail' => [
        'enabled' => false,
        'host' => 'smtp.example.com',
        'port' => 587,
        'security' => 'starttls',   // 'starttls' (port 587) or 'ssl' (port 465)
        'username' => 'REPLACE-WITH-ACCOUNT',
        'password' => 'REPLACE-WITH-APP-PASSWORD',
        'from' => 'REPLACE-WITH-THE-SAME-ACCOUNT',
        'from_name' => 'Kwetu Creations website',
        'to' => ['info@kwetucreations.com'],
    ],
];
