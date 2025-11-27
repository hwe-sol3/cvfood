<?php
include 'auth.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    die("관리자 전용 페이지입니다.");
}

include 'db_config.php';
$conn = new mysqli($host, $user, $pass, $dbname);
if($conn->connect_error){ die("DB 연결 실패: ".$conn->connect_error); }

$msg = '';

// 항목 추가
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $check_item = trim($_POST['check_item']);
    if($check_item !== ''){
        $stmt = $conn->prepare("INSERT INTO check_out_list (check_list) VALUES (?)");
        $stmt->bind_param("s", $check_item);
        if ($stmt->execute()) {
            $msg = "항목이 추가되었습니다.";
        } else {
            $msg = "이미 존재하는 항목이거나 오류가 발생했습니다.";
        }
        $stmt->close();
    } else {
        $msg = "항목 이름을 입력해주세요.";
    }
}

// 항목 삭제 (check_list 기준)
if (isset($_GET['delete'])) {
    $check_item = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM check_out_list WHERE check_list=?");
    $stmt->bind_param("s", $check_item);
    if ($stmt->execute()) {
        $msg = "항목이 삭제되었습니다.";
    } else {
        $msg = "삭제 실패: " . $stmt->error;
    }
    $stmt->close();

    // 삭제 후 페이지 리로드
    header("Location: check_out_list.php");
    exit;
}

// 기존 항목 조회
$result = $conn->query("SELECT * FROM check_out_list ORDER BY check_list ASC");
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>최종 퇴실 체크 항목 관리</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --primary: #2563eb; --secondary: #1e40af;
    --bg: #f9fafb; --text: #111827;
    --card-bg: #ffffff; --radius: 12px;
    --shadow: 0 4px 10px rgba(0,0,0,0.08);
}
*{box-sizing:border-box;}
body{font-family:'Segoe UI','Apple SD Gothic Neo',sans-serif;background:var(--bg);color:var(--text);padding:20px;}
h1{color:var(--primary);margin-bottom:15px;}
form{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
input,button{padding:10px;border-radius:var(--radius);border:1px solid #ccc;}
button{background:var(--primary);color:#fff;cursor:pointer;transition:0.3s;}
button:hover{background:var(--secondary);}
.msg{margin-bottom:15px;color:green;}
.list{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
.card{background:var(--card-bg);padding:15px;border-radius:var(--radius);box-shadow:var(--shadow);}
.card h3{margin-bottom:8px;}
.card a{color:red;text-decoration:none;float:right;}
.back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}
@media(max-width:768px){.list{grid-template-columns:1fr;}.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}}
</style>
</head>
<body>
<h1>최종 퇴실 체크 항목 관리</h1>
<?php if($msg) echo "<p class='msg'>$msg</p>"; ?>

<form method="POST">
  <input type="text" name="check_item" placeholder="항목 이름 입력" required>
  <button type="submit" name="add">추가</button>
</form>

<div class="list">
<?php while($row=$result->fetch_assoc()): ?>
  <div class="card">
    <h3><?=htmlspecialchars($row['check_list'])?></h3>
    <a href="?delete=<?=urlencode($row['check_list'])?>" onclick="return confirm('삭제하시겠습니까?')">삭제</a>
  </div>
<?php endwhile; ?>
</div>

<button class="back-btn" onclick="location.href='admin_dashboard.php'" title="처음으로 돌아가기">👑</button>
</body>
</html>
