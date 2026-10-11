<?php
require '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '未登录']);
    exit;
}

$survey_id = intval($_GET['survey_id'] ?? 0);

if (!$survey_id) {
    echo json_encode(['success' => false, 'msg' => '无效的问卷ID']);
    exit;
}

// 验证问卷属于当前用户
$stmt = $pdo->prepare("SELECT id, title FROM surveys WHERE id = ? AND user_id = ?");
$stmt->execute([$survey_id, $_SESSION['user_id']]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    echo json_encode(['success' => false, 'msg' => '无权限或问卷不存在']);
    exit;
}

try {
    // 1. 获取所有提交记录
    $stmt = $pdo->prepare("
        SELECT r.* 
        FROM responses r
        WHERE r.survey_id = ?
        ORDER BY r.submit_time DESC
    ");
    $stmt->execute([$survey_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. 获取问卷的问题列表
    $stmt = $pdo->prepare("
        SELECT q.id, q.title, q.type 
        FROM questions q
        WHERE q.survey_id = ?
        ORDER BY q.sort_order
    ");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. 获取每个提交的答案
    $response_data = [];
    foreach ($responses as $response) {
        $stmt = $pdo->prepare("
            SELECT a.question_id, a.answer_text
            FROM answers a
            WHERE a.response_id = ?
        ");
        $stmt->execute([$response['id']]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按问题ID组织答案
        $answer_map = [];
        foreach ($answers as $answer) {
            $answer_map[$answer['question_id']] = $answer['answer_text'];
        }
        
        $response_data[] = [
            'id' => $response['id'],
            'submit_time' => $response['submit_time'],
            'ip_address' => $response['ip_address'],
            'answers' => $answer_map
        ];
    }
    
    // 4. 统计信息
    $total_responses = count($responses);
    
    echo json_encode([
        'success' => true, 
        'survey' => [
            'id' => $survey['id'],
            'title' => $survey['title'],
            'questions' => $questions
        ],
        'data' => $response_data,
        'stats' => [
            'total_responses' => $total_responses
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => '获取数据失败: ' . $e->getMessage()]);
}
?>