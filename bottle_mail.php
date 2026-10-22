<?php
require_once __DIR__ . '/db/db.php';
$client_id = get_client_id();

// 送信履歴（自分が流したボトル）と、何人が拾ったか
$stmt = $pdo->prepare("
    SELECT m.id, m.message, m.canvas_data, m.created_at,
           (SELECT COUNT(*) FROM bottle_mail_receipts r WHERE r.mail_id = m.id) AS pickup_count
    FROM bottle_mails m
    WHERE m.sender_token = :t
    ORDER BY m.id DESC
    LIMIT 50
");
$stmt->execute([':t' => $client_id]);
$sent_mails = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 受信履歴（自分が拾ったボトル）
$stmt = $pdo->prepare("
    SELECT m.message, m.canvas_data, r.received_at
    FROM bottle_mail_receipts r
    JOIN bottle_mails m ON m.id = r.mail_id
    WHERE r.receiver_token = :t
    ORDER BY r.id DESC
    LIMIT 50
");
$stmt->execute([':t' => $client_id]);
$received_mails = $stmt->fetchAll(PDO::FETCH_ASSOC);

function render_mail_item(array $m, string $date, string $meta = ''): void {
    echo '<div class="history-item">';
    echo '<div class="history-date">' . h($date) . ($meta !== '' ? ' ／ ' . h($meta) : '') . '</div>';
    echo '<div class="history-msg">' . ($m['message'] === '' || $m['message'] === null
        ? '<span class="muted">（メッセージなし）</span>'
        : nl2br(h($m['message']))) . '</div>';
    if (!empty($m['canvas_data']) && strpos($m['canvas_data'], 'data:image/') === 0) {
        echo '<img class="history-img" alt="お絵描き" src="' . h($m['canvas_data']) . '">';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ボトルメール</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/bottle_mail.css">
</head>

<body>

    <?php require_once __DIR__ . '/header.php'; ?>

    <div class="page-wrap">

        <h1>✉️ ボトルメール</h1>

        <!-- ================= 送信 ================= -->
        <div class="panel">
            <h3>🌊 ボトルを流す</h3>
            <p style="margin-top:0;">メッセージを書いて海へ流そう！</p>

            <form id="mailForm" action="db/save_mail.php" method="POST">
                <div class="field">
                    <label for="message">メッセージ</label>
                    <textarea id="message" name="message" rows="6" placeholder="ここにメッセージを書いてください"></textarea>
                </div>

                <div class="field canvas-wrap">
                    <label for="mailCanvas">お絵描き</label>
                    <canvas id="mailCanvas" width="400" height="250"></canvas>
                </div>

                <input type="hidden" name="drawing" id="drawing">

                <div class="btn-row">
                    <button type="button" id="clearCanvas" class="btn btn-gray btn-lg">消す</button>
                    <button type="submit" class="btn btn-primary btn-lg">🌊 海に流す</button>
                </div>
            </form>
        </div>

        <!-- ================= 受信 ================= -->
        <div class="panel">
            <h3>🏖 ボトルを拾う</h3>
            <form action="db/pickup_mail.php" method="GET">
                <button type="submit" class="btn btn-success btn-lg">📩 ボトルを拾う</button>
            </form>
        </div>

        <!-- ================= 履歴 ================= -->
        <div class="panel" id="history">
            <h3>📜 送受信履歴</h3>

            <div class="view-tabs">
                <button type="button" class="active" data-target="panel-sent">📤 送信（<?= count($sent_mails) ?>）</button>
                <button type="button" data-target="panel-received">📥 受信（<?= count($received_mails) ?>）</button>
            </div>

            <div id="panel-sent" class="history-panel active">
                <?php if (empty($sent_mails)): ?>
                    <p class="history-empty">まだボトルを流していません。</p>
                <?php else: ?>
                    <?php foreach ($sent_mails as $m): ?>
                        <?php render_mail_item($m, $m['created_at'], $m['pickup_count'] . '人が拾いました'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="panel-received" class="history-panel">
                <?php if (empty($received_mails)): ?>
                    <p class="history-empty">まだボトルを拾っていません。</p>
                <?php else: ?>
                    <?php foreach ($received_mails as $m): ?>
                        <?php render_mail_item($m, $m['received_at']); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.page-wrap -->

    <script>
        // 送信時にキャンバスの絵を hidden に入れる
        document.getElementById('mailForm').addEventListener('submit', function () {
            document.getElementById('drawing').value = document.getElementById('mailCanvas').toDataURL();
        });

        // 送信／受信タブ切り替え
        document.querySelectorAll('.view-tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.view-tabs button').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.history-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById(btn.dataset.target).classList.add('active');
            });
        });
    </script>

    <script src="js/draw.js"></script>

</body>

</html>
