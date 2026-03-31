<?php
include 'auth.php';

// 세션 체크
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_level = $_SESSION['user_level'];

// 접근 제한: 레벨 5, 7, 9만 허용
if (!in_array($user_level, [5,6,7,9])) {
    die("접근 권한이 없습니다.");
}

date_default_timezone_set("Asia/Seoul");

// DB 연결
include 'db_config.php';
$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){
    die("DB 연결 실패: ".$conn->connect_error);
}

// 오늘 날짜
//$today = date('Y-m-d');

if (isset($_COOKIE['pc_datetime_now'])) {
    $current_time = substr($_COOKIE['pc_datetime_now'], 11, 5);
} else {
    $current_time = date('H:i');
}

// PC 시간 기준 날짜
if (isset($_COOKIE['pc_datetime_now'])) {
    $pc_datetime = $_COOKIE['pc_datetime_now'];
    $today = substr($pc_datetime, 0, 10);
    $timestamp = strtotime($today);
} else {
    $today = date('Y-m-d');
    $timestamp = time();
}

// 수령예정 버튼 활성 시간 (00:00 ~ 11:40)
$pickup_allowed = (strtotime($current_time) <= strtotime("11:40"));

// 수령 체크 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_pickup') {
    $meal_type = $_POST['meal_type'];
    $pickup_user_id = $_POST['pickup_user_id'];
    $current_status = (int)$_POST['current_status'];
    $new_status = $current_status ? 0 : 1; // 토글

    // 수령 시간 필드 결정
    $time_field = '';
    $status_field = '';
    switch($meal_type) {
        case 'lunch':
            $status_field = 'lunch_picked';
            $time_field = 'lunch_picked_at';
            break;
        case 'lunch_salad':
            $status_field = 'lunch_salad_picked';
            $time_field = 'lunch_salad_picked_at';
            break;
        case 'dinner_salad':
            $status_field = 'dinner_salad_picked';
            $time_field = 'dinner_salad_picked_at';
            break;
    }

    if ($status_field) {
        // order_data 테이블에서 직접 업데이트
        if ($new_status) {
            $update_sql = "UPDATE order_data SET $status_field = 1, $time_field = NOW() WHERE user_id = ? AND date = ?";
        } else {
            $update_sql = "UPDATE order_data SET $status_field = 0, $time_field = NULL WHERE user_id = ? AND date = ?";
        }
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ss", $pickup_user_id, $today);
        $update_stmt->execute();
    }

    // AJAX 응답
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'new_status' => $new_status,
        'action' => $new_status ? 'unavailable' : 'available'
    ]);
    exit;
}

// 오늘 주문한 사람들과 수령 상태 조회 (단일 테이블에서)
$sql = "
        SELECT o.user_id, l.user_name,
            o.lunch, o.lunch_salad, o.dinner_salad,
            o.lunch_picked, o.lunch_salad_picked, o.dinner_salad_picked,
            o.lunch_picked_at, o.lunch_salad_picked_at, o.dinner_salad_picked_at
        FROM order_data o
        JOIN login_data l ON o.user_id = l.user_id
        WHERE o.date = ?
        AND (o.lunch = 1 OR o.lunch_salad = 1 OR o.dinner_salad = 1)
        ORDER BY o.user_id
        ";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 현재 사용자의 오늘 주문 정보 조회
$user_order_sql = "SELECT lunch, lunch_salad, dinner_salad, lunch_picked, lunch_salad_picked, dinner_salad_picked FROM order_data WHERE user_id = ? AND date = ?";
$user_stmt = $conn->prepare($user_order_sql);
$user_stmt->bind_param("ss", $user_id, $today);
$user_stmt->execute();
$user_order = $user_stmt->get_result()->fetch_assoc();

