<?php
/**
 * Account settings: change the review password.
 *
 * The same door the terminal script uses, from inside a signed-in session. It
 * reuses everything the sign-in page already relies on — the hash file, the
 * address throttle, the token — rather than a second idea of what a password
 * is, so there is exactly one place a password lives and one rule about how
 * long it has to be.
 *
 * Three things this screen will not do:
 *   - change the password without the current one, typed in this request;
 *   - put a password, or anything about the old one, into the page it draws
 *     after the form is submitted. The answer is always a redirect, so the
 *     browser lands on a clean GET whose boxes are empty by construction;
 *   - send the new password back to the screen that just set it. A person who
 *     typed it twice already knows it.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

$ipHash = kwetu_ip_hash();
$minimum = kwetu_admin_password_min_length();

// Like the sign-in page, this screen has to work when MySQL is down: the
// password is a file, not a row. Without a connection the counter kept in this
// session is all that is left, so the throttle is still there in some form.
try {
    $pdo = kwetu_db();
} catch (Throwable $connectionFailed) {
    $pdo = null;
    error_log('kwetu admin: no database, the change-password screen is limited to the session counter.');
}

// How many times the current password can be mistyped on this screen before
// it stops listening. Tighter than the sign-in page on purpose: whoever reaches
// here is already inside a session, so the question being answered is whether
// they are the person who signed in.
$maximumAttempts = 5;
$lockSeconds = 900;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only a string counts as an answer. A field sent as ?current_password[]=a
    // is a malformed request, not a guess, and casting it would raise a
    // TypeError in the middle of a page the owner is trying to use.
    $given = static function (string $name): string {
        $value = $_POST[$name] ?? null;

        return is_string($value) ? $value : '';
    };

    $current = $given('current_password');
    $fresh = $given('new_password');
    $again = $given('confirm_password');

    $attempts = (int) ($_SESSION['pw_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['pw_lock_until'] ?? 0);
    $flash = '';
    $flashKind = 'bad';

    $waitingSince = $lockedUntil > time() ? $lockedUntil - time() : 0;
    $addressWaiting = $pdo instanceof PDO ? kwetu_admin_lock_seconds($pdo, $ipHash) : 0;

    if ($waitingSince > 0) {
        $flash = 'Too many failed attempts on this form. Wait ' . $waitingSince . ' seconds and try again.';
    } elseif ($addressWaiting > 0) {
        $flash = 'Too many failed attempts from this address. Wait ' . $addressWaiting . ' seconds and try again.';
    } elseif (!kwetu_admin_csrf_valid($_POST['csrf_token'] ?? null)) {
        // Not counted as a failure: an expired token says the tab was left open,
        // not that someone is guessing.
        $flash = 'That form had expired. Nothing was changed. Reload this page and try again.';
    } elseif (!kwetu_admin_is_configured()) {
        $flash = 'No password is set on this machine, so there is nothing to change here yet.';
    } elseif ($current === '') {
        $flash = 'Type your current password first.';
    } elseif (!kwetu_admin_password_ok($current)) {
        // One sentence, because anything longer — "that one is close", "the
        // first half matches" — is a hint about the password you are protecting.
        $_SESSION['pw_attempts'] = $attempts + 1;

        if ($_SESSION['pw_attempts'] >= $maximumAttempts) {
            $_SESSION['pw_lock_until'] = time() + $lockSeconds;
            $_SESSION['pw_attempts'] = 0;
        }

        if ($pdo instanceof PDO) {
            kwetu_admin_record_failure($pdo, $ipHash);
        }

        usleep(400000);

        $flash = 'That is not your current password.';
    } else {
        // The current password was right, so from here a complaint costs nothing
        // against the throttle — the owner is allowed to be told what is wrong
        // with what they typed, and told plainly.
        $problem = kwetu_admin_password_problem($fresh);

        if ($problem !== '') {
            $flash = $problem;
        } elseif ($again !== $fresh) {
            $flash = 'The new password and the confirmation do not match.';
        } elseif (kwetu_admin_password_ok($fresh)) {
            $flash = 'That is already the password in use. Choose a different one.';
        } elseif (!kwetu_admin_password_write($fresh)) {
            // Deliberately vague: the reason is a filesystem problem and the
            // person reading this cannot fix it from a browser.
            $flash = 'The new password could not be saved. Nothing was changed — ask whoever looks after this server.';
        } else {
            $_SESSION['pw_attempts'] = 0;
            $_SESSION['pw_lock_until'] = 0;

            if ($pdo instanceof PDO) {
                kwetu_admin_clear_failures($pdo, $ipHash);
            }

            // A new identifier for this session, and the new file stamp, so
            // this tab carries on while every other one is refused.
            session_regenerate_id(true);
            $_SESSION['admin_cred'] = kwetu_admin_credential_stamp();
            kwetu_admin_csrf_reset();

            $flashKind = 'good';
            $flash = 'Password changed. Every other review screen is signed out and needs the new password; this one stays open.';
        }
    }

    kwetu_admin_flash_put($flash, $flashKind);
    header('Location: password.php', true, 303);
    exit;
}

$flash = '';
$flashKind = 'good';

$taken = kwetu_admin_flash_take();
if ($taken['text'] !== '') {
    $flash = '<div class="notice ' . ($flashKind === 'good' ? 'good' : 'bad') . '" role="alert">'
        . kwetu_e($taken['text']) . '</div>';
}

// Read from the same clock the session check uses. If the file has no readable
// date there is nothing to claim, so the screen says so instead of guessing.
$stamp = kwetu_admin_credential_stamp();
$lastChanged = kwetu_admin_ago($stamp > 0 ? $stamp : null);
$token = kwetu_admin_csrf();

/**
 * One password box: label, field, and the show/hide control that belongs to it.
 * Same markup the sign-in card uses, so a password box on this screen is the
 * same object as the one on that screen.
 */
