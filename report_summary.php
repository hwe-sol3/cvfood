<?php
include 'db_config.php';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

// 타임존 고정 (서버 기본값이 UTC일 때 마감/카운트다운 어긋나는 문제 방지)
date_default_timezone_set('Asia/Seoul');

// ========== 주문 시간 설정 (변경 가능) ==========
$ORDER_START_HOUR = 12;      // 다음날 주문 시작 시간 (12시)
$LUNCH_DEADLINE_HOUR = 8;    // 백반 주문 마감 시간 (다음날 8시)
$LUNCH_DEADLINE_MINUTE = 55; // 백반 주문 마감 분 (55분)
$SALAD_DEADLINE_HOUR = 20;   // 샐러드 주문 마감 시간 (전날 20시)
$AUTO_CONFIRM_HOUR = 12;     // 자동 확인 처리 시간 (12시)

// 비밀코드로 접근하는 경우 (업체 관리자용)
if (isset($_GET['code']) && $_GET['code'] === 'cvfood2025') {
    session_start();
    $_SESSION['user_id'] = '업체관리자';
    $_SESSION['user_level'] = 1;
    $is_company_admin = true;
} else {
    include 'auth.php';
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
        header("Location: login.php"); exit;
    }
    if (!in_array($_SESSION['user_level'], [1,7])) {
        die("접근 권한이 없습니다.");
    }
    $is_company_admin = false;
}

// 현재 시간 정보
$current_timestamp = time();
$current_hour = (int)date('H');
$current_minute = (int)date('i');
$today = date('Y-m-d');

// 확인 대상 날짜 계산 (현재 시간에 따라)
if ($current_hour >= $ORDER_START_HOUR) {
    // 12시 이후면 다음날 확인
    $target_date = date('Y-m-d', strtotime('+1 day'));
} else {
    // 12시 이전이면 오늘 확인
    $target_date = $today;
}

// 샐러드 외부주문을 공급일 기준으로 맞추기 위한 보조 함수
// target_date(공급일)가 내일이면 외부주문 합산 기준은 "오늘", target_date가 오늘이면 "어제"
function externalSaladOrderDateForTarget($target_date) {
    $today = date('Y-m-d');
    if ($target_date === date('Y-m-d', strtotime('+1 day'))) {
        return $today; // 내일 공급분 → 오늘 주문 건
    } else {
        return date('Y-m-d', strtotime('-1 day', strtotime($target_date))); // 오늘 공급분 → 어제 주문 건
    }
}

// 월별 조회 처리 - 탭 상태 유지
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'today';
$month_start = $current_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// 확인 처리 (confirmed_by에 동작 구분 추가)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_orders'])) {
    $meal_type = $_POST['meal_type'];
    $total_qty = (int)$_POST['total_qty'];
    $internal_qty = (int)$_POST['internal_qty'];
    $external_qty = (int)$_POST['external_qty'];
    $confirmed_by = ($_SESSION['user_id'] ?? '업체관리자') . '_수동확인';
    
    // 기존 확인 내역이 있으면 업데이트, 없으면 삽입
    $sql_confirm = "INSERT INTO order_confirmations 
        (confirmation_date, meal_type, confirmed_qty, internal_qty, external_qty, confirmed_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        confirmed_qty = VALUES(confirmed_qty),
        internal_qty = VALUES(internal_qty),
        external_qty = VALUES(external_qty),
        confirmed_by = VALUES(confirmed_by),
        confirmed_at = CURRENT_TIMESTAMP";
    
    $stmt_confirm = $conn->prepare($sql_confirm);
    $stmt_confirm->bind_param("ssiiss", $target_date, $meal_type, $total_qty, $internal_qty, $external_qty, $confirmed_by);
    
    if ($stmt_confirm->execute()) {
        $success_message = "주문 확인이 완료되었습니다.";
    } else {
        $error_message = "확인 처리 중 오류가 발생했습니다.";
    }
    $stmt_confirm->close();
}

