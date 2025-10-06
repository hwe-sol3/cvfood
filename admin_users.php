<?php
include 'auth.php';
include 'db_config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    header("Location: login.php");
    exit;
}

$conn = new mysqli($host, $user, $pass, $dbname);
if($conn->connect_error) die("DB 연결 실패");

// 검색 조건
$search = $_GET['search'] ?? '';
$search_level = $_GET['search_level'] ?? '';
$search_group = $_GET['search_group'] ?? '';

// 페이지네이션
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// WHERE 조건
$where = [];
$params = [];
$types = '';

if ($search) {
    $where[] = "(user_id LIKE ? OR user_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($search_level !== '') {
    $where[] = "user_level = ?";
    $params[] = (int)$search_level;
    $types .= 'i';
}

if ($search_group !== '') {
    $where[] = "user_group = ?";
    $params[] = (int)$search_group;
    $types .= 'i';
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 전체 개수
$count_sql = "SELECT COUNT(*) as total FROM login_data $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $per_page);

// 데이터 조회
$sql = "SELECT * FROM login_data $where_clause ORDER BY user_level DESC, user_id ASC LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>직원 관리</title>
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
  font-size:2rem; color:var(--success); margin-bottom:10px;
  display:flex; align-items:center; justify-content:center; gap:12px;
}
.header h1::before{content:'👤'; font-size:2rem;}

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
  background:linear-gradient(135deg, var(--success), #059669);
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
.user-id{font-weight:600; color:var(--primary);}
.actions{display:flex; gap:6px; justify-content:center;}
.btn-sm{
  padding:6px 12px; font-size:0.8rem; border:none; border-radius:6px;
  cursor:pointer; font-weight:600; transition:all 0.3s ease;
}
.btn-edit{background:#fef3c7; color:#d97706;}
.btn-edit:hover{background:#fde68a;}
.btn-delete{background:#fee2e2; color:#dc2626;}
.btn-delete:hover{background:#fecaca;}

.badge{
  display:inline-block; padding:4px 8px; border-radius:6px; 
  font-size:0.75rem; font-weight:600;
}
.badge-admin{background:#fee2e2; color:#dc2626;}
.badge-user{background:#dbeafe; color:#1e40af;}

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
  background:linear-gradient(135deg, var(--success), #059669);
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
  cursor:pointer; box-shadow:0 4px 16px rgba(16,185,129,0.3); 
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
  <h1>직원 관리</h1>
</div>

<div class="container">
  <div class="search-section">
    <form class="search-form" method="GET">
      <div class="form-group">
        <label>🔍 검색 (ID/이름)</label>
        <input type="text" name="search" class="form-control" placeholder="직원ID 또는 이름" value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <div class="form-group">
        <label>권한 레벨</label>
        <select name="search_level" class="form-control">
          <option value="">전체</option>
          <option value="7" <?php echo $search_level === '7' ? 'selected' : ''; ?>>관리자(7)</option>
          <option value="1" <?php echo $search_level === '1' ? 'selected' : ''; ?>>일반(1)</option>
        </select>
      </div>
      <div class="form-group">
        <label>그룹</label>
        <input type="number" name="search_group" class="form-control" placeholder="그룹번호" value="<?php echo htmlspecialchars($search_group); ?>">
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button type="submit" class="btn">🔍 조회</button>
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button type="button" class="btn btn-success" onclick="openAddModal()">➕ 직원 추가</button>
      </div>
    </form>
  </div>

  <div class="table-section">
    <div class="table-header">
      <h2>👥 직원 목록 (총 <?php echo number_format($total); ?>명)</h2>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>직원ID</th>
            <th>이름</th>
            <th>권한</th>
            <th>그룹</th>
            <th>관리</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
          <tr><td colspan="5" style="padding:40px; color:var(--muted);">📭 검색 결과가 없습니다</td></tr>
          <?php else: ?>
            <?php foreach($users as $row): ?>
            <tr>
              <td class="user-id"><?php echo htmlspecialchars($row['user_id']); ?></td>
              <td><?php echo htmlspecialchars($row['user_name'] ?? '-'); ?></td>
              <td>
                <span class="badge <?php echo $row['user_level'] == 7 ? 'badge-admin' : 'badge-user'; ?>">
                  <?php echo $row['user_level'] == 7 ? '관리자' : '일반'; ?> (<?php echo $row['user_level']; ?>)
                </span>
              </td>
              <td><?php echo $row['user_group'] ?? '-'; ?></td>
              <td class="actions">
                <button class="btn-sm btn-edit" onclick='openEditModal(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>✏️ 수정</button>
                <button class="btn-sm btn-delete" onclick="deleteUser('<?php echo $row['user_id']; ?>')">🗑️ 삭제</button>
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
      <h3 id="modalTitle">직원 추가</h3>
      <button class="close-btn" onclick="closeModal()">×</button>
    </div>
    <form id="userForm" class="modal-body" onsubmit="return false;">
      <div class="modal-form">
        <div class="form-group">
          <label>직원ID *</label>
          <input type="text" name="user_id" id="userId" class="form-control" required>
        </div>
        <div class="form-group">
          <label>비밀번호 *</label>
          <input type="password" name="user_pw" id="userPw" class="form-control" required>
        </div>
        <div class="form-group">
          <label>이름 *</label>
          <input type="text" name="user_name" id="userName" class="form-control" required>
        </div>
        <div class="form-group">
          <label>권한 레벨 *</label>
          <select name="user_level" id="userLevel" class="form-control" required>
            <option value="1">일반 (1)</option>
            <option value="7">관리자 (7)</option>
          </select>
        </div>
        <div class="form-group">
          <label>그룹</label>
          <input type="number" name="user_group" id="userGroup" class="form-control" placeholder="선택사항">
        </div>
      </div>
    </form>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal()">취소</button>
      <button type="button" class="btn btn-success" onclick="saveUser()">저장</button>
    </div>
  </div>
</div>

<button class="back-btn" onclick="location.href='admin_dashboard.php'">👑</button>

<script>
let isEditMode = false;
let originalUserId = '';

function openAddModal() {
  isEditMode = false;
  document.getElementById('modalTitle').textContent = '직원 추가';
  document.getElementById('userForm').reset();
  document.getElementById('userId').readOnly = false;
  document.getElementById('userPw').required = true;
  document.getElementById('userPw').placeholder = '';
  document.getElementById('modal').classList.add('active');
}

function openEditModal(data) {
  isEditMode = true;
  originalUserId = data.user_id;
  
  document.getElementById('modalTitle').textContent = '직원 정보 수정';
  document.getElementById('userId').value = data.user_id;
  document.getElementById('userId').readOnly = true;
  document.getElementById('userPw').value = '';
  document.getElementById('userPw').required = false;
  document.getElementById('userPw').placeholder = '변경하지 않으려면 비워두세요';
  document.getElementById('userName').value = data.user_name || '';
  document.getElementById('userLevel').value = data.user_level;
  document.getElementById('userGroup').value = data.user_group || '';
  
  document.getElementById('modal').classList.add('active');
}

function closeModal() {
  document.getElementById('modal').classList.remove('active');
}

function saveUser() {
  const formData = new FormData(document.getElementById('userForm'));
  
  if (isEditMode) {
    formData.append('action', 'update');
    formData.append('original_user_id', originalUserId);
  } else {
    formData.append('action', 'insert');
  }
  
  fetch('admin_users_api.php', {
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

function deleteUser(userId) {
  if (!confirm(`${userId} 직원을 삭제하시겠습니까?\n관련된 모든 주문 데이터도 함께 삭제될 수 있습니다.`)) return;
  
  const formData = new FormData();
  formData.append('action', 'delete');
  formData.append('user_id', userId);
  
  fetch('admin_users_api.php', {
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

// 모달 외부 클릭시 닫기
window.onclick = function(event) {
  const modal = document.getElementById('modal');
  if (event.target === modal) {
    closeModal();
  }
}
</script>
</body>
</html>
