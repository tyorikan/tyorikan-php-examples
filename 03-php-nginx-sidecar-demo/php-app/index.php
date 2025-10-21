<?php

// Database connection details from environment variables
$host = getenv('DB_HOST') ?: 'db'; // Use 'db' as default for local compose environment
$dbname = getenv('DB_DATABASE') ?: 'sample';
$username = getenv('DB_USERNAME') ?: 'user';
$password = getenv('DB_PASSWORD') ?: 'password';

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    // エラー発生時に例外をスローする
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // SELECTの結果をカラム名をキーとする連想配列で取得する
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // SQLインジェクション対策として、ネイティブのプリペアドステートメント機能を利用する
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    $stmt = $pdo->query('SELECT version()');
    $version = $stmt->fetchColumn();

    echo sprintf("Hello %s!\n", getenv('NAME') ?: 'World');
    echo sprintf("Successfully connected to MySQL version: %s\n", $version);

} catch (PDOException $e) {
    header('Content-Type: text/plain', true, 500);
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}