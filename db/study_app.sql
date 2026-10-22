-- データベースの作成
CREATE DATABASE IF NOT EXISTS study_app CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE study_app;

-- ※ 以下のテーブルは、アプリ起動時に config/app_lib.php の ensure_schema() が
--    自動で作成・移行します（このSQLを流さなくても動作します）。
--    手動で初期構築したい場合のリファレンスとして残しています。

-- 課題管理（group_id が NULL なら個人課題、値があればグループ課題）
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subject VARCHAR(50) NOT NULL,
    deadline DATE NOT NULL,
    required_time INT NOT NULL,
    difficulty INT NOT NULL,
    is_completed TINYINT(1) DEFAULT 0,
    group_id INT NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 資材・プレイヤーレベル（player_level は資材量から算出して保存）
CREATE TABLE IF NOT EXISTS user_status (
    id INT PRIMARY KEY,
    materials INT DEFAULT 0,
    player_level INT NOT NULL DEFAULT 1,
    materials_spent INT NOT NULL DEFAULT 0  -- 建設で消費した資材（レベルは所持＋消費の総獲得量で算出）
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO user_status (id, materials, player_level) VALUES (1, 0, 1);

-- 教科別レベル（旧 math_level / english_level / rikei_level を置き換え）
CREATE TABLE IF NOT EXISTS subject_levels (
    user_id INT NOT NULL,
    subject VARCHAR(30) NOT NULL,  -- math / english / physics / chemistry / information
    level INT NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- グループ
CREATE TABLE IF NOT EXISTS study_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(12) NOT NULL UNIQUE,   -- 自動生成のグループコード（合言葉）
    name VARCHAR(50) NOT NULL,
    host_member_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    token CHAR(32) NOT NULL,            -- 端末ID（Cookie）
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_name (group_id, name),
    UNIQUE KEY uq_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- グループ課題の完了状況（メンバーごと）
CREATE TABLE IF NOT EXISTS task_completions (
    task_id INT NOT NULL,
    member_id INT NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ボトルメール（sender_token = 送信者の端末ID）
CREATE TABLE IF NOT EXISTS bottle_mails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT,
    canvas_data LONGTEXT, -- お絵描きデータ保存用
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sender_token CHAR(32) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ボトルメールの受信履歴
CREATE TABLE IF NOT EXISTS bottle_mail_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mail_id INT NOT NULL,
    receiver_token CHAR(32) NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mail_receiver (mail_id, receiver_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 島の建物レベル（建設・強化の結果）
CREATE TABLE IF NOT EXISTS buildings (
    user_id INT NOT NULL,
    building VARCHAR(30) NOT NULL,  -- camp / tower / lab
    level INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, building)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 自習ユニット（初期データは ensure_schema() が自動投入）
CREATE TABLE IF NOT EXISTS self_study_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(50) NOT NULL,
    unit_name VARCHAR(100) NOT NULL,
    understanding_level TINYINT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
