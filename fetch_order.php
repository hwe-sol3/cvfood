<?php
header('Content-Type: application/json');

include 'db_config.php';

// DB 연결
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    echo json_encode(['error'=>'DB 연결 실패']);
    exit;
}

// GET 데이터 받기
$user_id = $_GET['user_id'] ?? '';
$date = $_GET['date'] ?? '';

if (empty($user_id) || empty($date)) {
    echo json_encode(['lunch'=>0,'lunch_salad'=>0,'dinner_salad'=>0]);
    exit;
}

$sql = "SELECT lunch, lunch_salad, dinner_salad FROM order_data WHERE user_id=? AND date=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss",$user_id,$date);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows > 0){
    $row = $result->fetch_assoc();
    echo json_encode([
        'lunch' => (int)$row['lunch'],
        'lunch_salad' => (int)$row['lunch_salad'],
        'dinner_salad' => (int)$row['dinner_salad']
    ]);
} else {
    echo json_encode(['lunch'=>0,'lunch_salad'=>0,'dinner_salad'=>0]);
}

$stmt->close();
$conn->close();