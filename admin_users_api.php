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
            $user_pw = $_POST['user_pw'] ?? '';
            $user_name = $_POST['user_name'] ?? '';
            $user_level = (int)($_POST['user_level'] ?? 1);
            $user_group = $_POST['user_group'] !== '' ? (int)$_POST['user_group'] : null;
            
            if (!$user_id || !$user_pw || !$user_name) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            // 중복 체크
            $check = $conn->prepare("SELECT COUNT(*) as cnt FROM login_data WHERE user_id=?");
            $check->bind_param('s', $user_id);
            $check->execute();
            if ($check->get_result()->fetch_assoc()['cnt'] > 0) {
                throw new Exception('이미 존재하는 직원ID입니다.');
            }
            
            $stmt = $conn->prepare("INSERT INTO login_data (user_id, user_pw, user_name, user_level, user_group) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssii', $user_id, $user_pw, $user_name, $user_level, $user_group);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '직원이 추가되었습니다.']);
            } else {
                throw new Exception('추가 실패');
            }
            break;
            
        case 'update':
            $original_user_id = $_POST['original_user_id'] ?? '';
            $user_pw = $_POST['user_pw'] ?? '';
            $user_name = $_POST['user_name'] ?? '';
            $user_level = (int)($_POST['user_level'] ?? 1);
            $user_group = $_POST['user_group'] !== '' ? (int)$_POST['user_group'] : null;
            
            if (!$original_user_id || !$user_name) {
                throw new Exception('필수 항목을 입력해주세요.');
            }
            
            // 비밀번호 변경 여부에 따라 쿼리 분기
            if ($user_pw) {
                $stmt = $conn->prepare("UPDATE login_data SET user_pw=?, user_name=?, user_level=?, user_group=? WHERE user_id=?");
                $stmt->bind_param('ssiis', $user_pw, $user_name, $user_level, $user_group, $original_user_id);
            } else {
                $stmt = $conn->prepare("UPDATE login_data SET user_name=?, user_level=?, user_group=? WHERE user_id=?");
                $stmt->bind_param('siis', $user_name, $user_level, $user_group, $original_user_id);
            }
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '직원 정보가 수정되었습니다.']);
            } else {
                throw new Exception('수정 실패');
            }
            break;
            
        case 'delete':
            $user_id = $_POST['user_id'] ?? '';
            
            if (!$user_id) {
                throw new Exception('삭제할 직원 정보가 없습니다.');
            }
            
            // 자기 자신은 삭제 불가
            if ($user_id === $_SESSION['user_id']) {
                throw new Exception('자기 자신은 삭제할 수 없습니다.');
            }
            
            $stmt = $conn->prepare("DELETE FROM login_data WHERE user_id=?");
            $stmt->bind_param('s', $user_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => '직원이 삭제되었습니다.']);
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
