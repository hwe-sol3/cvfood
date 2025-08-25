<?php
require_once __DIR__ . '/db_config.php';

date_default_timezone_set('Asia/Seoul');

// 백업 저장 경로
$backupDir = __DIR__ . '/backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $filePath = "$backupDir/{$table}.csv";
        $fp = fopen($filePath, 'w');

        $firstRow = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($firstRow) {
                fputcsv($fp, array_keys($row));
                $firstRow = false;
            }
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

    // ZIP 파일 고정 이름 (덮어쓰기)
    $zipFile = $backupDir . "/db_backup_latest.zip";

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($tables as $table) {
            $zip->addFile("$backupDir/{$table}.csv", "{$table}.csv");
        }
        $zip->close();
    }

    // CSV 원본 삭제
    foreach ($tables as $table) {
        @unlink("$backupDir/{$table}.csv");
    }

    echo "✅ DB 백업 완료 (덮어쓰기): " . basename($zipFile);

} catch (Exception $e) {
    exit("❌ DB 백업 실패: " . $e->getMessage());
}
