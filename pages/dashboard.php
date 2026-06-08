<?php
// pages/dashboard.php
// ─────────────────────────────────────────────────────────────────────────────
// FACULTY DASHBOARD – multi-assignment aware.
// All data is driven by faculty_assignments for the logged-in faculty so
// courses / students / exams across EVERY assigned department are shown.
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']            ?? 'faculty';
$userId    = (int)($user['id']        ?? 0);
$collegeId = (int)($user['college_id']    ?? 0);   // primary college (fallback)
$deptId    = (int)($user['department_id'] ?? 0);   // primary dept   (fallback)
$fullName  = $user['full_name']       ?? 'Faculty';
$firstName = explode(' ', trim($fullName))[0];

// Role gate – only faculty / college_admin / super_admin reach this page
if (!in_array($role, ['faculty', 'college_admin', 'super_admin'])) {
    header('Location: students.php');
    exit;
}

// ── Scoped data defaults ──────────────────────────────────────────────────────
$collegeName     = 'Your College';
$collegeCode     = '';
$collegeEmail    = '';
$collegePhone    = '';
$collegeEst      = '';
$deptName        = 'Your Department';
$deptCode        = '';
$hodName         = '';
$myStudents      = 0;
$myCourses       = 0;
$myAttendancePct = 0.0;
$pendingMarks    = 0;
$upcomingExams   = 0;
$totalDepts      = 0;
$myTimetable     = [];
$recentStudents  = [];
$announcements   = [];
$myCourseList    = [];
$recentMarks     = [];
$upcomingExamList= [];

// Multi-department support
// Each entry: ['dept_id'=>int,'college_id'=>int,'dept_name'=>str,'dept_code'=>str,'hod_name'=>str,'college_name'=>str]
$myAssignments   = [];  // full row per distinct dept
$myDeptIds       = [];
$myCollegeIds    = [];
$myDeptNames     = [];  // display list
$myDeptDetails   = [];  // dept_id => ['name','code','hod_name'] for multi-HOD display

$db = getDB();

// ── Load scope: faculty_assignments for faculty; college depts for admin ─────
$isFaculty      = ($role === 'faculty');
$isCollegeAdmin = ($role === 'college_admin');
$isSuperAdmin   = ($role === 'super_admin');

