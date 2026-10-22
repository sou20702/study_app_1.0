<?php

require_once "db.php";

$client_id = get_client_id(); // 送信者（端末）を記録して送信履歴に使う

$message = $_POST["message"] ?? "";
$drawing = $_POST["drawing"] ?? "";

$sql = "INSERT INTO bottle_mails
(message, canvas_data, sender_token)
VALUES
(:message, :drawing, :sender)";

$stmt = $pdo->prepare($sql);

$stmt->bindValue(":message", $message);
$stmt->bindValue(":drawing", $drawing);
$stmt->bindValue(":sender", $client_id);

$stmt->execute();

// ヘッダー（ナビ）を db/ 配下から読み込むための設定
$nav_base = '../';
$nav_active = 'bottle_mail.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ボトルメール送信完了</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/bottle_mail.css">
</head>
<body>

    <?php require_once __DIR__ . '/../header.php'; ?>

    <div class="page-wrap">

        <h1>✉️ ボトルメール</h1>

        <div class="flash success">🌊 ボトルメールを海へ流しました！</div>

        <div class="btn-row">
            <a href="../bottle_mail.php" class="btn btn-primary btn-lg">戻る</a>
            <a href="../bottle_mail.php#history" class="btn btn-gray btn-lg">送受信履歴を見る</a>
        </div>

    </div>

</body>
</html>
