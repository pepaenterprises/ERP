<?php
// pages/students.php
// ─────────────────────────────────────────────────────────────────────────────
// FACULTY / COLLEGE-ADMIN / SUPER-ADMIN STUDENT PAGE
// All queries are scoped to the logged-in user's role:
//   faculty       → only departments/courses assigned via faculty_assignments
//   college_admin → all departments in their college
//   super_admin   → all departments across all colleges
//
// ADD STUDENT:
//   faculty       → can add students only to their assigned departments & courses
//   college_admin → can add students to any dept in their college
//   super_admin   → can add students to any dept / course across all colleges
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']               ?? 'faculty';
$userId    = (int)($user['id']           ?? 0);
$collegeId = (int)($user['college_id']   ?? 0);
$deptId    = (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']          ?? 'Faculty';
$firstName = explode(' ', trim($fullName))[0];

// Role gate
if (!in_array($role, ['faculty', 'college_admin', 'super_admin'])) {
    header('Location: dashboard.php');
    exit;
}

// ── Defaults ──────────────────────────────────────────────────────────────────
$collegeName = 'Your College';
$collegeCode = '';
$deptName    = 'Your Department';
$deptCode    = '';
$hodName     = '';
$totalDepts  = 0;

$totalStudents    = 0;
$activeStudents   = 0;
$pendingStudents  = 0;
$inactiveStudents = 0;

$myCourses     = 0;
$pendingMarks  = 0;
$upcomingExams = 0;

$students    = [];
$departments = [];

// For Add Student modal — available depts & courses scoped to user's access
$availableDepts   = [];
$availableCourses = [];

$db = getDB();

// ── URL filter params ─────────────────────────────────────────────────────────
$filterDept   = isset($_GET['dept'])   ? (int)$_GET['dept']   : $deptId;
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search       = isset($_GET['q'])      ? trim($_GET['q'])      : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

$isFaculty      = ($role === 'faculty');
$isSuperAdmin   = ($role === 'super_admin');
$isCollegeAdmin = ($role === 'college_admin');

// Faculty is locked to their own department — cannot override via URL
if ($isFaculty) {
    $filterDept = $deptId;
}

// ── Handle Add Student POST ───────────────────────────────────────────────────
$addError   = '';
$addSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_student' && $db) {

    $newFullName   = trim($_POST['full_name']   ?? '');
    $newEmail      = trim($_POST['email']        ?? '');
    $newUsername   = trim($_POST['username']     ?? '');
    $newPhone      = trim($_POST['phone']        ?? '');
    $newRoll       = trim($_POST['roll_number']  ?? '');
    $newPassword   = trim($_POST['password']     ?? '');
    $newDeptId     = (int)($_POST['dept_id']     ?? 0);
    $newCourseId   = (int)($_POST['course_id']   ?? 0);
    $newCollegeId  = $isSuperAdmin ? (int)($_POST['college_id'] ?? 0) : $collegeId;

    // Basic validation
    if (!$newFullName || !$newEmail || !$newUsername || !$newDeptId || !$newCollegeId) {
        $addError = 'Full name, email, username, college and department are required.';

    // Scope check: faculty can only add to their assigned departments
    } elseif ($isFaculty) {
        $stCheck = $db->prepare(
            'SELECT COUNT(*) FROM faculty_assignments
             WHERE faculty_id = ? AND department_id = ? AND college_id = ? AND status = "active"'
        );
        $stCheck->execute([$userId, $newDeptId, $collegeId]);
        if (!(int)$stCheck->fetchColumn()) {
            $addError = 'You can only add students to your assigned departments.';
        }
    }

    if (!$addError) {
        // Check duplicate email / username
        $stDup = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
        $stDup->execute([$newEmail, $newUsername]);
        if ((int)$stDup->fetchColumn()) {
            $addError = 'A user with that email or username already exists.';
        }
    }

    if (!$addError) {
        $hash = $newPassword ? password_hash($newPassword, PASSWORD_BCRYPT) : password_hash('Password@123', PASSWORD_BCRYPT);
        $stIns = $db->prepare(
            'INSERT INTO users
             (college_id, department_id, username, email, password, full_name, roll_number, phone, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "student", "active", NOW(), NOW())'
        );
        $stIns->execute([
            $newCollegeId, $newDeptId, $newUsername, $newEmail,
            $hash, $newFullName, $newRoll ?: null, $newPhone ?: null
        ]);
        $newStudentId = (int)$db->lastInsertId();

        // Enroll into course if selected
        if ($newStudentId && $newCourseId) {
            $stEnr = $db->prepare(
                'INSERT IGNORE INTO enrollments (student_id, course_id, status, enrolled_at)
                 VALUES (?, ?, "enrolled", NOW())'
            );
            $stEnr->execute([$newStudentId, $newCourseId]);
        }

        $addSuccess = "Student \"{$newFullName}\" added successfully!";
    }
}

// ── DB Migration: ensure year/section/semester columns exist in users ────────
if ($db) {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `study_year`        TINYINT(1)  DEFAULT NULL COMMENT '1=1st Year, 2=2nd Year, etc.'");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `section`            VARCHAR(10) DEFAULT NULL COMMENT 'e.g. A, B, C'");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `current_semester`   TINYINT(2)  DEFAULT NULL COMMENT 'Current semester e.g. 1–12'");
    } catch (Exception $e) { /* columns may already exist */ }
}

