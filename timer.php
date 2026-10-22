<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>勉強タイマー</title>
<link rel="stylesheet" href="css/timer.css">
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="timer-wrap">
  <div class="card" id="app" data-mode="study">
    <div class="tabs" role="tablist">
      <button class="tab on" data-t="timer" role="tab">タイマー</button>
      <button class="tab" data-t="rec" role="tab">記録</button>
    </div>

    <!-- ===== タイマー ===== -->
    <section id="timer">
      <div class="presets">
        <button class="chip" data-s="25" data-b="5">25 / 5</button>
        <button class="chip" data-s="50" data-b="10">50 / 10</button>
        <button class="chip" data-s="90" data-b="15">90 / 15</button>
      </div>

      <div class="ring">
        <svg viewBox="0 0 200 200" aria-hidden="true">
          <circle class="ring-bg" cx="100" cy="100" r="90"/>
          <circle class="ring-fg" id="ring" cx="100" cy="100" r="90"/>
        </svg>
        <div class="ring-center">
          <div id="phase">📖 勉強中</div>
          <div id="display">25:00</div>
          <div class="today-line">今日 <b id="today">0</b> 分</div>
        </div>
      </div>

      <div class="btns">
        <button class="btn" id="startBtn">スタート</button>
        <button class="btn" id="resetBtn">リセット</button>
      </div>
      <p id="msg"></p>

      <details class="custom">
        <summary>時間・通知の設定</summary>
        <div class="row">
          勉強 <input type="number" id="sMin" min="1" max="180"> 分
          休憩 <input type="number" id="bMin" min="1" max="60"> 分
        </div>
        <div class="row"><label><input type="checkbox" id="auto"> 終了後、自動で次を開始</label></div>
        <div class="row"><button class="chip" id="notifBtn">🔔 通知を許可</button><span id="notifState"></span></div>
      </details>
      <div class="small" id="lockState"></div>
    </section>

    <!-- ===== 記録 ===== -->
    <section id="rec" class="hide">
      <div class="stats">
        <div><b id="sToday">0</b>今日(分)</div>
        <div><b id="sWeek">0</b>7日間(分)</div>
        <div><b id="sMonth">0</b>今月(分)</div>
        <div><b id="sStreak">0</b>連続(日)</div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>勉強時間の推移</h2>
          <div class="seg">
            <button class="on" data-r="7">7日</button>
            <button data-r="30">30日</button>
          </div>
        </div>
        <svg id="chart" viewBox="0 0 320 170" width="100%" role="img" aria-label="日ごとの勉強時間"></svg>
        <div class="legend"><span class="dot"></span>勉強時間 <span class="dash"></span>平均 <span id="avg"></span></div>
      </div>

      <div class="panel">
        <div class="nav"><button id="prev" aria-label="前の月">◀</button><span id="ym"></span><button id="next" aria-label="次の月">▶</button></div>
        <div class="cal" id="cal"></div>
      </div>
    </section>
  </div>
</main>

<script src="js/timer.js"></script>
</body>
</html>
