<?php
session_start();

// DB 연결 정보
include 'db_config.php';

if (isset($_COOKIE['auto_login'])) {
    $token = $_COOKIE['auto_login'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $stmt = $pdo->prepare("DELETE FROM login_token WHERE token = :token");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
    } catch (PDOException $e) {
        // 무시
    }

    setcookie("auto_login", "", time() - 3600, "/");
}

session_unset();
session_destroy();

// 아이디 기억은 유지 (save_id 쿠키는 그대로 둠)

header("Location: index.php");
exit;