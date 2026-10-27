<?php
/**
 * The review screen. This is the whole of the publication decision: a
 * testimonial a client submitted is listed here as pending and stays out of
 * the public feed until someone signs in and marks it approved.
 *
 * A decision is answered with a redirect, the same way signing in is. Landing
 * on review.php after a POST would let the browser Refresh replay the button
 * that was just pressed, and "Delete" is not a safe thing to press twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

$pdo = kwetu_db();

$statusFilter = (string) ($_GET['status'] ?? 'pending');

if (!in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'pending';
}

// Every form on the page comes back here with its tab attached, and the
// message about what just happened travels in the session, not the URL.
$self = 'review.php?status=' . rawurlencode($statusFilter);

$allowed = ['approve', 'reject', 'reopen', 'delete'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $flashKind = 'bad';

    if (!in_array($action, $allowed, true) || $id <= 0) {
        $flash = 'That request was not recognised.';
    } elseif (!kwetu_admin_csrf_valid($_POST['csrf_token'] ?? null)) {
        $flash = 'That form had expired. Nothing was changed.';
    } else {
        $row = $pdo->prepare('SELECT id, client_name, publication_consent, status FROM testimonial_submissions WHERE id = :id');
        $row->execute([':id' => $id]);
        $found = $row->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            $flash = 'That submission no longer exists.';
        } elseif ($action === 'approve' && (int) $found['publication_consent'] !== 1) {
            // Consent is checked again here, not only at submit time, because
            // approving a name and business for publication is the moment
            // that actually puts them on the internet.
            $flash = 'Cannot publish "' . $found['client_name'] . '": that client never ticked the box allowing us to publish it.';
        } else {
            $note = (string) ($_POST['note'] ?? '');
            $note = $note === '' ? null : kwetu_clean_text($note, 255);

            if ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM testimonial_submissions WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $flash = 'Deleted permanently.';
            } else {
                $to = ['approve' => 'approved', 'reject' => 'rejected', 'reopen' => 'pending'][$action];

                // The buttons on this screen carry no note box, so the note
                // column is only written when a form actually supplied one.
                if (array_key_exists('note', $_POST) && $_POST['note'] !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE testimonial_submissions
                            SET status = :to, reviewed_at = NOW(), reviewed_status_note = :note
                          WHERE id = :id'
                    );
                    $stmt->execute([':to' => $to, ':note' => $note, ':id' => $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE testimonial_submissions
                            SET status = :to, reviewed_at = NOW()
                          WHERE id = :id'
                    );
                    $stmt->execute([':to' => $to, ':id' => $id]);
                }

                $flash = match ($action) {
                    'approve' => 'Approved — it is now visible on the website.',
                    'reject' => 'Rejected — it stays in the database but is not published.',
                    default => 'Moved back to pending.',
                };
                $flashKind = 'good';
            }
        }
    }

    kwetu_admin_flash_put($flash, $flashKind);
    header('Location: ' . $self, true, 303);
    exit;
}

$flash = '';
$flashKind = 'good';

$taken = kwetu_admin_flash_take();
if ($taken !== null) {
    $flash = $taken['text'];
    $flashKind = $taken['kind'];
}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

foreach ($pdo->query(
    'SELECT status, COUNT(*) c FROM testimonial_submissions GROUP BY status'
)->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $counts[$c['status']] = (int) $c['c'];
}

$stmt = $pdo->prepare(
    "SELECT id, client_name, business_name, rating, testimonial, publication_consent,
            status, created_at, reviewed_at, reviewed_status_note
       FROM testimonial_submissions
      WHERE status = :status
      ORDER BY created_at DESC, id DESC
      LIMIT 60"
);
$stmt->execute([':status' => $statusFilter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$token = kwetu_admin_csrf();
$total = array_sum($counts);

$actionForm = static function (string $action, int $id, string $label, string $class = '') use ($token, $self): string {
    $confirm = $action === 'delete'
        ? ' data-confirm="Delete this permanently? It cannot be undone."'
        : '';

    return '<form class="inline" method="post" action="' . kwetu_e($self) . '"' . $confirm
        . '><input type="hidden" name="csrf_token" value="' . kwetu_e($token)
        . '"><input type="hidden" name="id" value="' . $id
        . '"><input type="hidden" name="action" value="' . $action . '">'
        . '<button type="submit"' . ($class === '' ? '' : ' class="' . $class . '"')
        . '>' . kwetu_e($label) . '</button></form>';
};

$cards = '';

if ($rows === []) {
    $cards = '<div class="empty"><strong>Nothing ' . kwetu_e($statusFilter) . '.</strong> '
        . ($statusFilter === 'pending'
            ? 'New client submissions will land here first.'
            : 'Nothing has been marked that way yet.')
        . '</div>';
}

foreach ($rows as $r) {
    $consented = (int) $r['publication_consent'] === 1;
    $rating = (int) $r['rating'];
    $stars = kwetu_stars($rating);
    $name = kwetu_e($r['client_name']);
    $business = $r['business_name'] !== null && $r['business_name'] !== ''
        ? '<small> &mdash; ' . kwetu_e($r['business_name']) . '</small>' : '';
    $text = kwetu_e($r['testimonial']);
    $when = kwetu_admin_when($r['created_at'] === null ? null : (string) $r['created_at']);
    $note = $r['reviewed_status_note'] !== null && $r['reviewed_status_note'] !== ''
        ? '<div class="hint">Note: ' . kwetu_e((string) $r['reviewed_status_note']) . '</div>' : '';

    $rowStatus = (string) $r['status'];
    $id = (int) $r['id'];

    // What the submission is decides the buttons, not which tab listed it.
    $buttons = '';

    if ($rowStatus === 'pending') {
        $buttons .= $consented
            ? $actionForm('approve', $id, 'Approve and publish')
            : '<span class="consent no">Cannot publish &mdash; no consent ticked</span>';

        $buttons .= ' ' . $actionForm('reject', $id, 'Reject', 'ghost');
    } else {
        $buttons .= $actionForm('reopen', $id, 'Move back to pending', 'ghost');
    }

    $buttons .= ' ' . $actionForm('delete', $id, 'Delete', 'danger');

    $statusCard = kwetu_e($rowStatus);
    $state = $rowStatus === 'pending' ? 'tag is-unread' : 'tag is-read';
    $stateWord = $rowStatus === 'pending' ? 'unread' : 'read';
    $reviewed = $r['reviewed_at'] !== null
        ? '<span>decided ' . kwetu_e(kwetu_admin_when((string) $r['reviewed_at'])) . '</span>'
        : '';

    $cards .= <<<HTML
<div class="card is-{$statusCard}">
    <div class="meta">
        <span class="stars" title="{$rating} out of 5">{$stars}</span>
        <span class="{$state}">{$stateWord}</span>
        <span>#{$id}</span>
        <span>received {$when}</span>
        {$reviewed}
        <span>status: {$statusCard}</span>
    </div>
    <div class="who">{$name}{$business}</div>
    <blockquote>{$text}</blockquote>
    {$note}
    <div class="actions">{$buttons}</div>
</div>
HTML;
}

$labels = [
    'pending' => 'Waiting for you',
    'approved' => 'Live on the website',
    'rejected' => 'Kept, not published',
];

$heading = $labels[$statusFilter];

$flashHtml = $flash !== ''
    ? '<div class="notice ' . kwetu_e($flashKind) . '" role="alert">' . kwetu_e($flash) . '</div>'
    : '';

$consentNote = $statusFilter === 'pending'
    ? '<p class="hint">Only submissions whose client ticked the publication box can be approved. '
      . 'A rejected testimonial stays in the database so you can change your mind; delete removes it.</p>'
    : '';

$filters = '';

foreach (['pending', 'approved', 'rejected'] as $f) {
    $now = $f === $statusFilter ? ' aria-current="page"' : '';
    $filters .= '<a href="review.php?status=' . $f . '"' . $now . '>'
        . kwetu_e($labels[$f]) . ' (' . (int) $counts[$f] . ')</a>';
}

$submissionWord = $total === 1 ? 'submission' : 'submissions';

$body = <<<HTML
{$flashHtml}
<div class="count">{$total} {$submissionWord} in total</div>
<div class="filters">{$filters}</div>
{$consentNote}
{$cards}
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
HTML;

echo kwetu_admin_page($heading, $body, $statusFilter === 'pending' ? 'review' : $statusFilter, true, 'Testimonials awaiting your decision');
