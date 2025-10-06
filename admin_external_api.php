<?php
include 'auth.php';
include 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$conn = new mysqli($host, $user, $pass, $dbname);
if($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB 연결 실패']);
    exit;
}

$conn->set_charset("utf8mb4");
date_default_timezone_set("Asia/Seoul");

$action = $_POST['action'] ?? '';

try {
    switch($action) {
        case 'insert':
            $reason = trim($_POST['reason'] ?? '');
            $lunch_qty = (int)($_POST['lunch_qty'] ?? 0);
            $lunch_salad_qty = (int)($_POST['lunch_salad_qty'] ?? 0);
            $ordered_by = trim($_POST['ordered_by'] ?? '');
            
            if (empty($reason) || empty($ordered_by)) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            if ($lunch_qty < 0 || $lunch_salad_qty < 0) {
                throw new Exception('수량은 0 이상이어야 합니다.');
            }
            
            $stmt = $conn->prepare("INSERT INTO external_orders (reason, lunch_qty, lunch_salad_qty, ordered_by, ordered_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param('siis', $reason, $lunch_qty, $lunch_salad_qty, $ordered_by);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '외부 주문이 추가되었습니다.']);
            } else {
                throw new Exception('추가 실패: ' . $stmt->error);
            }
            $stmt->close();
            break;
            
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $lunch_qty = (int)($_POST['lunch_qty'] ?? 0);
            $lunch_salad_qty = (int)($_POST['lunch_salad_qty'] ?? 0);
            $ordered_by = trim($_POST['ordered_by'] ?? '');
            
            if (!$id || empty($reason) || empty($ordered_by)) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            if ($lunch_qty < 0 || $lunch_salad_qty < 0) {
                throw new Exception('수량은 0 이상이어야 합니다.');
            }
            
            $stmt = $conn->prepare("UPDATE external_orders SET reason=?, lunch_qty=?, lunch_salad_qty=?, ordered_by=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param('siisi', $reason, $lunch_qty, $lunch_salad_qty, $ordered_by, $id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['success' => true, 'message' => '외부 주문이 수정되었습니다.']);
                } else {
                    echo json_encode(['success' => false, 'message' => '수정할 데이터가 없거나 변경사항이 없습니다.']);
                }
            } else {
                throw new Exception('수정 실패: ' . $stmt->error);
            }
            $stmt->close();
            break;
            
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('삭제할 주문 정보가 없습니다.');
            }
            
            $stmt = $conn->prepare("DELETE FROM external_orders WHERE id=?");
            $stmt->bind_param('i', $id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['success' => true, 'message' => '외부 주문이 삭제되었습니다.']);
                } else {
                    echo json_encode(['success' => false, 'message' => '삭제할 데이터가 없습니다.']);
                }
            } else {
                throw new Exception('삭제 실패: ' . $stmt->error);
            }
            $stmt->close();
            break;
            
        default:
            throw new Exception('잘못된 요청입니다.');
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
