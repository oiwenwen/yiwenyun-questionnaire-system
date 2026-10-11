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
    // 获取问卷数据
    $stmt = $pdo->prepare("
        SELECT r.*, 
               GROUP_CONCAT(CONCAT(q.id, '|||', a.answer_text) ORDER BY q.sort_order SEPARATOR ':::') as answers
        FROM responses r
        LEFT JOIN answers a ON r.id = a.response_id
        LEFT JOIN questions q ON a.question_id = q.id
        WHERE r.survey_id = ?
        GROUP BY r.id
        ORDER BY r.submit_time
    ");
    $stmt->execute([$survey_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取问题列表
    $stmt = $pdo->prepare("SELECT id, title FROM questions WHERE survey_id = ? ORDER BY sort_order");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 创建CSV内容
    $filename = "问卷结果_" . date('Ymd_His') . ".csv";
    
    // 设置header直接下载文件，而不是返回JSON
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // 添加BOM，解决Excel中文乱码
    
    // 表头
    $headers = ['序号', '提交时间', 'IP地址'];
    foreach ($questions as $q) {
        $headers[] = $q['title'];
    }
    fputcsv($output, $headers);
    
    // 数据行
    foreach ($responses as $index => $response) {
        $row = [
            $index + 1,
            $response['submit_time'],
            $response['ip_address']
        ];
        
        // 解析答案
        $answer_map = [];
        if ($response['answers']) {
            $answer_pairs = explode(':::', $response['answers']);
            foreach ($answer_pairs as $pair) {
                list($qid, $answer) = explode('|||', $pair, 2);
                $answer_map[$qid] = $answer;
            }
        }
        
        // 按问题顺序添加答案
        foreach ($questions as $q) {
            $row[] = $answer_map[$q['id']] ?? '';
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit; // 直接退出，不返回JSON
    
} catch (Exception $e) {
    // 如果有错误，返回JSON错误信息
    echo json_encode(['success' => false, 'msg' => '导出失败: ' . $e->getMessage()]);
}
?>