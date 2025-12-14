<?php
include 'auth.php';
/**
 * PC 기준 datetime 문자열을 받아
 * 근무일자(Y-m-d)를 반환
 * 기준 시각: 08:30
 */
function getWorkDateFromDatetime($datetime) {
    $dt = new DateTime($datetime);
    $cutoff = clone $dt;
    $cutoff->setTime(8, 30, 0);

    if ($dt < $cutoff) {
        $dt->modify('-1 day');
    }
    return $dt->format('Y-m-d');
}
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

// PC 기준 현재 datetime (쿠키)
if (isset($_COOKIE['pc_datetime_now'])) {
    $pc_now = $_COOKIE['pc_datetime_now'];
} else {
    // 예외 fallback (거의 안 탐)
    $pc_now = date('Y-m-d H:i:s');
}

// 화면 기준 날짜 (08:30 기준)
$today = getWorkDateFromDatetime($pc_now);

// ▼▼▼ 날짜/주간 파라미터 처리 ▼▼▼
$week_offset = isset($_GET['week']) ? intval($_GET['week']) : 0;
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today;

// 미래 날짜인 경우 오늘로 리다이렉트
if (strtotime($selected_date) > strtotime($today)) {
    $selected_date = $today;
}

// 기준 주는 "이번주 월요일"
$base_monday = date(
    'Y-m-d',
    strtotime("monday this week", strtotime($today))
);

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
    ORDER BY
    CASE
        WHEN time < '08:30:00'
        THEN CONCAT(DATE_ADD(date, INTERVAL 1 DAY), ' ', time)
        ELSE CONCAT(date, ' ', time)
    END DESC
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

    // PC 기준 날짜/시간 (JS에서 전달)
    $pc_datetime = $_POST['pc_datetime']; // 예: 2025-01-12 02:15:00

    // 근무일자 (08:30 기준)
    $today = getWorkDateFromDatetime($pc_datetime);

    // 실제 체크 시각 (PC 기준)
    $current_time = substr($pc_datetime, 11); // HH:ii:ss
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
    $_SESSION['success'] = "퇴실 체크 완료!";

// redirect
header("Location: ".$_SERVER['PHP_SELF']."?date=".$today."&week=0");
exit;
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
            ORDER BY
    CASE
        WHEN time < '08:30:00'
        THEN CONCAT(DATE_ADD(date, INTERVAL 1 DAY), ' ', time)
        ELSE CONCAT(date, ' ', time)
    END DESC
LIMIT 1
        ");
        $stmt->bind_param("s", $d);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $weekday = ['일','월','화','수','목','금','토'][$date->format('w')];
$display_date = $d . "($weekday)";