// 자동 확인 처리 함수
function autoConfirmOrders($conn, $target_date, $confirmed_by_suffix = '_자동확인') {
    // 해당 날짜의 미확인 주문들 조회
    $sql_check = "SELECT meal_type FROM order_confirmations WHERE confirmation_date = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("s", $target_date);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    $confirmed_meals = [];
    while ($row = $result_check->fetch_assoc()) {
        $confirmed_meals[] = $row['meal_type'];
    }
    $stmt_check->close();
    
    // 각 메뉴별로 미확인 상태면 자동 확인 처리
    $meal_types = ['lunch', 'lunch_salad', 'dinner_salad'];
    
    foreach ($meal_types as $meal_type) {
        if (!in_array($meal_type, $confirmed_meals)) {
            // 내부 수량
            $sql_internal = "SELECT 
                SUM(lunch) AS sum_lunch, 
                SUM(lunch_salad) AS sum_lunch_salad, 
                SUM(dinner_salad) AS sum_dinner_salad 
                FROM order_data WHERE date = ?";
            $stmt_internal = $conn->prepare($sql_internal);
            $stmt_internal->bind_param("s", $target_date);
            $stmt_internal->execute();
            $result_internal = $stmt_internal->get_result();
            $internal_data = $result_internal->fetch_assoc();
            $stmt_internal->close();

            // 외부 수량: 백반은 공급일=주문일로 가정, 샐러드는 공급일 = 주문일+1
            if ($meal_type === 'lunch_salad' || $meal_type === 'dinner_salad') {
                $ext_date = externalSaladOrderDateForTarget($target_date); // 주문일
                $sql_external = "SELECT 
                    0 AS sum_lunch,
                    SUM(lunch_salad_qty) AS sum_lunch_salad,
                    0 AS sum_dinner_salad
                    FROM external_orders WHERE DATE(ordered_at) = ?";
            } else {
                $ext_date = $target_date; // 백반: 공급일=주문일
                $sql_external = "SELECT 
                    SUM(lunch_qty) AS sum_lunch, 
                    0 AS sum_lunch_salad, 
                    0 AS sum_dinner_salad 
                    FROM external_orders WHERE DATE(ordered_at) = ?";
            }
            $stmt_external = $conn->prepare($sql_external);
            $stmt_external->bind_param("s", $ext_date);
            $stmt_external->execute();
            $result_external = $stmt_external->get_result();
            $external_data = $result_external->fetch_assoc();
            $stmt_external->close();
            
            // 메뉴별 수량 계산
            switch($meal_type) {
                case 'lunch':
                    $internal_qty = (int)($internal_data['sum_lunch'] ?? 0);
                    $external_qty = (int)($external_data['sum_lunch'] ?? 0);
                    break;
                case 'lunch_salad':
                    $internal_qty = (int)($internal_data['sum_lunch_salad'] ?? 0);
                    $external_qty = (int)($external_data['sum_lunch_salad'] ?? 0);
                    break;
                case 'dinner_salad':
                    $internal_qty = (int)($internal_data['sum_dinner_salad'] ?? 0);
                    $external_qty = 0; // 외부 저녁 샐러드 없음
                    break;
            }
            
            $total_qty = $internal_qty + $external_qty;
            
            // 자동 확인 처리
            $sql_auto_confirm = "INSERT INTO order_confirmations 
                (confirmation_date, meal_type, confirmed_qty, internal_qty, external_qty, confirmed_by)
                VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_auto_confirm = $conn->prepare($sql_auto_confirm);
            $confirmed_by = '시스템' . $confirmed_by_suffix;
            $stmt_auto_confirm->bind_param("ssiiss", $target_date, $meal_type, $total_qty, $internal_qty, $external_qty, $confirmed_by);
            $stmt_auto_confirm->execute();
            $stmt_auto_confirm->close();
        }
    }
}

// 자동 확인 처리 실행 (12시 이후에 이전 날짜 자동 확인)
if ($current_hour >= $AUTO_CONFIRM_HOUR) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    autoConfirmOrders($conn, $yesterday, '_시간초과자동확인');
}

