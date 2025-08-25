<?php
include 'auth.php'; // 세션 체크
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_level'])) {
    header("Location: login.php"); exit;
}
$user_id = $_SESSION['user_id'];
$user_level = $_SESSION['user_level'];
// 접근 제한: 레벨 5, 7만 허용
if (!in_array($user_level, [5,7])) {
    die("접근 권한이 없습니다.");
}
date_default_timezone_set("Asia/Seoul");

// DB 연결
$host='localhost'; $dbname='cvfood'; $user='cvfood'; $pass='Nums135790!!';
$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){ die("DB 연결 실패: ".$conn->connect_error); }

// 공휴일 조회 함수
function getHolidays($conn) {
    $holidays = [];
    $result = $conn->query("SELECT holiday_date FROM holidays");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $holidays[] = $row['holiday_date'];
        }
    }
    return $holidays;
}

// 영업일인지 확인하는 함수 (주말과 공휴일 제외)
function isBusinessDay($date, $holidays) {
    $dateObj = new DateTime($date);
    $dayOfWeek = $dateObj->format('N'); // 1(월) ~ 7(일)
    
    // 토요일(6) 또는 일요일(7)이면 false
    if ($dayOfWeek == 6 || $dayOfWeek == 7) {
        return false;
    }
    
    // 공휴일이면 false
    if (in_array($date, $holidays)) {
        return false;
    }
    
    return true;
}

// 공휴일 목록 가져오기
$holidays = getHolidays($conn);

// 현재 시간 기준 주문 날짜 계산 (영업일만)
$now = new DateTime();
$hour = (int)$now->format('H'); 
$minute = (int)$now->format('i');

if($hour >= 12){
    $orderDate = $now->modify('+1 day')->format('Y-m-d');
} else {
    $orderDate = $now->format('Y-m-d');
}

// 영업일이 아니면 다음 영업일로 이동
while (!isBusinessDay($orderDate, $holidays)) {
    $orderDate = (new DateTime($orderDate))->modify('+1 day')->format('Y-m-d');
}

