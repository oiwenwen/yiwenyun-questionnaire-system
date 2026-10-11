<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    echo json_encode([
        'success' => true,
        'user' => [
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'] ?? 'editor'
        ]
    ]);
} else {
    echo json_encode(['success' => false]);
}
?>