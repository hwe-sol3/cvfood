<?php
include 'auth.php'; // 세션 체크
include 'db_config.php';
// DB 연결
$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){ die("DB 연결 실패: ".$conn->connect_error); }

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}

$user_id = $_SESSION['user_id'];
$user_level = $_SESSION['user_level'];
date_default_timezone_set("Asia/Seoul");

// 관리자 모드 체크 (레벨 7만 관리자)
$is_admin = in_array($user_level, [7]);
$admin_mode = $is_admin && ($_GET['admin'] ?? '') === '1';

// 검색 파라미터 처리
$search_type = $_GET['search_type'] ?? 'month'; // month 또는 range
$search_month = $_GET['month'] ?? date('Y-m');
$search_start = $_GET['start_date'] ?? '';
$search_end = $_GET['end_date'] ?? '';
$search_user = $admin_mode ? ($_GET['user'] ?? '') : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// 날짜 조건 설정
$where_conditions = [];
$params = [];
$param_types = '';

if ($search_type === 'month' && $search_month) {
    $month_start = $search_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $where_conditions[] = "date >= ? AND date <= ?";
    $params[] = $month_start;
    $params[] = $month_end;
    $param_types .= 'ss';
} elseif ($search_type === 'range' && $search_start && $search_end) {
    $where_conditions[] = "date >= ? AND date <= ?";
    $params[] = $search_start;
    $params[] = $search_end;
    $param_types .= 'ss';
}

