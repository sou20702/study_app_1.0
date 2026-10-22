<?php
// ==========================================
// 共通ライブラリ
//  - 教科の定義（クエスト・修練・ホームで共通利用）
//  - DBスキーマの自動作成・移行（ensure_schema）
//  - プレイヤーレベル / 教科別レベル / 資材の更新
//  - 端末ID（Cookie）とグループ機能
// ==========================================

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 教科の定義：キー => 表示名。クエストの科目選択と修練タブの教科はここを共有する
function get_subjects(): array {
    return [
        'math'        => '数学',
        'english'     => '英語',
        'physics'     => '物理',
        'chemistry'   => '化学',
        'information' => '情報',
    ];
}

// ------------------------------------------
// スキーマ（初回・旧バージョンからの移行を自動で行う）
// ------------------------------------------
function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
    $stmt->execute([':c' => $column]);
    return $stmt->fetch() !== false;
}

function ensure_schema(PDO $pdo): void {
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // 課題
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(50) NOT NULL,
        deadline DATE NOT NULL,
        required_time INT NOT NULL,
        difficulty INT NOT NULL,
        is_completed TINYINT(1) DEFAULT 0
    ) $opt");
    if (!column_exists($pdo, 'tasks', 'group_id')) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN group_id INT NULL DEFAULT NULL");
    }
    if (!column_exists($pdo, 'tasks', 'created_by')) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN created_by INT NULL DEFAULT NULL");
    }

    // 資材・プレイヤーレベル
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_status (
        id INT PRIMARY KEY,
        materials INT DEFAULT 0,
        player_level INT NOT NULL DEFAULT 1
    ) $opt");
    if (!column_exists($pdo, 'user_status', 'player_level')) {
        $pdo->exec("ALTER TABLE user_status ADD COLUMN player_level INT NOT NULL DEFAULT 1");
    }
    if (!column_exists($pdo, 'user_status', 'materials_spent')) {
        $pdo->exec("ALTER TABLE user_status ADD COLUMN materials_spent INT NOT NULL DEFAULT 0");
    }
    $pdo->exec("INSERT IGNORE INTO user_status (id, materials, player_level) VALUES (1, 0, 1)");

    // 島の建物レベル（建設・強化の結果を保存）
    $pdo->exec("CREATE TABLE IF NOT EXISTS buildings (
        user_id INT NOT NULL,
        building VARCHAR(30) NOT NULL,
        level INT NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, building)
    ) $opt");

    // 自習ユニット（修練タブ）。空なら初期データを投入
    $pdo->exec("CREATE TABLE IF NOT EXISTS self_study_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(50) NOT NULL,
        unit_name VARCHAR(100) NOT NULL,
        understanding_level TINYINT DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opt");
    if ((int)$pdo->query("SELECT COUNT(*) FROM self_study_units")->fetchColumn() === 0) {
        seed_self_study_units($pdo);
    }

    // 教科別レベル
    $pdo->exec("CREATE TABLE IF NOT EXISTS subject_levels (
        user_id INT NOT NULL,
        subject VARCHAR(30) NOT NULL,
        level INT NOT NULL DEFAULT 1,
        PRIMARY KEY (user_id, subject)
    ) $opt");

    // 旧カラム（math_level / english_level / rikei_level）からの移行：数学・英語は値を引き継ぐ
    foreach (['math_level' => 'math', 'english_level' => 'english'] as $col => $key) {
        if (column_exists($pdo, 'user_status', $col)) {
            $pdo->exec("INSERT IGNORE INTO subject_levels (user_id, subject, level)
                        SELECT id, '{$key}', GREATEST(1, COALESCE({$col}, 1)) FROM user_status");
        }
    }
    foreach (['math_level', 'english_level', 'rikei_level'] as $col) {
        if (column_exists($pdo, 'user_status', $col)) {
            $pdo->exec("ALTER TABLE user_status DROP COLUMN `{$col}`");
        }
    }
    $seed = $pdo->prepare("INSERT IGNORE INTO subject_levels (user_id, subject, level) VALUES (1, :s, 1)");
    foreach (array_keys(get_subjects()) as $key) {
        $seed->execute([':s' => $key]);
    }

    // グループ
    $pdo->exec("CREATE TABLE IF NOT EXISTS study_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(12) NOT NULL UNIQUE,
        name VARCHAR(50) NOT NULL,
        host_member_id INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        token CHAR(32) NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_group_name (group_id, name),
        UNIQUE KEY uq_token (token)
    ) $opt");
    // グループ課題の完了状況はメンバーごとに管理
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_completions (
        task_id INT NOT NULL,
        member_id INT NOT NULL,
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (task_id, member_id)
    ) $opt");

    // ボトルメール（送受信履歴用）
    $pdo->exec("CREATE TABLE IF NOT EXISTS bottle_mails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT,
        canvas_data LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $opt");
    if (!column_exists($pdo, 'bottle_mails', 'sender_token')) {
        $pdo->exec("ALTER TABLE bottle_mails ADD COLUMN sender_token CHAR(32) NULL DEFAULT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS bottle_mail_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mail_id INT NOT NULL,
        receiver_token CHAR(32) NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mail_receiver (mail_id, receiver_token)
    ) $opt");
}

// ------------------------------------------
// プレイヤーレベル（user_status.player_level に保存）
// ------------------------------------------
function sync_player_level(PDO $pdo): array {
    $row = $pdo->query("SELECT materials, materials_spent FROM user_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $materials = (int)($row['materials'] ?? 0);
    $level = calculate_user_level($materials + (int)($row['materials_spent'] ?? 0)); // 総獲得資材量で算出
    $stmt = $pdo->prepare("UPDATE user_status SET player_level = :lvl WHERE id = 1");
    $stmt->execute([':lvl' => $level]);
    return ['materials' => $materials, 'level' => $level];
}

// 資材を加算し、プレイヤーレベルも保存し直す
function add_materials(PDO $pdo, int $amount): void {
    $stmt = $pdo->prepare("UPDATE user_status SET materials = materials + :n WHERE id = 1");
    $stmt->execute([':n' => $amount]);
    sync_player_level($pdo);
}

// ------------------------------------------
// 教科別レベル
// ------------------------------------------
function get_subject_levels(PDO $pdo): array {
    $levels = [];
    foreach (array_keys(get_subjects()) as $key) {
        $levels[$key] = 1;
    }
    $rows = $pdo->query("SELECT subject, level FROM subject_levels WHERE user_id = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($levels[$r['subject']])) {
            $levels[$r['subject']] = (int)$r['level'];
        }
    }
    return $levels;
}

function add_subject_level(PDO $pdo, string $subject_key, int $n = 1): bool {
    if (!array_key_exists($subject_key, get_subjects())) {
        return false;
    }
    $stmt = $pdo->prepare("INSERT INTO subject_levels (user_id, subject, level) VALUES (1, :s, 1 + :n)
                           ON DUPLICATE KEY UPDATE level = level + :n2");
    $stmt->execute([':s' => $subject_key, ':n' => $n, ':n2' => $n]);
    return true;
}

// ------------------------------------------
// 端末ID（Cookie）。グループのメンバー識別・ボトルメール履歴に使用
// ※ページ出力より前に呼ぶこと
// ------------------------------------------
function get_client_id(): string {
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    if (!empty($_COOKIE['study_client']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['study_client'])) {
        return $id = $_COOKIE['study_client'];
    }
    $id = bin2hex(random_bytes(16));
    setcookie('study_client', $id, [
        'expires'  => time() + 60 * 60 * 24 * 365 * 5,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['study_client'] = $id;
    return $id;
}

// ------------------------------------------
// グループ
// ------------------------------------------
function normalize_user_name(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function get_my_member(PDO $pdo): ?array {
    $stmt = $pdo->prepare("
        SELECT m.id, m.group_id, m.name, g.code, g.name AS group_name, g.host_member_id
        FROM group_members m
        JOIN study_groups g ON g.id = m.group_id
        WHERE m.token = :t
    ");
    $stmt->execute([':t' => get_client_id()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_group_members(PDO $pdo, int $group_id): array {
    $stmt = $pdo->prepare("SELECT id, name, joined_at FROM group_members WHERE group_id = :g ORDER BY id ASC");
    $stmt->execute([':g' => $group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generate_group_code(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 紛らわしい文字(I,O,0,1)は除外
    $check = $pdo->prepare("SELECT COUNT(*) FROM study_groups WHERE code = :c");
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < 6; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check->execute([':c' => $code]);
        if ((int)$check->fetchColumn() === 0) {
            return $code;
        }
    }
    throw new RuntimeException('グループコードの生成に失敗しました。もう一度お試しください。');
}

function validate_user_name(string $name): ?string {
    if ($name === '') {
        return 'ユーザー名を入力してください。';
    }
    if (mb_strlen($name) > 20) {
        return 'ユーザー名は20文字以内で入力してください。';
    }
    return null;
}

// 戻り値: [成功フラグ, メッセージ]
function create_group(PDO $pdo, string $user_name, string $group_name): array {
    $user_name = normalize_user_name($user_name);
    $group_name = normalize_user_name($group_name);
    if ($err = validate_user_name($user_name)) {
        return [false, $err];
    }
    if ($group_name === '') {
        $group_name = '勉強グループ';
    }
    if (mb_strlen($group_name) > 30) {
        return [false, 'グループ名は30文字以内で入力してください。'];
    }
    if (get_my_member($pdo)) {
        return [false, 'すでにグループに参加しています。'];
    }
    try {
        $pdo->beginTransaction();
        $code = generate_group_code($pdo);
        $pdo->prepare("INSERT INTO study_groups (code, name) VALUES (:c, :n)")
            ->execute([':c' => $code, ':n' => $group_name]);
        $group_id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO group_members (group_id, name, token) VALUES (:g, :n, :t)")
            ->execute([':g' => $group_id, ':n' => $user_name, ':t' => get_client_id()]);
        $member_id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE study_groups SET host_member_id = :m WHERE id = :g")
            ->execute([':m' => $member_id, ':g' => $group_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'グループの作成に失敗しました: ' . $e->getMessage()];
    }
    return [true, "グループを作成しました。グループコード「{$code}」をメンバーに共有してください。"];
}

function join_group(PDO $pdo, string $code, string $user_name): array {
    $code = strtoupper(trim($code));
    $user_name = normalize_user_name($user_name);
    if ($code === '') {
        return [false, 'グループコードを入力してください。'];
    }
    if ($err = validate_user_name($user_name)) {
        return [false, $err];
    }
    if (get_my_member($pdo)) {
        return [false, 'すでにグループに参加しています。'];
    }
    $stmt = $pdo->prepare("SELECT id, name FROM study_groups WHERE code = :c");
    $stmt->execute([':c' => $code]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        return [false, 'そのグループコードのグループは見つかりません。'];
    }
    $dup = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = :g AND name = :n");
    $dup->execute([':g' => $group['id'], ':n' => $user_name]);
    if ((int)$dup->fetchColumn() > 0) {
        return [false, 'そのユーザー名はこのグループですでに使われています。別の名前で参加してください。'];
    }
    try {
        $pdo->prepare("INSERT INTO group_members (group_id, name, token) VALUES (:g, :n, :t)")
            ->execute([':g' => $group['id'], ':n' => $user_name, ':t' => get_client_id()]);
    } catch (PDOException $e) {
        return [false, '参加に失敗しました。ユーザー名が重複している可能性があります。'];
    }
    return [true, "「{$group['name']}」に参加しました。"];
}

function remove_member_rows(PDO $pdo, int $member_id): void {
    $pdo->prepare("DELETE FROM task_completions WHERE member_id = :m")->execute([':m' => $member_id]);
    $pdo->prepare("DELETE FROM group_members WHERE id = :m")->execute([':m' => $member_id]);
}

// ホストによるメンバー削除
function kick_member(PDO $pdo, ?array $me, int $target_id): array {
    if (!$me || (int)$me['host_member_id'] !== (int)$me['id']) {
        return [false, 'メンバーを削除できるのはグループホストだけです。'];
    }
    if ($target_id === (int)$me['id']) {
        return [false, 'ホスト自身は削除できません。グループを解散してください。'];
    }
    $stmt = $pdo->prepare("SELECT name FROM group_members WHERE id = :id AND group_id = :g");
    $stmt->execute([':id' => $target_id, ':g' => $me['group_id']]);
    $name = $stmt->fetchColumn();
    if ($name === false) {
        return [false, '対象のメンバーが見つかりません。'];
    }
    try {
        $pdo->beginTransaction();
        remove_member_rows($pdo, $target_id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, '削除に失敗しました: ' . $e->getMessage()];
    }
    return [true, "{$name} さんをグループから削除しました。"];
}

// 退出（ホストの場合はグループ解散：グループ課題も削除）
function leave_group(PDO $pdo, ?array $me): array {
    if (!$me) {
        return [false, 'グループに参加していません。'];
    }
    try {
        $pdo->beginTransaction();
        if ((int)$me['host_member_id'] === (int)$me['id']) {
            $g = (int)$me['group_id'];
            $pdo->prepare("DELETE FROM task_completions WHERE task_id IN (SELECT id FROM tasks WHERE group_id = :g)")
                ->execute([':g' => $g]);
            $pdo->prepare("DELETE FROM tasks WHERE group_id = :g")->execute([':g' => $g]);
            $pdo->prepare("DELETE FROM group_members WHERE group_id = :g")->execute([':g' => $g]);
            $pdo->prepare("DELETE FROM study_groups WHERE id = :g")->execute([':g' => $g]);
            $msg = 'グループを解散しました。';
        } else {
            remove_member_rows($pdo, (int)$me['id']);
            $msg = 'グループから退出しました。';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, '処理に失敗しました: ' . $e->getMessage()];
    }
    return [true, $msg];
}

// ------------------------------------------
// 課題
// ------------------------------------------
// 優先度スコアを付与して降順に並べる
function enrich_and_sort_tasks(array $tasks): array {
    $today = new DateTime('today');
    foreach ($tasks as &$task) {
        $days_left = (int)$today->diff(new DateTime($task['deadline']))->format('%r%a');
        if ($days_left <= 0)      { $deadline_score = 100; }
        elseif ($days_left === 1) { $deadline_score = 50; }
        elseif ($days_left === 2) { $deadline_score = 30; }
        elseif ($days_left <= 3)  { $deadline_score = 20; }
        elseif ($days_left <= 7)  { $deadline_score = 10; }
        else                      { $deadline_score = 0; }

        $task['priority_score'] = $deadline_score
            + (int)$task['difficulty'] * 10
            + (int)$task['required_time'] * 0.1;
        $task['days_left'] = $days_left;
    }
    unset($task);
    usort($tasks, function ($a, $b) {
        return $b['priority_score'] <=> $a['priority_score'];
    });
    return $tasks;
}

// 課題フォーム入力の検証。戻り値: [データ|null, エラー|null]
function parse_task_input(array $in, array $allowed_subjects): array {
    $title = trim((string)($in['title'] ?? ''));
    $subject = (string)($in['subject'] ?? '');
    $deadline = (string)($in['deadline'] ?? '');
    $required_time = (int)($in['required_time'] ?? 0);
    $difficulty = (int)($in['difficulty'] ?? 0);

    if ($title === '' || mb_strlen($title) > 255) {
        return [null, '課題名は1〜255文字で入力してください。'];
    }
    if (!in_array($subject, $allowed_subjects, true)) {
        return [null, '科目が正しくありません。'];
    }
    $d = DateTime::createFromFormat('Y-m-d', $deadline);
    if (!$d || $d->format('Y-m-d') !== $deadline) {
        return [null, '提出期限が正しくありません。'];
    }
    if ($required_time < 1) {
        return [null, '所要時間は1分以上で入力してください。'];
    }
    if ($difficulty < 1 || $difficulty > 3) {
        return [null, '難易度が正しくありません。'];
    }
    return [[
        'title' => $title, 'subject' => $subject, 'deadline' => $deadline,
        'required_time' => $required_time, 'difficulty' => $difficulty,
    ], null];
}

// グループ課題の編集・削除は「登録者本人 or ホスト」。個人課題は誰でも可
function can_modify_task(array $task, ?array $me): bool {
    if ($task['group_id'] === null) {
        return true;
    }
    if (!$me || (int)$task['group_id'] !== (int)$me['group_id']) {
        return false;
    }
    return (int)$task['created_by'] === (int)$me['id']
        || (int)$me['host_member_id'] === (int)$me['id'];
}


// ------------------------------------------
// 自習ユニットの初期データ（create_table.php と同じ内容）
// ------------------------------------------
function get_default_units(): array {
    return [
        'math' => [
            "【数I】数と式（展開・因数分解）", "【数I】実数・一次不等式", "【数I】集合と命題", "【数I】2次関数とグラフ",
            "【数I】2次方程式・2次不等式", "【数I】三角比の基本（正弦・余弦定理）", "【数I】図形の計量（空間図形への応用）",
            "【数I】データの分析（分散・標準偏差・相関）", "【数A】場合の数（順列・組合せ）", "【数A】確率・条件付き確率",
            "【数A】図形の性質（チェバ・メネラウスの定理等）", "【数A】数学と人間活動（n進法・素数）", "【数II】式と証明・高次方程式",
            "【数II】図形と方程式（点と直線・円）", "【数II】三角関数", "【数II】指数関数・対数関数", "【数II】微分法と導関数",
            "【数II】積分法と面積", "【数B】数列（等差・等比・漸化式）", "【数B】統計的な推測", "【数C】ベクトル（平面・空間）", "【数III】極限・複素数平面"
        ],
        'english' => [
            "【文法】文型・品詞・時制", "【文法】助動詞・受動態", "【文法】不定詞・動名詞・分詞", "【文法】関係詞（関係代名詞・関係副詞）",
            "【文法】仮定法", "【文法】比較・否定・強調", "【単語】英単語・熟語の暗記（基礎1000語）", "【単語】英単語・熟語の暗記（応用2000語レベル）",
            "【読解】英文解釈（精読・構造把握）", "【読解】長文読解（パラグラフリーディング）", "【読解】長文読解（速読・スラッシュリーディング）",
            "【リスニング】音読・シャドーイング", "【リスニング】ディクテーション・共通テスト対策", "【英作文】和文英訳（基本文型活用）", "【英作文】自由英作文（論理的展開）"
        ],
        'physics' => [
            "【力学】運動の表し方・等加速度運動", "【力学】力のつり合い・運動方程式", "【力学】剛体のつり合い・重心", "【力学】仕事と運動エネルギー・保存力",
            "【力学】運動量保存の法則・衝突", "【力学】円運動・慣性力", "【力学】単振動・万有引力", "【熱力学】熱と温度・気体の分子運動論",
            "【熱力学】熱力学第一法則・状態変化", "【波動】波の性質・反射と屈折", "【波動】音波・ドップラー効果", "【波動】光の性質・干渉・屈折率",
            "【電磁気】静電気力・電場と電位", "【電磁気】コンデンサーの接続とエネルギー", "【電磁気】直流回路・キルヒホッフの法則", "【電磁気】電流と磁場・ローレンツ力",
            "【電磁気】電磁誘導・交流回路", "【原子】光量子仮説・原子の構造・放射性崩壊"
        ],
        'chemistry' => [
            "【理論】物質の構成・化学結合", "【理論】物質量（mol）と化学反応式", "【理論】物質の状態変化・気体の法則", "【理論】溶液の濃度と沸点上昇・浸透圧",
            "【理論】熱化学・ヘスの法則", "【理論】電池と電気分解", "【理論】化学反応の速度と化学平衡", "【理論】酸と塩基・中和滴定",
            "【理論】塩の加水分解・弱酸の遊離", "【理論】酸化還元反応・酸化数", "【無機】非金属元素とその化合物", "【無機】典型金属元素（アルカリ金属・2族等）",
            "【無機】遷移金属元素・錯イオン・金属イオン分離", "【有機】脂肪族炭化水素（アルカン・アルケン等）", "【有機】酸素を含む有機化合物（アルコール・カルボン酸）",
            "【有機】芳香族化合物（ベンゼン・フェノール類）", "【高分子】天然高分子（糖類・アミノ酸・タンパク質）", "【高分子】合成高分子（合成繊維・プラスチック）"
        ],
        'information' => [
            "【情報社会】情報社会の課題と情報モラル", "【情報社会】知的財産権と個人情報保護", "【情報デザイン】コミュニケーションとメディア",
            "【情報デザイン】情報デザインとユニバーサルデザイン", "【デジタル化】2進数・16進数と数値表現", "【デジタル化】文字・画像・音声のデジタル化",
            "【コンピュータ】ハードウェアとOSの役割", "【アルゴリズム】アルゴリズムとフローチャート", "【プログラミング】変数・制御構造（順次・分岐・反復）",
            "【プログラミング】配列と関数・関数の利用", "【プログラミング】探索アルゴリズム（線形・二分）", "【プログラミング】整列アルゴリズム（バブルソート等）",
            "【ネットワーク】LAN/WAN・プロトコル（TCP/IP）", "【ネットワーク】Web・メールの仕組みと情報セキュリティ", "【データ活用】データベース（SQL）とデータ分析・統計"
        ],
    ];
}

function seed_self_study_units(PDO $pdo): void {
    $stmt = $pdo->prepare("INSERT INTO self_study_units (subject, unit_name, understanding_level) VALUES (:s, :u, 0)");
    foreach (get_default_units() as $subject => $units) {
        foreach ($units as $unit_name) {
            $stmt->execute([':s' => $subject, ':u' => $unit_name]);
        }
    }
}

// ------------------------------------------
// 島の建物（建設・強化はサーバー側で資材を消費して保存）
// ------------------------------------------
function get_building_defs(): array {
    // キー => [表示名, 基本コスト, コスト倍率, 解放に必要なプレイヤーLv, 初期Lv]
    return [
        'camp'  => ['name' => '⛺ 拠点キャンプ', 'base' => 30,  'mult' => 1.5, 'unlock' => 1, 'initial' => 1],
        'tower' => ['name' => '🗼 見張り台',     'base' => 60,  'mult' => 1.6, 'unlock' => 1, 'initial' => 0],
        'lab'   => ['name' => '🔬 研究所',       'base' => 120, 'mult' => 1.8, 'unlock' => 3, 'initial' => 0],
    ];
}

function building_cost(array $def, int $level): int {
    return (int)floor($def['base'] * pow($def['mult'], $level));
}

function get_building_levels(PDO $pdo): array {
    $levels = [];
    foreach (get_building_defs() as $key => $def) {
        $levels[$key] = $def['initial'];
    }
    $rows = $pdo->query("SELECT building, level FROM buildings WHERE user_id = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($levels[$r['building']])) {
            $levels[$r['building']] = (int)$r['level'];
        }
    }
    return $levels;
}

// 戻り値: [成功フラグ, メッセージ]
function upgrade_building(PDO $pdo, string $key): array {
    $defs = get_building_defs();
    if (!isset($defs[$key])) {
        return [false, '建物が正しくありません。'];
    }
    $def = $defs[$key];
    try {
        $pdo->beginTransaction();
        $row = $pdo->query("SELECT materials, materials_spent FROM user_status WHERE id = 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        $materials = (int)$row['materials'];
        $total_earned = $materials + (int)$row['materials_spent'];
        if (calculate_user_level($total_earned) < $def['unlock']) {
            $pdo->rollBack();
            return [false, "プレイヤーLv.{$def['unlock']}で解放されます。"];
        }
        $levels = get_building_levels($pdo);
        $cost = building_cost($def, $levels[$key]);
        if ($materials < $cost) {
            $pdo->rollBack();
            return [false, '資材が足りません！課題や自習で集めましょう。'];
        }
        $pdo->prepare("UPDATE user_status SET materials = materials - :c, materials_spent = materials_spent + :c2 WHERE id = 1")
            ->execute([':c' => $cost, ':c2' => $cost]);
        $pdo->prepare("INSERT INTO buildings (user_id, building, level) VALUES (1, :b, :l)
                       ON DUPLICATE KEY UPDATE level = :l2")
            ->execute([':b' => $key, ':l' => $levels[$key] + 1, ':l2' => $levels[$key] + 1]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, '建設に失敗しました: ' . $e->getMessage()];
    }
    return [true, '建設・強化が完了しました！'];
}
