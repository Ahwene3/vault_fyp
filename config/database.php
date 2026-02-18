<?php
/**
 * Database configuration - Final Year Project Vault
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'vault_fyp');
define('DB_USER', 'root');
define('DB_PASS', '7903@Dev');
define('DB_CHARSET', 'utf8mb4');
define('BASE_PATH', '/vault'); // Web path to app root

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    }
    return $pdo;
}
