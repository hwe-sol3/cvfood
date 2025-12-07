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

// ▼▼▼ 주간 조회용 날짜 처리 ▼▼▼
$week_offset = isset($_GET['week']) ? intval($_GET['week']) : 0;

// 기준 주는 “이번주 월요일”
$base_monday = date('Y-m-d', strtotime("monday this week"));

// week_offset 만큼 이동
$target_monday = date('Y-m-d', strtotime("$base_monday $week_offset week"));
$target_sunday = date('Y-m-d', strtotime("$target_monday +6 days"));

// 이번주인지 여부 (오른쪽 화살표 비활성화 조건)
$is_current_week = ($week_offset == 0);

// 주간 마지막 퇴실자 조회
$weekly_data = $conn->query("
    SELECT date, user_name, time
    FROM check_out
    WHERE date BETWEEN '$target_monday' AND '$target_sunday'
    ORDER BY date ASC, time DESC
")->fetch_all(MYSQLI_ASSOC);

// 날짜별 마지막 퇴실자만 저장
$final_weekly = [];
foreach ($weekly_data as $row) {
    if (!isset($final_weekly[$row['date']])) {
        $final_weekly[$row['date']] = $row;
    }
}

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

if (isset($_POST['download_month_csv'])) {
    $month = $_POST['csv_month']; // 예: 2025-01
    if (!$month) {
        die("월을 선택하세요.");
    }

    // 해당 월의 첫날, 마지막날 계산
    $first_day = date('Y-m-01', strtotime($month));
    $last_day = date('Y-m-t', strtotime($month));

    // 날짜 배열 만들기
    $period = new DatePeriod(
        new DateTime($first_day),
        new DateInterval('P1D'),
        (new DateTime($last_day))->modify('+1 day')
    );

    // CSV 데이터 준비
    $csv_data = "날짜,이름,시간\n";

    foreach ($period as $date) {
        $d = $date->format('Y-m-d');

        // 해당 날짜의 최종 퇴실자 1명 조회
        $stmt = $conn->prepare("
            SELECT user_name, time 
            FROM check_out
            WHERE date = ?
            ORDER BY time DESC
            LIMIT 1
        ");
        $stmt->bind_param("s", $d);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $csv_data .= "$d,{$row['user_name']},{$row['time']}\n";
        } else {
            $csv_data .= "$d,,\n"; // 데이터 없는 날짜는 빈 칸
        }
    }

    // CSV 다운로드 처리
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="final_checkout_'.$month.'.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM (엑셀용)
    echo $csv_data;
    exit;
}

// 날짜 선택 (미래 날짜 차단)
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today;
// 미래 날짜인 경우 오늘로 리다이렉트
if (strtotime($selected_date) > strtotime($today)) {
    header("Location: ?date=" . $today);
    exit;
}
// 전체 퇴실 기록 조회 (선택한 날짜 기준)
$list = $conn->query("SELECT * FROM check_out WHERE date='$selected_date' ORDER BY time DESC")->fetch_all(MYSQLI_ASSOC);
// 이전/다음 날짜 계산
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
// 다음 날짜가 미래인지 확인
$is_today = ($selected_date === $today);
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
body{
    font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;
    background:var(--bg);
    color:var(--text);
    display:flex;
    flex-direction:column;
    align-items:center;
    min-height:100vh;
    padding:15px;
    gap:15px;
    padding-bottom:100px;
}
h1{
    font-size:clamp(1.5rem, 5vw, 2rem);
    color:var(--primary);
    text-align:center;
    margin-bottom:5px;
}
.date-info{
    font-size:clamp(0.95rem, 3vw, 1.1rem);
    color:#6b7280;
    text-align:center;
    margin-bottom:10px;
}
.container{
    width:100%;
    max-width:800px;
    display:grid;
    gap:20px;
}
.card{
    background:var(--card-bg);
    padding:20px;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    border-left:4px solid var(--primary);
}
.card h2{
    font-size:clamp(1.1rem, 4vw, 1.3rem);
    color:var(--primary);
    margin-bottom:16px;
    display:flex;
    align-items:center;
    gap:8px;
}
.checkbox-group{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
    gap:10px;
    margin-bottom:16px;
}
.checkbox-group label{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:clamp(0.85rem, 2.5vw, 0.95rem);
    word-break:keep-all;
}
.checkbox-group input[type="checkbox"]{
    flex-shrink:0;
    width:18px;
    height:18px;
}
.btn{
    width:100%;
    padding:14px 24px;
    font-size:clamp(0.95rem, 3vw, 1rem);
    border-radius:var(--radius);
    border:none;
    cursor:pointer;
    transition:all 0.3s ease;
    background:var(--primary);
    color:white;
    font-weight:600;
}
.btn:disabled{
    background:#9ca3af;
    cursor:not-allowed;
}
.btn:not(:disabled):hover{
    background:var(--secondary);
    transform:translateY(-2px);
    box-shadow:0 6px 16px rgba(37,99,235,0.3);
}

/* 데스크톱: 테이블 표시 */
.table-wrapper{
    overflow-x:auto;
    margin-top:16px;
}
.table {
    width:100%;
    border-collapse:collapse;
    min-width:400px;
}
.table th, .table td {
    padding:12px 8px;
    border-bottom:1px solid #e5e7eb;
    text-align:center;
}
.table th {
    background:#f8fafc;
    font-weight:600;
    font-size:0.95rem;
}
.table td{
    font-size:0.9rem;
}

/* 모바일: 카드형 레이아웃 */
.record-list{
    display:none;
}
.record-item{
    background:#f8fafc;
    padding:16px;
    border-radius:8px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    border-left:none;
}
.record-item .name{
    font-weight:600;
    font-size:1rem;
    flex:1;
}
.record-item .time{
    color:#6b7280;
    font-size:0.95rem;
    white-space:nowrap;
}

/* 날짜 네비게이션 */
#date-navigation {
    display:flex;
    justify-content:center;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
    flex-wrap:wrap;
}
#date-navigation a{
    text-decoration:none;
}
#date-navigation button {
    background:var(--card-bg);
    border:2px solid #e5e7eb;
    padding:8px 16px;
    font-size:clamp(0.9rem, 3vw, 1rem);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    cursor:pointer;
    transition:all 0.3s ease;
    min-width:44px;
}
#date-navigation button:hover:not(:disabled) {
    border-color:var(--primary);
    background:var(--primary);
    color:#fff;
}
#date-navigation button:disabled {
    opacity:0.4;
    cursor:not-allowed;
}
.selected-date{
    font-weight:600;
    font-size:clamp(0.9rem, 3vw, 1rem);
    color:var(--primary);
    text-align:center;
}

