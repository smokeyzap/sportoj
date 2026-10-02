-- DEVIATION FROM docs/spec/schema.sql (baseline 1.0.0), see IMPLEMENTATION_NOTES.md section "Schema-afwijking":
-- fk_workout_sessions_assignment uses ON UPDATE RESTRICT instead of ON UPDATE CASCADE. MariaDB (10.6+) rejects
-- chk_workout_sessions_assignment_type (error 1901) when a CHECK references a column of a FOREIGN KEY with
-- ON UPDATE CASCADE. Internal BIGINT ids are never updated, so runtime behaviour is identical.
-- Trainingsapp database schema
-- Baseline: 1.0.0
-- Target: MariaDB 10.6+ using InnoDB and utf8mb4.
-- Run this on an empty database selected by the installer.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE app_schema_versions (
    version VARCHAR(20) NOT NULL,
    description VARCHAR(255) NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_meta (
    meta_key VARCHAR(64) NOT NULL,
    meta_value TEXT NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password VARCHAR(255) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    csrf_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_sessions_token_hash (token_hash),
    KEY idx_auth_sessions_user (user_id),
    KEY idx_auth_sessions_expires (expires_at),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    version VARCHAR(20) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_programs_public_id (public_id),
    UNIQUE KEY uq_programs_code_version (code, version),
    CONSTRAINT chk_programs_status CHECK (status IN ('draft','active','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    program_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    original_week_start SMALLINT UNSIGNED NOT NULL,
    original_week_end SMALLINT UNSIGNED NOT NULL,
    default_cycle_count SMALLINT UNSIGNED NOT NULL,
    description TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_blocks_public_id (public_id),
    UNIQUE KEY uq_training_blocks_program_sequence (program_id, sequence),
    KEY idx_training_blocks_program (program_id),
    CONSTRAINT fk_training_blocks_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_training_blocks_weeks CHECK (original_week_start >= 1 AND original_week_end >= original_week_start),
    CONSTRAINT chk_training_blocks_cycles CHECK (default_cycle_count >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(120) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(50) NOT NULL,
    category_label VARCHAR(80) NOT NULL,
    source_category_label VARCHAR(80) NOT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    video_url VARCHAR(500) NULL,
    protocol_type VARCHAR(32) NOT NULL DEFAULT 'standard',
    protocol_config JSON NOT NULL,
    source_text LONGTEXT NULL,
    content_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_workout_templates_public_id (public_id),
    UNIQUE KEY uq_workout_templates_code (code),
    KEY idx_workout_templates_category (category),
    CONSTRAINT chk_workout_templates_protocol CHECK (protocol_type IN ('standard','amrap','countdown','tabata','circuit')),
    CONSTRAINT chk_workout_templates_active CHECK (is_active IN (0,1)),
    CONSTRAINT chk_workout_templates_content_version CHECK (content_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_exercises (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workout_template_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    sets SMALLINT UNSIGNED NULL,
    reps SMALLINT UNSIGNED NULL,
    duration_seconds INT UNSIGNED NULL,
    notes TEXT NULL,
    source_line VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_workout_exercises_public_id (public_id),
    UNIQUE KEY uq_workout_exercises_template_sequence (workout_template_id, sequence),
    KEY idx_workout_exercises_template (workout_template_id),
    CONSTRAINT fk_workout_exercises_template FOREIGN KEY (workout_template_id) REFERENCES workout_templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_workout_exercises_sequence CHECK (sequence >= 1),
    CONSTRAINT chk_workout_exercises_sets CHECK (sets IS NULL OR sets >= 1),
    CONSTRAINT chk_workout_exercises_reps CHECK (reps IS NULL OR reps >= 1),
    CONSTRAINT chk_workout_exercises_duration CHECK (duration_seconds IS NULL OR duration_seconds >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE block_workouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    training_block_id BIGINT UNSIGNED NOT NULL,
    workout_template_id BIGINT UNSIGNED NOT NULL,
    sequence TINYINT UNSIGNED NOT NULL,
    day_label VARCHAR(20) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_block_workouts_public_id (public_id),
    UNIQUE KEY uq_block_workouts_block_sequence (training_block_id, sequence),
    KEY idx_block_workouts_block (training_block_id),
    KEY idx_block_workouts_template (workout_template_id),
    CONSTRAINT fk_block_workouts_block FOREIGN KEY (training_block_id) REFERENCES training_blocks (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_block_workouts_template FOREIGN KEY (workout_template_id) REFERENCES workout_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_block_workouts_sequence CHECK (sequence BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    program_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    start_mode VARCHAR(20) NOT NULL DEFAULT 'beginning',
    start_original_week SMALLINT UNSIGNED NULL,
    start_day_sequence TINYINT UNSIGNED NULL,
    continuation_mode VARCHAR(32) NOT NULL DEFAULT 'program_sequence',
    continuation_decision_required TINYINT(1) NOT NULL DEFAULT 0,
    continuation_anchor_assignment_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    continuation_source_assignment_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    paused_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_programs_public_id (public_id),
    KEY idx_user_programs_user_status (user_id, status),
    KEY idx_user_programs_program (program_id),
    KEY idx_user_programs_continuation_anchor (continuation_anchor_assignment_public_id),
    KEY idx_user_programs_continuation_source (continuation_source_assignment_public_id),
    CONSTRAINT fk_user_programs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_user_programs_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_user_programs_status CHECK (status IN ('active','paused','completed')),
    CONSTRAINT chk_user_programs_start_mode CHECK (start_mode IN ('beginning','position')),
    CONSTRAINT chk_user_programs_start_position CHECK (
        (start_mode = 'beginning' AND start_original_week IS NULL AND start_day_sequence IS NULL)
        OR
        (start_mode = 'position' AND start_original_week BETWEEN 1 AND 14 AND start_day_sequence BETWEEN 1 AND 6)
    ),
    CONSTRAINT chk_user_programs_continuation_mode CHECK (continuation_mode IN ('program_sequence','last_workout_sequence')),
    CONSTRAINT chk_user_programs_continuation_required CHECK (continuation_decision_required IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_program_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_program_id BIGINT UNSIGNED NOT NULL,
    training_block_id BIGINT UNSIGNED NOT NULL,
    target_cycles SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'not_started',
    started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_program_blocks_public_id (public_id),
    UNIQUE KEY uq_user_program_blocks_program_block (user_program_id, training_block_id),
    KEY idx_user_program_blocks_program_status (user_program_id, status),
    KEY idx_user_program_blocks_block (training_block_id),
    CONSTRAINT fk_user_program_blocks_program FOREIGN KEY (user_program_id) REFERENCES user_programs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_program_blocks_block FOREIGN KEY (training_block_id) REFERENCES training_blocks (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_user_program_blocks_cycles CHECK (target_cycles >= 1),
    CONSTRAINT chk_user_program_blocks_status CHECK (status IN ('prior_to_start','not_started','active','decision_required','completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_program_block_id BIGINT UNSIGNED NOT NULL,
    block_workout_id BIGINT UNSIGNED NOT NULL,
    cycle_number SMALLINT UNSIGNED NOT NULL,
    sequence TINYINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    is_extra_cycle TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    skipped_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_workout_assignments_public_id (public_id),
    UNIQUE KEY uq_workout_assignments_cycle_workout (user_program_block_id, cycle_number, block_workout_id),
    KEY idx_workout_assignments_progress (user_program_block_id, status, cycle_number, sequence),
    KEY idx_workout_assignments_block_workout (block_workout_id),
    CONSTRAINT fk_workout_assignments_program_block FOREIGN KEY (user_program_block_id) REFERENCES user_program_blocks (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_workout_assignments_block_workout FOREIGN KEY (block_workout_id) REFERENCES block_workouts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_workout_assignments_cycle CHECK (cycle_number >= 1),
    CONSTRAINT chk_workout_assignments_sequence CHECK (sequence BETWEEN 1 AND 6),
    CONSTRAINT chk_workout_assignments_status CHECK (status IN ('pending','started','completed','skipped','prior_to_start')),
    CONSTRAINT chk_workout_assignments_extra CHECK (is_extra_cycle IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    user_program_id BIGINT UNSIGNED NULL,
    workout_assignment_id BIGINT UNSIGNED NULL,
    workout_template_id BIGINT UNSIGNED NOT NULL,
    session_type VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'started',
    started_as_recommended TINYINT(1) NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_workout_sessions_public_id (public_id),
    KEY idx_workout_sessions_user_history (user_id, completed_at),
    KEY idx_workout_sessions_program (user_program_id),
    KEY idx_workout_sessions_assignment (workout_assignment_id),
    KEY idx_workout_sessions_template (workout_template_id),
    CONSTRAINT fk_workout_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workout_sessions_program FOREIGN KEY (user_program_id) REFERENCES user_programs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workout_sessions_assignment FOREIGN KEY (workout_assignment_id) REFERENCES workout_assignments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_workout_sessions_template FOREIGN KEY (workout_template_id) REFERENCES workout_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_workout_sessions_type CHECK (session_type IN ('program','extra')),
    CONSTRAINT chk_workout_sessions_status CHECK (status IN ('started','completed','cancelled')),
    CONSTRAINT chk_workout_sessions_recommended CHECK (started_as_recommended IS NULL OR started_as_recommended IN (0,1)),
    CONSTRAINT chk_workout_sessions_assignment_type CHECK (
        (session_type = 'program' AND workout_assignment_id IS NOT NULL)
        OR
        (session_type = 'extra' AND workout_assignment_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_schema_versions (version, description)
VALUES ('1.0.0', 'Initial production baseline for temporary PHP API and later Laravel cutover');