// 기본값 설정
if (!$user_order) {
    $user_order = [
        'lunch' => 0,
        'lunch_salad' => 0,
        'dinner_salad' => 0,
        'lunch_picked' => 0,
        'lunch_salad_picked' => 0,
        'dinner_salad_picked' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>도시락 수령 체크</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --primary:#2563eb;
            --secondary:#1e40af;
            --bg:#f9fafb;
            --text:#111827;
            --card-bg:#ffffff;
            --radius:12px;
            --shadow:0 4px 10px rgba(0,0,0,0.08);
            --success:#10b981;
            --warning:#f59e0b;
            --danger:#ef4444;
        }

        *{box-sizing:border-box; margin:0; padding:0;}

        body{
            font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;
            background:var(--bg);
            color:var(--text);
            display:flex;
            flex-direction:column;
            align-items:center;
            min-height:100vh;
            padding:20px;
            gap:20px;
        }

        h1{font-size:2rem; color:var(--primary); text-align:center; margin-bottom:10px;}

        .date-info{font-size:1.1rem; color:#6b7280; text-align:center; margin-bottom:10px;}

        .info-notice{
            background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%);
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            padding: 16px 20px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 1rem;
            color: var(--text);
            box-shadow: var(--shadow);
        }

        .container{width:100%; max-width:1200px; display:grid; gap:24px;}

        /* 개인 수령 체크 영역 */
        .my-pickup{background:var(--card-bg); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow); border-left:4px solid var(--primary);}

        .my-pickup h2{font-size:1.3rem; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px;}

        .my-pickup h2::before{content:'👤'; font-size:1.2em;}

        .pickup-grid{display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;}

        .pickup-item{display:flex; align-items:center; justify-content:space-between; padding:16px; border:2px solid #e5e7eb; border-radius:8px; transition:all 0.3s ease;}

        .pickup-item.ordered{border-color:var(--primary); background:#f0f9ff;}

        .pickup-item.not-ordered{background:#f9fafb; opacity:0.6;}

        .meal-name{font-weight:600; font-size:1rem; color:var(--text);}

        .meal-status{font-size:0.9rem; color:#6b7280; margin-top:2px;}

        .pickup-btn{padding:10px 16px; border:none; border-radius:6px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.3s ease; min-width:140px; position:relative;}

        .pickup-btn:disabled{opacity:0.5; cursor:not-allowed;}

        .pickup-btn:hover:not(:disabled){transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.15);}

        .pickup-btn.available{background:var(--success); color:white;}

        .pickup-btn.available:hover:not(:disabled){background:#059669;}

        .pickup-btn.unavailable{background:var(--danger); color:white;}

        .pickup-btn.unavailable:hover:not(:disabled){background:#dc2626;}

        /* 전체 현황 영역 */
        .all-status{background:var(--card-bg); padding:24px; border-radius:var(--radius); box-shadow:var(--shadow); border-left:4px solid var(--success);}

        .all-status h2{font-size:1.3rem; color:var(--success); margin-bottom:16px; display:flex; align-items:center; gap:8px;}

        .all-status h2::before{content:'📋'; font-size:1.2em;}

        .status-table{width:100%; border-collapse:collapse; margin-top:10px;}

        .status-table th, .status-table td{padding:12px 8px; text-align:center; border-bottom:1px solid #e5e7eb;}

        .status-table th{background:#f8fafc; font-weight:600; color:var(--text); font-size:0.9rem;}

        .status-table td{font-size:0.9rem;}

        .status-table .user-col{text-align:left; font-weight:600; color:var(--primary);}

        .status-cell{display:flex; flex-direction:column; align-items:center; gap:4px;}

        .order-badge{display:inline-block; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:600;}

        .order-badge.ordered{background:#dbeafe; color:#1e40af;}

        .order-badge.not-ordered{background:#f3f4f6; color:#6b7280;}

        .order-badge.unavailable{background:#fecaca; color:#dc2626;}

        .pickup-status{font-size:0.8rem; margin-top:2px;}

        .picked{color:var(--success); font-weight:600;}

        .not-picked{color:var(--warning); font-weight:600;}

        .summary{display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;}

        .summary-item{background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; padding:20px; border-radius:var(--radius); text-align:center; box-shadow:var(--shadow);}

        .summary-item h3{font-size:1.1rem; margin-bottom:8px; opacity:0.9;}

        .summary-item .number{font-size:2rem; font-weight:700;}

        .btn{background:var(--card-bg); border:2px solid transparent; padding:12px 24px; font-size:1rem; border-radius:var(--radius); cursor:pointer; transition:all 0.3s; text-decoration:none; display:inline-block; color:var(--text);}

        .btn:hover{border-color:var(--primary); background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff;}

        .btn.primary{background:var(--primary); color:#fff; border:0;}

        .btn.primary:hover{background:var(--secondary);}

        .btn-back {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 24px;
            background: var(--success);
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            font-size: 1.1rem;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-back:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        /* 메시지 알림 */
        .message{position:fixed; top:20px; right:20px; padding:12px 20px; border-radius:6px; font-weight:600; z-index:1000; opacity:0; transform:translateX(100%); transition:all 0.3s ease;}

        .message.show{opacity:1; transform:translateX(0);}

        .message.success{background:var(--success); color:white;}

        .message.info{background:var(--primary); color:white;}

        .back-btn{
            position:fixed;
            bottom:30px;
            right:30px;
            background:var(--primary);
            color:white;
            border:none;
            width:60px;
            height:60px;
            border-radius:50%;
            font-size:1.5rem;
            cursor:pointer;
            box-shadow:0 4px 16px rgba(37,99,235,0.3);
            transition:all 0.3s ease;
            z-index:100;
        }

        .back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

        @media (min-width: 768px){
            .container{grid-template-columns:1fr 2fr;}
        }

        @media (max-width: 768px){
            .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}
            h1{font-size:1.6rem;}
            .pickup-grid{grid-template-columns:1fr;}
            .pickup-item{flex-direction:column; gap:12px; text-align:center;}
            .pickup-btn{min-width:120px;}
            .status-table{font-size:0.8rem;}
            .status-table th, .status-table td{padding:8px 4px;}
            .message{position:fixed; top:auto; bottom:20px; left:20px; right:20px; text-align:center;}
        }
    </style>
</head>
<body>
    <h1>🍱 도시락 수령 확인</h1>
    <div class="date-info">📅 <?php
$days = ['일','월','화','수','목','금','토'];
echo date('Y년 m월 d일 ', $timestamp) . '(' . $days[date('w', $timestamp)] . '요일)';
?>
    <div class="info-notice">
        ℹ️ <strong></strong> 기본적으로 모두 수령하는 것으로 간주됩니다. 
<span style="color: var(--danger); font-weight: 600;">수령이 불가능한 경우</span><br> 
<span style="color: var(--danger); font-weight: 600; text-decoration: underline;">11시 40분까지</span> 체크해 주세요.
    </div>

    <div class="container">
        <!-- 개인 수령 체크 -->
        <div class="my-pickup">
            <h2>수령 불가능 여부 체크</h2>
            <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 12px; margin-bottom: 16px; font-size: 0.9rem; color: #92400e;">
                <strong>📢 중요:</strong> 수령 <strong>불가능한 경우에만</strong> 버튼 클릭
            </div>
            <div class="pickup-grid">
                <!-- 점심 백반 -->
                <div class="pickup-item <?php echo ($user_order && $user_order['lunch']) ? 'ordered' : 'not-ordered'; ?>">
                    <div>
                        <div class="meal-name">🍚 점심 백반</div>
                        <div class="meal-status">
                            <?php echo ($user_order && $user_order['lunch']) ? '주문함' : '주문하지 않음'; ?>
                        </div>
                    </div>
                    <button class="pickup-btn <?php echo $user_order['lunch_picked'] ? 'unavailable' : 'available'; ?>"
                            data-meal="lunch"
                            data-user="<?php echo $user_id; ?>"
                            data-status="<?php echo $user_order['lunch_picked']; ?>"
                            title="<?php echo $user_order['lunch_picked'] ? '수령불가 상태입니다. 클릭하면 수령예정으로 변경됩니다.' : '수령예정 상태입니다. 수령이 불가능하면 클릭하세요.'; ?>"
                            <?php echo (!$user_order || !$user_order['lunch'] || !$pickup_allowed) ? 'disabled' : ''; ?>>
                        <?php echo $user_order['lunch_picked'] ? '수령불가 ❌' : '수령예정 ✅'; ?>
                    </button>
                </div>

                <!-- 점심 샐러드 -->
                <div class="pickup-item <?php echo ($user_order && $user_order['lunch_salad']) ? 'ordered' : 'not-ordered'; ?>">
                    <div>
                        <div class="meal-name">🥗 점심 샐러드</div>
                        <div class="meal-status">
                            <?php echo ($user_order && $user_order['lunch_salad']) ? '주문함' : '주문하지 않음'; ?>
                        </div>
                    </div>
                    <button class="pickup-btn <?php echo $user_order['lunch_salad_picked'] ? 'unavailable' : 'available'; ?>"
                            data-meal="lunch_salad"
                            data-user="<?php echo $user_id; ?>"
                            data-status="<?php echo $user_order['lunch_salad_picked']; ?>"
                            title="<?php echo $user_order['lunch_salad_picked'] ? '수령불가 상태입니다. 클릭하면 수령예정으로 변경됩니다.' : '수령예정 상태입니다. 수령이 불가능하면 클릭하세요.'; ?>"
                            <?php echo (!$user_order || !$user_order['lunch_salad'] || !$pickup_allowed) ? 'disabled' : ''; ?>>
                        <?php echo $user_order['lunch_salad_picked'] ? '수령불가 ❌' : '수령예정 ✅'; ?>
                    </button>
                </div>

                <!-- 저녁 샐러드 -->
                <div class="pickup-item <?php echo ($user_order && $user_order['dinner_salad']) ? 'ordered' : 'not-ordered'; ?>">
                    <div>
                        <div class="meal-name">🌙 저녁 샐러드</div>
                        <div class="meal-status">
                            <?php echo ($user_order && $user_order['dinner_salad']) ? '주문함' : '주문하지 않음'; ?>
                        </div>
                    </div>
                    <button class="pickup-btn <?php echo $user_order['dinner_salad_picked'] ? 'unavailable' : 'available'; ?>"
                            data-meal="dinner_salad"
                            data-user="<?php echo $user_id; ?>"
                            data-status="<?php echo $user_order['dinner_salad_picked']; ?>"
                            title="<?php echo $user_order['dinner_salad_picked'] ? '수령불가 상태입니다. 클릭하면 수령예정으로 변경됩니다.' : '수령예정 상태입니다. 수령이 불가능하면 클릭하세요.'; ?>"
                            <?php echo (!$user_order || !$user_order['dinner_salad'] || !$pickup_allowed) ? 'disabled' : ''; ?>>
                        <?php echo $user_order['dinner_salad_picked'] ? '수령불가 ❌' : '수령예정 ✅'; ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- 전체 현황 -->
        <div class="all-status">
            <h2>전체 수령 현황</h2>
            <div style="background: #f0fdf4; border: 1px solid #22c55e; border-radius: 6px; padding: 10px; margin-bottom: 16px; font-size: 0.85rem; color: #166534; text-align: center;">
                <strong>✅ 수령예정:</strong> 기본상태 (별도 표시 없음) <br> <strong>❌ 수령불가:</strong> 빨간 배지로 표시
            </div>
            
            <!-- 요약 정보 -->
            <div class="summary">
                <?php
                $total_lunch = $total_lunch_salad = $total_dinner_salad = 0;
                $unavailable_lunch = $unavailable_lunch_salad = $unavailable_dinner_salad = 0;

                foreach($orders as $order) {
                    if($order['lunch']) {
                        $total_lunch++;
                        if($order['lunch_picked']) $unavailable_lunch++;
                    }
                    if($order['lunch_salad']) {
                        $total_lunch_salad++;
                        if($order['lunch_salad_picked']) $unavailable_lunch_salad++;
                    }
                    if($order['dinner_salad']) {
                        $total_dinner_salad++;
                        if($order['dinner_salad_picked']) $unavailable_dinner_salad++;
                    }
                }

                $available_lunch = $total_lunch - $unavailable_lunch;
                $available_lunch_salad = $total_lunch_salad - $unavailable_lunch_salad;
                $available_dinner_salad = $total_dinner_salad - $unavailable_dinner_salad;
                ?>
                <div class="summary-item">
                    <h3>🍚 점심 백반</h3>
                    <div class="number"><?php echo $available_lunch; ?>/<?php echo $total_lunch; ?></div>
                    <small style="opacity: 0.8;">수령예정/총주문</small>
                </div>
                <div class="summary-item">
                    <h3>🥗 점심 샐러드</h3>
                    <div class="number"><?php echo $available_lunch_salad; ?>/<?php echo $total_lunch_salad; ?></div>
                    <small style="opacity: 0.8;">수령예정/총주문</small>
                </div>
                <div class="summary-item">
                    <h3>🌙 저녁 샐러드</h3>
                    <div class="number"><?php echo $available_dinner_salad; ?>/<?php echo $total_dinner_salad; ?></div>
                    <small style="opacity: 0.8;">수령예정/총주문</small>
                </div>
            </div>

            <!-- 상세 테이블 -->
            <table class="status-table">
                <thead>
                    <tr>
                        <th>이름</th>
                        <th>점심 백반</th>
                        <th>점심 샐러드</th>
                        <th>저녁 샐러드</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($orders as $order): ?>
                    <tr>
                        <!-- 이름 -->
                        <td class="user-col" style="text-align:center;"><?php echo htmlspecialchars($order['user_name']); ?></td>
                        <!-- 점심 백반 -->
                        <td>
                            <div class="status-cell">
                                <?php if($order['lunch']): ?>
                                    <span class="order-badge ordered">주문</span>
                                    <?php if($order['lunch_picked']): ?>
                                        <span class="order-badge unavailable">수령불가</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="order-badge not-ordered">미주문</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- 점심 샐러드 -->
                        <td>
                            <div class="status-cell">
                                <?php if($order['lunch_salad']): ?>
                                    <span class="order-badge ordered">주문</span>
                                    <?php if($order['lunch_salad_picked']): ?>
                                        <span class="order-badge unavailable">수령불가</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="order-badge not-ordered">미주문</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- 저녁 샐러드 -->
                        <td>
                            <div class="status-cell">
                                <?php if($order['dinner_salad']): ?>
                                    <span class="order-badge ordered">주문</span>
                                    <?php if($order['dinner_salad_picked']): ?>
                                        <span class="order-badge unavailable">수령불가</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="order-badge not-ordered">미주문</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 메시지 알림 영역 -->
    <div id="messageContainer"></div>

    <script>
    document.cookie = "pc_datetime_now=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

    (function setPcDatetimeCookie() {
        const now = new Date();

        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const hh = String(now.getHours()).padStart(2, '0');
        const mi = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');

        const pcDatetime = `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;

        document.cookie = `pc_datetime_now=${pcDatetime}; path=/`;
    
        // ⭐ 쿠키 생성 후 새로고침
        if (!sessionStorage.getItem("pc_time_loaded")) {
            sessionStorage.setItem("pc_time_loaded", "1");
            location.reload();
        }
    })();

        // 메시지 표시 함수
        function showMessage(text, type = 'info') {
            const messageContainer = document.getElementById('messageContainer');
            const message = document.createElement('div');
            message.className = `message ${type}`;
            message.textContent = text;
            messageContainer.appendChild(message);

            // 애니메이션으로 표시
            setTimeout(() => message.classList.add('show'), 100);

            // 3초 후 제거
            setTimeout(() => {
                message.classList.remove('show');
                setTimeout(() => messageContainer.removeChild(message), 300);
            }, 3000);
        }

        // 식사 타입을 한국어로 변환
        function getMealTypeName(mealType) {
            const names = {
                'lunch': '점심 백반',
                'lunch_salad': '점심 샐러드',
                'dinner_salad': '저녁 샐러드'
            };
            return names[mealType] || mealType;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // 수령 체크 버튼 이벤트
            document.querySelectorAll('.pickup-btn:not(:disabled)').forEach(btn => {
                btn.addEventListener('click', function() {
                    const mealType = this.dataset.meal;
                    const userId = this.dataset.user;
                    const currentStatus = parseInt(this.dataset.status);
                    const mealName = getMealTypeName(mealType);

                    // 확인 대화상자
                    const action = currentStatus ? '수령예정' : '수령불가';
                    const confirmMessage = currentStatus ? 
                        `${mealName}을(를) 수령예정으로 변경하시겠습니까?` : 
                        `${mealName}을(를) 수령불가로 체크하시겠습니까?\n(수령이 어려운 경우에만 체크해 주세요)`;

                    if (!confirm(confirmMessage)) {
                        return;
                    }

                    // 로딩 상태
                    const originalText = this.textContent;
                    this.textContent = '처리중...';
                    this.disabled = true;

                    // AJAX 요청
                    const formData = new FormData();
                    formData.append('action', 'toggle_pickup');
                    formData.append('meal_type', mealType);
                    formData.append('pickup_user_id', userId);
                    formData.append('current_status', currentStatus);

                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // 상태 업데이트
                            this.dataset.status = data.new_status;
                            const newText = data.new_status ? '수령불가 ❌' : '수령예정 ✅';
                            const newTitle = data.new_status ? '수령불가 상태입니다. 클릭하면 수령예정으로 변경됩니다.' : '수령예정 상태입니다. 수령이 불가능하면 클릭하세요.';
                            
                            this.textContent = newText;
                            this.title = newTitle;
                            this.className = 'pickup-btn ' + (data.new_status ? 'unavailable' : 'available');

                            // 성공 메시지 표시
                            const actionText = data.action === 'unavailable' ? '수령불가로 체크되었습니다' : '수령예정으로 변경되었습니다';
                            showMessage(`${mealName} ${actionText}`, 'success');

                            // 페이지 새로고침 (전체 현황 업데이트를 위해)
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            alert('처리 중 오류가 발생했습니다.');
                            this.textContent = originalText;
                        }
                        this.disabled = false;
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('네트워크 오류가 발생했습니다.');
                        this.textContent = originalText;
                        this.disabled = false;
                    });
                });
            });
        });
    </script>

    <button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>
