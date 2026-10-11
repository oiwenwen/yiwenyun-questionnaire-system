<?php
/**
 * 读取单份问卷（含题目与选项）。
 *
 * 用途有两个：
 *   1. 后台「编辑问卷」：作者本人读取自己的问卷（草稿也可以）。
 *   2. 公开填写页 fill.html?id=N：匿名访客读取，只允许读取「已发布」的问卷。
 *
 * 修复记录：
 *   - 原文件整段代码被重复粘贴了两遍，会输出两段拼接的 JSON，
 *     导致 fill.html 与后台编辑功能的 response.json() 解析失败。此处已删除重复部分。
 *   - 原文件不校验问卷状态，未发布的草稿只要猜到 id 就能被外人填写。现按上面的规则收紧。
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$response = ['success' => false, 'msg' => '未知錯誤'];

try {
    $survey_id = (int) ($_GET['id'] ?? 0);
    if ($survey_id <= 0) {
        throw new Exception('無效的問卷ID');
    }

    // 獲取問卷（連 user_id 一起取，用于判断是否是作者本人）
    $stmt = $pdo->prepare("
        SELECT id, user_id, title, description, status
        FROM surveys
        WHERE id = ?
    ");
    $stmt->execute([$survey_id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        throw new Exception('問卷不存在');
    }

    // 未發布的問卷只有作者本人能看到（后台编辑草稿时走的就是这里）
    if ($survey['status'] !== 'published') {
        $isOwner = isset($_SESSION['user_id'])
            && (int) $_SESSION['user_id'] === (int) $survey['user_id'];

        if (!$isOwner) {
            throw new Exception('該問卷尚未發布，暫時無法填寫');
        }
    }

    // 獲取問題
    $stmt = $pdo->prepare("
        SELECT id, title, type, required
        FROM questions
        WHERE survey_id = ?
        ORDER BY sort_order
    ");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 為每個問題查詢選項
    foreach ($questions as &$q) {
        if (in_array($q['type'], ['radio', 'checkbox'], true)) {
            $stmt = $pdo->prepare("
                SELECT id, text
                FROM options
                WHERE question_id = ?
                ORDER BY sort_order
            ");
            $stmt->execute([$q['id']]);
            $q['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $q['options'] = [];
        }
    }
    unset($q);

    // 对外的返回体里去掉 user_id，避免泄露作者账号信息
    unset($survey['user_id']);

    $response = [
        'success'   => true,
        'survey'    => $survey,
        'questions' => $questions
    ];
} catch (Exception $e) {
    $response['msg'] = $e->getMessage();
} catch (PDOException $e) {
    $response['msg'] = '資料庫錯誤: ' . $e->getMessage();
}

echo json_encode($response);
