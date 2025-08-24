<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $user_pw = $_POST['user_pw'] ?? '';
    $remember_id = isset($_POST['remember_id']);
    $auto_login = isset($_POST['auto_login']);

    $host = 'localhost';
    $dbname = 'cvfood';
    $user = 'cvfood';
    $pass = 'Nums135790!!';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM login_data WHERE user_id = :user_id");
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $user_pw === $row['user_pw']) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_level'] = $row['user_level'];

            // 아이디 기억하기
            if ($remember_id) {
                setcookie("save_id", $user_id, time()+60*60*24*30, "/");
            } else {
                setcookie("save_id", "", time()-3600, "/");
            }

            // 자동 로그인
            if ($auto_login) {
                $token = bin2hex(random_bytes(16));
                $expire = date("Y-m-d H:i:s", time() + 60*60*24*30);

                $stmt = $pdo->prepare("INSERT INTO login_token (user_id, token, expire_date) 
                                        VALUES (:user_id, :token, :expire)
                                        ON DUPLICATE KEY UPDATE token = :token, expire_date = :expire");
                $stmt->execute([
                    ":user_id" => $user_id,
                    ":token" => $token,
                    ":expire" => $expire
                ]);

                setcookie("auto_login", $token, time()+60*60*24*30, "/");
            } else {
                setcookie("auto_login", "", time()-3600, "/");
            }

            header("Location: index.php");
            exit;
        } else {
            $error = "아이디 또는 비밀번호가 잘못되었습니다.";
        }
    } catch (PDOException $e) {
        $error = "DB 오류: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>로그인</title>
  <!-- ✅ 반응형 필수 -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <style>
    :root {
      --primary: #2563eb;
      --secondary: #1e40af;
      --bg: #f9fafb;
      --card-bg: #ffffff;
      --text: #111827;
      --radius: 12px;
      --shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      background: var(--bg);
      font-family: 'Segoe UI', 'Apple SD Gothic Neo', sans-serif;
      color: var(--text);
      padding: 20px;
    }

    form {
      background: var(--card-bg);
      padding: 30px;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 360px;
    }

    h2 {
      text-align: center;
      margin-bottom: 20px;
      color: var(--primary);
    }

    .form-group {
      margin-bottom: 15px;
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      margin-bottom: 6px;
      font-size: 0.9rem;
      font-weight: bold;
    }

    .form-group input {
      padding: 12px;
      font-size: 1rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      transition: border 0.2s, box-shadow 0.2s;
    }

    .form-group input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37,99,235,0.2);
      outline: none;
    }

    .options {
      display: flex;
      justify-content: space-between;
      margin-top: 10px;
      font-size: 0.85rem;
    }

    .options label {
      display: flex;
      align-items: center;
    }

    .options input {
      margin-right: 5px;
    }

    button {
      margin-top: 20px;
      padding: 12px;
      width: 100%;
      font-size: 1rem;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      transition: background 0.3s;
    }

    button:hover {
      background: var(--secondary);
    }

    .error {
      color: red;
      margin-bottom: 15px;
      font-size: 0.9rem;
      text-align: center;
    }

    /* ✅ 모바일 최적화 */
    @media (max-width: 500px) {
      form {
        padding: 20px;
        border-radius: 0;
        height: 100vh;
        max-width: 100%;
        box-shadow: none;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      h2 { font-size: 1.5rem; margin-bottom: 30px; }

      .form-group input {
        font-size: 1rem;
        padding: 14px;
      }

      button {
        padding: 14px;
        font-size: 1.1rem;
      }
    }
  </style>
</head>
<body>
  <form method="POST" action="">
    <h2>로그인</h2>
    <?php if (!empty($error)): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="form-group">
      <label for="user_id">아이디</label>
      <input type="text" id="user_id" name="user_id" 
        value="<?php if(isset($_COOKIE['save_id'])) echo htmlspecialchars($_COOKIE['save_id']); ?>" required>
    </div>

    <div class="form-group">
      <label for="user_pw">비밀번호</label>
      <input type="password" id="user_pw" name="user_pw" required>
    </div>

    <div class="options">
      <label><input type="checkbox" name="remember_id" <?php if(isset($_COOKIE['save_id'])) echo "checked"; ?>> 아이디 기억</label>
      <label><input type="checkbox" name="auto_login" <?php if(isset($_COOKIE['auto_login'])) echo "checked"; ?>> 자동 로그인</label>
    </div>

    <button type="submit">로그인</button>
  </form>
</body>
</html>
