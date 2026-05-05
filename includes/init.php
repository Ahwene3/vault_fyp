<?php
/**
 * Bootstrap - session, config, error handling
 */
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mail.php';

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('UTC');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// Helper to get base URL
function base_url(string $path = ''): string {
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    return $base . ($path ? '/' . ltrim($path, '/') : '');
}

function redirect(string $url, int $code = 302): void {
    header('Location: ' . $url, true, $code);
    exit;
}

function csrf_field(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function flash(string $key, $value = null) {
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }
    $v = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $v;
}

function e(?string $s): string {
    return $s === null ? '' : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function sql_placeholders(int $count): string {
    if ($count <= 0) {
        return '';
    }
    return implode(',', array_fill(0, $count, '?'));
}

function ensure_departments_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS departments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function ensure_user_archive_columns(PDO $pdo): void {
    $required_columns = [
        'archived_permanent' => 'ALTER TABLE users ADD COLUMN archived_permanent TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
        'archived_at' => 'ALTER TABLE users ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER archived_permanent',
        'archived_by' => 'ALTER TABLE users ADD COLUMN archived_by INT UNSIGNED NULL DEFAULT NULL AFTER archived_at',
    ];

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "users"
           AND COLUMN_NAME IN (' . sql_placeholders(count($required_columns)) . ')'
    );
    $stmt->execute(array_keys($required_columns));

    $existing_columns = array_map(
        'strtolower',
        array_column($stmt->fetchAll(), 'COLUMN_NAME')
    );

    foreach ($required_columns as $column_name => $ddl) {
        if (in_array(strtolower($column_name), $existing_columns, true)) {
            continue;
        }

        try {
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }
}

/**
 * Build flexible department variants so legacy text values (e.g. "IT")
 * still match canonical department rows (e.g. "ICT Department").
 */
function department_name_variants(?string $value): array {
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        return [];
    }

    $variants = [$raw];

    $collapsed = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $variants[] = trim($collapsed);

    $base = preg_replace('/\b(department|dept)\b/', '', $collapsed) ?? $collapsed;
    $base = trim((string) preg_replace('/\s+/', ' ', $base));
    if ($base !== '') {
        $variants[] = $base;
    }

    $alpha_num = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $base !== '' ? $base : $collapsed));
    if ($alpha_num !== '') {
        $variants[] = $alpha_num;
        $words = array_values(array_filter(explode(' ', $alpha_num), static function ($w) {
            return $w !== '';
        }));
        if (count($words) >= 2) {
            $acronym = '';
            foreach ($words as $w) {
                if (in_array($w, ['and', 'of', 'the'], true)) {
                    continue;
                }
                $acronym .= substr($w, 0, 1);
            }
            if ($acronym !== '') {
                $variants[] = $acronym;
            }
        }
    }

    // Common aliases for historical/short-form department labels in this system.
    $alias_map = [
        'ict' => ['it', 'information technology', 'information technology department'],
        'information technology' => ['it', 'ict', 'ict department'],
        'information technology department' => ['it', 'ict', 'ict department'],
        'it' => ['ict', 'information technology', 'ict department'],
    ];

    foreach ($alias_map as $needle => $aliases) {
        if (strpos($raw, $needle) !== false || $base === $needle || $alpha_num === $needle) {
            foreach ($aliases as $alias) {
                $variants[] = strtolower(trim($alias));
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('trim', $variants), static function ($v) {
        return $v !== '';
    })));
}

/**
 * Normalize a department reference that may be either department id or department name.
 * Returns canonical id/name when available plus variants for resilient matching.
 */
