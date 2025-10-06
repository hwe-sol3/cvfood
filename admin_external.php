<?php
include 'auth.php';
include 'db_config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    header("Location: login.php");
    exit;
}

$conn = new mysqli($host, $user, $pass, $dbname);
if($conn->connect_error) die("DB 연결 실패");
date_default_timezone_set("Asia/Seoul");

// 검색 조건
$search_reason = $_GET['search_reason'] ?? '';
$search_ordered_by = $_GET['search_ordered_by'] ?? '';
$search_date = $_GET['search_date'] ?? '';

// 페이지네이션
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// WHERE 조건
$where = [];
$params = [];
$types = '';

if ($search_reason) {
    $where[] = "reason LIKE ?";
    $params[] = "%$search_reason%";
    $types .= 's';
}

if ($search_ordered_by) {
    $where[] = "ordered_by LIKE ?";
    $params[] = "%$search_ordered_by%";
    $types .= 's';
}

if ($search_date) {
    $where[] = "DATE(ordered_at) = ?";
    $params[] = $search_date;
    $types .= 's';
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 전체 개수
$count_sql = "SELECT COUNT(*) as total FROM external_orders $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// 데이터 조회
$sql = "SELECT * FROM external_orders $where_clause ORDER BY ordered_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>외부 주문 관리</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --primary:#2563eb; --secondary:#1e40af; --bg:#f9fafb;
  --text:#111827; --card-bg:#ffffff; --radius:12px;
  --shadow:0 4px 10px rgba(0,0,0,0.08);
  --success:#10b981; --warning:#f59e0b; --danger:#ef4444;
  --border:#e5e7eb; --muted:#6b7280;
}

*{box-sizing:border-box; margin:0; padding:0;}
body{ 
  font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif; 
  background:var(--bg); color:var(--text); line-height:1.6;
  padding:20px; min-height:100vh;
}

.header{text-align:center; margin-bottom:30px;}
.header h1{
  font-size:2rem; color:var(--danger); margin-bottom:10px;
  display:flex; align-items:center; justify-content:center; gap:12px;
}
.header h1::before{content:'📦'; font-size:2rem;}

.container{max-width:1600px; margin:0 auto;}

