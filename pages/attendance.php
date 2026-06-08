<?php
// ─────────────────────────────────────────────────────────────────────────────
// pages/attendance.php  — v4: Multi-department faculty support
//
// FLOW (all roles):
//   1. Select Department   (super_admin/college_admin can switch; faculty gets
//                           dropdown if assigned to >1 dept, locked if only 1)
//   2. Select Subject      (filtered by faculty_assignments for faculty role;
//                           all dept courses for admin roles)
//   3. Students listed     (filtered by enrollments for selected subject)
//   4. Mark attendance     (saved with course_id from selected subject)
//
// FIXES (v3 → v4):
//   - Faculty department list now queries faculty_assignments (all assigned depts)
//     instead of reading users.department_id (only one dept)
//   - Faculty with >1 assigned dept gets a department <select> dropdown
//   - All AJAX handlers (load_courses, load_students, load_attendance,
//     save_attendance, load_history) now use dept_id from POST instead of
//     hard-locking to $userDeptId, so switching dept actually works
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']              ?? 'faculty';
$userId    = (int)($user['id']          ?? 0);
$userColId = (int)($user['college_id']  ?? 0);
$userDeptId= (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']         ?? 'Faculty';

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ─────────────────────────────────────────────────────────────────────────────
//  Helper: get courses for a dept, optionally filtered by faculty_assignments
// ─────────────────────────────────────────────────────────────────────────────
function getCoursesForDept(PDO $pdo, int $colId, int $deptId, string $role, int $userId): array {
    if ($role === 'faculty') {
        // Only courses this faculty is assigned to teach in this dept
        $st = $pdo->prepare("
            SELECT DISTINCT c.id, c.name, c.code, c.semester
            FROM courses c
            INNER JOIN faculty_assignments fa
                ON fa.course_id = c.id
               AND fa.college_id = c.college_id
               AND fa.department_id = c.department_id
               AND fa.faculty_id = ?
               AND fa.status = 'active'
            WHERE c.college_id = ? AND c.department_id = ? AND c.status = 'active'
            ORDER BY c.semester, c.name
        ");
        $st->execute([$userId, $colId, $deptId]);
    } else {
        // Admins see all active courses in this dept
        $st = $pdo->prepare("
            SELECT id, name, code, semester
            FROM courses
            WHERE college_id = ? AND department_id = ? AND status = 'active'
            ORDER BY semester, name
        ");
        $st->execute([$colId, $deptId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $pdo = getDB();
    if (!$pdo) { echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit; }

    $ajaxAction = $_POST['ajax_action'];

    // ── load_departments ──────────────────────────────────────────────────────
    if ($ajaxAction === 'load_departments') {
        $colId = (int)($_POST['college_id'] ?? 0);
        if ($role !== 'super_admin') $colId = $userColId;
        $st = $pdo->prepare("SELECT id, name, code FROM departments WHERE college_id=? AND status='active' ORDER BY name");
        $st->execute([$colId]);
        echo json_encode(['success'=>true, 'departments'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── load_courses_for_dept — respects faculty_assignments ─────────────────
    if ($ajaxAction === 'load_courses') {
        $colId  = (int)($_POST['college_id'] ?? 0);
        $deptId = (int)($_POST['dept_id']    ?? 0);
        // Scope enforcement: faculty locked to their own college only;
        // dept_id comes from POST (they may have multiple assigned depts)
        if ($role === 'faculty') { $colId = $userColId; }
        elseif ($role === 'college_admin') { $colId = $userColId; }

        $courses = getCoursesForDept($pdo, $colId, $deptId, $role, $userId);
        echo json_encode(['success'=>true, 'courses'=>$courses]);
        exit;
    }

    // ── load_students ─────────────────────────────────────────────────────────
    if ($ajaxAction === 'load_students') {
        $colId    = (int)($_POST['college_id'] ?? 0);
        $deptId   = (int)($_POST['dept_id']    ?? 0);
        $courseId = (int)($_POST['course_id']  ?? 0); // FIX 1: read course_id
        $date     = $_POST['date'] ?? date('Y-m-d');
        $studyYear       = (int)($_POST['study_year']       ?? 0);
        $section         = trim($_POST['section']           ?? '');
        $currentSemester = (int)($_POST['current_semester'] ?? 0);

        if ($role === 'faculty') { $colId = $userColId; }
        elseif ($role === 'college_admin') { $colId = $userColId; }

        // Build student query with optional year/semester/section filters
        $stuSql    = "SELECT u.id, u.full_name, u.roll_number,
                             COALESCE(u.avatar_color,'#00c6ae') AS avatar_color,
                             u.study_year, u.section, u.current_semester
                      FROM users u
                      WHERE u.college_id = ? AND u.department_id = ?
                        AND u.role = 'student' AND u.status = 'active'";
        $stuParams = [$colId, $deptId];
        if ($studyYear)       { $stuSql .= " AND u.study_year = ?";       $stuParams[] = $studyYear; }
        if ($currentSemester) { $stuSql .= " AND u.current_semester = ?"; $stuParams[] = $currentSemester; }
        if ($section !== '')  { $stuSql .= " AND u.section = ?";          $stuParams[] = $section; }
        $stuSql .= " ORDER BY u.roll_number, u.full_name";
        $st = $pdo->prepare($stuSql);
        $st->execute($stuParams);

        // Return distinct year/semester/section combos for dynamic dropdowns
        $ySt = $pdo->prepare("
            SELECT DISTINCT study_year, current_semester, section
            FROM users
            WHERE college_id = ? AND department_id = ? AND role = 'student' AND status = 'active'
              AND study_year IS NOT NULL
            ORDER BY study_year, current_semester, section
        ");
        $ySt->execute([$colId, $deptId]);
        $yearSections = $ySt->fetchAll(PDO::FETCH_ASSOC);
        $students = $st->fetchAll(PDO::FETCH_ASSOC);

        // BUG FIX: scope attendance lookup to selected course_id so daily_status
        // returned per student reflects THIS course, not a different course's record
        $atSql    = "SELECT student_id, period, status, remarks FROM attendance WHERE college_id = ? AND department_id = ? AND date = ?";
        $atParams = [$colId, $deptId, $date];
        if ($courseId) { $atSql .= " AND course_id = ?"; $atParams[] = $courseId; }
        $at = $pdo->prepare($atSql);
        $at->execute($atParams);
        $dailyMap = []; $periodMap = []; $remarksMap = [];
        foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['period']) $periodMap[$r['student_id']][$r['period']] = $r['status'];
            else { $dailyMap[$r['student_id']] = $r['status']; $remarksMap[$r['student_id']] = $r['remarks'] ?? ''; }
        }

        // FIX 1b: scope attendance % to selected course when available
        if ($courseId) {
            $pc = $pdo->prepare("
                SELECT student_id,
                       ROUND(SUM(status='P')*100.0/NULLIF(COUNT(*),0),1) AS pct,
                       COUNT(*) AS total, SUM(status='P') AS present,
                       SUM(status='A') AS absent,
                       SUM(status IN ('LV','ML')) AS leave_cnt
                FROM attendance
                WHERE college_id = ? AND department_id = ? AND course_id = ? AND period IS NULL
                GROUP BY student_id
            ");
            $pc->execute([$colId, $deptId, $courseId]);
        } else {
            $pc = $pdo->prepare("
                SELECT student_id,
                       ROUND(SUM(status='P')*100.0/NULLIF(COUNT(*),0),1) AS pct,
                       COUNT(*) AS total, SUM(status='P') AS present,
                       SUM(status='A') AS absent,
                       SUM(status IN ('LV','ML')) AS leave_cnt
                FROM attendance
                WHERE college_id = ? AND department_id = ? AND period IS NULL
                GROUP BY student_id
            ");
            $pc->execute([$colId, $deptId]);
        }
        $pctMap = [];
        foreach ($pc->fetchAll(PDO::FETCH_ASSOC) as $r) $pctMap[$r['student_id']] = $r;

        $COLORS = ['#00c6ae','#f4a261','#7c3aed','#3b82f6','#e76f51','#06b6d4','#52c41a','#a78bfa'];
        $out = [];
        foreach ($students as $i => $s) {
            $p = $pctMap[$s['id']] ?? null;
            $parts = explode(' ', trim($s['full_name']));
            $out[] = [
                'id'           => (int)$s['id'],
                'name'         => $s['full_name'],
                'roll'         => $s['roll_number'] ?: 'STU-'.str_pad($s['id'],3,'0',STR_PAD_LEFT),
                'color'        => $s['avatar_color'] ?: $COLORS[$i % count($COLORS)],
                'initials'     => strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):'')),
                'pct'          => $p ? round((float)$p['pct'], 1) : null,
                'total'        => (int)($p['total']     ?? 0),
                'present'      => (int)($p['present']   ?? 0),
                'absent'       => (int)($p['absent']    ?? 0),
                'leave'        => (int)($p['leave_cnt'] ?? 0),
                'daily_status' => $dailyMap[$s['id']]   ?? null,
                'remarks'      => $remarksMap[$s['id']] ?? '',
                'period_status'=> (object)($periodMap[$s['id']] ?? []),
                'year'         => $s['study_year']       ? (int)$s['study_year']       : null,
                'section'      => $s['section']           ?? '',
                'semester'     => $s['current_semester']  ? (int)$s['current_semester'] : null,
            ];
        }
        echo json_encode(['success'=>true, 'students'=>$out, 'year_sections'=>$yearSections]);
        exit;
    }

    // ── load_year_sections — return distinct year/section combos for a dept ──
    if ($ajaxAction === 'load_year_sections') {
        $colId  = (int)($_POST['college_id'] ?? $userColId);
        $deptId = (int)($_POST['dept_id']    ?? 0);
        if ($role === 'faculty' || $role === 'college_admin') $colId = $userColId;
        $st = $pdo->prepare("
            SELECT DISTINCT study_year, current_semester, section
            FROM users
            WHERE college_id = ? AND department_id = ? AND role = 'student' AND status = 'active'
              AND study_year IS NOT NULL
            ORDER BY study_year, current_semester, section
        ");
        $st->execute([$colId, $deptId]);
        echo json_encode(['success'=>true, 'year_sections'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── load_attendance ───────────────────────────────────────────────────────
    if ($ajaxAction === 'load_attendance') {
        $colId    = (int)($_POST['college_id'] ?? $userColId);
        $deptId   = (int)($_POST['dept_id']    ?? $userDeptId);
        $date     = $_POST['date'] ?? date('Y-m-d');
        $courseId = (int)($_POST['course_id']  ?? 0) ?: null;
        if ($role === 'faculty') { $colId = $userColId; } // college locked, dept from POST

        $sql    = "SELECT student_id, period, status, remarks FROM attendance WHERE college_id=? AND department_id=? AND date=?";
        $params = [$colId, $deptId, $date];
        if ($courseId) { $sql .= " AND course_id=?"; $params[] = $courseId; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode(['success'=>true, 'records'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── save_attendance ───────────────────────────────────────────────────────
    if ($ajaxAction === 'save_attendance') {
        $colId    = (int)($_POST['college_id'] ?? 0);
        $deptId   = (int)($_POST['dept_id']    ?? 0);
        $date     = $_POST['date']    ?? date('Y-m-d');
        $courseId = (int)($_POST['course_id']  ?? 0) ?: null;
        $records  = json_decode($_POST['records'] ?? '[]', true);

        if ($role === 'faculty') { $colId = $userColId; } // college locked, dept comes from POST
        if (!$colId || !$deptId || empty($records)) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields.']); exit;
        }

        $saved = 0;
        $pdo->beginTransaction();
        try {
            // ROOT-CAUSE FIX: MySQL treats NULL != NULL in UNIQUE KEY checks, so
            // ON DUPLICATE KEY UPDATE never fires when course_id or period is NULL.
            // Step 0: Delete any duplicate rows from old broken saves (same student+course+date+period).
            $stDedupe = $pdo->prepare("
                DELETE a1 FROM attendance a1
                INNER JOIN attendance a2
                    ON  a2.student_id    = a1.student_id
                    AND a2.college_id    = a1.college_id
                    AND a2.department_id = a1.department_id
                    AND a2.date          = a1.date
                    AND (a2.course_id = a1.course_id OR (a2.course_id IS NULL AND a1.course_id IS NULL))
                    AND (a2.period    = a1.period    OR (a2.period    IS NULL AND a1.period    IS NULL))
                    AND a2.id > a1.id
                WHERE a1.college_id = ? AND a1.department_id = ? AND a1.date = ?
            ");
            $stDedupe->execute([$colId, $deptId, $date]);
            // Step 1: UPDATE existing row; Step 2: INSERT if none matched.
            $stUpdate = $pdo->prepare("
                UPDATE attendance
                   SET status     = :status,
                       remarks    = :remarks,
                       marked_by  = :by,
                       updated_at = current_timestamp()
                 WHERE student_id    = :sid
                   AND college_id    = :colid
                   AND department_id = :deptid
                   AND date          = :date
                   AND (course_id    = :cid   OR (course_id IS NULL AND :cid2  IS NULL))
                   AND (period       = :period OR (period    IS NULL AND :period2 IS NULL))
            ");
            $stInsert = $pdo->prepare("
                INSERT INTO attendance
                    (student_id, course_id, college_id, department_id, date, period, status, remarks, marked_by)
                VALUES
                    (:sid, :cid, :colid, :deptid, :date, :period, :status, :remarks, :by)
            ");
            foreach ($records as $rec) {
                $period = $rec['period'] ?: null;
                $stUpdate->execute([
                    ':status'  => $rec['status'],
                    ':remarks' => $rec['remarks'] ?? null,
                    ':by'      => $userId,
                    ':sid'     => (int)$rec['student_id'],
                    ':colid'   => $colId,
                    ':deptid'  => $deptId,
                    ':date'    => $date,
                    ':cid'     => $courseId,
                    ':cid2'    => $courseId,
                    ':period'  => $period,
                    ':period2' => $period,
                ]);
                if ($stUpdate->rowCount() === 0) {
                    // No existing row matched — insert fresh
                    $stInsert->execute([
                        ':sid'     => (int)$rec['student_id'],
                        ':cid'     => $courseId,
                        ':colid'   => $colId,
                        ':deptid'  => $deptId,
                        ':date'    => $date,
                        ':period'  => $period,
                        ':status'  => $rec['status'],
                        ':remarks' => $rec['remarks'] ?? null,
                        ':by'      => $userId,
                    ]);
                }
                $saved++;
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'saved'=>$saved]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── load_history ──────────────────────────────────────────────────────────
    if ($ajaxAction === 'load_history') {
        $colId    = $userColId;
        $deptId   = (int)($_POST['dept_id'] ?? $userDeptId); // FIX: use posted dept_id for multi-dept faculty
        // Admins can pass dept_id
        if ($role === 'super_admin' || $role === 'college_admin') {
            $colId  = (int)($_POST['college_id'] ?? $userColId);
            $deptId = (int)($_POST['dept_id']    ?? $deptId);
        }
        $courseId = (int)($_POST['course_id'] ?? 0) ?: null;
        $from     = $_POST['from'] ?? date('Y-m-01');
        $to       = $_POST['to']   ?? date('Y-m-d');
        $studyYear       = (int)($_POST['study_year']       ?? 0);
        $section         = trim($_POST['section']           ?? '');
        $currentSemester = (int)($_POST['current_semester'] ?? 0);
        if ((strtotime($to) - strtotime($from)) > 90*86400) $from = date('Y-m-d', strtotime($to)-90*86400);

        // Filter by year/section if provided
        $stuSql = "SELECT u.id, u.full_name, u.roll_number FROM users u
                   WHERE u.college_id=? AND u.department_id=? AND u.role='student' AND u.status='active'";
        $stuP   = [$colId, $deptId];
        if ($studyYear)       { $stuSql .= " AND u.study_year=?";       $stuP[] = $studyYear; }
        if ($currentSemester) { $stuSql .= " AND u.current_semester=?"; $stuP[] = $currentSemester; }
        if ($section !== '')  { $stuSql .= " AND u.section=?";          $stuP[] = $section; }
        $stuSql .= " ORDER BY u.roll_number, u.full_name";
        $stSt = $pdo->prepare($stuSql);
        $stSt->execute($stuP);
        $stuList = $stSt->fetchAll(PDO::FETCH_ASSOC);

        $sql    = "SELECT a.student_id, a.date, a.period, a.status, a.course_id,
                          COALESCE(c.code,'') AS course_code
                   FROM attendance a LEFT JOIN courses c ON c.id=a.course_id
                   WHERE a.college_id=? AND a.department_id=? AND a.date BETWEEN ? AND ? AND a.period IS NULL";
        $params = [$colId, $deptId, $from, $to];
        if ($courseId) { $sql .= " AND a.course_id=?"; $params[] = $courseId; }
        $sql .= " ORDER BY a.date DESC, a.student_id";
        $at = $pdo->prepare($sql);
        $at->execute($params);
        $rows = $at->fetchAll(PDO::FETCH_ASSOC);

        $dates = [];
        foreach ($rows as $r) $dates[$r['date']] = true;
        $dates = array_keys($dates);

        $summary = [];
        foreach ($stuList as $s) $summary[$s['id']] = ['P'=>0,'A'=>0,'L'=>0,'H'=>0,'LV'=>0,'ML'=>0,'HOL'=>0,'total'=>0];
        $map = [];
        foreach ($rows as $r) {
            $map[$r['student_id']][$r['date']] = $r['status'];
            if (isset($summary[$r['student_id']])) {
                $summary[$r['student_id']][$r['status']] = ($summary[$r['student_id']][$r['status']] ?? 0) + 1;
                $summary[$r['student_id']]['total']++;
            }
        }

        echo json_encode(['success'=>true,'students'=>$stuList,'dates'=>$dates,'map'=>$map,'summary'=>$summary,'from'=>$from,'to'=>$to]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  PAGE-RENDER DATA LOAD
// ─────────────────────────────────────────────────────────────────────────────
$pdo = getDB();
if (!$pdo) {
    die('<div style="font-family:sans-serif;padding:40px;color:#f87171;background:#0d1b2a;min-height:100vh">
         <h2>⚠ Database Connection Failed</h2>
         <p style="color:#94a3b8;margin-top:12px">MySQL is not running or credentials are wrong.</p></div>');
}

// ── Active college ────────────────────────────────────────────────────────────
if ($role === 'faculty') {
    $activeColId = $userColId;
} else {
    $activeColId = (int)($_GET['college_id'] ?? $userColId);
}

// ── Colleges list ─────────────────────────────────────────────────────────────
if ($role === 'super_admin') {
    $colSt = $pdo->prepare("SELECT id, name, code FROM colleges WHERE status='active' ORDER BY name");
    $colSt->execute();
    $colleges = $colSt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $colSt = $pdo->prepare("SELECT id, name, code FROM colleges WHERE id=? AND status='active' LIMIT 1");
    $colSt->execute([$userColId]);
    $colleges    = $colSt->fetchAll(PDO::FETCH_ASSOC);
    $activeColId = $userColId;
}
if (!$activeColId && !empty($colleges)) $activeColId = (int)$colleges[0]['id'];

$collegeNameSt = $pdo->prepare("SELECT name, code FROM colleges WHERE id=? LIMIT 1");
$collegeNameSt->execute([$activeColId]);
$collegeRow  = $collegeNameSt->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'Your College','code'=>''];
$collegeName = $collegeRow['name'];
$collegeCode = $collegeRow['code'];

// ── Departments ───────────────────────────────────────────────────────────────
if ($role === 'faculty') {
    // FIX: Faculty may be assigned to MULTIPLE departments via faculty_assignments.
    // Query all distinct departments they are assigned to, not just users.department_id.
    $deptSt = $pdo->prepare("
        SELECT DISTINCT d.id, d.name, d.code
        FROM departments d
        INNER JOIN faculty_assignments fa
            ON fa.department_id = d.id
           AND fa.college_id = d.college_id
           AND fa.faculty_id = ?
           AND fa.status = 'active'
        WHERE d.college_id = ? AND d.status = 'active'
        ORDER BY d.name
    ");
    $deptSt->execute([$userId, $activeColId]);
    $departments  = $deptSt->fetchAll(PDO::FETCH_ASSOC);
    // Allow switching dept via URL param as long as it belongs to their assignments
    $activeDeptId = (int)($_GET['dept_id'] ?? 0);
    $assignedDeptIds = array_column($departments, 'id');
    if (!$activeDeptId || !in_array($activeDeptId, $assignedDeptIds)) {
        $activeDeptId = !empty($departments) ? (int)$departments[0]['id'] : $userDeptId;
    }
} else {
    $deptSt = $pdo->prepare("SELECT id, name, code FROM departments WHERE college_id=? AND status='active' ORDER BY name");
    $deptSt->execute([$activeColId]);
    $departments  = $deptSt->fetchAll(PDO::FETCH_ASSOC);
    $activeDeptId = (int)($_GET['dept_id'] ?? ($departments[0]['id'] ?? 0));
}
$activeDeptName = 'Department'; $activeDeptCode = '';
foreach ($departments as $d) {
    if ((int)$d['id'] === $activeDeptId) { $activeDeptName = $d['name']; $activeDeptCode = $d['code']; break; }
}

// ── Courses — assignment-aware ────────────────────────────────────────────────
$courses = getCoursesForDept($pdo, $activeColId, $activeDeptId, $role, $userId);

// ── Students ────────────────────────────────────────────────────────────────────────────
// Show all active students in the dept — enrollments table may be empty so do NOT join it.
// Attendance is scoped per course_id in the attendance records themselves.
$activeCourseId = !empty($courses) ? (int)$courses[0]['id'] : null;
$stuSt = $pdo->prepare("
    SELECT u.id, u.full_name, u.roll_number,
           COALESCE(u.avatar_color,'#00c6ae') AS avatar_color
    FROM users u
    WHERE u.college_id=? AND u.department_id=? AND u.role='student' AND u.status='active'
    ORDER BY u.roll_number, u.full_name
");
$stuSt->execute([$activeColId, $activeDeptId]);
$studentsRaw = $stuSt->fetchAll(PDO::FETCH_ASSOC);

// ── Today's attendance ────────────────────────────────────────────
$today          = date('Y-m-d');
// $activeCourseId already set above
// BUG FIX: filter by active course_id so statuses from other courses are not mixed in
$todaySql    = "SELECT student_id, period, status, remarks FROM attendance WHERE college_id=? AND department_id=? AND date=?";
$todayParams = [$activeColId, $activeDeptId, $today];
if ($activeCourseId) { $todaySql .= " AND course_id=?"; $todayParams[] = $activeCourseId; }
$todaySt = $pdo->prepare($todaySql);
$todaySt->execute($todayParams);
$dailyMap = []; $periodMap = []; $remarksMap = [];
foreach ($todaySt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['period']) $periodMap[$r['student_id']][$r['period']] = $r['status'];
    else { $dailyMap[$r['student_id']] = $r['status']; $remarksMap[$r['student_id']] = $r['remarks'] ?? ''; }
}

// ── Per-student overall % (scoped to active course when selected) ──────────────
// BUG FIX: was missing course_id filter so % mixed all courses; now scoped correctly
$pctSql = "
    SELECT student_id,
           ROUND(SUM(status='P')*100.0/NULLIF(COUNT(*),0),1) AS pct,
           COUNT(*) AS total, SUM(status='P') AS present,
           SUM(status='A') AS absent,
           SUM(status IN ('LV','ML')) AS leave_cnt
    FROM attendance
    WHERE college_id=? AND department_id=? AND period IS NULL
";
$pctParams = [$activeColId, $activeDeptId];
if ($activeCourseId) { $pctSql .= " AND course_id=?"; $pctParams[] = $activeCourseId; }
$pctSql .= " GROUP BY student_id";
$pctSt = $pdo->prepare($pctSql);
$pctSt->execute($pctParams);
$pctMap = [];
foreach ($pctSt->fetchAll(PDO::FETCH_ASSOC) as $r) $pctMap[$r['student_id']] = $r;

// ── Weekly chart ──────────────────────────────────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$wkSt      = $pdo->prepare("
    SELECT DAYOFWEEK(date) AS dow, SUM(status='P') AS present, SUM(status='A') AS absent
    FROM attendance
    WHERE college_id=? AND department_id=? AND date BETWEEN ? AND ? AND period IS NULL
    GROUP BY DAYOFWEEK(date)
");
$wkSt->execute([$activeColId, $activeDeptId, $weekStart, $weekEnd]);
$wkPresent = array_fill(0,7,0); $wkAbsent = array_fill(0,7,0);
foreach ($wkSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $idx = ($r['dow']+5)%7;
    $wkPresent[$idx] = (int)$r['present'];
    $wkAbsent[$idx]  = (int)$r['absent'];
}

// ── Build enriched student array ──────────────────────────────────────────────
$COLORS   = ['#00c6ae','#f4a261','#7c3aed','#3b82f6','#e76f51','#06b6d4','#52c41a','#a78bfa'];
$students = [];
foreach ($studentsRaw as $i => $s) {
    $p     = $pctMap[$s['id']] ?? null;
    $parts = explode(' ', trim($s['full_name']));
    $students[] = [
        'id'           => (int)$s['id'],
        'name'         => $s['full_name'],
        'roll'         => $s['roll_number'] ?: 'STU-'.str_pad($s['id'],3,'0',STR_PAD_LEFT),
        'color'        => $s['avatar_color'] ?: $COLORS[$i % count($COLORS)],
        'initials'     => strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):'')),
        'pct'          => $p ? round((float)$p['pct'], 1) : null,
        'total'        => (int)($p['total']     ?? 0),
        'present'      => (int)($p['present']   ?? 0),
        'absent'       => (int)($p['absent']    ?? 0),
        'leave'        => (int)($p['leave_cnt'] ?? 0),
        'daily_status' => $dailyMap[$s['id']]   ?? null,
        'remarks'      => $remarksMap[$s['id']] ?? '',
        'period_status'=> (object)($periodMap[$s['id']] ?? []),
    ];
}

$parts         = explode(' ', trim($fullName));
$userInitials  = strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):''));
$userRoleLabel = ucfirst(str_replace('_',' ', $role));

// ── Fetch avatar photo & color ────────────────────────────────────────────────
$avatarPath  = '';
$avatarColor = '#00d4bb';
if ($pdo && $userId) {
    $stAv = $pdo->prepare('SELECT avatar_path, avatar_color FROM users WHERE id = ? LIMIT 1');
    $stAv->execute([$userId]);
    if ($avRow = $stAv->fetch(PDO::FETCH_ASSOC)) {
        $avatarColor = $avRow['avatar_color'] ?: '#00d4bb';
        $rawPath     = $avRow['avatar_path']  ?? '';
        if ($rawPath && file_exists(__DIR__ . '/../' . $rawPath)) {
            $avatarPath = '../' . $rawPath . '?v=' . time();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduNexus — Attendance · <?= esc($activeDeptName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & tokens ──────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === DASHBOARD PALETTE (teal/amber/light) === */
  --teal:#0F766E;--teal-dark:#0D5C56;--teal-mid:#0F766E;
  --teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);--amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;
  --success:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;--text:#0F172A;--muted:#475569;
  --border:rgba(15,118,110,.10);--border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;--card-hover:#F0FDFA;
  --sidebar-w:264px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  --sb-text:rgba(255,255,255,.78);--sb-text-active:#FFFFFF;--sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);--sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);--sb-active-border:rgba(245,158,11,.38);
  /* Attendance status colours */
  --sp:#16A34A;--sa:#DC2626;--sl:#D97706;
  --sh:#1D4ED8;--slv:#7C3AED;--sml:#0891B2;--shol:#64748B;
}
html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;
}
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── Sidebar ─────────────────────────────────────────────────────────────────── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),inset 1px 0 0 rgba(20,184,166,.08),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;
  z-index:100;transition:transform .3s ease,width .3s ease;overflow:hidden}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);pointer-events:none}
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}
.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);
  display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12)}
.logo-mark{width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;
  box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15)}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}
.scope-chip{
  margin:10px 12px 0;
  background:linear-gradient(135deg,rgba(20,184,166,.13) 0%,rgba(15,118,110,.09) 100%);
  border:1px solid rgba(20,184,166,.32);
  border-radius:12px;padding:11px 13px 12px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 4px 16px rgba(0,0,0,.12);
  position:relative;overflow:hidden;
}
.scope-chip::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent)}
.sc-label{font-size:.52rem;color:rgba(94,234,212,.90);text-transform:uppercase;letter-spacing:.16em;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.sc-label i{font-size:.54rem}
.sc-college{font-size:.86rem;font-weight:800;color:#FFFFFF;line-height:1.25;margin-bottom:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;letter-spacing:.01em}
.sc-depts{display:flex;flex-direction:column;gap:4px;margin-top:0}
.sc-dept-tag{font-size:.72rem;color:rgba(186,230,253,.90);display:inline-flex;align-items:center;gap:5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.sc-dept-tag i{font-size:.62rem;flex-shrink:0;color:rgba(94,234,212,.80)}
.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;
  color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;
  margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;
  cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);
  display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;
  box-shadow:0 2px 10px rgba(245,158,11,.28)}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.63rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}
/* Mobile overlay */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.40);z-index:99;backdrop-filter:blur(2px)}
.sidebar-overlay.active{display:block}

/* ── Main ───────────────────────────────────────────────────────────────────── */
/* ── Collapse button (expanded) ─────────────────────────────────────────────── */
.sb-collapse-btn{
  margin-left:auto;flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);cursor:pointer;
  display:grid;place-items:center;font-size:.68rem;transition:all .2s;
}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber-acc)}

/* ── Collapsed sidebar ───────────────────────────────────────────────────────── */
:root{--sb-collapsed-w:72px}
.sidebar.collapsed{width:var(--sb-collapsed-w)}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0}
.sidebar.collapsed .logo-text{display:none !important}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem}
.sidebar.collapsed .scope-chip,.sidebar.collapsed .scope-chip *,.sidebar.collapsed .scope-chip::before{display:none !important;height:0 !important;max-height:0 !important;padding:0 !important;margin:0 !important;border:none !important;overflow:hidden !important;visibility:hidden !important;opacity:0 !important}
.sidebar.collapsed .nav-label{display:none !important;height:0 !important;margin:0 !important;padding:0 !important;overflow:hidden !important}
.sidebar.collapsed .sidebar-nav{padding:6px 8px 16px;margin-top:46px}
.sidebar.collapsed .nav-item{display:flex !important;justify-content:center !important;align-items:center !important;padding:10px 0 !important;gap:0 !important;width:100%;overflow:hidden;border-radius:10px}
.sidebar.collapsed .nav-item i{width:20px;text-align:center;font-size:.9rem;flex-shrink:0;margin:0;padding:0}
.sidebar.collapsed .nav-text,.sidebar.collapsed .nav-badge{display:none !important;width:0 !important;height:0 !important;overflow:hidden !important;padding:0 !important;margin:0 !important}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important}
.sidebar.collapsed .nav-item.active,.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12) !important;border-color:rgba(255,255,255,.15) !important;box-shadow:none !important;color:#fff !important}
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i,.sidebar.collapsed a.nav-item.active i{color:#fff !important;opacity:1 !important;visibility:visible !important;-webkit-text-fill-color:#fff !important}
.sidebar.collapsed .sidebar-user{padding:10px 0;justify-content:center;gap:0}
.sidebar.collapsed .user-info,.sidebar.collapsed .logout-btn{display:none !important;width:0 !important;overflow:hidden !important}
.sidebar.collapsed .user-avatar{margin:0 auto;flex-shrink:0}
.sidebar.collapsed .nav-item::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#0F172A;color:#fff;font-size:.71rem;font-weight:500;font-family:var(--font);padding:5px 11px;border-radius:7px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08)}
.sidebar.collapsed .nav-item:hover::after{opacity:1}

/* ── Main ───────────────────────────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease}
.sidebar.collapsed ~ .main,.shell:has(.sidebar.collapsed) .main{margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w))}
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:50;overflow:visible;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none !important}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:#fff;font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);
  background:rgba(255,255,255,.15);color:#ffffff;
  display:grid;place-items:center;cursor:pointer;transition:all .18s;font-size:.82rem;position:relative}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{font-size:.72rem;color:rgba(255,255,255,.72);background:rgba(255,255,255,.10);border:1px solid rgba(245,158,11,.20);padding:5px 11px;border-radius:7px;font-family:var(--mono)}
/* ── Topbar Avatar & Dropdown ──────────────────────────────────────────────── */
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
  min-width:200px;background:#fff;border:1px solid var(--border);
  border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);
  z-index:9999;opacity:0;pointer-events:none;transform:translateY(-8px);
  transition:opacity .18s ease,transform .18s ease;
}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{
  display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;
  color:var(--muted);font-size:.8rem;font-weight:500;text-decoration:none;
  transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;
}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:#94A3B8;flex-shrink:0}
.ad-item:hover{background:var(--card-hover);color:var(--text)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}
.content{padding:20px 24px;flex:1}

