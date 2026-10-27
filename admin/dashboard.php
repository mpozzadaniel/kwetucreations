<?php
/**
 * The overview a signed-in reviewer lands on.
 *
 * Every number on this page is a COUNT against the same two tables the screens
 * behind it list, read on the page load in front of you. Nothing is sampled,
 * estimated or written into this file, and when the database cannot be read
 * the numbers come out as dashes: a zero would claim an empty queue that was
 * never actually looked at.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

$counts = kwetu_admin_counts();

$contactNew = $counts['contact']['new'];
$quoteNew = $counts['quote']['new'];
$pending = $counts['testimonial']['pending'];

$handled = $counts['contact']['handled'] !== null && $counts['quote']['handled'] !== null
    ? $counts['contact']['handled'] + $counts['quote']['handled']
    : null;

// Everything in the enquiries table, however it is marked, so the reviewer can
// tell a live queue apart from a database that has simply never been used.
$enquiriesHeld = $counts['readable']
    ? array_sum(array_map(
        static fn (array $group): int => array_sum(array_map('intval', $group)),
        [$counts['contact'], $counts['quote']]
    ))
    : null;

$testimonialsHeld = $counts['readable'] ? array_sum(array_map('intval', $counts['testimonial'])) : null;

$latestEnquiries = [];
$latestTestimonials = [];
$readFailure = '';

if ($counts['readable']) {
    try {
        $pdo = kwetu_db();

        $latestEnquiries = $pdo->query(
            'SELECT id, kind, full_name, business, topic, message, status, created_at
               FROM enquiries
              ORDER BY created_at DESC, id DESC
              LIMIT 6'
        )->fetchAll(PDO::FETCH_ASSOC);

        $latestTestimonials = $pdo->query(
            'SELECT id, client_name, business_name, rating, testimonial, status, created_at
               FROM testimonial_submissions
              ORDER BY created_at DESC, id DESC
              LIMIT 6'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $couldNotRead) {
        error_log('kwetu dashboard recent lists: ' . $couldNotRead->getMessage());
        $readFailure = 'The two lists below could not be read just now, so they are empty. '
            . 'They share their connection with the counts above, so treat those as unknown '
            . 'rather than zero.';
    }
}

// Heredoc only takes simple values, so every figure is worked out first.
$contactNum = $contactNew === null ? '&mdash;' : (string) $contactNew;
$quoteNum = $quoteNew === null ? '&mdash;' : (string) $quoteNew;
$pendingNum = $pending === null ? '&mdash;' : (string) $pending;
$handledNum = $handled === null ? '&mdash;' : (string) $handled;

$contactAlert = ($contactNew === null || $contactNew > 0) ? ' is-alert' : '';
$quoteAlert = ($quoteNew === null || $quoteNew > 0) ? ' is-alert' : '';
$pendingAlert = ($pending === null || $pending > 0) ? ' is-alert' : '';

$attachmentNote = $counts['attachments'] === null
    ? 'Files arrive with the request itself.'
    : 'Design files stored with requests: ' . (int) $counts['attachments'] . '.';

if (!$counts['readable']) {
    $notice = '<div class="notice bad" role="alert"><strong>The numbers below are dashes, not zeros.</strong> '
        . 'This page could not reach the database, so nothing here has been counted. The website is '
        . 'still taking submissions; open <a href="inbox.php">the inbox</a> to see whether it can be read.</div>';
} elseif ($readFailure !== '') {
    $notice = '<div class="notice bad" role="alert">' . kwetu_e($readFailure) . '</div>';
} else {
    $notice = '';
}

$stats = <<<HTML
<div class="stats">
    <a class="stat{$contactAlert}" href="inbox.php">
        <span class="stat-num">{$contactNum}</span>
        <span class="stat-label">Contact enquiries waiting</span>
        <span class="stat-note">Still marked new, so nobody has said they have dealt with them.</span>
    </a>
    <a class="stat{$quoteAlert}" href="inbox.php?kind=quote&amp;status=all">
        <span class="stat-num">{$quoteNum}</span>
        <span class="stat-label">Quote requests waiting</span>
        <span class="stat-note">{$attachmentNote}</span>
    </a>
    <a class="stat{$pendingAlert}" href="review.php">
        <span class="stat-num">{$pendingNum}</span>
        <span class="stat-label">Testimonials awaiting approval</span>
        <span class="stat-note">None of these is on the website yet.</span>
    </a>
    <a class="stat" href="inbox.php?status=handled">
        <span class="stat-num">{$handledNum}</span>
        <span class="stat-label">Marked handled, not filed away</span>
        <span class="stat-note">Answered from your own mailbox, then archived here once you agree they are closed.</span>
    </a>
</div>
HTML;

$enquiryRows = '';

if ($latestEnquiries === []) {
    $enquiryRows = '<li><div class="empty">'
        . ($counts['readable']
            ? '<strong>No messages yet</strong>The contact and quote forms write here the moment someone uses them.'
            : 'Nothing could be read.')
        . '</div></li>';
}

foreach ($latestEnquiries as $row) {
    $kind = $row['kind'] === 'quote' ? 'Quote request' : 'Contact enquiry';
    $status = kwetu_e((string) $row['status']);
    $isUnread = $status === 'new';
    $state = $isUnread ? 'is-unread' : 'is-read';
    $stateWord = $isUnread ? 'unread' : $status;
    $who = kwetu_e((string) $row['full_name']);
    $business = trim((string) $row['business']) === ''
        ? '' : ' &middot; ' . kwetu_e((string) $row['business']);
    $say = kwetu_e(kwetu_admin_excerpt((string) $row['message']));
    $when = kwetu_e(kwetu_admin_when($row['created_at']));

    $enquiryRows .= '<li><div class="row">'
        . '<div><a href="inbox.php?status=' . $status . '">' . $who . '</a>'
        . '<small>' . $kind . $business . ' &mdash; ' . $say . '</small></div>'
        . '<div class="when"><span class="tag ' . $state . '">' . $stateWord . '</span>'
        . '<small>' . $when . '</small></div>'
        . '</div></li>';
}

$testimonialRows = '';

if ($latestTestimonials === []) {
    $testimonialRows = '<li><div class="empty">'
        . ($counts['readable']
            ? '<strong>Nothing submitted yet</strong>Client testimonials land here as pending, and stay private until approved.'
            : 'Nothing could be read.')
        . '</div></li>';
}

foreach ($latestTestimonials as $row) {
    $status = kwetu_e((string) $row['status']);
    $who = kwetu_e((string) $row['client_name']);
    $stars = kwetu_stars((int) $row['rating']);
    $say = kwetu_e(kwetu_admin_excerpt((string) $row['testimonial'], 70));
    $when = kwetu_e(kwetu_admin_when($row['created_at']));
    $state = $status === 'pending' ? 'is-unread' : 'is-read';

    $testimonialRows .= '<li><div class="row">'
        . '<div><a href="review.php?status=' . $status . '">' . $who . '</a>'
        . '<small><span class="stars">' . $stars . '</span> ' . $say . '</small></div>'
        . '<div class="when"><span class="tag ' . $state . '">' . $status . '</span>'
        . '<small>' . $when . '</small></div>'
        . '</div></li>';
}

$heldLine = '';

if ($enquiriesHeld !== null || $testimonialsHeld !== null) {
    $heldLine = '<p class="count">Held in the database: '
        . ($enquiriesHeld === null ? 'unknown' : (int) $enquiriesHeld)
        . ' messages and ' . ($testimonialsHeld === null ? 'unknown' : (int) $testimonialsHeld)
        . ' testimonials, including any already answered, approved or filed away.</p>';
}

// The one setting this desk has, put where the owner actually lands first
// rather than left only in the sidebar. The age is the password file's own
// clock — the same value that decides whether other sessions still count.
$changed = kwetu_admin_ago(kwetu_admin_credential_stamp());

$settingsLine = <<<HTML
<p class="count"><a href="password.php">Change the review password</a> &mdash; last replaced {$changed}.</p>
HTML;

$body = <<<HTML
{$notice}
{$stats}
<div class="panels">
    <section class="panel">
        <h2>Latest messages</h2>
        <ul>{$enquiryRows}</ul>
    </section>
    <section class="panel">
        <h2>Latest testimonials</h2>
        <ul>{$testimonialRows}</ul>
    </section>
</div>
{$heldLine}
{$settingsLine}
<p class="hint">This desk is the whole of the publication decision: a testimonial only reaches the
website when someone with this password marks it approved, and a message here is private
correspondence that the website has not answered on its own.</p>
HTML;

echo kwetu_admin_page('Dashboard', $body, 'dashboard', true, 'What the website has collected');