function resolve_department_info(PDO $pdo, ?string $department): array {
    ensure_departments_table($pdo);

    $raw = trim((string) $department);
    if ($raw === '') {
        return [
            'raw' => '',
            'id' => null,
            'name' => null,
            'variants' => [],
        ];
    }

    $variants = department_name_variants($raw);
    $id = null;
    $name = null;

    if (ctype_digit($raw)) {
        $id = (int) $raw;
        $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn() ?: null;
        if ($name) {
            $variants = array_merge($variants, department_name_variants((string) $name));
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, name FROM departments WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1');
        $stmt->execute([$raw]);
        $row = $stmt->fetch();
        if ($row) {
            $id = (int) $row['id'];
            $name = $row['name'];
            $variants[] = strtolower((string) $id);
            $variants = array_merge($variants, department_name_variants((string) $name));
        }
    }

    $variants = array_values(array_unique(array_filter(array_map('trim', $variants), static function ($v) {
        return $v !== '';
    })));

    return [
        'raw' => $raw,
        'id' => $id,
        'name' => $name,
        'variants' => $variants,
    ];
}

function get_department_display_name(PDO $pdo, ?string $department): string {
    $info = resolve_department_info($pdo, $department);
    if (!empty($info['name'])) {
        return (string) $info['name'];
    }
    return trim((string) $department);
}

function ensure_supervisor_logsheets_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS supervisor_logsheets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        supervisor_id INT UNSIGNED NOT NULL,
        meeting_date DATE NOT NULL,
        student_attendees TEXT NULL,
        topics_discussed TEXT NOT NULL,
        action_points TEXT NULL,
        next_meeting_date DATE NULL,
        supervisor_notes TEXT NULL,
        confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_project (project_id),
        INDEX idx_supervisor (supervisor_id),
        CONSTRAINT fk_sl_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        CONSTRAINT fk_sl_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function ensure_pending_completion_status(PDO $pdo): void {
    $stmt = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'status'");
    $col_type = (string) $stmt->fetchColumn();
    if (strpos($col_type, 'pending_completion') !== false) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE projects MODIFY COLUMN status ENUM('draft','submitted','approved','rejected','in_progress','completed','pending_completion','archived') DEFAULT 'draft'");
    } catch (Throwable $ex) {
        if (stripos($ex->getMessage(), 'duplicate') === false) {
            throw $ex;
        }
    }
}

function ensure_student_tracking_columns(PDO $pdo): void {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME IN (?, ?)');
    $stmt->execute(['student_project_status', 'repeat_required']);
    $existing = array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));

    if (!in_array('student_project_status', $existing, true)) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN student_project_status ENUM('active','completed','failed') NOT NULL DEFAULT 'active'");
        } catch (Throwable $ex) {
            if (stripos($ex->getMessage(), 'duplicate column name') === false) {
                throw $ex;
            }
        }
    }
    if (!in_array('repeat_required', $existing, true)) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN repeat_required TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $ex) {
            if (stripos($ex->getMessage(), 'duplicate column name') === false) {
                throw $ex;
            }
        }
    }
}

function ensure_project_contribution_status_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS project_contribution_status (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        contribution_status ENUM("contributed","partial","not_contributed") NOT NULL DEFAULT "partial",
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_project_student (project_id, student_id),
        INDEX idx_project (project_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * Prepare a repeating student to join a new group:
 * - Remove them from any archived group memberships
 * - Delete their stale contribution_status records
 * - Reset student_project_status → "active" and repeat_required → 0
 *
 * Must be called inside an open transaction.
 */
function reset_repeating_student(PDO $pdo, int $student_id): void {
    // Find all archived group memberships for this student
    $stmt = $pdo->prepare('
        SELECT gm.group_id
        FROM `group_members` gm
        JOIN `groups` g ON g.id = gm.group_id
        LEFT JOIN projects p ON p.group_id = g.id
        WHERE gm.student_id = ?
          AND (p.status = "archived" OR p.id IS NULL)
    ');
    $stmt->execute([$student_id]);
    $archived_group_ids = array_column($stmt->fetchAll(), 'group_id');

    if (!empty($archived_group_ids)) {
        // Remove stale group memberships
        $ph = implode(',', array_fill(0, count($archived_group_ids), '?'));
        $pdo->prepare("DELETE FROM `group_members` WHERE student_id = ? AND group_id IN ($ph)")
            ->execute(array_merge([$student_id], $archived_group_ids));

        // Remove stale contribution records for those archived projects
        $proj_stmt = $pdo->prepare("SELECT id FROM projects WHERE group_id IN ($ph) AND status = 'archived'");
        $proj_stmt->execute($archived_group_ids);
        $archived_project_ids = array_column($proj_stmt->fetchAll(), 'id');

        if (!empty($archived_project_ids)) {
            $pph = implode(',', array_fill(0, count($archived_project_ids), '?'));
            $pdo->prepare("DELETE FROM project_contribution_status WHERE student_id = ? AND project_id IN ($pph)")
                ->execute(array_merge([$student_id], $archived_project_ids));
        }
    }

    // Reset the student's tracking columns for the new cycle
    $pdo->prepare('UPDATE users SET student_project_status = "active", repeat_required = 0 WHERE id = ? AND repeat_required = 1')
        ->execute([$student_id]);
}

function has_other_active_hod_in_department(PDO $pdo, string $department, int $excludeUserId = 0): bool {
    $info = resolve_department_info($pdo, $department);
    if (empty($info['variants'])) {
        return false;
    }

    $placeholders = sql_placeholders(count($info['variants']));
    $sql = 'SELECT id FROM users WHERE role = "hod" AND is_active = 1 AND LOWER(TRIM(COALESCE(department, ""))) IN (' . $placeholders . ')';
    $params = $info['variants'];
    if ($excludeUserId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}
