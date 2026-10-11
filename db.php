<?php
/**
 * 统一的会话初始化 + 数据库连接。
 * 所有 api/*.php 都通过 require 引入本文件，从而拿到 $pdo 和 $_SESSION。
 *
 * 数据库地址/账号/口令不再写在本文件里，而是放在同目录的 config.php。
 * config.php 不要提交到 Git；新环境请复制 config.example.php 改名为 config.php 后填写。
 */

// 只在尚未启动会话时启动，避免重复调用 session_start() 产生 Notice 污染 JSON 输出
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// 说明：原来的 Access-Control-Allow-Origin: * 已移除。
// 本项目的页面和接口同源部署，放开跨域会让任何第三方站点都能带着用户 Cookie 调用接口。
// 如果以后把前端拆到独立域名，再在这里按白名单精确添加。

$__configFile = __DIR__ . '/config.php';
if (!is_file($__configFile)) {
    echo json_encode([
        'success' => false,
        'msg' => '缺少配置文件 config.php，请复制 config.example.php 并按实际情况填写数据库连接信息。'
    ]);
    exit;
}

$config = require $__configFile;

$host     = $config['host']     ?? '127.0.0.1';
$port     = $config['port']     ?? 3306;
$dbname   = $config['dbname']   ?? 'yiwenyun';
$username = $config['username'] ?? 'root';
$password = $config['password'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'msg' => '資料庫連接失敗: ' . $e->getMessage()
    ]);
    error_log('資料庫連接錯誤: ' . $e->getMessage());
    exit;
}
