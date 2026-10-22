<?php
// ==========================================
// 全ページ共通のヘッダー・ナビゲーション
// ==========================================
// db/ 配下のページから読み込む場合は、呼び出し側で $nav_base = '../' と
// $nav_active = 'bottle_mail.php' を指定する
$nav_base = $nav_base ?? '';
$current_page = $nav_active ?? basename($_SERVER['SCRIPT_NAME']);

function nav_link($href, $label, $icon, $current_page) {
    global $nav_base;
    $active_class = ($href === $current_page) ? ' active' : '';
    echo "<a href=\"{$nav_base}{$href}\" class=\"nav-btn{$active_class}\">";
    echo "<span class=\"icon\">{$icon}</span>{$label}";
    echo "</a>";
}
?>

<style>
/* 1. ページごとの余白ズレを防止するリセット設定 */
html, body {
    margin: 0 !important;
    padding: 0 !important;
}

/* 2. 明るい空と海のようなスカイブルーヘッダー */
.game-header {
    width: 100%;
    box-sizing: border-box; /* 端までぴったり広げる */
    background-color: #8ecae6; /* 明るく爽やかなスカイブルー */
    color: #023047;
    padding: 12px 24px;
    border-bottom: 5px solid #ffb703; /* 太陽・砂浜風の明るいイエロー */
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    font-family: sans-serif;
}

/* アプリタイトル */
.game-title {
    font-size: 1.15rem;
    font-weight: bold;
    color: #023047; /* 濃いネイビーでくっきり表示 */
    display: flex;
    align-items: center;
    gap: 6px;
}

.game-title .tag {
    font-size: 0.75rem;
    background: #06d6a0; /* 鮮やかなミントグリーン */
    color: #ffffff;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: bold;
}

/* メニューボタン */
.game-nav {
    display: flex;
    gap: 8px;
}

/* 通常ボタン（未選択） */
.nav-btn {
    color: #ffffff;
    text-decoration: none;
    background: #219ebc; /* ヘッダーより一段濃い爽やかなブルー */
    padding: 7px 16px;
    border-radius: 20px;
    font-size: 0.85rem;
    border: 2px solid #219ebc;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: bold;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

/* ホバーしたとき */
.nav-btn:hover {
    background: #38b000; /* 島っぽい爽やかなグリーンに変化 */
    border-color: #38b000;
    color: #ffffff;
    transform: translateY(-2px);
}

/* 選択中のページ（アクティブ） */
.nav-btn.active {
    background: #ffffff; /* パッと目立つ純白 */
    color: #023047;      /* 濃い文字で視認性を確保 */
    border-color: #ffffff;
    font-weight: bold;
    text-shadow: none;
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15);
}
</style>

<header class="game-header">
    <div class="game-title">
        🏝️ 勉強大好きアプリ <span class="tag">開拓中</span>
    </div>
    <nav class="game-nav">
        <?php nav_link('index.php', '拠点', '🏠', $current_page); ?>
        <?php nav_link('task_reward.php', 'クエスト', '⚔️', $current_page); ?>
        <?php nav_link('self_study.php', '修練', '📖', $current_page); ?>
        <?php nav_link('timer.php', 'タイマー', '⏱️', $current_page); ?>
        <?php nav_link('bottle_mail.php', '手紙', '✉️', $current_page); ?>
    </nav>
</header>