/* ── Selection wizard banner ────────────────────────────────────────────────── */
.wizard-banner{background:var(--teal-soft);border:1px solid rgba(15,118,110,.16);
  border-radius:12px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.wiz-step{display:flex;align-items:center;gap:8px;font-size:.8rem}
.wiz-num{width:22px;height:22px;border-radius:50%;background:var(--teal);color:#fff;
  font-weight:800;font-size:.65rem;display:grid;place-items:center;flex-shrink:0}
.wiz-num.done{background:var(--teal)}
.wiz-num.todo{background:rgba(15,118,110,.12);color:var(--muted)}
.wiz-lbl{color:var(--text);font-weight:500}
.wiz-val{color:var(--teal);font-weight:700;font-family:var(--mono);font-size:.75rem;
  background:var(--teal-soft2);padding:2px 8px;border-radius:5px;margin-left:4px}
.wiz-sep{color:var(--muted);font-size:.9rem}

/* ── Stats ──────────────────────────────────────────────────────────────────── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 18px;display:flex;flex-direction:column;gap:12px;
  animation:up .4s ease both;transition:transform .2s,box-shadow .2s;
  box-shadow:0 1px 8px rgba(15,118,110,.05)}
.stat:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(15,118,110,.10),0 2px 8px rgba(0,0,0,.05)}
.stat:nth-child(1){animation-delay:.08s}.stat:nth-child(2){animation-delay:.13s}
.stat:nth-child(3){animation-delay:.18s}.stat:nth-child(4){animation-delay:.23s}
.stat-top{display:flex;align-items:flex-start;justify-content:space-between}
.sicon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:.95rem;flex-shrink:0}
.si-g{background:var(--green2);color:var(--success)}.si-r{background:var(--red2);color:var(--red)}
.si-a{background:var(--amber-soft);color:var(--amber-acc)}.si-t{background:var(--teal-soft);color:var(--teal)}
.tag{font-size:.64rem;padding:2px 7px;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:3px;font-family:var(--mono)}
.tag.up{background:var(--green2);color:var(--success)}.tag.dn{background:var(--red2);color:var(--red)}
.tag.warn{background:var(--amber2);color:var(--amber)}.tag.info{background:var(--teal-soft);color:var(--teal)}
.stat-val{font-size:1.75rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.73rem;color:var(--muted);margin-top:2px}
.sbar{height:4px;border-radius:2px;background:rgba(15,118,110,.08);margin-top:4px;overflow:hidden}
.sbar-fill{height:100%;border-radius:2px;transition:width .8s ease}

/* ── Controls ───────────────────────────────────────────────────────────────── */
.ctrl-bar{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 22px;margin-bottom:16px;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.ctrl-row{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.ctrl-row+.ctrl-row{margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.ctrl-row-label{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--teal);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.ctrl-row-label i{font-size:.6rem}
.cg{display:flex;flex-direction:column;gap:5px}
.clbl{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.csel,.cinp{background:var(--teal-soft);border:1px solid rgba(15,118,110,.25);color:var(--teal);
  font-size:.82rem;font-family:var(--font);padding:8px 11px;border-radius:9px;
  outline:none;cursor:pointer;transition:border-color .2s,box-shadow .2s;min-width:140px}
.csel:focus,.cinp:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.10)}
.csel option{background:#fff}
.cright{margin-left:auto;display:flex;align-items:flex-end;gap:8px}
.cright-locked{font-size:.82rem;color:var(--teal);background:var(--teal-soft);
  border:1px solid rgba(15,118,110,.25);border-radius:9px;padding:8px 11px;
  display:flex;align-items:center;gap:6px;font-family:var(--font);min-width:140px}
.cright-locked i{color:var(--teal);font-size:.72rem}

/* ── Subject selector row (pill tabs) ───────────────────────────────────────── */
.subj-row{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:14px 22px;margin-bottom:16px;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.subj-row-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.subj-row-title{font-size:.82rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.subj-row-title i{color:var(--teal)}
.subj-pills{display:flex;flex-wrap:wrap;gap:8px}
.subj-pill{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:9px;
  border:1px solid rgba(15,118,110,.25);background:var(--teal-soft);color:var(--teal);
  font-size:.82rem;font-family:var(--font);font-weight:500;cursor:pointer;transition:all .18s;white-space:nowrap}
.subj-pill:hover{border-color:rgba(15,118,110,.35);background:var(--teal-soft);color:var(--teal)}
.subj-pill.active{background:var(--teal-soft);border-color:rgba(15,118,110,.35);color:var(--teal);font-weight:700}
.subj-pill .sem-badge{font-size:.62rem;background:rgba(15,118,110,.10);color:var(--teal);padding:1px 6px;border-radius:10px;font-family:var(--mono)}
.subj-pill.active .sem-badge{background:rgba(15,118,110,.10)}
.subj-none{font-size:.82rem;color:var(--muted);font-style:italic;padding:4px 0}

/* ── Mode tabs ──────────────────────────────────────────────────────────────── */
.mode-tabs{display:flex;gap:6px;margin-bottom:20px}
.mode-tab{display:flex;align-items:center;gap:8px;padding:9px 18px;border-radius:9px;
  border:1px solid var(--border);background:#F8FAFC;color:var(--muted);
  font-size:.8rem;font-weight:500;cursor:pointer;transition:all .18s;white-space:nowrap}
.mode-tab:hover{border-color:var(--border-accent);color:var(--teal)}
.mode-tab.active{background:var(--teal-soft2);border-color:var(--border-accent);color:var(--teal);font-weight:600}
.att-mode{display:none}
.att-mode.active{display:block}

/* ── Panel ──────────────────────────────────────────────────────────────────── */
.panel{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  box-shadow:0 1px 8px rgba(15,118,110,.05);margin-bottom:20px;overflow:hidden;animation:up .45s .2s ease both}
.ph{display:flex;align-items:center;justify-content:space-between;
  padding:16px 22px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px;background:#F0FDFA}
.pt{font-size:.9rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.pt i{color:var(--teal);font-size:.8rem}
.pa{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

/* ── Bulk strip ─────────────────────────────────────────────────────────────── */
.bulk-strip{background:var(--teal-soft);border-bottom:1px solid rgba(15,118,110,.14);
  padding:10px 22px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bl{color:var(--teal);font-weight:600;font-size:.8rem;flex-shrink:0}
.bulk-btns{display:flex;gap:5px;flex-wrap:wrap}
.sbtn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:7px;
  font-size:.73rem;font-weight:600;cursor:pointer;border:1.5px solid transparent;
  transition:all .15s;font-family:var(--font)}
.sbtn:hover{transform:translateY(-1px)}
.sbtn.P{background:rgba(22,163,74,.1);color:var(--sp);border-color:rgba(22,163,74,.3)}
.sbtn.P:hover{background:rgba(22,163,74,.2)}
.sbtn.A{background:var(--red2);color:var(--sa);border-color:rgba(220,38,38,.3)}
.sbtn.A:hover{background:rgba(220,38,38,.2)}
.sbtn.L{background:var(--amber-soft);color:var(--sl);border-color:rgba(217,119,6,.3)}
.sbtn.L:hover{background:var(--amber-soft2)}
.sbtn.H{background:var(--blue2);color:var(--sh);border-color:rgba(29,78,216,.3)}
.sbtn.H:hover{background:rgba(29,78,216,.2)}
.sbtn.LV{background:var(--purple2);color:var(--slv);border-color:rgba(124,58,237,.3)}
.sbtn.LV:hover{background:rgba(124,58,237,.2)}
.sbtn.ML{background:rgba(8,145,178,.1);color:var(--sml);border-color:rgba(8,145,178,.3)}
.sbtn.ML:hover{background:rgba(8,145,178,.2)}
.sbtn.HOL{background:rgba(100,116,139,.1);color:var(--shol);border-color:rgba(100,116,139,.3)}
.sbtn.HOL:hover{background:rgba(100,116,139,.2)}

/* ── Table ──────────────────────────────────────────────────────────────────── */
.twrap{overflow-x:auto}
.att-tbl{width:100%;border-collapse:collapse}
.att-tbl thead th{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--muted);padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);
  background:#F8FAFC;white-space:nowrap}
.att-tbl tbody td{padding:12px 16px;font-size:.82rem;color:var(--text);vertical-align:middle}
.att-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
.att-tbl tbody tr:last-child{border-bottom:none}
.att-tbl tbody tr:hover{background:#F0FDFA}
.si{display:flex;align-items:center;gap:10px}
.av{width:34px;height:34px;border-radius:9px;flex-shrink:0;display:grid;place-items:center;
  font-size:.76rem;font-weight:700;color:#fff}
.sn{font-weight:600;color:var(--text);font-size:.84rem}
.sr{font-size:.68rem;color:var(--muted);margin-top:1px}
.mono{font-family:var(--mono);font-size:.7rem;color:var(--muted)}

/* ── Row status buttons ─────────────────────────────────────────────────────── */
.ssw{display:flex;gap:4px;flex-wrap:nowrap}
.asb{width:30px;height:30px;border-radius:7px;border:1.5px solid transparent;
  display:grid;place-items:center;font-size:.68rem;cursor:pointer;transition:all .15s;
  flex-shrink:0;background:#F8FAFC;color:var(--muted);
  font-family:var(--font);font-weight:600;position:relative}
.asb[data-active=true]{opacity:1;transform:scale(1.1)}
.asb:not([data-active=true]){opacity:.35}
.asb.P[data-active=true]{background:rgba(22,163,74,.15);color:var(--sp);border-color:rgba(22,163,74,.4)}
.asb.A[data-active=true]{background:rgba(239,68,68,.15);color:var(--sa);border-color:rgba(239,68,68,.4)}
.asb.L[data-active=true]{background:rgba(245,158,11,.15);color:var(--sl);border-color:rgba(245,158,11,.4)}
.asb.H[data-active=true]{background:rgba(59,130,246,.15);color:var(--sh);border-color:rgba(59,130,246,.4)}
.asb.LV[data-active=true]{background:rgba(139,92,246,.15);color:var(--slv);border-color:rgba(139,92,246,.4)}
.asb.ML[data-active=true]{background:rgba(6,182,212,.15);color:var(--sml);border-color:rgba(6,182,212,.4)}
.asb.HOL[data-active=true]{background:rgba(100,116,139,.15);color:var(--shol);border-color:rgba(100,116,139,.4)}

/* ── Pct bar ────────────────────────────────────────────────────────────────── */
.pw{display:flex;align-items:center;gap:8px;min-width:110px}
.pb{flex:1;height:6px;border-radius:3px;background:rgba(15,118,110,.10);overflow:hidden;min-width:60px}
.pf{height:100%;border-radius:3px;width:0%;transition:width 1s ease}
.pf.hi{background:linear-gradient(90deg,var(--success),#4ade80)}
.pf.md{background:linear-gradient(90deg,var(--amber-acc),#fbbf24)}
.pf.lo{background:linear-gradient(90deg,var(--red),#f87171)}
.pn{font-size:.76rem;font-weight:700;white-space:nowrap}
.pn.hi{color:var(--success)}.pn.md{color:var(--amber-acc)}.pn.lo{color:var(--red)}

/* ── Period grid ────────────────────────────────────────────────────────────── */
.period-cell{text-align:center;min-width:62px}
.pp{display:inline-flex;align-items:center;justify-content:center;padding:4px 9px;
  border-radius:6px;font-size:.7rem;font-weight:700;cursor:pointer;
  transition:all .15s;user-select:none;min-width:40px}
.pp.P{background:rgba(22,163,74,.13);color:var(--sp)}
.pp.A{background:rgba(220,38,38,.13);color:var(--sa)}
.pp.L{background:rgba(217,119,6,.13);color:var(--sl)}
.pp.H{background:rgba(29,78,216,.13);color:var(--sh)}
.pp.LV{background:rgba(124,58,237,.13);color:var(--slv)}
.pp.ML{background:rgba(8,145,178,.13);color:var(--sml)}
.pp.HOL{background:rgba(100,116,139,.12);color:var(--shol)}
.pp:hover{filter:brightness(.92);transform:scale(1.06)}

/* ── Summary bar ────────────────────────────────────────────────────────────── */
.sumbar{padding:11px 22px;border-top:1px solid var(--border);
  display:flex;gap:18px;flex-wrap:wrap;font-size:.76rem;background:#F8FAFC}

/* ── Period controls ────────────────────────────────────────────────────────── */
.pctrl{padding:12px 22px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#F0FDFA}
.plbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em}
.pmsel{background:#fff;border:1px solid var(--border);color:var(--text);
  font-size:.76rem;padding:5px 10px;border-radius:7px;outline:none;cursor:pointer}
.pmsel option{background:#fff}
.plgnd{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.68rem;color:var(--muted);margin-left:auto}
.pldot{width:8px;height:8px;border-radius:2px;flex-shrink:0}

/* ── Bulk search ────────────────────────────────────────────────────────────── */
.bsw{padding:12px 22px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bsinner{position:relative;flex:1;max-width:320px}
.bsinner i{position:absolute;left:11px;top:50%;transform:translateY(-50%);
  color:var(--muted);font-size:.76rem;pointer-events:none}
.bsinp{background:#fff;border:1px solid var(--border);color:var(--text);
  font-size:.82rem;font-family:var(--font);padding:8px 11px 8px 34px;border-radius:9px;
  outline:none;transition:border-color .2s;width:100%}
.bsinp:focus{border-color:var(--teal-light)}

/* ── Bottom grid ────────────────────────────────────────────────────────────── */
.bottom{display:grid;grid-template-columns:1fr 360px;gap:16px;margin-bottom:24px}
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  box-shadow:0 1px 8px rgba(15,118,110,.05);overflow:hidden;animation:up .5s .3s ease both}
.ch{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:#F0FDFA}
.ct{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.ct i{color:var(--teal);font-size:.8rem}

/* ── Weekly chart ───────────────────────────────────────────────────────────── */
.wchart{padding:18px 22px}
.wbars{display:flex;align-items:flex-end;gap:8px;height:120px}
.wcol{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%}
.wtrack{flex:1;width:100%;display:flex;flex-direction:row;align-items:flex-end;gap:2px;height:100%}
.wbar{flex:1;border-radius:4px 4px 0 0;transition:height 1s cubic-bezier(.34,1.56,.64,1);min-height:2px;height:0%}
.wbar.g{background:linear-gradient(180deg,var(--success),#16a34a)}
.wbar.r{background:linear-gradient(180deg,var(--red),#b91c1c)}
.wlbl{font-size:.63rem;color:var(--muted)}
.wleg{display:flex;gap:14px;margin-top:12px}
.wli{display:flex;align-items:center;gap:5px;font-size:.7rem;color:var(--muted)}
.wld{width:8px;height:8px;border-radius:2px}

/* ── Defaulters ─────────────────────────────────────────────────────────────── */
.dlist{padding:6px 0}
.ditem{display:flex;align-items:center;gap:11px;padding:10px 20px;transition:background .15s}
.ditem:hover{background:#F0FDFA}
.dav{width:32px;height:32px;border-radius:8px;flex-shrink:0;display:grid;place-items:center;
  font-size:.7rem;font-weight:700;color:#fff}
.din{flex:1;min-width:0}
.dn{font-size:.82rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ds{font-size:.68rem;color:var(--muted)}
.dpct{font-size:.84rem;font-weight:700;color:var(--red)}
.dbadge{font-size:.6rem;font-weight:700;padding:2px 7px;border-radius:5px;
  background:var(--red2);color:var(--red)}

/* ── History ────────────────────────────────────────────────────────────────── */
.hist-ctrl{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;
  padding:14px 22px;border-bottom:1px solid var(--border);background:#F0FDFA}
.hist-table-wrap{overflow-x:auto;max-height:520px;overflow-y:auto}
.hist-tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.hist-tbl thead th{position:sticky;top:0;z-index:2;
  background:#F8FAFC;font-size:.62rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:var(--muted);padding:10px 12px;text-align:center;border-bottom:1px solid var(--border);white-space:nowrap}
.hist-tbl thead th:first-child,.hist-tbl thead th:nth-child(2){text-align:left}
.hist-tbl tbody td{padding:9px 10px;vertical-align:middle;border-bottom:1px solid var(--border)}
.hist-tbl tbody tr:hover{background:#F0FDFA}
.hist-tbl tbody td:not(:first-child):not(:nth-child(2)){text-align:center}
.hst{display:inline-flex;align-items:center;justify-content:center;
  width:28px;height:22px;border-radius:5px;font-size:.66rem;font-weight:700;cursor:default;user-select:none}
.hst.P{background:rgba(22,163,74,.15);color:var(--sp)}
.hst.A{background:rgba(220,38,38,.15);color:var(--sa)}
.hst.L{background:rgba(217,119,6,.15);color:var(--sl)}
.hst.H{background:rgba(29,78,216,.15);color:var(--sh)}
.hst.LV{background:rgba(124,58,237,.15);color:var(--slv)}
.hst.ML{background:rgba(8,145,178,.15);color:var(--sml)}
.hst.HOL{background:rgba(100,116,139,.15);color:var(--shol)}
.hst.none{background:rgba(15,118,110,.06);color:var(--muted);font-size:.55rem}
.hist-pct{font-size:.76rem;font-weight:700}
.hist-pct.hi{color:var(--success)}.hist-pct.md{color:var(--amber-acc)}.hist-pct.lo{color:var(--red)}
.hist-loading{padding:40px;text-align:center;color:var(--muted);font-size:.84rem}
.hist-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 22px;
  border-bottom:1px solid var(--border);background:#F8FAFC;font-size:.76rem}
.hist-legend{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.hist-legend-item{display:flex;align-items:center;gap:4px;font-size:.66rem;color:var(--muted)}

/* ── Buttons ────────────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 17px;border-radius:10px;
  font-size:.8rem;font-weight:600;font-family:var(--font);cursor:pointer;border:none;
  transition:all .2s;text-decoration:none;white-space:nowrap}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-teal:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(15,118,110,.3)}
.btn-out{background:var(--teal-soft);border:1px solid rgba(15,118,110,.25);color:var(--teal);padding:8px 11px;border-radius:9px}
.btn-out:hover{border-color:var(--teal-light);color:var(--teal-dark);background:var(--teal-soft2)}
.btn-ghost{background:#F8FAFC;color:var(--text);border:1px solid var(--border)}
.btn-ghost:hover{background:#F0FDFA}
.btn-green{background:linear-gradient(135deg,var(--success),#15803d);color:#fff}
.btn-green:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(22,163,74,.3)}
.rchk{width:15px;height:15px;accent-color:var(--teal);cursor:pointer}

/* ── Save bar ───────────────────────────────────────────────────────────────── */
.save-bar{display:none;position:fixed;bottom:26px;
  left:calc(var(--sidebar-w) + 28px);right:28px;z-index:200;
  background:linear-gradient(135deg,#0F766E,#14B8A6);border-radius:13px;padding:13px 20px;
  align-items:center;gap:12px;box-shadow:0 8px 40px rgba(15,118,110,.35);
  backdrop-filter:blur(10px)}
.save-bar-text{flex:1;font-weight:600;color:#fff;font-size:.85rem}
.sav-save{background:#fff;color:var(--teal);padding:7px 16px;border-radius:8px;
  font-weight:700;cursor:pointer;border:none;font-family:var(--font);font-size:.8rem;transition:all .2s}
.sav-save:hover{background:#F0FDFA}
.sav-dis{background:transparent;color:rgba(255,255,255,.7);padding:7px 12px;border-radius:8px;
  cursor:pointer;border:none;font-family:var(--font);font-size:.8rem;transition:all .2s}
.sav-dis:hover{background:rgba(0,0,0,.08)}

/* ── Toast ──────────────────────────────────────────────────────────────────── */
.toast{position:fixed;top:82px;right:28px;z-index:300;background:#fff;
  border:1px solid var(--border);border-radius:11px;padding:13px 17px;
  display:flex;align-items:center;gap:9px;font-size:.82rem;color:var(--text);
  box-shadow:0 8px 30px rgba(15,118,110,.12);
  transform:translateX(120%);transition:transform .3s ease;max-width:300px}
.toast.show{transform:translateX(0)}
.toast.success i{color:var(--success)}.toast.error i{color:var(--red)}.toast.info i{color:var(--teal)}

/* ── Empty ──────────────────────────────────────────────────────────────────── */
.empty{padding:42px 24px;text-align:center;color:var(--muted)}
.empty i{font-size:1.8rem;display:block;margin-bottom:10px;opacity:.3}
.empty p{font-size:.82rem}

/* ── Legend ─────────────────────────────────────────────────────────────────── */
.legend{display:flex;gap:14px;flex-wrap:wrap;padding:10px 0 0;font-size:.7rem;color:var(--muted)}
.li{display:flex;align-items:center;gap:5px}
.ld{width:8px;height:8px;border-radius:2px;flex-shrink:0}

/* ── Animations ─────────────────────────────────────────────────────────────── */
@keyframes up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}

/* ── Scrollbar ──────────────────────────────────────────────────────────────── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:1280px){.bottom{grid-template-columns:1fr}}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,1fr)}.bottom{grid-template-columns:1fr}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}.content{padding:14px}.stats{grid-template-columns:1fr 1fr}
  .hamburger{display:grid}.mode-tabs{flex-wrap:wrap}
  .save-bar{left:14px;right:14px;bottom:14px}
  .ctrl-row{flex-direction:column;align-items:stretch}
  .cright{margin-left:0}
  .csel,.cinp{min-width:0;width:100%}
  .subj-pills{gap:6px}
  .subj-pill{font-size:.73rem;padding:6px 12px}
  .wizard-banner{gap:8px}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .mode-tab span{display:none}
  .mode-tab{padding:9px 12px}
  .hist-ctrl{flex-direction:column;align-items:stretch}
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

<div class="shell">

<!-- Mobile sidebar overlay backdrop -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">PE</div>
    <div class="logo-text">PEPA <span>ERP Platform</span></div>
    <button class="sb-collapse-btn" id="sidebarCollapseBtn" onclick="collapseSidebar()" title="Collapse sidebar">
      <i class="fas fa-angles-left" id="collapseIcon"></i>
    </button>
  </div>

  <div class="sidebar-scroll">
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college" id="sideCollegeName"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
        <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><span id="sideDeptName"><?= esc($activeDeptName) ?></span></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php"  class="nav-item" data-tip="Dashboard"><i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span></a>
      <a href="students.php"   class="nav-item" data-tip="Students"><i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span></a>
      <div class="nav-label">Academic</div>
      <a href="attendance.php" class="nav-item active" data-tip="Attendance"><i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span></a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results"><i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span></a>
      <a href="examinations.php"   class="nav-item" data-tip="Examinations"><i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span></a>
      <a href="timetable.php"      class="nav-item" data-tip="Timetable"><i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span></a>
      <div class="nav-label">Communication</div>
      <a href="staff_noticeboard.php" class="nav-item" data-tip="Staff Noticeboard"><i class="fas fa-clipboard-list"></i><span class="nav-text"> Staff Noticeboard</span></a>
      <a href="leave_application.php" class="nav-item" data-tip="Leave Application"><i class="fas fa-calendar-minus"></i><span class="nav-text"> Leave Application</span></a>
      <a href="expense_apply.php"     class="nav-item" data-tip="Expense Apply"><i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span></a>
      <div class="nav-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile"><i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span></a>
    </nav>
  </div><!-- /sidebar-scroll -->

  <!-- Pinned user bar -->
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($userInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= esc($userRoleLabel) ?> · <span id="sideScope"><?= esc($activeDeptCode ?: $activeDeptName) ?></span></div>
    </div>
    <button class="logout-btn" onclick="doLogout()" title="Logout">
      <i class="fas fa-arrow-right-from-bracket"></i>
    </button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <button class="topbar-btn hamburger" id="menuToggle" onclick="toggleSidebar()">
      <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-title">
      Attendance <span id="topbarSub">/ Daily Marking</span>
    </div>
    <div class="topbar-actions">
      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
      </div>
      <!-- Avatar dropdown -->
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= esc($fullName) ?>"
             style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>">
          <?php if ($avatarPath): ?>
            <img src="<?= esc($avatarPath) ?>" alt="<?= esc($userInitials) ?>"
                 style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
          <?php else: ?>
            <?= esc($userInitials) ?>
          <?php endif; ?>
        </div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= esc($fullName) ?></div>
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= esc($userRoleLabel) ?></div>
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


    <!-- Stats -->
    <div class="stats">
      <div class="stat">
        <div class="stat-top"><div class="sicon si-g"><i class="fas fa-circle-check"></i></div>
          <div class="tag up" id="tagPresent"><i class="fas fa-users"></i> —</div></div>
        <div><div class="stat-val" id="stPresent">0</div><div class="stat-lbl">Present Today</div></div>
        <div class="sbar"><div class="sbar-fill" id="barP" style="background:var(--green);width:0%"></div></div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="sicon si-r"><i class="fas fa-circle-xmark"></i></div>
          <div class="tag dn" id="tagAbsent"><i class="fas fa-users"></i> —</div></div>
        <div><div class="stat-val" id="stAbsent">0</div><div class="stat-lbl">Absent Today</div></div>
        <div class="sbar"><div class="sbar-fill" id="barA" style="background:var(--red);width:0%"></div></div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="sicon si-a"><i class="fas fa-clock-rotate-left"></i></div>
          <div class="tag warn"><i class="fas fa-minus"></i> —</div></div>
        <div><div class="stat-val" id="stLate">0</div><div class="stat-lbl">Late / Half-day</div></div>
        <div class="sbar"><div class="sbar-fill" id="barL" style="background:var(--amber);width:0%"></div></div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="sicon si-t"><i class="fas fa-percent"></i></div>
          <div class="tag info"><i class="fas fa-chart-line"></i> live</div></div>
        <div>
          <div class="stat-val" id="stPct">0<span style="font-size:1.1rem;color:var(--muted)">%</span></div>
          <div class="stat-lbl">Overall Attendance</div>
        </div>
        <div class="sbar"><div class="sbar-fill" id="barPct" style="background:var(--teal);width:0%"></div></div>
      </div>
    </div>

    <!-- Controls Row 1: Date + College + Department -->
    <div class="ctrl-bar">
      <div style="font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:12px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-sliders" style="color:var(--teal)"></i> Step 1 — Choose Scope &amp; Date
      </div>
      <div class="ctrl-row">
        <div class="cg">
          <label class="clbl">Date</label>
          <input type="date" class="cinp" id="attDate" value="<?= date('Y-m-d') ?>" onchange="onDateChange()">
        </div>
        <?php if ($role === 'super_admin'): ?>
        <div class="cg">
          <label class="clbl">College</label>
          <select class="csel" id="selCollege" onchange="onCollegeChange()">
            <?php foreach ($colleges as $col): ?>
              <option value="<?= $col['id'] ?>" <?= $col['id'] == $activeColId ? 'selected' : '' ?>>
                <?= esc($col['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cg">
          <label class="clbl">Department</label>
          <select class="csel" id="selDept" onchange="onDeptChange()">
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $d['id'] == $activeDeptId ? 'selected' : '' ?>>
                <?= esc($d['code'].' — '.$d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif ($role === 'college_admin'): ?>
        <div class="cg">
          <label class="clbl">College (locked)</label>
          <div class="cright-locked"><i class="fas fa-lock"></i><?= esc($collegeName) ?></div>
        </div>
        <div class="cg">
          <label class="clbl">Department</label>
          <select class="csel" id="selDept" onchange="onDeptChange()">
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $d['id'] == $activeDeptId ? 'selected' : '' ?>>
                <?= esc($d['code'].' — '.$d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <div class="cg">
          <label class="clbl">College (locked)</label>
          <div class="cright-locked"><i class="fas fa-lock"></i><?= esc($collegeName) ?></div>
        </div>
        <div class="cg">
          <?php if (count($departments) > 1): ?>
            <!-- FIX: faculty with multiple dept assignments gets a selector -->
            <label class="clbl">Department</label>
            <select class="csel" id="selDept" onchange="onDeptChange()">
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $d['id'] == $activeDeptId ? 'selected' : '' ?>>
                  <?= esc($d['code'].' — '.$d['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <label class="clbl">Department (locked)</label>
            <div class="cright-locked"><i class="fas fa-lock"></i><?= esc($activeDeptName) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="cright">
          <button class="btn btn-out" onclick="exportAttendance()"><i class="fas fa-download"></i> Export CSV</button>
          <button class="btn btn-teal" onclick="saveAttendance()"><i class="fas fa-floppy-disk"></i> Save Attendance</button>
        </div>
      </div>
      <!-- Row 2: Year + Section filters -->
      <div class="ctrl-row" id="yearSectionRow" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
        <div class="cg">
          <label class="clbl"><i class="fas fa-layer-group" style="font-size:.65rem"></i> Year / Batch</label>
          <select class="csel" id="selYear" onchange="onYearSectionChange()">
            <option value="">All Years</option>
          </select>
        </div>
        <div class="cg">
          <label class="clbl"><i class="fas fa-graduation-cap" style="font-size:.65rem"></i> Semester</label>
          <select class="csel" id="selSemester" onchange="onYearSectionChange()">
            <option value="">All Semesters</option>
          </select>
        </div>
        <div class="cg">
          <label class="clbl"><i class="fas fa-users-rectangle" style="font-size:.65rem"></i> Section</label>
          <select class="csel" id="selSection" onchange="onYearSectionChange()">
            <option value="">All Sections</option>
          </select>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;padding-bottom:2px">
          <span id="studentCountChip" style="font-size:.72rem;color:var(--muted);background:var(--teal-soft);padding:5px 12px;border-radius:8px;border:1px solid var(--border-accent);display:none">
            <i class="fas fa-users" style="font-size:.65rem;color:var(--teal)"></i>
            <span id="studentCountVal">0</span> students
          </span>
        </div>
      </div>
    </div>

    <!-- Subject Selector Row — Step 2 -->
    <div class="subj-row" id="subjRow">
      <div class="subj-row-header">
        <div class="subj-row-title">
          <i class="fas fa-book"></i> Step 2 — Select Subject
          <?php if ($role === 'faculty'): ?>
            <span style="font-size:.68rem;color:var(--text);font-weight:400;margin-left:4px">(your assigned courses)</span>
          <?php endif; ?>
        </div>
        <span id="subjRowInfo" style="font-size:.72rem;color:var(--muted);margin-left:auto"></span>
      </div>
      <div class="subj-pills" id="subjPills">
        <?php if (empty($courses)): ?>
          <span class="subj-none">
            <?= $role === 'faculty'
              ? 'No courses assigned to you in this department. Contact the admin.'
              : 'No active courses found for this department.' ?>
          </span>
        <?php else: ?>
          <?php foreach ($courses as $i => $c): ?>
            <button class="subj-pill <?= $i===0 ? 'active' : '' ?>"
                    data-cid="<?= $c['id'] ?>"
                    data-cname="<?= esc($c['name']) ?>"
                    data-ccode="<?= esc($c['code']) ?>"
                    data-sem="<?= $c['semester'] ?>"
                    onclick="selectSubject(this)">
              <i class="fas fa-book-open" style="font-size:.7rem"></i>
              <?= esc($c['code']) ?> — <?= esc($c['name']) ?>
              <?php if ($c['semester']): ?>
                <span class="sem-badge">Sem <?= $c['semester'] ?></span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Mode tabs -->
    <div class="mode-tabs">
      <button class="mode-tab active" onclick="switchMode('daily',   this)"><i class="fas fa-calendar-day"></i> Daily</button>
      <button class="mode-tab"        onclick="switchMode('period',   this)"><i class="fas fa-clock"></i> Period-wise</button>
      <button class="mode-tab"        onclick="switchMode('bulk',     this)"><i class="fas fa-list-check"></i> Bulk Marking</button>
      <button class="mode-tab"        onclick="switchMode('history', this)"><i class="fas fa-clock-rotate-left"></i> History</button>
    </div>

    <!-- ══ MODE 1: DAILY ══════════════════════════════════════════════════════ -->
    <div id="mode-daily" class="att-mode active">
      <div class="panel">
        <div class="ph">
          <div class="pt"><i class="fas fa-clipboard-check"></i> Daily Attendance
            <span style="color:var(--muted);font-size:.78rem;font-weight:400" id="dailySub"></span>
          </div>
        </div>
        <div class="bulk-strip">
          <span class="bl"><i class="fas fa-wand-magic-sparkles"></i> Quick mark all:</span>
          <div class="bulk-btns">
            <button class="sbtn P"   onclick="bulkMark('P')"><i class="fas fa-check"></i> Present</button>
            <button class="sbtn A"   onclick="bulkMark('A')"><i class="fas fa-xmark"></i> Absent</button>
            <button class="sbtn L"   onclick="bulkMark('L')"><i class="fas fa-clock"></i> Late</button>
            <button class="sbtn H"   onclick="bulkMark('H')"><i class="fas fa-circle-half-stroke"></i> Half Day</button>
            <button class="sbtn LV"  onclick="bulkMark('LV')"><i class="fas fa-plane-departure"></i> Leave</button>
            <button class="sbtn ML"  onclick="bulkMark('ML')"><i class="fas fa-kit-medical"></i> Medical</button>
            <button class="sbtn HOL" onclick="bulkMark('HOL')"><i class="fas fa-star"></i> Holiday</button>
          </div>
        </div>
        <div style="padding:10px 22px 0">
          <div class="legend">
            <div class="li"><div class="ld" style="background:var(--sp)"></div>Present (P)</div>
            <div class="li"><div class="ld" style="background:var(--sa)"></div>Absent (A)</div>
            <div class="li"><div class="ld" style="background:var(--sl)"></div>Late (L)</div>
            <div class="li"><div class="ld" style="background:var(--sh)"></div>Half Day (H)</div>
            <div class="li"><div class="ld" style="background:var(--slv)"></div>Leave (LV)</div>
            <div class="li"><div class="ld" style="background:var(--sml)"></div>Medical (ML)</div>
            <div class="li"><div class="ld" style="background:var(--shol)"></div>Holiday (HOL)</div>
          </div>
        </div>
        <div class="twrap">
          <table class="att-tbl">
            <thead>
              <tr>
                <th style="width:36px"><input type="checkbox" class="rchk" id="chkAll" onchange="toggleAll(this)"></th>
                <th>#</th><th>Student</th><th>Roll No.</th>
                <th>Mark Status</th><th>Attendance %</th><th>Remarks</th>
              </tr>
            </thead>
            <tbody id="dailyBody"></tbody>
          </table>
        </div>
        <div class="sumbar" id="dailySumBar"></div>
      </div>
    </div>

    <!-- ══ MODE 2: PERIOD-WISE ════════════════════════════════════════════════ -->
    <div id="mode-period" class="att-mode">
      <div class="panel">
        <div class="ph">
          <div class="pt"><i class="fas fa-clock"></i> Period-wise Attendance
            <span style="color:var(--muted);font-size:.78rem;font-weight:400" id="periodSub"></span>
          </div>
          <div class="pa">
            <button class="btn btn-ghost" onclick="allPeriodPresent()" style="padding:6px 13px;font-size:.76rem"><i class="fas fa-check-double"></i> All Present</button>
            <button class="btn btn-out"   onclick="allPeriodHoliday()" style="padding:6px 13px;font-size:.76rem;color:var(--shol)"><i class="fas fa-star"></i> Holiday</button>
          </div>
        </div>
        <div class="pctrl">
          <span class="plbl">View Period:</span>
          <select class="pmsel" id="periodFilter" onchange="renderPeriodTable()">
            <option value="all">All Periods</option>
            <option value="P1">P1 (8–9)</option><option value="P2">P2 (9–10)</option>
            <option value="P3">P3 (10–11)</option><option value="P4">P4 (11–12)</option>
            <option value="P5">P5 (1–2)</option><option value="P6">P6 (2–3)</option>
          </select>
          <span class="plbl" style="margin-left:12px">Bulk Mark Period:</span>
          <select class="pmsel" id="periodBulkTgt">
            <option value="P1">P1</option><option value="P2">P2</option>
            <option value="P3">P3</option><option value="P4">P4</option>
            <option value="P5">P5</option><option value="P6">P6</option>
          </select>
          <div class="bulk-btns" style="margin-left:4px">
            <button class="sbtn P"   onclick="bulkPeriod('P')"><i class="fas fa-check"></i> P</button>
            <button class="sbtn A"   onclick="bulkPeriod('A')"><i class="fas fa-xmark"></i> A</button>
            <button class="sbtn L"   onclick="bulkPeriod('L')"><i class="fas fa-clock"></i> L</button>
            <button class="sbtn LV"  onclick="bulkPeriod('LV')"><i class="fas fa-plane-departure"></i> LV</button>
            <button class="sbtn HOL" onclick="bulkPeriod('HOL')"><i class="fas fa-star"></i> HOL</button>
          </div>
          <div class="plgnd">
            <div class="pldot" style="background:var(--sp)"></div>P=Present
            <div class="pldot" style="background:var(--sa)"></div>A=Absent
            <div class="pldot" style="background:var(--sl)"></div>L=Late
            <span style="font-style:italic">· Click cell to cycle</span>
          </div>
        </div>
        <div class="twrap">
          <table class="att-tbl">
            <thead id="periodHead"></thead>
            <tbody id="periodBody"></tbody>
          </table>
        </div>
        <div class="sumbar" id="periodSumBar"></div>
      </div>
    </div>

    <!-- ══ MODE 3: BULK ═══════════════════════════════════════════════════════ -->
    <div id="mode-bulk" class="att-mode">
      <div class="panel">
        <div class="ph">
          <div class="pt"><i class="fas fa-list-check"></i> Bulk Attendance Marking</div>
          <div class="pa">
            <span style="font-size:.76rem;color:var(--muted)" id="bulkCount">0 selected</span>
            <button class="btn btn-out" onclick="selectAbsent()" style="padding:6px 13px;font-size:.76rem"><i class="fas fa-filter"></i> Select Absent</button>
            <button class="btn btn-green" onclick="applyBulk()" style="padding:6px 13px;font-size:.76rem"><i class="fas fa-check"></i> Apply to Selected</button>
          </div>
        </div>
        <div class="bulk-strip">
          <span class="bl">Apply status:</span>
          <div class="bulk-btns" id="bulkStatusBtns">
            <button class="sbtn P"   onclick="setBulkSt('P')"   id="bs-P"><i class="fas fa-check"></i> Present</button>
            <button class="sbtn A"   onclick="setBulkSt('A')"   id="bs-A"><i class="fas fa-xmark"></i> Absent</button>
            <button class="sbtn L"   onclick="setBulkSt('L')"   id="bs-L"><i class="fas fa-clock"></i> Late</button>
            <button class="sbtn H"   onclick="setBulkSt('H')"   id="bs-H"><i class="fas fa-circle-half-stroke"></i> Half Day</button>
            <button class="sbtn LV"  onclick="setBulkSt('LV')"  id="bs-LV"><i class="fas fa-plane-departure"></i> Leave</button>
            <button class="sbtn ML"  onclick="setBulkSt('ML')"  id="bs-ML"><i class="fas fa-kit-medical"></i> Medical</button>
            <button class="sbtn HOL" onclick="setBulkSt('HOL')" id="bs-HOL"><i class="fas fa-star"></i> Holiday</button>
          </div>
        </div>
        <div class="bsw">
          <div class="bsinner">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" class="bsinp" id="bulkSearch" placeholder="Search by name or roll…" oninput="renderBulkTable()">
          </div>
          <select class="pmsel" id="bulkFilter" onchange="renderBulkTable()">
            <option value="all">All Students</option>
            <option value="P">Present only</option><option value="A">Absent only</option>
            <option value="L">Late only</option><option value="H">Half Day</option>
            <option value="LV">On Leave</option><option value="ML">Medical</option>
            <option value="HOL">Holiday</option>
          </select>
          <span style="font-size:.72rem;color:var(--muted);background:rgba(255,255,255,.04);
            border:1px solid var(--border);padding:5px 12px;border-radius:7px" id="bsCount">
            <i class="fas fa-users"></i> <?= count($students) ?> students
          </span>
        </div>
        <div class="twrap">
          <table class="att-tbl">
            <thead>
              <tr>
                <th><input type="checkbox" class="rchk" id="bulkChkAll" onchange="toggleBulkAll(this)"></th>
                <th>#</th><th>Student</th><th>Roll No.</th>
                <th>Status</th><th>Attendance %</th><th>Quick Change</th>
              </tr>
            </thead>
            <tbody id="bulkBody"></tbody>
          </table>
        </div>
        <div class="sumbar" id="bulkSumBar"></div>
      </div>
    </div>

    <!-- ══ MODE 4: HISTORY ═══════════════════════════════════════════════════ -->
    <div id="mode-history" class="att-mode">
      <div class="panel">
        <div class="ph">
          <div class="pt"><i class="fas fa-clock-rotate-left"></i> Attendance History
            <span style="color:var(--muted);font-size:.78rem;font-weight:400" id="histSub"></span>
          </div>
          <div class="pa">
            <button class="btn btn-out" onclick="exportHistoryCSV()" style="padding:6px 13px;font-size:.76rem"><i class="fas fa-download"></i> Export CSV</button>
          </div>
        </div>
        <div class="hist-ctrl">
          <div class="cg">
            <label class="clbl">From Date</label>
            <input type="date" class="cinp" id="histFrom" style="min-width:130px">
          </div>
          <div class="cg">
            <label class="clbl">To Date</label>
            <input type="date" class="cinp" id="histTo" style="min-width:130px">
          </div>
          <div class="cg">
            <label class="clbl">Search Student</label>
            <div style="position:relative">
              <i class="fas fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.72rem;pointer-events:none"></i>
              <input type="text" class="cinp" id="histSearch" placeholder="Name or roll…" style="padding-left:30px;min-width:160px" oninput="filterHistoryTable()">
            </div>
          </div>
          <div class="cg" style="margin-left:auto">
            <label class="clbl">&nbsp;</label>
            <button class="btn btn-teal" onclick="loadHistory()" style="padding:9px 18px"><i class="fas fa-magnifying-glass"></i> Load History</button>
          </div>
        </div>
        <div class="hist-filters">
          <span style="font-size:.68rem;color:var(--muted)">Status legend:</span>
          <div class="hist-legend">
            <div class="hist-legend-item"><span class="hst P">P</span> Present</div>
            <div class="hist-legend-item"><span class="hst A">A</span> Absent</div>
            <div class="hist-legend-item"><span class="hst L">L</span> Late</div>
            <div class="hist-legend-item"><span class="hst H">H</span> Half Day</div>
            <div class="hist-legend-item"><span class="hst LV">LV</span> Leave</div>
            <div class="hist-legend-item"><span class="hst ML">ML</span> Medical</div>
            <div class="hist-legend-item"><span class="hst HOL">HOL</span> Holiday</div>
            <div class="hist-legend-item"><span class="hst none">–</span> Not marked</div>
          </div>
          <span style="margin-left:auto;font-size:.68rem;color:var(--muted)" id="histStudentCount"></span>
        </div>
        <div class="hist-table-wrap">
          <div id="histTableContainer">
            <div class="hist-loading"><i class="fas fa-calendar-clock" style="font-size:1.6rem;margin-bottom:10px;display:block;color:var(--teal);opacity:.5"></i>Select a date range and click <strong style="color:var(--teal)">Load History</strong></div>
          </div>
        </div>
        <div class="sumbar" id="histSumBar"></div>
      </div>
    </div>

    <!-- Bottom: weekly chart + defaulters -->
    <div class="bottom">
      <div class="card">
        <div class="ch">
          <div class="ct"><i class="fas fa-chart-column"></i> Weekly Attendance Overview</div>
        </div>
        <div class="wchart">
          <div class="wbars" id="weeklyBars"></div>
          <div class="wleg">
            <div class="wli"><div class="wld" style="background:var(--green)"></div>Present</div>
            <div class="wli"><div class="wld" style="background:var(--red)"></div>Absent</div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="ch">
          <div class="ct"><i class="fas fa-triangle-exclamation"></i> Attendance Defaulters</div>
        </div>
        <div class="dlist" id="defaultersList"></div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- Save bar -->
<div class="save-bar" id="saveBar">
  <i class="fas fa-circle-info" style="color:var(--teal);font-size:1.1rem"></i>
  <span class="save-bar-text">You have unsaved attendance changes.</span>
  <button class="sav-dis" onclick="discardChanges()">Discard</button>
  <button class="sav-save" onclick="saveAttendance()"><i class="fas fa-floppy-disk"></i> Save Now</button>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   BOOT DATA (injected from PHP)
═══════════════════════════════════════════════════════════════════════ */
let STUDENTS = <?= json_encode(array_values($students)) ?>;
let COURSES  = <?= json_encode(array_values(array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>$c['name'],'code'=>$c['code'],'semester'=>$c['semester']], $courses))) ?>;
const WEEKLY_P = <?= json_encode(array_values($wkPresent)) ?>;
const WEEKLY_A = <?= json_encode(array_values($wkAbsent))  ?>;
let COL_ID    = <?= (int)$activeColId ?>;
let DEPT_ID   = <?= (int)$activeDeptId ?>;
let COURSE_ID = <?= count($courses) ? (int)$courses[0]['id'] : 0 ?>;  // selected subject id
let STUDY_YEAR = 0;   // 0 = all years
let SEMESTER   = 0;   // 0 = all semesters
let SECTION    = '';  // '' = all sections
const USER_ROLE = '<?= esc($role) ?>';

const SL     = {P:'Present',A:'Absent',L:'Late',H:'Half Day',LV:'Leave',ML:'Medical Leave',HOL:'Holiday'};
const ALL_P  = ['P1','P2','P3','P4','P5','P6'];
const PLBL   = {P1:'8–9',P2:'9–10',P3:'10–11',P4:'11–12',P5:'1–2',P6:'2–3'};
const CYCLE  = ['P','A','L','H','LV','ML','HOL'];

/* ── State ────────────────────────────────────────────────────────────── */
let dSt  = {};  // daily:   [sid] = code
let dRem = {};  // remarks: [sid] = string
let pSt  = {};  // period:  [sid][p] = code
let bulkApply = 'P';
let dirty = false;
let curMode = 'daily';

function seedState() {
  STUDENTS.forEach(s => {
    dSt[s.id]  = s.daily_status || 'P';
    dRem[s.id] = s.remarks || '';
    pSt[s.id]  = {};
    ALL_P.forEach(p => pSt[s.id][p] = (s.period_status && s.period_status[p]) ? s.period_status[p] : 'P');
  });
}

/* ── Init ─────────────────────────────────────────────────────────────── */
window.addEventListener('DOMContentLoaded', () => {
  seedState();
  renderAll();
  renderWeekly();
  renderDefaulters();
  updateStats();
  updateSubs();
  initHistoryDates();
  updateWizard();
  // Activate first subject pill if any
  const firstPill = document.querySelector('.subj-pill');
  if (firstPill) selectSubject(firstPill, true);

  // Populate year/section dropdowns on initial load
  const fdYS = new FormData();
  fdYS.append('ajax_action','load_year_sections');
  fdYS.append('college_id', COL_ID);
  fdYS.append('dept_id',    DEPT_ID);
  fetch(location.pathname,{method:'POST',body:fdYS}).then(r=>r.json()).then(d=>{
    if(d.success) populateYearSectionDropdowns(d.year_sections);
  });
  // Show initial student count chip
  const chip=document.getElementById('studentCountChip');
  const cnt=document.getElementById('studentCountVal');
  if(chip&&cnt&&STUDENTS.length){cnt.textContent=STUDENTS.length;chip.style.display='';}
});

function renderAll() {
  renderDailyTable();
  renderPeriodTable();
  renderBulkTable();
}

function updateSubs() {
  const d = document.getElementById('attDate').value;
  document.getElementById('dailySub').textContent  = `— ${d}`;
  document.getElementById('periodSub').textContent = `— ${d}`;
}

/* ── Wizard state ─────────────────────────────────────────────────────── */
function updateWizard() {
  const dept    = document.getElementById('selDept');
  const deptTxt = dept ? dept.options[dept.selectedIndex]?.text : '<?= esc($activeDeptCode ?: $activeDeptName) ?>';
  const el      = document.getElementById('wizDept');
  if (el) el.textContent = deptTxt.split('—')[0].trim();

  const subjPill = document.querySelector('.subj-pill.active');
  const wizSubj  = document.getElementById('wizSubj');
  if (subjPill) {
    if (wizSubj) { wizSubj.textContent = subjPill.dataset.ccode; wizSubj.style.color = 'var(--teal)'; }
    document.getElementById('wizStep2Num').className = 'wiz-num done';
    document.getElementById('wizStep3Num').className = 'wiz-num';
  } else {
    if (wizSubj) { wizSubj.textContent = '—'; wizSubj.style.color = 'var(--muted)'; }
    document.getElementById('wizStep2Num').className = 'wiz-num';
    document.getElementById('wizStep3Num').className = 'wiz-num todo';
  }

  const ws = document.getElementById('wizStudents');
  if (ws) ws.textContent = STUDENTS.length + ' student' + (STUDENTS.length !== 1 ? 's' : '');

  // Sidebar scope chip
  const sideCol  = document.getElementById('sideCollegeName');
  const sideDept = document.getElementById('sideDeptName');
  const sideScope= document.getElementById('sideScope');
  if (sideCol  && document.getElementById('selCollege')) sideCol.textContent  = document.getElementById('selCollege').options[document.getElementById('selCollege').selectedIndex]?.text || '';
  if (sideDept && dept) sideDept.textContent = dept.options[dept.selectedIndex]?.text?.split('—')[1]?.trim() || '';
  if (sideScope && subjPill) sideScope.textContent = subjPill.dataset.ccode || '';
}

/* ── Subject pill selection ────────────────────────────────────────────── */
function selectSubject(pill, silent) {
  document.querySelectorAll('.subj-pill').forEach(p => p.classList.remove('active'));
  pill.classList.add('active');
  COURSE_ID = parseInt(pill.dataset.cid) || 0;
  updateWizard();

  if (!silent) {
    // Load students filtered by selected course — response already includes
    // daily_status & period_status scoped to this course, so NO separate
    // onDateChange() call needed (that caused a race condition overwriting state).
    const fd = new FormData();
    fd.append('ajax_action', 'load_students');
    fd.append('college_id',  COL_ID);
    fd.append('dept_id',     DEPT_ID);
    fd.append('course_id',   COURSE_ID);
    fd.append('study_year',       STUDY_YEAR);
    fd.append('current_semester', SEMESTER);
    fd.append('section',          SECTION);
    fd.append('date',        document.getElementById('attDate').value);
    fetch(location.pathname, {method:'POST',body:fd})
      .then(r => r.json()).then(d => {
        if (d.success) {
          STUDENTS = d.students;
          seedState(); renderAll(); updateStats(); renderDefaulters();
          updateWizard();
          updateSubs();
        }
      }).catch(() => showToast('Failed to load students.', 'error'));
    showToast(`Subject: ${pill.dataset.cname}`, 'info');
  }

  // Keep hidden course select in sync
  const cs = document.getElementById('selCourse');
  if (cs) cs.value = String(COURSE_ID);
}

/* ── Stats ────────────────────────────────────────────────────────────── */
function updateStats() {
  const n = STUDENTS.length;
  if (!n) {
    ['stPresent','stAbsent','stLate'].forEach(id => set(id,'0'));
    document.getElementById('stPct').innerHTML = '0<span style="font-size:1.1rem;color:var(--muted)">%</span>';
    return;
  }
  let p=0,a=0,l=0;
  STUDENTS.forEach(s=>{const c=dSt[s.id];if(c==='P')p++;else if(c==='A')a++;else if(c==='L'||c==='H')l++;});
  const pct = Math.round((p/n)*100);
  set('stPresent',p); set('stAbsent',a); set('stLate',l);
  document.getElementById('stPct').innerHTML = pct+'<span style="font-size:1.1rem;color:var(--muted)">%</span>';
  document.getElementById('tagPresent').innerHTML = `<i class="fas fa-users"></i> ${p}/${n}`;
  document.getElementById('tagAbsent').innerHTML  = `<i class="fas fa-users"></i> ${a}/${n}`;
  w('barP', (p/n)*100); w('barA', (a/n)*100); w('barL', (l/n)*100); w('barPct', pct);
}
function set(id,v){const e=document.getElementById(id);if(e)e.textContent=v;}
function w(id,v){const e=document.getElementById(id);if(e)e.style.width=v+'%';}

/* ═══════════════════════════════════════════════════════════════════════
   FILTER CHANGES
═══════════════════════════════════════════════════════════════════════ */
function onDateChange() {
  updateSubs();
  const fd = new FormData();
  fd.append('ajax_action', 'load_attendance');
  fd.append('college_id',  COL_ID);
  fd.append('dept_id',     DEPT_ID);
  fd.append('date',        document.getElementById('attDate').value);
  fd.append('course_id',   COURSE_ID);
  fetch(location.pathname, {method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.success){
        STUDENTS.forEach(s=>{dSt[s.id]='P';dRem[s.id]='';});
        d.records.forEach(r=>{
          if(!r.period){dSt[r.student_id]=r.status;dRem[r.student_id]=r.remarks||'';}
          else{if(!pSt[r.student_id])pSt[r.student_id]={};pSt[r.student_id][r.period]=r.status;}
        });
        renderAll(); updateStats(); markClean();
      }
    }).catch(()=>showToast('Failed to load data.','error'));
}

function onCollegeChange() {
  const cid = document.getElementById('selCollege').value;
  COL_ID = parseInt(cid) || 0;
  const fd = new FormData();
  fd.append('ajax_action','load_departments');
  fd.append('college_id', cid);
  fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      const sel=document.getElementById('selDept');
      sel.innerHTML=d.departments.map(dd=>`<option value="${dd.id}">${esc(dd.code+' — '+dd.name)}</option>`).join('');
      onDeptChange();
    }
  });
}

/* ── Year / Section filter change ────────────────────────────────── */
function populateYearSectionDropdowns(yearSections, preserveSelection) {
  const selY = document.getElementById('selYear');
  const selSm= document.getElementById('selSemester');
  const selS = document.getElementById('selSection');
  const prevY = selY.value;
  const prevSm= selSm.value;
  const prevS = selS.value;

  const years     = [...new Set(yearSections.map(r=>r.study_year).filter(Boolean))].sort((a,b)=>a-b);
  const semesters = [...new Set(yearSections.map(r=>r.current_semester).filter(Boolean))].sort((a,b)=>a-b);
  const sections  = [...new Set(yearSections.map(r=>r.section).filter(Boolean))].sort();

  const yearLabels = {1:'1st Year',2:'2nd Year',3:'3rd Year',4:'4th Year',5:'5th Year',6:'6th Year'};
  selY.innerHTML  = '<option value="">All Years</option>'     + years.map(y=>`<option value="${y}">${yearLabels[y]||'Year '+y}</option>`).join('');
  selSm.innerHTML = '<option value="">All Semesters</option>' + semesters.map(s=>`<option value="${s}">Semester ${s}</option>`).join('');
  selS.innerHTML  = '<option value="">All Sections</option>'  + sections.map(s=>`<option value="${s}">Section ${s}</option>`).join('');

  if (preserveSelection) {
    if (prevY  && selY.querySelector(`option[value="${prevY}"]`))   selY.value  = prevY;
    if (prevSm && selSm.querySelector(`option[value="${prevSm}"]`)) selSm.value = prevSm;
    if (prevS  && selS.querySelector(`option[value="${prevS}"]`))   selS.value  = prevS;
  }
}

function onYearSectionChange() {
  STUDY_YEAR = parseInt(document.getElementById('selYear').value)     || 0;
  SEMESTER   = parseInt(document.getElementById('selSemester').value) || 0;
  SECTION    = document.getElementById('selSection').value?.trim()   || '';
  loadStudentsWithFilters();
}

function loadStudentsWithFilters() {
  const fd = new FormData();
  fd.append('ajax_action','load_students');
  fd.append('college_id', COL_ID);
  fd.append('dept_id',    DEPT_ID);
  fd.append('course_id',  COURSE_ID);
  fd.append('study_year',       STUDY_YEAR);
  fd.append('current_semester', SEMESTER);
  fd.append('section',          SECTION);
  fd.append('date',       document.getElementById('attDate').value);
  fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      STUDENTS = d.students;
      if(d.year_sections) populateYearSectionDropdowns(d.year_sections, true); // true = preserve selection
      seedState(); renderAll(); updateStats(); renderDefaulters();
      updateWizard();
      // Show/update count chip
      const chip = document.getElementById('studentCountChip');
      const cnt  = document.getElementById('studentCountVal');
      if(chip && cnt){
        cnt.textContent = STUDENTS.length;
        chip.style.display = '';  // always show after first filter interaction
      }
      const lbl = [];
      if(STUDY_YEAR){ const yl={1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th',6:'6th'}; lbl.push((yl[STUDY_YEAR]||STUDY_YEAR+' Yr')+' Year'); }
      if(SEMESTER)  lbl.push('Sem '+SEMESTER);
      if(SECTION)   lbl.push('Sec '+SECTION);
      const filterLabel = lbl.length ? ' ('+lbl.join(', ')+')' : '';
      showToast(`${STUDENTS.length} student${STUDENTS.length!==1?'s':''} loaded${filterLabel}.`, 'info');
    }
  }).catch(()=>showToast('Failed to load students.','error'));
}

function onDeptChange() {
  const cid  = document.getElementById('selCollege')?.value || COL_ID;
  const did  = document.getElementById('selDept')?.value    || DEPT_ID;
  COL_ID  = parseInt(cid) || 0;
  DEPT_ID = parseInt(did) || 0;
  STUDY_YEAR = 0; SEMESTER = 0; SECTION = '';  // reset filters on dept change

  // Reload year/section dropdowns for new dept
  const fdYS = new FormData();
  fdYS.append('ajax_action','load_year_sections');
  fdYS.append('college_id', cid);
  fdYS.append('dept_id',    did);
  fetch(location.pathname,{method:'POST',body:fdYS}).then(r=>r.json()).then(d=>{
    if(d.success) populateYearSectionDropdowns(d.year_sections);
  });

  // Load courses (assignment-aware via AJAX)
  const fd = new FormData();
  fd.append('ajax_action','load_courses');
  fd.append('college_id', cid);
  fd.append('dept_id',    did);
  fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success) {
      COURSES = d.courses.map(c=>({id:parseInt(c.id),name:c.name,code:c.code,semester:c.semester}));
      rebuildSubjectPills(d.courses);
    }
  });

  // Load students for new dept — FIX 2: include course_id so list is subject-filtered
  const fd2 = new FormData();
  fd2.append('ajax_action','load_students');
  fd2.append('college_id', cid);
  fd2.append('dept_id',    did);
  fd2.append('course_id',  COURSE_ID); // FIX 2
  fd2.append('study_year',       STUDY_YEAR);
  fd2.append('current_semester', SEMESTER);
  fd2.append('section',          SECTION);
  fd2.append('date',       document.getElementById('attDate').value);
  fetch(location.pathname,{method:'POST',body:fd2}).then(r=>r.json()).then(d=>{
    if(d.success){
      STUDENTS = d.students;
      if(d.year_sections) populateYearSectionDropdowns(d.year_sections);
      seedState(); renderAll(); updateStats(); renderDefaulters();
      updateWizard();
      const chip=document.getElementById('studentCountChip');
      const cnt=document.getElementById('studentCountVal');
      if(chip&&cnt){cnt.textContent=STUDENTS.length;chip.style.display=STUDENTS.length?'':'none';}
      showToast(`Loaded ${STUDENTS.length} students.`, 'info');
    }
  });
}

/** Rebuild subject pill strip from AJAX-returned course list */
function rebuildSubjectPills(courses) {
  const container = document.getElementById('subjPills');
  COURSE_ID = 0;
  if (!courses || !courses.length) {
    container.innerHTML = '<span class="subj-none">No courses assigned for this department.</span>';
    updateWizard();
    return;
  }
  container.innerHTML = courses.map((c,i) => `
    <button class="subj-pill ${i===0?'active':''}"
            data-cid="${c.id}"
            data-cname="${esc(c.name)}"
            data-ccode="${esc(c.code)}"
            data-sem="${c.semester||''}"
            onclick="selectSubject(this)">
      <i class="fas fa-book-open" style="font-size:.7rem"></i>
      ${esc(c.code)} — ${esc(c.name)}
      ${c.semester ? `<span class="sem-badge">Sem ${c.semester}</span>` : ''}
    </button>`).join('');

  // Auto-select first
  const firstPill = container.querySelector('.subj-pill');
  if (firstPill) selectSubject(firstPill, true);
  updateWizard();
}

/* ═══════════════════════════════════════════════════════════════════════
   DAILY TABLE
═══════════════════════════════════════════════════════════════════════ */
function renderDailyTable(){
  const tb=document.getElementById('dailyBody');
  if(!STUDENTS.length){
    tb.innerHTML=`<tr><td colspan="7"><div class="empty"><i class="fas fa-users-slash"></i><p>No students in this department.</p></div></td></tr>`;
    renderSum('dailySumBar',{}); return;
  }
  tb.innerHTML=STUDENTS.map((s,i)=>{
    const st=dSt[s.id]; const pc=pclass(s.pct);
    return `<tr id="dr-${s.id}">
      <td><input type="checkbox" class="rchk row-cb" data-id="${s.id}"></td>
      <td class="mono">${pad(i+1)}</td>
      <td><div class="si">
        <div class="av" style="background:${s.color}22;color:${s.color}">${s.initials}</div>
        <div><div class="sn">${esc(s.name)}</div><div class="sr">${esc(s.roll)}</div></div>
      </div></td>
      <td class="mono">${esc(s.roll)}</td>
      <td><div class="ssw">${rowBtns(s.id,st)}</div></td>
      <td><div class="pw"><div class="pb"><div class="pf ${pc}" style="width:0%" data-w="${s.pct??0}"></div></div>
        <span class="pn ${pc}">${s.pct!==null&&s.pct!==undefined?s.pct+'%':'—'}</span></div></td>
      <td><input type="text" placeholder="Remark…" value="${esc(dRem[s.id]||'')}"
        style="background:#fff;border:1px solid var(--border);color:var(--text);
          padding:5px 10px;border-radius:7px;font-size:.74rem;width:128px;outline:none;font-family:var(--font)"
        onchange="dRem[${s.id}]=this.value;markDirty()"></td>
    </tr>`;
  }).join('');
  renderSum('dailySumBar', countSt(dSt));
  setTimeout(()=>document.querySelectorAll('#dailyBody .pf[data-w]').forEach(el=>el.style.width=el.dataset.w+'%'),50);
}

function rowBtns(sid, cur) {
  return [
    ['P','Present','fa-check'],['A','Absent','fa-xmark'],['L','Late','fa-clock'],
    ['H','Half Day','fa-circle-half-stroke'],['LV','Leave','fa-plane-departure'],
    ['ML','Medical Leave','fa-kit-medical'],['HOL','Holiday','fa-star']
  ].map(([c,lbl,ic])=>`
    <button class="asb ${c}" title="${lbl}" data-active="${cur===c}"
      onclick="setDaily(${sid},'${c}',this)">
      <i class="fas ${ic}"></i></button>`).join('');
}

function setDaily(sid, code, btn) {
  dSt[sid]=code;
  btn.closest('tr').querySelectorAll('.asb').forEach(b=>b.dataset.active='false');
  btn.dataset.active='true';
  markDirty(); updateStats(); renderSum('dailySumBar',countSt(dSt)); renderBulkTable();
}

function bulkMark(code) {
  STUDENTS.forEach(s=>dSt[s.id]=code);
  renderDailyTable(); renderBulkTable(); markDirty(); updateStats();
  showToast(`All students marked ${SL[code]}.`, 'success');
}

function toggleAll(chk) {
  document.querySelectorAll('.row-cb').forEach(c=>c.checked=chk.checked);
}

/* ═══════════════════════════════════════════════════════════════════════
   PERIOD TABLE
═══════════════════════════════════════════════════════════════════════ */
function renderPeriodTable() {
  const fv  = document.getElementById('periodFilter')?.value||'all';
  const ps  = fv==='all' ? ALL_P : [fv];
  document.getElementById('periodHead').innerHTML=`<tr><th>Student</th>${ps.map(p=>`<th class="period-cell">${p}<br>
    <small style="font-size:.6rem;color:var(--muted)">${PLBL[p]}</small></th>`).join('')}<th>P/Total</th></tr>`;
  const tb=document.getElementById('periodBody');
  if(!STUDENTS.length){
    tb.innerHTML=`<tr><td colspan="${ps.length+2}"><div class="empty"><i class="fas fa-users-slash"></i><p>No students found.</p></div></td></tr>`;
    return;
  }
  tb.innerHTML=STUDENTS.map(s=>{
    const cells=ps.map(p=>{
      const st=(pSt[s.id]||{})[p]||'P';
      return `<td class="period-cell"><span class="pp ${st}" onclick="cyclePeriod(${s.id},'${p}',this)">${st}</span></td>`;
    }).join('');
    const pc=ALL_P.filter(p=>(pSt[s.id]||{})[p]==='P').length;
    const col=pc>=5?'var(--green)':pc>=3?'var(--amber)':'var(--red)';
    return `<tr>
      <td><div class="si">
        <div class="av" style="background:${s.color}22;color:${s.color}">${s.initials}</div>
        <div><div class="sn">${esc(s.name)}</div><div class="sr">${esc(s.roll)}</div></div>
      </div></td>
      ${cells}
      <td><span style="font-weight:700;color:${col}">${pc}/${ALL_P.length}</span></td>
    </tr>`;
  }).join('');
  renderPeriodSum();
}

function cyclePeriod(sid, period, el) {
  const cur=(pSt[sid]||{})[period]||'P';
  const nxt=CYCLE[(CYCLE.indexOf(cur)+1)%CYCLE.length];
  if(!pSt[sid])pSt[sid]={};
  pSt[sid][period]=nxt;
  el.className=`pp ${nxt}`; el.textContent=nxt;
  markDirty(); renderPeriodSum();
}

function bulkPeriod(code) {
  const tgt=document.getElementById('periodBulkTgt').value;
  STUDENTS.forEach(s=>{if(!pSt[s.id])pSt[s.id]={};pSt[s.id][tgt]=code;});
  renderPeriodTable(); markDirty();
  showToast(`${tgt} (${PLBL[tgt]}) — all students marked ${SL[code]}.`, 'success');
}

function allPeriodPresent() {
  STUDENTS.forEach(s=>{ALL_P.forEach(p=>{if(!pSt[s.id])pSt[s.id]={};pSt[s.id][p]='P';});});
  renderPeriodTable(); markDirty(); showToast('All periods marked Present.','success');
}

function allPeriodHoliday() {
  STUDENTS.forEach(s=>{ALL_P.forEach(p=>{if(!pSt[s.id])pSt[s.id]={};pSt[s.id][p]='HOL';});});
  renderPeriodTable(); markDirty(); showToast('All periods marked Holiday.','info');
}

function renderPeriodSum() {
  let tot=0,pr=0,ab=0;
  STUDENTS.forEach(s=>ALL_P.forEach(p=>{tot++;const st=(pSt[s.id]||{})[p];if(st==='P')pr++;if(st==='A')ab++;}));
  const rt=tot?Math.round((pr/tot)*100):0;
  const bar=document.getElementById('periodSumBar');
  if(bar) bar.innerHTML=`
    <span style="color:var(--sp);font-weight:600">Present Slots: <span style="color:var(--text)">${pr}</span></span>
    <span style="color:var(--sa);font-weight:600">Absent Slots: <span style="color:var(--text)">${ab}</span></span>
    <span style="color:var(--teal);font-weight:600">Rate: <span style="color:var(--text)">${rt}%</span></span>
    <span style="margin-left:auto;color:var(--muted)">Total: <span style="color:var(--text);font-weight:600">${tot}</span></span>`;
}

/* ═══════════════════════════════════════════════════════════════════════
   BULK TABLE
═══════════════════════════════════════════════════════════════════════ */
function renderBulkTable() {
  const srch=(document.getElementById('bulkSearch')?.value||'').toLowerCase();
  const flt =document.getElementById('bulkFilter')?.value||'all';
  const vis =STUDENTS.filter(s=>{
    const ms=!srch||s.name.toLowerCase().includes(srch)||s.roll.toLowerCase().includes(srch);
    const mf=flt==='all'||dSt[s.id]===flt;
    return ms&&mf;
  });
  const bc=document.getElementById('bsCount');
  if(bc) bc.innerHTML=`<i class="fas fa-users"></i> ${vis.length} student${vis.length===1?'':'s'}`;
  const tb=document.getElementById('bulkBody');
  if(!vis.length){
    tb.innerHTML=`<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted);font-size:.8rem">
      <i class="fas fa-search" style="margin-right:8px;opacity:.5"></i>No students match.</td></tr>`;
    renderSum('bulkSumBar',countSt(dSt)); return;
  }
  tb.innerHTML=vis.map((s,i)=>{
    const st=dSt[s.id]; const pc=pclass(s.pct);
    return `<tr id="br-${s.id}">
      <td><input type="checkbox" class="rchk bulk-cb" data-id="${s.id}" onchange="updBulkCnt()"></td>
      <td class="mono">${pad(i+1)}</td>
      <td><div class="si">
        <div class="av" style="background:${s.color}22;color:${s.color}">${s.initials}</div>
        <div><div class="sn">${esc(s.name)}</div><div class="sr">${esc(s.roll)}</div></div>
      </div></td>
      <td class="mono">${esc(s.roll)}</td>
      <td id="bst-${s.id}">${spill(st)}</td>
      <td><div class="pw"><div class="pb"><div class="pf ${pc}" style="width:0%" data-w="${s.pct??0}"></div></div>
        <span class="pn ${pc}">${s.pct!==null&&s.pct!==undefined?s.pct+'%':'—'}</span></div></td>
      <td><div style="display:flex;gap:4px">
        <button onclick="qbulk(${s.id},'P')" title="Present"
          style="width:24px;height:24px;border-radius:5px;border:none;background:rgba(16,185,129,.12);color:var(--sp);cursor:pointer;font-size:.62rem">
          <i class="fas fa-check"></i></button>
        <button onclick="qbulk(${s.id},'A')" title="Absent"
          style="width:24px;height:24px;border-radius:5px;border:none;background:rgba(239,68,68,.12);color:var(--sa);cursor:pointer;font-size:.62rem">
          <i class="fas fa-xmark"></i></button>
        <button onclick="qbulk(${s.id},'L')" title="Late"
          style="width:24px;height:24px;border-radius:5px;border:none;background:rgba(245,158,11,.12);color:var(--sl);cursor:pointer;font-size:.62rem">
          <i class="fas fa-clock"></i></button>
      </div></td>
    </tr>`;
  }).join('');
  renderSum('bulkSumBar',countSt(dSt));
  setTimeout(()=>document.querySelectorAll('#bulkBody .pf[data-w]').forEach(el=>el.style.width=el.dataset.w+'%'),50);
}

function qbulk(sid, code) {
  dSt[sid]=code;
  const el=document.getElementById('bst-'+sid); if(el) el.innerHTML=spill(code);
  markDirty(); updateStats(); renderSum('dailySumBar',countSt(dSt)); renderSum('bulkSumBar',countSt(dSt));
}

function updBulkCnt() {
  const n=document.querySelectorAll('.bulk-cb:checked').length;
  set('bulkCount', n+' selected');
}

function toggleBulkAll(chk) {
  document.querySelectorAll('.bulk-cb').forEach(c=>c.checked=chk.checked); updBulkCnt();
}

function setBulkSt(code) {
  bulkApply=code;
  document.querySelectorAll('#bulkStatusBtns .sbtn').forEach(b=>b.style.outline='none');
  const btn=document.getElementById('bs-'+code); if(btn) btn.style.outline='2px solid var(--teal)';
}

function applyBulk() {
  const checked=[...document.querySelectorAll('.bulk-cb:checked')].map(c=>parseInt(c.dataset.id));
  if(!checked.length){showToast('No students selected.','error');return;}
  checked.forEach(sid=>{dSt[sid]=bulkApply;const e=document.getElementById('bst-'+sid);if(e)e.innerHTML=spill(bulkApply);});
  markDirty(); updateStats();
  renderSum('dailySumBar',countSt(dSt)); renderSum('bulkSumBar',countSt(dSt));
  showToast(`${checked.length} student(s) marked ${SL[bulkApply]}.`, 'success');
}

function selectAbsent() {
  document.querySelectorAll('.bulk-cb').forEach(cb=>cb.checked=dSt[parseInt(cb.dataset.id)]==='A');
  updBulkCnt();
}

/* ═══════════════════════════════════════════════════════════════════════
   DEFAULTERS
═══════════════════════════════════════════════════════════════════════ */
function renderDefaulters() {
  const list=STUDENTS.filter(s=>s.total>0&&s.pct<75).sort((a,b)=>a.pct-b.pct);
  const w=document.getElementById('defaultersList');
  if(!list.length){
    w.innerHTML=`<div style="padding:18px 20px;color:var(--muted);font-size:.8rem">
      <i class="fas fa-check-circle" style="color:var(--green);margin-right:8px"></i>
      No defaulters — all students above 75% (or no records yet).</div>`;
    return;
  }
  w.innerHTML=list.map(s=>`
    <div class="ditem">
      <div class="dav" style="background:${s.color}22;color:${s.color}">${s.initials}</div>
      <div class="din">
        <div class="dn">${esc(s.name)}</div>
        <div class="ds">${esc(s.roll)}</div>
      </div>
      <div style="text-align:right">
        <div class="dpct">${s.pct!==null&&s.pct!==undefined?s.pct+'%':'—'}</div>
        <div class="dbadge">${s.pct!==null&&s.pct<60?'Critical':'Below 75%'}</div>
      </div>
    </div>`).join('');
}

/* ═══════════════════════════════════════════════════════════════════════
   WEEKLY CHART
═══════════════════════════════════════════════════════════════════════ */
function renderWeekly() {
  const days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  const mx=Math.max(...WEEKLY_P,...WEEKLY_A,1);
  document.getElementById('weeklyBars').innerHTML=days.map((d,i)=>{
    const ph=Math.max(((WEEKLY_P[i]/mx)*100),WEEKLY_P[i]>0?4:0).toFixed(0);
    const ah=Math.max(((WEEKLY_A[i]/mx)*100),WEEKLY_A[i]>0?4:0).toFixed(0);
    const hasData = WEEKLY_P[i]>0||WEEKLY_A[i]>0;
    return `<div class="wcol">
      <div class="wtrack">
        <div class="wbar g" style="height:0%" data-h="${ph}%" title="Present: ${WEEKLY_P[i]}"></div>
        <div class="wbar r" style="height:0%" data-h="${ah}%" title="Absent: ${WEEKLY_A[i]}"></div>
      </div>
      <div class="wlbl">${d}</div>
    </div>`;
  }).join('');
  setTimeout(()=>document.querySelectorAll('.wbar[data-h]').forEach(b=>b.style.height=b.dataset.h), 100);
}

/* ═══════════════════════════════════════════════════════════════════════
   MODE SWITCHING
═══════════════════════════════════════════════════════════════════════ */
function switchMode(mode, btn) {
  curMode=mode;
  document.querySelectorAll('.att-mode').forEach(el=>el.classList.remove('active'));
  document.getElementById('mode-'+mode).classList.add('active');
  document.querySelectorAll('.mode-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  const subs={daily:'/ Daily Marking',period:'/ Period-wise',bulk:'/ Bulk Marking',history:'/ History'};
  document.getElementById('topbarSub').textContent=subs[mode]||'';
}

/* ═══════════════════════════════════════════════════════════════════════
   SAVE & DISCARD
═══════════════════════════════════════════════════════════════════════ */
function markDirty(){dirty=true;document.getElementById('saveBar').style.display='flex';}
function markClean(){dirty=false;document.getElementById('saveBar').style.display='none';}

async function saveAttendance() {
  const date = document.getElementById('attDate').value;
  const records = [];

  if (curMode === 'period') {
    // BUG FIX: in period mode, only save period rows — do NOT also save daily (null-period)
    // rows, as that was creating duplicate/conflicting records
    STUDENTS.forEach(s => ALL_P.forEach(p => records.push({student_id:s.id, status:(pSt[s.id]||{})[p]||'P', remarks:'', period:p})));
  } else {
    // Daily mode: save null-period rows only
    STUDENTS.forEach(s => records.push({student_id:s.id, status:dSt[s.id]||'P', remarks:dRem[s.id]||'', period:null}));
  }

  const fd=new FormData();
  fd.append('ajax_action','save_attendance');
  fd.append('college_id', COL_ID);
  fd.append('dept_id',    DEPT_ID);
  fd.append('date',       date);
  fd.append('course_id',  COURSE_ID);   // always send selected subject
  fd.append('records',    JSON.stringify(records));

  try {
    const res=await fetch(location.pathname,{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){
      markClean();
      showToast(`Attendance saved! (${d.saved} records)`,'success');
      // Reload students from server so attendance % updates immediately (no refresh needed)
      const fd2=new FormData();
      fd2.append('ajax_action','load_students');
      fd2.append('college_id', COL_ID);
      fd2.append('dept_id',    DEPT_ID);
      fd2.append('course_id',  COURSE_ID);
      fd2.append('date',       date);
      fetch(location.pathname,{method:'POST',body:fd2})
        .then(r=>r.json()).then(d2=>{
          if(d2.success){
            // Preserve current dSt/dRem/pSt — only update pct/totals from server
            const prevDst={...dSt}, prevDrem={...dRem}, prevPst=JSON.parse(JSON.stringify(pSt));
            STUDENTS=d2.students;
            // Restore the marked statuses so UI stays consistent
            STUDENTS.forEach(s=>{
              dSt[s.id]  = prevDst[s.id]  !== undefined ? prevDst[s.id]  : s.daily_status || 'P';
              dRem[s.id] = prevDrem[s.id] !== undefined ? prevDrem[s.id] : s.remarks || '';
              if(prevPst[s.id]) pSt[s.id]=prevPst[s.id];
            });
            renderAll(); renderDefaulters(); updateStats();
          }
        }).catch(()=>{/* silent — save already succeeded */});
    }
    else showToast('Save failed: '+(d.message||'Unknown error'),'error');
  } catch{showToast('Network error. Please try again.','error');}
}

function discardChanges() {
  // BUG FIX: also reset period state (pSt), not just daily state
  STUDENTS.forEach(s => {
    dSt[s.id]  = s.daily_status || 'P';
    dRem[s.id] = s.remarks || '';
    pSt[s.id]  = {};
    ALL_P.forEach(p => pSt[s.id][p] = (s.period_status && s.period_status[p]) ? s.period_status[p] : 'P');
  });
  renderAll(); updateStats();
  markClean(); showToast('Changes discarded.', 'error');
}

/* ═══════════════════════════════════════════════════════════════════════
   EXPORT
═══════════════════════════════════════════════════════════════════════ */
function exportAttendance() {
  if(!STUDENTS.length){ showToast('No students to export.','error'); return; }
  const activePill = document.querySelector('.subj-pill.active');
  const subjLabel  = activePill ? activePill.dataset.ccode  : 'all-courses';
  const subjName   = activePill ? activePill.dataset.cname  : 'All Courses';
  const dateVal    = document.getElementById('attDate').value || 'today';

  // Header metadata rows
  const yearLabels = {'':'All Years',1:'1st Year',2:'2nd Year',3:'3rd Year',4:'4th Year',5:'5th Year',6:'6th Year'};
  const semLabel   = SEMESTER ? 'Semester '+SEMESTER : 'All Semesters';
  const secLabel   = SECTION  ? 'Section '+SECTION   : 'All Sections';
  const yearLabel  = yearLabels[STUDY_YEAR] || ('Year '+STUDY_YEAR);

  const meta = [
    ['Attendance Report'],
    ['Date',            dateVal],
    ['Subject',         subjName+' ('+subjLabel+')'],
    ['Year',            yearLabel],
    ['Semester',        semLabel],
    ['Section',         secLabel],
    ['Total Students',  STUDENTS.length],
    [],
    ['#','Roll No.','Student Name','Year','Semester','Section','Status','Attendance %','Total Classes','Present','Absent','Remarks']
  ];

  const dataRows = STUDENTS.map((s,i) => [
    i+1,
    s.roll,
    s.name,
    s.year     ? (yearLabels[s.year]||'Year '+s.year) : '—',
    s.semester ? 'Sem '+s.semester : '—',
    s.section  || '—',
    SL[dSt[s.id]] || '—',
    s.pct     !== null ? s.pct+'%' : '—',
    s.total   || 0,
    s.present || 0,
    s.absent  || 0,
    dRem[s.id] || ''
  ]);

  const allRows = [...meta, ...dataRows];
  const csv = allRows.map(r=>r.map(v=>`"${String(v??'').replace(/"/g,'""')}"`).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['\uFEFF'+csv, {type:'text/csv;charset=utf-8'}]));
  a.download = `attendance_${subjLabel}_${dateVal}${STUDY_YEAR?'_Y'+STUDY_YEAR:''}${SEMESTER?'_S'+SEMESTER:''}${SECTION?'_Sec'+SECTION:''}.csv`;
  a.click();
  showToast('CSV exported successfully.','success');
}

/* ═══════════════════════════════════════════════════════════════════════
   HISTORY
═══════════════════════════════════════════════════════════════════════ */
let HIST_DATA = null;

function initHistoryDates() {
  const today = new Date();
  const y=today.getFullYear(), m=String(today.getMonth()+1).padStart(2,'0'), d=String(today.getDate()).padStart(2,'0');
  const fromEl=document.getElementById('histFrom'), toEl=document.getElementById('histTo');
  if(fromEl&&!fromEl.value) fromEl.value=`${y}-${m}-01`;
  if(toEl&&!toEl.value)     toEl.value=`${y}-${m}-${d}`;
}

async function loadHistory() {
  const from=document.getElementById('histFrom').value;
  const to  =document.getElementById('histTo').value;
  if(!from||!to){showToast('Please select a date range.','error');return;}
  if(from>to){showToast('From date must be before To date.','error');return;}

  const container=document.getElementById('histTableContainer');
  container.innerHTML='<div class="hist-loading"><i class="fas fa-spinner fa-spin" style="margin-right:8px"></i>Loading history…</div>';
  document.getElementById('histSumBar').innerHTML='';

  const fd=new FormData();
  fd.append('ajax_action','load_history');
  fd.append('college_id', COL_ID);
  fd.append('dept_id',    DEPT_ID);
  fd.append('from', from);
  fd.append('to',   to);
  fd.append('course_id',        COURSE_ID);
  fd.append('study_year',       STUDY_YEAR);
  fd.append('current_semester', SEMESTER);
  fd.append('section',          SECTION);

  try {
    const res=await fetch(location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(!data.success){showToast('Failed to load history.','error');return;}
    HIST_DATA=data;
    renderHistoryTable();
    const f=new Date(data.from+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
    const t=new Date(data.to  +'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
    document.getElementById('histSub').textContent=`— ${f} to ${t}`;
    showToast(`History loaded: ${data.dates.length} days, ${data.students.length} students.`,'info');
  } catch(e){
    showToast('Network error loading history.','error');
    document.getElementById('histTableContainer').innerHTML='<div class="hist-loading">Failed to load.</div>';
  }
}

function renderHistoryTable() {
  if(!HIST_DATA) return;
  const {students,dates,map,summary}=HIST_DATA;
  const search=(document.getElementById('histSearch')?.value||'').toLowerCase();
  const filtered=students.filter(s=>s.full_name.toLowerCase().includes(search)||s.roll_number.toLowerCase().includes(search));
  document.getElementById('histStudentCount').textContent=`${filtered.length} student${filtered.length!==1?'s':''}`;

  const container=document.getElementById('histTableContainer');
  if(!dates.length){container.innerHTML='<div class="hist-loading">No attendance records found for the selected range.</div>';return;}

  const fmtDate=d=>{const dt=new Date(d+'T00:00:00');return `<span style="font-size:.65rem">${dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short'})}</span><br><span style="font-size:.55rem;color:var(--muted)">${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][dt.getDay()]}</span>`;};
  const thead=`<tr>
    <th style="min-width:160px;text-align:left">Student</th>
    <th style="min-width:70px;text-align:left">Roll No.</th>
    ${dates.map(d=>`<th style="min-width:46px" title="${d}">${fmtDate(d)}</th>`).join('')}
    <th style="min-width:50px">P</th><th style="min-width:50px">A</th><th style="min-width:60px">Att%</th>
  </tr>`;

  if(!filtered.length){container.innerHTML='<div class="hist-loading">No students match the search.</div>';return;}

  const COLORS=['#00c6ae','#f4a261','#7c3aed','#3b82f6','#e76f51','#06b6d4','#52c41a','#a78bfa'];
  const tbody=filtered.map((s,i)=>{
    const parts=s.full_name.split(' ');
    const init=(parts[0][0]+(parts[1]?parts[1][0]:'')).toUpperCase();
    const color=COLORS[i%COLORS.length];
    const sm=summary[s.id]||{P:0,A:0,total:0};
    const pct=sm.total?Math.round((sm.P/sm.total)*100):null;
    const pc=pct===null?'md':pct>=85?'hi':pct>=75?'md':'lo';
    const dateCells=dates.map(d=>{const st=(map[s.id]||{})[d];return st?`<td><span class="hst ${st}" title="${d}: ${SL[st]||st}">${st}</span></td>`:`<td><span class="hst none" title="${d}: Not marked">–</span></td>`;}).join('');
    return `<tr>
      <td><div class="si">
        <div class="av" style="background:${color}22;color:${color};width:28px;height:28px;font-size:.66rem">${esc(init)}</div>
        <div class="sn" style="font-size:.8rem">${esc(s.full_name)}</div>
      </div></td>
      <td class="mono" style="font-size:.72rem">${esc(s.roll_number)}</td>
      ${dateCells}
      <td style="color:var(--sp);font-weight:700">${sm.P||0}</td>
      <td style="color:var(--sa);font-weight:700">${sm.A||0}</td>
      <td><span class="hist-pct ${pc}">${pct!==null?pct+'%':'—'}</span></td>
    </tr>`;
  }).join('');

  container.innerHTML=`<table class="hist-tbl"><thead>${thead}</thead><tbody>${tbody}</tbody></table>`;

  let tp=0,ta=0; filtered.forEach(s=>{const sm=summary[s.id]||{};tp+=sm.P||0;ta+=sm.A||0;});
  const total=tp+ta;
  document.getElementById('histSumBar').innerHTML=
    `<span style="color:var(--sp);font-weight:600">Present: <span style="color:var(--text)">${tp}</span></span>`+
    `<span style="color:var(--sa);font-weight:600">Absent: <span style="color:var(--text)">${ta}</span></span>`+
    (total?`<span style="margin-left:auto;color:var(--muted)">Total records: <span style="color:var(--text);font-weight:600">${total}</span></span>`:'');
}

function filterHistoryTable(){if(HIST_DATA)renderHistoryTable();}

function exportHistoryCSV(){
  if(!HIST_DATA||!HIST_DATA.dates.length){showToast('Load history first.','error');return;}
  const {students,dates,map,summary}=HIST_DATA;
  const header=['Roll No.','Student Name',...dates,'Present','Absent','Att%'];
  const rows=[header];
  students.forEach(s=>{const sm=summary[s.id]||{P:0,A:0,total:0};const pct=sm.total?Math.round((sm.P/sm.total)*100)+'%':'—';rows.push([s.roll_number,s.full_name,...dates.map(d=>(map[s.id]||{})[d]||'–'),sm.P||0,sm.A||0,pct]);});
  const csv=rows.map(r=>r.map(v=>'\"'+String(v).replace(/"/g,'\"\"')+'\"').join(',')).join('\n');
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
  a.download=`attendance_history_${HIST_DATA.from}_to_${HIST_DATA.to}.csv`;
  a.click(); showToast('History CSV exported.','success');
}

/* ═══════════════════════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════════════════════ */
function pclass(p){return p===null||p===undefined?'md':p>=85?'hi':p>=75?'md':'lo';}
function pad(n){return String(n).padStart(2,'0');}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

const SCOL={P:'rgba(16,185,129,.13)',A:'rgba(239,68,68,.13)',L:'rgba(245,158,11,.13)',H:'rgba(59,130,246,.13)',LV:'rgba(139,92,246,.13)',ML:'rgba(6,182,212,.13)',HOL:'rgba(100,116,139,.12)'};
const STXT={P:'var(--sp)',A:'var(--sa)',L:'var(--sl)',H:'var(--sh)',LV:'var(--slv)',ML:'var(--sml)',HOL:'var(--shol)'};
function spill(c){return `<span style="background:${SCOL[c]||'transparent'};color:${STXT[c]||'var(--muted)'};font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:6px">${SL[c]||c}</span>`;}

function countSt(map){const c={};STUDENTS.forEach(s=>{const l=SL[map[s.id]]||map[s.id];c[l]=(c[l]||0)+1;});return c;}

const SCOLORS={Present:'var(--sp)',Absent:'var(--sa)',Late:'var(--sl)','Half Day':'var(--sh)',Leave:'var(--slv)','Medical Leave':'var(--sml)',Holiday:'var(--shol)'};
function renderSum(id,counts){
  const bar=document.getElementById(id);if(!bar)return;
  if(!STUDENTS.length){bar.innerHTML='<span style="color:var(--muted)">No students loaded.</span>';return;}
  bar.innerHTML=Object.entries(counts).filter(([,v])=>v>0)
    .map(([k,v])=>`<span style="color:${SCOLORS[k]||'var(--muted)'};font-weight:600">${k}: <span style="color:var(--text)">${v}</span></span>`)
    .join('<span style="color:var(--border)"> | </span>')+
    `<span style="margin-left:auto;color:var(--muted)">Total: <span style="color:var(--text);font-weight:600">${STUDENTS.length}</span></span>`;
}

/* ── Toast ──────────────────────────────────────────────────────────── */
function showToast(msg, type='success') {
  const t=document.getElementById('toast');
  document.getElementById('toastMsg').textContent=msg;
  t.className=`toast ${type}`;
  const ic={success:'fa-circle-check',error:'fa-circle-xmark',info:'fa-circle-info'};
  t.querySelector('i').className=`fas ${ic[type]||'fa-circle-check'}`;
  t.classList.add('show'); clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),3500);
}

/* ── Sidebar collapse (desktop) ─────────────────────────────────────── */
function collapseSidebar(){
  const sb=document.getElementById('sidebar');
  const icon=document.getElementById('collapseIcon');
  const collapsed=sb.classList.toggle('collapsed');
  localStorage.setItem('sbCollapsed',collapsed?'1':'0');
  if(collapsed){icon.classList.replace('fa-angles-left','fa-angles-right');}
  else{icon.classList.replace('fa-angles-right','fa-angles-left');}
}
(function(){
  if(localStorage.getItem('sbCollapsed')==='1'){
    const sb=document.getElementById('sidebar');
    const icon=document.getElementById('collapseIcon');
    if(sb){sb.classList.add('collapsed');}
    if(icon){icon.classList.replace('fa-angles-left','fa-angles-right');}
  }
})();

/* ── Sidebar / Logout ───────────────────────────────────────────────── */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sidebarOverlay');
  const open=sb.classList.toggle('open');
  if(ov) ov.classList.toggle('active',open);
}
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sidebarOverlay');
  if(window.innerWidth<=800&&sb.classList.contains('open')&&
     !sb.contains(e.target)&&!document.getElementById('menuToggle').contains(e.target)){
    sb.classList.remove('open');
    if(ov) ov.classList.remove('active');
  }
});

/* ── Avatar dropdown ────────────────────────────────────────────────── */
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

async function doLogout(){
  try{
    const r=await fetch('../auth/auth_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
    const d=await r.json();if(d.redirect)window.location.href=d.redirect;
  }catch{window.location.href='../login.php';}
}
</script>
</body>
</html>