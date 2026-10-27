<?php
/**
 * Creates the database and table from backend/schema.sql.
 * Run once:  php backend/setup-database.php
 *
 * Safe to re-run — everything in the schema is CREATE ... IF NOT EXISTS.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script is command-line only.\n";
    echo "Run it from a terminal:  php backend/setup-database.php\n";
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$config = kwetu_config();

try {
    $server = kwetu_server_pdo();
} catch (PDOException $e) {
    fwrite(STDERR, sprintf("Cannot reach MySQL at %s:%d as '%s'.\n%s\n", $config['db']['host'], $config['db']['port'], $config['db']['user'], $e->getMessage()));
    fwrite(STDERR, "Is Apache's MySQL running? Start it in the XAMPP Control Panel.\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/schema.sql');

if ($sql === false) {
    fwrite(STDERR, "Cannot read backend/schema.sql\n");
    exit(1);
}

// Strip whole-line comments before splitting. A semicolon inside a comment
// would otherwise cut a CREATE TABLE in half.
$statements = preg_replace('/^\s*--.*$/m', '', $sql);

foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
    if ($statement === '') {
        continue;
    }

    try {
        $server->exec($statement);
        fwrite(STDOUT, "ok: " . preg_replace('/\s+/', ' ', substr($statement, 0, 58)) . "...\n");
    } catch (PDOException $e) {
        fwrite(STDERR, "failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$db = kwetu_db();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

fwrite(STDOUT, sprintf("\ndatabase '%s' ready\n", $config['db']['name']));
fwrite(STDOUT, "tables: " . implode(', ', $tables) . "\n");

foreach ($tables as $table) {
    $columns = $db->query('DESCRIBE `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDOUT, $table . " (" . count($columns) . " columns): " . implode(', ', $columns) . "\n");
}
