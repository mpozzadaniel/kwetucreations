<?php
/**
 * The enquiries inbox: contact messages and quote requests, including the
 * design files attached to a quote.
 *
 * This is private correspondence. Nothing here is published anywhere, and
 * marking an enquiry "handled" only records that a person dealt with it — no
 * reply is sent by this site, and no reply is claimed. The team answers from
 * their own mailbox, on their own terms.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

$pdo = kwetu_db();

$statuses = ['new', 'handled', 'archived', 'all'];
$kinds = ['contact', 'quote'];

$statusFilter = (string) ($_GET['status'] ?? 'new');
$kindFilter = (string) ($_GET['kind'] ?? 'all');

// One box, six columns. Cleaned the same way a submission is cleaned, so a
// term cannot arrive full of control characters or stray tabs.
$search = kwetu_clean_text((string) ($_GET['q'] ?? ''), 80);

if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = 'new';
}

if (!in_array($kindFilter, $kinds, true)) {
    $kindFilter = 'all';
}

// Back to the same view of the same list, with the search still attached. The
// answer to a POST goes through the session so a Refresh reloads the list
// instead of pressing the button again - deleting an enquiry and its files is
// not something to do twice by accident.
$self = 'inbox.php?status=' . rawurlencode($statusFilter) . '&kind=' . rawurlencode($kindFilter)
    . ($search === '' ? '' : '&q=' . rawurlencode($search));

$actions = ['handled', 'reopen', 'archive', 'delete'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = '';
    $flashKind = 'bad';
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if (!in_array($action, $actions, true) || $id <= 0) {
        $flash = 'That request was not recognised.';
    } elseif (!kwetu_admin_csrf_valid($_POST['csrf_token'] ?? null)) {
        $flash = 'That form had expired. Nothing was changed.';
    } else {
        $flashKind = 'good';
        $row = $pdo->prepare('SELECT id, kind, full_name, status FROM enquiries WHERE id = :id');
        $row->execute([':id' => $id]);
        $found = $row->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            $flash = 'That enquiry no longer exists.';
            $flashKind = 'bad';
        } elseif ($action === 'delete') {
            // The files have to be collected before the row goes, because the
            // foreign key removes the index rows the moment the enquiry does.
            require_once __DIR__ . '/../backend/uploads.php';

            $names = $pdo->prepare('SELECT stored_name FROM enquiry_files WHERE enquiry_id = :id');
            $names->execute([':id' => $id]);
            $stored = $names->fetchAll(PDO::FETCH_COLUMN);

            kwetu_delete_stored($stored);

            $stmt = $pdo->prepare('DELETE FROM enquiries WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $fileCount = count($stored);
            $flash = $fileCount === 0
                ? 'Deleted the enquiry permanently.'
                : 'Deleted the enquiry and its ' . $fileCount . ' attached '
                    . ($fileCount === 1 ? 'file' : 'files') . ' permanently.';
        } else {
            $note = kwetu_clean_text((string) ($_POST['note'] ?? ''), 255);

            $to = match ($action) {
                'handled' => 'handled',
                'archive' => 'archived',
                default => 'new',
            };

            if (array_key_exists('note', $_POST)) {
                $stmt = $pdo->prepare(
                    'UPDATE enquiries
                        SET status = :to, reviewed_at = NOW(), reply_note = :note
                      WHERE id = :id'
                );
                $stmt->execute([':to' => $to, ':note' => $note === '' ? null : $note, ':id' => $id]);
            } else {
                // "Move back to new" and "Archive" carry no note box. Sending
                // an empty one would erase what was written when the message
                // was first marked handled, so those buttons leave it alone.
                $stmt = $pdo->prepare(
                    'UPDATE enquiries
                        SET status = :to, reviewed_at = NOW()
                      WHERE id = :id'
                );
                $stmt->execute([':to' => $to, ':id' => $id]);
            }

            $flash = match ($action) {
                'handled' => 'Marked handled. Remember that this site has not replied — you still have to answer '
                    . $found['full_name'] . ' yourself.',
                'archive' => 'Archived.',
                default => 'Moved back to new.',
            };
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

$counts = ['new' => 0, 'handled' => 0, 'archived' => 0];

foreach ($pdo->query('SELECT status, COUNT(*) c FROM enquiries GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $counts[$c['status']] = (int) $c['c'];
}

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if ($kindFilter !== 'all') {
    $where[] = 'kind = :kind';
    $params[':kind'] = $kindFilter;
}

if ($search !== '') {
    // The term is bound, never pasted into the statement, and the two
    // characters that mean "match anything" in LIKE are escaped out of it
    // first, so searching for "100%" looks for a percent sign instead of
    // returning the whole table.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

    // Six separate names for one value: with emulated prepares switched off,
    // MySQL refuses to reuse a single named placeholder in one statement.
    $where[] = '(full_name LIKE :q1 OR business LIKE :q2 OR email LIKE :q3'
        . ' OR phone LIKE :q4 OR topic LIKE :q5 OR message LIKE :q6)';

    foreach (range(1, 6) as $slot) {
        $params[':q' . $slot] = $like;
    }
}

$sql = 'SELECT id, kind, full_name, business, email, phone, address, topic, message,
               status, created_at, reviewed_at, reply_note
          FROM enquiries';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 60';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$listed = count($rows);
$truncated = $listed === 60;

$filesStmt = $pdo->prepare(
    'SELECT id, original_name, detected_type, size_bytes FROM enquiry_files WHERE enquiry_id = :id ORDER BY id'
);

$token = kwetu_admin_csrf();
$total = array_sum($counts);
$previewCount = 0;

/**
 * One POST back to this same view of the list: which message, which action,
 * and the token that says a person pressed the button rather than a form on
 * another site.
 */