// 요일 배열
$weekdays = ["일","월","화","수","목","금","토"];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>식사 선택</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --primary:#2563eb; --secondary:#1e40af; --bg:#f9fafb;
  --text:#111827; --card-bg:#ffffff; --radius:12px;
  --shadow:0 4px 10px rgba(0,0,0,0.08);
}
*{box-sizing:border-box; margin:0; padding:0;}
body{ font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif; background:var(--bg);
color:var(--text); display:flex; flex-direction:column; align-items:center;
min-height:100vh; padding:20px; gap:16px;}
h1{font-size:2rem; color:var(--primary); text-align:center;}
p#current-time{font-size:1.05rem; color:#374151; text-align:center;}
#date-navigation{display:flex; justify-content:center; align-items:center; gap:15px;}
#date-navigation button{ background:var(--card-bg); border:2px solid transparent; padding:8px 14px; font-size:1rem; border-radius:var(--radius); box-shadow:var(--shadow); cursor:pointer; transition:all .3s ease; }
#date-navigation button:hover{ border-color:var(--primary); background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff; }
.wrap{display:grid; grid-template-columns: 1fr; gap:16px; width:100%; max-width:980px;}
form{ background:var(--card-bg); padding:20px; border-radius:var(--radius); box-shadow:var(--shadow); }
.checkbox-group{display:flex; flex-direction:column; gap:14px; margin-top:10px; margin-bottom:24px;}
.checkbox-item{position:relative;}
.checkbox-item label{font-size:1.1rem; display:flex; align-items:center; gap:10px; cursor:pointer;}
.hint{font-size:.9rem; color:#6b7280; margin-left:2px;}
.warning{color:#ef4444; font-size:.85rem; margin-left:2px; display:block; margin-top:4px;}
.menu-badge{display:inline-block; padding:3px 8px; border-radius:6px; font-size:.8rem; color:#fff; margin-left:8px;}
.menu-badge.today{background:#10b981;}
.menu-badge.tomorrow{background:#ef4444;}
.menu-badge.closed{background:#6b7280;}
.menu-badge.reserve{background:#2563eb;}
.menu-badge.holiday{background:#ff9800;}
input[type="checkbox"]{transform:scale(1.2); cursor:pointer;}
.btn{background:var(--card-bg); border:2px solid transparent; padding:12px 16px; font-size:1rem; border-radius:var(--radius); box-shadow:var(--shadow); cursor:pointer; transition:all .3s; width:100%; margin-bottom:10px;}
.btn:hover{border-color:var(--primary); background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff;}
.btn.primary{background:#2563eb; color:#fff; border:0;}
.btn.primary:hover{background:#1e40af;}
.btn.success{background:#4CAF50; color:#fff; border:0;}
.btn.success:hover{background:#388E3C;}
.secondary{background:#ef4444; color:#fff;}
.secondary:hover{filter:brightness(.95);}
.panel{ background:var(--card-bg); padding:16px; border-radius:var(--radius); box-shadow:var(--shadow); }
.panel h2{font-size:1.1rem; margin-bottom:8px; color:#111827;}
.pending-list{display:flex; flex-wrap:wrap; gap:8px;}
.tag{ background:#e5e7eb; color:#111827; padding:6px 10px; border-radius:9999px; font-size:.9rem; display:flex; align-items:center; gap:6px; }
.tag .x{cursor:pointer; color:#ef4444; font-weight:700;}
.badge{display:inline-block; padding:2px 6px; border-radius:6px; font-size:.8rem; color:#fff; margin-left:6px;}
.badge.today{background:#10b981;}
.badge.tomorrow{background:#ef4444;}
.badge.closed{background:#6b7280;}
.badge.reserve{background:#2563eb;}
.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

.holiday-notice{background:#fff3cd; border:1px solid #ffeaa7; color:#856404; padding:10px; border-radius:8px; margin:10px 0; text-align:center;}
@media (min-width: 960px){ .wrap{grid-template-columns:2fr 1fr;} }
@media (max-width: 768px){ h1{font-size:1.6rem;} .checkbox-item label{font-size:1rem;} .btn{font-size:.95rem; padding:10px;} .back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}}
</style>
</head>
<body>
<h1>식사 메뉴 선택</h1>
<p id="current-time"></p>

<div class="panel" style="max-width:980px; width:100%; text-align:left;">
  <h2 style="margin-bottom:6px; color:var(--primary);">주문 가능 시간 안내</h2>
  <ul style="list-style:disc; margin-left:20px; font-size:.95rem; color:#374151; line-height:1.5;">
    <li><strong>백반</strong>: 당일 주문은 <span style="color:#ef4444;">08:55</span> 까지 가능<br> └ 내일 주문은 <span style="color:#2563eb;">12:00 이후</span>부터 가능</li>
    <li><strong>샐러드</strong>: <span style="color:#ef4444;">당일 주문 불가</span><br> └ 내일 주문은 <span style="color:#2563eb;">12:00 ~ 20:00</span> 사이 가능</li>
    <li><strong style="color:#ff9800;">주말 및 공휴일에는 주문이 불가능합니다.</strong></li>
  </ul>
</div>

<div id="date-navigation">
  <button type="button" id="prev-day">&lt;</button>
  <span id="current-date-display"></span>
  <button type="button" id="next-day">&gt;</button>
</div>

<div class="wrap">
<form id="order-form">
  <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
  <input type="hidden" id="meal-date" value="">
  <div id="holiday-notice" class="holiday-notice" style="display:none;">
    주말 또는 공휴일로 인해 주문이 불가능합니다.
  </div>
  <div class="checkbox-group">
    <div class="checkbox-item">
      <label for="baekban">
        <input type="checkbox" id="baekban" value="baekban"> 백반 주문
        <span class="menu-badge" id="badge-baekban"></span>
      </label>
      <span class="warning" id="warn-baekban"></span>
    </div>
    <div class="checkbox-item">
      <label for="salad-lunch">
        <input type="checkbox" id="salad-lunch" value="salad-lunch"> 점심 샐러드
        <span class="menu-badge" id="badge-salad-lunch"></span>
      </label>
      <span class="warning" id="warn-salad-lunch"></span>
    </div>
    <div class="checkbox-item">
      <label for="salad-dinner">
        <input type="checkbox" id="salad-dinner" value="salad-dinner"> 저녁 샐러드
        <span class="menu-badge" id="badge-salad-dinner"></span>
      </label>
      <span class="warning" id="warn-salad-dinner"></span>
    </div>
  </div>
  <button class="btn primary" id="submit-all" type="submit">제출 (변경된 모든 날짜)</button>
</form>

<div class="panel">
  <h2>변경 예정 <span id="pending-count">0</span>건</h2>
  <div class="pending-list" id="pending-list"></div>
  <p class="hint" style="margin-top:8px;">날짜 이동 후 체크를 바꾸면 이곳에 누적됩니다.</p>
</div>
</div>

<script>
const initialOrderDate = "<?php echo $orderDate; ?>";
let currentOrderDate = new Date(initialOrderDate);
const user_id = "<?php echo $user_id; ?>";
const weekdays = ["일","월","화","수","목","금","토"];
let currentYear = currentOrderDate.getFullYear();
let currentMonth = currentOrderDate.getMonth();

// 공휴일 및 주말 체크를 위한 데이터
const holidays = <?php echo json_encode($holidays); ?>;

function renderTime(){
    const now = new Date();
    const h = now.getHours().toString().padStart(2,'0');
    const m = now.getMinutes().toString().padStart(2,'0');
    const s = now.getSeconds().toString().padStart(2,'0');
    document.getElementById('current-time').textContent = `현재 시간: ${h}:${m}:${s}`;
}

function formatDate(date){ return date.toISOString().split('T')[0]; }
function getDayName(date){ return weekdays[date.getDay()]; }

// 영업일 체크 함수
function isBusinessDay(dateStr) {
    const date = new Date(dateStr);
    const dayOfWeek = date.getDay(); // 0(일) ~ 6(토)
    
    // 토요일(6) 또는 일요일(0)이면 false
    if (dayOfWeek === 0 || dayOfWeek === 6) {
        return false;
    }
    
    // 공휴일이면 false
    if (holidays.includes(dateStr)) {
        return false;
    }
    
    return true;
}

// 다음 영업일 찾기
function getNextBusinessDay(dateStr) {
    let date = new Date(dateStr);
    do {
        date.setDate(date.getDate() + 1);
        const newDateStr = formatDate(date);
        if (isBusinessDay(newDateStr)) {
            return date;
        }
    } while (true);
}

// 이전 영업일 찾기
function getPrevBusinessDay(dateStr) {
    let date = new Date(dateStr);
    do {
        date.setDate(date.getDate() - 1);
        const newDateStr = formatDate(date);
        if (isBusinessDay(newDateStr)) {
            return date;
        }
    } while (true);
}

const pendingChanges = {};
const latestServerCache = {};

function getCurrentState(){
    return {
        lunch: document.getElementById('baekban').checked?1:0,
        lunch_salad: document.getElementById('salad-lunch').checked?1:0,
        dinner_salad: document.getElementById('salad-dinner').checked?1:0
    };
}

function setFromState(st){
    document.getElementById('baekban').checked = !!(st?.lunch);
    document.getElementById('salad-lunch').checked = !!(st?.lunch_salad);
    document.getElementById('salad-dinner').checked = !!(st?.dinner_salad);
}

function markPending(dateStr){ pendingChanges[dateStr]=getCurrentState(); renderPendingBox(); }
function removePending(dateStr){ delete pendingChanges[dateStr]; renderPendingBox(); }

function renderPendingBox(){
    const list = document.getElementById('pending-list');
    list.innerHTML='';
    const dates = Object.keys(pendingChanges).sort();
    document.getElementById('pending-count').textContent = dates.length;
    dates.forEach(d=>{
        const el=document.createElement('span');
        el.className='tag';
        el.innerHTML=`${d} <span class="x" title="목록에서 제거" data-date="${d}">×</span>`;
        list.appendChild(el);
    });
    document.querySelectorAll('.tag .x').forEach(x=>{
        x.onclick=(e)=>{
            const d=e.target.getAttribute('data-date');
            removePending(d);
            if(formatDate(currentOrderDate)===d){
                const fallback=latestServerCache[d]??{lunch:0,lunch_salad:0,dinner_salad:0};
                setFromState(fallback);
                updateMenuAvailability();
            }
        };
    });
}

function fetchOrderData(date){
    const dateStr=formatDate(date);
    if(pendingChanges[dateStr]){
        setFromState(pendingChanges[dateStr]);
        updateMenuAvailability();
    }
    fetch(`fetch_order.php?user_id=${encodeURIComponent(user_id)}&date=${dateStr}`)
    .then(res=>res.json())
    .then(data=>{
        const serverState={
            lunch:Number(data.lunch||0),
            lunch_salad:Number(data.lunch_salad||0),
            dinner_salad:Number(data.dinner_salad||0)
        };
        latestServerCache[dateStr]=serverState;
        if(!pendingChanges[dateStr]){
            setFromState(serverState);
            updateMenuAvailability();
        }
    });
}

// --- 메뉴 뱃지 업데이트 함수 (색상 변경: 오늘=초록, 내일=빨강, 예약일=파랑, 마감=회색, 휴일=주황)
function updateMenuBadges(){
    const now = new Date();
    const hour = now.getHours();
    const minute = now.getMinutes();
    const currentDateStr = formatDate(currentOrderDate);
    const isToday = (currentDateStr === initialOrderDate);
    
    const badgeBaekban = document.getElementById('badge-baekban');
    const badgeSaladLunch = document.getElementById('badge-salad-lunch');
    const badgeSaladDinner = document.getElementById('badge-salad-dinner');
    
    // 영업일이 아닌 경우
    if (!isBusinessDay(currentDateStr)) {
        badgeBaekban.textContent = '휴일';
        badgeBaekban.className = 'menu-badge holiday';
        badgeSaladLunch.textContent = '휴일';
        badgeSaladLunch.className = 'menu-badge holiday';
        badgeSaladDinner.textContent = '휴일';
        badgeSaladDinner.className = 'menu-badge holiday';
        return;
    }
    
    if (isToday) {
        // 오늘인 경우
        // 백반: 8:55 이전 = 오늘(초록), 8:55~12:00 = 마감(회색), 12:00 이후 = 내일(빨강)
        if (hour < 8 || (hour === 8 && minute <= 55)) {
            badgeBaekban.textContent = '오늘';
            badgeBaekban.className = 'menu-badge today';
        } else if (hour < 12) {
            badgeBaekban.textContent = '마감';
            badgeBaekban.className = 'menu-badge closed';
        } else {
            badgeBaekban.textContent = '내일';
            badgeBaekban.className = 'menu-badge tomorrow';
        }
        
        // 샐러드: 12:00 이전 = 마감(회색), 12:00 이후 = 내일(빨강)
        if (hour < 12) {
            badgeSaladLunch.textContent = '마감';
            badgeSaladLunch.className = 'menu-badge closed';
            badgeSaladDinner.textContent = '마감';
            badgeSaladDinner.className = 'menu-badge closed';
        } else {
            badgeSaladLunch.textContent = '내일';
            badgeSaladLunch.className = 'menu-badge tomorrow';
            badgeSaladDinner.textContent = '내일';
            badgeSaladDinner.className = 'menu-badge tomorrow';
        }
    } else {
        // 오늘이 아닌 경우 (내일 이후) = 모두 예약일(파랑)
        badgeBaekban.textContent = '예약일';
        badgeBaekban.className = 'menu-badge reserve';
        badgeSaladLunch.textContent = '예약일';
        badgeSaladLunch.className = 'menu-badge reserve';
        badgeSaladDinner.textContent = '예약일';
        badgeSaladDinner.className = 'menu-badge reserve';
    }
}

// --- 메뉴 시간/마감 처리
function updateMenuAvailability(){
    const currentDateStr = formatDate(currentOrderDate);
    const holidayNotice = document.getElementById('holiday-notice');
    const checkboxGroup = document.querySelector('.checkbox-group');
    const submitButton = document.getElementById('submit-all');
    
    // 영업일이 아닌 경우 모든 메뉴 비활성화
    if (!isBusinessDay(currentDateStr)) {
        holidayNotice.style.display = 'block';
        checkboxGroup.style.opacity = '0.3';
        submitButton.disabled = true;
        submitButton.style.opacity = '0.5';
        
        document.getElementById('baekban').disabled = true;
        document.getElementById('salad-lunch').disabled = true;
        document.getElementById('salad-dinner').disabled = true;
        
        updateMenuBadges();
        return;
    } else {
        holidayNotice.style.display = 'none';
        checkboxGroup.style.opacity = '1';
        submitButton.disabled = false;
        submitButton.style.opacity = '1';
    }

    const now=new Date();
    const hour=now.getHours();
    const minute=now.getMinutes();
    const initialScreen=(currentDateStr===initialOrderDate);

    const baekban=document.getElementById('baekban');
    const saladLunch=document.getElementById('salad-lunch');
    const saladDinner=document.getElementById('salad-dinner');

    const warnB=document.getElementById('warn-baekban');
    const warnSL=document.getElementById('warn-salad-lunch');
    const warnSD=document.getElementById('warn-salad-dinner');

    // 경고 메시지 초기화
    warnB.textContent = '';
    warnSL.textContent = '';
    warnSD.textContent = '';

    // 초기 상태
    let baekbanEnable=true, saladEnable=true;

    if(initialScreen){
        // 현재 서버에서 가져온 원래 상태 확인
        const originalState = latestServerCache[currentDateStr] || {lunch:0, lunch_salad:0, dinner_salad:0};
        const originalSaladOrdered = (originalState.lunch_salad);
        
        // 샐러드가 원래 주문되어 있고, 현재 샐러드 주문 불가능 시간이면
        const saladOrderDisabled = (hour < 12 || hour >= 20);
        
        if(originalSaladOrdered && saladOrderDisabled){
            // 샐러드가 이미 주문되어 있고 샐러드 주문시간이 아니면 백반 주문 불가
            baekbanEnable = false;
            saladEnable = false; // 샐러드도 변경 불가
            warnB.textContent = "기존 샐러드 주문으로 인해 백반 주문(변경) 불가";
            warnSL.textContent = "샐러드 주문시간 외이므로 변경 불가";
            warnSD.textContent = "샐러드 주문시간 외이므로 변경 불가";
        } else {
            // 일반적인 시간 제한
            baekbanEnable = (hour < 8 || (hour === 8 && minute <= 55) || hour >= 12);
            saladEnable = (hour >= 12 && hour < 20);
        }
    }

    baekban.disabled=!baekbanEnable;
    saladLunch.disabled=!saladEnable;
    saladDinner.disabled=!saladEnable;

    // 메뉴 뱃지 업데이트
    updateMenuBadges();

    // 상호 배타 (활성화된 경우만)
    baekban.onchange=()=>{
        if(baekban.checked && !baekban.disabled) {
            saladLunch.checked=false;
        }
        markPending(formatDate(currentOrderDate));
    };
    saladLunch.onchange=()=>{
        if(saladLunch.checked && !saladLunch.disabled) {
            baekban.checked=false;
        }
        markPending(formatDate(currentOrderDate));
    };
    saladDinner.onchange=()=>{ 
        markPending(formatDate(currentOrderDate)); 
    };
}

// --- 렌더
function renderScreen(){
    const currentDateStr = formatDate(currentOrderDate);
    const dayName = getDayName(currentOrderDate);
    
    // 휴일 표시
    let displayText = `${currentDateStr} (${dayName}요일)`;
    if (holidays.includes(currentDateStr)) {
        displayText += ' - 공휴일';
    } else if (currentOrderDate.getDay() === 0 || currentOrderDate.getDay() === 6) {
        displayText += ' - 주말';
    }
    
    document.getElementById('current-date-display').textContent = displayText;
    document.getElementById('meal-date').value = currentDateStr;

    const prevBtn=document.getElementById('prev-day');
    const today = new Date(initialOrderDate);
    const currentDateOnly = new Date(currentOrderDate);
    
    // 오늘보다 이전 날짜로는 갈 수 없도록 제한
    prevBtn.style.visibility = (currentDateOnly <= today) ? 'hidden' : 'visible';

    fetchOrderData(currentOrderDate);
}

// --- 이벤트
document.getElementById('prev-day').addEventListener('click',()=>{
    const today = new Date(initialOrderDate);
    let newDate = new Date(currentOrderDate);
    newDate.setDate(newDate.getDate()-1);
    
    // 오늘보다 이전으로는 갈 수 없음
    if (newDate >= today) {
        // 영업일이 아니면 이전 영업일로 이동
        const newDateStr = formatDate(newDate);
        if (!isBusinessDay(newDateStr)) {
            try {
                newDate = getPrevBusinessDay(newDateStr);
            } catch(e) {
                // 이전 영업일을 찾을 수 없으면 오늘로
                newDate = today;
            }
        }
        
        // 다시 한번 오늘보다 이전인지 체크
        if (newDate >= today) {
            currentOrderDate = newDate;
            renderScreen();
        }
    }
});

document.getElementById('next-day').addEventListener('click',()=>{
    let newDate = new Date(currentOrderDate);
    newDate.setDate(newDate.getDate()+1);
    
    // 영업일이 아니면 다음 영업일로 이동
    const newDateStr = formatDate(newDate);
    if (!isBusinessDay(newDateStr)) {
        newDate = getNextBusinessDay(newDateStr);
    }
    
    currentOrderDate = newDate;
    renderScreen();
});

// --- 일괄 제출
async function batchSubmit(){
    const dates=Object.keys(pendingChanges).sort();
    if(dates.length===0){ alert('변경된 내역이 없습니다.'); return; }

    // 영업일이 아닌 날짜가 포함되어 있는지 체크
    const invalidDates = dates.filter(d => !isBusinessDay(d));
    if (invalidDates.length > 0) {
        alert('주말 또는 공휴일에는 주문할 수 없습니다: ' + invalidDates.join(', '));
        return;
    }

    const snapRes=await fetch('save_prev.php',{method:'POST',
        headers:{'Content-Type':'application/json'}, body:JSON.stringify({user_id, dates})});
    if(!snapRes.ok){ alert('이전 상태 저장에 실패했습니다.'); return; }

    for(const d of dates){
        const st=pendingChanges[d];
        const fd=new FormData();
        fd.append('user_id',user_id);
        fd.append('meal_date',d);
        if(st.lunch) fd.append('meal[]','baekban');
        if(st.lunch_salad) fd.append('meal[]','salad-lunch');
        if(st.dinner_salad) fd.append('meal[]','salad-dinner');
        try{ await fetch('submit.php',{method:'POST', body:fd}); }
        catch(e){ console.error('제출 실패:', d, e); }
    }
    location.href=`result.php?user_id=${encodeURIComponent(user_id)}`;
}

document.getElementById('order-form').addEventListener('submit',(e)=>{ e.preventDefault(); batchSubmit(); });
renderTime(); renderScreen(); renderPendingBox();
setInterval(()=>{ renderTime(); updateMenuAvailability(); },1000);
</script>
<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

</body>
</html>
