<?php
/**
 * admin/ with no page named on it. A signed-in person lands on the dashboard;
 * anyone else is sent to the sign-in page by the rule inside it.
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

kwetu_admin_login_required();

header('Location: dashboard.php');
