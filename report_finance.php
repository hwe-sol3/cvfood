<?php
// 세션 없으면 로그인 페이지로
include 'auth.php';
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
    header("Location: login.php"); exit;
}
if (!in_array($_SESSION['user_level'], [3,7])) {
    die("접근 권한이 없습니다.");
}

$host = 'localhost';
$dbname = 'cvfood';
$user = 'cvfood';
$pass = 'Nums135790!!';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
  die("DB 연결 실패: " . $conn->connect_error);
}



$month_start = date('Y-m-01');   // 이번 달 1일
$month_end = date('Y-m-t');      // 이번 달 마지막 날짜 (28~31일 자동)

if (isset($_GET['download']) && $_GET['download'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=order_report_' . $month_start . '_to_' . $month_end . '.csv');

  $output = fopen('php://output', 'w');
  fputcsv($output, ['아이디', '아이디', '점심 주문량', '저녁 주문량']);

  $sql = "SELECT o.user_id,  
          SUM(COALESCE(o.lunch, 0) + COALESCE(o.lunch_salad, 0)) AS lunch_total, 
          SUM(COALESCE(o.dinner_salad, 0)) AS dinner_total
          FROM order_data o
          LEFT JOIN login_data u ON o.user_id = u.number
          WHERE o.date BETWEEN ? AND ?
          GROUP BY o.user_id, u.user_id
          ORDER BY u.user_id";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $month_start, $month_end);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
      $row['user_id'],
      $row['lunch_total'],
      $row['dinner_total']
    ]);
  }
  fclose($output);
  exit;
}

// CSV 다운로드가 아닐 때는 HTML 출력
$sql = "SELECT o.user_id, 
        SUM(COALESCE(o.lunch, 0) + COALESCE(o.lunch_salad, 0)) AS lunch_total, 
        SUM(COALESCE(o.dinner_salad, 0)) AS dinner_total
        FROM order_data o
        LEFT JOIN login_data u ON o.user_id = u.user_id
        WHERE o.date BETWEEN ? AND ?
        GROUP BY o.user_id, u.user_id
        ORDER BY u.user_id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8" />
  <title>재무팀용 주문 통계</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      padding: 20px;
      text-align: center;
    }
    h1 {
      font-size: 48px;
      margin-bottom: 30px;
    }
    table {
      border-collapse: collapse;
      width: 90%;
      margin: 0 auto;
      font-size: 18px;
    }
    th, td {
      border: 1px solid #666;
      padding: 8px 12px;
      text-align: center;
    }
    th {
      background-color: #f2f2f2;
    }
    caption {
      font-size: 28px;
      font-weight: bold;
      margin-bottom: 30px; /* 버튼과 캡션 사이 간격 늘림 */
    }
    .btn-back, .btn-csv {
      display: inline-block;
      margin: 20px 10px 30px 10px; /* 아래 간격 30px로 늘림 */
      font-size: 22px;
      padding: 12px 24px;
      color: white;
      text-decoration: none;
      border-radius: 6px;
      cursor: pointer;
    }
    .btn-back {
      background-color: #4CAF50;
    }
    .btn-csv {
      background-color: #2196F3;
    }
	
  </style>
</head>
<body>
  <h1>당월 주문 통계</h1>
  <p>기간: <?php echo htmlspecialchars($month_start); ?> ~ <?php echo htmlspecialchars($month_end); ?></p>
  <a href="?download=csv" class="btn-csv">CSV 다운로드</a>
  <a href="index.php" class="btn-back">처음으로 돌아가기</a>
  <table>
    <caption><?php echo date('n월'); ?> 주문 내역</caption>
    <thead>
      <tr>
        <th>아이디</th>
        <th>점심 주문량</th>
        <th>저녁 주문량</th>
      </tr>
    </thead>
    <tbody>
<?php
while ($row = $result->fetch_assoc()) {
  echo "<tr>";
  echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
  echo "<td>" . htmlspecialchars($row['lunch_total']) . "</td>";
  echo "<td>" . htmlspecialchars($row['dinner_total']) . "</td>";
  echo "</tr>";
}
?>
    </tbody>
  </table>
<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