// 주문 상태 및 배지 정보 계산 함수
function getOrderStatus($meal_type) {
    global $current_hour, $current_minute, $current_timestamp;
    global $ORDER_START_HOUR, $LUNCH_DEADLINE_HOUR, $LUNCH_DEADLINE_MINUTE, $SALAD_DEADLINE_HOUR;
    
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    switch($meal_type) {
        case 'lunch':
            // 백반 로직
            if ($current_hour >= $ORDER_START_HOUR) {
                // 12시 이후 - 다음날을 위한 주문 진행중
                $deadline = strtotime($tomorrow . " {$LUNCH_DEADLINE_HOUR}:{$LUNCH_DEADLINE_MINUTE}:00");
                $is_ordering = true;
                $is_closed = false;
            } elseif ($current_hour < $LUNCH_DEADLINE_HOUR || 
                     ($current_hour == $LUNCH_DEADLINE_HOUR && $current_minute < $LUNCH_DEADLINE_MINUTE)) {
                // 마감 전 - 주문 진행중
                $deadline = strtotime($today . " {$LUNCH_DEADLINE_HOUR}:{$LUNCH_DEADLINE_MINUTE}:00");
                $is_ordering = true;
                $is_closed = false;
            } else {
                // 마감 후 - 주문 마감
                $deadline = strtotime($today . " {$LUNCH_DEADLINE_HOUR}:{$LUNCH_DEADLINE_MINUTE}:00");
                $is_ordering = false;
                $is_closed = true;
            }
            break;
            
        case 'lunch_salad':
        case 'dinner_salad':
            // 샐러드 로직 (주문창: 당일 12시~20시, 공급일은 다음날)
            if ($current_hour >= $ORDER_START_HOUR && $current_hour < $SALAD_DEADLINE_HOUR) {
                // 12시~20시 - 주문 진행중
                $deadline = strtotime($today . " {$SALAD_DEADLINE_HOUR}:00:00");
                $is_ordering = true;
                $is_closed = false;
            } elseif ($current_hour >= $SALAD_DEADLINE_HOUR) {
                // 20시 이후 - 주문 마감
                $deadline = strtotime($today . " {$SALAD_DEADLINE_HOUR}:00:00");
                $is_ordering = false;
                $is_closed = true;
            } else {
                // 12시 이전 - 주문 마감(전날 20시 기준)
                $deadline = strtotime('yesterday ' . $SALAD_DEADLINE_HOUR . ':00:00');
                $is_ordering = false;
                $is_closed = true;
            }
            break;
            
        default:
            $deadline = $current_timestamp - 1;
            $is_ordering = false;
            $is_closed = true;
    }

    $remaining_time = max(0, $deadline - $current_timestamp);

    return [
        'is_ordering' => $is_ordering,
        'is_closed' => $is_closed,
        'remaining_time' => $remaining_time,
        'deadline' => $deadline,
    ];
}

// ====== 확인 대상 날짜 주문량 집계 (내부 직원 + 외부인) ======

// 내부 집계: 공급일 기준
$sql_target_internal = "SELECT 
    SUM(lunch) AS sum_lunch, 
    SUM(lunch_salad) AS sum_lunch_salad, 
    SUM(dinner_salad) AS sum_dinner_salad 
    FROM order_data WHERE date = ?";
$stmt_target_internal = $conn->prepare($sql_target_internal);
$stmt_target_internal->bind_param("s", $target_date);
$stmt_target_internal->execute();
$result_target_internal = $stmt_target_internal->get_result();
$target_internal = $result_target_internal->fetch_assoc();

// 외부 집계: 백반은 공급일=주문일, 샐러드는 공급일=주문일+1(즉 target_date가 내일이면 오늘 주문분을 본다)
$ext_salad_order_date = externalSaladOrderDateForTarget($target_date);

// 백반 외부(공급일=주문일)
$sql_target_external_lunch = "SELECT SUM(lunch_qty) AS sum_lunch FROM external_orders WHERE DATE(ordered_at) = ?";
$stmt_target_external_lunch = $conn->prepare($sql_target_external_lunch);
$stmt_target_external_lunch->bind_param("s", $target_date);
$stmt_target_external_lunch->execute();
$res_ext_lunch = $stmt_target_external_lunch->get_result()->fetch_assoc();

// 샐러드 외부(공급일 = 주문일+1)
$sql_target_external_salad = "SELECT SUM(lunch_salad_qty) AS sum_lunch_salad FROM external_orders WHERE DATE(ordered_at) = ?";
$stmt_target_external_salad = $conn->prepare($sql_target_external_salad);
$stmt_target_external_salad->bind_param("s", $ext_salad_order_date);
$stmt_target_external_salad->execute();
$res_ext_salad = $stmt_target_external_salad->get_result()->fetch_assoc();

