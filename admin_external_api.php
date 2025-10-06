<?php
include 'auth.php';
include 'db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$conn = new mysqli($host, $user, $pass, $dbname);
if($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB 연결 실패']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch($action) {
        case 'insert':
            $reason = $_POST['reason'] ?? '';
            $lunch_qty = (int)($_POST['lunch_qty'] ?? 0);
            $lunch_salad_qty = (int)($_POST['lunch_salad_qty'] ?? 0);
            $ordered_by = $_POST['ordered_by'] ?? '';
            
            if (!$reason || !$ordered_by) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            $stmt = $conn->prepare("UPDATE external_orders SET reason=?, lunch_qty=?, lunch_salad_qty=?, ordered_by=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param('siisi', $reason, $lunch_qty, $lunch_salad_qty, $ordered_by, $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '외부 주문이 수정되었습니다.']);
            } else {
                throw new Exception('수정 실패');
            }
            break;
            
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('삭제할 주문 정보가 없습니다.');
            }
            
            $stmt = $conn->prepare("DELETE FROM external_orders WHERE id=?");
            $stmt->bind_param('i', $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '외부 주문이 삭제되었습니다.']);
            } else {
                throw new Exception('삭제 실패');
            }
            break;
            
        default:
            throw new Exception('잘못된 요청입니다.');
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>prepare("INSERT INTO external_orders (reason, lunch_qty, lunch_salad_qty, ordered_by, ordered_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param('siis', $reason, $lunch_qty, $lunch_salad_qty, $ordered_by);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '외부 주문이 추가되었습니다.']);
            } else {
                throw new Exception('추가 실패');
            }
            break;
            
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $reason = $_POST['reason'] ?? '';
            $lunch_qty = (int)($_POST['lunch_qty'] ?? 0);
            $lunch_salad_qty = (int)($_POST['lunch_salad_qty'] ?? 0);
            $ordered_by = $_POST['ordered_by'] ?? '';
            
            if (!$id || !$reason || !$ordered_by) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            $stmt = $conn->
