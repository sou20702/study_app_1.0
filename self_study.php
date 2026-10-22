<?php
require_once 'config/db_config.php';

// 理解度レベルの設定（0:未着手 〜 3:マスター）
$level_labels = [
    0 => ['label' => '未着手',   'badge' => 'lvl-0'],
    1 => ['label' => '要復習★', 'badge' => 'lvl-1'],
    2 => ['label' => '理解★★', 'badge' => 'lvl-2'],
    3 => ['label' => 'マスター★★★', 'badge' => 'lvl-3']
];

session_start();

// ==========================================
// レベルUP処理（ボタンが押されたら level + 1 ）
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unit_id = $_POST['unit_id'] ?? null;
    $current_level = isset($_POST['current_level']) ? (int)$_POST['current_level'] : 0;

    if ($unit_id) {
        // レベルを1上げる（3の次は0に戻る）
        $next_level = ($current_level + 1) % 4;

        try {
            $pdo->beginTransaction();

            // 0. 対象ユニットの教科を取得（どのスキルレベルを上げるか判定するため）
            $stmt_subject = $pdo->prepare("SELECT subject FROM self_study_units WHERE id = :id");
            $stmt_subject->execute([':id' => $unit_id]);
            $unit_subject = $stmt_subject->fetchColumn();

            // 1. 理解度レベルの更新
            $stmt = $pdo->prepare("UPDATE self_study_units SET understanding_level = :level WHERE id = :id");
            $stmt->execute([':level' => $next_level, ':id' => $unit_id]);

            // 2. レベルアップ時のみ資材(materials)・プレイヤーレベルと、その教科のレベルを付与
            if ($next_level > $current_level) {
                $reward_materials = 50; // 付与する資材の量
                add_materials($pdo, $reward_materials); // 資材加算＋プレイヤーレベル保存

                // 教科ごとのレベル(subject_levels)を+1
                add_subject_level($pdo, (string)$unit_subject, 1);

                $subject_name = get_subjects()[$unit_subject] ?? '';
                $_SESSION['study_message'] = "🎉 {$subject_name}レベルUP！ 🧰 資材 +{$reward_materials} を獲得しました！";
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['study_message'] = '⚠️ 更新に失敗しました: ' . $e->getMessage();
        }
    }
    // 再読み込みによる二重送信（報酬の二重付与）を防ぐためリダイレクト
    header('Location: self_study.php');
    exit;
}

$message = $_SESSION['study_message'] ?? '';
unset($_SESSION['study_message']);
$subject_levels = get_subject_levels($pdo);

// データの取得と進捗率計算
$subjects = get_subjects(); // クエストタブの科目選択と共通

$units_by_subject = [];
$progress_data = [];

foreach ($subjects as $key => $name) {
    $stmt = $pdo->prepare("SELECT * FROM self_study_units WHERE subject = :subject ORDER BY id ASC");
    $stmt->execute([':subject' => $key]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $units_by_subject[$key] = $units;

    // 理解度の合計ポイントから進捗％を算出
    $total_units = count($units);
    $max_points = $total_units * 3;
    $current_points = 0;

    foreach ($units as $u) {
        $current_points += $u['understanding_level'];
    }

    $percent = $max_points > 0 ? round(($current_points / $max_points) * 100) : 0;

    $progress_data[$key] = [
        'name' => $name, 'points' => $current_points, 'max' => $max_points, 'percent' => $percent
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>段階的・自習進捗管理</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* header.php が body の margin/padding を !important で 0 にするため、
           幅制限は body ではなく専用ラッパーに付ける（拡大時もヘッダーと幅が揃う） */
        body { font-family: sans-serif; background: #f4f6f8; }
        .page-wrap { max-width: 900px; margin: 20px auto; padding: 0 15px; }
        .subject-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }
        .subject-lvl { font-size: 0.6em; background: #023047; color: #ffb703; padding: 2px 10px; border-radius: 12px; }
        .subject-block { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .progress-bar-bg { background: #e0e0e0; border-radius: 6px; height: 14px; width: 100%; overflow: hidden; margin: 10px 0; }
        .progress-bar-fill { background: linear-gradient(90deg, #4caf50, #81c784); height: 100%; transition: width 0.4s ease; }
        
        .unit-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 12px; margin-top: 15px; }
        .unit-card { background: #fafafa; border: 1px solid #e0e0e0; padding: 12px; border-radius: 8px; display: flex; flex-direction: column; justify-content: space-between; }
        
        .lvl-0 { border-left: 5px solid #ccc; }
        .lvl-1 { border-left: 5px solid #ffb74d; background: #fffde7; }
        .lvl-2 { border-left: 5px solid #64b5f6; background: #e3f2fd; }
        .lvl-3 { border-left: 5px solid #81c784; background: #e8f5e9; }

        .btn-lvl { border: none; padding: 6px 12px; border-radius: 20px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.85em; }
        .btn-lvl-0 { background: #e0e0e0; color: #666; }
        .btn-lvl-1 { background: #ffe082; color: #f57f17; }
        .btn-lvl-2 { background: #90caf9; color: #1565c0; }
        .btn-lvl-3 { background: #a5d6a7; color: #1b5e20; }
        .btn-lvl:hover { opacity: 0.8; transform: scale(1.03); }

        .reward-msg {
            background: #e8f5e9;
            color: #2e7d32;
            border: 2px solid #a5d6a7;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/header.php'; ?>

    <div class="page-wrap">

    <h1>📈 自習ステップアップ・進捗管理</h1>

    <?php if (!empty($message)): ?>
        <div class="reward-msg">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($subjects as $key => $subject_name): ?>
        <?php $p = $progress_data[$key]; ?>
        <div class="subject-block">
            <h2 class="subject-title">
                <?= htmlspecialchars($subject_name) ?> 
                <span class="subject-lvl"><?= htmlspecialchars($subject_name) ?>レベル Lv.<?= (int)$subject_levels[$key] ?></span>
                <span style="font-size: 0.6em; color: #666; font-weight: normal;">
                    (熟練度: <?= $p['points'] ?> / <?= $p['max'] ?> pt — <strong><?= $p['percent'] ?>%</strong>)
                </span>
            </h2>

            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width: <?= $p['percent'] ?>%;"></div>
            </div>

            <div class="unit-list">
                <?php foreach ($units_by_subject[$key] as $unit): ?>
                    <?php 
                        $lvl = $unit['understanding_level']; 
                        $lvl_info = $level_labels[$lvl];
                    ?>
                    <div class="unit-card <?= $lvl_info['badge'] ?>">
                        <div style="font-weight: bold; margin-bottom: 8px; font-size: 0.9em; color: #333;">
                            <?= htmlspecialchars($unit['unit_name']) ?>
                        </div>
                        
                        <form method="POST" style="margin:0; text-align: right;">
                            <input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
                            <input type="hidden" name="current_level" value="<?= $lvl ?>">
                            <button type="submit" class="btn-lvl btn-<?= $lvl_info['badge'] ?>">
                                <?= $lvl_info['label'] ?> (クリックでUP)
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    </div><!-- /.page-wrap -->

</body>
</html>