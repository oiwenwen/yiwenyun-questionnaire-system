<?php
/**
 * 注册接口。
 *
 * 修复记录：原文件对任何人开放注册，且注册出来的是 editor 角色。
 * 线上会被人用脚本批量刷账号。现在由 config.php 的 allow_register 开关控制，
 * 部署到公网时把它设为 false，需要开号时再临时打开或由管理员在数据库里加。
 */

require '../db.php';  // 引入数据库连接和 session

if (empty($config['allow_register'])) {
    echo json_encode(['success' => false, 'msg' => '本站已关闭自助注册，请联系管理员开通账号']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'msg' => '请填写用户名和密码']);
    exit;
}

if (mb_strlen($username) > 50) {
    echo json_encode(['success' => false, 'msg' => '用户名过长']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'msg' => '密码至少6位']);
    exit;
}

// 检查用户名是否已存在
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'msg' => '用户名已存在']);
    exit;
}

// 生成密码哈希（安全！）
$hash = password_hash($password, PASSWORD_DEFAULT);

// 插入新用户
$stmt = $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'editor', 'active')");
$stmt->execute([$username, $hash]);

// 注册成功，自动登录
$stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$_SESSION['user_id']  = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];

echo json_encode([
    'success' => true,
    'user' => [
        'username' => $user['username'],
        'role'     => $user['role']
    ]
]);