$csv_data .= "$display_date,{$row['user_name']},{$row['time']}\n";
        } else {
            $weekday = ['일','월','화','수','목','금','토'][$date->format('w')];
$display_date = $d . "($weekday)";

$csv_data .= "$display_date,,\n"; // 데이터 없는 날짜는 빈 칸
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

// 전체 퇴실 기록 조회 (선택한 날짜 기준)
$list = $conn->query("
    SELECT *
    FROM check_out
    WHERE date='$selected_date'
    ORDER BY
        CASE
            WHEN time < '08:30:00'
            THEN CONCAT(DATE_ADD(date, INTERVAL 1 DAY), ' ', time)
            ELSE CONCAT(date, ' ', time)
        END DESC
")->fetch_all(MYSQLI_ASSOC);

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
    
    /* 최종 퇴실 기록 모바일 헤더 */
    .daily-header {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        background: #e0e7ff;
        padding: 10px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 13px;
        color: var(--primary);
    }
    
    .daily-header > div {
        text-align: center;
    }
    
    /* 최종 퇴실 기록 모바일 아이템 */
    .daily-record .record-item {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        background: #f8fafc;
        padding: 10px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
        align-items: center;
    }
    
    .daily-record .record-item .name {
        font-size: 13px;
        font-weight: 600;
        text-align: center;
    }
    
    .daily-record .record-item .time {
        text-align: center;
        color: #374151;
        font-size: 13px;
        font-weight: 700;
    }
}

/* 태블릿 최적화 */
@media (min-width: 641px) and (max-width: 1024px) {
    .checkbox-group{
        grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    }
}

/* 최종 퇴실자 조회(Weekly Final) 모바일 스타일 전용 */
@media (max-width: 640px) {
    .weekly-final .record-item {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        background: #f8fafc;
        padding: 10px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
        align-items: center;
    }
    
    .weekly-final .record-item .date-col {
        display: flex;
        flex-direction: row;
        align-items: center;
        justify-content: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.2;
    }
    
    .weekly-final .record-item .day-text {
        font-size: 11px;
        color: #6b7280;
        font-weight: 400;
    }
    
    .weekly-final .record-item .name-col {
        font-size: 13px;
        font-weight: 600;
        text-align: center;
    }

    .weekly-final .record-item .time-col {
        text-align: center;
        color: #374151;
        font-size: 13px;
        font-weight: 700;
    }
    
    /* 모바일 주간 조회 헤더 */
    .weekly-header {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        background: #e0e7ff;
        padding: 10px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 13px;
        color: var(--primary);
    }
    
    .weekly-header > div {
        text-align: center;
    }
}

/* 월 선택 input 크기 확대 */
input[type="month"] {
    padding: 10px 14px;
    font-size: 1rem;
    border: 2px solid #d1d5db;
    border-radius: 8px;
    background-color: #fff;
}

/* 날짜/시간 숫자만 monospace */
.date-mono,
.time-mono {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}
</style>
</head>
<body>
<h1>🕖 최종 퇴실자</h1>
<div class="date-info" id="top-date-fixed">
    📅 <?= 
    date('Y년 m월 d일', strtotime($today)) .
    ' (' .
    ['일','월','화','수','목','금','토'][date('w', strtotime($today))] .
    ')'
?>
</div>
<div class="container">
    <div class="card">
        <h2>최종 퇴실 체크</h2>
        <form method="post" id="checkoutForm">
<input type="hidden" name="pc_datetime" id="pc_datetime">
            <div class="checkbox-group">
                <?php foreach($items as $item): ?>
                    <label><input type="checkbox" class="check-item"> <?= htmlspecialchars($item) ?></label>
                <?php endforeach; ?>
            </div>
            <button class="btn" type="submit" name="checkout" id="checkoutBtn" disabled>최종 퇴실 체크</button>
        </form>
        <?php if (isset($_SESSION['success'])): ?>
    <p style="color:var(--success); margin-top:10px; font-weight:600;">
        <?= $_SESSION['success'] ?>
    </p>
<?php unset($_SESSION['success']); endif; ?>
    </div>
    
    <div class="card">
        <h2>최종 퇴실 기록</h2>
        <div id="date-navigation">
            <a href="?date=<?= $prev_date ?>&week=<?= $week_offset ?>"><button>&lt;</button></a>
            <span class="selected-date"><?= date('Y-m-d', strtotime($selected_date)) ?> (<?= ['일','월','화','수','목','금','토'][date('w', strtotime($selected_date))] ?>)</span>
            <?php if ($is_today): ?>
                <button disabled>&gt;</button>
            <?php else: ?>
                <a href="?date=<?= $next_date ?>&week=<?= $week_offset ?>"><button>&gt;</button></a>
            <?php endif; ?>
        </div>
        <?php if(count($list) === 0): ?>
             <p style="text-align:center; color:#6b7280; font-weight:600; margin-top:10px;">퇴실 기록이 없습니다.</p>
        <?php else: ?>
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
                    <td><span class="time-mono"><?= htmlspecialchars($row['time']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <!-- 모바일: 카드형 -->
        <div class="daily-record">
            <div class="record-list">
                <!-- 모바일 헤더 추가 -->
                <div class="daily-header">
                    <div>이름</div>
                    <div>시간</div>
                </div>
                
                <?php foreach ($list as $row): ?>
                <div class="record-item">
                    <div class="name"><?= htmlspecialchars($row['user_name']) ?></div>
                    <div class="time"><span class="time-mono"><?= htmlspecialchars($row['time']) ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ⭐ 주간 조회 (팀장님 전용) -->
    <?php if ($user_level == 9): ?>
    <div class="card">
        <h2>주간 최종 퇴실자 조회</h2>

        <!-- 주간 이동 네비 -->
        <div id="date-navigation" style="margin-bottom: 18px;">
            <a href="?date=<?= $selected_date ?>&week=<?= $week_offset - 1 ?>">
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
                <a href="?date=<?= $selected_date ?>&week=<?= $week_offset + 1 ?>">
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
                    <td><span class="date-mono"><?= $day ?></span> (<?= $w ?>)</td>
                    <td><?= $uname ?></td>
                    <td><span class="time-mono"><?= $utime ?></span></td>
                </tr>
                <?php endfor; ?>
            </table>
        </div>

        <!-- 모바일 카드 형태 -->
        <div class="weekly-final">
            <div class="record-list">
                <!-- 모바일 헤더 추가 -->
                <div class="weekly-header">
                    <div>날짜</div>
                    <div>이름</div>
                    <div>시간</div>
                </div>
                
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
                    <div class="date-col">
                        <span class="date-mono"><?= $day ?></span>
                        <span class="day-text">(<?= $w ?>)</span>
                    </div>
                    <div class="name-col"><?= $uname ?></div>
                    <div class="time-col"><span class="time-mono"><?= $utime ?></span></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ⭐ 월간 CSV 다운로드 (항상 표시) -->
    <div class="card">
        <h2>월간 최종 퇴실자 CSV 다운로드</h2>
        <form method="post">
            <input
    type="month"
    name="csv_month"
    value="<?= date('Y-m', strtotime($today)) ?>"
    required
>
            <br><br>
            <button class="btn" type="submit" name="download_month_csv">CSV 다운로드</button>
        </form>
    </div>

</div> <!-- container 닫는 태그 -->

<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

<script>
document.cookie = "pc_datetime_now=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
// ✅ 페이지 로드시 PC 시간 → 쿠키 저장 (화면 기준 날짜용)
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
})();
// 체크박스 상태를 sessionStorage에 저장
const checkboxes = document.querySelectorAll('.check-item');
const checkoutBtn = document.getElementById('checkoutBtn');
const formKey = 'checkout_checkboxes';

