<?php
/**
 * Prints a random value to paste into local-config.php as ip_salt.
 * Run from a terminal:  php backend/make-secret.php
 */

echo bin2hex(random_bytes(32)), PHP_EOL;
