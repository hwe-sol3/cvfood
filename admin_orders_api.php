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
            $user_id = $_POST['user_id'] ?? '';
            $date = $_POST['date'] ?? '';
            $lunch = (int)($_POST['lunch'] ?? 0);
            $lunch_salad = (int)($_POST['lunch_salad'] ?? 0);
            $dinner_salad = (int)($_POST['dinner_salad'] ?? 0);
            
            if (!$user_id || !$date) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            // 중복 체크
            $check = $conn->prepare("SELECT COUNT(*) as cnt FROM order_data WHERE user_id=? AND date=?");
            $check->bind_param('ss', $user_id, $date);
            $check->execute();
            if ($check->get_result()->fetch_assoc()['cnt'] > 0) {
                throw new Exception('이미 해당 날짜에 주문이 존재합니다.');
            }
            
            $stmt = $conn->prepare("INSERT INTO order_data (user_id, date, lunch, lunch_salad, dinner_salad, lunch_picked, lunch_salad_picked, dinner_salad_picked) VALUES (?, ?, ?, ?, ?, 0, 0, 0)");
            $stmt->bind_param('ssiii', $user_id, $date, $lunch, $lunch_salad, $dinner_salad);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문이 추가되었습니다.']);
            } else {
                throw new Exception('추가 실패');
            }
            break;
            
        case 'update':
            $original_user_id = $_POST['original_user_id'] ?? '';
            $original_date = $_POST['original_date'] ?? '';
            $lunch = (int)($_POST['lunch'] ?? 0);
            $lunch_salad = (int)($_POST['lunch_salad'] ?? 0);
            $dinner_salad = (int)($_POST['dinner_salad'] ?? 0);
            
            if (!$original_user_id || !$original_date) {
                throw new Exception('수정할 주문 정보가 없습니다.');
            }
            
            $stmt = $conn->prepare("UPDATE order_data SET lunch=?, lunch_salad=?, dinner_salad=? WHERE user_id=? AND date=?");
            $stmt->bind_param('iiiss', $lunch, $lunch_salad, $dinner_salad, $original_user_id, $original_date);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문이 수정되었습니다.']);
            } else {
                throw new Exception('수정 실패');
            }
            break;
            
        case 'delete':
            $user_id = $_POST['user_id'] ?? '';
            $date = $_POST['date'] ?? '';
            
            if (!$user_id || !$date) {
                throw new Exception('삭제할 주문 정보가 없습니다.');
            }
            
            $stmt = $conn->prepare("DELETE FROM order_data WHERE user_id=? AND date=?");
            $stmt->bind_param('ss', $user_id, $date);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '주문이 삭제되었습니다.']);
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
