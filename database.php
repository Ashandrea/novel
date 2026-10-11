<?php
declare(strict_types=1);

const DB_HOST = 'srv1633.hstgr.io';
const DB_PORT = '3306';
const DB_NAME = 'u542362032_ashandrea';
const DB_USER = 'u542362032_ashandrea';
const DB_PASSWORD = 'Ashandrea1212?';

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('NOVEL_DB_HOST') ?: DB_HOST;
    $port = getenv('NOVEL_DB_PORT') ?: DB_PORT;
    $name = getenv('NOVEL_DB_NAME') ?: DB_NAME;
    $user = getenv('NOVEL_DB_USER') ?: DB_USER;
    $password = getenv('NOVEL_DB_PASSWORD');
    if ($password === false) {
        $password = DB_PASSWORD;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