.back-btn{
    position:fixed;
    bottom:20px;
    right:20px;
    background:var(--primary);
    color:white;
    border:none;
    width:56px;
    height:56px;
    border-radius:50%;
    font-size:1.4rem;
    cursor:pointer;
    box-shadow:0 4px 16px rgba(37,99,235,0.3);
    transition:all 0.3s ease;
    z-index:1000;
}
.back-btn:hover{
    transform:scale(1.1);
    box-shadow:0 6px 20px rgba(37,99,235,0.4);
}

/* 모바일 최적화 */
@media (max-width: 640px) {
    body{
        padding:12px;
        gap:12px;
    }
    .card{
        padding:16px;
    }
    .checkbox-group{
        grid-template-columns:repeat(auto-fit,minmax(100px,1fr));
        gap:8px;
    }
    .table-wrapper{
        display:none;
    }
    .record-list{
        display:block;
    }
    #date-navigation{
        gap:8px;
    }
    #date-navigation button{
        padding:6px 12px;
    }
    .back-btn{
        width:50px;
        height:50px;
        bottom:15px;
        right:15px;
        font-size:1.2rem;
    }
}

/* 태블릿 최적화 */
@media (min-width: 641px) and (max-width: 1024px) {
    .checkbox-group{
        grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    }
}

/* 최종 퇴실자 조회(Weekly Final) 모바일 스타일 전용 */
@media (max-width: 600px) {
    .weekly-final .record-item .name {
        display: flex;
        align-items: center;
        gap: 60px; /* 날짜-이름 사이 간격 */
        font-size: 14px;
    }

    .weekly-final .record-item .time {
        margin-top: 2px;
        font-size: 13px;
    }
}
/* 월 선택 input 크기 확대 */
input[type="month"] {
    padding: 10px 14px;
    font-size: 1rem;
    border: 2px solid #d1d5db;
    border-radius: 8px;
}
.section-wrapper {
    width: 100%;
    max-width: 800px;
    margin: 20px auto;
    margin-top: 10px !important;
}
</style>
</head>
<body>
<h1>🕖 최종 퇴실자</h1>
<div class="date-info" id="top-date-fixed">
    📅 <?= date('Y년 m월 d일 (') . ['일','월','화','수','목','금','토'][date('w')] . ')' ?>
