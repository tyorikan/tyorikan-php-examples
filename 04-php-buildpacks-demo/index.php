<?php
header('Content-Type: application/json; charset=utf-8');

$name = $_GET['name'] ?? 'World';
echo json_encode(['message' => 'Hello ' . htmlspecialchars($name) . ' from PHP!']);