if ($db && $userId) {

    // ── FACULTY: scope comes from faculty_assignments ─────────────────────
    if ($isFaculty) {
        $stAsgn = $db->prepare('
            SELECT DISTINCT fa.department_id, fa.college_id,
                   d.name AS dept_name, d.code AS dept_code, d.hod_name,
                   c.name AS college_name, c.code AS college_code,
                   c.email AS college_email, c.phone AS college_phone,
                   c.established AS college_est,
                   fa.is_primary
            FROM   faculty_assignments fa
            JOIN   departments d ON d.id = fa.department_id
            JOIN   colleges    c ON c.id = fa.college_id
            WHERE  fa.faculty_id = ? AND fa.status = "active"
            ORDER  BY fa.is_primary DESC, d.name ASC
        ');
        $stAsgn->execute([$userId]);
        $asgRows = $stAsgn->fetchAll(PDO::FETCH_ASSOC);

        foreach ($asgRows as $ar) {
            $did = (int)$ar['department_id'];
            $cid = (int)$ar['college_id'];
            if (!in_array($did, $myDeptIds))    $myDeptIds[]   = $did;
            if (!in_array($cid, $myCollegeIds)) $myCollegeIds[] = $cid;
            if (!in_array($ar['dept_name'], $myDeptNames)) $myDeptNames[] = $ar['dept_name'];
            $myDeptDetails[$did] = [
                'name'     => $ar['dept_name'],
                'code'     => $ar['dept_code'],
                'hod_name' => $ar['hod_name'] ?? '',
            ];
            if ($ar['is_primary'] && empty($myAssignments)) {
                $collegeName  = $ar['college_name'];
                $collegeCode  = $ar['college_code'];
                $collegeEmail = $ar['college_email'] ?? '';
                $collegePhone = $ar['college_phone'] ?? '';
                $collegeEst   = $ar['college_est']   ?? '';
                $deptName     = $ar['dept_name'];
                $deptCode     = $ar['dept_code'];
                $hodName      = $ar['hod_name'] ?? '';
            }
            $myAssignments[] = $ar;
        }

        // Faculty fallback: no assignments yet → use users.department_id
        if (empty($myDeptIds) && $deptId)       $myDeptIds[]    = $deptId;
        if (empty($myCollegeIds) && $collegeId)  $myCollegeIds[] = $collegeId;

    // ── COLLEGE ADMIN / SUPER ADMIN: scope comes from college departments ─
    } else {
        // Determine which college(s) to scope to
        $scopeCollegeIds = $isSuperAdmin ? [] : [$collegeId];   // empty = all colleges for superadmin
        if (!empty($scopeCollegeIds)) $myCollegeIds = $scopeCollegeIds;

        // Load all departments in the scoped college(s)
        if ($isSuperAdmin) {
            $stDepts = $db->prepare(
                "SELECT d.id, d.name, d.code, d.hod_name, d.college_id,
                        c.name AS college_name, c.code AS college_code,
                        c.email AS college_email, c.phone AS college_phone,
                        c.established AS college_est
                 FROM   departments d JOIN colleges c ON c.id = d.college_id
                 WHERE  d.status = 'active'
                 ORDER  BY c.name, d.name"
            );
            $stDepts->execute();
        } else {
            $stDepts = $db->prepare(
                "SELECT d.id, d.name, d.code, d.hod_name, d.college_id,
                        c.name AS college_name, c.code AS college_code,
                        c.email AS college_email, c.phone AS college_phone,
                        c.established AS college_est
                 FROM   departments d JOIN colleges c ON c.id = d.college_id
                 WHERE  d.college_id = ? AND d.status = 'active'
                 ORDER  BY d.name"
            );
            $stDepts->execute([$collegeId]);
        }
        $deptRows = $stDepts->fetchAll(PDO::FETCH_ASSOC);

        $firstRow = true;
        foreach ($deptRows as $dr) {
            $did = (int)$dr['id'];
            $cid = (int)$dr['college_id'];
            if (!in_array($did, $myDeptIds))    $myDeptIds[]   = $did;
            if (!in_array($cid, $myCollegeIds)) $myCollegeIds[] = $cid;
            if (!in_array($dr['name'], $myDeptNames)) $myDeptNames[] = $dr['name'];
            $myDeptDetails[$did] = [
                'name'     => $dr['name'],
                'code'     => $dr['code'],
                'hod_name' => $dr['hod_name'] ?? '',
            ];
            // Use first dept's college info for the header
            if ($firstRow) {
                $collegeName  = $dr['college_name'];
                $collegeCode  = $dr['college_code'];
                $collegeEmail = $dr['college_email'] ?? '';
                $collegePhone = $dr['college_phone'] ?? '';
                $collegeEst   = $dr['college_est']   ?? '';
                $deptName     = $dr['name'];
                $deptCode     = $dr['code'];
                $hodName      = $dr['hod_name'] ?? '';
                $firstRow     = false;
            }
        }
    }

    $primaryCollegeId = $myCollegeIds[0] ?? $collegeId;
    $primaryDeptId    = $myDeptIds[0]    ?? $deptId;

    // Fill college info from DB if still unset
    if ($collegeName === 'Your College' && $primaryCollegeId) {
        $st = $db->prepare(
            'SELECT name, code, email, phone, established
             FROM   colleges WHERE id = ? AND status = "active" LIMIT 1'
        );
        $st->execute([$primaryCollegeId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $collegeName  = $row['name'];
            $collegeCode  = $row['code'];
            $collegeEmail = $row['email']       ?? '';
            $collegePhone = $row['phone']       ?? '';
            $collegeEst   = $row['established'] ?? '';
        }
    }
    if ($deptName === 'Your Department' && $primaryDeptId) {
        $st = $db->prepare('SELECT name, code, hod_name FROM departments WHERE id = ? LIMIT 1');
        $st->execute([$primaryDeptId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $deptName = $row['name'];
            $deptCode = $row['code'];
            $hodName  = $row['hod_name'] ?? '';
        }
    }

} else {
    $primaryCollegeId = $collegeId;
    $primaryDeptId    = $deptId;
}

// Build combined dept label for display
$allDeptLabel = !empty($myDeptNames) ? implode(' | ', $myDeptNames) : $deptName;

// ── Remaining queries ─────────────────────────────────────────────────────────
if ($db) {

    /* ── 1. Total departments in my assigned college(s) ──────────────────── */
    if (!empty($myCollegeIds)) {
        $ph = implode(',', array_fill(0, count($myCollegeIds), '?'));
        $st = $db->prepare(
            "SELECT COUNT(*) FROM departments
             WHERE  college_id IN ($ph) AND status = 'active'"
        );
        $st->execute($myCollegeIds);
        $totalDepts = (int)$st->fetchColumn();
    }

    /* ── 2. Active students in ALL my assigned depts ─────────────────────── */
    if (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT COUNT(*) FROM users
             WHERE  role = 'student'
               AND  department_id IN ($ph)
               AND  status = 'active'"
        );
        $st->execute($myDeptIds);
        $myStudents = (int)$st->fetchColumn();
    }

    /* ── 3. Courses: faculty_assignments for faculty; dept scope for admin ── */
    if ($isFaculty) {
        $st = $db->prepare('
            SELECT DISTINCT c.id, c.name, c.code, c.credits, c.semester,
                   d.name AS dept_name, d.code AS dept_code
            FROM   faculty_assignments fa
            JOIN   courses     c ON c.id  = fa.course_id
            JOIN   departments d ON d.id  = fa.department_id
            WHERE  fa.faculty_id = ?
              AND  fa.status     = "active"
              AND  c.status      = "active"
            ORDER  BY c.semester, d.name, c.name
            LIMIT  12
        ');
        $st->execute([$userId]);
    } elseif (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare("
            SELECT c.id, c.name, c.code, c.credits, c.semester,
                   d.name AS dept_name, d.code AS dept_code
            FROM   courses c
            JOIN   departments d ON d.id = c.department_id
            WHERE  c.department_id IN ($ph)
              AND  c.status = 'active'
            ORDER  BY c.semester, d.name, c.name
            LIMIT  12
        ");
        $st->execute($myDeptIds);
    } else {
        $st = $db->prepare('SELECT 1 WHERE 0'); // empty result
        $st->execute();
    }
    $myCourseList = $st->fetchAll(PDO::FETCH_ASSOC);
    $myCourses    = count($myCourseList);

    /* ── 4. Average attendance % ─────────────────────────────────────────── */
    if ($isFaculty) {
        $st = $db->prepare(
            "SELECT ROUND(SUM(a.status = 'P') / NULLIF(COUNT(*), 0) * 100, 1)
             FROM   attendance a
             JOIN   faculty_assignments fa ON fa.course_id = a.course_id
             WHERE  fa.faculty_id = ? AND fa.status = 'active'"
        );
        $st->execute([$userId]);
    } elseif (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT ROUND(SUM(status = 'P') / NULLIF(COUNT(*), 0) * 100, 1)
             FROM   attendance WHERE department_id IN ($ph)"
        );
        $st->execute($myDeptIds);
    } else {
        $st = null;
    }
    $myAttendancePct = $st ? (float)($st->fetchColumn() ?: 0) : 0.0;

    /* ── 5. Pending marks ─────────────────────────────────────────────────── */
    if ($isFaculty) {
        $st = $db->prepare(
            'SELECT COUNT(DISTINCT m.id)
             FROM   marks m
             JOIN   exams e  ON e.id  = m.exam_id
             JOIN   faculty_assignments fa ON fa.course_id = e.course_id
             WHERE  fa.faculty_id = ?
               AND  fa.status = "active"
               AND  m.obtained_marks IS NULL
               AND  m.is_absent = 0'
        );
        $st->execute([$userId]);
    } elseif (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT COUNT(DISTINCT m.id)
             FROM   marks m
             JOIN   exams e ON e.id = m.exam_id
             WHERE  e.department_id IN ($ph)
               AND  m.obtained_marks IS NULL
               AND  m.is_absent = 0"
        );
        $st->execute($myDeptIds);
    } else {
        $st = null;
    }
    $pendingMarks = $st ? (int)$st->fetchColumn() : 0;

    /* ── 6. Upcoming exam COUNT ───────────────────────────────────────────── */
    if ($isFaculty) {
        $st = $db->prepare(
            'SELECT COUNT(DISTINCT e.id)
             FROM   exams e
             JOIN   faculty_assignments fa ON fa.course_id = e.course_id
             WHERE  fa.faculty_id = ?
               AND  fa.status     = "active"
               AND  e.exam_date  >= CURDATE()
               AND  e.status IN ("upcoming","ongoing")'
        );
        $st->execute([$userId]);
    } elseif (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT COUNT(DISTINCT id) FROM exams
             WHERE  department_id IN ($ph)
               AND  exam_date >= CURDATE()
               AND  status IN ('upcoming','ongoing')"
        );
        $st->execute($myDeptIds);
    } else {
        $st = null;
    }
    $upcomingExams = $st ? (int)$st->fetchColumn() : 0;

    /* ── 7. Today's timetable – all depts this faculty is assigned to ──────
       Scoped by s.faculty_id so all multi-dept slots appear.               */
    $todayAbbr = date('D'); // 'Mon','Tue',…
    $st = $db->prepare(
        'SELECT
            p.start_time, p.end_time, p.label AS period_label,
            c.name  AS course_name, c.code AS course_code,
            r.name  AS room_name,
            d.name  AS dept_name, d.code AS dept_code
         FROM   tt_slots s
         JOIN   timetables tt   ON tt.id  = s.timetable_id
         JOIN   tt_periods p    ON p.id   = s.period_id
         LEFT JOIN courses      c ON c.id  = s.course_id
         LEFT JOIN tt_rooms     r ON r.id  = s.room_id
         LEFT JOIN departments  d ON d.id  = tt.department_id
         WHERE  s.faculty_id = ?
           AND  s.day        = ?
           AND  p.type       = "Period"
         ORDER  BY p.start_time'
    );
    $st->execute([$userId, $todayAbbr]);
    $myTimetable = $st->fetchAll(PDO::FETCH_ASSOC);

    /* ── 8. Recent students across all my assigned depts ────────────────── */
    if (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT u.full_name, u.email, u.roll_number, u.status, u.created_at,
                    d.name AS dept_name, d.code AS dept_code
             FROM   users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE  u.role = 'student'
               AND  u.department_id IN ($ph)
             ORDER  BY u.created_at DESC LIMIT 8"
        );
        $st->execute($myDeptIds);
        $recentStudents = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── 9. Upcoming exam list ────────────────────────────────────────────── */
    if ($isFaculty) {
        $st = $db->prepare(
            'SELECT DISTINCT
                    e.id, e.title, e.type, e.exam_date, e.max_marks, e.status,
                    c.name AS course_name, c.code AS course_code,
                    d.name AS dept_name,  d.code AS dept_code
             FROM   exams e
             JOIN   faculty_assignments fa ON fa.course_id  = e.course_id
                                          AND fa.faculty_id = ?
                                          AND fa.status     = "active"
             LEFT JOIN courses     c ON c.id = e.course_id
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE  e.exam_date >= CURDATE()
               AND  e.status IN ("upcoming","ongoing","draft")
             ORDER  BY e.exam_date ASC LIMIT 8'
        );
        $st->execute([$userId]);
    } elseif (!empty($myDeptIds)) {
        $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
        $st = $db->prepare(
            "SELECT DISTINCT e.id, e.title, e.type, e.exam_date, e.max_marks, e.status,
                    c.name AS course_name, c.code AS course_code,
                    d.name AS dept_name,  d.code AS dept_code
             FROM   exams e
             LEFT JOIN courses     c ON c.id = e.course_id
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE  e.department_id IN ($ph)
               AND  e.exam_date >= CURDATE()
               AND  e.status IN ('upcoming','ongoing','draft')
             ORDER  BY e.exam_date ASC LIMIT 8"
        );
        $st->execute($myDeptIds);
    } else {
        $st = null;
    }
    $upcomingExamList = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    /* ── 10. Recent activity log for my college (latest 5 entries) ─────── */
    if ($primaryCollegeId) {
        $st = $db->prepare(
            'SELECT al.action, al.created_at, u.full_name, u.role
             FROM   activity_log al
             JOIN   users u ON u.id = al.user_id
             WHERE  u.college_id = ?
             ORDER  BY al.created_at DESC LIMIT 5'
        );
        $st->execute([$primaryCollegeId]);
        $announcements = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── 11. Recent marks – DISTINCT to avoid duplication from multi-assign */
    $st = $db->prepare(
        'SELECT DISTINCT
                m.id, m.obtained_marks, m.grade, m.percentage, m.is_pass,
                u.full_name AS student_name,
                e.title     AS exam_title, e.type AS exam_type,
                m.updated_at,
                d.name AS dept_name, d.code AS dept_code
         FROM   marks m
         JOIN   exams   e  ON e.id  = m.exam_id
         JOIN   users   u  ON u.id  = m.student_id
         JOIN   faculty_assignments fa ON fa.course_id = e.course_id
                                      AND fa.faculty_id = ?
                                      AND fa.status     = "active"
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE  m.obtained_marks IS NOT NULL
         ORDER  BY m.updated_at DESC LIMIT 8'
    );
    $st->execute([$userId]);
    $recentMarks = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function fmtDate($d): string {
    return ($d && $d !== '0000-00-00') ? date('d M Y', strtotime($d)) : '—';
}
function fmtTime($t): string {
    return $t ? date('h:i A', strtotime($t)) : '—';
}
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$avatarInitials = initials($fullName);

// ── Fetch avatar photo & color for the logged-in user ────────────────────────
$avatarPath   = '';
$avatarColor  = '#e8a820';
$userEmail    = '';
$userPhone    = '';
$userDesig    = '';
if ($db && $userId) {
    $stAv = $db->prepare('SELECT avatar_path, avatar_color, email, phone, designation FROM users WHERE id = ? LIMIT 1');
    $stAv->execute([$userId]);
    if ($avRow = $stAv->fetch(PDO::FETCH_ASSOC)) {
        $avatarColor = $avRow['avatar_color'] ?: '#e8a820';
        $userEmail   = $avRow['email']       ?? '';
        $userPhone   = $avRow['phone']       ?? '';
        $userDesig   = $avRow['designation'] ?? '';
        $rawPath     = $avRow['avatar_path']  ?? '';
        // avatar_path stored as "uploads/profile_photos/filename.jpg"
        // dashboard.php is in pages/ so the file root is one level up
        if ($rawPath && file_exists(__DIR__ . '/../' . $rawPath)) {
            $avatarPath = '../' . $rawPath . '?v=' . time();
        }
    }
}

// Per-dept student counts
$deptStudentCounts = [];
if ($db && !empty($myDeptIds)) {
    $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
    $st = $db->prepare(
        "SELECT department_id, COUNT(*) AS cnt
         FROM   users
         WHERE  role = 'student' AND status = 'active' AND department_id IN ($ph)
         GROUP  BY department_id"
    );
    $st->execute($myDeptIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $deptStudentCounts[(int)$r['department_id']] = (int)$r['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduNexus — <?= esc($allDeptLabel) ?> · <?= esc($collegeName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ─────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === TEAL · LIGHT TEAL · SLATE · AMBER PALETTE === */
  --teal:#0F766E;              /* Primary teal */
  --teal-dark:#0D5C56;         /* Deep teal */
  --teal-mid:#0F766E;
  --teal-light:#14B8A6;        /* Secondary light teal */
  --teal-soft:rgba(20,184,166,.10);
  --teal-soft2:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;         /* Accent amber */
  --amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);
  --amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;           /* Cool slate background */
  --success:#16A34A;
  --green2:rgba(22,163,74,.12);

  /* Status */
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);

  /* Text */
  --white:#FFFFFF;
  --text:#0F172A;
  --text-muted:#475569;
  --text-light:#94A3B8;
  --border:rgba(15,118,110,.10);
  --border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;
  --card-hover:#F0FDFA;

  --sidebar-w:264px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;

  /* Sidebar glassmorphic vars (sidebar is deep teal) */
  --sb-text:rgba(255,255,255,.78);
  --sb-text-active:#FFFFFF;
  --sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);
  --sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);
  --sb-active-border:rgba(245,158,11,.38);

  /* Alias for shared component references */
  --accent:var(--teal-light);
  --accent2:var(--teal);
  --accent3:var(--teal-soft);
  --accent4:var(--teal-soft2);
  --indigo:var(--teal-mid);
  --indigo2:var(--teal-soft);
}

html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* ── Cool slate grid bg ─────────────────────────────────────────────────────── */
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%, rgba(20,184,166,.07) 0%, transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;
}