.search-section{
  background:var(--card-bg); padding:24px; border-radius:var(--radius);
  box-shadow:var(--shadow); margin-bottom:24px;
}
.search-form{display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; align-items:end;}
.form-group{display:flex; flex-direction:column; gap:6px;}
.form-group label{font-weight:600; font-size:0.9rem;}
.form-control{
  padding:10px 12px; border:2px solid var(--border); border-radius:8px;
  font-size:1rem; transition:all 0.3s ease;
}
.form-control:focus{outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1);}
.btn{
  background:var(--primary); color:white; border:none; padding:12px 24px;
  border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer;
  transition:all 0.3s ease;
}
.btn:hover{background:var(--secondary); transform:translateY(-1px);}
.btn-success{background:var(--success);}
.btn-success:hover{background:#059669;}
.btn-danger{background:var(--danger);}
.btn-danger:hover{background:#dc2626;}
.btn-secondary{background:var(--muted);}
.btn-secondary:hover{background:#374151;}

.table-section{
  background:var(--card-bg); border-radius:var(--radius); 
  box-shadow:var(--shadow); overflow:hidden; margin-bottom:24px;
}
.table-header{
  background:linear-gradient(135deg, var(--danger), #dc2626);
  color:white; padding:20px 24px; display:flex; justify-content:space-between; align-items:center;
  flex-wrap:wrap; gap:12px;
}
.table-header h2{font-size:1.3rem;}
.table-container{overflow-x:auto;}
.data-table{width:100%; border-collapse:collapse;}
.data-table th{
  background:#f8fafc; padding:16px 12px; font-weight:600; 
  text-align:center; border-bottom:2px solid var(--border); font-size:0.9rem;
}
.data-table td{
  padding:14px 12px; text-align:center; border-bottom:1px solid var(--border);
  font-size:0.9rem;
}
.data-table tr:hover{background:#f9fafb;}
.actions{display:flex; gap:6px; justify-content:center;}
.btn-sm{
  padding:6px 12px; font-size:0.8rem; border:none; border-radius:6px;
  cursor:pointer; font-weight:600; transition:all 0.3s ease;
}
.btn-edit{background:#fef3c7; color:#d97706;}
.btn-edit:hover{background:#fde68a;}
.btn-delete{background:#fee2e2; color:#dc2626;}
.btn-delete:hover{background:#fecaca;}

.pagination{
  display:flex; justify-content:center; gap:6px; margin-top:24px; flex-wrap:wrap;
}
.pagination a, .pagination span{
  padding:8px 12px; border:2px solid var(--border); border-radius:6px;
  text-decoration:none; color:var(--text); font-weight:500; transition:all 0.3s ease;
  min-width:40px; text-align:center;
}
.pagination a:hover{border-color:var(--primary); background:var(--primary); color:white;}
.pagination .current{background:var(--primary); color:white; border-color:var(--primary);}

.modal{
  display:none; position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;
}
.modal.active{display:flex;}
.modal-content{
  background:var(--card-bg); border-radius:var(--radius); 
  max-width:600px; width:90%; max-height:90vh; overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,0.3);
}
.modal-header{
  background:linear-gradient(135deg, var(--danger), #dc2626);
  color:white; padding:20px 24px; display:flex; justify-content:space-between; align-items:center;
}
.modal-header h3{font-size:1.3rem;}
.close-btn{
  background:none; border:none; color:white; font-size:1.5rem;
  cursor:pointer; width:32px; height:32px; border-radius:50%;
  transition:background 0.3s;
}
.close-btn:hover{background:rgba(255,255,255,0.2);}
.modal-body{padding:24px;}
.modal-form{display:flex; flex-direction:column; gap:16px;}
.modal-actions{display:flex; gap:12px; justify-content:flex-end; padding:20px 24px; border-top:1px solid var(--border);}

.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(239,68,68,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1);}

@media (max-width: 768px){
  body{padding:16px;}
  .header h1{font-size:1.5rem;}
  .search-form{grid-template-columns:1fr;}
  .table-header{flex-direction:column; text-align:center;}
  .data-table th, .data-table td{padding:10px 6px; font-size:0.8rem;}
  .actions{flex-direction:column;}
  .btn-sm{width:100%;}
  .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}
}
</style>
</head>
<body>
<div class="header">
  <h1>외부 주문 관리</h1>
</div>

<div class="container">
  <div class="search-section">
    <form class="search-form" method="GET">
      <div class="form-group">
        <label>🔍 사유 검색</label>
        <input type="text" name="search_reason" class="form-control" placeholder="주문 사유" value="<?php echo htmlspecialchars($search_reason); ?>">
      </div>
      <div class="form-group">
        <label>👤 주문자 검색</label>
        <input type="text" name="search_ordered_by" class="form-control" placeholder="주문자" value="<?php echo htmlspecialchars($search_ordered_by); ?>">
      </div>
      <div class="form-group">
        <label>📅 주문일 조회</label>
        <input type="date" name="search_date" class="form-control" value="<?php echo htmlspecialchars($search_date); ?>">
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button type="submit" class="btn">🔍 조회</button>
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button type="button" class="btn btn-success" onclick="openAddModal()">➕ 외부 주문 추가</button>
      </div>
    </form>
  </div>

  <div class="table-section">
    <div class="table-header">
      <h2>📦 외부 주문 목록 (총 <?php echo number_format($total); ?>건)</h2>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>사유</th>
            <th>점심 백반</th>
            <th>점심 샐러드</th>
            <th>주문자</th>
            <th>주문일시</th>
            <th>수정일시</th>
            <th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="8" style="padding:40px; color:var(--muted);">📭 검색 결과가 없습니다</td></tr>
          <?php else: ?>
            <?php foreach($orders as $row): ?>
            <tr>
              <td><?php echo $row['id']; ?></td>
              <td><?php echo htmlspecialchars($row['reason']); ?></td>
              <td><strong><?php echo number_format($row['lunch_qty']); ?></strong></td>
              <td><strong><?php echo number_format($row['lunch_salad_qty']); ?></strong></td>
              <td><?php echo htmlspecialchars($row['ordered_by']); ?></td>
              <td><?php echo date('Y-m-d H:i', strtotime($row['ordered_at'])); ?></td>
              <td><?php echo $row['updated_at'] ? date('Y-m-d H:i', strtotime($row['updated_at'])) : '-'; ?></td>
              <td class="actions">
                <button class="btn-sm btn-edit" onclick='openEditModal(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>✏️ 수정</button>
                <button class="btn-sm btn-delete" onclick="deleteOrder(<?php echo $row['id']; ?>)">🗑️ 삭제</button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">‹</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
      <?php if ($i == $page): ?>
        <span class="current"><?php echo $i; ?></span>
      <?php else: ?>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div id="modal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="modalTitle">외부 주문 추가</h3>
      <button class="close-btn" onclick="closeModal()">×</button>
    </div>
    <form id="orderForm" class="modal-body" onsubmit="return false;">
      <input type="hidden" name="id" id="orderId">
      <div class="modal-form">
        <div class="form-group">
          <label>사유 *</label>
          <input type="text" name="reason" id="reason" class="form-control" required placeholder="예: 손님 접대, 회의">
        </div>
        <div class="form-group">
          <label>점심 백반 수량 *</label>
          <input type="number" name="lunch_qty" id="lunchQty" class="form-control" min="0" value="0" required>
        </div>
        <div class="form-group">
          <label>점심 샐러드 수량 *</label>
          <input type="number" name="lunch_salad_qty" id="lunchSaladQty" class="form-control" min="0" value="0" required>
        </div>
        <div class="form-group">
          <label>주문자 *</label>
          <input type="text" name="ordered_by" id="orderedBy" class="form-control" required value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
        </div>
      </div>
    </form>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal()">취소</button>
      <button type="button" class="btn btn-success" onclick="saveOrder()">저장</button>
    </div>
  </div>
</div>

<button class="back-btn" onclick="location.href='admin_dashboard.php'">👑</button>

<script>
let isEditMode = false;

function openAddModal() {
  isEditMode = false;
  document.getElementById('modalTitle').textContent = '외부 주문 추가';
  document.getElementById('orderForm').reset();
  document.getElementById('orderedBy').value = '<?php echo $_SESSION['user_id']; ?>';
  document.getElementById('modal').classList.add('active');
}

function openEditModal(data) {
  isEditMode = true;
  document.getElementById('modalTitle').textContent = '외부 주문 수정';
  document.getElementById('orderId').value = data.id;
  document.getElementById('reason').value = data.reason;
  document.getElementById('lunchQty').value = data.lunch_qty;
  document.getElementById('lunchSaladQty').value = data.lunch_salad_qty;
  document.getElementById('orderedBy').value = data.ordered_by;
  document.getElementById('modal').classList.add('active');
}

function closeModal() {
  document.getElementById('modal').classList.remove('active');
}

function saveOrder() {
  const formData = new FormData(document.getElementById('orderForm'));
  formData.append('action', isEditMode ? 'update' : 'insert');
  
  fetch('admin_external_api.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert(data.message);
      location.reload();
    } else {
      alert('오류: ' + data.message);
    }
  })
  .catch(err => {
    alert('처리 중 오류가 발생했습니다.');
    console.error(err);
  });
}

function deleteOrder(id) {
  if (!confirm('이 외부 주문을 삭제하시겠습니까?')) return;
  
  const formData = new FormData();
  formData.append('action', 'delete');
  formData.append('id', id);
  
  fetch('admin_external_api.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      alert(data.message);
      location.reload();
    } else {
      alert('오류: ' + data.message);
    }
  })
  .catch(err => {
    alert('삭제 중 오류가 발생했습니다.');
    console.error(err);
  });
}

document.getElementById('modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>
</body>
</html>
