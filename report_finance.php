<?php
include 'db_config.php';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

// 요청된 월 처리
$year  = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');

$month_start = date('Y-m-01', strtotime("$year-$month-01"));
$month_end   = date('Y-m-t', strtotime($month_start));

/* ===============================
   CSV 다운로드 처리
================================= */
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=order_report_' . $month_start . '_to_' . $month_end . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['아이디', '날짜', '점심 주문량', '저녁 주문량']);

    $sql = "SELECT o.user_id, o.date,
            SUM(COALESCE(o.lunch,0)+COALESCE(o.lunch_salad,0)) AS lunch_total,
            SUM(COALESCE(o.dinner_salad,0)) AS dinner_total
            FROM order_data o
            LEFT JOIN login_data u ON o.user_id = u.user_id
            WHERE o.date BETWEEN ? AND ?
            GROUP BY o.user_id, o.date
            ORDER BY o.user_id, o.date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_lunch = 0;
    $total_dinner = 0;
    $current_user = null;

    while ($row = $result->fetch_assoc()) {
        if ($current_user !== null && $current_user !== $row['user_id']) {
            fputcsv($output, [$current_user, '합계', $total_lunch, $total_dinner]);
            $total_lunch = 0;
            $total_dinner = 0;
        }

        fputcsv($output, [$row['user_id'], $row['date'], $row['lunch_total'], $row['dinner_total']]);
        $total_lunch += $row['lunch_total'];
        $total_dinner += $row['dinner_total'];
        $current_user = $row['user_id'];
    }

    if ($current_user !== null) {
        fputcsv($output, [$current_user, '합계', $total_lunch, $total_dinner]);
    }

    fclose($output);
    exit;
}

/* ===============================
   HTML 출력 처리
================================= */
$sql = "SELECT o.user_id,
        SUM(COALESCE(o.lunch,0)+COALESCE(o.lunch_salad,0)) AS lunch_total,
        SUM(COALESCE(o.dinner_salad,0)) AS dinner_total
        FROM order_data o
        LEFT JOIN login_data u ON o.user_id = u.user_id
        WHERE o.date BETWEEN ? AND ?
        GROUP BY o.user_id
        ORDER BY o.user_id";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$result = $stmt->get_result();

// 이전/다음 달 계산
$prev_month = date('Y-n', strtotime("$month_start -1 month"));
$next_month = date('Y-n', strtotime("$month_start +1 month"));
list($prev_year, $prev_m) = explode('-', $prev_month);
list($next_year, $next_m) = explode('-', $next_month);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>재무팀용 주문 통계</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 960px;
    margin: 20px auto;
    padding: 0 12px;
    text-align: center;
}
h1 {
    font-size: 1.5rem;
    margin-bottom: 16px;
    color: #2563eb;
}
table {
    border-collapse: collapse;
    width: 100%;
    margin: 16px 0;
    table-layout: fixed;
}
th, td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: center;
    font-size: 0.95rem;
    word-break: break-word;
}
th {
    background: #f0f0f0;
}
tfoot td {
    font-weight: bold;
    background: #fafafa;
}
.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 16px 0;
}
.header-container a {
    padding: 6px 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    text-decoration: none;
    color: #333;
    background: #fff;
}
.header-container a:hover {
    background: #eee;
}
.bottom-buttons {
    text-align: center;
    margin-top: 20px;
}
.bottom-buttons a {
    display: inline-block;
    margin: 0 5px;
    padding: 10px 20px;
    text-decoration: none;
    color: white;
    border-radius: 8px;
}
.btn-csv { background-color:  #f39c12; }
@media(max-width:600px){
    th, td { padding: 6px; font-size: 0.8rem; }
    .header-container a { padding: 4px 8px; font-size: 0.8rem; }
    .bottom-buttons a { padding: 6px 12px; font-size: 0.8rem; }
}
.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:#2962FF; color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}
</style>
</head>
<body>

<div class="header-container">
    <a href="?year=<?php echo $prev_year; ?>&month=<?php echo $prev_m; ?>">← 이전 달</a>
    <h1><?php echo date('Y년 n월', strtotime($month_start)); ?> 주문 통계</h1>
    <a href="?year=<?php echo $next_year; ?>&month=<?php echo $next_m; ?>">다음 달 →</a>
</div>

<table>
<thead>
<tr>
    <th>아이디</th>
    <th>점심 주문량</th>
    <th>저녁 주문량</th>
</tr>
</thead>
<tbody>
<?php
$total_lunch_all = 0;
$total_dinner_all = 0;

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['user_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['lunch_total']) . "</td>";
    echo "<td>" . htmlspecialchars($row['dinner_total']) . "</td>";
    echo "</tr>";

    $total_lunch_all += $row['lunch_total'];
    $total_dinner_all += $row['dinner_total'];
}
?>
</tbody>
<tfoot>
<tr>
    <td>총 합계</td>
    <td><?php echo $total_lunch_all; ?></td>
    <td><?php echo $total_dinner_all; ?></td>
</tr>
</tfoot>
</table>

<div class="bottom-buttons">
    <a class="btn-csv" href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&download=csv">CSV 다운로드</a>
</div>
    <button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>

<?php
$stmt->close();
$conn->close();
?>