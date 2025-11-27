<?php
include 'auth.php';

// 세션 체크
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_level = $_SESSION['user_level'];

// 접근 제한: 레벨 5,6,7,9만 허용
if (!in_array($user_level, [5,6,7,9])) {
    die("접근 권한이 없습니다.");
}

// DB 연결
include 'db_config.php';
$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){
    die("DB 연결 실패: ".$conn->connect_error);
}

// 로그인 데이터에서 이름 가져오기
$stmt = $conn->prepare("SELECT user_name FROM login_data WHERE user_id=?");
$stmt->bind_param("s", $user_id);
$stmt->execute();
$stmt->bind_result($user_name);
$stmt->fetch();
$stmt->close();

if(!$user_name){
    die("사용자 정보를 가져올 수 없습니다.");
}

// 오늘 날짜
$today = date('Y-m-d');

// 체크박스 항목 DB에서 조회
$items = [];
$result = $conn->query("SELECT check_list FROM check_out_list ORDER BY check_list ASC");
while($row = $result->fetch_assoc()){
    $items[] = $row['check_list'];
}

// 퇴근 체크 처리
if (isset($_POST['checkout'])) {
    date_default_timezone_set('Asia/Seoul');

    // 오늘 날짜와 현재 시간
    $today = date('Y-m-d');
    $current_time = date('H:i:s');

    // 1) 오늘 날짜 + user_id 로 기존 기록 있는지 확인
    $stmt = $conn->prepare("
        SELECT * FROM check_out
        WHERE user_id = ? AND date = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $user_id, $today);
    $stmt->execute();
    $stmt->store_result();
    $record_exists = $stmt->num_rows > 0;
    $stmt->close();

    // 2) 있으면 → UPDATE (시간만 갱신)
    if ($record_exists) {
        $stmt = $conn->prepare("
            UPDATE check_out
            SET time = ?, user_name = ?
            WHERE user_id = ? AND date = ?
        ");
        $stmt->bind_param("ssss", $current_time, $user_name, $user_id, $today);
        $stmt->execute();
        $stmt->close();
    } 

    // 3) 없으면 → INSERT
    else {
        $stmt = $conn->prepare("
            INSERT INTO check_out (user_id, user_name, date, time)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $user_id, $user_name, $today, $current_time);
        $stmt->execute();
        $stmt->close();
    }

    $success = "퇴실 체크 완료!";
}

// 날짜 선택
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today;

// 전체 퇴실 기록 조회 (선택한 날짜 기준)
$list = $conn->query("SELECT * FROM check_out WHERE date='$selected_date' ORDER BY time DESC")->fetch_all(MYSQLI_ASSOC);

// 이전/다음 날짜 계산
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>🕖 최종 퇴실자</title>
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
    --danger:#ef4444;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column;align-items:center;min-height:100vh;padding:20px;gap:20px;}
h1{font-size:2rem;color:var(--primary);text-align:center;margin-bottom:10px;}
.date-info{font-size:1.1rem;color:#6b7280;text-align:center;margin-bottom:10px;}
.container{width:100%;max-width:800px;display:grid;gap:24px;}
.card{background:var(--card-bg);padding:24px;border-radius:var(--radius);box-shadow:var(--shadow);border-left:4px solid var(--primary);}
.card h2{font-size:1.3rem;color:var(--primary);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.checkbox-group{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;}
.checkbox-group label{display:flex;align-items:center;gap:6px;font-size:0.95rem;}
.btn{padding:12px 24px;font-size:1rem;border-radius:var(--radius);border:none;cursor:pointer;transition:all 0.3s ease;background:var(--primary);color:white;}
.btn:disabled{background:gray;cursor:not-allowed;}

/* 테이블 고정 너비 */
.table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin-top:16px;
}
.table th, .table td {
    padding:12px 8px;
    border-bottom:1px solid #e5e7eb;
    text-align:center;
    overflow-wrap: break-word;
}
.table th {
    background:#f8fafc;
    font-weight:600;
}
/* 이름/시간 열 너비 고정 */
.table th:nth-child(1), .table td:nth-child(1) { width: 200px; } /* 이름 */
.table th:nth-child(2), .table td:nth-child(2) { width: 150px; } /* 시간 */

/* 화살표 버튼 스타일 */
#date-navigation {
    display:flex;
    justify-content:center;
    align-items:center;
    gap:15px;
    margin-bottom:12px;
}
#date-navigation button {
    background: var(--card-bg);
    border: 2px solid transparent;
    padding: 8px 14px;
    font-size: 1rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    cursor: pointer;
    transition: all 0.3s ease;
}
#date-navigation button:hover {
    border-color: var(--primary);
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: #fff;
}
.selected-date{font-weight:normal;font-size:1rem;}
.back-btn{position:fixed;bottom:30px;right:30px;background:var(--primary);color:white;border:none;width:60px;height:60px;border-radius:50%;font-size:1.5rem;cursor:pointer;box-shadow:0 4px 16px rgba(37,99,235,0.3);transition:all 0.3s ease;}
.back-btn:hover{transform:scale(1.1);box-shadow:0 6px 20px rgba(37,99,235,0.4);}
</style>
</head>
<body>
<h1>🕖 최종 퇴실자</h1>
<div class="date-info">📅 <?= date('Y년 m월 d일 (') . ['일','월','화','수','목','금','토'][date('w', strtotime($selected_date))] . ')' ?></div>

<div class="container">
    <div class="card">
        <h2>최종 퇴실 체크</h2>
        <form method="post" id="checkoutForm">
            <div class="checkbox-group">
                <?php foreach($items as $item): ?>
                    <label><input type="checkbox" class="check-item"> <?= htmlspecialchars($item) ?></label>
                <?php endforeach; ?>
            </div>
            <button class="btn" type="submit" name="checkout" id="checkoutBtn" disabled>최종 퇴실 체크</button>
        </form>
        <?php if (isset($success)) echo "<p style='color:var(--success);margin-top:10px;'>$success</p>"; ?>
    </div>

    <div class="card">
        <h2>최종 퇴실 기록</h2>
        <div id="date-navigation">
            <a href="?date=<?= $prev_date ?>"><button>&lt;</button></a>
            <span class="selected-date"><?= date('Y-m-d', strtotime($selected_date)) ?> (<?= ['일','월','화','수','목','금','토'][date('w', strtotime($selected_date))] ?>)</span>
            <a href="?date=<?= $next_date ?>"><button>&gt;</button></a>
        </div>
        <table class="table">
            <tr>
                <th>이름</th>
                <th>시간</th>
            </tr>
            <?php foreach ($list as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['user_name']) ?></td>
                <td><?= htmlspecialchars($row['time']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

<script>
const checkboxes = document.querySelectorAll('.check-item');
const checkoutBtn = document.getElementById('checkoutBtn');

checkboxes.forEach(cb => {
    cb.addEventListener('change', () => {
        const allChecked = Array.from(checkboxes).every(c => c.checked);
        checkoutBtn.disabled = !allChecked;
    });
});

document.getElementById('checkoutForm').addEventListener('submit', function(e){
    if(!confirm('최종 퇴실 체크를 하시겠습니까?')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