$passwordField = static function (string $id, string $label, string $autoComplete, string $hint, string $rules): string {
    $buttonId = 'reveal-' . $id;
    $labelId = $buttonId . '-label';

    return <<<HTML
<label for="{$id}">{$label}</label>
<div class="field">
    <input type="password" id="{$id}" name="{$id}"
           autocomplete="{$autoComplete}" placeholder="{$hint}" {$rules}>
    <button type="button" class="reveal" id="{$buttonId}" aria-pressed="false" aria-controls="{$id}">
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
        <span class="sr-only" id="{$labelId}">Show this password while you type it</span>
    </button>
</div>
HTML;
};

$rules = 'required minlength="' . $minimum . '" maxlength="72"';

$body = <<<HTML
<div class="settings">
    <form class="card settings-form" method="post" action="password.php" id="password-form">
        <h2>Change the review password</h2>
        <p class="hint">Your current password, then the new one twice. At least {$minimum} characters,
        no further than 72.</p>
        {$flash}
        <input type="hidden" name="csrf_token" value="{$token}">
        {$passwordField('current_password', 'Current password', 'current-password', 'The password you sign in with', 'required')}
        {$passwordField('new_password', 'New password', 'new-password', 'At least {$minimum} characters', $rules)}
        {$passwordField('confirm_password', 'Confirm new password', 'new-password', 'Type the new password again', $rules)}
        <button type="submit" class="signin" id="change-btn">
            <span class="spin" aria-hidden="true"></span><span id="change-text">Change password</span>
        </button>
        <p class="revealed-hint">Nothing is saved until the button says it worked. If it does, the new
        password is the only one that will sign in from now on.</p>
    </form>
    <aside class="card settings-side">
        <h2>How this password works</h2>
        <p>This installation has one shared review password and no user names. Only a one-way hash of it
        is kept, and the file holding that hash is not reachable from the web.</p>
        <p>Changing it here signs out every other review screen that is open. They will need the new
        password the next time they are used.</p>
        <p class="hint">Last changed: <b>{$lastChanged}</b></p>
        <h2>If it is lost</h2>
        <p>There is no forgotten-password link and no reset by email — a public page that sets a password
        without proving who is asking would be a second door into this desk. Recovery is from a terminal on
        the machine that hosts it:</p>
        <pre>php admin/set-password.php</pre>
        <p>That prints a new password once, and signs out every open session.</p>
    </aside>
</div>
HTML;

$script = <<<'SCRIPT'
<script>
(function () {
    "use strict";

    var form = document.getElementById("password-form");
    var button = document.getElementById("change-btn");
    var buttonText = document.getElementById("change-text");
    var fresh = document.getElementById("new_password");
    var again = document.getElementById("confirm_password");

    // Whatever the browser tried to put back after a reload, this page has no
    // business showing. A password form only ever opens empty.
    Array.prototype.forEach.call(
        document.querySelectorAll("#password-form input[type=password]"),
        function (field) { field.value = ""; }
    );

    Array.prototype.forEach.call(
        document.querySelectorAll("#password-form .reveal"),
        function (control) {
            var field = document.getElementById(control.getAttribute("aria-controls"));
            var caption = document.getElementById(control.id + "-label");

            if (!field || !caption) { return; }

            control.addEventListener("click", function () {
                var shown = field.type === "text";
                var caret = field.value.length;

                field.type = shown ? "password" : "text";
                control.setAttribute("aria-pressed", shown ? "false" : "true");
                caption.textContent = shown
                    ? "Show this password while you type it"
                    : "Hide this password again";
                field.focus();

                try { field.setSelectionRange(caret, caret); } catch (notSupported) {}
            });
        }
    );

    function compare() {
        if (!fresh || !again) { return; }

        again.setCustomValidity(fresh.value === again.value
            ? ""
            : "Type the same password in both boxes.");
    }

    if (fresh && again) {
        fresh.addEventListener("input", compare);
        again.addEventListener("input", compare);
    }

    if (form && button && buttonText) {
        form.addEventListener("submit", function (event) {
            compare();

            // The browser has already refused a blank or too-short box before
            // this runs, so a blocked submit never leaves a dead button behind.
            if (!form.checkValidity()) {
                event.preventDefault();
                again.reportValidity();
                return;
            }

            button.disabled = true;
            button.setAttribute("aria-busy", "true");
            buttonText.textContent = "Saving";

            window.setTimeout(function () {
                button.disabled = false;
                button.removeAttribute("aria-busy");
                buttonText.textContent = "Change password";
            }, 15000);
        });
    }
}());
</script>
SCRIPT;

echo kwetu_admin_page('Account settings', $body . $script, 'settings', true, 'Change the review password');
