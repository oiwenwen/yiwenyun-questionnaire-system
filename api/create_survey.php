<?php
require '../db.php';  // 连接数据库和 session

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '未登录']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$title = trim($data['title'] ?? '');
$description = $data['description'] ?? '';
$status = $data['status'] ?? 'draft';  // draft 或 published
$questions = $data['questions'] ?? [];

if (!$title) {
    echo json_encode(['success' => false, 'msg' => '问卷标题不能为空']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // 1. 插入问卷主表（移除了access_type字段）
    $stmt = $pdo->prepare("
        INSERT INTO surveys (title, description, user_id, status) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$title, $description, $user_id, $status]);
    $survey_id = $pdo->lastInsertId();

    // 2. 插入每个问题
    foreach ($questions as $q_index => $q) {
        $stmt = $pdo->prepare("INSERT INTO questions (survey_id, title, type, required, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$survey_id, $q['title'], $q['type'], $q['required'] ? 1 : 0, $q_index]);
        $question_id = $pdo->lastInsertId();

        // 3. 插入选项（如果有）
        if (!empty($q['options']) && is_array($q['options'])) {
            foreach ($q['options'] as $o_index => $option_text) {
                if (trim($option_text) !== '') {
                    $stmt = $pdo->prepare("INSERT INTO options (question_id, text, sort_order) VALUES (?, ?, ?)");
                    $stmt->execute([$question_id, trim($option_text), $o_index]);
                }
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'msg' => '问卷保存成功！', 'survey_id' => $survey_id]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'msg' => '保存失败，请重试: ' . $e->getMessage()]);
}
?>