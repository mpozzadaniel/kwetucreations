<?php
/**
 * The only door into admin/. Password comparison is bcrypt against the hash
 * in backend/admin-hash.php, so the plain password exists only in the
 * submitter's request body and is never written down anywhere.
 *
 * A wrong password answers with a redirect, not with a page. That is
 * deliberate: a form that redraws itself as the answer to its own POST lets
 * the browser carry what was just typed into the new copy of the field, and
 * lets Enter on the keyboard send the same guess again. Sent back to a clean
 * GET instead, the box is empty every time this page is drawn, and Refresh
 * repeats a read rather than a sign-in attempt.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_session_start();
kwetu_admin_no_store();

if (!kwetu_admin_ip_allowed()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "This address is not on the list allowed to reach the review screens.\n";
    echo "If you are the site owner, add it to admin_allow_from in backend/local-config.php.\n";
    exit;
}

if (!empty($_SESSION['admin_ok'])) {
    header('Location: dashboard.php');
    exit;
}

$configured = kwetu_admin_is_configured();
$ipHash = kwetu_ip_hash();

// The sign-in page must still open when MySQL is down, so the connection is
// optional here: without it the session counter below is all that is left.
try {
    $pdo = kwetu_db();
} catch (Throwable $connectionFailed) {
    $pdo = null;
    error_log('kwetu admin: no database, falling back to the session lockout only.');
}

$attempts = (int) ($_SESSION['login_attempts'] ?? 0);
$lockedUntil = (int) ($_SESSION['login_lock_until'] ?? 0);
$lockedSeconds = $pdo instanceof PDO ? kwetu_admin_lock_seconds($pdo, $ipHash) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notice = '';

    // Two counters, because they stop two different things. The one in the
    // session costs an attacker nothing but is cheap to run. The one against
    // the address fingerprint survives them clearing cookies.
    if ($lockedSeconds > 0) {
        $notice = 'Too many failed attempts from this address. Wait ' . $lockedSeconds . ' seconds.';
    } elseif ($attempts >= 6 && $lockedUntil > time()) {
        $notice = 'Too many failed attempts. Wait ' . ($lockedUntil - time()) . ' seconds and try again.';
    } elseif (!kwetu_admin_csrf_valid($_POST['csrf_token'] ?? null)) {
        $notice = 'That form had expired. Try again.';
    } elseif (!$configured) {
        $notice = 'No password has been set yet.';
    } else {
        if ($attempts >= 6) {
            // The session lock had already run out, so the number sitting in
            // the session no longer means anything. Start again.
            $attempts = 0;
            $_SESSION['login_attempts'] = 0;
        }

        if (kwetu_admin_password_ok((string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);

            $_SESSION['admin_ok'] = true;
            $_SESSION['admin_at'] = time();
            $_SESSION['admin_seen'] = time();
            $_SESSION['login_attempts'] = 0;

            // Which password this session was earned with. If it is replaced
            // later, this stops matching and the session ends on its next
            // request — see kwetu_admin_credential_stamp().
            $_SESSION['admin_cred'] = kwetu_admin_credential_stamp();

            unset($_SESSION['login_lock_until'], $_SESSION['csrf']);

            kwetu_admin_csrf_reset();

            if ($pdo instanceof PDO) {
                kwetu_admin_clear_failures($pdo, $ipHash);
            }

            header('Location: dashboard.php');
            exit;
        }

        // A wrong answer costs a little time, so guessing at it in a loop is
        // slower than the lockout alone would make it.
        usleep(400000);

        if ($pdo instanceof PDO) {
            kwetu_admin_record_failure($pdo, $ipHash);
        }

        $_SESSION['login_attempts'] = $attempts + 1;

        if ($attempts + 1 >= 6) {
            $_SESSION['login_lock_until'] = time() + 300;
            $notice = 'Too many failed attempts. Wait 300 seconds.';
        } else {
            $notice = 'That password is not right.';
        }
    }

    kwetu_admin_flash_put($notice, 'bad');

    header('Location: login.php');
    exit;
}

// What is left is a fresh GET: the only way this page ever draws itself.
$noticeText = '';
$noticeKind = 'bad';

if ($lockedSeconds > 0) {
    $noticeText = 'Too many failed attempts from this address. Wait ' . $lockedSeconds . ' seconds.';
} elseif ($attempts >= 6 && $lockedUntil > time()) {
    $noticeText = 'Too many failed attempts. Wait ' . ($lockedUntil - time()) . ' seconds and try again.';
} else {
    $flash = kwetu_admin_flash_take();
    $noticeText = $flash['text'];
    $noticeKind = $flash['kind'];
}

$token = kwetu_admin_csrf();

// A sign-out is a thing that went right, so it is not allowed to wear the
// same red as a rejected password.
$notice = $noticeText === ''
    ? ''
    : '<div class="notice ' . ($noticeKind === 'good' ? 'good' : 'bad') . '" role="alert">'
        . kwetu_e($noticeText) . '</div>';

if (!$configured) {
    $body = <<<HTML
<div class="login-card is-single">
    <div class="login-form setup">
        <h2>No review password is set on this machine yet</h2>
        <p class="login-sub">Until one exists there is nothing to sign in with.</p>
        <div class="notice bad">
            Open a terminal in the project folder and run:
            <pre>php admin/set-password.php</pre>
            It prints one password. Keep it. Reload this page afterwards.
        </div>
        <p class="login-help">If you are only looking at the website, you do not need this page at
        all &mdash; it is for the person who decides which testimonials go on it.</p>
    </div>
</div>
<p class="login-sign">Kwetu Creations &middot; private review desk</p>
HTML;

    echo kwetu_admin_page('Sign in', $body, '', false);
    exit;
}

// No autofocus on purpose. A credential field that takes focus the moment the
// page arrives is filled by the browser before anyone asks for it, which is
// how this box used to look already typed in. The password manager still
// works - it is offered whenever the field is clicked into.
$body = <<<HTML
<div class="login-card">
    <aside class="login-brand">
        <span class="login-mark"><img class="login-logo" src="../images/logo/mark.webp?v=3" alt="" width="120" height="120" decoding="async">KWETU<span class="w-dot">.</span> <span class="w-red">CREATIONS</span></span>
        <span class="login-rule" aria-hidden="true"></span>
        <h1 class="login-title">Admin Sign In</h1>
        <p class="login-lede">Access your business dashboard.</p>
        <ul class="login-points">
            <li>Contact enquiries and quote requests, with the files that came with them.</li>
            <li>Testimonials waiting to be read, approved or kept back.</li>
            <li>Nothing a client sent is public until you say so.</li>
        </ul>
    </aside>
    <div class="login-form">
        <h2>Review desk</h2>
        <p class="login-sub">Enter the review password for this installation.</p>
        {$notice}
        <form method="post" action="login.php" id="signin-form">
            <input type="hidden" name="csrf_token" value="{$token}">
            <label for="password">Review password</label>
            <div class="field">
                <input type="password" id="password" name="password"
                       autocomplete="current-password" placeholder="Your review password" required>
                <button type="button" class="reveal" id="reveal" aria-pressed="false" aria-controls="password">
                    <svg class="eye-on" width="19" height="19" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">
                        <path d="M2 12s3.7-6.6 10-6.6 10 6.6 10 6.6-3.7 6.6-10 6.6S2 12 2 12z"/>
                        <circle cx="12" cy="12" r="2.7"/>
                    </svg>
                    <svg class="eye-off" width="19" height="19" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">
                        <path d="M2 12s3.7-6.6 10-6.6 10 6.6 10 6.6-3.7 6.6-10 6.6S2 12 2 12z"/>
                        <circle cx="12" cy="12" r="2.7"/>
                        <line x1="4" y1="20" x2="20" y2="4"/>
                    </svg>
                    <span class="sr-only" id="reveal-label">Show the password you typed</span>
                </button>
            </div>
            <button type="submit" class="signin" id="signin-btn">
                <span class="spin" aria-hidden="true"></span><span id="signin-text">Sign In</span>
            </button>
        </form>
        <p class="login-help">This desk has one shared password and no user names, so there is nothing
        to sign up for and no forgotten-password link. Once you are in, change it under
        <b>Change password</b>. If it is lost, whoever looks after this server can reset it from a
        terminal with <b>php admin/set-password.php</b>.</p>
    </div>
</div>
<p class="login-sign">Kwetu Creations &middot; private review desk</p>
HTML;

$script = <<<'SCRIPT'
<script>
(function () {
    "use strict";

    var button = document.getElementById("reveal");
    var input = document.getElementById("password");
    var label = document.getElementById("reveal-label");
    var form = document.getElementById("signin-form");
    var signIn = document.getElementById("signin-btn");
    var signInText = document.getElementById("signin-text");

    if (input) { input.value = ""; }

    if (button && input && label) {
        button.addEventListener("click", function () {
            var shown = input.type === "text";
            var caret = input.value.length;

            input.type = shown ? "password" : "text";
            button.setAttribute("aria-pressed", shown ? "false" : "true");
            label.textContent = shown
                ? "Show the password you typed"
                : "Hide the password you typed";
            input.focus();

            try { input.setSelectionRange(caret, caret); } catch (notSupported) {}
        });
    }

    if (form && signIn && signInText) {
        form.addEventListener("submit", function () {
            // The browser's own "this field is required" answer fires before
            // this listener, so a blocked submit never greys the button out.
            if (!form.checkValidity()) { return; }

            signIn.disabled = true;
            signIn.setAttribute("aria-busy", "true");
            signInText.textContent = "Signing in";

            // A sign-in that never came back should not leave the person
            // looking at a dead button; the page is still there to answer.
            window.setTimeout(function () {
                signIn.disabled = false;
                signIn.removeAttribute("aria-busy");
                signInText.textContent = "Sign In";
            }, 15000);
        });
    }
})();
</script>
SCRIPT;

echo kwetu_admin_page('Sign in', $body . $script, '', false);
