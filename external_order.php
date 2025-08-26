<?php
declare(strict_types=1);
include 'auth.php';

// 접근 권한: 레벨 5 또는 7 허용
// 세션 없으면 로그인 페이지로
include 'auth.php';
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
    header("Location: login.php"); exit;
}
if (!in_array($_SESSION['user_level'], [6,7,9])) {
    die("접근 권한이 없습니다.");
}
$userId = $_SESSION['user_id'];

// DB 연결 (경로 그대로, PDO 직접 생성)
include 'db_config.php';
$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

// CSRF 토큰
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];

$message = "";
$isEdit = false;
$row = [
  'reason' => '',
  'lunch_qty' => 0,
  'lunch_salad_qty' => 0,
];

// 편집 모드: ?id=...
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM external_orders WHERE id=? AND ordered_by=?");
    $stmt->execute([$id, $userId]);
    $found = $stmt->fetch();
    if ($found) {
        $isEdit = true;
        $row = $found;
    } else {
        $message = "해당 주문을 찾을 수 없거나 권한이 없습니다.";
    }
}

// 등록/수정 처리
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        die("잘못된 요청입니다.");
    }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $reason = trim($_POST['reason'] ?? '');
    $lunch_qty = max(0, (int)($_POST['lunch_qty'] ?? 0));
    $lunch_salad_qty = max(0, (int)($_POST['lunch_salad_qty'] ?? 0));

    if ($reason === '') {
        $message = "⚠️ 프로젝트 코드/프로젝트명을 입력하세요.";
    } elseif ($lunch_qty === 0 && $lunch_salad_qty === 0) {
        $message = "⚠️ 백반 또는 샐러드 수량 중 최소 하나는 1 이상이어야 합니다.";
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE external_orders
              SET reason=?,lunch_qty=?, lunch_salad_qty=?, updated_at=NOW()
              WHERE id=? AND ordered_by=?");
            $stmt->execute([$reason,$lunch_qty,$lunch_salad_qty,$id,$userId]);
            $message = "✅ 주문이 수정되었습니다.";
            $isEdit = true;
            $row = [
                'id'=>$id,
                'reason'=>$reason,
                'lunch_qty'=>$lunch_qty,
                'lunch_salad_qty'=>$lunch_salad_qty,
            ];
        } else {
            $stmt = $pdo->prepare("INSERT INTO external_orders
              (reason, lunch_qty, lunch_salad_qty, ordered_by, ordered_at)
              VALUES (?,?,?,?,NOW())");
            $stmt->execute([$reason,$lunch_qty,$lunch_salad_qty,$userId]);
            $message = "✅ 외부인 주문이 등록되었습니다.";
            $row = ['reason'=>'','lunch_qty'=>0,'lunch_salad_qty'=>0];
        }
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?= $isEdit ? '외부인 주문 수정' : '외부인 주문' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root{--primary:#2563eb;--secondary:#1e40af;--bg:#f9fafb;--text:#111827;--card:#fff;--radius:12px;--shadow:0 4px 10px rgba(0,0,0,.08);}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);
       display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px}
  form{background:var(--card);padding:30px;border-radius:var(--radius);box-shadow:var(--shadow);width:100%;max-width:520px}
  h2{margin-bottom:18px;font-size:1.5rem;color:var(--primary);text-align:center}
  .form-group{margin-bottom:14px;display:flex;flex-direction:column}
  label{margin-bottom:6px;font-weight:700}
  input[type=text],input[type=number]{padding:12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;width:100%}
  .qty-control{display:flex;align-items:center;gap:6px}
  .qty-btn{padding:10px 14px;border:none;border-radius:6px;font-size:18px;cursor:pointer;background:#e5e7eb}
  .qty-btn:hover{background:#d1d5db}
  .message{margin-bottom:12px;font-weight:700;text-align:center}
  .actions{display:flex;flex-direction:column;gap:10px;margin-top:16px}
  .btn-primary{padding:12px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;font-size:16px;cursor:pointer}
  .btn-secondary{padding:12px;border:none;border-radius:10px;background:#22c55e;color:#fff;font-size:16px;cursor:pointer;text-align:center;text-decoration:none;display:block}
  .btn-outline{padding:12px;border:2px solid #d1d5db;border-radius:10px;background:#fff;color:#111;font-size:16px;text-align:center;text-decoration:none;display:block}
  .btn-primary:hover{filter:brightness(1.05)}
  .btn-secondary:hover{background:#15803d}
  .btn-outline:hover{background:#f3f4f6}
  .back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

  @media(max-width:768px){body{padding:12px}form{padding:22px;max-width:100%}.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}}
</style>
<script>
function adjustQty(id, delta){
  const input = document.getElementById(id);
  let val = parseInt(input.value,10);
  if(isNaN(val)) val = 0;
  val += delta;
  if(val < 0) val = 0;
  input.value = val;
}
</script>
</head>
<body>
  <form method="post">
    <h2><?= $isEdit ? '✏️ 외부인 도시락 주문 수정' : '🤝 외부인 도시락 주문' ?></h2>

    <?php if($message): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <?php if($isEdit && !empty($row['id'])): ?>
      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <?php endif; ?>

    <div class="form-group">
      <label for="reason">사유</label>
      <input type="text" id="reason" name="reason" value="<?= htmlspecialchars($row['reason']) ?>" required>
    </div>

    <div class="form-group">
      <label for="lunch_qty">🍚 점심 백반 수량</label>
      <div class="qty-control">
        <button type="button" class="qty-btn" onclick="adjustQty('lunch_qty',-1)">−</button>
        <input type="number" id="lunch_qty" name="lunch_qty" value="<?= (int)$row['lunch_qty'] ?>" min="0" required>
        <button type="button" class="qty-btn" onclick="adjustQty('lunch_qty',1)">+</button>
      </div>
    </div>

    <div class="form-group">
      <label for="lunch_salad_qty">🥗 점심 샐러드 수량</label>
      <div class="qty-control">
        <button type="button" class="qty-btn" onclick="adjustQty('lunch_salad_qty',-1)">−</button>
        <input type="number" id="lunch_salad_qty" name="lunch_salad_qty" value="<?= (int)$row['lunch_salad_qty'] ?>" min="0" required>
        <button type="button" class="qty-btn" onclick="adjustQty('lunch_salad_qty',1)">+</button>
      </div>
    </div>

    <div class="actions">
      <button class="btn-primary" type="submit"><?= $isEdit ? '수정 완료' : '주문 등록' ?></button>
      <a class="btn-outline" href="external_orders_list.php">📋 주문 확인</a>
    </div>
  </form>
<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>