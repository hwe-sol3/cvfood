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
$admin_name = $_SESSION['user_id'];

// 간단한 통계
$today = date('Y-m-d');
$stats = [];

// 오늘 주문 수
$result = $conn->query("SELECT COUNT(*) as cnt FROM order_data WHERE date='$today'");
$stats['today_orders'] = $result->fetch_assoc()['cnt'];

// 전체 직원 수
$result = $conn->query("SELECT COUNT(*) as cnt FROM login_data");
$stats['total_users'] = $result->fetch_assoc()['cnt'];

// 오늘 확정 수
$result = $conn->query("SELECT COUNT(*) as cnt FROM order_confirmations WHERE confirmation_date='$today'");
$stats['today_confirmations'] = $result->fetch_assoc()['cnt'];

// 외부 주문 수
$result = $conn->query("SELECT COUNT(*) as cnt FROM external_orders");
$stats['external_orders'] = $result->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>관리자 대시보드</title>
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
  text-align:center; margin-bottom:40px;
}
.header h1{
  font-size:2.5rem; color:var(--danger); margin-bottom:10px;
  display:flex; align-items:center; justify-content:center; gap:12px;
}
.header h1::before{content:'👑'; font-size:2rem;}
.header .subtitle{
  font-size:1.1rem; color:var(--muted); margin-bottom:16px;
}
.admin-info{
  font-size:0.9rem; color:var(--primary); font-weight:600;
  background:rgba(37,99,235,0.1); padding:8px 16px; border-radius:20px;
  display:inline-block;
}

.container{
  max-width:1400px; margin:0 auto;
}

/* 통계 카드 */
.stats-grid{
  display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));
  gap:20px; margin-bottom:40px;
}
.stat-card{
  background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color:white; padding:24px; border-radius:var(--radius); 
  box-shadow:var(--shadow); text-align:center; position:relative; overflow:hidden;
  transition:transform 0.3s ease;
}
.stat-card:hover{transform:translateY(-5px);}
.stat-card.blue{background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);}
.stat-card.green{background:linear-gradient(135deg, #10b981 0%, #059669 100%);}
.stat-card.orange{background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);}
.stat-card.red{background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%);}
.stat-card .icon{font-size:2.5rem; margin-bottom:12px; display:block;}
.stat-card h3{font-size:1rem; margin-bottom:8px; opacity:0.9; font-weight:500;}
.stat-card .number{font-size:2.5rem; font-weight:700;}

/* 메뉴 그리드 */
.menu-grid{
  display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));
  gap:24px; margin-bottom:40px;
}
.menu-card{
  background:var(--card-bg); border-radius:var(--radius); 
  box-shadow:var(--shadow); overflow:hidden; transition:all 0.3s ease;
  border-top:4px solid var(--primary); cursor:pointer;
}
.menu-card:hover{transform:translateY(-5px); box-shadow:0 8px 20px rgba(0,0,0,0.12);}
.menu-card.green{border-top-color:var(--success);}
.menu-card.orange{border-top-color:var(--warning);}
.menu-card.red{border-top-color:var(--danger);}
.menu-card.purple{border-top-color:#764ba2;}
.menu-card.cyan{border-top-color:#06b6d4;}
.menu-card.pink{border-top-color:#ec4899;}

.menu-card a{
  text-decoration:none; color:inherit; display:block; padding:30px 24px;
}
.menu-icon{
  font-size:3rem; margin-bottom:16px; display:block; text-align:center;
}
.menu-title{
  font-size:1.3rem; font-weight:700; margin-bottom:8px; text-align:center;
  color:var(--text);
}
.menu-desc{
  font-size:0.9rem; color:var(--muted); text-align:center; line-height:1.5;
}

.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

@media (max-width: 768px){
  body{padding:16px;}
  .header h1{font-size:2rem;}
  .stats-grid{grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:16px;}
  .stat-card{padding:20px;}
  .stat-card .icon{font-size:2rem;}
  .stat-card .number{font-size:2rem;}
  .menu-grid{grid-template-columns:1fr; gap:16px;}
  .menu-card a{padding:24px 20px;}
  .menu-icon{font-size:2.5rem;}
  .menu-title{font-size:1.1rem;}
  .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}
}
</style>
</head>
<body>
<div class="header">
  <h1>관리자 대시보드</h1>
  <p class="subtitle">식수관리 시스템 통합 관리</p>
  <div class="admin-info">👤 <?php echo htmlspecialchars($admin_name); ?> 관리자</div>
</div>

<div class="container">
  <!-- 통계 카드 -->
  <div class="stats-grid">
    <div class="stat-card blue">
      <span class="icon">📊</span>
      <h3>오늘 주문</h3>
      <div class="number"><?php echo number_format($stats['today_orders']); ?></div>
    </div>
    <div class="stat-card green">
      <span class="icon">👥</span>
      <h3>전체 직원</h3>
      <div class="number"><?php echo number_format($stats['total_users']); ?></div>
    </div>
    <div class="stat-card orange">
      <span class="icon">✅</span>
      <h3>오늘 확정</h3>
      <div class="number"><?php echo number_format($stats['today_confirmations']); ?></div>
    </div>
    <div class="stat-card red">
      <span class="icon">📦</span>
      <h3>외부 주문</h3>
      <div class="number"><?php echo number_format($stats['external_orders']); ?></div>
    </div>
  </div>

  <!-- 관리 메뉴 -->
  <div class="menu-grid">
    <div class="menu-card">
      <a href="admin_orders.php">
        <span class="menu-icon">🍱</span>
        <div class="menu-title">주문 관리</div>
        <div class="menu-desc">직원들의 도시락 주문 내역을 조회하고 관리합니다</div>
      </a>
    </div>

    <div class="menu-card green">
      <a href="admin_users.php">
        <span class="menu-icon">👤</span>
        <div class="menu-title">직원 관리</div>
        <div class="menu-desc">직원 계정 정보를 조회, 추가, 수정, 삭제합니다</div>
      </a>
    </div>

    <div class="menu-card orange">
      <a href="admin_confirmations.php">
        <span class="menu-icon">✅</span>
        <div class="menu-title">주문 확정 관리</div>
        <div class="menu-desc">업체의 일일 주문 확정 내역을 관리합니다</div>
      </a>
    </div>

    <div class="menu-card red">
      <a href="admin_external.php">
        <span class="menu-icon">📦</span>
        <div class="menu-title">외부 주문 관리</div>
        <div class="menu-desc">외부 주문 내역을 조회하고 관리합니다</div>
      </a>
    </div>

    <div class="menu-card cyan">
      <a href="holidays.php">
        <span class="menu-icon">📅</span>
        <div class="menu-title">휴일 관리</div>
        <div class="menu-desc">휴일을 조회하고 관리합니다</div>
      </a>
    </div>

    <div class="menu-card pink">
      <a href="report_summary.php">
        <span class="menu-icon">📋</span>
        <div class="menu-title">업체 확인 페이지</div>
        <div class="menu-desc">업체가 사용하는 페이지를 조회합니다</div>
      </a>
    </div>

    <div class="menu-card">
      <a href="report_finance.php">
        <span class="menu-icon">📊</span>
        <div class="menu-title">당월 주문 통계</div>
        <div class="menu-desc">이번 달 주문 통계를 조회합니다</div>
      </a>
    </div>
  </div>
</div>

<button class="back-btn" onclick="location.href='index.php'" title="메인으로 돌아가기">🏠</button>
</body>
</html>