$target_external = [
    'sum_lunch' => (int)($res_ext_lunch['sum_lunch'] ?? 0),
    'sum_lunch_salad' => (int)($res_ext_salad['sum_lunch_salad'] ?? 0),
    'sum_dinner_salad' => 0
];

$target_data = [
    'sum_lunch' => ((int)($target_internal['sum_lunch'] ?? 0)) + $target_external['sum_lunch'],
    'sum_lunch_salad' => ((int)($target_internal['sum_lunch_salad'] ?? 0)) + $target_external['sum_lunch_salad'],
    'sum_dinner_salad' => (int)($target_internal['sum_dinner_salad'] ?? 0) // 외부 저녁 샐러드 없음
];

// ====== 월별 일자별 주문량 집계 (공급일 기준) ======
// 내부는 date(공급일) 그대로, 외부는 백반: DATE(ordered_at), 샐러드: DATE(ordered_at)+1 로 공급일 정규화
$sql_monthly = "
    SELECT 
        date,
        SUM(internal_lunch + external_lunch) as total_lunch,
        SUM(internal_lunch_salad + external_lunch_salad) as total_lunch_salad,
        SUM(internal_dinner_salad) as total_dinner_salad
    FROM (
        SELECT 
            date,
            SUM(lunch) as internal_lunch,
            SUM(lunch_salad) as internal_lunch_salad,
            SUM(dinner_salad) as internal_dinner_salad,
            0 as external_lunch,
            0 as external_lunch_salad
        FROM order_data 
        WHERE date BETWEEN ? AND ? AND date <= CURDATE()
        GROUP BY date
        
        UNION ALL
        
        -- 외부 백반: 주문일 = 공급일
        SELECT 
            DATE(ordered_at) as date,
            0 as internal_lunch,
            0 as internal_lunch_salad,
            0 as internal_dinner_salad,
            SUM(lunch_qty) as external_lunch,
            0 as external_lunch_salad
        FROM external_orders 
        WHERE DATE(ordered_at) BETWEEN ? AND ? AND DATE(ordered_at) <= CURDATE()
        GROUP BY DATE(ordered_at)

        UNION ALL

        -- 외부 샐러드: 공급일 = 주문일 + 1
        SELECT 
            DATE(DATE_ADD(ordered_at, INTERVAL 1 DAY)) as date,
            0 as internal_lunch,
            0 as internal_lunch_salad,
            0 as internal_dinner_salad,
            0 as external_lunch,
            SUM(lunch_salad_qty) as external_lunch_salad
        FROM external_orders 
        WHERE DATE(DATE_ADD(ordered_at, INTERVAL 1 DAY)) BETWEEN ? AND ? 
              AND DATE(DATE_ADD(ordered_at, INTERVAL 1 DAY)) <= CURDATE()
        GROUP BY DATE(DATE_ADD(ordered_at, INTERVAL 1 DAY))
    ) combined
    GROUP BY date
    ORDER BY date";

$stmt_monthly = $conn->prepare($sql_monthly);
$stmt_monthly->bind_param("ssssss", $month_start, $month_end, $month_start, $month_end, $month_start, $month_end);
$stmt_monthly->execute();
$result_monthly = $stmt_monthly->get_result();
$monthly_data = [];
$monthly_totals = ['lunch' => 0, 'lunch_salad' => 0, 'dinner_salad' => 0];

while ($row = $result_monthly->fetch_assoc()) {
    $monthly_data[] = [
        'date' => $row['date'],
        'total_lunch' => (int)$row['total_lunch'],
        'total_lunch_salad' => (int)$row['total_lunch_salad'],
        'total_dinner_salad' => (int)$row['total_dinner_salad']
    ];
    $monthly_totals['lunch'] += (int)$row['total_lunch'];
    $monthly_totals['lunch_salad'] += (int)$row['total_lunch_salad'];
    $monthly_totals['dinner_salad'] += (int)$row['total_dinner_salad'];
}

