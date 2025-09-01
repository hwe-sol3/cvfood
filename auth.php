<?php
session_start();
include 'db_config.php';

$timeout = 1800; // 30분 (1800초)

// 1️⃣ 세션 없고 자동 로그인 쿠키 있으면 복구
if (!isset($_SESSION['user_id']) && isset($_COOKIE['auto_login'])) {
    $token = $_COOKIE['auto_login'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 토큰 유효 확인
        $stmt = $pdo->prepare("SELECT user_id FROM login_token WHERE token = :token AND expire_date > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stmt = $pdo->prepare("SELECT user_id, user_level FROM login_data WHERE user_id = :user_id");
            $stmt->bindParam(":user_id", $row['user_id']);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_level'] = $user['user_level'];

                // ✅ last_activity가 없을 경우에만 현재 시간 설정
                if (!isset($_SESSION['last_activity'])) {
                    $_SESSION['last_activity'] = time();
                }
            }
        }
    } catch (PDOException $e) {
        // DB 오류 시 무시
    }
}

// 2️⃣ 세션 없으면 로그인 페이지로
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 3️⃣ 세션 타임아웃 체크
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=timeout");
    exit;
}

// 4️⃣ 마지막 활동 시간 갱신 (요청이 있을 때마다)
$_SESSION['last_activity'] = time();