// 페이지 로드 시 저장된 체크박스 상태 복원
window.addEventListener('DOMContentLoaded', () => {
    const saved = sessionStorage.getItem(formKey);
    if (saved) {
        const states = JSON.parse(saved);
        checkboxes.forEach((cb, idx) => {
            if (states[idx]) cb.checked = true;
        });
        updateButton();
    }
});

// 체크박스 상태 변경 시 저장
checkboxes.forEach((cb, idx) => {
    cb.addEventListener('change', () => {
        const states = Array.from(checkboxes).map(c => c.checked);
        sessionStorage.setItem(formKey, JSON.stringify(states));
        updateButton();
    });
});

function updateButton() {
    const allChecked = Array.from(checkboxes).every(c => c.checked);
    checkoutBtn.disabled = !allChecked;
}

// 폼 제출 시 저장된 상태 삭제
document.getElementById('checkoutForm').addEventListener('submit', function(e){
    if(!confirm('최종 퇴실 체크를 하시겠습니까?')) {
        e.preventDefault();
    } else {
        sessionStorage.removeItem(formKey);
    }
});

document.getElementById('checkoutForm').addEventListener('submit', function () {
    const now = new Date();

    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const hh = String(now.getHours()).padStart(2, '0');
    const mi = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');

    document.getElementById('pc_datetime').value =
        `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
});
</script>
</body>
</html>