<?php
/**
 * Approved testimonials, as a page you can read and as markup you can paste.
 *
 * The live site already shows every approved review on its own: the static
 * pages ask backend/approved-testimonials.php for the approved list and
 * testimonials-live.js writes it into the page. Nothing here has to be pasted
 * for the website to work.
 *
 * Pasting is still worth doing before a launch, for one reason only: text that
 * arrives by script is weaker for search engines, and a visitor with JavaScript
 * switched off sees the standing cards instead of the compliments. Copying the
 * block below into testimonials.html and the homepage carousel puts the real
 * words into the page itself. Whatever is pasted, the live list replaces it the
 * moment the feed answers, so the two can never disagree.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

$pdo = kwetu_db();

$rows = $pdo->query(
    "SELECT client_name, business_name, rating, testimonial
       FROM testimonial_submissions
      WHERE status = 'approved' AND publication_consent = 1
      ORDER BY reviewed_at DESC, id DESC
      LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC);

$grid = '';
$track = '';
$preview = '';

foreach ($rows as $r) {
    $name = kwetu_e($r['client_name']);
    $business = $r['business_name'] !== null && $r['business_name'] !== ''
        ? kwetu_e($r['business_name']) : 'Client';
    $text = kwetu_e($r['testimonial']);
    $stars = kwetu_stars((int) $r['rating']);
    $rating = (int) $r['rating'];

    $grid .= <<<HTML

        <article class="testimonial-card">
            <div class="testimonial-quote" aria-hidden="true">&ldquo;</div>
            <div class="testimonial-stars" aria-label="{$rating} out of 5 stars">{$stars}</div>
            <blockquote>{$text}</blockquote>
            <div class="testimonial-client">
                <strong>{$name}</strong>
                <span>{$business}</span>
            </div>
        </article>
HTML;

    $track .= <<<HTML

            <article class="testimonial-card">
                <div class="testimonial-stars" aria-label="{$rating} out of 5 stars">{$stars}</div>
                <p class="testimonial-quote">{$text}</p>
                <div class="testimonial-client">
                    <h3>{$name}</h3>
                    <p>{$business}</p>
                </div>
            </article>
HTML;

    $preview .= <<<HTML

<div class="card">
    <div class="who">{$name} <small>&mdash; {$business}</small></div>
    <div class="stars" aria-label="{$rating} out of 5 stars">{$stars}</div>
    <blockquote>{$text}</blockquote>
</div>
HTML;
}

$gridNote = $rows === []
    ? "<!-- Nothing has been approved yet. Approve submissions in review.php, then come back here. -->"
    : '';

$gridBlock = '<div class="testimonials-grid">' . ($rows === [] ? "\n    " . $gridNote : $grid) . "\n</div>";
$trackBlock = '<div class="testimonials-track" id="testimonials-track">'
    . ($rows === [] ? "\n    " . $gridNote : $track) . "\n</div>";

// The plain-text form, for reading in a terminal or saving as a .html snippet.
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    echo "<!-- ===== testimonials.html : replace the whole <div class=\"testimonials-grid\"> block ===== -->\n";
    echo $gridBlock;
    echo "\n\n<!-- ===== index3.html and home.html : replace the whole <div class=\"testimonials-track\"> block ===== -->\n";
    echo $trackBlock;
    echo "\n";
    exit;
}

$count = count($rows);

if ($count === 0) {
    $body = '<div class="notice">Nothing has been approved yet, so there is no text to paste. '
        . 'The live pages are showing their standing cards, which is the honest state.</div>'
        . '<p class="hint">Approve submissions in <a href="review.php">the review screen</a> first &mdash; '
        . 'and only the ones whose writer ticked the box allowing us to publish.</p>';

    echo kwetu_admin_page('Approved text', $body, 'export');
    exit;
}

$gridEsc = kwetu_e($gridBlock);
$trackEsc = kwetu_e($trackBlock);
$wordCount = 0;

foreach ($rows as $r) {
    $wordCount += str_word_count((string) $r['testimonial']);
}

$body = <<<HTML
<div class="notice good">
    <strong>{$count} approved</strong> and cleared for publication &mdash; {$wordCount} words in total.
    They are already live: <a href="../backend/approved-testimonials.php">the feed</a> fills both testimonial
    areas on every page load, so nothing below has to be pasted for the site to work.
</div>

<div class="count">
    Pasting the two blocks further down is optional, and only useful before a launch: it puts the
    same words into the static files so search engines and visitors without JavaScript can read them.
    The live feed replaces whatever you paste, so the two can never disagree.
</div>

{$preview}

<h2 style="margin:30px 0 10px;font-size:15px;letter-spacing:.05em;text-transform:uppercase;">
    Paste into testimonials.html
</h2>
<div class="paste-row">
    <button type="button" class="copy-btn" data-copy="grid-paste" data-note="grid-note">Copy this block</button>
    <span class="copied" id="grid-note"></span>
    <a href="export-approved.php?raw=1">Plain text version of both blocks</a>
</div>
<div class="paste">
    <textarea id="grid-paste" readonly spellcheck="false" aria-label="Markup for testimonials.html">{$gridEsc}</textarea>
</div>

<h2 style="margin:30px 0 10px;font-size:15px;letter-spacing:.05em;text-transform:uppercase;">
    Paste into index3.html and home.html
</h2>
<div class="paste-row">
    <button type="button" class="copy-btn" data-copy="track-paste" data-note="track-note">Copy this block</button>
    <span class="copied" id="track-note"></span>
</div>
<div class="paste">
    <textarea id="track-paste" readonly spellcheck="false" aria-label="Markup for the homepage carousel">{$trackEsc}</textarea>
</div>

<p class="hint">The card markup here is the same shape the live script builds, so the pasted page
and the scripted page look identical. Stars come from the rating each client chose; a review
never gets a star nobody awarded.</p>

<script>
(function () {
    "use strict";

    var buttons = document.querySelectorAll(".copy-btn");

    Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener("click", function () {
            var box = document.getElementById(btn.getAttribute("data-copy"));
            var note = document.getElementById(btn.getAttribute("data-note"));

            if (!box) { return; }

            box.select();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(box.value).then(function () {
                    if (note) { note.textContent = "Copied."; }
                }, function () {
                    if (note) { note.textContent = "Already selected — press Ctrl+C."; }
                });
            } else {
                if (note) { note.textContent = "Already selected — press Ctrl+C."; }
            }
        });
    });
})();
</script>
HTML;

echo kwetu_admin_page('Approved text', $body, 'export');
