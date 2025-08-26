<?php
include 'auth.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_level'] != 7) {
    die("관리자 전용 페이지입니다.");
}

include 'db_config.php';
$conn = new mysqli($host,$user,$pass,$dbname);
if($conn->connect_error){ die("DB 연결 실패: ".$conn->connect_error); }

// 휴일 추가 처리
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $date = $_POST['holiday_date'];
    $title = $_POST['title'];
    $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, title) VALUES (?, ?)");
    $stmt->bind_param("ss", $date, $title);
    if ($stmt->execute()) {
        $msg = "휴일이 추가되었습니다.";
    } else {
        $msg = "이미 등록된 날짜거나 오류가 발생했습니다.";
    }
    $stmt->close();
}

// 휴일 삭제
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM holidays WHERE id=$id");
    $msg = "삭제되었습니다.";
}

// 조회할 연도 (기본: 올해)
$year = $_GET['year'] ?? date("Y");
$result = $conn->query("SELECT * FROM holidays WHERE YEAR(holiday_date)='$year' ORDER BY holiday_date ASC");
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>휴일 관리</title>
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
  input,select,button{padding:10px;border-radius:var(--radius);border:1px solid #ccc;}
  button{background:var(--primary);color:#fff;cursor:pointer;transition:0.3s;}
  button:hover{background:var(--secondary);}
  .msg{margin-bottom:15px;color:green;}
  .list{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
  .card{background:var(--card-bg);padding:15px;border-radius:var(--radius);box-shadow:var(--shadow);}
  .card h3{margin-bottom:8px;}
  .card small{color:#666;}
  .card a{color:red;text-decoration:none;float:right;}
    .back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:var(--primary); color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

  @media(max-width:768px){.list{grid-template-columns:1fr;}.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;}}
</style>
</head>
<body>
<h1>휴일 관리</h1>
<?php if($msg) echo "<p class='msg'>$msg</p>"; ?>

<form method="POST">
  <input type="date" name="holiday_date" required>
  <input type="text" name="title" placeholder="휴일 제목 (예: 광복절)" required>
  <button type="submit" name="add">추가</button>
</form>

<form method="GET">
  <select name="year" onchange="this.form.submit()">
    <?php for($y=date("Y")-1;$y<=date("Y")+3;$y++): ?>
      <option value="<?=$y?>" <?=($y==$year?"selected":"")?>><?=$y?>년</option>
    <?php endfor; ?>
  </select>
</form>

<div class="list">
<?php while($row=$result->fetch_assoc()): ?>
  <div class="card">
    <h3><?=htmlspecialchars($row['title'])?></h3>
    <small><?=$row['holiday_date']?></small>
    <a href="?delete=<?=$row['id']?>" onclick="return confirm('삭제하시겠습니까?')">삭제</a>
  </div>
<?php endwhile; ?>
</div>
<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>
</body>
</html>