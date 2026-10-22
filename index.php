<?php
require_once 'config/db_config.php';
session_start();

// 建設・強化（資材はサーバー側で消費し、建物レベルをDBに保存）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade') {
    [$ok, $msg] = upgrade_building($pdo, (string)($_POST['building'] ?? ''));
    $_SESSION['index_flash'] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
    header('Location: index.php#island');
    exit;
}
$flash = $_SESSION['index_flash'] ?? null;
unset($_SESSION['index_flash']);

// プレイヤーレベル：総獲得資材量から算出し、user_status.player_level に保存して同期する
$sync = sync_player_level($pdo);
$materials = $sync['materials'];
$user_lvl  = $sync['level'];

// 教科別レベル（修練タブ・クエストタブで上がる）
$subjects       = get_subjects();
$subject_levels = get_subject_levels($pdo);

// 島の建物
$building_defs   = get_building_defs();
$building_levels = get_building_levels($pdo);

// 未完了の課題数（個人課題 ＋ 自分が所属するグループの未完了課題）
$task_count = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE is_completed = 0 AND group_id IS NULL")->fetchColumn();
$my_member = get_my_member($pdo);
if ($my_member) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE t.group_id = :g
        AND NOT EXISTS (SELECT 1 FROM task_completions c WHERE c.task_id = t.id AND c.member_id = :m)");
    $stmt->execute([':g' => $my_member['group_id'], ':m' => $my_member['id']]);
    $task_count += (int)$stmt->fetchColumn();
}

