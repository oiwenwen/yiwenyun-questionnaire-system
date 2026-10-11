<?php
/**
 * 删除问卷。
 *
 * 修复记录：原文件注释写着"级联删除问题和选项"，但本项目的表是 MyISAM 引擎，
 * 不支持外键，也不会有任何级联行为。原代码只删了 surveys 一行，
 * 结果题目、选项、答卷、答案全部变成孤儿数据留在库里越积越多。
 * 现在改为在一个事务里按依赖顺序显式删除。
 */

require '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '未登录']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$survey_id = (int) ($data['survey_id'] ?? 0);

if ($survey_id <= 0) {
    echo json_encode(['success' => false, 'msg' => '无效的问卷ID']);
    exit;
}

// 检查是否是当前用户的问卷
$stmt = $pdo->prepare("SELECT id FROM surveys WHERE id = ? AND user_id = ?");
$stmt->execute([$survey_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'msg' => '无权限或问卷不存在']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. 答案（挂在答卷下）
    $stmt = $pdo->prepare("
        DELETE FROM answers
        WHERE response_id IN (SELECT id FROM responses WHERE survey_id = ?)
    ");
    $stmt->execute([$survey_id]);

    // 2. 答卷
    $stmt = $pdo->prepare("DELETE FROM responses WHERE survey_id = ?");
    $stmt->execute([$survey_id]);

    // 3. 选项（挂在题目下）
    $stmt = $pdo->prepare("
        DELETE FROM options
        WHERE question_id IN (SELECT id FROM questions WHERE survey_id = ?)
    ");
    $stmt->execute([$survey_id]);

    // 4. 题目
    $stmt = $pdo->prepare("DELETE FROM questions WHERE survey_id = ?");
    $stmt->execute([$survey_id]);

    // 5. 问卷本身
    $stmt = $pdo->prepare("DELETE FROM surveys WHERE id = ? AND user_id = ?");
    $stmt->execute([$survey_id, $_SESSION['user_id']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'msg' => '删除成功']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
}
