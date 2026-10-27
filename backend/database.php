<?php
/**
 * Single place that opens a MySQL connection, so every backend file gets the
 * same hardening: real prepared statements, exceptions instead of silent
 * failures, and no credentials leaking into an error message.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function kwetu_pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
}

function kwetu_server_pdo(): PDO
{
    $db = kwetu_config()['db'];

    return new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']),
        $db['user'],
        $db['password'],
        kwetu_pdo_options()
    );
}

function kwetu_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $config = kwetu_config();

    try {
        $pdo = new PDO(kwetu_dsn($config['db']), $config['db']['user'], $config['db']['password'], kwetu_pdo_options());
    } catch (PDOException $e) {
        // A database error page is a good way to hand out connection details.
        // The reason goes to the server log; the visitor gets one plain sentence.
        error_log('kwetu intake: database connection failed: ' . $e->getMessage());
        throw new RuntimeException('The enquiry service is temporarily unavailable.');
    }

    return $pdo;
}
