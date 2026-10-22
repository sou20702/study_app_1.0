<?php

require_once "db.php";

$client_id = get_client_id();

// 自分が流したボトルは拾わない。まだ拾っていないボトルを優先してランダムに1通選ぶ
$sql = "
SELECT m.*
FROM bottle_mails m
WHERE m.sender_token IS NULL OR m.sender_token <> :me
ORDER BY
    EXISTS (SELECT 1 FROM bottle_mail_receipts r
            WHERE r.mail_id = m.id AND r.receiver_token = :me2) ASC,
    RAND()
LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([":me" => $client_id, ":me2" => $client_id]);

$mail = $stmt->fetch(PDO::FETCH_ASSOC);

// 受信履歴に記録（同じボトルは1回だけ）
if ($mail) {
    $log = $pdo->prepare("INSERT IGNORE INTO bottle_mail_receipts (mail_id, receiver_token) VALUES (:id, :t)");
    $log->execute([":id" => $mail["id"], ":t" => $client_id]);
}

// ヘッダー（ナビ）を db/ 配下から読み込むための設定
$nav_base = '../';
$nav_active = 'bottle_mail.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ボトル開封</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/bottle_mail.css">
</head>
<body>

    <?php require_once __DIR__ . '/../header.php'; ?>

    <div class="page-wrap">

        <h1>✉️ ボトルメール</h1>

        <div class="panel">
            <h3>📩 ボトルを開封しました</h3>

            <?php if ($mail): ?>

                <div class="history-item">
                    <div class="section-label" style="margin-top:0;">メッセージ</div>
                    <div class="history-msg">
                        <?= $mail["message"] === ""
                            ? '<span class="muted">（メッセージなし）</span>'
                            : nl2br(h($mail["message"])) ?>
                    </div>

                    <div class="section-label">お絵描き</div>
                    <?php if (!empty($mail["canvas_data"]) && strpos($mail["canvas_data"], "data:image/") === 0): ?>
                        <img class="history-img" alt="お絵描き" src="<?= h($mail["canvas_data"]) ?>">
                    <?php else: ?>
                        <p class="muted" style="margin:0;">絵はありません。</p>
                    <?php endif; ?>
                </div>

                <div class="section-label">リアクション</div>
                <div class="btn-row">
                    <button type="button" class="btn btn-primary btn-lg" onclick="alert('ナイス！を送りました！')">👍 ナイス！</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="alert('がんばれ！を送りました！')">🔥 がんばれ！</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="alert('いいね！を送りました！')">😊 いいね！</button>
                </div>

            <?php else: ?>

                <p class="history-empty" style="margin:0;">まだボトルが流れていません。</p>

            <?php endif; ?>
        </div>

        <div class="btn-row">
            <a href="../bottle_mail.php" class="btn btn-primary btn-lg">戻る</a>
            <a href="../bottle_mail.php#history" class="btn btn-gray btn-lg">送受信履歴を見る</a>
        </div>

    </div>

</body>
</html>