$actionForm = static function (
    string $token,
    int $id,
    string $action,
    string $label,
    string $class = '',
    string $placeholder = ''
) use ($self): string {
    $note = $placeholder === ''
        ? ''
        : '<input class="note" type="text" name="note" maxlength="255" '
            . 'aria-label="Note to yourself about this enquiry, kept private" placeholder="'
            . kwetu_e($placeholder) . '">';

    $confirm = $action === 'delete'
        ? ' data-confirm="Delete this enquiry and its attachments permanently? It cannot be undone."'
        : '';

    return '<form class="inline" method="post" action="' . kwetu_e($self) . '"' . $confirm . '>'
        . '<input type="hidden" name="csrf_token" value="' . $token
        . '"><input type="hidden" name="id" value="' . $id
        . '"><input type="hidden" name="action" value="' . $action . '">'
        . $note
        . '<button type="submit"' . ($class === '' ? '' : ' class="' . $class . '"') . '>'
        . kwetu_e($label) . '</button></form>';
};

$cards = '';

if ($rows === []) {
    $scope = $kindFilter === 'all' ? '' : ' matching ' . kwetu_e($kindFilter) . ' requests';

    if ($search !== '') {
        $cards = '<div class="empty"><strong>Nothing found</strong>No message holds the text '
            . '<b>&ldquo;' . kwetu_e($search) . '&rdquo;</b>' . $scope
            . '. Try part of a name, an email address or a phone number instead.</div>';
    } else {
        $cards = '<div class="empty"><strong>This list is empty</strong>Nothing is '
            . kwetu_e($statusFilter === 'all' ? 'held at all' : 'marked ' . kwetu_e($statusFilter))
            . $scope . '. New submissions from the contact and quote forms land here first.</div>';
    }
}