// ── Handle Allot Student POST (AJAX) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'allot_student' && $db) {
    header('Content-Type: application/json');

    $studentId      = (int)($_POST['student_id']      ?? 0);
    $studyYear      = (int)($_POST['study_year']      ?? 0);  // 1–6
    $section        = strtoupper(trim($_POST['section']        ?? ''));  // A–Z or custom
    $currentSemester= (int)($_POST['current_semester'] ?? 0);  // 1–12

    if (!$studentId) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid student.']); exit;
    }
    if ($studyYear < 1 || $studyYear > 6) {
        echo json_encode(['ok'=>false,'msg'=>'Please select a valid year (1–6).']); exit;
    }
    if ($section === '') {
        echo json_encode(['ok'=>false,'msg'=>'Please select a section.']); exit;
    }
    if ($currentSemester < 1 || $currentSemester > 12) {
        echo json_encode(['ok'=>false,'msg'=>'Please select a valid semester (1–12).']); exit;
    }

    // Verify student belongs to this faculty's college (+ dept for faculty)
    $scopeWhere = 'u.id = ? AND u.role = "student"';
    $scopeVals  = [$studentId];
    if (!$isSuperAdmin) {
        $scopeWhere .= ' AND u.college_id = ?';
        $scopeVals[]  = $collegeId;
    }
    if ($isFaculty) {
        $scopeWhere .= ' AND u.department_id IN (SELECT DISTINCT department_id FROM faculty_assignments WHERE faculty_id = ? AND college_id = ? AND status = "active")';
        $scopeVals[]  = $userId;
        $scopeVals[]  = $collegeId;
    }

    $stCheck = $db->prepare("SELECT id, full_name FROM users u WHERE $scopeWhere LIMIT 1");
    $stCheck->execute($scopeVals);
    $stuRow = $stCheck->fetch(PDO::FETCH_ASSOC);

    if (!$stuRow) {
        echo json_encode(['ok'=>false,'msg'=>'Student not found or access denied.']); exit;
    }

    try {
        $stUpd = $db->prepare('UPDATE users SET study_year = ?, section = ?, current_semester = ?, updated_at = NOW() WHERE id = ?');
        $stUpd->execute([$studyYear, $section, $currentSemester, $studentId]);
        echo json_encode([
            'ok'               => true,
            'msg'              => 'Allotment saved for <strong>' . htmlspecialchars($stuRow['full_name']) . '</strong>.',
            'study_year'       => $studyYear,
            'section'          => $section,
            'current_semester' => $currentSemester,
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

if ($db) {

    /* ── College info ─────────────────────────────────────────────────────── */
    if ($collegeId) {
        $st = $db->prepare('SELECT name, code FROM colleges WHERE id = ? AND status = "active" LIMIT 1');
        $st->execute([$collegeId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $collegeName = $row['name'];
            $collegeCode = $row['code'];
        }
    }

    /* ── Department info ─────────────────────────────────────────────────── */
    if ($deptId && $collegeId) {
        $st = $db->prepare('SELECT name, code, hod_name FROM departments WHERE id = ? AND college_id = ? LIMIT 1');
        $st->execute([$deptId, $collegeId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $deptName = $row['name'];
            $deptCode = $row['code'];
            $hodName  = $row['hod_name'] ?? '';
        }
    }

    /* ── Departments for list view ───────────────────────────────────────── */
    if ($isFaculty && $userId) {
        $st = $db->prepare(
            'SELECT DISTINCT d.id, d.name, d.code
             FROM departments d
             INNER JOIN faculty_assignments fa ON fa.department_id = d.id
             WHERE fa.faculty_id = ? AND fa.college_id = ? AND fa.status = "active" AND d.status = "active"
             ORDER BY d.name'
        );
        $st->execute([$userId, $collegeId]);
    } elseif ($isSuperAdmin) {
        $st = $db->prepare(
            'SELECT d.id, d.name, d.code, c.name AS college_name, c.id AS c_id
             FROM departments d
             JOIN colleges c ON c.id = d.college_id
             WHERE d.status = "active"
             ORDER BY c.name, d.name'
        );
        $st->execute();
    } else {
        $st = $db->prepare('SELECT id, name, code FROM departments WHERE college_id = ? AND status = "active" ORDER BY name');
        $st->execute([$collegeId]);
    }
    $departments = $st->fetchAll(PDO::FETCH_ASSOC);
    $totalDepts  = count($departments);

    /* ── Available departments for Add Student modal ─────────────────────── */
    if ($isFaculty && $userId) {
        // Faculty: only departments/courses they're assigned to
        $st = $db->prepare(
            'SELECT DISTINCT d.id, d.name, d.code, d.college_id,
                    c.name AS college_name, c.id AS college_id_val
             FROM departments d
             JOIN colleges c ON c.id = d.college_id
             INNER JOIN faculty_assignments fa ON fa.department_id = d.id
             WHERE fa.faculty_id = ? AND fa.college_id = ? AND fa.status = "active" AND d.status = "active"
             ORDER BY d.name'
        );
        $st->execute([$userId, $collegeId]);
        $availableDepts = $st->fetchAll(PDO::FETCH_ASSOC);

        // Courses assigned to this faculty
        $st = $db->prepare(
            'SELECT DISTINCT co.id, co.name, co.code, co.department_id, co.college_id, co.semester
             FROM courses co
             JOIN faculty_assignments fa ON fa.course_id = co.id
             WHERE fa.faculty_id = ? AND fa.college_id = ? AND fa.status = "active" AND co.status = "active"
             ORDER BY co.name'
        );
        $st->execute([$userId, $collegeId]);
        $availableCourses = $st->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($isSuperAdmin) {
        // Super admin: all depts across all colleges
        $st = $db->prepare(
            'SELECT d.id, d.name, d.code, d.college_id,
                    c.name AS college_name, c.id AS college_id_val
             FROM departments d
             JOIN colleges c ON c.id = d.college_id
             WHERE d.status = "active"
             ORDER BY c.name, d.name'
        );
        $st->execute();
        $availableDepts = $st->fetchAll(PDO::FETCH_ASSOC);

        // All courses
        $st = $db->prepare(
            'SELECT co.id, co.name, co.code, co.department_id, co.college_id, co.semester
             FROM courses co
             WHERE co.status = "active"
             ORDER BY co.name'
        );
        $st->execute();
        $availableCourses = $st->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // College admin: all depts in their college
        $st = $db->prepare(
            'SELECT d.id, d.name, d.code, d.college_id,
                    c.name AS college_name, c.id AS college_id_val
             FROM departments d
             JOIN colleges c ON c.id = d.college_id
             WHERE d.college_id = ? AND d.status = "active"
             ORDER BY d.name'
        );
        $st->execute([$collegeId]);
        $availableDepts = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $db->prepare(
            'SELECT co.id, co.name, co.code, co.department_id, co.college_id, co.semester
             FROM courses co
             WHERE co.college_id = ? AND co.status = "active"
             ORDER BY co.name'
        );
        $st->execute([$collegeId]);
        $availableCourses = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── Build scoped WHERE clause ───────────────────────────────────────── */
    $whereParts = ['u.role = "student"'];
    $bindParams = [];

    if ($isSuperAdmin) {
        if ($filterDept) {
            $whereParts[] = 'u.department_id = :did';
            $bindParams[':did'] = $filterDept;
        }
    } elseif ($isFaculty && $userId) {
        $whereParts[] = 'u.college_id = :cid';
        $bindParams[':cid'] = $collegeId;
        $whereParts[] = 'u.department_id IN (
            SELECT DISTINCT fa.department_id
            FROM faculty_assignments fa
            WHERE fa.faculty_id = :fid_w
              AND fa.college_id = :cid_w
              AND fa.status = "active"
        )';
        $bindParams[':fid_w'] = $userId;
        $bindParams[':cid_w'] = $collegeId;
    } else {
        $whereParts[] = 'u.college_id = :cid';
        $bindParams[':cid'] = $collegeId;
        if ($filterDept) {
            $whereParts[] = 'u.department_id = :did';
            $bindParams[':did'] = $filterDept;
        }
    }

    if ($filterStatus !== '') {
        $whereParts[] = 'u.status = :status';
        $bindParams[':status'] = $filterStatus;
    }
    if ($search !== '') {
        $whereParts[] = '(u.full_name LIKE :q OR u.email LIKE :q OR u.roll_number LIKE :q OR u.username LIKE :q)';
        $bindParams[':q'] = '%' . $search . '%';
    }

    $where = implode(' AND ', $whereParts);

    /* ── Stats counts ─────────────────────────────────────────────────────── */
    $scopeParts  = ['role = "student"'];
    $scopeParams = [];

    if ($isSuperAdmin) {
        if ($filterDept) {
            $scopeParts[] = 'department_id = :s_did';
            $scopeParams[':s_did'] = $filterDept;
        }
    } elseif ($isFaculty && $userId) {
        $scopeParts[] = 'college_id = :s_cid';
        $scopeParams[':s_cid'] = $collegeId;
        $scopeParts[] = 'department_id IN (
            SELECT DISTINCT department_id
            FROM faculty_assignments
            WHERE faculty_id = :s_fid AND college_id = :s_cid2 AND status = "active"
        )';
        $scopeParams[':s_fid']  = $userId;
        $scopeParams[':s_cid2'] = $collegeId;
    } else {
        $scopeParts[] = 'college_id = :s_cid';
        $scopeParams[':s_cid'] = $collegeId;
        if ($filterDept) {
            $scopeParts[] = 'department_id = :s_did';
            $scopeParams[':s_did'] = $filterDept;
        }
    }

    $scopeWhere = implode(' AND ', $scopeParts);
    $st = $db->prepare("SELECT COUNT(*) AS total, SUM(status='active') AS active, SUM(status='pending') AS pending, SUM(status='inactive') AS inactive FROM users WHERE $scopeWhere");
    $st->execute($scopeParams);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $totalStudents    = (int)$row['total'];
        $activeStudents   = (int)$row['active'];
        $pendingStudents  = (int)$row['pending'];
        $inactiveStudents = (int)$row['inactive'];
    }

    /* ── Pagination ──────────────────────────────────────────────────────── */
    $stCount = $db->prepare("SELECT COUNT(*) FROM users u WHERE $where");
    $stCount->execute($bindParams);
    $totalRows  = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    /* ── Fetch paginated students ─────────────────────────────────────────── */
    $st = $db->prepare(
        "SELECT u.id, u.full_name, u.email, u.roll_number, u.username,
                u.phone, u.status, u.avatar_color, u.created_at,
                u.study_year, u.section, u.current_semester,
                d.name AS dept_name, d.code AS dept_code,
                c.name AS college_name, c.code AS college_code
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN colleges    c ON c.id = u.college_id
         WHERE $where
         ORDER BY u.created_at DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($bindParams as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $offset,  PDO::PARAM_INT);
    $st->execute();
    $students = $st->fetchAll(PDO::FETCH_ASSOC);

    /* ── Sidebar badges ───────────────────────────────────────────────────── */
    if ($deptId && $collegeId) {
        $st = $db->prepare('SELECT COUNT(*) FROM courses WHERE college_id = ? AND department_id = ? AND status = "active"');
        $st->execute([$collegeId, $deptId]);
        $myCourses = (int)$st->fetchColumn();

        $st = $db->prepare('SELECT COUNT(*) FROM exams WHERE college_id = ? AND department_id = ? AND exam_date >= CURDATE() AND status IN ("upcoming","ongoing")');
        $st->execute([$collegeId, $deptId]);
        $upcomingExams = (int)$st->fetchColumn();
    }
    if ($collegeId) {
        $st = $db->prepare('SELECT COUNT(*) FROM marks m JOIN exams e ON e.id = m.exam_id WHERE e.college_id = ? AND (e.department_id = ? OR ? = 0) AND m.obtained_marks IS NULL AND m.is_absent = 0');
        $st->execute([$collegeId, $deptId, $deptId]);
        $pendingMarks = (int)$st->fetchColumn();
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtDate(string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; }
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));
}
$avatarInitials = initials($fullName);

// ── Fetch avatar photo & color ────────────────────────────────────────────────
$avatarPath  = '';
$avatarColor = '#00d4bb';
if ($db && $userId) {
    $stAv = $db->prepare('SELECT avatar_path, avatar_color FROM users WHERE id = ? LIMIT 1');
    $stAv->execute([$userId]);
    if ($avRow = $stAv->fetch(PDO::FETCH_ASSOC)) {
        $avatarColor = $avRow['avatar_color'] ?: '#00d4bb';
        $rawPath     = $avRow['avatar_path']  ?? '';
        if ($rawPath && file_exists(__DIR__ . '/../' . $rawPath)) {
            $avatarPath = '../' . $rawPath . '?v=' . time();
        }
    }
}

function pageUrl(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

// JSON encode available depts & courses for JS modal
$jsAvailableDepts   = json_encode(array_values($availableDepts),   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsAvailableCourses = json_encode(array_values($availableCourses), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduNexus — Students · <?= esc($deptName) ?> · <?= esc($collegeName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ─────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === TEAL #0F766E · LIGHT TEAL #14B8A6 · SLATE #F8FAFC · AMBER #F59E0B === */
  --teal:#0F766E;
  --teal-dark:#0D5C56;
  --teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);
  --teal-soft2:rgba(20,184,166,.18);
  --amber:#F59E0B;
  --amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);
  --amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;
  --success:#16A34A;
  --green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;
  --text:#0F172A;
  --text-muted:#475569;
  --text-light:#94A3B8;
  --border:rgba(15,118,110,.10);
  --border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;
  --card-hover:#F0FDFA;
  /* Sidebar glassmorphic (deep teal) */
  --sb-text:rgba(255,255,255,.78);
  --sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);
  --sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);
  --sb-active-border:rgba(245,158,11,.38);
  --sidebar-w:264px;--sb-collapsed-w:72px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  /* legacy aliases used by existing HTML */
  --navy:#0F766E;--navy2:#0D5C56;
  --teal2:var(--teal-light);
  --teal3:var(--teal-soft);
  --teal4:var(--teal-soft2);
  --green:var(--success);
  --green2:rgba(22,163,74,.12);
  --muted:var(--text-muted);
}

html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* ── Cool BG ───────────────────────────────────────────────────────────────── */
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;
}

/* ── Layout ─────────────────────────────────────────────────────────────────── */
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── SIDEBAR — Glassmorphic deep teal ───────────────────────────────────────── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),inset 1px 0 0 rgba(20,184,166,.08),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease,width .3s ease;overflow:hidden;
}
.sidebar::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent,rgba(245,158,11,.55),rgba(255,255,255,.22),rgba(245,158,11,.55),transparent);
  pointer-events:none;
}
.sidebar::after{
  content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12));
  pointer-events:none;
}

