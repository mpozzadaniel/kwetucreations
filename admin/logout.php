<?php
/**
 * Signing out.
 *
 * The session's data goes, the identifier is thrown away rather than reused,
 * and the cookie that pointed at it is expired back to the browser. What is
 * left behind is one empty session holding a single sentence, so the reviewer
 * is told what happened instead of wondering whether the button worked.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_session_start();
kwetu_admin_no_store();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

// Started again on a fresh, random identifier: reusing the id that was just
// destroyed would mean the signed-out tab still carries the number a session
// used to be known by.
session_id(bin2hex(random_bytes(16)));
kwetu_session_start();

kwetu_admin_flash_put('You are signed out. The review screens need the password again.', 'good');

header('Location: login.php');
exit;