foreach ($rows as $r) {
    $id = (int) $r['id'];
    $when = kwetu_e(kwetu_admin_when($r['created_at'] === null ? null : (string) $r['created_at']));
    $kind = $r['kind'] === 'quote' ? 'Quote request' : 'Contact enquiry';

    $filesStmt->execute([':id' => $id]);
    $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

    $lines = '';

    foreach ([
        'Email' => ['<a href="mailto:' . rawurlencode($r['email']) . '">' . kwetu_e($r['email']) . '</a>', $r['email']],
        'Phone' => [kwetu_e($r['phone']), $r['phone']],
        'Business' => [kwetu_e($r['business']), $r['business']],
        'Address' => [kwetu_e($r['address']), $r['address']],
        $r['kind'] === 'quote' ? 'Service' : 'Topic' => [kwetu_e($r['topic']), $r['topic']],
    ] as $label => [$html, $raw]) {
        if ($raw === null || $raw === '') {
            continue;
        }

        $lines .= '<div><b>' . kwetu_e((string) $label) . '</b>' . $html . '</div>';
    }

    $attachments = '';

    if ($files !== []) {
        // Only these four are ever drawn on the screen. A PDF, .ai, .psd or .svg
        // stays a download, because a vector file can carry a script.
        $previewable = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $thumbs = '';
        $chips = '';

        foreach ($files as $file) {
            $bytes = (int) $file['size_bytes'];
            $size = $bytes >= 1048576
                ? round($bytes / 1048576, 1) . ' MB'
                : max(1, (int) ceil($bytes / 1024)) . ' KB';

            $href = 'attachment.php?file=' . (int) $file['id'];
            $name = kwetu_e($file['original_name']);
            $meta = '<span class="tag">' . $size . ' · ' . kwetu_e($file['detected_type']) . '</span>';

            if (in_array((string) $file['detected_type'], $previewable, true)) {
                $caption = $name . ' · ' . $size;

                $thumbs .= '<button type="button" class="thumb" data-full="' . $href
                    . '" data-caption="' . $caption . '">'
                    . '<img src="' . $href . '" width="180" height="135" loading="lazy" '
                    . 'alt="Picture attached to this request by the client; file named ' . $name . '">'
                    . '<span class="thumb-cap">' . $name . ' ' . $meta . '</span></button>';

                $previewCount++;
            } else {
                $chips .= '<li><a href="' . $href . '">' . $name . '</a> ' . $meta . '</li>';
            }
        }

        if ($thumbs !== '') {
            $attachments .= '<div class="thumbs">' . $thumbs . '</div>';
        }

        if ($chips !== '') {
            $attachments .= '<ul class="files">' . $chips . '</ul>';
        }
    } elseif ($r['kind'] === 'quote') {
        $attachments = '<p class="hint">No files were attached to this request.</p>';
    }

    $note = $r['reply_note'] !== null && $r['reply_note'] !== ''
        ? '<div class="hint">Note: ' . kwetu_e((string) $r['reply_note']) . '</div>'
        : '';

    // A message moved back to new keeps the timestamp of the decision that
    // put it there. Showing it would read as " somebody dealt with this", which
    // is exactly the opposite of what "new" means.
    $reviewed = (string) $r['status'] !== 'new' && $r['reviewed_at'] !== null
        ? '<span>decided ' . kwetu_e(kwetu_admin_when((string) $r['reviewed_at'])) . '</span>'
        : '';

    $rowStatus = (string) $r['status'];
    $buttons = '';

    // Offered by what this message actually is, not by the tab it happens to
    // be listed under - the "all" view shows new, handled and archived rows
    // side by side.
    if ($rowStatus === 'new') {
        $buttons .= $actionForm($token, $id, 'handled', 'Mark handled', '', 'Optional note to yourself');
    } else {
        $buttons .= $actionForm($token, $id, 'reopen', 'Move back to new', 'ghost');
    }

    if ($rowStatus !== 'archived') {
        $buttons .= $actionForm($token, $id, 'archive', 'Archive', 'ghost');
    }

    $buttons .= $actionForm($token, $id, 'delete', 'Delete', 'danger');

    $status = kwetu_e($rowStatus);
    $stateWord = $rowStatus === 'new' ? 'unread' : 'read';
    $stateClass = $rowStatus === 'new' ? 'tag is-unread' : 'tag is-read';
    $name = kwetu_e($r['full_name']);
    $message = kwetu_e($r['message']);

    $cards .= <<<HTML
<div class="card is-{$status}">
    <div class="meta">
        <span class="tag">{$kind}</span>
        <span class="{$stateClass}">{$stateWord}</span>
        <span>#{$id}</span>
        <span>received {$when}</span>
        {$reviewed}
        <span>status: {$status}</span>
    </div>
    <div class="who">{$name}</div>
    <div class="detail">{$lines}</div>
    <blockquote>{$message}</blockquote>
    {$attachments}
    {$note}
    <div class="actions">{$buttons}</div>
</div>
HTML;
}

$labels = [
    'new' => 'Waiting to be read',
    'handled' => 'Marked handled',
    'archived' => 'Filed away',
    'all' => 'Every message',
];

$heading = $labels[$statusFilter];

// The sidebar points Quote requests at kind=quote with nothing filed out, so
// that pair is the one tab that lights up.
$current = $kindFilter === 'quote' && $statusFilter === 'all' ? 'quotes' : 'inbox';

$flashHtml = $flash === ''
    ? ''
    : '<div class="notice ' . $flashKind . '" role="alert">' . kwetu_e($flash) . '</div>';

// Carried on every filter link, so narrowing a search does not silently drop
// the words someone typed to find the message in the first place.
$carry = $search === '' ? '' : '&amp;q=' . rawurlencode($search);

$kindLinks = '';

foreach (['all' => 'Both forms', 'contact' => 'Contact', 'quote' => 'Quote requests'] as $key => $label) {
    $now = $key === $kindFilter ? ' aria-current="page"' : '';
    $kindLinks .= '<a href="inbox.php?status=' . $statusFilter . '&amp;kind=' . $key . $carry . '"'
        . $now . '>' . $label . '</a>';
}

