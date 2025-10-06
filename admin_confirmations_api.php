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
            $confirmation_date = $_POST['confirmation_date'] ?? '';
            $meal_type = $_POST['meal_type'] ?? '';
            $confirmed_qty = (int)($_POST['confirmed_qty'] ?? 0);
            $internal_qty = (int)($_POST['internal_qty'] ?? 0);
            $external_qty = (int)($_POST['external_qty'] ?? 0);
            $confirmed_by = $_POST['confirmed_by'] ?? '';
            
            if (!$confirmation_date || !$meal_type || !$confirmed_by) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            $stmt = $conn->prepare("INSERT INTO order_confirmations (confirmation_date, meal_type, confirmed_qty, internal_qty, external_qty, confirmed_by, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('ssiiis', $confirmation_date, $meal_type, $confirmed_qty, $internal_qty, $external_qty, $confirmed_by);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문 확정이 추가되었습니다.']);
            } else {
                throw new Exception('추가 실패');
            }
            break;
            
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $confirmation_date = $_POST['confirmation_date'] ?? '';
            $meal_type = $_POST['meal_type'] ?? '';
            $confirmed_qty = (int)($_POST['confirmed_qty'] ?? 0);
            $internal_qty = (int)($_POST['internal_qty'] ?? 0);
            $external_qty = (int)($_POST['external_qty'] ?? 0);
            $confirmed_by = $_POST['confirmed_by'] ?? '';
            
            if (!$id || !$confirmation_date || !$meal_type || !$confirmed_by) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            $stmt = $conn->prepare("UPDATE order_confirmations SET confirmation_date=?, meal_type=?, confirmed_qty=?, internal_qty=?, external_qty=?, confirmed_by=? WHERE id=?");
            $stmt->bind_param('ssiiisi', $confirmation_date, $meal_type, $confirmed_qty, $internal_qty, $external_qty, $confirmed_by, $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문 확정이 수정되었습니다.']);
            } else {
                throw new Exception('수정 실패');
            }
            break;
            
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('삭제할 확정 정보가 없습니다.');
            }
            
            $stmt = $conn->prepare("DELETE FROM order_confirmations WHERE id=?");
            $stmt->bind_param('i', $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문 확정이 삭제되었습니다.']);
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
?>