// 教科別 学習進捗（修練タブの理解度ポイントを教科ごとに集計）
$chart_labels = [];
$chart_points = [];
$stmt = $pdo->prepare("SELECT COALESCE(SUM(understanding_level), 0) FROM self_study_units WHERE subject = :s");
foreach ($subjects as $key => $name) {
    $stmt->execute([':s' => $key]);
    $chart_labels[] = $name;
    $chart_points[] = (int)$stmt->fetchColumn();
}
$chart_has_data = array_sum($chart_points) > 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>勉強大好きアプリ（仮）- 島開拓ダッシュボード</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f0f7f9; font-family: sans-serif; color: #2b2d42; margin: 0; padding: 0; }
        main { max-width: 1000px; margin: 20px auto; padding: 0 15px; display: flex; flex-direction: column; gap: 20px; }
        
        .card { background: #ffffff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2ece9; }
        .card-title { margin-top: 0; margin-bottom: 15px; font-size: 1.1rem; color: #023047; display: flex; align-items: center; gap: 8px; }

        .dashboard-top { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .dashboard-top { grid-template-columns: 1fr; } }

        .status-box { display: flex; gap: 15px; margin-bottom: 15px; }
        .status-item { flex: 1; background: #eef7f6; border-radius: 12px; padding: 12px; text-align: center; }
        .status-item .num { font-size: 1.6rem; font-weight: bold; color: #219ebc; }
        .status-item .label { font-size: 0.8rem; color: #666; }

        /* 資材表示バー */
        .resource-bar {
            background: #023047;
            color: #fff;
            padding: 15px 20px;
            border-radius: 12px;
            text-align: center;
            font-weight: bold;
            font-size: 1.1rem;
        }
        .resource-val { color: #ffb703; font-size: 1.4rem; margin-left: 8px; }

        .subject-lv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; }
        .subject-lv-item { background: #eef7f6; border-radius: 12px; padding: 12px; text-align: center; }
        .subject-lv-item .label { font-size: 0.85rem; color: #666; }
        .subject-lv-item .num { font-size: 1.5rem; font-weight: bold; color: #219ebc; }

        .island-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 15px; }
        .build-card { background: #fafafa; border: 2px solid #e0e0e0; border-radius: 12px; padding: 15px; }
        .build-card.unlocked { background: #f0fdf4; border-color: #86efac; }
        .build-card.locked { background: #f5f5f5; opacity: 0.7; }

        .build-name { font-weight: bold; font-size: 1rem; margin-bottom: 5px; }
        .build-lvl { font-size: 0.85rem; color: #2563eb; font-weight: bold; }
        .cost-list { font-size: 0.85rem; color: #555; margin: 10px 0; background: rgba(255,255,255,0.8); padding: 8px; border-radius: 6px; }

        .btn { width: 100%; padding: 8px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .btn-build { background: #219ebc; color: white; }
        .btn-build:hover { background: #1a829d; }
        .flash { padding: 12px 16px; border-radius: 8px; font-weight: bold; }
        .flash.success { background: #e8f5e9; color: #2e7d32; border: 2px solid #a5d6a7; }
        .flash.error { background: #fdecea; color: #c62828; border: 2px solid #ef9a9a; }
        .empty-note { text-align: center; color: #888; font-size: 0.85rem; margin: 8px 0 0; }
        .lock-msg { font-size: 0.75rem; color: #ef476f; font-weight: bold; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/header.php'; ?>

    <main>
        <?php if ($flash): ?>
            <div class="flash <?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['msg']); ?></div>
        <?php endif; ?>

        <div class="dashboard-top">
            <div class="card">
                <h3 class="card-title">📊 教科別 学習進捗</h3>
                <div style="max-width: 250px; margin: 0 auto;">
                    <canvas id="progressChart"></canvas>
                </div>
                <?php if (!$chart_has_data): ?>
                    <p class="empty-note">「修練」タブで理解度を上げると、ここに進捗が表示されます。</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="card-title">📝 クエスト＆スキル状況</h3>
                <div class="status-box">
                    <div class="status-item">
                        <div class="label">未完了の課題</div>
                        <div class="num"><?php echo $task_count; ?><span style="font-size:0.9rem"> 件</span></div>
                    </div>
                    <div class="status-item">
                        <div class="label">プレイヤーLv</div>
                        <div class="num"><?php echo $user_lvl; ?></div>
                    </div>
                </div>
                <div style="background: #fff8e7; padding: 12px; border-radius: 8px; border-left: 4px solid #ffb703; font-size: 0.85rem;">
                    💡 <b>ヒント:</b> 自習や課題をクリアすると「資材」を獲得できます！
                </div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">🎓 教科別レベル</h3>
            <div class="subject-lv-grid">
                <?php foreach ($subjects as $key => $name): ?>
                    <div class="subject-lv-item">
                        <div class="label"><?php echo htmlspecialchars($name); ?>レベル</div>
                        <div class="num">Lv.<?php echo (int)$subject_levels[$key]; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" id="island">
            <h3 class="card-title">🏝️ 島開拓シミュレーション</h3>

            <div class="resource-bar">
                🧰 所持資材: <span id="res-materials" class="resource-val"><?php echo (int)$materials; ?></span>
            </div>

            <div class="island-grid">
                <?php foreach ($building_defs as $key => $def): ?>
                    <?php
                        $lvl = $building_levels[$key];
                        $cost = building_cost($def, $lvl);
                        $locked = $user_lvl < $def['unlock'];
                    ?>
                    <div class="build-card <?php echo $locked ? 'locked' : 'unlocked'; ?>">
                        <div class="build-name"><?php echo htmlspecialchars($def['name']); ?></div>
                        <div class="build-lvl">Lv. <?php echo (int)$lvl; ?></div>
                        <div class="cost-list">
                            <?php if ($locked): ?>
                                <span class="lock-msg">🔒 解放条件: プレイヤーLv. <?php echo (int)$def['unlock']; ?></span><br>
                            <?php endif; ?>
                            次Lvに必要な資材: 🧰 <?php echo $cost; ?>
                        </div>
                        <form method="POST" action="index.php">
                            <input type="hidden" name="action" value="upgrade">
                            <input type="hidden" name="building" value="<?php echo htmlspecialchars($key); ?>">
                            <button type="submit" class="btn btn-build" <?php echo ($locked || $materials < $cost) ? 'disabled' : ''; ?>>建設・強化</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
        // 教科別 学習進捗の円グラフ（修練タブの理解度ポイント）
        (function () {
            const labels = <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const points = <?php echo json_encode($chart_points); ?>;
            const hasData = <?php echo $chart_has_data ? 'true' : 'false'; ?>;
            if (typeof Chart === 'undefined') return;
            new Chart(document.getElementById('progressChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: hasData ? points : labels.map(() => 1),
                        backgroundColor: hasData
                            ? ['#219ebc', '#ffb703', '#06d6a0', '#ef476f', '#8c97ff']
                            : labels.map(() => '#e0e0e0')
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' }, tooltip: { enabled: hasData } } }
            });
        })();
    </script>
</body>
</html>