</div>
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
        <?php if (isset($success)) echo "<p style='color:var(--success);margin-top:10px;font-weight:600;'>$success</p>"; ?>
    </div>
    <div class="card">
        <h2>최종 퇴실 기록</h2>
        <div id="date-navigation">
            <a href="?date=<?= $prev_date ?>"><button>&lt;</button></a>
            <span class="selected-date"><?= date('Y-m-d', strtotime($selected_date)) ?> (<?= ['일','월','화','수','목','금','토'][date('w', strtotime($selected_date))] ?>)</span>
            <?php if ($is_today): ?>
                <button disabled>&gt;</button>
            <?php else: ?>
                <a href="?date=<?= $next_date ?>"><button>&gt;</button></a>
            <?php endif; ?>
        </div>

        <!-- 데스크톱: 테이블 -->
        <div class="table-wrapper">
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
        
        <!-- 모바일: 카드형 -->
        <div class="record-list">
            <?php foreach ($list as $row): ?>
            <div class="record-item">
                <div class="name"><?= htmlspecialchars($row['user_name']) ?></div>
                <div class="time"><?= htmlspecialchars($row['time']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<!-- ⭐ 주간 조회 (팀장님 전용) -->
<?php if ($user_level == 9): ?>
<div class="card">
    <h2>주간 최종 퇴실자 조회</h2>

    <!-- 주간 이동 네비 -->
    <div id="date-navigation" style="margin-bottom: 18px;">
        <a href="?week=<?= $week_offset - 1 ?>">
            <button>&lt;</button>
        </a>

        <span class="selected-date">
            <?= date('Y-m-d', strtotime($target_monday)) ?>
            (<?= ['일','월','화','수','목','금','토'][date('w', strtotime($target_monday))] ?>)
            ~
            <?= date('Y-m-d', strtotime($target_sunday)) ?>
            (<?= ['일','월','화','수','목','금','토'][date('w', strtotime($target_sunday))] ?>)
        </span>

        <?php if ($is_current_week): ?>
            <button disabled>&gt;</button>
        <?php else: ?>
            <a href="?week=<?= $week_offset + 1 ?>">
                <button>&gt;</button>
            </a>
        <?php endif; ?>
    </div>

    <!-- 주간 테이블 -->
    <div class="table-wrapper">
        <table class="table">
            <tr>
                <th>날짜</th>
                <th>최종 퇴실자</th>
                <th>시간</th>
            </tr>

            <?php
            // 월요일부터 일요일까지 순서대로 출력
            for ($i = 0; $i < 7; $i++):
                $day = date('Y-m-d', strtotime("$target_monday +$i days"));
                $w = ['일','월','화','수','목','금','토'][date('w', strtotime($day))];

                if (isset($final_weekly[$day])) {
                    $row = $final_weekly[$day];
                    $uname = htmlspecialchars($row['user_name']);
                    $utime = htmlspecialchars($row['time']);
                } else {
                    $uname = "";
                    $utime = "";
                }
            ?>
            <tr>
                <td><?= $day ?> (<?= $w ?>)</td>
                <td><?= $uname ?></td>
                <td><?= $utime ?></td>
            </tr>
            <?php endfor; ?>
        </table>
    </div>

<!-- 모바일 카드 형태 -->
<div class="weekly-final">
<div class="record-list">
    <?php for ($i = 0; $i < 7; $i++):
        $day = date('Y-m-d', strtotime("$target_monday +$i days"));
        $w = ['일','월','화','수','목','금','토'][date('w', strtotime($day))];

        if (isset($final_weekly[$day])) {
            $row = $final_weekly[$day];
            $uname = htmlspecialchars($row['user_name']);
            $utime = htmlspecialchars($row['time']);
        } else {
            $uname = "";
            $utime = "";
        }
    ?>
    <div class="record-item">
        <div class="name">
            <span class="date-text"><?= $day ?> (<?= $w ?>)</span>
            <?php if ($uname): ?>
                <span class="user-text"><?= $uname ?></span>
            <?php endif; ?>
        </div>
        <div class="time"><?= $utime ?></div>
      </div>
      <?php endfor; ?>
    </div>
</div>
<?php endif; ?>
</div>
<div class="section-wrapper">
    <div class="card">
        <h2>월간 최종 퇴실자 CSV 다운로드</h2>
        <form method="post">
            <input type="month" name="csv_month" required>
            <br><br>
            <button class="btn" type="submit" name="download_month_csv">CSV 다운로드</button>
        </form>
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
// ▼▼ 현재 월을 input[type="month"] 기본값으로 지정 ▼▼
const monthInput = document.querySelector('input[name="csv_month"]');
if (monthInput) {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    monthInput.value = `${yyyy}-${mm}`;
}
</script>
</body>
</html>