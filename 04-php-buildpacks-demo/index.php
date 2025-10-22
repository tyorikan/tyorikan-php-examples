<?php
echo json_encode(['message' => 'Hello ' . htmlspecialchars($_GET['name']) . ' from PHP!']);