// 관리자가 아니면 자신의 주문만 조회
if (!$admin_mode) {
    $where_conditions[] = "user_id = ?";
    $params[] = $user_id;
    $param_types .= 's';
} elseif ($search_user) {
    $where_conditions[] = "user_id LIKE ?";
    $params[] = "%$search_user%";
    $param_types .= 's';
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 전체 개수 조회
$count_sql = "SELECT COUNT(*) as total FROM order_data $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($params) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_count = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_count / $per_page);

// 주문 데이터 조회
$sql = "SELECT user_id, date, lunch, lunch_salad, dinner_salad, 
               lunch_picked, lunch_salad_picked, dinner_salad_picked,
               lunch_picked_at, lunch_salad_picked_at, dinner_salad_picked_at,
               date
        FROM order_data 
        $where_clause 
        ORDER BY date DESC, user_id ASC 
        LIMIT $per_page OFFSET $offset";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 통계 계산
$stats_sql = "SELECT 
    SUM(lunch) as total_lunch,
    SUM(lunch_salad) as total_lunch_salad, 
    SUM(dinner_salad) as total_dinner_salad,
    SUM(lunch_picked) as picked_lunch,
    SUM(lunch_salad_picked) as picked_lunch_salad,
    SUM(dinner_salad_picked) as picked_dinner_salad
    FROM order_data $where_clause";

$stats_stmt = $conn->prepare($stats_sql);
if ($params) {
    $stats_stmt->bind_param($param_types, ...$params);
}
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// 기간 표시용 텍스트
$period_text = '';
if ($search_type === 'month' && $search_month) {
    $period_text = date('Y년 m월', strtotime($search_month . '-01'));
} elseif ($search_type === 'range' && $search_start && $search_end) {
    $period_text = date('Y-m-d', strtotime($search_start)) . ' ~ ' . date('Y-m-d', strtotime($search_end));
} else {
    $period_text = date('Y년 m월');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title><?php echo $admin_mode ? '관리자 주문 조회' : '나의 주문 조회'; ?></title>
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

.header{
  text-align:center; margin-bottom:40px; position:relative;
}
.header h1{
  font-size:2.5rem; color:var(--primary); margin-bottom:10px;
  display:flex; align-items:center; justify-content:center; gap:12px;
}
.header h1::before{content:'📊'; font-size:2rem;}
.header .subtitle{
  font-size:1.1rem; color:var(--muted); margin-bottom:16px;
}
.header .period{
  font-size:1rem; color:var(--primary); font-weight:600;
  background:rgba(37,99,235,0.1); padding:8px 16px; border-radius:20px;
  display:inline-block;
}

/* 모드 전환 버튼 */
.mode-toggle{
  position:absolute; top:0; right:0;
}
.mode-btn{
  background:var(--success); color:white; border:none; padding:12px 20px;
  border-radius:25px; font-size:0.9rem; font-weight:600; cursor:pointer;
  transition:all 0.3s ease; display:inline-flex; align-items:center; gap:8px;
  text-decoration:none;
}
.mode-btn:hover{background:#059669; transform:translateY(-2px); box-shadow:0 4px 12px rgba(16,185,129,0.3);}
.mode-btn.admin{background:var(--danger);}
.mode-btn.admin:hover{background:#dc2626;}

.container{
  max-width:1400px; margin:0 auto;
}

/* 검색 영역 */
.search-section{
  background:var(--card-bg); padding:24px; border-radius:var(--radius);
  box-shadow:var(--shadow); margin-bottom:24px; border-left:4px solid var(--primary);
}

/* 검색 타입 선택 */
.search-type-tabs{
  display:flex; gap:8px; margin-bottom:20px; border-bottom:2px solid var(--border); padding-bottom:16px;
}
.tab-btn{
  padding:10px 20px; border:2px solid transparent; border-radius:8px 8px 0 0;
  background:transparent; color:var(--muted); cursor:pointer; font-weight:600;
  transition:all 0.3s ease; border-bottom:none;
}
.tab-btn.active{
  background:var(--primary); color:white; border-color:var(--primary);
}
.tab-btn:hover:not(.active){
  background:rgba(37,99,235,0.1); color:var(--primary);
}

.search-form{
  display:grid; gap:16px;
}
.search-row{
  display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); 
  gap:16px; align-items:end;
}
.form-group{
  display:flex; flex-direction:column; gap:6px;
}
.form-group label{
  font-weight:600; font-size:0.9rem; color:var(--text);
}
.form-control{
  padding:10px 12px; border:2px solid var(--border); border-radius:8px;
  font-size:1rem; transition:all 0.3s ease; background:var(--card-bg);
}
.form-control:focus{
  outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1);
}
.btn{
  background:var(--primary); color:white; border:none; padding:12px 24px;
  border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer;
  transition:all 0.3s ease; display:inline-flex; align-items:center; gap:8px;
}
.btn:hover{background:var(--secondary); transform:translateY(-1px);}
.btn-secondary{background:var(--muted); color:white;}
.btn-secondary:hover{background:#374151;}

/* 통계 카드 */
.stats-grid{
  display:grid; grid-template-columns:1fr;
  gap:20px; margin-bottom:32px;
}
.stat-card{
  background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color:white; padding:24px; border-radius:var(--radius); 
  box-shadow:var(--shadow); text-align:center; position:relative; overflow:hidden;
}
.stat-card::before{
  content:''; position:absolute; top:0; left:0; right:0; bottom:0;
  background:rgba(255,255,255,0.1); backdrop-filter:blur(10px);
  opacity:0; transition:opacity 0.3s ease;
}
.stat-card:hover::before{opacity:1;}
.stat-card .icon{font-size:2.5rem; margin-bottom:12px; display:block;}
.stat-card h3{font-size:1.2rem; margin-bottom:8px; opacity:0.9;}
.stat-card .number{font-size:2.2rem; font-weight:700; margin-bottom:8px;}
.stat-card .detail{font-size:0.9rem; opacity:0.8;}
.stat-card .detail-row{display:flex; justify-content:space-between; margin-bottom:8px;}

/* 테이블 섹션 */
.table-section{
  background:var(--card-bg); border-radius:var(--radius); 
  box-shadow:var(--shadow); overflow:hidden; margin-bottom:24px;
}
.table-header{
  background:linear-gradient(135deg, var(--primary), var(--secondary));
  color:white; padding:20px 24px; display:flex; justify-content:space-between; align-items:center;
}
.table-header h2{font-size:1.3rem; display:flex; align-items:center; gap:10px;}
.table-header h2::before{content:'📋'; font-size:1.2em;}
.result-count{font-size:0.9rem; opacity:0.9;}

.table-container{
  overflow-x:auto;
}
.order-table{
  width:100%; border-collapse:collapse; background:var(--card-bg);
}
.order-table th{
  background:#f8fafc; padding:16px 12px; font-weight:600; 
  color:var(--text); font-size:0.9rem; text-align:center; border-bottom:2px solid var(--border);
}
.order-table td{
  padding:14px 12px; text-align:center; border-bottom:1px solid var(--border);
  font-size:0.9rem; vertical-align:middle;
}
.order-table tr:hover{background:#f9fafb;}

.user-id{font-weight:600; color:var(--primary); text-align:left !important;}
.date-cell{color:var(--text); font-weight:500;}
.quantity-cell{font-weight:600; font-size:1rem;}

.status-badge{
  display:inline-block; padding:4px 8px; border-radius:6px; 
  font-size:0.75rem; font-weight:600; text-transform:uppercase;
}
.status-picked{background:#d1fae5; color:#059669;}
.status-not-picked{background:#fef3c7; color:#d97706;}

.pickup-info{
  display:flex; flex-direction:column; align-items:center; gap:4px;
}
.pickup-time{font-size:0.75rem; color:var(--muted);}

.order-badge{display:inline-block; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:600;}
.order-badge.ordered{background:#dbeafe; color:#1e40af;}
.order-badge.not-ordered{background:#f3f4f6; color:#6b7280;}

/* 페이지네이션 */
.pagination{
  display:flex; justify-content:center; gap:6px; margin-top:32px; flex-wrap:wrap;
}
.pagination a, .pagination span{
  padding:8px 12px; border:2px solid var(--border); border-radius:6px;
  text-decoration:none; color:var(--text); font-weight:500; transition:all 0.3s ease;
  font-size:0.9rem; min-width:40px; text-align:center;
}
.pagination a:hover{border-color:var(--primary); background:var(--primary); color:white;}
.pagination .current{background:var(--primary); color:white; border-color:var(--primary);}
.pagination .disabled{opacity:0.5; cursor:not-allowed;}

.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

.hidden{display:none !important;}

@media (max-width: 768px){
  body{padding:16px;}
  .header{margin-bottom:30px;}
  .header h1{font-size:2rem;}
  .mode-toggle{position:static; text-align:center; margin-bottom:20px;}
  .search-form{gap:12px;}
  .search-row{grid-template-columns:1fr;}
  .search-type-tabs{flex-direction:column; gap:4px;}
  .tab-btn{border-radius:8px; text-align:center;}
  .stats-grid{grid-template-columns:1fr;}
  .stat-card{padding:20px;}
  .order-table th, .order-table td{padding:10px 6px; font-size:0.8rem;}
  .table-header{padding:16px 20px; flex-direction:column; gap:8px; text-align:center;}
  .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}
  .pagination{gap:4px;}
  .pagination a, .pagination span{padding:6px 8px; font-size:0.8rem; min-width:32px;}
}

@media (max-width: 480px){
  .order-table th, .order-table td{padding:8px 4px; font-size:0.75rem;}
  .status-badge{padding:2px 6px; font-size:0.7rem;}
  .pagination a, .pagination span{padding:5px 6px; font-size:0.75rem; min-width:28px;}
}
</style>
</head>
<body>
<div class="header">
  <h1><?php echo $admin_mode ? '관리자 주문 조회' : '나의 주문 조회'; ?></h1>
  <p class="subtitle"><?php echo $admin_mode ? '전체 직원의 도시락 주문 현황을 관리하세요' : '나의 도시락 주문 내역을 확인하세요'; ?></p>
  <div class="period"><?php echo $period_text; ?> 조회 결과</div>
  
  <?php if ($is_admin): ?>
  <div class="mode-toggle">
    <?php if ($admin_mode): ?>
      <a href="?" class="mode-btn">👤 개인모드로 전환</a>
    <?php else: ?>
      <a href="?admin=1" class="mode-btn admin">👑 관리자모드로 전환</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="container">
  <!-- 검색 영역 -->
  <div class="search-section">
    <div class="search-type-tabs">
      <button type="button" class="tab-btn <?php echo $search_type === 'month' ? 'active' : ''; ?>" onclick="switchSearchType('month')">
        📅 월별 조회
      </button>
      <button type="button" class="tab-btn <?php echo $search_type === 'range' ? 'active' : ''; ?>" onclick="switchSearchType('range')">
        📊 기간별 조회
      </button>
    </div>
    
    <form class="search-form" method="GET" id="searchForm">
      <input type="hidden" name="search_type" id="searchType" value="<?php echo $search_type; ?>">
      <?php if ($admin_mode): ?>
      <input type="hidden" name="admin" value="1">
      <?php endif; ?>
      
      <!-- 월별 조회 -->
      <div id="monthSearch" class="search-row <?php echo $search_type === 'range' ? 'hidden' : ''; ?>">
        <div class="form-group">
          <label>📅 조회 월</label>
          <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($search_month); ?>">
        </div>
        <?php if ($admin_mode): ?>
        <div class="form-group">
          <label>👤 직원ID</label>
          <input type="text" name="user" class="form-control" placeholder="직원ID 검색..." value="<?php echo htmlspecialchars($search_user); ?>">
        </div>
        <?php endif; ?>
        <div class="form-group">
          <button type="submit" class="btn">🔍 조회</button>
        </div>
      </div>
      
      <!-- 기간별 조회 -->
      <div id="rangeSearch" class="search-row <?php echo $search_type === 'month' ? 'hidden' : ''; ?>">
        <div class="form-group">
          <label>📅 시작일</label>
          <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($search_start); ?>">
        </div>
        <div class="form-group">
          <label>📅 종료일</label>
          <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($search_end); ?>">
        </div>
        <?php if ($admin_mode): ?>
        <div class="form-group">
          <label>👤 직원ID</label>
          <input type="text" name="user" class="form-control" placeholder="직원ID 검색..." value="<?php echo htmlspecialchars($search_user); ?>">
        </div>
        <?php endif; ?>
        <div class="form-group">
          <button type="submit" class="btn">🔍 조회</button>
        </div>
      </div>
    </form>
  </div>

  <!-- 통계 카드 -->
  <div class="stats-grid">
    <div class="stat-card">
      <span class="icon">🍽️</span>
      <h3>주문 통계</h3>
      <div class="detail-row">
        <span>점심 백반</span>
        <span><?php echo number_format($stats['total_lunch']); ?>개 <!--(수령: <?php echo $stats['picked_lunch']; ?>개)</span>-->
      </div>
      <div class="detail-row">
        <span>점심 샐러드</span>
        <span><?php echo number_format($stats['total_lunch_salad']); ?>개 <!--(수령: <?php echo $stats['picked_lunch_salad']; ?>개)</span>-->
      </div>
      <div class="detail-row">
        <span>저녁 샐러드</span>
        <span><?php echo number_format($stats['total_dinner_salad']); ?>개 <!--(수령: <?php echo $stats['picked_dinner_salad']; ?>개)</span>-->
      </div>
    </div>
  </div>

  <!-- 주문 목록 테이블 -->
  <div class="table-section">
    <div class="table-header">
      <h2>주문 상세 목록</h2>
    </div>
    
    <div class="table-container">
      <table class="order-table">
        <thead>
          <tr>
            <?php if ($admin_mode): ?>
            <th>직원ID</th>
            <?php endif; ?>
            <th>주문일</th>
            <th>점심 백반</th>
            <th>점심 샐러드</th>
            <th>저녁 샐러드</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr>
            <td colspan="<?php echo $admin_mode ? '5' : '4'; ?>" style="padding:40px; text-align:center; color:var(--muted);">
              📭 검색 조건에 맞는 주문이 없습니다.
            </td>
          </tr>
          <?php else: ?>
            <?php foreach($orders as $order): ?>
            <tr>
              <?php if ($admin_mode): ?>
              <td class="user-id"><?php echo htmlspecialchars($order['user_id']); ?></td>
              <?php endif; ?>
              <!-- 주문일 -->
				<td class="date-cell">
				  <?php 
					$date = date('m-d', strtotime($order['date']));
					$day = ['일', '월', '화', '수', '목', '금', '토'][date('w', strtotime($order['date']))];
					echo $date . ' (' . $day . ')';
				  ?>
				</td>
              
              <!-- 점심 백반 -->
              <td>
                <div class="pickup-info">
                  <span class="quantity-cell"><?php echo $order['lunch'] ? '<span class="order-badge ordered">주문</span>' : '<span class="order-badge not-ordered">미주문</span>'; ?></span>
                </div>
              </td>
              
              <!-- 점심 샐러드 -->
              <td>
                <div class="pickup-info">
                  <span class="quantity-cell"><?php echo $order['lunch_salad'] ? '<span class="order-badge ordered">주문</span>' : '<span class="order-badge not-ordered">미주문</span>'; ?></span>
                </div>
              </td>
              
              <!-- 저녁 샐러드 -->
              <td>
                <div class="pickup-info">
                  <span class="quantity-cell"><?php echo $order['dinner_salad'] ? '<span class="order-badge ordered">주문</span>' : '<span class="order-badge not-ordered">미주문</span>'; ?></span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 페이지네이션 -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">««</a>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">‹</a>
    <?php endif; ?>
    
    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
      <?php if ($i == $page): ?>
        <span class="current"><?php echo $i; ?></span>
      <?php else: ?>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    
    <?php if ($page < $total_pages): ?>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">›</a>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">»»</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

<script>
// 검색 타입 전환
function switchSearchType(type) {
  document.getElementById('searchType').value = type;
  
  // 탭 버튼 활성화
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelector(`.tab-btn[onclick*="${type}"]`).classList.add('active');
  
  // 검색 영역 표시/숨김
  if (type === 'month') {
    document.getElementById('monthSearch').classList.remove('hidden');
    document.getElementById('rangeSearch').classList.add('hidden');
  } else {
    document.getElementById('monthSearch').classList.add('hidden');
    document.getElementById('rangeSearch').classList.remove('hidden');
  }
}

// 테이블 행 클릭 시 하이라이트 효과
document.querySelectorAll('.order-table tr').forEach(row => {
  if (!row.querySelector('th')) { // 헤더 행 제외
    row.addEventListener('click', function() {
      this.style.background = '#e0f2fe';
      setTimeout(() => {
        this.style.background = '';
      }, 2000);
    });
  }
});

// 검색 폼 자동 제출 (날짜 선택 시)
document.querySelector('input[name="date"]').addEventListener('change', function() {
  if (this.value) {
    this.form.submit();
  }
});
</script>
</body>
</html>