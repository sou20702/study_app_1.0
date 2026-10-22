<?php
// データベース接続設定の共通ルール
$host = 'localhost';
$dbname = 'study_app'; // 全員このDB名で作成する
$user = 'root';        // XAMPPのデフォルト
$pass = '';            // XAMPPのデフォルト

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    // エラーを表示するモードに設定（デバッグしやすくするため）
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ==========================================
// 総合ユーザーレベルの算出
// user_status.materials（総獲得資材量）から総合レベルを計算する共通ロジック。
// 資材100ごとにレベル+1（Lv.1スタート）。※しきい値は仮の値のため必要に応じて調整してください。
// ==========================================
if (!function_exists('calculate_user_level')) {
    function calculate_user_level(int $materials): int {
        $materials_per_level = 100;
        return 1 + intdiv(max(0, $materials), $materials_per_level);
    }
}

// 共通ライブラリ（スキーマ自動更新・グループ・教科別レベルなど）
require_once __DIR__ . '/app_lib.php';
ensure_schema($pdo);
?>