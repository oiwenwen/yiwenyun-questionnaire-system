<?php
session_start();

// 清空所有 session 数据
$_SESSION = [];

// 删除 session cookie（可选，更彻底）
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 销毁 session
session_destroy();

echo json_encode(['success' => true, 'msg' => '已退出登录']);
?>