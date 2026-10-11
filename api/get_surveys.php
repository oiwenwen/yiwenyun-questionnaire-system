<?php
require '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '未登录']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, title, description, status, created_at FROM surveys WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $surveys
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'msg' => '加载失败'
    ]);
}
?>