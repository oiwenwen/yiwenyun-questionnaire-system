<?php
/**
 * 重置用户密码（命令行工具，不通过网页访问）。
 *
 * 用途：忘了登录密码时使用。本工具只改 users.password，不动任何其他数据。
 *
 * 用法：
 *   php reset_password.php <用户名> <新密码> [角色]
 *
 * 例：
 *   php reset_password.php zhangsan MyNewPass123
 *
 * 想顺便调整角色，可以加第三个参数：admin / editor / viewer
 *   php reset_password.php zhangsan MyNewPass123 admin
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只允许在命令行下运行。');
}

if ($argc < 3) {
    echo "用法: php reset_password.php <用户名> <新密码> [角色]\n";
    echo "角色可选: admin / editor / viewer（默认不改动）\n";
    exit(1);
}

$username = $argv[1];
$password = $argv[2];
$role     = $argv[3] ?? null;

if (strlen($password) < 6) {
    echo "密码至少 6 位。\n";
    exit(1);
}
if ($role !== null && !in_array($role, ['admin', 'editor', 'viewer'], true)) {
    echo "角色只能是 admin / editor / viewer。\n";
    exit(1);
}

$configFile = __DIR__ . '/../../config.php';
if (!is_file($configFile)) {
    echo "找不到配置文件: $configFile\n";
    exit(1);
}
$cfg = require $configFile;

try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'],
        $cfg['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo "用户不存在: $username\n";
        echo "（现有用户：";
        foreach ($pdo->query("SELECT username FROM users")->fetchAll(PDO::FETCH_COLUMN) as $u) {
            echo $u . ' ';
        }
        echo "）\n";
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($role !== null) {
        $stmt = $pdo->prepare("UPDATE users SET password = ?, role = ?, status = 'active' WHERE id = ?");
        $stmt->execute([$hash, $role, $user['id']]);
        echo "已重置 {$user['username']} 的密码，角色设为 {$role}。\n";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET password = ?, status = 'active' WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);
        echo "已重置 {$user['username']} 的密码（当前角色 {$user['role']}）。\n";
    }
} catch (PDOException $e) {
    echo "数据库操作失败: " . $e->getMessage() . "\n";
    exit(1);
}
