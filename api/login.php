<?php
/**
 * 登录接口。
 *
 * 修复记录：原文件用 error_log 记录了用户名、**明文密码**以及数据库里的密码哈希。
 * 服务器日志会被运维、备份、甚至别的程序读到，明文密码不该出现在任何日志里，
 * 这里已全部删除，只保留便于排查的失败原因（用户名，不含密码）。
 */

require '../db.php';  // 引入数据库连接和 session

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'msg' => '请输入用户名和密码']);
    exit;
}

// 查询用户
$stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND status = 'active'");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password'])) {
    // 防止会话固定攻击：登录成功后换一个 session id
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

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
} else {
    // 只记用户名，不记密码；也不透露"用户不存在"还是"密码错误"
    error_log('登录失败: ' . $username);
    echo json_encode(['success' => false, 'msg' => '用户名或密码错误']);
}
