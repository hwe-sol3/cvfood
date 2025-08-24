<?php
declare(strict_types=1);
include 'auth.php';

// 5 또는 7만
if (!isset($_SESSION['user_id']) || !in_array((int)$_SESSION['user_level'], [6,7], true)) {
    header("Location: login.php"); exit;
}else {
    die("접근 권한이 없습니다.");
}
$userId = $_SESSION['user_id'];

// DB
$pdo = new PDO("mysql:host=localhost;dbname=cvfood;charset=utf8mb4", "cvfood", "Nums135790!!", [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];

// 삭제 처리
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='delete') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) { die("잘못된 요청"); }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM external_orders WHERE id=? AND ordered_by=?");
        $stmt->execute([$id, $userId]);
    }
    $y = (int)($_POST['year'] ?? date('Y'));
    $m = (int)($_POST['month'] ?? date('n'));
    header("Location: external_orders_list.php?year={$y}&month={$m}"); exit;
}

// 필터
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));

// 데이터
$stmt = $pdo->prepare("
  SELECT id, ordered_at, reason, lunch_qty, lunch_salad_qty
  FROM external_orders
  WHERE ordered_by=? AND ordered_at BETWEEN ? AND ?
  ORDER BY ordered_at DESC, id DESC
");
$stmt->execute([$userId, $monthStart, $monthEnd]);
$rows = $stmt->fetchAll();

// 합계
$sumLunch = 0; $sumSalad = 0;
foreach ($rows as $r) { $sumLunch += (int)$r['lunch_qty']; $sumSalad += (int)$r['lunch_salad_qty']; }

// 요일
function wday($dt){ $d=strtotime($dt); $w=['일','월','화','수','목','금','토'][date('w',$d)]; return $w; }
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>외부인 주문 내역</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root{--primary:#2563eb;--secondary:#1e40af;--bg:#f9fafb;--text:#111827;--card:#fff;--radius:12px;--shadow:0 4px 10px rgba(0,0,0,.08);}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);max-width:1000px;margin:0 auto;padding:20px}
  h1{font-size:1.6rem;margin-bottom:12px;text-align:center;color:var(--primary)}
  .bar{display:flex;gap:10px;justify-content:flex-start;align-items:center;margin:12px 0}
  .bar .left, .bar .right{display:flex;gap:8px;align-items:center}
  .btn{padding:6px 10px;border-radius:10px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;font-size:0.9rem}
  .btn-primary{background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;border:none}
  .btn-outline{background:#fff;border:1px solid #d1d5db;color:#111}
  .btn-danger{background:#ef4444;color:#fff;border:none}
  .grid{background:var(--card);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
  table{width:100%;border-collapse:collapse;word-wrap:break-word; table-layout:auto}
  th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;word-break:break-all;font-size:0.95rem}
  th{background:#f3f4f6;font-weight:800}
  tfoot td{font-weight:800;background:#f9fafb}
  .actions-cell {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  flex-wrap: nowrap;
  min-width: 90px; /* 버튼들이 들어갈 공간 확보 */
	}

	.actions-cell .btn {
	  width: 36px;
	  height: 36px;
	  padding: 0;
	  border-radius: 8px;
	  font-size: 1rem;
	  display: flex;
	  align-items: center;
	  justify-content: center;
	}
	.actions-cell form{display:inline-flex; justify-content:center; gap:2px}
  .filter{display:flex;gap:8px;align-items:center}
  select{padding:8px;border:1px solid #d1d5db;border-radius:8px}
  .back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

  @media(max-width:768px){
    body{padding:12px}
    th,td{padding:6px;font-size:0.85rem; min-width:0; overflow:hidden; white-space:normal;}
    .bar{flex-direction:row;align-items:center;gap:6px; flex-wrap:wrap;}
    .filter{justify-content:flex-start}
    .bar .right{align-self:center; margin-top:0}
    .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}
    table{table-layout:auto}
    td.actions-cell{overflow:visible; white-space:nowrap; min-width:70px;}
    th:nth-child(6), td:nth-child(6){width:15%; min-width:70px;}
	td.actions-cell {
    min-width: 90px;
  }
  .actions-cell .btn {
    width: 32px;
    height: 32px;
    font-size: 0.9rem;
  }
  }
</style>
</head>
<body>
  <h1>📋 외부인 주문 내역</h1>

  <div class="bar">
    <div class="left filter">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <select name="year">
          <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
            <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?>년</option>
          <?php endfor; ?>
        </select>
        <select name="month">
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $m==$month?'selected':'' ?>><?= $m ?>월</option>
          <?php endfor; ?>
        </select>
        <button class="btn btn-primary" type="submit">조회</button>
      </form>
    </div>

    <div class="right">
      <a class="btn btn-outline" href="external_order.php">이전</a>
      <a class="btn btn-outline" href="index.php">처음</a>
    </div>
  </div>

  <div class="grid">
    <table>
      <thead>
        <tr>
          <th style="width:22%">주문일자</th>
          <th>백반 개수</th>
          <th>샐러드 개수</th>
          <th style="width:58%">사유</th>
          <th style="width:20%">작업</th>
        </tr>
      </thead>
      <tbody>
        <?php if($rows): foreach($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars(date('Y-m-d', strtotime($r['ordered_at']))) ?> (<?= wday($r['ordered_at']) ?>)</td>
            <td><?= (int)$r['lunch_qty'] ?></td>
            <td><?= (int)$r['lunch_salad_qty'] ?></td>
            <td><?= htmlspecialchars($r['reason']) ?></td>
            <td class="actions-cell">
			  <a class="btn btn-outline" href="external_order.php?id=<?= (int)$r['id'] ?>">✏️</a>
			  <form method="post" onsubmit="return confirm('이 주문을 삭제할까요?');">
				<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
				<input type="hidden" name="action" value="delete">
				<input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
				<input type="hidden" name="year" value="<?= (int)$year ?>">
				<input type="hidden" name="month" value="<?= (int)$month ?>">
				<button class="btn btn-danger" type="submit">🗑</button>
			  </form>
			</td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6">해당 월에 주문 내역이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>합계</td>
          <td><?= (int)$sumLunch ?></td>
          <td><?= (int)$sumSalad ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
  </div>
 <button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>