/* ── Layout ─────────────────────────────────────────────────────────────────── */
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── SIDEBAR — Glassmorphic Deep Teal ───────────────────────────────────────── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(
    168deg,
    rgba(13,92,86,.98) 0%,
    rgba(15,118,110,.95) 40%,
    rgba(17,140,130,.92) 72%,
    rgba(13,92,86,.98) 100%
  );
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:
    inset -1px 0 0 rgba(255,255,255,.05),
    inset  1px 0 0 rgba(20,184,166,.08),
    2px 0 50px rgba(15,118,110,.45),
    8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease,width .3s ease;overflow:hidden;
}

/* Amber shimmer top edge */
.sidebar::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);
  pointer-events:none;
}
/* Teal glow on right edge */
.sidebar::after{
  content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);
  pointer-events:none;
}

/* Scrollable section */
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}

.sidebar-logo{
  padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);
  display:flex;align-items:center;gap:12px;flex-shrink:0;
  background:rgba(0,0,0,.12);
}
.logo-mark{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;
  box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15);
}
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
/* subtle shimmer bar on top */
.scope-chip::before{
  content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent);
}
.sc-label{
  font-size:.52rem;color:rgba(94,234,212,.90);text-transform:uppercase;
  letter-spacing:.16em;font-weight:700;margin-bottom:6px;
  display:flex;align-items:center;gap:5px;
}
.sc-label i{ font-size:.54rem; }

/* College name — large, bold, white */
.sc-college{
  font-size:.86rem;font-weight:800;color:#FFFFFF;
  line-height:1.25;margin-bottom:7px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  letter-spacing:.01em;
}

/* Dept row(s) */
.sc-depts{display:flex;flex-direction:column;gap:4px;margin-top:0}
.sc-dept-tag{
  font-size:.72rem;color:rgba(186,230,253,.90);
  display:inline-flex;align-items:center;gap:5px;
  font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  max-width:100%;
}
.sc-dept-tag i{
  font-size:.62rem;flex-shrink:0;
  color:rgba(94,234,212,.80);
}

/* Nav */
.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;border-radius:9px;margin-bottom:1px;
  color:var(--sb-text);font-size:.82rem;font-weight:500;
  cursor:pointer;text-decoration:none;transition:all .18s;position:relative;
  border:1px solid transparent;
}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{
  background:var(--sb-active-bg);
  color:var(--amber-acc);
  border-color:var(--sb-active-border);
  box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10);
}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--amber-acc);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.nav-badge.amber{background:var(--amber);color:#fff}

/* Sidebar user */
.sidebar-user{
  padding:12px 14px;border-top:1px solid var(--sb-border);
  display:flex;align-items:center;gap:10px;flex-shrink:0;
  background:rgba(0,0,0,.16);
  backdrop-filter:blur(10px);
}
.user-avatar{
  width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;
  box-shadow:0 2px 10px rgba(245,158,11,.28);
}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.63rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}

/* ── Sidebar collapse button (expanded state) ───────────────────────────────── */
.sb-collapse-btn{
  margin-left:auto;
  flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);
  cursor:pointer;
  display:grid;place-items:center;
  font-size:.68rem;
  transition:all .2s;
}
.sb-collapse-btn:hover{
  background:rgba(245,158,11,.22);
  border-color:rgba(245,158,11,.50);
  color:var(--amber-acc);
}

/* ── Collapsed sidebar — wider rail so button is always visible ─────────────── */
:root{ --sb-collapsed-w: 72px; }

.sidebar.collapsed{ width:var(--sb-collapsed-w); }

