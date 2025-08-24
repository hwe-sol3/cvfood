<?php
session_start();

$host = 'localhost';
$dbname = 'cvfood';
$user = 'cvfood';
$pass = 'Nums135790!!';

// 세션 없고 자동로그인 쿠키 있으면 복구 시도
if (!isset($_SESSION['user_id']) && isset($_COOKIE['auto_login'])) {
    $token = $_COOKIE['auto_login'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            }
        }
    } catch (PDOException $e) {
        // DB 오류 시 무시
    }
}
