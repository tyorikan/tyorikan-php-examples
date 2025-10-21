<?php

// This endpoint is used by Cloud Run's startup probe to check if the application
// is ready to serve traffic, including database connectivity.

// Database connection details from environment variables
$host = getenv('DB_HOST');
$dbname = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

// Exit early if essential environment variables are not set.
if (empty($host) || empty($dbname) || empty($username) || empty($password)) {
    http_response_code(503); // Service Unavailable
    echo "Error: Database configuration is incomplete.";
    exit;
}

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 2, // Set a short timeout for health checks
];

try {
    // Attempt to connect to the database
    $pdo = new PDO($dsn, $username, $password, $options);

    // If connection is successful, return 200 OK
    http_response_code(200);
    echo "OK";

} catch (PDOException $e) {
    // If connection fails, return 503 Service Unavailable
    http_response_code(503);
    error_log("Health check failed: " . $e->getMessage()); // Log error for debugging
    echo "Error: Database connection failed.";
}