/* ── Logo row when collapsed: stack EN mark on top line, toggle below ─────── */
.sidebar.collapsed .sidebar-logo{
  padding: 12px 10px 10px;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.sidebar.collapsed .logo-mark{
  width:34px; height:34px; font-size:.78rem; margin:0;
}
.sidebar.collapsed .logo-text{
  display:none !important;
}
/* Make collapse button full-width so it's easy to click */
.sidebar.collapsed .sb-collapse-btn{
  margin:0;
  width:38px; height:22px;
  border-radius:6px;
  font-size:.6rem;
}

/* ── Active item when collapsed: strip ALL amber styling, show plain icon ─── */
.sidebar.collapsed .nav-item.active::before{
  display:none !important;
  content:none !important;
}
.sidebar.collapsed .nav-item.active,
.sidebar.collapsed .nav-item.active:hover{
  background: rgba(255,255,255,.12) !important;
  border-color: rgba(255,255,255,.15) !important;
  box-shadow: none !important;
  color: #ffffff !important;
}
.sidebar.collapsed .nav-item.active i,
.sidebar.collapsed .nav-item.active:hover i,
.sidebar.collapsed a.nav-item.active i{
  color: #ffffff !important;
  opacity: 1 !important;
  visibility: visible !important;
  -webkit-text-fill-color: #ffffff !important;
}

/* ── Scope chip: completely gone, zero footprint ─────────────────────────── */
.sidebar.collapsed .scope-chip,
.sidebar.collapsed .scope-chip *,
.sidebar.collapsed .scope-chip::before{
  display:none !important;
  height:0 !important;
  max-height:0 !important;
  padding:0 !important;
  margin:0 !important;
  border:none !important;
  overflow:hidden !important;
  visibility:hidden !important;
  opacity:0 !important;
}

/* ── Nav section labels: gone ────────────────────────────────────────────── */
.sidebar.collapsed .nav-label{
  display:none !important;
  height:0 !important;
  margin:0 !important;
  padding:0 !important;
  overflow:hidden !important;
}

/* ── Nav items: icon only, centred ──────────────────────────────────────── */
.sidebar.collapsed .sidebar-nav{
  padding: 6px 8px 16px;
  /* scope chip (~90px) + nav label "MAIN" (~32px) = ~122px lost — restore with margin-top */
  margin-top: 46px;
}
.sidebar.collapsed .nav-item{
  display: flex !important;
  justify-content: center !important;
  align-items: center !important;
  padding: 10px 0 !important;
  gap: 0 !important;
  width: 100%;
  overflow: hidden;
  border-radius: 10px;
}
/* icon perfectly centred */
.sidebar.collapsed .nav-item i{
  width: 20px;
  text-align: center;
  font-size: .9rem;
  flex-shrink: 0;
  margin: 0;
  padding: 0;
}
/* kill text & badge */
.sidebar.collapsed .nav-text,
.sidebar.collapsed .nav-badge{
  display:none !important;
  width:0 !important; height:0 !important;
  overflow:hidden !important; padding:0 !important; margin:0 !important;
}

/* ── Bottom user bar ─────────────────────────────────────────────────────── */
.sidebar.collapsed .sidebar-user{
  padding: 10px 0;
  justify-content: center;
  gap: 0;
}
.sidebar.collapsed .user-info,
.sidebar.collapsed .logout-btn{
  display:none !important;
  width:0 !important; overflow:hidden !important;
}
.sidebar.collapsed .user-avatar{
  margin:0 auto; flex-shrink:0;
}

/* ── Tooltip labels on hover ─────────────────────────────────────────────── */
.sidebar.collapsed .nav-item::after{
  content: attr(data-tip);
  position: absolute;
  left: calc(100% + 10px);
  top: 50%; transform: translateY(-50%);
  background: #0F172A;
  color: #fff;
  font-size: .71rem; font-weight:500; font-family:var(--font);
  padding: 5px 11px;
  border-radius: 7px;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: opacity .15s;
  z-index: 9999;
  box-shadow: 0 4px 18px rgba(0,0,0,.35);
  border: 1px solid rgba(255,255,255,.08);
}
.sidebar.collapsed .nav-item:hover::after{ opacity:1; }

/* ── Main content area transitions ──────────────────────────────────────── */
.main{
  margin-left: var(--sidebar-w);
  flex:1; display:flex; flex-direction:column;
  min-height:100vh; min-width:0;
  width: calc(100% - var(--sidebar-w));
  transition: margin-left .3s ease, width .3s ease;
}
.sidebar.collapsed ~ .main,
.shell:has(.sidebar.collapsed) .main{
  margin-left: var(--sb-collapsed-w);
  width: calc(100% - var(--sb-collapsed-w));
}

/* Topbar */
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none !important}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:#fff;font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{
  width:36px;height:36px;border-radius:9px;
  border:1px solid rgba(255,255,255,.30);
  background:rgba(255,255,255,.15);color:#ffffff;
  display:grid;place-items:center;cursor:pointer;transition:all .18s;position:relative;font-size:.82rem;
}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.notif-dot{position:absolute;top:5px;right:5px;width:6px;height:6px;background:var(--amber-acc);border-radius:50%;border:1.5px solid #0F766E}
.date-chip{height:36px;display:flex;align-items:center;font-size:.72rem;color:#ffffff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);padding:0 11px;border-radius:9px;font-family:var(--mono)}

/* ── Topbar Avatar ─────────────────────────────────────────────────────────── */
.topbar-avatar-wrap{position:relative}
.topbar-avatar{
  width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;
  cursor:pointer;border:2px solid rgba(245,158,11,.30);transition:border-color .2s,box-shadow .2s;
  user-select:none;flex-shrink:0;
}
.topbar-avatar:hover{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.topbar-avatar.open{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{
  position:absolute;top:calc(100% + 10px);right:0;
  min-width:200px;
  background:#fff;
  border:1px solid var(--border);
  border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);
  z-index:200;
  opacity:0;pointer-events:none;transform:translateY(-8px);
  transition:opacity .18s ease,transform .18s ease;
}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{
  display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;
  color:var(--text-muted);font-size:.8rem;font-weight:500;text-decoration:none;
  transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;
}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:var(--text-light);flex-shrink:0}
.ad-item:hover{background:var(--card-hover);color:var(--text)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}

/* ── Content ────────────────────────────────────────────────────────────────── */
.content{padding:20px 24px;flex:1}

/* Scope banner */
/* ══ UNIFIED HERO CARD — REDESIGN ══════════════════════════════════════════ */
.hero-card{
  position:relative;overflow:hidden;
  border-radius:20px;margin-bottom:18px;
  animation:slideUp .45s ease both;
  background:linear-gradient(135deg,#0a4f4a 0%,#0F766E 38%,#0d8f82 68%,#12a898 100%);
  box-shadow:0 8px 40px rgba(13,92,86,.38),0 2px 8px rgba(0,0,0,.10),inset 0 1px 0 rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.07);
}

/* Noise / mesh overlay */
.hero-card::before{
  content:'';position:absolute;inset:0;
  background-image:
    radial-gradient(ellipse 60% 80% at 0% 50%,rgba(255,255,255,.055) 0%,transparent 55%),
    radial-gradient(ellipse 45% 60% at 100% 0%,rgba(20,184,166,.20) 0%,transparent 50%),
    radial-gradient(ellipse 30% 40% at 75% 100%,rgba(245,158,11,.08) 0%,transparent 55%),
    url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='30' cy='30' r='1'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
  pointer-events:none;z-index:0;
}

/* Decorative top-right glow orb */
.hero-card::after{
  content:'';position:absolute;
  width:340px;height:340px;border-radius:50%;
  background:radial-gradient(circle,rgba(20,184,166,.13) 0%,transparent 65%);
  top:-100px;right:-80px;pointer-events:none;z-index:0;
}

.hero-card > *{position:relative;z-index:1}

/* ── College bar ── */
.hc-college-bar{
  display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  padding:12px 22px 11px;
  border-bottom:1px solid rgba(255,255,255,.09);
  background:rgba(0,0,0,.10);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
}
.hc-college-icon{
  width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:rgba(255,255,255,.12);backdrop-filter:blur(8px);
  color:rgba(255,255,255,.9);display:grid;place-items:center;font-size:.95rem;
  border:1px solid rgba(255,255,255,.15);
}
.hc-college-name{font-size:.9rem;font-weight:800;color:#fff;margin-bottom:3px;letter-spacing:.01em}
.hc-college-meta{font-size:.67rem;color:rgba(255,255,255,.92);display:flex;gap:12px;flex-wrap:wrap}
.hc-college-meta span{display:flex;align-items:center;gap:4px}
.hc-college-meta i{color:rgba(20,184,166,.9);font-size:.58rem}
.hc-dept-badges{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap;align-items:center;flex-shrink:0}
.hc-dept-chip{
  display:flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.10);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.18);
  border-radius:9px;padding:4px 10px;
  transition:background .2s;
}
.hc-dept-chip:hover{background:rgba(255,255,255,.16)}
.hc-dept-code{font-size:.7rem;font-weight:700;color:#fff;font-family:var(--mono)}
.hc-dept-hod{font-size:.75rem;color:#ffffff;font-weight:700;display:flex;align-items:center;gap:4px}
.hc-dept-hod i{color:rgba(20,184,166,.8);font-size:.55rem}

/* ── Body ── */
.hc-body{
  display:flex;align-items:stretch;gap:12px;
  padding:16px 18px 18px;
}

/* Glass profile panel */
.hc-profile{
  display:flex;align-items:center;gap:16px;
  flex:1 1 0;min-width:0;
  background:rgba(255,255,255,.08);
  backdrop-filter:blur(18px) saturate(160%);
  -webkit-backdrop-filter:blur(18px) saturate(160%);
  border:1px solid rgba(255,255,255,.14);
  border-radius:16px;
  padding:16px 20px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 4px 20px rgba(0,0,0,.12);
}
.hc-photo-wrap{position:relative;flex-shrink:0}
.hc-photo{
  width:82px;height:82px;border-radius:18px;object-fit:cover;display:block;
  border:3px solid rgba(255,255,255,.3);
  box-shadow:0 8px 24px rgba(0,0,0,.25),0 0 0 1px rgba(255,255,255,.08);
}
.hc-initials{
  width:82px;height:82px;border-radius:18px;
  display:grid;place-items:center;
  font-size:1.7rem;font-weight:800;color:#fff;
  background:rgba(255,255,255,.15);backdrop-filter:blur(8px);
  border:3px solid rgba(255,255,255,.25);
  box-shadow:0 8px 24px rgba(0,0,0,.2);
}
.hc-online-dot{
  position:absolute;bottom:4px;right:4px;width:13px;height:13px;
  border-radius:50%;background:#22c55e;border:2.5px solid #0F766E;
  box-shadow:0 0 0 2px rgba(34,197,94,.3);
}
.hc-identity{min-width:0}
.hc-name{font-size:1.18rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:4px;letter-spacing:.01em}
.hc-role-line{display:flex;align-items:center;gap:7px;margin-bottom:8px}
.hc-role-badge{
  font-size:.62rem;font-weight:700;font-family:var(--mono);
  background:rgba(245,158,11,.22);color:#FCD34D;
  padding:2px 8px;border-radius:5px;border:1px solid rgba(245,158,11,.35);
  display:flex;align-items:center;gap:3px;
}
.hc-desig{font-size:.74rem;color:rgba(255,255,255,.92);font-weight:500}
.hc-contact-row{display:flex;flex-wrap:wrap;gap:5px 14px;font-size:.7rem;color:rgba(255,255,255,.92)}
.hc-contact-row span{display:flex;align-items:center;gap:5px}
.hc-contact-row i{color:rgba(20,184,166,.85);font-size:.6rem;flex-shrink:0}
.hc-contact-row a{color:#fff;text-decoration:none;transition:color .15s}
.hc-contact-row a:hover{color:#fff}

/* Vertical separator — hidden (gap between glass panels serves as spacing) */
.hc-sep{ display:none; }

/* Glass greeting panel */
.hc-greeting{
  flex:0 0 auto;min-width:200px;
  background:rgba(255,255,255,.08);
  backdrop-filter:blur(18px) saturate(160%);
  -webkit-backdrop-filter:blur(18px) saturate(160%);
  border:1px solid rgba(255,255,255,.14);
  border-radius:16px;
  padding:16px 20px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 4px 20px rgba(0,0,0,.12);
  display:flex;flex-direction:column;justify-content:space-between;
}
.hc-greet-text{font-size:1.05rem;font-weight:700;color:#fff;margin-bottom:4px;letter-spacing:.01em}
.hc-greet-text strong{color:#ffffff;font-weight:800}
.hc-greet-sub{
  font-size:.72rem;color:rgba(255,255,255,.92);
  display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap;
}
.hc-greet-sub strong{color:#fff}
.hc-greet-sub i{font-size:.72rem;flex-shrink:0}
.hc-stats-row{display:flex;align-items:stretch;gap:0;width:100%;
  background:rgba(0,0,0,.15);
  border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden;
}
.hc-stat{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  flex:1;padding:8px 10px;gap:2px;cursor:default;transition:background .2s;
}
.hc-stat:hover{background:rgba(255,255,255,.06)}
.hc-stat span{font-size:1.05rem;font-weight:800;color:#5EEAD4;font-family:var(--mono);line-height:1}
.hc-stat-label{font-size:.56rem;color:rgba(255,255,255,.88);text-transform:uppercase;letter-spacing:.1em;margin-top:1px}
.hc-stat-sep{width:1px;background:rgba(255,255,255,.1);align-self:stretch;margin:6px 0}

/* Right: clock + edit */
.hc-right{
  display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;
  gap:12px;flex-shrink:0;
  background:rgba(255,255,255,.08);
  backdrop-filter:blur(18px) saturate(160%);
  -webkit-backdrop-filter:blur(18px) saturate(160%);
  border:1px solid rgba(255,255,255,.14);
  border-radius:16px;
  padding:16px 18px;
  min-width:148px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 4px 20px rgba(0,0,0,.12);
}
.hc-clock{text-align:right}
.hc-time{font-family:var(--mono);font-size:1.8rem;font-weight:500;color:#fff;line-height:1;letter-spacing:.02em}
.hc-date{font-size:.62rem;color:rgba(255,255,255,.92);margin-top:3px;letter-spacing:.02em}
.hc-edit-btn{
  font-size:.67rem;font-weight:600;color:#fff;
  border:1px solid rgba(255,255,255,.2);border-radius:8px;
  padding:6px 13px;text-decoration:none;display:flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.08);backdrop-filter:blur(8px);
  transition:all .2s;white-space:nowrap;
}
.hc-edit-btn:hover{background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.35)}

/* Responsive */
@media(max-width:1100px){
  .hc-greeting{min-width:170px}
  .hc-stat{padding:8px 8px}
}
@media(max-width:900px){
  .hc-body{flex-wrap:wrap;gap:10px;padding:14px 14px 16px}
  .hc-profile{flex:1 1 100%;width:100%;order:1}
  .hc-greeting{flex:1 1 0;order:2}
  .hc-right{order:3;align-self:stretch}
  .hc-clock{text-align:right}
}
@media(max-width:640px){
  .hc-college-bar{gap:8px}
  .hc-dept-badges{margin-left:0;width:100%}
  .hc-photo,.hc-initials{width:64px;height:64px;font-size:1.3rem;border-radius:14px}
  .hc-time{font-size:1.4rem}
  .hc-body{flex-direction:column}
  .hc-right{flex-direction:row;align-items:center;width:100%;min-width:0}
  .hc-clock{text-align:left}
}
@media(max-width:480px){
  .hc-greet-sub{flex-wrap:wrap}
  .hc-stat{padding:8px 8px}
}

/* ── Stats grid ─────────────────────────────────────────────────────────────── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 18px;display:flex;flex-direction:column;gap:12px;
  animation:slideUp .4s ease both;transition:transform .2s,box-shadow .2s;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.stat:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(15,118,110,.10),0 2px 8px rgba(0,0,0,.05)}
.stat:nth-child(1){animation-delay:.10s}.stat:nth-child(2){animation-delay:.15s}
.stat:nth-child(3){animation-delay:.20s}.stat:nth-child(4){animation-delay:.25s}
.stat-top{display:flex;align-items:flex-start;justify-content:space-between}
.stat-icon{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.si-teal{background:var(--teal-soft);color:var(--teal)}
.si-amber{background:var(--amber-soft);color:var(--amber-acc)}
.si-green{background:var(--green2);color:var(--success)}
.si-purple{background:var(--purple2);color:var(--purple)}
.si-red{background:var(--red2);color:var(--red)}
.si-blue{background:var(--blue2);color:var(--blue)}
.tag{font-size:.64rem;padding:2px 7px;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:3px;font-family:var(--mono)}
.tag.up{background:var(--green2);color:var(--success)}
.tag.warn{background:var(--amber2);color:var(--amber)}
.tag.alert{background:var(--red2);color:var(--red)}
.tag.info{background:var(--teal-soft);color:var(--teal)}
.stat-val{font-size:1.75rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.73rem;color:var(--text-muted);margin-top:2px}
.stat-sub{font-size:.68rem;color:var(--text-muted);display:flex;align-items:center;gap:5px}
.stat-sub i{font-size:.64rem}

/* ── Quick Action strip ─────────────────────────────────────────────────────── */
.qa-strip{
  display:flex;flex-wrap:wrap;gap:8px;
  padding:10px 16px 14px;border-top:1px solid var(--border);
}
.qa-chip{
  display:inline-flex;align-items:center;gap:7px;
  padding:7px 12px;border-radius:8px;
  background:rgba(20,184,166,.10);border:1px solid rgba(20,184,166,.28);
  color:var(--teal);font-size:.78rem;font-weight:500;
  text-decoration:none;transition:all .18s;white-space:nowrap;
}
.qa-chip:hover{border-color:var(--border-accent);background:rgba(20,184,166,.18);color:var(--teal-dark)}
.qa-chip-icon{width:24px;height:24px;border-radius:6px;display:grid;place-items:center;font-size:.72rem;flex-shrink:0}
.qa-chip-badge{background:var(--red);color:#fff;font-size:.6rem;font-style:normal;font-weight:700;padding:1px 5px;border-radius:4px;margin-left:2px;font-family:var(--mono)}

/* Cards */
.card{
  background:#fff;border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;
  animation:slideUp .45s .22s ease both;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);background:#F0FDFA}
.card-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal);font-size:.8rem}
.card-link{font-size:.72rem;color:var(--teal);text-decoration:none;border:1px solid rgba(20,184,166,.30);padding:4px 10px;border-radius:6px;transition:all .18s;white-space:nowrap;background:rgba(20,184,166,.10)}
.card-link:hover{background:rgba(20,184,166,.20)}

/* ── Timetable ──────────────────────────────────────────────────────────────── */
.timetable{padding:12px 16px;display:flex;flex-direction:column;gap:9px}
.tt-empty{padding:16px 18px;text-align:center;color:var(--text-muted);font-size:.78rem;display:flex;align-items:center;gap:10px;justify-content:center}
.tt-empty i{font-size:1rem;opacity:.35;flex-shrink:0}
.tt-item{
  display:flex;align-items:center;gap:12px;
  padding:12px 14px;border-radius:10px;
  background:#F0FDFA;border:1px solid rgba(20,184,166,.16);transition:border-color .2s,background .2s;
}
.tt-item:hover{border-color:var(--border-accent);background:#CCFBF1}
.tt-time{font-family:var(--mono);font-size:.76rem;color:var(--teal);min-width:100px;flex-shrink:0}
.tt-info{flex:1;min-width:0}
.tt-name{font-size:.84rem;font-weight:600;color:var(--text)}
.tt-code{font-size:.7rem;color:var(--text-muted);margin-top:1px;font-family:var(--mono)}
.tt-dept{font-size:.68rem;color:var(--teal-light);margin-top:2px;font-family:var(--mono)}
.tt-room{font-size:.7rem;color:var(--text-muted);background:#fff;border:1px solid var(--border);padding:3px 8px;border-radius:6px;display:flex;align-items:center;gap:5px;white-space:nowrap}
.tt-room i{color:var(--teal);font-size:.62rem}
.tt-now{border-color:rgba(22,163,74,.28)!important;background:rgba(22,163,74,.04)!important}
.tt-now .tt-time{color:var(--success)}
.now-badge{font-size:.58rem;font-weight:700;background:var(--success);color:#fff;padding:2px 6px;border-radius:4px;margin-left:6px;font-family:var(--mono);animation:pulse 2s infinite}

/* ── Quick Actions ──────────────────────────────────────────────────────────── */
.quick-actions{padding:4px 0}
.qa-item{display:flex;align-items:center;gap:12px;padding:8px 14px;cursor:pointer;transition:background .18s;text-decoration:none}
.qa-item:hover{background:var(--card-hover)}
.qa-icon{width:32px;height:32px;border-radius:8px;flex-shrink:0;display:grid;place-items:center;font-size:.8rem}
.qa-info{flex:1;min-width:0}
.qa-name{font-size:.82rem;font-weight:500;color:var(--text)}
.qa-sub{font-size:.68rem;color:var(--text-muted);margin-top:1px}
.qa-arrow{color:var(--text-muted);font-size:.72rem}

/* ── Bottom row ─────────────────────────────────────────────────────────────── */
.bottom{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;align-items:start}
.bottom3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;align-items:start}

/* ── Table ──────────────────────────────────────────────────────────────────── */
.t-wrap{padding:0 16px 14px}
table{width:100%;border-collapse:collapse}
thead th{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:8px 10px;text-align:left;border-bottom:1px solid var(--border)}
tbody td{padding:8px 10px;font-size:.79rem;color:var(--text);vertical-align:middle}
tbody tr:not(:last-child) td{border-bottom:1px solid var(--border)}
tbody tr:hover td{background:#F0FDFA}
.mono{font-family:var(--mono);font-size:.7rem;color:var(--text-muted)}
.s-name{font-size:.82rem;font-weight:500;color:var(--text)}
.s-email{font-size:.68rem;color:var(--text-muted);margin-top:1px}
.pill{font-size:.64rem;font-weight:700;padding:3px 8px;border-radius:6px;display:inline-block}
.pill.active{background:var(--green2);color:var(--success)}
.pill.pending{background:var(--amber2);color:var(--amber)}
.pill.inactive{background:#F1F5F9;color:var(--text-muted)}
.pill.upcoming{background:var(--blue2);color:var(--blue)}
.pill.ongoing{background:var(--teal-soft2);color:var(--teal)}
.pill.draft{background:#F1F5F9;color:var(--text-muted)}
.pill.published{background:var(--green2);color:var(--success)}
.pill.pass{background:var(--green2);color:var(--success)}
.pill.fail{background:var(--red2);color:var(--red)}
.empty-state{padding:20px;text-align:center;color:var(--text-muted);font-size:.8rem}
.empty-state i{font-size:1.4rem;display:block;margin-bottom:6px;opacity:.25}

/* ── Course cards ───────────────────────────────────────────────────────────── */
.course-grid{padding:10px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.course-card{background:#F0FDFA;border:1px solid rgba(20,184,166,.16);border-radius:10px;padding:12px 14px;transition:border-color .2s,box-shadow .2s}
.course-card:hover{border-color:var(--border-accent);box-shadow:0 3px 12px rgba(20,184,166,.10)}
.cc-code{font-family:var(--mono);font-size:.68rem;color:var(--teal);margin-bottom:4px}
.cc-name{font-size:.8rem;font-weight:600;color:var(--text)}
.cc-dept{font-size:.66rem;color:var(--teal-light);margin-top:3px;font-family:var(--mono)}
.cc-meta{font-size:.68rem;color:var(--text-muted);margin-top:4px;display:flex;gap:10px}

/* ── Department grid ────────────────────────────────────────────────────────── */
.dept-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;padding:12px 14px}
.dept-tile{background:#F0FDFA;border:1px solid rgba(20,184,166,.16);border-radius:10px;padding:12px 14px;transition:border-color .2s,box-shadow .2s}
.dept-tile:hover{border-color:var(--border-accent);box-shadow:0 3px 12px rgba(20,184,166,.10)}
.dept-tile-head{display:flex;align-items:center;gap:7px;margin-bottom:5px}
.dept-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);flex-shrink:0}
.dept-tile-name{font-size:.82rem;font-weight:700;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dept-tile-code{font-family:var(--mono);font-size:.62rem;background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent);padding:1px 6px;border-radius:4px;flex-shrink:0}
.dept-tile-meta{font-size:.67rem;color:var(--text-muted);display:flex;gap:10px;margin-bottom:9px;flex-wrap:wrap}
.dept-tile-meta span{display:flex;align-items:center;gap:4px}
.dept-tile-meta i{color:var(--teal);font-size:.58rem}
.dept-tile-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px}
.dept-tile-stat{text-align:center;padding:6px 4px;border-radius:6px;font-size:.6rem;color:var(--text-muted)}
.dept-tile-stat span{display:block;font-family:var(--mono);font-size:1rem;font-weight:700;margin-bottom:1px}
.dept-tile-stat.teal{background:rgba(20,184,166,.07);border:1px solid rgba(20,184,166,.18)}.dept-tile-stat.teal span{color:var(--teal)}
.dept-tile-stat.amber{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.18)}.dept-tile-stat.amber span{color:var(--amber-acc)}
.dept-tile-stat.blue{background:rgba(29,78,216,.06);border:1px solid rgba(29,78,216,.14)}.dept-tile-stat.blue span{color:var(--blue)}

/* ── Activity log ───────────────────────────────────────────────────────────── */
.log-list{padding:8px 14px 12px;display:flex;flex-direction:column;gap:4px}
.log-grid{padding:8px 14px 12px;display:flex;flex-direction:column;gap:4px}
.log-item{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)}
.log-item:last-child{border-bottom:none}
.log-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);flex-shrink:0;margin-top:5px}
.log-dot.faculty{background:var(--teal-light)}
.log-dot.student{background:var(--blue)}
.log-dot.college_admin,.log-dot.admin{background:var(--amber-acc)}
.log-dot.super_admin{background:var(--red)}
.log-body{flex:1;min-width:0}
.log-action{font-size:.79rem;color:var(--text)}
.log-who{font-size:.68rem;color:var(--text-muted);margin-top:1px;display:flex;align-items:center;gap:6px}
.log-time{font-size:.65rem;color:var(--text-muted);font-family:var(--mono);white-space:nowrap;flex-shrink:0}

/* ── Exam cards ─────────────────────────────────────────────────────────────── */
.exam-list{padding:8px 14px 12px;display:flex;flex-direction:column;gap:7px}
.exam-item{display:flex;align-items:center;gap:12px;padding:9px 11px;background:#F0FDFA;border:1px solid rgba(20,184,166,.16);border-radius:9px;transition:border-color .18s,box-shadow .18s}
.exam-item:hover{border-color:var(--border-accent);box-shadow:0 3px 10px rgba(20,184,166,.10)}
.exam-date-box{min-width:46px;text-align:center;background:#fff;border:1px solid var(--border);border-radius:8px;padding:6px 4px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.edb-day{font-size:1rem;font-weight:800;color:var(--teal);font-family:var(--mono);line-height:1}
.edb-mon{font-size:.6rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em}
.exam-info{flex:1;min-width:0}
.exam-name{font-size:.82rem;font-weight:600;color:var(--text)}
.exam-meta{font-size:.7rem;color:var(--text-muted);margin-top:2px;font-family:var(--mono);display:flex;gap:8px;flex-wrap:wrap}
.exam-dept{font-size:.68rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.exam-marks{font-size:.7rem;color:var(--text-muted)}

/* ── Dual column ────────────────────────────────────────────────────────────── */
.dual-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}

/* ── Animations ─────────────────────────────────────────────────────────────── */
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Scrollbar ──────────────────────────────────────────────────────────────── */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Sidebar overlay (mobile) ───────────────────────────────────────────────── */
.sidebar-overlay{
  display:none;position:fixed;inset:0;z-index:99;
  background:rgba(0,0,0,.40);backdrop-filter:blur(2px);
}
.sidebar-overlay.active{display:block}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:1280px){
  .dept-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
}
@media(max-width:1100px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .bottom,.bottom3{grid-template-columns:1fr 1fr}
  .dept-grid{grid-template-columns:repeat(2,1fr)}
  .dual-col{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%}
  .content{padding:14px}
  .stats{grid-template-columns:1fr 1fr}
  .hc-clock{display:none}
  .hamburger{display:grid}
  .course-grid{grid-template-columns:1fr}
  .bottom,.bottom3{grid-template-columns:1fr}
  .dual-col{grid-template-columns:1fr}
  .dept-grid{grid-template-columns:1fr 1fr}
  .qa-strip{gap:6px}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .dept-grid{grid-template-columns:1fr}
  .qa-chip span{display:none}
  .qa-chip{padding:8px}
  .dual-col{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="shell">

<!-- ══ SIDEBAR ═══════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

  <div class="sidebar-logo">
    <div class="logo-mark">PE</div>
    <div class="logo-text">PEPA<span>ERP Platform</span></div>
    <button class="sb-collapse-btn" id="sidebarCollapseBtn" onclick="collapseSidebar()" title="Collapse sidebar">
      <i class="fas fa-angles-left" id="collapseIcon"></i>
    </button>
  </div>

  <!-- Scrollable area: scope chip + nav -->
  <div class="sidebar-scroll">
    <!-- Assigned To chip: college name + dept(s) -->
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
      <?php if (!empty($myDeptNames)): ?>
        <?php foreach ($myDeptNames as $dn): ?>
          <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($dn) ?></span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($deptName) ?></span>
      <?php endif; ?>
      </div>
    </div>

    <nav class="sidebar-nav" style="flex:1;min-height:0">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item active" data-tip="Dashboard">
        <i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span>
      </a>
      <a href="students.php" class="nav-item" data-tip="Students">
        <i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span>
        <?php if ($myStudents): ?><span class="nav-badge"><?= $myStudents ?></span><?php endif; ?>
      </a>

      <div class="nav-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance">
        <i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span>
      </a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results">
        <i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
        <?php if ($pendingMarks): ?><span class="nav-badge red"><?= $pendingMarks ?></span><?php endif; ?>
      </a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations">
        <i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
        <?php if ($upcomingExams): ?><span class="nav-badge amber"><?= $upcomingExams ?></span><?php endif; ?>
      </a>
      <a href="timetable.php" class="nav-item" data-tip="Timetable">
        <i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span>
      </a>

      <div class="nav-label">Communication</div>
      <a href="staff_noticeboard.php" class="nav-item" data-tip="Staff Noticeboard">
        <i class="fas fa-clipboard-list"></i><span class="nav-text"> Staff Noticeboard</span>
      </a>
      <a href="leave_application.php" class="nav-item" data-tip="Leave Application">
        <i class="fas fa-calendar-minus"></i><span class="nav-text"> Leave Application</span>
      </a>
      <a href="expense_apply.php" class="nav-item" data-tip="Expense Apply">
        <i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span>
      </a>

      <div class="nav-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile">
        <i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span>
      </a>
    </nav>
  </div><!-- /sidebar-scroll -->

  <!-- Pinned user bar — always visible at bottom, never overlaps nav -->
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag">Faculty · <?= count($myDeptIds) > 1 ? count($myDeptIds).' Depts' : esc($deptCode ?: $deptName) ?></div>
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
    <button class="topbar-btn hamburger" id="menuToggle" onclick="toggleSidebar()">
      <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-title">
      Dashboard <span>/ <?= esc($fullName) ?></span>
    </div>
    <div class="topbar-actions">
      <div class="date-chip" id="topbarDate"></div>
      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if ($pendingMarks || $upcomingExams): ?><span class="notif-dot"></span><?php endif; ?>
      </div>
      <!-- Avatar -->
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= esc($fullName) ?>"
             style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>">
          <?php if ($avatarPath): ?>
            <img src="<?= esc($avatarPath) ?>" alt="<?= esc($avatarInitials) ?>"
                 style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
          <?php else: ?>
            <?= esc($avatarInitials) ?>
          <?php endif; ?>
        </div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= esc($fullName) ?></div>
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= ucfirst(str_replace('_',' ',$role)) ?></div>
          </div>
          <a href="profile.php" class="ad-item">
            <i class="fas fa-circle-user"></i> My Profile
          </a>
          <a href="settings.php" class="ad-item">
            <i class="fas fa-gear"></i> Settings
          </a>
          <div class="ad-sep"></div>
          <button class="ad-item danger" onclick="doLogout()">
            <i class="fas fa-arrow-right-from-bracket"></i> Logout
          </button>
        </div>
      </div>
    </div>
  </header>

  <div class="content">

    <!-- ══ UNIFIED HERO CARD ══════════════════════════════════════════════════ -->
    <div class="hero-card">

      <!-- Top bar: college info -->
      <div class="hc-college-bar">
        <div class="hc-college-icon"><i class="fas fa-university"></i></div>
        <div>
          <div class="hc-college-name"><?= esc($collegeName) ?></div>
          <div class="hc-college-meta">
            <?php if ($collegeCode):  ?><span><i class="fas fa-hashtag"></i><?= esc($collegeCode) ?></span><?php endif; ?>
            <?php if ($collegeEst):   ?><span><i class="fas fa-calendar-alt"></i>Est. <?= esc($collegeEst) ?></span><?php endif; ?>
            <?php if ($collegeEmail): ?><span><i class="fas fa-envelope"></i><?= esc($collegeEmail) ?></span><?php endif; ?>
            <?php if ($collegePhone): ?><span><i class="fas fa-phone"></i><?= esc($collegePhone) ?></span><?php endif; ?>
            <span><i class="fas fa-building-columns"></i><?= $totalDepts ?> Dept<?= $totalDepts!=1?'s':'' ?></span>
          </div>
        </div>
        <div class="hc-dept-badges">
          <?php if (!empty($myDeptDetails)): ?>
            <?php foreach ($myDeptDetails as $dd): ?>
              <div class="hc-dept-chip">
                <span class="hc-dept-code">HOD</span>
                <?php if ($dd['hod_name']): ?>
                  <span class="hc-dept-hod"><i class="fas fa-user-tie"></i><?= esc($dd['hod_name']) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="hc-dept-chip">
              <span class="hc-dept-code">HOD</span>
              <?php if ($hodName): ?><span class="hc-dept-hod"><i class="fas fa-user-tie"></i><?= esc($hodName) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Body: faculty profile + greeting + clock -->
      <div class="hc-body">

        <!-- Left: photo + identity -->
        <div class="hc-profile">
          <div class="hc-photo-wrap">
            <?php if ($avatarPath): ?>
              <img src="<?= esc($avatarPath) ?>" alt="<?= esc($fullName) ?>" class="hc-photo">
            <?php else: ?>
              <div class="hc-initials" style="background:<?= esc($avatarColor) ?>"><?= esc($avatarInitials) ?></div>
            <?php endif; ?>
            <span class="hc-online-dot"></span>
          </div>
          <div class="hc-identity">
            <div class="hc-name"><?= esc($fullName) ?></div>
            <div class="hc-role-line">
              <span class="hc-role-badge"><i class="fas fa-chalkboard-user"></i> Faculty</span>
              <span class="hc-desig"><?= esc($userDesig ?: 'Faculty Member') ?></span>
            </div>
            <div class="hc-contact-row">
              <?php if ($userEmail): ?>
                <span><i class="fas fa-envelope"></i><a href="mailto:<?= esc($userEmail) ?>"><?= esc($userEmail) ?></a></span>
              <?php endif; ?>
              <?php if ($userPhone): ?>
                <span><i class="fas fa-phone"></i><?= esc($userPhone) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-shield-halved"></i><?= esc($allDeptLabel) ?></span>
            </div>
          </div>
        </div>

        <div class="hc-sep"></div>

        <!-- Centre: greeting + stats -->
        <div class="hc-greeting">
          <div class="hc-greet-text">Good <span id="greeting">Day</span>, <strong><?= esc($firstName) ?></strong>!</div>
          <div class="hc-greet-sub">
            <?php if ($pendingMarks): ?>
              <i class="fas fa-circle-exclamation" style="color:#FCA5A5"></i>
              <strong><?= $pendingMarks ?> mark entr<?= $pendingMarks!=1?'ies':'y' ?></strong> pending
            <?php elseif ($upcomingExams): ?>
              <i class="fas fa-calendar-check" style="color:#93C5FD"></i>
              <strong><?= $upcomingExams ?> exam<?= $upcomingExams!=1?'s':'' ?></strong> upcoming
            <?php else: ?>
              <i class="fas fa-circle-check" style="color:#86EFAC"></i>
              All caught up — <strong><?= $myStudents ?> student<?= $myStudents!=1?'s':'' ?></strong> across <?= count($myDeptIds) > 1 ? count($myDeptIds).' departments' : esc($deptName) ?>
            <?php endif; ?>
          </div>
          <div class="hc-stats-row">
            <div class="hc-stat"><span><?= $myCourses ?></span><div class="hc-stat-label">Courses</div></div>
            <div class="hc-stat-sep"></div>
            <div class="hc-stat"><span><?= $myStudents ?></span><div class="hc-stat-label">Students</div></div>
            <div class="hc-stat-sep"></div>
            <div class="hc-stat"><span><?= count($myDeptIds) ?></span><div class="hc-stat-label">Dept<?= count($myDeptIds)!=1?'s':'' ?></div></div>
          </div>
        </div>

        <div class="hc-sep"></div>

        <!-- Right: clock + edit -->
        <div class="hc-right">
          <div class="hc-clock">
            <div class="hc-time" id="liveTime">--:--</div>
            <div class="hc-date" id="liveDateStr"></div>
          </div>
          <a href="profile.php" class="hc-edit-btn"><i class="fas fa-pen-to-square"></i> Edit Profile</a>
        </div>

      </div><!-- /hc-body -->
    </div><!-- /hero-card -->

    <!-- ── Stats ──────────────────────────────────────────────────────────── -->
    <div class="stats">
      <!-- Students across all my depts -->
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-teal"><i class="fas fa-user-graduate"></i></div>
          <div class="tag up"><i class="fas fa-users"></i> <?= count($myDeptIds) > 1 ? count($myDeptIds).' Depts' : 'Dept' ?></div>
        </div>
        <div>
          <div class="stat-val"><?= $myStudents ?></div>
          <div class="stat-lbl">Students in My <?= count($myDeptIds) > 1 ? 'Departments' : 'Department' ?></div>
        </div>
        <div class="stat-sub"><i class="fas fa-circle-dot" style="color:var(--green)"></i> <?= implode(' · ', array_map(fn($d) => esc($d['code']), $myDeptDetails)) ?: esc($deptCode) ?></div>
      </div>

      <!-- Courses assigned to me -->
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-amber"><i class="fas fa-book-open"></i></div>
          <div class="tag up"><i class="fas fa-check"></i> Active</div>
        </div>
        <div>
          <div class="stat-val"><?= $myCourses ?></div>
          <div class="stat-lbl">My Assigned Courses</div>
        </div>
        <div class="stat-sub"><i class="fas fa-layer-group"></i> Across <?= count($myDeptIds) ?> dept<?= count($myDeptIds) != 1 ? 's' : '' ?></div>
      </div>

      <!-- Attendance -->
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-green"><i class="fas fa-clipboard-check"></i></div>
          <?php $attClass = $myAttendancePct >= 75 ? 'up' : 'alert'; $attIcon = $myAttendancePct >= 75 ? 'fa-check' : 'fa-triangle-exclamation'; ?>
          <div class="tag <?= $attClass ?>"><i class="fas <?= $attIcon ?>"></i> <?= $myAttendancePct ?>%</div>
        </div>
        <div>
          <div class="stat-val"><?= $myAttendancePct ?>%</div>
          <div class="stat-lbl">Avg Attendance</div>
        </div>
        <div class="stat-sub"><i class="fas fa-calendar-week"></i> Across all my courses</div>
      </div>

      <!-- Pending marks / Upcoming exams -->
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon <?= $pendingMarks ? 'si-red' : 'si-purple' ?>">
            <i class="fas <?= $pendingMarks ? 'fa-chart-bar' : 'fa-file-invoice' ?>"></i>
          </div>
          <div class="tag <?= $pendingMarks ? 'alert' : 'info' ?>">
            <i class="fas <?= $pendingMarks ? 'fa-clock' : 'fa-calendar-check' ?>"></i>
            <?= $pendingMarks ? 'Pending' : 'Upcoming' ?>
          </div>
        </div>
        <div>
          <div class="stat-val"><?= $pendingMarks ?: $upcomingExams ?></div>
          <div class="stat-lbl"><?= $pendingMarks ? 'Mark Entries Due' : 'Upcoming Exams' ?></div>
        </div>
        <div class="stat-sub">
          <i class="fas <?= $pendingMarks ? 'fa-exclamation-circle' : 'fa-circle-check' ?>"
             style="color:<?= $pendingMarks ? 'var(--red)' : 'var(--green)' ?>"></i>
          <?= $pendingMarks ? 'Action required' : ($upcomingExams ? 'Scheduled' : 'None scheduled') ?>
        </div>
      </div>
    </div>

    <!-- ── Today's Classes (full width) + Quick Actions strip ───────────────── -->
    <div class="card tt-card" style="margin-bottom:18px">
      <div class="card-hd">
        <div class="card-title">
          <i class="fas fa-calendar-day"></i> Today's Classes
          <span style="font-size:.68rem;color:var(--muted);font-weight:400;font-family:var(--mono)"><?= date('l, d M') ?></span>
        </div>
        <a href="timetable.php" class="card-link">Full Schedule <i class="fas fa-arrow-right"></i></a>
      </div>
      <?php if (empty($myTimetable)): ?>
        <div class="tt-empty">
          <i class="fas fa-mug-hot"></i>
          No classes today — enjoy the break!
        </div>
      <?php else: ?>
        <div class="timetable">
          <?php
            $nowMins = (int)date('H') * 60 + (int)date('i');
            foreach ($myTimetable as $t):
              [$sh, $sm] = explode(':', substr($t['start_time'], 0, 5));
              [$eh, $em] = explode(':', substr($t['end_time'],   0, 5));
              $startMins = (int)$sh * 60 + (int)$sm;
              $endMins   = (int)$eh * 60 + (int)$em;
              $isNow     = ($nowMins >= $startMins && $nowMins < $endMins);
          ?>
          <div class="tt-item <?= $isNow ? 'tt-now' : '' ?>">
            <div class="tt-time">
              <?= esc(substr($t['start_time'], 0, 5)) ?> – <?= esc(substr($t['end_time'], 0, 5)) ?>
              <?php if ($isNow): ?><span class="now-badge">NOW</span><?php endif; ?>
            </div>
            <div class="tt-info">
              <div class="tt-name"><?= esc($t['course_name'] ?? $t['period_label']) ?></div>
              <div class="tt-code"><?= esc($t['course_code'] ?? '') ?></div>
              <?php if (!empty($t['dept_name'])): ?>
                <div class="tt-dept"><i class="fas fa-sitemap" style="font-size:.58rem;margin-right:3px"></i><?= esc($t['dept_name']) ?><?php if(!empty($t['dept_code'])): ?> · <?= esc($t['dept_code']) ?><?php endif; ?></div>
              <?php endif; ?>
            </div>
            <?php if (!empty($t['room_name'])): ?>
              <div class="tt-room"><i class="fas fa-location-dot"></i><?= esc($t['room_name']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Quick Actions as a horizontal strip inside the same card -->
      <div class="qa-strip">
        <a href="attendance.php?action=mark&college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="qa-chip">
          <div class="qa-chip-icon si-teal"><i class="fas fa-clipboard-check"></i></div>
          <span>Mark Attendance</span>
        </a>
        <a href="grades_results.php?action=enter&college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="qa-chip">
          <div class="qa-chip-icon si-amber"><i class="fas fa-pen-to-square"></i></div>
          <span>Enter Marks<?php if($pendingMarks): ?> <em class="qa-chip-badge"><?= $pendingMarks ?></em><?php endif; ?></span>
        </a>
        <a href="examinations.php?action=new&college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="qa-chip">
          <div class="qa-chip-icon si-purple"><i class="fas fa-file-invoice"></i></div>
          <span>Create Exam</span>
        </a>
        <a href="assignments.php?action=new&college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="qa-chip">
          <div class="qa-chip-icon si-blue"><i class="fas fa-paperclip"></i></div>
          <span>Post Assignment</span>
        </a>
        <a href="students.php?college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="qa-chip">
          <div class="qa-chip-icon si-green"><i class="fas fa-user-graduate"></i></div>
          <span>My Students</span>
        </a>
      </div>
    </div>

    <!-- ── Recent Students + My Assigned Courses (independent widths) ────────── -->
    <div class="dual-col" style="margin-bottom:18px">

      <!-- Recent Students — filtered by my assigned depts -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title">
            <i class="fas fa-user-graduate"></i>
            Recent Students
          </div>
          <a href="students.php?college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="card-link">All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recentStudents)): ?>
          <div class="empty-state"><i class="fas fa-users-slash"></i>No students in your assigned department<?= count($myDeptIds) > 1 ? 's' : '' ?> yet.</div>
        <?php else: ?>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Student</th><th>Department</th><th>Roll No.</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($recentStudents as $s): ?>
                <tr>
                  <td>
                    <div class="s-name"><?= esc($s['full_name']) ?></div>
                    <div class="s-email"><?= esc($s['email']) ?></div>
                  </td>
                  <td>
                    <span class="mono" style="font-size:.67rem"><?= esc($s['dept_name'] ?? '—') ?></span>
                    <?php if (!empty($s['dept_code'])): ?>
                      <div style="font-size:.62rem;color:var(--teal);font-family:var(--mono);margin-top:2px"><?= esc($s['dept_code']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="mono"><?= esc($s['roll_number'] ?? '—') ?></span></td>
                  <td><span class="pill <?= esc($s['status']) ?>"><?= ucfirst(esc($s['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- My Assigned Courses (from faculty_assignments only) -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-book-open"></i> My Assigned Courses</div>
          <a href="accounts.php?college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="card-link">All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($myCourseList)): ?>
          <div class="empty-state"><i class="fas fa-book"></i>No courses assigned to you yet. Contact the admin.</div>
        <?php else: ?>
          <div class="course-grid">
            <?php foreach ($myCourseList as $c): ?>
            <div class="course-card">
              <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
                <div class="cc-code" style="margin-bottom:0"><?= esc($c['code']) ?></div>
                <?php if (!empty($c['dept_code'])): ?>
                  <span style="font-size:.6rem;background:rgba(45,102,66,.12);color:var(--purple);padding:1px 6px;border-radius:4px;font-family:var(--mono)"><?= esc($c['dept_code']) ?></span>
                <?php endif; ?>
              </div>
              <div class="cc-name"><?= esc($c['name']) ?></div>
              <?php if (!empty($c['dept_name'])): ?>
                <div class="cc-dept"><i class="fas fa-sitemap" style="font-size:.58rem;margin-right:3px"></i><?= esc($c['dept_name']) ?></div>
              <?php endif; ?>
              <div class="cc-meta">
                <span><i class="fas fa-star" style="color:var(--amber);font-size:.6rem"></i> <?= esc($c['credits']) ?> cr</span>
                <span><i class="fas fa-layer-group" style="color:var(--purple);font-size:.6rem"></i> Sem <?= esc($c['semester'] ?? '—') ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Your Departments (full-width, inline summary) ────────────────────── -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <div class="card-title">
          <i class="fas fa-sitemap"></i>
          Your Department<?= count($myDeptIds) > 1 ? 's' : '' ?>
          <span style="font-size:.7rem;color:var(--muted);font-weight:400">(assigned by superadmin)</span>
        </div>
      </div>
      <?php if (empty($myDeptDetails)): ?>
        <div class="empty-state"><i class="fas fa-building"></i>No department assignments found.</div>
      <?php else: ?>
        <div class="dept-grid">
          <?php foreach ($myDeptDetails as $did => $dd):
            $sc = $deptStudentCounts[$did] ?? 0;
            $cc = 0; foreach ($myCourseList as $c) { if (($c['dept_code'] ?? '') === $dd['code']) $cc++; }
            $ec = 0; foreach ($upcomingExamList as $ex) { if (($ex['dept_code'] ?? '') === $dd['code']) $ec++; }
            $deptCourseCount = $cc;
          ?>
          <div class="dept-tile">
            <div class="dept-tile-head">
              <div class="dept-dot"></div>
              <div class="dept-tile-name"><?= esc($dd['name']) ?></div>
              <span class="dept-tile-code"><?= esc($dd['code']) ?></span>
            </div>
            <div class="dept-tile-meta">
              <?php if ($dd['hod_name']): ?>
                <span><i class="fas fa-user-tie"></i><?= esc($dd['hod_name']) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-book-open"></i><?= $cc ?> course<?= $cc != 1 ? 's' : '' ?></span>
            </div>
            <div class="dept-tile-stats">
              <div class="dept-tile-stat teal"><span><?= $sc ?></span>Students</div>
              <div class="dept-tile-stat amber"><span><?= $cc ?></span>Courses</div>
              <div class="dept-tile-stat blue"><span><?= $ec ?></span>Exams</div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Bottom: Upcoming Exams + Recent Marks side by side, College Activity full-width ── -->
    <div class="dual-col" style="margin-bottom:18px">

      <!-- Upcoming Exams -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-file-invoice"></i> Upcoming Exams</div>
          <a href="examinations.php?college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="card-link">All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($upcomingExamList)): ?>
          <div class="empty-state"><i class="fas fa-calendar-xmark"></i>No upcoming exams for your courses.</div>
        <?php else: ?>
          <div class="exam-list">
            <?php foreach ($upcomingExamList as $ex): ?>
            <div class="exam-item">
              <div class="exam-date-box">
                <div class="edb-day"><?= date('d', strtotime($ex['exam_date'])) ?></div>
                <div class="edb-mon"><?= date('M', strtotime($ex['exam_date'])) ?></div>
              </div>
              <div class="exam-info">
                <div class="exam-name"><?= esc($ex['title']) ?></div>
                <div class="exam-meta">
                  <span><?= esc($ex['course_code'] ?? '') ?></span>
                  <span>· <?= ucfirst(str_replace('_', ' ', $ex['type'])) ?></span>
                </div>
                <?php if (!empty($ex['dept_name'])): ?>
                  <div class="exam-dept"><i class="fas fa-sitemap" style="font-size:.58rem;margin-right:3px"></i><?= esc($ex['dept_name']) ?></div>
                <?php endif; ?>
                <div class="exam-marks" style="margin-top:2px">Max: <?= esc($ex['max_marks']) ?> marks</div>
              </div>
              <span class="pill <?= esc($ex['status']) ?>"><?= ucfirst(esc($ex['status'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent Marks Entered -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-chart-bar"></i> Recent Marks Entered</div>
          <a href="grades_results.php?college=<?= $primaryCollegeId ?>&dept=<?= $primaryDeptId ?>" class="card-link">All <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recentMarks)): ?>
          <div class="empty-state"><i class="fas fa-chart-simple"></i>No marks entered yet for your courses.</div>
        <?php else: ?>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Student</th><th>Exam</th><th>Dept</th><th>Marks</th><th>Result</th></tr></thead>
              <tbody>
                <?php foreach ($recentMarks as $m): ?>
                <tr>
                  <td><div class="s-name" style="font-size:.78rem"><?= esc($m['student_name']) ?></div></td>
                  <td>
                    <div style="font-size:.76rem;color:var(--text)"><?= esc(mb_strimwidth($m['exam_title'], 0, 22, '…')) ?></div>
                    <div class="mono"><?= ucfirst(str_replace('_', ' ', $m['exam_type'])) ?></div>
                  </td>
                  <td><span class="mono" style="font-size:.65rem"><?= esc($m['dept_code'] ?? $m['dept_name'] ?? '—') ?></span></td>
                  <td><span class="mono"><?= esc($m['obtained_marks']) ?></span></td>
                  <td><span class="pill <?= $m['is_pass'] ? 'pass' : 'fail' ?>"><?= $m['is_pass'] ? 'Pass' : 'Fail' ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- College Activity (full-width) -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-clock-rotate-left"></i> College Activity</div>
      </div>
      <?php if (empty($announcements)): ?>
        <div class="empty-state"><i class="fas fa-list"></i>No recent activity.</div>
      <?php else: ?>
        <div class="log-grid">
          <?php foreach ($announcements as $a): ?>
          <div class="log-item">
            <div class="log-dot <?= esc($a['role']) ?>"></div>
            <div class="log-body">
              <div class="log-action"><?= esc($a['action']) ?></div>
              <div class="log-who">
                <i class="fas fa-user" style="font-size:.6rem"></i>
                <?= esc($a['full_name']) ?>
                <span class="pill <?= esc($a['role']) ?>" style="font-size:.58rem;padding:1px 5px"><?= ucfirst(str_replace('_', ' ', $a['role'])) ?></span>
              </div>
            </div>
            <div class="log-time"><?= fmtDate($a['created_at']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<script>
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

/* ── Clock ─────────────────────────────────────────────────────────────────── */
function tick(){
  const now=new Date();
  const h=String(now.getHours()).padStart(2,'0'), m=String(now.getMinutes()).padStart(2,'0');
  document.getElementById('liveTime').textContent=`${h}:${m}`;
  document.getElementById('liveDateStr').textContent=now.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  document.getElementById('topbarDate').textContent=now.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  const hr=now.getHours();
  document.getElementById('greeting').textContent=hr<12?'Morning':hr<17?'Afternoon':'Evening';
}
tick(); setInterval(tick,10000);

/* ── Sidebar toggle (mobile) ────────────────────────────────────────────────── */
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  const open = sb.classList.toggle('open');
  ov.classList.toggle('active', open);
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=800 && sb.classList.contains('open') &&
     !sb.contains(e.target) && !document.getElementById('menuToggle').contains(e.target)){
    sb.classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
  }
});

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res=await fetch('../auth/auth_handler.php',{
      method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'
    });
    const data=await res.json();
    if(data.redirect) window.location.href=data.redirect;
  } catch{ window.location.href='../login.php'; }
}

/* ── Avatar dropdown ────────────────────────────────────────────────────────── */
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
</body>
</html>