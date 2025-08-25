<?php
// DB 연결 설정
include 'db_config.php';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
  die("DB 연결 실패: " . $conn->connect_error);
}

// POST 데이터 받기
$user_id = $_POST['user_id'] ?? '';
$user_pw = $_POST['user_pw'] ?? '';
$date = $_POST['meal_date'] ?? date('Y-m-d');  // 주문한 식사 날짜

// 체크박스 값 처리 (한 개만 선택해도 오류 안 나도록 배열로 변환)
$meals_raw = $_POST['meal'] ?? [];
$meals = is_array($meals_raw) ? $meals_raw : [$meals_raw];

// 식사 항목 초기화
$lunch = 0;          // 백반 점심
$lunch_salad = 0;    // 샐러드 점심
$dinner_salad = 0;   // 샐러드 저녁

if (in_array('baekban', $meals)) {
  $lunch = 1;
}
if (in_array('salad-lunch', $meals)) {
  $lunch_salad = 1;
}
if (in_array('salad-dinner', $meals)) {
  $dinner_salad = 1;
}

// 주문 여부 확인
$sql_check = "SELECT * FROM order_data WHERE user_id = ? AND date = ?";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("ss", $user_id, $date);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows > 0) {
  // 이미 같은 날짜에 주문한 기록이 있으면 업데이트
  // 새로 추가된 수령 관련 컬럼들은 기본값 유지 (0, NULL)
  $sql_update = "UPDATE order_data SET lunch = ?, lunch_salad = ?, dinner_salad = ? WHERE user_id = ? AND date = ?";
  $stmt_update = $conn->prepare($sql_update);
  $stmt_update->bind_param("iiiss", $lunch, $lunch_salad, $dinner_salad, $user_id, $date);
  $stmt_update->execute();
  $stmt_update->close();
} else {
  // 새로운 주문이면 추가
  // 수령 관련 컬럼들은 기본값으로 설정 (lunch_picked=0, lunch_salad_picked=0, dinner_salad_picked=0, 시간들은 NULL)
  $sql_insert = "INSERT INTO order_data (user_id, lunch, lunch_salad, dinner_salad, date, lunch_picked, lunch_salad_picked, dinner_salad_picked, lunch_picked_at, lunch_salad_picked_at, dinner_salad_picked_at) VALUES (?, ?, ?, ?, ?, 0, 0, 0, NULL, NULL, NULL)";
  $stmt_insert = $conn->prepare($sql_insert);
  $stmt_insert->bind_param("siiis", $user_id, $lunch, $lunch_salad, $dinner_salad, $date);
  $stmt_insert->execute();
  $stmt_insert->close();
}

$stmt_check->close();
$conn->close();

// 완료 후 주문 내역 확인 페이지로 이동
echo "<script>
  alert('주문이 정상적으로 제출되었습니다.');
  window.location.href = 'result.php?user_id=" . urlencode($user_id) . "';
</script>";
?>