.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12);}
.logo-mark{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15);}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}
/* Scope chip — "ASSIGNED TO" card */
.scope-chip{
  margin:10px 12px 0;
  background:linear-gradient(135deg,rgba(20,184,166,.13) 0%,rgba(15,118,110,.09) 100%);
  border:1px solid rgba(20,184,166,.32);
  border-radius:12px;
  padding:11px 13px 12px;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.10),
    0 4px 16px rgba(0,0,0,.12);
  position:relative;
  overflow:hidden;
}
.scope-chip::before{
  content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent);
}
.sc-label{
  font-size:.52rem;color:rgba(94,234,212,.90);text-transform:uppercase;
  letter-spacing:.16em;font-weight:700;margin-bottom:6px;
  display:flex;align-items:center;gap:5px;
}
.sc-label i{font-size:.54rem;}
.sc-college{
  font-size:.86rem;font-weight:800;color:#FFFFFF;
  line-height:1.25;margin-bottom:7px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  letter-spacing:.01em;
}
.sc-depts{display:flex;flex-direction:column;gap:4px;margin-top:0}
.sc-dept-tag{
  font-size:.72rem;color:rgba(186,230,253,.90);
  display:inline-flex;align-items:center;gap:5px;
  font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  max-width:100%;
}
.sc-dept-tag i{font-size:.62rem;flex-shrink:0;color:rgba(94,234,212,.80);}
.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent;}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.05)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--amber);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.nav-badge.amber{background:var(--amber);color:#0D5C56}
.sidebar-user{padding:13px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;box-shadow:0 2px 10px rgba(245,158,11,.28);}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.65rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}

/* ── Sidebar scrollable section ─────────────────────────────────────────────── */
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}

/* ── Sidebar collapse button ─────────────────────────────────────────────────── */
.sb-collapse-btn{
  margin-left:auto;flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);cursor:pointer;display:grid;place-items:center;
  font-size:.68rem;transition:all .2s;
}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber);}

/* ── Collapsed sidebar ───────────────────────────────────────────────────────── */
.sidebar.collapsed{width:var(--sb-collapsed-w);}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px;}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0;}
.sidebar.collapsed .logo-text{display:none !important;}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem;}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important;}
.sidebar.collapsed .nav-item.active,.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12)!important;border-color:rgba(255,255,255,.15)!important;box-shadow:none!important;color:#ffffff!important;}
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i{color:#ffffff!important;opacity:1!important;visibility:visible!important;}
.sidebar.collapsed .scope-chip,.sidebar.collapsed .scope-chip *,.sidebar.collapsed .scope-chip::before{display:none!important;height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:none!important;overflow:hidden!important;visibility:hidden!important;opacity:0!important;}
.sidebar.collapsed .nav-label{display:none!important;height:0!important;margin:0!important;padding:0!important;overflow:hidden!important;}
.sidebar.collapsed .sidebar-nav{padding:6px 8px 16px;margin-top:46px;}
.sidebar.collapsed .nav-item{display:flex!important;justify-content:center!important;align-items:center!important;padding:10px 0!important;gap:0!important;width:100%;overflow:hidden;border-radius:10px;}
.sidebar.collapsed .nav-item i{width:20px;text-align:center;font-size:.9rem;flex-shrink:0;margin:0;padding:0;}
.sidebar.collapsed .nav-text,.sidebar.collapsed .nav-badge{display:none!important;width:0!important;height:0!important;overflow:hidden!important;padding:0!important;margin:0!important;}
.sidebar.collapsed .sidebar-user{padding:10px 0;justify-content:center;gap:0;}
.sidebar.collapsed .user-info,.sidebar.collapsed .logout-btn{display:none!important;width:0!important;overflow:hidden!important;}
.sidebar.collapsed .user-avatar{margin:0 auto;flex-shrink:0;}
.sidebar.collapsed .nav-item::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#0F172A;color:#fff;font-size:.71rem;font-weight:500;font-family:var(--font);padding:5px 11px;border-radius:7px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08);}
.sidebar.collapsed .nav-item:hover::after{opacity:1;}

/* ── Main content area transitions ──────────────────────────────────────────── */
.main{
  margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;
  width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease;
}
.sidebar.collapsed ~ .main,.shell:has(.sidebar.collapsed) .main{
  margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w));
}

/* Topbar */
.topbar{height:var(--top-h);display:flex;align-items:center;padding:0 28px;gap:12px;background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);border-bottom:1px solid rgba(245,158,11,.18);position:sticky;top:0;z-index:50;box-shadow:0 2px 20px rgba(15,118,110,.28),0 1px 0 rgba(245,158,11,.10) inset;}
.hamburger{display:none}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:rgba(255,255,255,.52);font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);color:#ffffff;display:grid;place-items:center;cursor:pointer;transition:all .18s;position:relative;font-size:.82rem;}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber);background:rgba(245,158,11,.12)}
.date-chip{font-size:.72rem;color:#ffffff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);padding:5px 11px;border-radius:7px;font-family:var(--mono)}

/* ── Topbar Avatar ─────────────────────────────────────────────────────────── */
.topbar-avatar-wrap{position:relative}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;cursor:pointer;border:2px solid rgba(245,158,11,.30);transition:border-color .2s,box-shadow .2s;user-select:none;flex-shrink:0;}
.topbar-avatar:hover,.topbar-avatar.open{border-color:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{position:absolute;top:calc(100% + 10px);right:0;min-width:200px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);z-index:200;opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease;}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;color:var(--text-muted);font-size:.8rem;font-weight:500;text-decoration:none;transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:var(--text-light);flex-shrink:0}
.ad-item:hover{background:var(--card-hover);color:var(--text)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}

/* ── Content ────────────────────────────────────────────────────────────────── */
.content{padding:24px 28px;flex:1}

/* Scope banner */
.scope-banner{display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.16);border-radius:10px;padding:10px 16px;margin-bottom:18px;font-size:.78rem;animation:slideUp .4s ease both;}
.scope-banner i{color:var(--teal);flex-shrink:0}
.scope-banner strong{color:var(--text)}
.scope-banner .sep{color:var(--text-muted)}
.scope-banner .role-tag{margin-left:auto;font-size:.65rem;font-family:var(--mono);background:var(--teal-soft2);color:var(--teal);padding:2px 8px;border-radius:6px}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;animation:slideUp .4s .05s ease both;}
.page-header h1{font-size:1.3rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px}
.page-header h1 i{color:var(--teal)}
.page-header p{font-size:.8rem;color:var(--text-muted);margin-top:4px}

/* Buttons */
.btn-primary{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff;font-family:var(--font);font-weight:700;font-size:.82rem;padding:10px 18px;border-radius:9px;border:none;cursor:pointer;transition:all .2s;white-space:nowrap;box-shadow:0 4px 16px rgba(15,118,110,.25);}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.35)}
.btn-secondary{display:flex;align-items:center;gap:8px;background:var(--teal-soft);color:var(--teal);font-family:var(--font);font-weight:500;font-size:.82rem;padding:10px 16px;border-radius:9px;border:1px solid rgba(15,118,110,.25);cursor:pointer;transition:all .2s;text-decoration:none}
.btn-secondary:hover{background:var(--teal-soft2);color:var(--teal-dark);border-color:rgba(15,118,110,.40)}

