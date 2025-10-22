<?php
header('Content-Type: application/json; charset=utf-8');

$name = htmlspecialchars($_GET['name'] ?? 'World', ENT_QUOTES, 'UTF-8');
echo json_encode(['message' => 'Hello ' . $name . ' from PHP!']);
