<?php
session_start();
include 'db_config.php';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) die("DB 연결 실패: ".$conn->connect_error);

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) die("아이디 정보가 없습니다.");

$prevData = $_SESSION['prev_order_data'] ?? []; // ['Y-m-d' => ['lunch'=>0/1, ...]]
$dates = array_keys($prevData);

// 비교 대상 날짜가 없으면 바로 종료
if (empty($dates)) {
  echo "<!DOCTYPE html><html lang='ko'><head><meta charset='UTF-8'><title>변경된 주문 내역</title>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
          :root{--primary:#2563eb;--secondary:#1e40af;--bg:#f9fafb;--text:#111827;--card-bg:#fff;--radius:12px;--shadow:0 4px 10px rgba(0,0,0,.08);}
          body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
          .card{background:var(--card-bg);padding:24px;border-radius:var(--radius);box-shadow:var(--shadow);max-width:720px;width:100%;text-align:center;}
          a.btn{display:inline-block;margin-top:16px;background:#2563eb;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;}
        </style></head><body>
        <div class='card'>
          <h2>변경된 주문 내역</h2>
          <p>세션에 비교 기준이 없습니다. (직전 페이지에서 제출을 다시 시도해 주세요)</p>
          <a class='btn' href='index.php'>처음으로 돌아가기</a>
        </div></body></html>";
  exit;
}

// DB: 해당 유저, 해당 날짜만 조회
$placeholders = implode(',', array_fill(0, count($dates), '?'));
$types = str_repeat('s', count($dates)+1); // user_id + dates
$sql = "SELECT date, lunch, lunch_salad, dinner_salad FROM order_data WHERE user_id = ? AND date IN ($placeholders)";
$stmt = $conn->prepare($sql);
$params = array_merge([$user_id], $dates);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$dbData = [];
while($row = $res->fetch_assoc()){
  $dbData[$row['date']] = [
    'lunch' => (int)$row['lunch'],
    'lunch_salad' => (int)$row['lunch_salad'],
    'dinner_salad' => (int)$row['dinner_salad'],
  ];
}
$stmt->close();
$conn->close();

// 변경 비교
$changed = []; // date => ['lunch'=>[prev,now], ...]
$meals = ['lunch'=>'백반 점심','lunch_salad'=>'점심 샐러드','dinner_salad'=>'저녁 샐러드'];

foreach($prevData as $d=>$prev){
  $now = $dbData[$d] ?? ['lunch'=>0,'lunch_salad'=>0,'dinner_salad'=>0];
  $diff = [];
  foreach($meals as $k=>$label){
    if ((int)$prev[$k] !== (int)$now[$k]) {
      $diff[$k] = [ (int)$prev[$k], (int)$now[$k] ];
    }
  }
  if (!empty($diff)) $changed[$d] = $diff;
}

function txt($v){ return $v ? '주문함' : '주문 안함'; }

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>변경된 주문 내역</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root{--primary:#2563eb;--secondary:#1e40af;--bg:#f9fafb;--text:#111827;--card-bg:#fff;--radius:12px;--shadow:0 4px 10px rgba(0,0,0,.08);}
  *{box-sizing:border-box;}
  body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);padding:20px;display:flex;justify-content:center;}
  .container{max-width:980px;width:100%;display:flex;flex-direction:column;gap:16px;}
  .card{background:var(--card-bg);padding:20px;border-radius:var(--radius);box-shadow:var(--shadow);}
  h1{color:var(--primary);font-size:1.6rem;margin:0;}
  .summary{color:#374151;}
  .table-wrap{overflow-x:auto;}
  table{width:100%;border-collapse:collapse;margin-top:12px;}
  th,td{border:1px solid #e5e7eb;padding:10px;text-align:center;font-size:.95rem;}
  th{background:#f3f4f6;}
  .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
  .btn{background:#2563eb;color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;display:inline-block;}
  .btn.secondary{background:#6b7280;}
  @media (max-width:768px){ h1{font-size:1.3rem;} td,th{font-size:.9rem;} }
</style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h1>변경된 주문 내역</h1>
      <p class="summary">
        사용자: <strong><?=htmlspecialchars($user_id)?></strong> · 비교대상 <?=count($dates)?>일 ·
        변경된 일자 <?=count($changed)?>건
      </p>

      <div class="table-wrap">
      <?php if (!empty($changed)): ?>
        <table>
          <thead>
            <tr>
              <th>날짜</th>
              <th>백반 점심</th>
              <th>점심 샐러드</th>
              <th>저녁 샐러드</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($changed as $d=>$diff): ?>
            <tr>
              <td><?=htmlspecialchars($d)?></td>
              <td><?= isset($diff['lunch']) ? txt($diff['lunch'][0])." → ".txt($diff['lunch'][1]) : "-" ?></td>
              <td><?= isset($diff['lunch_salad']) ? txt($diff['lunch_salad'][0])." → ".txt($diff['lunch_salad'][1]) : "-" ?></td>
              <td><?= isset($diff['dinner_salad']) ? txt($diff['dinner_salad'][0])." → ".txt($diff['dinner_salad'][1]) : "-" ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="margin-top:10px;">변경된 주문이 없습니다.</p>
      <?php endif; ?>
      </div>

      <div class="btns">
        <a class="btn" href="index.php">처음으로 돌아가기</a>
        <a class="btn secondary" href="order.php">주문 페이지로</a>
      </div>
    </div>
  </div>
</body>
</html>