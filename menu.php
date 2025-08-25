<?php
// upload.php
declare(strict_types=1);

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
    if (!in_array($_SESSION['user_level'], [1,5,6,7])) {
        die("접근 권한이 없습니다.");
    }
    $is_company_admin = false;
}

// --------- 설정 ---------
$uploadDir = __DIR__ . '/uploads';
$maxSize   = 5 * 1024 * 1024;
$allowedImageTypes = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$messages = [];
$latestFileUrl = null;
$latestFileTime = null;

// ---------- 업로드 처리 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        if ($file['size'] <= $maxSize) {
            $imgType = @exif_imagetype($file['tmp_name']);
            if ($imgType !== false && isset($allowedImageTypes[$imgType])) {
                $ext = $allowedImageTypes[$imgType];
                $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $publicUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/uploads/' . $safeName;
                    $messages[] = '업로드 성공!';
                } else $messages[] = '파일 저장 실패';
            } else $messages[] = '이미지 파일만 업로드 가능';
        } else $messages[] = '최대 5MB까지 허용';
    } else {
        if (isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE)
            $messages[] = '업로드 중 오류 발생';
    }
}

// ---------- 최신 업로드 파일 ----------
$files = glob($uploadDir . '/*');
if ($files) {
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latestFile = $files[0];
    $latestFileUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/uploads/' . basename($latestFile);
    $latestFileTime = date("Y-m-d H:i:s", filemtime($latestFile));
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>식단표</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Arial,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;background:#f9fafb}
    h2{color:#2563eb;text-align:center;margin-bottom:16px}
    .preview{text-align:center;margin-bottom:20px}
    .preview img{max-width:100%;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.2);cursor:pointer}
    .fileinfo{margin-top:8px;color:#555;font-size:.9rem;text-align:center}

    /* 업로드 카드 - 존재감 줄임 */
    .card{margin:20px auto;padding:12px 16px;border:1px solid #ddd;border-radius:10px;background:#fff;max-width:500px;font-size:.9rem}
    .card label{font-weight:600;font-size:.9rem}
    .card input[type=file]{margin:8px 0;font-size:.9rem}
    .card button{padding:8px 14px;font-size:.9rem;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer}
    .card button:hover{background:#1e40af}
    .hint{color:#666;font-size:.8rem}

    /* 돌아가기 버튼 */
    .btn-back{display:block;text-align:center;margin-top:24px;padding:12px;background:#4CAF50;color:#fff;text-decoration:none;border-radius:8px;font-size:1rem}
    .btn-back:hover{background:#388E3C}

    /* 모달 */
    .modal{display:none;position:fixed;z-index:999;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.8);justify-content:center;align-items:center}
    .modal img{max-width:90%;max-height:90%;border-radius:12px}
    .close{position:absolute;top:20px;right:30px;font-size:36px;color:#fff;cursor:pointer}
	
  .back-btn{
  position:fixed; bottom:30px; right:30px; 
  background:#2962FF; color:white; border:none; 
  width:60px; height:60px; border-radius:50%; font-size:1.5rem;
  cursor:pointer; box-shadow:0 4px 16px rgba(37,99,235,0.3); 
  transition:all 0.3s ease; z-index:100;
}
.back-btn:hover{transform:scale(1.1); box-shadow:0 6px 20px rgba(37,99,235,0.4);}

  @media (max-width:768px){ h1{font-size:1.3rem;} td,th{font-size:.9rem;}.back-btn{bottom:20px; right:20px; width:50px; height:50px; font-size:1.2rem;} }
  </style>
</head>
<body>

  <?php if ($latestFileUrl): ?>
    <div class="preview">
      <h2>📋 식단표</h2>
      <img src="<?= htmlspecialchars($latestFileUrl,ENT_QUOTES) ?>" alt="최근 업로드 이미지" id="menuImage">
      <div class="fileinfo">업로드 시각: <?= $latestFileTime ?></div>
    </div>
  <?php endif; ?>

  <?php if ($messages): ?>
    <ul class="messages">
      <?php foreach ($messages as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <label for="image">식단표 업로드</label><br>
      <input id="image" name="image" type="file" accept="image/*" required>
      <div class="hint">JPG/PNG/GIF/WEBP, 최대 5MB</div>
      <button type="submit">업로드</button>
    </form>
  </div>

<button class="back-btn" onclick="location.href='index.php'" title="처음으로 돌아가기">🏠</button>

</body>
</html>