// 확인 상태 조회
$sql_confirmations = "SELECT meal_type, confirmed_qty, confirmed_at, confirmed_by FROM order_confirmations WHERE confirmation_date = ?";
$stmt_confirmations = $conn->prepare($sql_confirmations);
$stmt_confirmations->bind_param("s", $target_date);
$stmt_confirmations->execute();
$result_confirmations = $stmt_confirmations->get_result();
$confirmations = [];
while ($row = $result_confirmations->fetch_assoc()) {
    $confirmations[$row['meal_type']] = $row;
}

// 각 메뉴별 상태 정보
$lunch_status = getOrderStatus('lunch');
$lunch_salad_status = getOrderStatus('lunch_salad');
$dinner_salad_status = getOrderStatus('dinner_salad');

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>업체 확인용</title>
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #1e40af;
            --bg: #f9fafb;
            --text: #111827;
            --card-bg: #ffffff;
            --radius: 12px;
            --shadow: 0 4px 10px rgba(0,0,0,0.08);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', 'Apple SD Gothic Neo', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
            min-height: 100vh;
        }

        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 2rem; color: var(--primary); text-align: center; margin-bottom: 30px; }

        .company-admin {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white; padding: 10px 20px; border-radius: var(--radius);
            margin-bottom: 20px; box-shadow: var(--shadow); text-align: center;
        }

        .tabs { display: flex; margin-bottom: 30px; background: var(--card-bg);
            border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }

        .tab-button { flex: 1; padding: 15px 20px; background: transparent; border: none;
            cursor: pointer; font-size: 1.1rem; transition: all 0.3s ease; border-bottom: 3px solid transparent; }

        .tab-button.active { background: var(--primary); color: white; border-bottom-color: var(--secondary); }
        .tab-button:hover:not(.active) { background: #f3f4f6; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); padding: 24px; margin-bottom: 24px; }

        .month-navigation { display: flex; justify-content: center; align-items: center; gap: 20px; margin-bottom: 20px; }
        .month-nav-btn { background: var(--primary); color: white; border: none; padding: 10px 15px; border-radius: var(--radius); cursor: pointer; font-size: 1.2rem; transition: background 0.3s ease; }
        .month-nav-btn:hover { background: var(--secondary); }
        .current-month { font-size: 1.3rem; font-weight: bold; color: var(--primary); }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background: var(--card-bg); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); }
        th, td { padding: 12px 16px; text-align: center; border-bottom: 1px solid #e5e7eb; }
        th { background: var(--primary); color: white; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f9fafb; }

        .order-list-item { display: flex; flex-direction: column; padding: 16px; border-bottom: 1px solid #e5e7eb; }
        .order-list-item:last-child { border-bottom: none; }
        .order-info { display: flex; justify-content: space-between; align-items: center; }
        .order-header { display: flex; flex-direction: column; align-items: flex-start; margin-bottom: 12px; }
        .order-title { font-size: 1.1rem; font-weight: bold; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .order-details { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
        .order-quantity { display: flex; align-items: center; gap: 8px; }
        .order-confirm { text-align: right; }
        
        .confirm-btn { background: var(--success); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; transition: background 0.3s ease; white-space: nowrap; min-width: 80px; }
        .confirm-btn:hover { background: #047857; }
        .confirm-btn:disabled { background: #d1d5db; cursor: not-allowed; }
        .ordering-btn { background: #d1d5db; color: #6b7280; border: none; padding: 8px 16px; border-radius: 6px; cursor: not-allowed; font-size: 0.9rem; white-space: nowrap; min-width: 80px; }

        .quantity-text { font-size: 1.1rem; color: var(--text); font-weight: 500; }
        .quantity-large { font-size: 1.1rem; font-weight: bold; color: var(--primary); }

        .confirmed-status { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .confirmed-btn { background: #4b5563; color: white; padding: 8px 16px; border-radius: 6px; font-size: 0.9rem; white-space: nowrap; }
        .confirmed-time { font-size: 0.8rem; color: #6b7280; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; }
        .badge-ordering { background: #dbeafe; color: #1d4ed8; }
        .badge-closed { background: #fee2e2; color: #b91c1c; }

        .countdown-info { font-size: 0.85rem; color: var(--danger); font-weight: 500; margin-top: 4px; }

        .btn-back { display: inline-block; margin-top: 30px; padding: 12px 24px; background: var(--success); color: white; text-decoration: none; border-radius: var(--radius); font-size: 1.1rem; text-align: center; transition: all 0.3s ease; box-shadow: var(--shadow); }
        .btn-back:hover { background: #059669; transform: translateY(-2px); }

        .alert { padding: 15px; border-radius: var(--radius); margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .today-header { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px; }
        .today-date { font-size: 1.2rem; color: var(--primary); font-weight: bold; }
        .back-btn{
		  position:fixed; bottom:30px; right:30px; 
		  background:var(--primary); color:white; border:none; 
		  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
		  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
		  transition:all 0.3s ease; z-index:100;
		}
		.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

        @media (max-width: 480px) {
            body { padding: 10px; }
            .card { padding: 16px; }
            .order-title { font-size: 1rem; }
            .order-details { flex-direction: column; align-items: flex-start; gap: 12px; }
            .order-confirm { width: 100%; text-align: center; }
            .order-quantity { align-items: flex-start; }
            .confirm-btn, .confirmed-btn, .ordering-btn { width: 100%; 
			.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;} }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>업체 확인용</h1>
        
        <?php if ($is_company_admin): ?>
            <div class="company-admin">
                🏢 업체 관리자 모드로 접근 중입니다
            </div>
        <?php endif; ?>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-button <?php echo $active_tab === 'today' ? 'active' : ''; ?>" onclick="showTab('today')">주문 확인</button>
            <button class="tab-button <?php echo $active_tab === 'monthly' ? 'active' : ''; ?>" onclick="showTab('monthly')">월별 주문</button>
        </div>

        <div id="today-tab" class="tab-content <?php echo $active_tab === 'today' ? 'active' : ''; ?>">
            <div class="card">
                <div class="today-header">
                    <h2>주문 수량 확인</h2>
                    <div class="today-date">(<?php echo date('m월 d일', strtotime($target_date)); ?>)</div>
                </div>
                
                <!-- 백반 점심 -->
                <div class="order-list-item">
                    <div class="order-header">
                        <div class="order-title">
                            🍚 백반 점심
                            <span class="badge <?php echo $lunch_status['is_ordering'] ? 'badge-ordering' : 'badge-closed'; ?>">
                                <?php echo $lunch_status['is_ordering'] ? '주문 진행중' : '주문 마감'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="order-details">
                        <div class="order-quantity">
                            <?php if (isset($confirmations['lunch'])): ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo (int)$confirmations['lunch']['confirmed_qty']; ?></span>
                            <?php elseif ($lunch_status['is_ordering']): ?>
                                <div class="countdown-info" id="countdown-lunch" data-remaining="<?php echo $lunch_status['remaining_time']; ?>">마감까지 남은 시간</div>
                            <?php else: ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo $target_data['sum_lunch']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="order-confirm">
                            <?php if (isset($confirmations['lunch'])): ?>
                                <div class="confirmed-status">
                                    <div class="confirmed-btn">확인완료</div>
                                    <span class="confirmed-time">
                                        <?php echo date('Y-m-d H:i', strtotime($confirmations['lunch']['confirmed_at'])); ?>
                                        <?php if (strpos($confirmations['lunch']['confirmed_by'], '자동') !== false): ?>
                                            <br><small style="color: #f59e0b;">(자동)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php elseif ($lunch_status['is_ordering']): ?>
                                <div class="ordering-btn">주문 진행중</div>
                            <?php else: ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="meal_type" value="lunch">
                                    <input type="hidden" name="total_qty" value="<?php echo $target_data['sum_lunch']; ?>">
                                    <input type="hidden" name="internal_qty" value="<?php echo (int)($target_internal['sum_lunch'] ?? 0); ?>">
                                    <input type="hidden" name="external_qty" value="<?php echo (int)($target_external['sum_lunch'] ?? 0); ?>">
                                    <button type="submit" name="confirm_orders" class="confirm-btn">확인</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 샐러드 점심 -->
                <div class="order-list-item">
                    <div class="order-header">
                        <div class="order-title">
                            🥗 샐러드 점심
                            <span class="badge <?php echo $lunch_salad_status['is_ordering'] ? 'badge-ordering' : 'badge-closed'; ?>">
                                <?php echo $lunch_salad_status['is_ordering'] ? '주문 진행중' : '주문 마감'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="order-details">
                        <div class="order-quantity">
                            <?php if (isset($confirmations['lunch_salad'])): ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo (int)$confirmations['lunch_salad']['confirmed_qty']; ?></span>
                            <?php elseif ($lunch_salad_status['is_ordering']): ?>
                                <div class="countdown-info" id="countdown-lunch-salad" data-remaining="<?php echo $lunch_salad_status['remaining_time']; ?>">마감까지 남은 시간</div>
                            <?php else: ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo $target_data['sum_lunch_salad']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="order-confirm">
                            <?php if (isset($confirmations['lunch_salad'])): ?>
                                <div class="confirmed-status">
                                    <div class="confirmed-btn">확인완료</div>
                                    <span class="confirmed-time">
                                        <?php echo date('Y-m-d H:i', strtotime($confirmations['lunch_salad']['confirmed_at'])); ?>
                                        <?php if (strpos($confirmations['lunch_salad']['confirmed_by'], '자동') !== false): ?>
                                            <br><small style="color: #f59e0b;">(자동)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php elseif ($lunch_salad_status['is_ordering']): ?>
                                <div class="ordering-btn">주문 진행중</div>
                            <?php else: ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="meal_type" value="lunch_salad">
                                    <input type="hidden" name="total_qty" value="<?php echo $target_data['sum_lunch_salad']; ?>">
                                    <input type="hidden" name="internal_qty" value="<?php echo (int)($target_internal['sum_lunch_salad'] ?? 0); ?>">
                                    <input type="hidden" name="external_qty" value="<?php echo (int)($target_external['sum_lunch_salad'] ?? 0); ?>">
                                    <button type="submit" name="confirm_orders" class="confirm-btn">확인</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 샐러드 저녁 -->
                <div class="order-list-item">
                    <div class="order-header">
                        <div class="order-title">
                            🥗 샐러드 저녁
                            <span class="badge <?php echo $dinner_salad_status['is_ordering'] ? 'badge-ordering' : 'badge-closed'; ?>">
                                <?php echo $dinner_salad_status['is_ordering'] ? '주문 진행중' : '주문 마감'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="order-details">
                        <div class="order-quantity">
                            <?php if (isset($confirmations['dinner_salad'])): ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo (int)$confirmations['dinner_salad']['confirmed_qty']; ?></span>
                            <?php elseif ($dinner_salad_status['is_ordering']): ?>
                                <div class="countdown-info" id="countdown-dinner-salad" data-remaining="<?php echo $dinner_salad_status['remaining_time']; ?>">마감까지 남은 시간</div>
                            <?php else: ?>
                                <span class="quantity-text">확인 수량</span>
                                <span class="quantity-large"><?php echo $target_data['sum_dinner_salad']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="order-confirm">
                            <?php if (isset($confirmations['dinner_salad'])): ?>
                                <div class="confirmed-status">
                                    <div class="confirmed-btn">확인완료</div>
                                    <span class="confirmed-time">
                                        <?php echo date('Y-m-d H:i', strtotime($confirmations['dinner_salad']['confirmed_at'])); ?>
                                        <?php if (strpos($confirmations['dinner_salad']['confirmed_by'], '자동') !== false): ?>
                                            <br><small style="color: #f59e0b;">(자동)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php elseif ($dinner_salad_status['is_ordering']): ?>
                                <div class="ordering-btn">주문 진행중</div>
                            <?php else: ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="meal_type" value="dinner_salad">
                                    <input type="hidden" name="total_qty" value="<?php echo $target_data['sum_dinner_salad']; ?>">
                                    <input type="hidden" name="internal_qty" value="<?php echo (int)($target_internal['sum_dinner_salad'] ?? 0); ?>">
                                    <input type="hidden" name="external_qty" value="0">
                                    <button type="submit" name="confirm_orders" class="confirm-btn">확인</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="monthly-tab" class="tab-content <?php echo $active_tab === 'monthly' ? 'active' : ''; ?>">
            <div class="card">
                <div class="month-navigation">
                    <button class="month-nav-btn" onclick="changeMonth(-1)">◀</button>
                    <span class="current-month"><?php echo date('Y년 m월', strtotime($current_month . '-01')); ?></span>
                    <button class="month-nav-btn" onclick="changeMonth(1)">▶</button>
                </div>

                <table style="margin-bottom: 30px;">
                    <thead>
                        <tr><th>메뉴</th><th>월 총계</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>백반 점심</td><td class="quantity-large"><?php echo $monthly_totals['lunch']; ?></td></tr>
                        <tr><td>샐러드 점심</td><td class="quantity-large"><?php echo $monthly_totals['lunch_salad']; ?></td></tr>
                        <tr><td>샐러드 저녁</td><td class="quantity-large"><?php echo $monthly_totals['dinner_salad']; ?></td></tr>
                    </tbody>
                </table>

                <h3 style="margin-bottom: 15px; color: var(--primary);">일별 주문 내역 (공급일 기준)</h3>
                
                <?php if (!empty($monthly_data)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>날짜</th>
                                <th>백반 점심</th>
                                <th>샐러드 점심</th>
                                <th>샐러드 저녁</th>
                                <th>일 합계</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_data as $day): ?>
                                <?php $daily_total = $day['total_lunch'] + $day['total_lunch_salad'] + $day['total_dinner_salad']; ?>
                                <tr>
                                    <td style="font-weight: bold;">
                                        <?php 
                                            $date_obj = new DateTime($day['date']);
                                            $weekdays = ['월', '화', '수', '목', '금', '토', '일'];
                                            $weekday_kor = $weekdays[$date_obj->format('N') - 1];
                                            echo $date_obj->format('m월 d일') . " ({$weekday_kor})";
                                        ?>
                                    </td>
                                    <td><?php echo $day['total_lunch']; ?></td>
                                    <td><?php echo $day['total_lunch_salad']; ?></td>
                                    <td><?php echo $day['total_dinner_salad']; ?></td>
                                    <td style="font-weight: bold; color: var(--primary);"><?php echo $daily_total; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #6b7280; padding: 40px;">
                        해당 월에 주문 내역이 없습니다.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            const targetButton = document.querySelector(`.tab-button[onclick="showTab('${tabName}')"]`);
            if (targetButton) targetButton.classList.add('active');
        }

        function changeMonth(direction) {
            const currentMonth = '<?php echo $current_month; ?>';
            const date = new Date(currentMonth + '-01');
            date.setMonth(date.getMonth() + direction);
            const newMonth = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');

            const code = '<?php echo $is_company_admin ? "&code=cvfood2025" : ""; ?>';
            const self = '<?php echo basename($_SERVER["PHP_SELF"]); ?>'; // 현재 파일명 자동 참조
            window.location.href = `${self}?month=${newMonth}&tab=monthly${code}`;
        }
        
        function updateCountdown() {
            document.querySelectorAll('[id^="countdown-"]').forEach(timer => {
                let remaining = parseInt(timer.dataset.remaining);
                if (remaining > 0) {
                    const hours = Math.floor(remaining / 3600);
                    const minutes = Math.floor((remaining % 3600) / 60);
                    const seconds = remaining % 60;
                    
                    if (hours > 0) {
                        timer.textContent = `마감까지 ${hours}시간 ${minutes}분 ${seconds}초`;
                    } else if (minutes > 0) {
                        timer.textContent = `마감까지 ${minutes}분 ${seconds}초`;
                    } else {
                        timer.textContent = `마감까지 ${seconds}초`;
                    }
                    timer.dataset.remaining = remaining - 1;
                } else {
                    timer.textContent = '마감되었습니다. 새로고침해주세요.';
                    setTimeout(() => { window.location.reload(); }, 2000); 
                }
            });
        }

        window.onload = function() {
            if (document.getElementById('today-tab').classList.contains('active')) {
                setInterval(updateCountdown, 1000);
                updateCountdown();
            }
        };
        
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'today';
        showTab(activeTab);
    </script>
<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>

<?php
// 리소스 정리
$stmt_target_internal->close();
$stmt_target_external_lunch->close();
$stmt_target_external_salad->close();
$stmt_monthly->close();
$stmt_confirmations->close();
$conn->close();
?>
