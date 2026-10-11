<?php
/**
 * 配置模板 —— 复制这个文件为 config.php，然后按实际情况填写。
 * config.php 含明文口令，务必不要提交到 Git（.gitignore 里已排除）。
 */

return [
    // 本地 phpstudy / XAMPP：直接用 127.0.0.1
    // 1Panel 部署：填容器名（如 mysql）或宿主内网地址（如 172.18.0.1），不要写 localhost
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'yiwenyun',
    'username' => 'root',
    'password' => '这里填数据库口令',

    // 是否允许访客自助注册。上线运营时建议设为 false，改由管理员开号。
    'allow_register' => true,
];
