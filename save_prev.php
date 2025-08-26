<?php
session_start();
include 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !isset($payload['user_id']) || !isset($payload['dates']) || !is_array($payload['dates'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>'invalid payload']); exit;
}

$user_id = $payload['user_id'];
$dates = array_values(array_unique($payload['dates']));

// (선택) 세션 사용자와 일치 확인
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'msg'=>'forbidden']); exit;
}


$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){ http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'db error']); exit; }

$prev = [];
$stmt = $conn->prepare("SELECT lunch, lunch_salad, dinner_salad FROM order_data WHERE user_id=? AND date=?");

foreach($dates as $d){
  $stmt->bind_param("ss", $user_id, $d);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()){
    $prev[$d] = [
      'lunch' => (int)$row['lunch'],
      'lunch_salad' => (int)$row['lunch_salad'],
      'dinner_salad' => (int)$row['dinner_salad'],
    ];
  } else {
    // 기존 주문 없던 날짜는 0으로 초기 상태 취급
    $prev[$d] = ['lunch'=>0,'lunch_salad'=>0,'dinner_salad'=>0];
  }
}
$stmt->close();
$conn->close();

$_SESSION['prev_order_data'] = $prev;
echo json_encode(['ok'=>true, 'count'=>count($prev)]);