$statusLinks = '';

foreach (['new' => 'New', 'handled' => 'Handled', 'archived' => 'Archived', 'all' => 'All'] as $key => $label) {
    $now = $key === $statusFilter ? ' aria-current="page"' : '';
    $number = $key === 'all' ? $total : $counts[$key];
    $statusLinks .= '<a href="inbox.php?status=' . $key . '&amp;kind=' . $kindFilter . $carry . '"'
        . $now . '>' . $label . ' (' . (int) $number . ')</a>';
}

$enquiryWord = (int) $total === 1 ? 'enquiry' : 'enquiries';

$foundLine = $search === ''
    ? $total . ' ' . $enquiryWord . ' in total &middot; ' . $counts['new'] . ' new &middot; '
        . $counts['handled'] . ' handled &middot; ' . $counts['archived'] . ' archived'
    : $listed . ' of ' . $total . ' messages hold &ldquo;' . kwetu_e($search) . '&rdquo;';

$cutLine = $truncated
    ? '<p class="hint">Only the first 60 are shown, newest first. Narrow it with a search or a filter.</p>'
    : '';

$clearLink = $search === ''
    ? ''
    : '<a class="ghost" href="inbox.php?status=' . $statusFilter . '&amp;kind=' . $kindFilter
        . '">Clear the search</a>';

// The lightbox only goes on the page when there is something to light up.
// Every picture needs a way back out, so this is not optional while previews
// exist: a bare browser tab with an image in it has no close button of its own.
$viewer = '';

if ($previewCount > 0) {
    $viewer = <<<'HTML'
<div class="viewer" id="viewer" role="dialog" aria-modal="true" aria-label="Attached picture" hidden>
    <figure class="viewer-frame">
        <img id="viewer-img" alt="">
        <figcaption id="viewer-caption"></figcaption>
    </figure>
    <button type="button" class="viewer-close">Close</button>
    <p class="viewer-hint">Esc closes it as well, and the rest of the inbox is exactly where you left it.</p>
</div>
<script>
(function () {
    "use strict";

    var viewer = document.getElementById("viewer");

    if (!viewer) { return; }

    var image = document.getElementById("viewer-img");
    var caption = document.getElementById("viewer-caption");
    var closeButton = viewer.querySelector(".viewer-close");
    var opener = null;

    function open(button) {
        var name = button.getAttribute("data-caption") || "attached picture";

        opener = button;
        image.alt = "Picture attached to this request, full size; file named " + name;
        image.src = button.getAttribute("data-full");
        caption.textContent = name;
        viewer.hidden = false;
        document.body.style.overflow = "hidden";
        closeButton.focus();
    }

    function close() {
        if (viewer.hidden) { return; }

        viewer.hidden = true;
        image.removeAttribute("src");
        document.body.style.overflow = "";

        if (opener) { opener.focus(); }
    }

    Array.prototype.forEach.call(document.querySelectorAll(".thumb"), function (button) {
        button.addEventListener("click", function () { open(button); });
    });

    closeButton.addEventListener("click", close);

    viewer.addEventListener("click", function (event) {
        if (event.target === viewer) { close(); }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" || event.key === "Esc") { close(); }
    });
})();
</script>
HTML;
}

$searchValue = kwetu_e($search);
$keepStatus = kwetu_e($statusFilter);
$keepKind = kwetu_e($kindFilter);

$body = <<<HTML
{$flashHtml}
<div class="toolbar">
    <form class="search" method="get" action="inbox.php">
        <input type="hidden" name="status" value="{$keepStatus}">
        <input type="hidden" name="kind" value="{$keepKind}">
        <label class="sr-only" for="q">Search the messages</label>
        <input id="q" type="search" name="q" value="{$searchValue}" maxlength="80"
               placeholder="A name, an email address, a number, or words from the message">
        <button type="submit">Search</button>
        {$clearLink}
    </form>
</div>
<p class="count">{$foundLine}</p>
<div class="filters">{$statusLinks}</div>
<div class="filters">{$kindLinks}</div>
{$cards}
{$cutLine}
<p class="hint">These are private messages. Nothing here is published on the website, and this site has not replied to
any of them — answering is done from your own email or phone. "Unread" means nobody has marked the
message handled yet; the inbox has no way of knowing what you did in your own mailbox.</p>
{$viewer}
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
HTML;

echo kwetu_admin_page($heading, $body, $current, true, 'Contact enquiries and quote requests');
