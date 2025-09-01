<?php
include 'db_config.php';

// 비밀코드로 접근하는 경우 (업체 관리자용)
if (isset($_GET['code']) && $_GET['code'] === 'cvfood2025') {
    // 임시 세션 설정 (업체 관리자)
    $_SESSION['user_id'] = '업체관리자';
    $_SESSION['user_level'] = 1;
    $user_id = '업체관리자';
    $user_level = 1;
    $user_level_kor = '업체 관리자';
} else {
    // 일반 로그인 체크
    include 'auth.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $user_level = $_SESSION['user_level'];
    $user_level_kor = '';

    if ($user_level == 9) {
        $user_level_kor = '관리자';
    }
    else if ($user_level == 7) {
        $user_level_kor = '관리자/직원';
    } else if ($user_level == 6 || $user_level == 5) {
        $user_level_kor = '직원';
    } else if ($user_level == 3) {
        $user_level_kor = '인사기획팀';
    } else if ($user_level == 1) {
        $user_level_kor = '업체';
    }
}

// 그룹별 인원 수 가져오기 (관리자 전용)
if ($user_level_kor === '관리자') {
    try {
        $dbh = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $dbh->query("SELECT `user_group`, COUNT(*) as cnt 
                             FROM login_data 
                             WHERE `user_group` != 0 
                             GROUP BY `user_group` 
                             ORDER BY `user_group`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parts = [];
        foreach ($rows as $row) {
            $groupNum = (int)$row['user_group'];
            $parts[] = "{$groupNum}그룹 - {$row['cnt']}명";
        }
        $groupSummary = implode("<br>", $parts);

    } catch (PDOException $e) {
        $groupSummary = "DB 연결 오류 발생";
    }
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>천안사업장 식사 주문</title>
  <!-- ✅ 모바일 반응형 필수 -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <style>
    :root {
      --primary: #2563eb;
      --secondary: #1e40af;
      --bg: #f9fafb;
      --text: #111827;
      --card-bg: #ffffff;
      --radius: 12px;
      --shadow: 0 4px 10px rgba(0,0,0,0.08);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Segoe UI', 'Apple SD Gothic Neo', sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }

    h1 {
      font-size: 2rem;
      margin-bottom: 10px;
      color: var(--primary);
      text-align: center;
    }

    p.welcome {
      margin-bottom: 30px;
      font-size: 1.1rem;
      color: #374151;
      text-align: center;
    }

    /* ✅ PC 기본: 카드형 그리드 */
    .menu-buttons {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      width: 100%;
      max-width: 800px;
      margin-bottom: 40px;
    }

    .menu-buttons button {
      background: var(--card-bg);
      border: 2px solid transparent;
      padding: 20px;
      font-size: 1.1rem;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      cursor: pointer;
      transition: all 0.3s ease;
      width: 100%;
    }

    .menu-buttons button:hover {
      border-color: var(--primary);
      transform: translateY(-3px);
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: #fff;
    }

    /* 로그아웃 */
    .logout {
      margin-top: 40px;
      text-align: center;
    }
    .logout a {
      text-decoration: none;
      background: #ef4444;
      color: #fff;
      padding: 10px 20px;
      border-radius: var(--radius);
      transition: background 0.3s ease;
    }
    .logout a:hover {
      background: #b91c1c;
    }

    /* 업체 관리자 특별 표시 */
    .company-admin {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      padding: 10px 20px;
      border-radius: var(--radius);
      margin-bottom: 20px;
      box-shadow: var(--shadow);
    }

	.group-summary {
	  display: grid;
	  grid-template-columns: repeat(3, 1fr); /* 한 행에 3개씩 */
	  gap: 15px;
	  margin: 20px 0;
	  width: 100%;
	  max-width: 800px; /* 필요에 따라 조정 */
	}

	.group-card {
	  background: linear-gradient(135deg, #667eea, #764ba2);
	  color: white;
	  padding: 16px;
	  border-radius: 12px;
	  text-align: center;
	  font-weight: 600;
	  font-size: 1rem;
	  box-shadow: 0 4px 10px rgba(0,0,0,0.08);
	  transition: transform 0.3s ease;
	}
	.group-card:hover {
	  transform: translateY(-3px);
	}

    /* ✅ 모바일: 세로 1열 꽉차게 */
    @media (max-width: 768px) {
      .menu-buttons {
        grid-template-columns: 1fr; /* 한 줄에 하나 */
        gap: 15px;
      }

      .menu-buttons button {
        font-size: 1rem;
        padding: 15px;
      }
    }
  </style>
</head>
<body>
  <h1>천안사업장 식사 주문</h1>
  
  <?php if (isset($_GET['code'])): ?>
    <div class="company-admin">
      🏢 업체 관리자 모드로 접근 중입니다
    </div>
  <?php endif; ?>
  
  <p class="welcome"><?php echo htmlspecialchars($user_id); ?>(<?= $user_level_kor?>)님 환영합니다 🎉</p>

  <div class="menu-buttons">
    <?php if ($user_level == 7): ?>
      <button onclick="location.href='menu.php'">🍱 식단표</button>
      <button onclick="location.href='order.php'">📝 식사 주문</button>
      <button onclick="location.href='myOrder.php'">📋 주문 조회</button>
	  <button onclick="location.href='food_picked.php'">🚩 수령 확인 </button>
	  <button onclick="location.href='external_order.php'">🤝 외부인 주문</button>
      <button onclick="location.href='report_summary.php'">🏢 업체 확인용</button>
      <button onclick="location.href='report_finance.php'">📊 당월 주문 통계</button>
	  <button onclick="location.href='holidays.php'">📅 휴일 관리</button>
    <?php elseif ($user_level == 9 || $user_level == 6 || $user_level == 5): ?>
      <button onclick="location.href='menu.php'">🍱 식단표</button>
      <button onclick="location.href='order.php'">📝 식사 주문</button>
      <button onclick="location.href='myOrder.php'">📋 주문 조회</button>
	  <button onclick="location.href='food_picked.php'">🚩 수령 확인 </button>
		<?php if ($user_level == 9 || $user_level == 6): ?>
			<button onclick="location.href='external_order.php'">🤝 외부인 주문</button>
		<?php endif; ?>
    <?php elseif ($user_level == 3): ?>
      <button onclick="location.href='report_finance.php'">📊 당월 주문 통계</button>
    <?php elseif ($user_level == 1): ?>
      <button onclick="location.href='menu.php<?php echo isset($_GET['code']) ? '?code=cvfood2025' : ''; ?>'">🍱 식단표</button>
      <button onclick="location.href='report_summary.php<?php echo isset($_GET['code']) ? '?code=cvfood2025' : ''; ?>'">🏢 업체 확인용</button>
    <?php endif; ?>
  </div>
	<?php if ($user_level_kor === '관리자'): ?>
	  <div class="group-summary">
		<?php 
		foreach (explode("<br>", $groupSummary) as $g): 
		  // "1그룹 - 12명" 형태를 "1그룹<br>12명" 으로 변환
		  $parts = explode("-", $g);
		  if (count($parts) == 2) {
			$groupName = trim($parts[0]);
			$groupCount = trim($parts[1]);
			$g = $groupName . "<br>" . $groupCount;
		  }
		?>
		  <div class="group-card"><?php echo $g; ?></div>
		<?php endforeach; ?>
	  </div>
	<?php endif; ?>
  <div class="logout">
    <?php if (isset($_GET['code'])): ?>
      <a href="index.php">일반 로그인으로 돌아가기</a>
    <?php else: ?>
      <a href="logout.php">로그아웃</a>
    <?php endif; ?>
  </div>
</body>
</html>