<?php
/**
 * 提交答卷（填寫頁使用）。
 *
 * 修复记录：
 *   1. 原文件先自己 session_start()，随后 require db.php 里又 session_start()，
 *      PHP 会抛 Notice；一旦开启 display_errors，输出会跑到 JSON 前面，前端直接解析失败。
 *      现在统一交给 db.php 处理会话。
 *   2. 原文件把 $_POST 里任何 q<数字> 字段都当成题目 ID 直接入库，
 *      不检查这道题属不属于本问卷、必答项有没有答。现在补上这些校验。
 */

require '../db.php';

$survey_id = (int) ($_POST['survey_id'] ?? 0);
if ($survey_id <= 0) {
    echo json_encode(['success' => false, 'msg' => '无效的问卷ID']);
    exit;
}

try {
    // 问卷必须存在且已发布
    $stmt = $pdo->prepare("SELECT id, title, status FROM surveys WHERE id = ?");
    $stmt->execute([$survey_id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        throw new Exception('问卷不存在');
    }
    if ($survey['status'] !== 'published') {
        throw new Exception('该问卷尚未发布，无法提交');
    }
    $survey_title = $survey['title'];

    // 取出本问卷的题目，用于校验归属与必答项
    $stmt = $pdo->prepare("SELECT id, title, required FROM questions WHERE survey_id = ?");
    $stmt->execute([$survey_id]);
    $question_map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $question_map[(int) $q['id']] = $q;
    }

    // 收集本次提交的答案，并过滤掉不属于本问卷的题目
    $submitted = [];   // question_id => answer_text
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'q') !== 0) {
            continue;
        }
        $question_id = (int) substr($key, 1);
        if ($question_id <= 0 || !isset($question_map[$question_id])) {
            continue;   // 不属于本问卷的题目，直接忽略
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $answer_text = implode(', ', $parts);
        } else {
            $answer_text = trim((string) $value);
        }

        if ($answer_text !== '') {
            $submitted[$question_id] = $answer_text;
        }
    }

    // 必答项校验
    $missing = [];
    foreach ($question_map as $qid => $q) {
        if (!empty($q['required']) && !isset($submitted[$qid])) {
            $missing[] = $q['title'];
        }
    }
    if ($missing) {
        throw new Exception('请填写必答题：' . implode('、', $missing));
    }

    if (!$submitted) {
        throw new Exception('没有收到任何答案');
    }

    $pdo->beginTransaction();

    // 1. 插入提交记录
    $stmt = $pdo->prepare("INSERT INTO responses (survey_id, ip_address, submit_time) VALUES (?, ?, NOW())");
    $stmt->execute([$survey_id, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    $response_id = $pdo->lastInsertId();

    // 2. 插入每个问题的答案
    $stmt = $pdo->prepare("INSERT INTO answers (response_id, question_id, answer_text) VALUES (?, ?, ?)");
    foreach ($submitted as $question_id => $answer_text) {
        $stmt->execute([$response_id, $question_id, $answer_text]);
    }

    $pdo->commit();

    echo json_encode([
        'success'      => true,
        'msg'          => '提交成功！感谢您填写问卷。',
        'survey_title' => $survey_title
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'msg' => '提交失败：' . $e->getMessage()]);
}
