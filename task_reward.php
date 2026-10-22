<?php
require_once 'config/db_config.php';
session_start();
get_client_id(); // 端末IDのCookieを出力前に発行

$subjects       = get_subjects();            // key => 表示名（修練タブと共通）
$subject_labels = array_values($subjects);
$self           = 'task_reward.php';

$me = get_my_member($pdo);                   // 自分のグループ所属（なければ null）
$is_host = $me && (int)$me['host_member_id'] === (int)$me['id'];

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function go(string $view = 'personal'): void {
    header('Location: task_reward.php' . ($view === 'group' ? '?view=group' : ''));
    exit;
}

// ==========================================================
// POST処理（処理後はリダイレクトして二重送信を防ぐ）
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $post_view = (($_POST['view'] ?? '') === 'group' && $me) ? 'group' : 'personal';
    $next_view = $post_view;

    try {
        switch ($action) {
            // ---------- グループ ----------
            case 'create_group':
                [$ok, $msg] = create_group($pdo, (string)($_POST['user_name'] ?? ''), (string)($_POST['group_name'] ?? ''));
                flash($ok ? 'success' : 'error', $msg);
                $next_view = $ok ? 'group' : 'personal';
                break;

            case 'join_group':
                [$ok, $msg] = join_group($pdo, (string)($_POST['code'] ?? ''), (string)($_POST['user_name'] ?? ''));
                flash($ok ? 'success' : 'error', $msg);
                $next_view = $ok ? 'group' : 'personal';
                break;

            case 'kick_member':
                [$ok, $msg] = kick_member($pdo, $me, (int)($_POST['member_id'] ?? 0));
                flash($ok ? 'success' : 'error', $msg);
                $next_view = 'group';
                break;

            case 'leave_group':
                [$ok, $msg] = leave_group($pdo, $me);
                flash($ok ? 'success' : 'error', $msg);
                $next_view = $ok ? 'personal' : 'group';
                break;

            // ---------- 課題の追加 ----------
            case 'add_task':
                [$data, $err] = parse_task_input($_POST, $subject_labels);
                if ($err) {
                    flash('error', $err);
                    break;
                }
                $to_group = (($_POST['scope'] ?? '') === 'group') && $me;
                $stmt = $pdo->prepare("INSERT INTO tasks (title, subject, deadline, required_time, difficulty, is_completed, group_id, created_by)
                                       VALUES (:title, :subject, :deadline, :required_time, :difficulty, 0, :group_id, :created_by)");
                $stmt->execute([
                    ':title' => $data['title'], ':subject' => $data['subject'], ':deadline' => $data['deadline'],
                    ':required_time' => $data['required_time'], ':difficulty' => $data['difficulty'],
                    ':group_id'   => $to_group ? $me['group_id'] : null,
                    ':created_by' => $me ? $me['id'] : null,
                ]);
                flash('success', $to_group ? '【成功】グループに新しい課題を登録しました！' : '【成功】新しい課題を登録しました！');
                $next_view = $to_group ? 'group' : 'personal';
                break;

            // ---------- 課題の変更 ----------
            case 'update_task':
            case 'delete_task':
                $task_id = (int)($_POST['task_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :id");
                $stmt->execute([':id' => $task_id]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$task) {
                    flash('error', '対象の課題が見つかりません。');
                    break;
                }
                if (!can_modify_task($task, $me)) {
                    flash('error', 'この課題を変更・削除できるのは、登録した本人かグループホストだけです。');
                    break;
                }
                $next_view = $task['group_id'] === null ? 'personal' : 'group';

                if ($action === 'delete_task') {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM task_completions WHERE task_id = :id")->execute([':id' => $task_id]);
                    $pdo->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $task_id]);
                    $pdo->commit();
                    flash('success', '課題を削除しました。');
                } else {
                    // 既存課題が旧科目（例：理系）の場合は、その科目のままでも保存できる
                    $allowed = $subject_labels;
                    if (!in_array($task['subject'], $allowed, true)) {
                        $allowed[] = $task['subject'];
                    }
                    [$data, $err] = parse_task_input($_POST, $allowed);
                    if ($err) {
                        flash('error', $err);
                        break;
                    }
                    $stmt = $pdo->prepare("UPDATE tasks SET title = :title, subject = :subject, deadline = :deadline,
                                           required_time = :required_time, difficulty = :difficulty WHERE id = :id");
                    $stmt->execute([
                        ':title' => $data['title'], ':subject' => $data['subject'], ':deadline' => $data['deadline'],
                        ':required_time' => $data['required_time'], ':difficulty' => $data['difficulty'], ':id' => $task_id,
                    ]);
                    flash('success', '課題を更新しました。');
                }
                break;

            // ---------- 課題の完了（報酬付与） ----------
            case 'complete_task':
                $task_id = (int)($_POST['task_id'] ?? 0);
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :id");
                $stmt->execute([':id' => $task_id]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);
                $done = false;

                if ($task && $task['group_id'] === null) {
                    // 個人課題
                    $upd = $pdo->prepare("UPDATE tasks SET is_completed = 1 WHERE id = :id AND is_completed = 0");
                    $upd->execute([':id' => $task_id]);
                    $done = $upd->rowCount() === 1;
                    $next_view = 'personal';
                } elseif ($task && $me && (int)$task['group_id'] === (int)$me['group_id']) {
                    // グループ課題：自分の分だけ完了にする
                    $ins = $pdo->prepare("INSERT IGNORE INTO task_completions (task_id, member_id) VALUES (:t, :m)");
                    $ins->execute([':t' => $task_id, ':m' => $me['id']]);
                    $done = $ins->rowCount() === 1;
                    $next_view = 'group';
                }

                if ($done) {
                    $gain = (int)$task['difficulty'] * 20;
                    add_materials($pdo, $gain);
                    $key = array_search($task['subject'], $subjects, true);
                    if ($key !== false && add_subject_level($pdo, $key, 1)) {
                        flash('success', "【課題完了】{$task['subject']}Lvが1アップ！ 建築資材+{$gain} 獲得！");
                    } else {
                        flash('success', "【課題完了】建築資材+{$gain} 獲得！");
                    }
                }
                $pdo->commit();
                break;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'エラーが発生しました: ' . $e->getMessage());
    }
    go($next_view);
}

// ==========================================================
// 表示用データ
// ==========================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$view = (($_GET['view'] ?? '') === 'group' && $me) ? 'group' : 'personal';

$members = $me ? get_group_members($pdo, (int)$me['group_id']) : [];

$user_status = $pdo->query("SELECT materials FROM user_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

if ($view === 'group') {
    $stmt = $pdo->prepare("
        SELECT t.*, cm.name AS creator_name,
               (SELECT COUNT(*) FROM task_completions c WHERE c.task_id = t.id) AS done_count
        FROM tasks t
        LEFT JOIN group_members cm ON cm.id = t.created_by
        WHERE t.group_id = :gid
          AND NOT EXISTS (SELECT 1 FROM task_completions c2 WHERE c2.task_id = t.id AND c2.member_id = :mid)
    ");
    $stmt->execute([':gid' => $me['group_id'], ':mid' => $me['id']]);
} else {
    $stmt = $pdo->query("SELECT * FROM tasks WHERE is_completed = 0 AND group_id IS NULL");
}
$tasks = enrich_and_sort_tasks($stmt->fetchAll(PDO::FETCH_ASSOC));

// 編集対象（?edit=ID）。今表示している範囲の課題で、かつ権限がある場合のみ
$edit_task = null;
if (isset($_GET['edit'])) {
    foreach ($tasks as $t) {
        if ((int)$t['id'] === (int)$_GET['edit'] && can_modify_task($t, $me)) {
            $edit_task = $t;
            break;
        }
    }
}

// 円グラフ：表示中の課題を科目別に集計
$counts = [];
foreach ($tasks as $t) {
    $counts[$t['subject']] = ($counts[$t['subject']] ?? 0) + 1;
}
$chart_data = [];
foreach ($counts as $label => $n) {
    $chart_data[] = ['label' => $label, 'value' => $n];
}
$chart_json = json_encode($chart_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// フォーム用の値
$form_action = $edit_task ? 'update_task' : 'add_task';
$f = $edit_task ?: ['title' => '', 'subject' => $subject_labels[0], 'deadline' => '', 'required_time' => '', 'difficulty' => 1];
$form_subjects = $subject_labels;
if ($edit_task && !in_array($edit_task['subject'], $form_subjects, true)) {
    $form_subjects[] = $edit_task['subject']; // 旧科目（例：理系）
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>課題リスト</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .page-wrap { font-family: Arial, sans-serif; line-height: 1.6; }
        .panel { background: #fff; border: 2px solid #008080; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .panel h3, .panel h2 { margin-top: 0; }
        .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: bold; }
        .flash.success { background: #e8f5e9; color: #2e7d32; border: 2px solid #a5d6a7; }
        .flash.error { background: #fdecea; color: #c62828; border: 2px solid #ef9a9a; }
        .group-menu { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .group-menu form { background: #fafafa; border: 1px solid #ddd; border-radius: 8px; padding: 14px; }
        .group-menu h4 { margin: 0 0 8px; }
        .field { margin-bottom: 10px; }
        .field label { font-size: 0.9em; display: block; }
        .field input[type=text], .field input[type=date], .field input[type=number], .field select { width: 100%; padding: 6px; }
        .code-box { display: inline-flex; align-items: center; gap: 8px; background: #fff8e7; border: 2px dashed #ffb703; border-radius: 8px; padding: 6px 12px; }
        .code-box .code { font-size: 1.4rem; font-weight: bold; letter-spacing: 3px; color: #023047; }
        .member-list { list-style: none; padding: 0; margin: 12px 0 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
        .member-list li { display: flex; justify-content: space-between; align-items: center; gap: 8px; background: #eef7f6; border-radius: 8px; padding: 6px 10px; }
        .badge { font-size: 0.7em; padding: 1px 7px; border-radius: 10px; color: #fff; margin-left: 4px; vertical-align: middle; }
        .badge.host { background: #ffb703; color: #023047; }
        .badge.me { background: #219ebc; }
        .btn { border: none; border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 0.9em; color: #fff; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; } .btn-success { background: #28a745; }
        .btn-edit { background: #f0ad4e; } .btn-danger { background: #dc3545; } .btn-gray { background: #888; }
        .view-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
        .view-tabs a { padding: 7px 16px; border-radius: 20px; background: #e0e0e0; color: #333; text-decoration: none; font-weight: bold; font-size: 0.9em; }
        .view-tabs a.active { background: #219ebc; color: #fff; }
        .form-card { border: 1px solid #ccc; padding: 20px; max-width: 520px; margin-bottom: 30px; background: #fafafa; border-radius: 8px; }
        .table-scroll { overflow-x: auto; }
        table.tasks { width: 100%; border-collapse: collapse; text-align: left; background: #fff; }
        table.tasks th, table.tasks td { border: 1px solid #999; padding: 8px 10px; }
        table.tasks thead { background: #eee; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        .chart-wrap { width: 400px; max-width: 100%; margin: 20px 0; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/header.php'; ?>

    <div class="page-wrap" style="max-width: 1000px; margin: 20px auto; padding: 0 15px;">

    <h1>📚 課題リスト</h1>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h3>現在のステータス</h3>
        <p style="margin:0;">
            <strong>建築資材（materials）:</strong> <?= (int)($user_status['materials'] ?? 0) ?>
            <span style="color:#666; font-size:0.85em;">（レベルは「拠点」タブで確認できます）</span>
        </p>
    </div>

    <!-- ================= グループ ================= -->
    <div class="panel">
        <h3>👥 グループ共有</h3>
        <?php if (!$me): ?>
            <p style="margin-top:0;">グループを作成するか、グループコードで参加すると、課題をメンバーと共有できます。</p>
            <div class="group-menu">
                <form method="POST" action="<?= $self ?>">
                    <input type="hidden" name="action" value="create_group">
                    <h4>➕ グループを作成</h4>
                    <div class="field">
                        <label>あなたのユーザー名（ホストになります）</label>
                        <input type="text" name="user_name" maxlength="20" required placeholder="例：たろう">
                    </div>
                    <div class="field">
                        <label>グループ名（省略可）</label>
                        <input type="text" name="group_name" maxlength="30" placeholder="例：3年A組 受験勉強">
                    </div>
                    <button type="submit" class="btn btn-primary">作成してコードを発行</button>
                </form>

                <form method="POST" action="<?= $self ?>">
                    <input type="hidden" name="action" value="join_group">
                    <h4>🔑 グループに参加</h4>
                    <div class="field">
                        <label>グループコード（合言葉）</label>
                        <input type="text" name="code" maxlength="12" required placeholder="例：AB3XK7" style="text-transform: uppercase;">
                    </div>
                    <div class="field">
                        <label>あなたのユーザー名</label>
                        <input type="text" name="user_name" maxlength="20" required placeholder="例：はなこ">
                    </div>
                    <button type="submit" class="btn btn-success">参加する</button>
                </form>
            </div>
        <?php else: ?>
            <p style="margin-top:0;">
                <strong><?= h($me['group_name']) ?></strong>
                <span class="badge <?= $is_host ? 'host' : 'me' ?>"><?= $is_host ? 'ホスト' : 'メンバー' ?></span>
                　あなたの名前: <strong><?= h($me['name']) ?></strong>
            </p>
            <p>
                グループコード:
                <span class="code-box">
                    <span class="code" id="groupCode"><?= h($me['code']) ?></span>
                    <button type="button" class="btn btn-gray" onclick="copyGroupCode(this)">コピー</button>
                </span>
            </p>

            <strong>メンバー（<?= count($members) ?>人）</strong>
            <ul class="member-list">
                <?php foreach ($members as $m): ?>
                    <?php $m_is_host = (int)$m['id'] === (int)$me['host_member_id']; ?>
                    <li>
                        <span>
                            <?= h($m['name']) ?>
                            <?php if ($m_is_host): ?><span class="badge host">ホスト</span><?php endif; ?>
                            <?php if ((int)$m['id'] === (int)$me['id']): ?><span class="badge me">あなた</span><?php endif; ?>
                        </span>
                        <?php if ($is_host && !$m_is_host): ?>
                            <form method="POST" action="<?= $self ?>" style="margin:0;"
                                  onsubmit="return confirm('<?= h(addslashes($m['name'])) ?> さんをグループから削除しますか？');">
                                <input type="hidden" name="action" value="kick_member">
                                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                <input type="hidden" name="view" value="group">
                                <button type="submit" class="btn btn-danger">削除</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="POST" action="<?= $self ?>" style="margin-top:14px;"
                  onsubmit="return confirm('<?= $is_host ? 'グループを解散すると、グループの課題もすべて削除されます。よろしいですか？' : 'このグループから退出しますか？' ?>');">
                <input type="hidden" name="action" value="leave_group">
                <button type="submit" class="btn btn-gray"><?= $is_host ? 'グループを解散する' : 'グループから退出する' ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ================= 表示切替 ================= -->
    <?php if ($me): ?>
        <div class="view-tabs">
            <a href="<?= $self ?>" class="<?= $view === 'personal' ? 'active' : '' ?>">📝 個人の課題</a>
            <a href="<?= $self ?>?view=group" class="<?= $view === 'group' ? 'active' : '' ?>">👥 グループの課題（<?= h($me['group_name']) ?>）</a>
        </div>
    <?php endif; ?>

    <!-- ================= 登録・編集フォーム ================= -->
    <div class="form-card">
        <h2 style="margin-top:0;"><?= $edit_task ? '課題を編集' : '新規課題を登録' ?></h2>
        <form action="<?= $self ?>" method="POST">
            <input type="hidden" name="action" value="<?= $form_action ?>">
            <input type="hidden" name="view" value="<?= $view ?>">
            <?php if ($edit_task): ?>
                <input type="hidden" name="task_id" value="<?= (int)$edit_task['id'] ?>">
            <?php endif; ?>

            <div class="field">
                <label>課題名:</label>
                <input type="text" name="title" required maxlength="255" placeholder="例：数学プリント P.24" value="<?= h($f['title']) ?>">
            </div>
            <div class="field">
                <label>科目:</label>
                <select name="subject" required>
                    <?php foreach ($form_subjects as $label): ?>
                        <option value="<?= h($label) ?>" <?= $f['subject'] === $label ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>提出期限:</label>
                <input type="date" name="deadline" required value="<?= h($f['deadline']) ?>">
            </div>
            <div class="field">
                <label>所要時間 (分):</label>
                <input type="number" name="required_time" min="1" required placeholder="例：30" value="<?= h($f['required_time']) ?>">
            </div>
            <div class="field">
                <label>難易度:</label>
                <?php foreach ([1 => '簡単', 2 => '普通', 3 => '難しい'] as $d => $dl): ?>
                    <input type="radio" id="d<?= $d ?>" name="difficulty" value="<?= $d ?>" <?= (int)$f['difficulty'] === $d ? 'checked' : '' ?>>
                    <label for="d<?= $d ?>" style="display:inline;"><?= $d ?>: <?= $dl ?></label>
                <?php endforeach; ?>
            </div>
            <?php if ($me && !$edit_task): ?>
                <div class="field">
                    <label>登録先:</label>
                    <input type="radio" id="sc_p" name="scope" value="personal" <?= $view === 'personal' ? 'checked' : '' ?>>
                    <label for="sc_p" style="display:inline;">個人</label>
                    <input type="radio" id="sc_g" name="scope" value="group" <?= $view === 'group' ? 'checked' : '' ?>>
                    <label for="sc_g" style="display:inline;">グループ（<?= h($me['group_name']) ?>）</label>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="padding:10px 15px;"><?= $edit_task ? '変更を保存' : '課題を登録する' ?></button>
            <?php if ($edit_task): ?>
                <a href="<?= $self ?><?= $view === 'group' ? '?view=group' : '' ?>" class="btn btn-gray" style="padding:10px 15px;">キャンセル</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ================= 課題一覧 ================= -->
    <h2>📋 <?= $view === 'group' ? 'グループの課題一覧（あなたの未完了分）' : '課題一覧' ?></h2>
    <div class="table-scroll">
    <table class="tasks">
        <thead>
            <tr>
                <th>優先順位</th>
                <th>課題名</th>
                <th>科目</th>
                <th>提出期限</th>
                <th>所要時間</th>
                <th>難易度</th>
                <th>算出スコア</th>
                <?php if ($view === 'group'): ?><th>登録者</th><th>完了状況</th><?php endif; ?>
                <th>アクション</th>
            </tr>
        </thead>
        <tbody>
            <?php $colspan = $view === 'group' ? 10 : 8; ?>
            <?php if (empty($tasks)): ?>
                <tr><td colspan="<?= $colspan ?>" style="text-align:center; color:#888;">未完了の課題はありません</td></tr>
            <?php else: ?>
                <?php $rank = 1; ?>
                <?php foreach ($tasks as $task): ?>
                    <?php $can_mod = can_modify_task($task, $me); ?>
                    <tr>
                        <td><strong>No.<?= $rank++ ?></strong></td>
                        <td>
                            <?= h($task['title']) ?>
                            <?php if ($task['days_left'] <= 3 && $task['days_left'] >= 0): ?>
                                <span style="color:red; font-weight:bold; margin-left:10px;">⚠️【締切間近！】</span>
                            <?php elseif ($task['days_left'] < 0): ?>
                                <span style="color:purple; font-weight:bold; margin-left:10px;">🚨【期限超過！】</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($task['subject']) ?></td>
                        <td><?= h($task['deadline']) ?></td>
                        <td><?= h($task['required_time']) ?> 分</td>
                        <td>レベル <?= h($task['difficulty']) ?></td>
                        <td style="color:#666; font-size:0.9em;"><?= h($task['priority_score']) ?> pt</td>
                        <?php if ($view === 'group'): ?>
                            <td><?= $task['creator_name'] !== null ? h($task['creator_name']) : '（退会済み）' ?></td>
                            <td><?= (int)$task['done_count'] ?> / <?= count($members) ?> 人</td>
                        <?php endif; ?>
                        <td>
                            <div class="actions">
                                <form action="<?= $self ?>" method="POST">
                                    <input type="hidden" name="action" value="complete_task">
                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                    <input type="hidden" name="view" value="<?= $view ?>">
                                    <button type="submit" class="btn btn-success">完了！</button>
                                </form>
                                <?php if ($can_mod): ?>
                                    <a class="btn btn-edit" href="<?= $self ?>?<?= $view === 'group' ? 'view=group&' : '' ?>edit=<?= (int)$task['id'] ?>">編集</a>
                                    <form action="<?= $self ?>" method="POST" onsubmit="return confirm('この課題を削除しますか？');">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                        <input type="hidden" name="view" value="<?= $view ?>">
                                        <button type="submit" class="btn btn-danger">削除</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <!-- ================= 科目別グラフ ================= -->
    <?php if (!empty($chart_data)): ?>
        <h3>科目別の未完了課題</h3>
        <div class="chart-wrap"><canvas id="taskPieChart"></canvas></div>
    <?php endif; ?>

    </div><!-- /.page-wrap -->

    <script>
        function copyGroupCode(btn) {
            const code = document.getElementById('groupCode').textContent;
            const done = () => { btn.textContent = 'コピーしました'; setTimeout(() => btn.textContent = 'コピー', 1500); };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(done, () => window.prompt('コードをコピーしてください', code));
            } else {
                window.prompt('コードをコピーしてください', code);
            }
        }

        (function () {
            const raw = <?= $chart_json ?>;
            const canvas = document.getElementById('taskPieChart');
            if (!canvas || !raw.length) return;
            new Chart(canvas.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: raw.map(r => r.label),
                    datasets: [{
                        data: raw.map(r => r.value),
                        backgroundColor: ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc949', '#af7aa1', '#ff9da7']
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        })();
    </script>
</body>
</html>
