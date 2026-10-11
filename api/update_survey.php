<?php
require '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '未登录']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$survey_id = $data['survey_id'] ?? 0;
$title = trim($data['title'] ?? '');
$description = $data['description'] ?? '';
$questions = $data['questions'] ?? [];

if (!$survey_id || !$title) {
    echo json_encode(['success' => false, 'msg' => '参数错误']);
    exit;
}

// 检查权限
$stmt = $pdo->prepare("SELECT id, status FROM surveys WHERE id = ? AND user_id = ?");
$stmt->execute([$survey_id, $_SESSION['user_id']]);
$survey = $stmt->fetch();

if (!$survey) {
    echo json_encode(['success' => false, 'msg' => '问卷不存在或无权限']);
    exit;
}

if ($survey['status'] === 'published') {
    echo json_encode(['success' => false, 'msg' => '已发布问卷不能编辑']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 更新主表（移除了access_type字段）
    $stmt = $pdo->prepare("
        UPDATE surveys SET 
            title = ?, description = ? 
        WHERE id = ?
    ");
    $stmt->execute([$title, $description, $survey_id]);

    // 删除旧的问题和选项
    $stmt = $pdo->prepare("DELETE FROM options WHERE question_id IN (SELECT id FROM questions WHERE survey_id = ?)");
    $stmt->execute([$survey_id]);

    $stmt = $pdo->prepare("DELETE FROM questions WHERE survey_id = ?");
    $stmt->execute([$survey_id]);

    // 重新插入新问题和选项
    foreach ($questions as $q_index => $q) {
        $stmt = $pdo->prepare("INSERT INTO questions (survey_id, title, type, required, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$survey_id, $q['title'], $q['type'], $q['required'] ? 1 : 0, $q_index]);
        $question_id = $pdo->lastInsertId();

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
    echo json_encode(['success' => true, 'msg' => '问卷更新成功！']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'msg' => '更新失败: ' . $e->getMessage()]);
}
?>