/* Stats strip */
.stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;animation:slideUp .4s .08s ease both;}
.strip-item{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 8px rgba(15,118,110,.06);transition:transform .2s,box-shadow .2s}
.strip-item:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.10)}
.strip-icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.strip-num{font-size:1.4rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.strip-lbl{font-size:.7rem;color:var(--text-muted);margin-top:2px}

/* Filter bar */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;flex-wrap:wrap;animation:slideUp .4s .12s ease both;box-shadow:0 1px 6px rgba(15,118,110,.05);}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.82rem}
.search-input{width:100%;padding:9px 11px 9px 33px;background:#F8FAFC;border:1px solid rgba(15,118,110,.14);border-radius:8px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none;transition:all .2s;}
.search-input::placeholder{color:var(--text-light)}
.search-input:focus{border-color:var(--teal-light);background:#fff;box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.filter-select{padding:9px 30px 9px 11px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.25);border-radius:8px;color:var(--teal);font-family:var(--font);font-size:.83rem;outline:none;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%230F766E' stroke-width='1.5' stroke-linecap='round' fill='none'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;transition:all .2s;accent-color:#0F766E;color-scheme:light}
.filter-select:focus{border-color:var(--teal-light);background:var(--teal-soft);box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.filter-select option{background:#fff;color:var(--text)}
select{accent-color:#0F766E}
.filter-select.has-value{background:#0F766E;color:#fff;border-color:#0F766E}
.filter-select.has-value option{background:#fff;color:var(--text)}
.filter-btn{display:flex;align-items:center;gap:7px;padding:9px 14px;background:var(--teal-soft);color:var(--teal);border:1px solid rgba(15,118,110,.22);border-radius:8px;font-family:var(--font);font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.filter-btn:hover{background:var(--teal-soft2);border-color:var(--border-accent)}

/* Table card */
.table-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:slideUp .4s .16s ease both;box-shadow:0 1px 8px rgba(15,118,110,.06);}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:720px}
thead th{font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:12px 16px;text-align:left;background:#F0FDFA;border-bottom:1px solid var(--border);white-space:nowrap;}
tbody td{padding:13px 16px;font-size:.82rem;color:var(--text);vertical-align:middle}
tbody tr:not(:last-child) td{border-bottom:1px solid rgba(15,118,110,.06)}
tbody tr{transition:background .15s}
tbody tr:hover td{background:var(--card-hover)}
.student-cell{display:flex;align-items:center;gap:11px}
.student-avatar{width:34px;height:34px;border-radius:9px;flex-shrink:0;display:grid;place-items:center;font-weight:700;font-size:.75rem;color:#fff;}
.student-name{font-weight:600;color:var(--text);font-size:.84rem}
.student-sub{font-size:.7rem;color:var(--text-muted);margin-top:1px}
.pill{font-size:.64rem;font-weight:700;padding:3px 9px;border-radius:6px;display:inline-block;white-space:nowrap}
.pill.active{background:var(--green2);color:var(--success)}
.pill.pending{background:var(--amber-soft);color:var(--amber-dark)}
.pill.inactive{background:#F1F5F9;color:var(--text-muted)}
.mono{font-family:var(--mono);font-size:.72rem;color:var(--text-muted);background:#F1F5F9;padding:2px 7px;border-radius:5px;white-space:nowrap}
.row-actions{display:flex;align-items:center;gap:5px;opacity:1;transition:opacity .2s}
tbody tr:hover .row-actions{opacity:1}
.action-btn{width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:#F8FAFC;color:var(--text-muted);font-size:.72rem;display:grid;place-items:center;cursor:pointer;transition:all .2s;}
.action-btn:hover{background:var(--teal-soft);border-color:rgba(15,118,110,.25);color:var(--teal)}
.table-footer{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--border);font-size:.78rem;color:var(--teal);flex-wrap:wrap;gap:10px}
.pagination{display:flex;gap:4px}
.page-btn{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:#fff;color:var(--text-muted);font-size:.78rem;display:grid;place-items:center;cursor:pointer;transition:all .2s;text-decoration:none}
.page-btn:hover{border-color:rgba(15,118,110,.28);color:var(--teal);background:var(--teal-soft)}
.page-btn.active{background:var(--teal);border-color:var(--teal);color:#fff;font-weight:700}
.page-btn.disabled{opacity:.35;pointer-events:none}
.empty-state{padding:52px;text-align:center;color:var(--text-muted);font-size:.85rem}
.empty-state i{font-size:2rem;display:block;margin-bottom:10px;opacity:.25}

/* ── Toast ───────────────────────────────────────────────────────────────────── */
.toast{position:fixed;bottom:24px;right:24px;z-index:400;background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px 18px;display:flex;align-items:center;gap:11px;box-shadow:0 12px 40px rgba(15,118,110,.14);transform:translateY(20px);opacity:0;pointer-events:none;transition:all .3s}
.toast.show{transform:translateY(0);opacity:1;pointer-events:all}
.toast.success{border-color:rgba(22,163,74,.30)}.toast.success i{color:var(--success)}
.toast.error{border-color:rgba(220,38,38,.30)}.toast.error i{color:var(--red)}
.toast-msg{font-size:.83rem;color:var(--text)}

/* ══ MODAL ═══════════════════════════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.50);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:#fff;border:1px solid rgba(20,184,166,.22);border-radius:20px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 32px 80px rgba(15,118,110,.18);transform:translateY(24px) scale(.97);transition:transform .25s}
.modal-overlay.open .modal{transform:translateY(0) scale(1)}
.modal-header{display:flex;align-items:center;gap:12px;padding:22px 24px 16px;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:1}
.modal-header h2{font-size:1rem;font-weight:800;color:var(--text);flex:1}
.modal-header .modal-icon{width:38px;height:38px;border-radius:10px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.18);display:grid;place-items:center;color:var(--teal);font-size:.9rem;flex-shrink:0}
.modal-close{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:#F8FAFC;color:var(--text-muted);font-size:.85rem;display:grid;place-items:center;cursor:pointer;transition:all .18s;flex-shrink:0}
.modal-close:hover{background:var(--red2);border-color:rgba(220,38,38,.3);color:var(--red)}
.modal-body{padding:22px 24px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.full{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:.72rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:5px}
.form-label .req{color:var(--red);font-size:.8rem}
.form-input,.form-select{padding:10px 13px;background:#F8FAFC;border:1px solid rgba(15,118,110,.16);border-radius:9px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none;transition:all .2s;width:100%}
.form-input::placeholder{color:var(--text-light)}
.form-input:focus,.form-select:focus{border-color:var(--teal-light);background:#fff;box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.form-select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%230F766E' stroke-width='1.5' stroke-linecap='round' fill='none'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-color:#F8FAFC;cursor:pointer;padding-right:32px;}
.form-select option{background:#fff;color:var(--text)}
.form-hint{font-size:.68rem;color:var(--text-muted);margin-top:2px}
.section-divider{grid-column:1/-1;border:none;border-top:1px solid var(--border);margin:4px 0}
.section-label{grid-column:1/-1;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--text-muted);display:flex;align-items:center;gap:6px}
.section-label i{color:var(--teal)}
.form-alert{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;font-size:.8rem;margin-bottom:14px}
.form-alert.error{background:var(--red2);border:1px solid rgba(220,38,38,.22);color:#991b1b}
.form-alert.success{background:var(--green2);border:1px solid rgba(22,163,74,.22);color:#166534}
.modal-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#fff}
.btn-ghost{padding:10px 16px;background:#F8FAFC;border:1px solid var(--border);border-radius:9px;color:var(--text-muted);font-family:var(--font);font-size:.82rem;font-weight:500;cursor:pointer;transition:all .2s}
.btn-ghost:hover{background:var(--card-hover);color:var(--teal);border-color:rgba(15,118,110,.22)}

/* ── Scope badge in modal ───────────────────────────────────────────────────── */
.scope-info{grid-column:1/-1;background:var(--teal-soft);border:1px solid rgba(15,118,110,.16);border-radius:10px;padding:10px 14px;font-size:.76rem;color:var(--text-muted);display:flex;align-items:flex-start;gap:8px}
.scope-info i{color:var(--teal);margin-top:1px;flex-shrink:0}

/* ── Animations ─────────────────────────────────────────────────────────────── */
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Sidebar overlay (mobile) ───────────────────────────────────────────────── */
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:1100px){.stats-strip{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .main{margin-left:0}.content{padding:16px}
  .stats-strip{grid-template-columns:1fr 1fr}
  .hamburger{display:grid}
  .page-header{flex-direction:column}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
  .stats-strip{grid-template-columns:1fr 1fr}
  .filter-bar{flex-direction:column;align-items:stretch}
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Toast -->
<div class="toast" id="toast"><i class="fas fa-circle-check"></i><span class="toast-msg" id="toastMsg"></span></div>

<!-- ══ ADD STUDENT MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-icon"><i class="fas fa-user-plus"></i></div>
      <h2 id="modalTitle">Add New Student</h2>
      <button class="modal-close" onclick="closeModal()" title="Close"><i class="fas fa-xmark"></i></button>
    </div>

    <form method="POST" action="students.php<?= $filterDept ? '?dept='.$filterDept : '' ?>" id="addStudentForm" autocomplete="off">
      <input type="hidden" name="action" value="add_student">

      <div class="modal-body">

        <?php if($addError): ?>
        <div class="form-alert error"><i class="fas fa-circle-exclamation"></i><?= esc($addError) ?></div>
        <?php endif; ?>

        <!-- Scope info -->
        <div class="form-grid">
          <div class="scope-info">
            <i class="fas fa-shield-halved"></i>
            <div>
              <?php if($isFaculty): ?>
                <strong style="color:var(--teal)">Faculty scope</strong> — you can only add students to departments and courses you're assigned to by the superadmin.
              <?php elseif($isSuperAdmin): ?>
                <strong style="color:var(--teal)">Super Admin scope</strong> — you can add students to any department and course across all colleges.
              <?php else: ?>
                <strong style="color:var(--teal)">College Admin scope</strong> — you can add students to any department within <strong style="color:var(--white)"><?= esc($collegeName) ?></strong>.
              <?php endif; ?>
            </div>
          </div>

          <!-- ── Personal Info ── -->
          <hr class="section-divider">
          <div class="section-label"><i class="fas fa-id-card"></i> Personal Information</div>

          <div class="form-group">
            <label class="form-label" for="f_fullname">Full Name <span class="req">*</span></label>
            <input type="text" id="f_fullname" name="full_name" class="form-input" placeholder="e.g. Arjun Sharma" required
                   value="<?= esc($_POST['full_name'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label" for="f_roll">Roll Number</label>
            <input type="text" id="f_roll" name="roll_number" class="form-input" placeholder="e.g. CSE-A-042"
                   value="<?= esc($_POST['roll_number'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label" for="f_email">Email <span class="req">*</span></label>
            <input type="email" id="f_email" name="email" class="form-input" placeholder="student@college.edu" required
                   value="<?= esc($_POST['email'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label" for="f_phone">Phone</label>
            <input type="tel" id="f_phone" name="phone" class="form-input" placeholder="+91 98765 43210"
                   value="<?= esc($_POST['phone'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label" for="f_username">Username <span class="req">*</span></label>
            <input type="text" id="f_username" name="username" class="form-input" placeholder="e.g. arjun_cse42" required
                   value="<?= esc($_POST['username'] ?? '') ?>">
            <span class="form-hint">Used to log in. Must be unique.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="f_password">Password</label>
            <input type="password" id="f_password" name="password" class="form-input" placeholder="Leave blank → Password@123">
            <span class="form-hint">Default: <code style="color:var(--teal)">Password@123</code> if left blank.</span>
          </div>

          <!-- ── Assignment ── -->
          <hr class="section-divider">
          <div class="section-label"><i class="fas fa-sitemap"></i> Department & Course Assignment</div>

          <?php if($isSuperAdmin): ?>
          <!-- Super admin: college dropdown first, then dept cascades -->
          <div class="form-group">
            <label class="form-label" for="f_college_id">College <span class="req">*</span></label>
            <select id="f_college_id" name="college_id" class="form-select" onchange="cascadeDept()" required>
              <option value="">— Select College —</option>
              <?php
                // Unique colleges from availableDepts
                $seenColleges = [];
                foreach($availableDepts as $d) {
                  $cid = $d['college_id'] ?? $d['college_id_val'] ?? null;
                  if ($cid && !isset($seenColleges[$cid])) {
                    $seenColleges[$cid] = $d['college_name'] ?? '';
                    echo '<option value="'.esc($cid).'"'.($cid == ($_POST['college_id'] ?? '') ? ' selected':'').'>'.esc($d['college_name'] ?? '').'</option>';
                  }
                }
              ?>
            </select>
          </div>
          <?php else: ?>
          <input type="hidden" name="college_id" value="<?= $collegeId ?>">
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label" for="f_dept_id">Department <span class="req">*</span></label>
            <select id="f_dept_id" name="dept_id" class="form-select" onchange="cascadeCourse()" required>
              <option value="">— Select Department —</option>
              <?php foreach($availableDepts as $d): ?>
                <option
                  value="<?= (int)$d['id'] ?>"
                  data-college="<?= (int)($d['college_id'] ?? $d['college_id_val'] ?? 0) ?>"
                  <?= (isset($_POST['dept_id']) && $_POST['dept_id'] == $d['id']) ? 'selected' : '' ?>>
                  <?= esc(($isSuperAdmin ? ($d['college_name'] ?? '').' — ' : '') . $d['name'].' ('.$d['code'].')') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if($isFaculty && empty($availableDepts)): ?>
              <span class="form-hint" style="color:var(--amber)"><i class="fas fa-exclamation-triangle"></i> No departments assigned to you yet. Contact the superadmin.</span>
            <?php endif; ?>
          </div>

          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label" for="f_course_id">Enroll in Course / Subject</label>
            <select id="f_course_id" name="course_id" class="form-select">
              <option value="">— Select Course (optional) —</option>
              <?php foreach($availableCourses as $co): ?>
                <option
                  value="<?= (int)$co['id'] ?>"
                  data-dept="<?= (int)$co['department_id'] ?>"
                  data-college="<?= (int)$co['college_id'] ?>"
                  <?= (isset($_POST['course_id']) && $_POST['course_id'] == $co['id']) ? 'selected' : '' ?>>
                  <?= esc($co['name'].' ('.$co['code'].')'.($co['semester'] ? ' · Sem '.$co['semester'] : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-hint">Only courses belonging to the selected department are shown.</span>
          </div>

        </div><!-- /form-grid -->
      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-primary" <?= empty($availableDepts) ? 'disabled title="No departments available"' : '' ?>>
          <i class="fas fa-user-plus"></i> Add Student
        </button>
      </div>
    </form>
  </div>
</div>
<!-- /modal -->

<div class="shell">

<!-- ══ SIDEBAR ═══════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">PE</div>
    <div class="logo-text">PEPA <span>ERP Platform</span></div>
    <button class="sb-collapse-btn" onclick="collapseSidebar()" title="Collapse sidebar">
      <i class="fas fa-angles-left" id="collapseIcon"></i>
    </button>
  </div>

  <div class="sidebar-scroll">
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
        <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($deptName) ?></span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard"><i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span></a>
      <a href="students.php" class="nav-item active" data-tip="Students">
        <i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span>
        <?php if($totalStudents): ?><span class="nav-badge"><?= $totalStudents ?></span><?php endif; ?>
      </a>

      <div class="nav-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance"><i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span></a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results">
        <i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
        <?php if($pendingMarks): ?><span class="nav-badge red"><?= $pendingMarks ?></span><?php endif; ?>
      </a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations">
        <i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
        <?php if($upcomingExams): ?><span class="nav-badge amber"><?= $upcomingExams ?></span><?php endif; ?>
      </a>
      <a href="timetable.php" class="nav-item" data-tip="Timetable"><i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span></a>

      <div class="nav-label">Communication</div>
      <a href="staff_noticeboard.php" class="nav-item" data-tip="Staff Noticeboard"><i class="fas fa-clipboard-list"></i><span class="nav-text"> Staff Noticeboard</span></a>
      <a href="leave_application.php" class="nav-item" data-tip="Leave Application"><i class="fas fa-calendar-minus"></i><span class="nav-text"> Leave Application</span></a>
      <a href="expense_apply.php" class="nav-item" data-tip="Expense Apply"><i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span></a>

      <div class="nav-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile"><i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span></a>
    </nav>
  </div><!-- /sidebar-scroll -->

  <!-- Pinned user bar — always visible at bottom -->
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= esc(ucfirst(str_replace('_',' ',$role))) ?> · <?= esc($deptCode ?: $deptName) ?></div>
    </div>
    <button class="logout-btn" title="Logout" onclick="doLogout()">
      <i class="fas fa-arrow-right-from-bracket"></i>
    </button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      Students
    </div>
    <div class="topbar-actions">

      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if($pendingMarks || $upcomingExams): ?><span style="position:absolute;top:5px;right:5px;width:6px;height:6px;background:var(--amber);border-radius:50%;border:1.5px solid var(--navy)"></span><?php endif; ?>
      </div>
      <!-- Avatar -->
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= esc($fullName) ?>"
             style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>">
          <?php if ($avatarPath): ?>
            <img src="<?= esc($avatarPath) ?>" alt="<?= esc($avatarInitials) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
          <?php else: ?>
            <?= esc($avatarInitials) ?>
          <?php endif; ?>
        </div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= esc($fullName) ?></div>
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= esc(ucfirst(str_replace('_',' ',$role))) ?></div>
          </div>
          <a href="profile.php" class="ad-item"><i class="fas fa-circle-user"></i> My Profile</a>
          <a href="settings.php" class="ad-item"><i class="fas fa-gear"></i> Settings</a>
          <div class="ad-sep"></div>
          <button class="ad-item danger" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </header>

  <div class="content">

    <!-- Scope banner -->

    <!-- Page header -->
    <div class="page-header">
      <div>
        <h1><i class="fas fa-user-graduate"></i> Student Management</h1>
        <p>
          <?php if($isFaculty): ?>
            Showing students in your assigned departments
          <?php elseif($isSuperAdmin): ?>
            All students across all colleges
          <?php else: ?>
            All students in <strong style="color:var(--teal)"><?= esc($collegeName) ?></strong>
          <?php endif; ?>
        </p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn-secondary">
          <i class="fas fa-download"></i> Export CSV
        </a>
        <?php if (!$isFaculty): ?>
        <button class="btn-secondary" onclick="openAllotModal()" style="border-color:rgba(124,58,237,.35);color:#7c3aed;background:rgba(124,58,237,.07)" onmouseover="this.style.background='rgba(124,58,237,.14)'" onmouseout="this.style.background='rgba(124,58,237,.07)'">
          <i class="fas fa-layer-group"></i> Allot Students
        </button>
        <?php endif; ?>
        <!-- Add Student button — visible to all roles; faculty sees it but can only add to assigned depts -->
        <button class="btn-primary" onclick="openModal()">
          <i class="fas fa-plus"></i> Add Student
        </button>
      </div>
    </div>

    <!-- Stats strip -->
    <div class="stats-strip">
      <div class="strip-item">
        <div class="strip-icon" style="background:var(--teal3);color:var(--teal)"><i class="fas fa-users"></i></div>
        <div><div class="strip-num"><?= $totalStudents ?></div><div class="strip-lbl">Total Students</div></div>
      </div>
      <div class="strip-item">
        <div class="strip-icon" style="background:var(--green2);color:var(--green)"><i class="fas fa-circle-check"></i></div>
        <div><div class="strip-num"><?= $activeStudents ?></div><div class="strip-lbl">Active</div></div>
      </div>
      <div class="strip-item">
        <div class="strip-icon" style="background:var(--amber2);color:var(--amber)"><i class="fas fa-clock"></i></div>
        <div><div class="strip-num"><?= $pendingStudents ?></div><div class="strip-lbl">Pending Approval</div></div>
      </div>
      <div class="strip-item">
        <div class="strip-icon" style="background:var(--red2);color:var(--red)"><i class="fas fa-user-xmark"></i></div>
        <div><div class="strip-num"><?= $inactiveStudents ?></div><div class="strip-lbl">Inactive</div></div>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="students.php" id="filterForm">
      <div class="filter-bar">
        <div class="search-wrap">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" name="q" class="search-input"
                 placeholder="Search name, email, roll number…"
                 value="<?= esc($search) ?>">
        </div>

        <?php if(!$isFaculty && count($departments) > 1): ?>
        <select name="dept" class="filter-select" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php foreach($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>>
              <?= esc(($isSuperAdmin && isset($d['college_name']) ? $d['college_name'].' — ' : '') . $d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
          <input type="hidden" name="dept" value="<?= $deptId ?>">
        <?php endif; ?>

        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Status</option>
          <option value="active"   <?= $filterStatus==='active'   ? 'selected' : '' ?>>Active</option>
          <option value="pending"  <?= $filterStatus==='pending'  ? 'selected' : '' ?>>Pending</option>
          <option value="inactive" <?= $filterStatus==='inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>


        <?php if($search || $filterStatus || (!$isFaculty && $filterDept !== $deptId)): ?>
        <a href="students.php<?= $isFaculty ? '?dept='.$deptId : '' ?>" class="filter-btn" style="background:var(--red2);color:var(--red);border-color:rgba(239,68,68,.25)">
          <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Table Card -->
    <div class="table-card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Roll No.</th>
              <th>Username</th>
              <th>Phone</th>
              <th>Department</th>
              <th>Year / Section</th>
              <th>College</th>
              <th>Joined</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="studentTableBody">
            <?php if(empty($students)): ?>
            <tr>
              <td colspan="11">
                <div class="empty-state">
                  <i class="fas fa-users-slash"></i>
                  <?php if($search): ?>
                    No students match your search — <strong style="color:var(--white)"><?= esc($search) ?></strong>
                  <?php elseif($filterStatus): ?>
                    No <?= esc($filterStatus) ?> students found in this scope.
                  <?php else: ?>
                    No students found. <a href="javascript:void(0)" onclick="openModal()" style="color:var(--teal);text-decoration:underline">Add the first one?</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <?php
              $colorPalette = ['#00d4bb','#f59e0b','#8b5cf6','#3b82f6','#10b981','#ef4444','#ec4899','#f97316'];
              $rowNum = ($page - 1) * $perPage + 1;
              foreach($students as $s):
                $color = $colorPalette[ord($s['full_name'][0] ?? 'A') % count($colorPalette)];
                $initials = initials($s['full_name']);
            ?>
            <tr data-sid="<?= (int)$s['id'] ?>" data-name="<?= esc($s['full_name']) ?>" data-year="<?= (int)($s['study_year']??0) ?>" data-sec="<?= esc($s['section']??'') ?>" data-sem="<?= (int)($s['current_semester']??0) ?>">
              <td style="color:var(--muted);font-family:var(--mono);font-size:.7rem"><?= $rowNum++ ?></td>
              <td>
                <div class="student-cell">
                  <div class="student-avatar" style="background:<?= $color ?>"><?= esc($initials) ?></div>
                  <div>
                    <div class="student-name"><?= esc($s['full_name']) ?></div>
                    <div class="student-sub"><?= esc($s['email']) ?></div>
                  </div>
                </div>
              </td>
              <td>
                <?php if($s['roll_number']): ?>
                  <span class="mono"><?= esc($s['roll_number']) ?></span>
                <?php else: ?>
                  <span style="color:var(--muted)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="mono" style="font-size:.78rem;color:var(--teal)"><?= esc($s['username']) ?></span>
              </td>
              <td>
                <?php if($s['phone']): ?>
                  <span style="font-size:.78rem;color:var(--text)"><i class="fas fa-phone" style="font-size:.6rem;color:var(--muted)"></i> <?= esc($s['phone']) ?></span>
                <?php else: ?>
                  <span style="color:var(--muted)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:.8rem;font-weight:600;color:var(--text)"><?= esc($s['dept_name'] ?? '—') ?></span>
                <?php if($s['dept_code']): ?>
                  <div style="font-size:.68rem;color:var(--muted);font-family:var(--mono)"><?= esc($s['dept_code']) ?></div>
                <?php endif; ?>
              </td>
              <td class="year-section-cell">
                <?php
                  $yearLabels = ['','1st','2nd','3rd','4th','5th','6th'];
                  $sy  = (int)($s['study_year']       ?? 0);
                  $sec = $s['section']                ?? '';
                  $csem= (int)($s['current_semester'] ?? 0);
                  if ($sy || $sec || $csem):
                ?>
                <div style="display:flex;flex-direction:column;gap:3px">
                  <?php if($sy): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.22);color:#7c3aed;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content">
                    <i class="fas fa-layer-group" style="font-size:.6rem"></i>
                    <?= esc($yearLabels[$sy] ?? $sy) ?> Year
                  </span>
                  <?php endif; ?>
                  <?php if($csem): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:#d97706;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content">
                    <i class="fas fa-graduation-cap" style="font-size:.6rem"></i>
                    Sem <?= $csem ?>
                  </span>
                  <?php endif; ?>
                  <?php if($sec): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.22);color:var(--teal);padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content">
                    <i class="fas fa-users" style="font-size:.6rem"></i>
                    Sec <?= esc($sec) ?>
                  </span>
                  <?php endif; ?>
                </div>
                <?php else: ?>
                <span style="color:var(--muted);font-size:.75rem">Not allotted</span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:.78rem;color:var(--muted)"><?= esc($s['college_code'] ?? $s['college_name'] ?? '—') ?></span>
              </td>
              <td style="white-space:nowrap;font-size:.76rem;color:var(--muted)"><?= fmtDate($s['created_at']) ?></td>
              <td>
                <span class="pill <?= esc($s['status']) ?>"><?= ucfirst(esc($s['status'])) ?></span>
              </td>
              <td>
                <div class="row-actions">
                  <button class="action-btn" title="View details"
                    onclick="openStudentDetailModal(<?= json_encode(['id'=>(int)$s['id'],'full_name'=>$s['full_name'],'email'=>$s['email'],'username'=>$s['username'],'phone'=>$s['phone']??'','roll_number'=>$s['roll_number']??'','dept_name'=>$s['dept_name']??'','dept_code'=>$s['dept_code']??'','college_name'=>$s['college_name']??'','status'=>$s['status'],'created_at'=>$s['created_at'],'study_year'=>(int)($s['study_year']??0),'current_semester'=>(int)($s['current_semester']??0),'section'=>$s['section']??'']) ?>)">
                    <i class="fas fa-eye"></i>
                  </button>
                  <button class="action-btn" title="Edit student"
                    onclick="showToast('Edit handler — connect to your edit page')">
                    <i class="fas fa-pen"></i>
                  </button>
                  <?php if(!$isFaculty): ?>
                  <button class="action-btn" title="Allot year &amp; section"
                    style="color:#7c3aed;border-color:rgba(124,58,237,.25)"
                    onclick="openAllotSingle(<?= (int)$s['id'] ?>, <?= json_encode($s['full_name']) ?>, <?= (int)($s['study_year']??0) ?>, <?= json_encode($s['section']??'') ?>, <?= (int)($s['current_semester']??0) ?>)">
                    <i class="fas fa-layer-group"></i>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if($totalPages > 1 || $totalRows > 0): ?>
      <div class="table-footer">
        <span>
          Showing
          <strong style="color:var(--teal)"><?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $totalRows) ?></strong>
          of
          <strong style="color:var(--teal)"><?= $totalRows ?></strong>
          student<?= $totalRows != 1 ? 's' : '' ?>
        </span>
        <?php if($totalPages > 1): ?>
        <div class="pagination">
          <a href="<?= pageUrl($page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
            <i class="fas fa-chevron-left"></i>
          </a>
          <?php for($p = 1; $p <= $totalPages; $p++):
            if($p === 1 || $p === $totalPages || abs($p - $page) <= 1): ?>
              <a href="<?= pageUrl($p) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php elseif(abs($p - $page) === 2): ?>
              <span class="page-btn" style="pointer-events:none">…</span>
            <?php endif;
          endfor; ?>
          <a href="<?= pageUrl($page + 1) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <i class="fas fa-chevron-right"></i>
          </a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div><!-- /table-card -->

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<?php
// ── CSV export handler ────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $db):
    $stExp = $db->prepare(
        "SELECT u.full_name, u.email, u.roll_number, u.username, u.phone, u.status,
                u.created_at, d.name AS dept_name, d.code AS dept_code, c.name AS college_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN colleges    c ON c.id = u.college_id
         WHERE $where ORDER BY u.created_at DESC"
    );
    foreach ($bindParams as $k => $v) $stExp->bindValue($k, $v);
    $stExp->execute();
    $rows = $stExp->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="students_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full Name','Email','Roll Number','Username','Phone','Department','Dept Code','College','Status','Joined']);
    foreach($rows as $r) {
        fputcsv($out, [
            $r['full_name'], $r['email'], $r['roll_number'] ?? '', $r['username'],
            $r['phone'] ?? '', $r['dept_name'] ?? '', $r['dept_code'] ?? '',
            $r['college_name'] ?? '', $r['status'], date('d M Y', strtotime($r['created_at']))
        ]);
    }
    fclose($out);
    exit;
endif;
?>

<script>
/* ── Data from PHP ───────────────────────────────────────────────────────────── */
const AVAILABLE_DEPTS   = <?= $jsAvailableDepts ?>;
const AVAILABLE_COURSES = <?= $jsAvailableCourses ?>;

/* ── Clock ─────────────────────────────────────────────────────────────────── */
function tick(){
  const el = document.getElementById('topbarDate');
  if(el) el.textContent = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
tick(); setInterval(tick, 60000);

/* ── Sidebar collapse (desktop) ─────────────────────────────────────────────── */
function collapseSidebar(){
  const sb = document.getElementById('sidebar');
  const icon = document.getElementById('collapseIcon');
  const collapsed = sb.classList.toggle('collapsed');
  localStorage.setItem('sbCollapsed', collapsed ? '1' : '0');
  if(collapsed){
    icon.classList.replace('fa-angles-left','fa-angles-right');
  } else {
    icon.classList.replace('fa-angles-right','fa-angles-left');
  }
}
// Restore state on load
(function(){
  if(localStorage.getItem('sbCollapsed') === '1'){
    const sb = document.getElementById('sidebar');
    const icon = document.getElementById('collapseIcon');
    if(sb){ sb.classList.add('collapsed'); }
    if(icon){ icon.classList.replace('fa-angles-left','fa-angles-right'); }
  }
})();

/* ── Sidebar (mobile) ───────────────────────────────────────────────────────── */
/* ── Filter select highlight ── */
(function(){
  function syncSelect(sel){
    if(sel.value) sel.classList.add('has-value');
    else sel.classList.remove('has-value');
  }
  document.querySelectorAll('.filter-select').forEach(function(sel){
    syncSelect(sel);
    sel.addEventListener('change', function(){ syncSelect(this); });
  });
})();

function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  const open = sb.classList.toggle('open');
  if(ov) ov.classList.toggle('active', open);
}
document.addEventListener('click', function(e){
  const sb = document.getElementById('sidebar'), btn = document.getElementById('menuToggle');
  if(window.innerWidth <= 800 && sb.classList.contains('open') &&
     !sb.contains(e.target) && btn && !btn.contains(e.target)){
    sb.classList.remove('open');
    const ov = document.getElementById('sidebarOverlay');
    if(ov) ov.classList.remove('active');
  }
});

/* ── Toast ─────────────────────────────────────────────────────────────────── */
function showToast(msg, type='success'){
  const t = document.getElementById('toast');
  t.querySelector('i').className = type==='success' ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
  document.getElementById('toastMsg').textContent = msg;
  t.className = `toast show ${type}`;
  setTimeout(() => t.classList.remove('show'), 3500);
}

/* ── Modal ─────────────────────────────────────────────────────────────────── */
function openModal(){
  document.getElementById('addModal').classList.add('open');
  document.body.style.overflow = 'hidden';
  // Auto-open if server returned an error (re-show after validation fail)
}
function closeModal(){
  document.getElementById('addModal').classList.remove('open');
  document.body.style.overflow = '';
}
// Close on overlay click
document.getElementById('addModal').addEventListener('click', function(e){
  if(e.target === this) closeModal();
});
document.getElementById('allotModal').addEventListener('click', function(e){
  if(e.target === this) closeAllotModal();
});
// Close on Escape
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){ closeModal(); closeAllotModal(); }
});

/* ── Cascade: college → dept → course ──────────────────────────────────────── */
function cascadeDept(){
  const collegeId = parseInt(document.getElementById('f_college_id')?.value || '0');
  const deptSel = document.getElementById('f_dept_id');
  const prevVal = deptSel.value;

  // Remove all options except placeholder
  while(deptSel.options.length > 1) deptSel.remove(1);

  AVAILABLE_DEPTS.forEach(d => {
    const cid = parseInt(d.college_id || d.college_id_val || 0);
    if(!collegeId || cid === collegeId){
      const opt = new Option(d.name + ' (' + d.code + ')', d.id);
      opt.dataset.college = cid;
      deptSel.add(opt);
    }
  });

  // Restore selection if still valid
  if([...deptSel.options].some(o => o.value == prevVal)) deptSel.value = prevVal;
  cascadeCourse();
}

function cascadeCourse(){
  const deptId = parseInt(document.getElementById('f_dept_id')?.value || '0');
  const collegeId = parseInt(document.getElementById('f_college_id')?.value || '0');
  const courseSel = document.getElementById('f_course_id');
  const prevVal = courseSel.value;

  while(courseSel.options.length > 1) courseSel.remove(1);

  AVAILABLE_COURSES.forEach(c => {
    const cdept    = parseInt(c.department_id || 0);
    const ccollege = parseInt(c.college_id    || 0);
    const deptMatch    = !deptId    || cdept    === deptId;
    const collegeMatch = !collegeId || ccollege === collegeId;
    if(deptMatch && collegeMatch){
      const label = c.name + ' (' + c.code + ')' + (c.semester ? ' · Sem ' + c.semester : '');
      const opt = new Option(label, c.id);
      opt.dataset.dept = cdept;
      courseSel.add(opt);
    }
  });

  if([...courseSel.options].some(o => o.value == prevVal)) courseSel.value = prevVal;
}

// Auto-suggest username from full name
document.getElementById('f_fullname')?.addEventListener('input', function(){
  const uname = document.getElementById('f_username');
  if(!uname.value || uname.dataset.manual !== '1'){
    uname.value = this.value.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'').slice(0,30);
  }
});
document.getElementById('f_username')?.addEventListener('input', function(){
  this.dataset.manual = '1';
});

/* Re-open modal on page load if there was a server-side error */
<?php if($addError): ?>
window.addEventListener('DOMContentLoaded', () => openModal());
<?php endif; ?>

/* Show success toast on page load if student was just added */
<?php if($addSuccess): ?>
window.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($addSuccess) ?>));
<?php endif; ?>

/* ── Allot Students modal ──────────────────────────────────────────────────── */
function openAllotModal() {
  // Bulk-allot mode: populate student list
  document.getElementById('allotSingleMode').style.display = 'none';
  document.getElementById('allotBulkMode').style.display   = '';
  document.getElementById('allotModalTitle').textContent   = 'Allot Year & Section';
  document.getElementById('allotMsg').style.display        = 'none';
  document.getElementById('allotConfirmBtn').style.display = '';
  document.getElementById('allotModal').classList.add('open');
  document.body.style.overflow = 'hidden';
  populateBulkList();
}

function openAllotSingle(id, name, currentYear, currentSection, currentSem) {
  // Single-student mode
  document.getElementById('allotSingleMode').style.display = '';
  document.getElementById('allotBulkMode').style.display   = 'none';
  document.getElementById('allotModalTitle').textContent   = 'Allot: ' + name;
  document.getElementById('allotMsg').style.display        = 'none';
  document.getElementById('allotConfirmBtn').style.display = '';
  document.getElementById('allotSingleId').value    = id;
  document.getElementById('allotSingleName').textContent = name;
  document.getElementById('allotYear').value    = currentYear || '';
  document.getElementById('allotSection').value = currentSection || '';
  document.getElementById('allotSemester').value = currentSem || '';
  document.getElementById('allotModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeAllotModal() {
  document.getElementById('allotModal').classList.remove('open');
  document.body.style.overflow = '';
}

function populateBulkList() {
  // Collect all students from the current table
  const rows = document.querySelectorAll('#studentTableBody tr[data-sid]');
  const list = document.getElementById('allotBulkList');
  if (!rows.length) {
    list.innerHTML = '<p style="color:var(--muted);font-size:.8rem">No students on this page.</p>';
    return;
  }
  let html = '';
  rows.forEach(row => {
    const sid  = row.dataset.sid;
    const name = row.dataset.name;
    const yr   = row.dataset.year  || '';
    const sec  = row.dataset.sec   || '';
    const sem  = row.dataset.sem   || '';
    const semOpts = [1,2,3,4,5,6,7,8,9,10].map(n=>`<option value="${n}" ${sem==n?'selected':''}>Sem ${n}</option>`).join('');
    html += `
      <div class="allot-row" id="allot-row-${sid}">
        <div class="allot-student-name">
          <div style="width:28px;height:28px;border-radius:7px;background:rgba(124,58,237,.12);display:grid;place-items:center;font-size:.65rem;font-weight:800;color:#7c3aed;flex-shrink:0">${name.charAt(0).toUpperCase()}</div>
          <span>${escHtml(name)}</span>
        </div>
        <select class="allot-yr-sel" data-sid="${sid}" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;font-size:.74rem;font-family:var(--font);color:var(--text);background:#fff;min-width:100px">
          <option value="">— Year —</option>
          <option value="1" ${yr=='1'?'selected':''}>1st Year</option>
          <option value="2" ${yr=='2'?'selected':''}>2nd Year</option>
          <option value="3" ${yr=='3'?'selected':''}>3rd Year</option>
          <option value="4" ${yr=='4'?'selected':''}>4th Year</option>
          <option value="5" ${yr=='5'?'selected':''}>5th Year</option>
          <option value="6" ${yr=='6'?'selected':''}>6th Year</option>
        </select>
        <select class="allot-sem-sel" data-sid="${sid}" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;font-size:.74rem;font-family:var(--font);color:var(--text);background:#fff;min-width:90px">
          <option value="">— Sem —</option>
          ${semOpts}
        </select>
        <select class="allot-sec-sel" data-sid="${sid}" style="padding:5px 8px;border:1px solid var(--border);border-radius:7px;font-size:.74rem;font-family:var(--font);color:var(--text);background:#fff;min-width:80px">
          <option value="">— Sec —</option>
          ${['A','B','C','D','E','F'].map(s=>`<option value="${s}" ${sec===s?'selected':''}>${s}</option>`).join('')}
        </select>
        <span class="allot-row-status" id="allot-status-${sid}" style="font-size:.68rem;min-width:16px"></span>
      </div>`;
  });
  list.innerHTML = html;
}

function escHtml(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

async function submitAllot() {
  const isSingle = document.getElementById('allotSingleMode').style.display !== 'none';
  const btn      = document.getElementById('allotConfirmBtn');
  const msgEl    = document.getElementById('allotMsg');
  btn.disabled   = true;
  btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  msgEl.style.display = 'none';

  if (isSingle) {
    const sid  = document.getElementById('allotSingleId').value;
    const yr   = document.getElementById('allotYear').value;
    const sec  = document.getElementById('allotSection').value.trim().toUpperCase();
    const sem  = document.getElementById('allotSemester').value;
    if (!yr || !sec || !sem) {
      msgEl.style.cssText = 'display:block;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);border-radius:8px;font-size:.78rem;color:var(--red);margin-bottom:10px';
      msgEl.innerHTML = '<i class="fas fa-circle-exclamation"></i> Please select year, semester and section.';
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Allotment';
      return;
    }
    const fd = new FormData();
    fd.append('action','allot_student'); fd.append('student_id',sid);
    fd.append('study_year',yr); fd.append('section',sec); fd.append('current_semester',sem);
    try {
      const r = await fetch('students.php', {method:'POST', body:fd});
      const d = await r.json();
      msgEl.style.cssText = d.ok
        ? 'display:block;padding:9px 13px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.22);border-radius:8px;font-size:.78rem;color:var(--green);margin-bottom:10px'
        : 'display:block;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);border-radius:8px;font-size:.78rem;color:var(--red);margin-bottom:10px';
      msgEl.innerHTML = (d.ok ? '<i class="fas fa-circle-check"></i> ' : '<i class="fas fa-circle-exclamation"></i> ') + d.msg;
      if (d.ok) {
        // Update the row in the table live
        const row = document.querySelector(`tr[data-sid="${sid}"]`);
        if (row) { row.dataset.year = d.study_year; row.dataset.sec = d.section; row.dataset.sem = d.current_semester; updateRowBadges(row, d.study_year, d.section, d.current_semester); }
        showToast('Allotment saved!');
        setTimeout(closeAllotModal, 1800);
      }
    } catch(e) { msgEl.style.cssText='display:block;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);border-radius:8px;font-size:.78rem;color:var(--red);margin-bottom:10px'; msgEl.innerHTML='<i class="fas fa-circle-exclamation"></i> Network error.'; }
    btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Allotment';
  } else {
    // Bulk: gather each row
    const yrSels  = document.querySelectorAll('.allot-yr-sel');
    const tasks = [];
    yrSels.forEach(sel => {
      const sid = sel.dataset.sid;
      const yr  = sel.value;
      const sem = document.querySelector(`.allot-sem-sel[data-sid="${sid}"]`)?.value || '';
      const sec = document.querySelector(`.allot-sec-sel[data-sid="${sid}"]`).value;
      if (yr && sec && sem) tasks.push({sid, yr, sec, sem});
    });
    if (!tasks.length) {
      msgEl.style.cssText='display:block;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);border-radius:8px;font-size:.78rem;color:var(--red);margin-bottom:10px';
      msgEl.innerHTML='<i class="fas fa-circle-exclamation"></i> No year+semester+section set for any student.';
      btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save All';
      return;
    }
    let saved=0, failed=0;
    for (const t of tasks) {
      const fd=new FormData();
      fd.append('action','allot_student'); fd.append('student_id',t.sid);
      fd.append('study_year',t.yr); fd.append('section',t.sec); fd.append('current_semester',t.sem);
      try {
        const r=await fetch('students.php',{method:'POST',body:fd});
        const d=await r.json();
        const statusEl=document.getElementById(`allot-status-${t.sid}`);
        if (d.ok) {
          saved++;
          if(statusEl){ statusEl.innerHTML='<i class="fas fa-check-circle" style="color:var(--green)"></i>'; }
          const row=document.querySelector(`tr[data-sid="${t.sid}"]`);
          if(row){ row.dataset.year=d.study_year; row.dataset.sec=d.section; row.dataset.sem=d.current_semester; updateRowBadges(row,d.study_year,d.section,d.current_semester); }
        } else {
          failed++;
          if(statusEl){ statusEl.innerHTML='<i class="fas fa-xmark-circle" style="color:var(--red)"></i>'; }
        }
      } catch { failed++; }
    }
    msgEl.style.cssText='display:block;padding:9px 13px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.22);border-radius:8px;font-size:.78rem;color:var(--green);margin-bottom:10px';
    msgEl.innerHTML=`<i class="fas fa-circle-check"></i> ${saved} student(s) allotted${failed?' — '+failed+' failed':''}.`;
    showToast(saved + ' allotment(s) saved!');
    btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save All';
  }
}

const yearLabels = {1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th',6:'6th'};
function updateRowBadges(row, yr, sec, sem) {
  const cell = row.querySelector('.year-section-cell');
  if (!cell) return;
  yr  = parseInt(yr)  || 0;
  sem = parseInt(sem) || 0;
  sec = sec || '';
  if (!yr && !sec && !sem) {
    cell.innerHTML = '<span style="color:var(--muted);font-size:.75rem">Not allotted</span>'; return;
  }
  let html = '<div style="display:flex;flex-direction:column;gap:3px">';
  if (yr)  html += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(124,58,237,.10);border:1px solid rgba(124,58,237,.22);color:#7c3aed;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content"><i class="fas fa-layer-group" style="font-size:.6rem"></i>${yearLabels[yr]||yr} Year</span>`;
  if (sem) html += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:#d97706;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content"><i class="fas fa-graduation-cap" style="font-size:.6rem"></i>Sem ${sem}</span>`;
  if (sec) html += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.22);color:var(--teal);padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;width:fit-content"><i class="fas fa-users" style="font-size:.6rem"></i>Sec ${sec}</span>`;
  html += '</div>';
  cell.innerHTML = html;
}

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res  = await fetch('../auth/auth_handler.php',{
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=logout'
    });
    const data = await res.json();
    if(data.redirect) window.location.href = data.redirect;
  } catch { window.location.href = '../login.php'; }
}

/* ── Avatar dropdown ─────────────────────────────────────────────────────────── */
function toggleAvatarMenu(e){
  e.stopPropagation();
  const avatar=document.getElementById('topbarAvatar');
  const drop=document.getElementById('avatarDropdown');
  const open=drop.classList.toggle('open');
  avatar.classList.toggle('open',open);
}
document.addEventListener('click',function(e){
  const wrap=document.querySelector('.topbar-avatar-wrap');
  if(wrap && !wrap.contains(e.target)){
    document.getElementById('avatarDropdown').classList.remove('open');
    document.getElementById('topbarAvatar').classList.remove('open');
  }
});
</script>
<!-- ══ ALLOT STUDENTS MODAL ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="allotModal" role="dialog" aria-modal="true">
  <div class="modal" style="max-width:620px">
    <div class="modal-header">
      <div class="modal-icon" style="background:rgba(124,58,237,.12);border-color:rgba(124,58,237,.22);color:#7c3aed"><i class="fas fa-layer-group"></i></div>
      <h2 id="allotModalTitle">Allot Year &amp; Section</h2>
      <button class="modal-close" onclick="closeAllotModal()" title="Close"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">

      <!-- Feedback msg -->
      <div id="allotMsg" style="display:none;margin-bottom:10px"></div>

      <!-- ── Single-student mode ── -->
      <div id="allotSingleMode" style="display:none">
        <div style="background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:.8rem;color:#7c3aed;display:flex;align-items:center;gap:9px">
          <i class="fas fa-user-graduate"></i>
          <span id="allotSingleName" style="font-weight:700"></span>
        </div>
        <input type="hidden" id="allotSingleId">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label"><i class="fas fa-layer-group" style="color:#7c3aed"></i> Year</label>
            <select id="allotYear" class="form-control">
              <option value="">— Select Year —</option>
              <option value="1">1st Year</option>
              <option value="2">2nd Year</option>
              <option value="3">3rd Year</option>
              <option value="4">4th Year</option>
              <option value="5">5th Year</option>
              <option value="6">6th Year</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><i class="fas fa-users" style="color:var(--teal)"></i> Section</label>
            <select id="allotSection" class="form-control">
              <option value="">— Select Section —</option>
              <option value="A">Section A</option>
              <option value="B">Section B</option>
              <option value="C">Section C</option>
              <option value="D">Section D</option>
              <option value="E">Section E</option>
              <option value="F">Section F</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><i class="fas fa-graduation-cap" style="color:#f59e0b"></i> Semester</label>
            <select id="allotSemester" class="form-control">
              <option value="">— Select Sem —</option>
              <option value="1">Semester 1</option>
              <option value="2">Semester 2</option>
              <option value="3">Semester 3</option>
              <option value="4">Semester 4</option>
              <option value="5">Semester 5</option>
              <option value="6">Semester 6</option>
              <option value="7">Semester 7</option>
              <option value="8">Semester 8</option>
              <option value="9">Semester 9</option>
              <option value="10">Semester 10</option>
            </select>
          </div>
        </div>
      </div>

      <!-- ── Bulk mode ── -->
      <div id="allotBulkMode">
        <div style="background:rgba(124,58,237,.05);border:1px solid rgba(124,58,237,.14);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:.76rem;color:#7c3aed;display:flex;align-items:flex-start;gap:8px">
          <i class="fas fa-circle-info" style="margin-top:2px;flex-shrink:0"></i>
          <span>Set year, semester and section for each student below. Only rows where all three are selected will be saved.</span>
        </div>
        <div style="max-height:340px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:4px 0" id="allotBulkList">
        </div>
      </div>

    </div><!-- /modal-body -->
    <div class="modal-footer">
      <button type="button" class="btn-secondary" onclick="closeAllotModal()">
        <i class="fas fa-xmark"></i> Cancel
      </button>
      <button type="button" id="allotConfirmBtn" onclick="submitAllot()"
        style="background:#7c3aed;color:#fff;border:none;padding:9px 20px;border-radius:10px;font-size:.82rem;font-weight:700;font-family:var(--font);cursor:pointer;display:flex;align-items:center;gap:7px;transition:background .18s"
        onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
        <i class="fas fa-save"></i> Save Allotment
      </button>
    </div>
  </div>
</div>

<style>
.allot-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 14px;
  border-bottom: 1px solid var(--border);
  transition: background .13s;
}
.allot-row:last-child { border-bottom: none; }
.allot-row:hover { background: rgba(124,58,237,.03); }
.allot-student-name {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
  font-size: .8rem;
  font-weight: 600;
  color: var(--text);
  overflow: hidden;
}
.allot-student-name span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>

<!-- ══ STUDENT DETAIL MODAL ══════════════════════════════════════════════ -->
<div id="studentDetailModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeStudentDetailModal()">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.45);overflow:hidden;animation:slideUp .22s ease both">
    <!-- Header -->
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px">
      <div id="sdm-avatar" style="width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;color:#fff;flex-shrink:0"></div>
      <div style="flex:1;min-width:0">
        <div id="sdm-name" style="font-size:1rem;font-weight:700;color:var(--text)"></div>
        <div id="sdm-roll" style="font-size:0.74rem;color:var(--muted);font-family:var(--mono);margin-top:2px"></div>
      </div>
      <div id="sdm-status"></div>
      <button onclick="closeStudentDetailModal()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px;margin-left:4px"><i class="fas fa-xmark"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:14px" id="sdm-body">
    </div>
  </div>
</div>

<script>
function openStudentDetailModal(s) {
  const colors = ['#00d4bb','#f59e0b','#8b5cf6','#3b82f6','#10b981','#ef4444','#ec4899','#f97316'];
  const col = colors[(s.full_name.charCodeAt(0)||65) % colors.length];
  const parts = s.full_name.trim().split(/\s+/);
  const ini = ((parts[0]||'')[0]||('')).toUpperCase() + ((parts[1]||'')[0]||'').toUpperCase();

  document.getElementById('sdm-avatar').style.background = col;
  document.getElementById('sdm-avatar').textContent = ini;
  document.getElementById('sdm-name').textContent = s.full_name;
  document.getElementById('sdm-roll').textContent = s.roll_number ? 'Roll: ' + s.roll_number : 'No roll number assigned';

  const stColors = {active:'rgba(16,185,129,.12)',pending:'rgba(245,158,11,.12)',inactive:'rgba(239,68,68,.12)'};
  const stText   = {active:'#10b981',pending:'#f59e0b',inactive:'#ef4444'};
  document.getElementById('sdm-status').innerHTML = `<span style="padding:4px 12px;border-radius:20px;font-size:0.73rem;font-weight:700;background:${stColors[s.status]||stColors.inactive};color:${stText[s.status]||stText.inactive}">${s.status.toUpperCase()}</span>`;

  const yearL = {1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th',6:'6th'};
  const yssHtml = (s.study_year||s.current_semester||s.section) ? `
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      ${s.study_year ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.22);color:#7c3aed;padding:3px 10px;border-radius:12px;font-size:0.72rem;font-weight:700"><i class="fas fa-layer-group" style="font-size:0.62rem"></i>${yearL[s.study_year]||s.study_year} Year</span>` : ''}
      ${s.current_semester ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.28);color:#d97706;padding:3px 10px;border-radius:12px;font-size:0.72rem;font-weight:700"><i class="fas fa-graduation-cap" style="font-size:0.62rem"></i>Sem ${s.current_semester}</span>` : ''}
      ${s.section ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.22);color:var(--teal);padding:3px 10px;border-radius:12px;font-size:0.72rem;font-weight:700"><i class="fas fa-users" style="font-size:0.62rem"></i>Section ${s.section}</span>` : ''}
    </div>` : '<span style="color:var(--muted);font-size:0.77rem">Not allotted yet</span>';

  const fields = [
    {icon:'fa-envelope',label:'Email',val:s.email||'—',full:true},
    {icon:'fa-phone',label:'Phone',val:s.phone||'—'},
    {icon:'fa-at',label:'Username',val:s.username||'—',mono:true},
    {icon:'fa-sitemap',label:'Department',val:(s.dept_name||'—')+(s.dept_code?' ('+s.dept_code+')':'')},
    {icon:'fa-university',label:'College',val:s.college_name||'—'},
    {icon:'fa-calendar',label:'Joined',val:s.created_at ? new Date(s.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'},
    {icon:'fa-layer-group',label:'Academic',val:null,html:yssHtml,full:true},
  ];

  document.getElementById('sdm-body').innerHTML = fields.map(f => `
    <div style="grid-column:${f.full ? '1 / -1' : 'auto'};background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px">
      <div style="font-size:0.7rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:6px;margin-bottom:6px">
        <i class="fas ${f.icon}" style="font-size:0.65rem"></i>${f.label}
      </div>
      <div style="font-size:0.84rem;font-weight:600;color:var(--text);${f.mono?'font-family:var(--mono);color:var(--teal)':''}word-break:break-all">
        ${f.html ?? f.val}
      </div>
    </div>`).join('');

  const modal = document.getElementById('studentDetailModal');
  modal.style.display = 'flex';
}
function closeStudentDetailModal() {
  document.getElementById('studentDetailModal').style.display = 'none';
}
</script>

</body>
</html>