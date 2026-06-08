<?php
// pages/timetable.php
// ─────────────────────────────────────────────────────────────────────────────
// FACULTY TIMETABLE — scoped strictly to the logged-in faculty's college +
// department.  A faculty from College A / Dept CSE NEVER sees College B or
// Dept ECE data.  Queries always carry WHERE college_id=? AND department_id=?.
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']               ?? 'faculty';
$userId    = (int)($user['id']           ?? 0);
$collegeId = (int)($user['college_id']   ?? 0);
$deptId    = (int)($user['department_id']?? 0);
$fullName  = $user['full_name']          ?? 'Faculty';
$firstName = explode(' ', trim($fullName))[0];

// Only faculty / college_admin / super_admin reach this page
if (!in_array($role, ['faculty', 'college_admin', 'super_admin'])) {
    header('Location: students.php');
    exit;
}

$db = getDB();
if (!$db) {
    die('<div style="font-family:sans-serif;padding:40px;background:#080f1a;color:#ef4444;min-height:100vh">
        <h2>⚠️ Database Connection Failed</h2>
        <p style="color:#c8d8e8">MySQL is not running or credentials in config.php are wrong.</p>
    </div>');
}

// ── Build list of department IDs this user is allowed to access ──────────
// For super_admin / college_admin: no restriction (handled separately)
// For faculty: their own dept + any dept from active faculty_assignments
$allowedDeptIds = $deptId ? [$deptId] : [];
if ($role === 'faculty' && $userId) {
    $faStmt = $db->prepare(
        "SELECT DISTINCT department_id FROM faculty_assignments
         WHERE faculty_id = ? AND college_id = ? AND status = 'active'"
    );
    $faStmt->execute([$userId, $collegeId]);
    foreach ($faStmt->fetchAll(PDO::FETCH_COLUMN) as $did) {
        if (!in_array((int)$did, $allowedDeptIds)) {
            $allowedDeptIds[] = (int)$did;
        }
    }
}
// For admins allow all depts in their college (access check is college-scoped)
if (in_array($role, ['college_admin', 'super_admin'])) {
    $allowedDeptIds = []; // empty = no dept restriction, only college restriction
}

// Helper: build a safe IN clause placeholder string
function deptInClause(array $ids): string {
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

// ── Super-admin can override college+dept via GET/POST params ─────────────
if ($role === 'super_admin') {
    // Allow switching via GET params  ?sa_college=X&sa_dept=Y
    $saCollege = (int)($_GET['sa_college'] ?? $_SESSION['sa_tt_college'] ?? $collegeId);
    $saDept    = (int)($_GET['sa_dept']    ?? $_SESSION['sa_tt_dept']    ?? $deptId);
    if ($saCollege) { $_SESSION['sa_tt_college'] = $saCollege; $collegeId = $saCollege; }
    if ($saDept)    { $_SESSION['sa_tt_dept']    = $saDept;    $deptId    = $saDept;   }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function initials(string $name): string {
    $p = explode(' ', trim($name));
    return strtoupper(substr($p[0],0,1) . (isset($p[1]) ? substr($p[1],0,1) : ''));
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

/* ══════════════════════════════════════════════════════════════════
   AJAX HANDLERS  (POST requests with ajax_action)
   Every write operation is double-scoped: college_id + department_id
══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    try {

        /* ── Get sections for a dept+sem (scoped to my college+allowed depts) ─ */
        if ($action === 'get_sections') {
            $requestedDeptId = (int)($_POST['dept_id'] ?? 0);
            $sem_id          = (int)($_POST['sem_id'] ?? 0);

            if (in_array($role, ['college_admin', 'super_admin'])) {
                // Admins: filter by explicitly passed dept_id or all college depts
                if ($requestedDeptId) {
                    $stmt = $db->prepare(
                        'SELECT id, label, strength, department_id
                         FROM tt_sections
                         WHERE college_id = ? AND department_id = ?
                           AND (? = 0 OR semester_id = ?)
                         ORDER BY label'
                    );
                    $stmt->execute([$collegeId, $requestedDeptId, $sem_id, $sem_id]);
                } else {
                    $stmt = $db->prepare(
                        'SELECT id, label, strength, department_id
                         FROM tt_sections
                         WHERE college_id = ?
                           AND (? = 0 OR semester_id = ?)
                         ORDER BY label'
                    );
                    $stmt->execute([$collegeId, $sem_id, $sem_id]);
                }
            } else {
                // Faculty: only sections in their allowed departments
                $deptIds = $allowedDeptIds;
                if ($requestedDeptId && in_array($requestedDeptId, $deptIds)) {
                    $deptIds = [$requestedDeptId]; // narrow to requested if allowed
                } elseif ($requestedDeptId && !in_array($requestedDeptId, $deptIds)) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit;
                }
                if (empty($deptIds)) {
                    echo json_encode(['success' => true, 'sections' => []]);
                    exit;
                }
                $in = deptInClause($deptIds);
                $params = array_merge([$collegeId], $deptIds, [$sem_id, $sem_id]);
                $stmt = $db->prepare(
                    "SELECT id, label, strength, department_id
                     FROM tt_sections
                     WHERE college_id = ? AND department_id IN ($in)
                       AND (? = 0 OR semester_id = ?)
                     ORDER BY label"
                );
                $stmt->execute($params);
            }
            echo json_encode(['success' => true, 'sections' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        /* ── Find timetable (scoped to allowed depts) ────────────────── */
        if ($action === 'find_tt') {
            $sem_id = (int)($_POST['sem_id'] ?? 0);
            $sec_id = (int)($_POST['sec_id'] ?? 0);

            if (in_array($role, ['college_admin', 'super_admin'])) {
                $findDeptId = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : $deptId;
                if ($findDeptId) {
                    $stmt = $db->prepare(
                        'SELECT id, status, academic_year, department_id
                         FROM timetables
                         WHERE college_id = ? AND department_id = ?
                           AND semester_id = ? AND section_id = ?
                         LIMIT 1'
                    );
                    $stmt->execute([$collegeId, $findDeptId, $sem_id, $sec_id]);
                } else {
                    $stmt = $db->prepare(
                        'SELECT id, status, academic_year, department_id
                         FROM timetables
                         WHERE college_id = ?
                           AND semester_id = ? AND section_id = ?
                         LIMIT 1'
                    );
                    $stmt->execute([$collegeId, $sem_id, $sec_id]);
                }
            } else {
                // Faculty: section must belong to an allowed dept
                if (empty($allowedDeptIds)) {
                    echo json_encode(['success' => true, 'timetable' => null]);
                    exit;
                }
                $in = deptInClause($allowedDeptIds);
                $params = array_merge([$collegeId], $allowedDeptIds, [$sem_id, $sec_id]);
                $stmt = $db->prepare(
                    "SELECT id, status, academic_year, department_id
                     FROM timetables
                     WHERE college_id = ? AND department_id IN ($in)
                       AND semester_id = ? AND section_id = ?
                     LIMIT 1"
                );
                $stmt->execute($params);
            }
            echo json_encode(['success' => true, 'timetable' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);
            exit;
        }

        /* ── Load timetable grid data (read-only for faculty) ──────── */
        if ($action === 'load_tt') {
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            // Verify timetable belongs to this user's college + an allowed dept
            if (in_array($role, ['college_admin', 'super_admin'])) {
                $chk = $db->prepare('SELECT id FROM timetables WHERE id = ? AND college_id = ?');
                $chk->execute([$tt_id, $collegeId]);
            } elseif (!empty($allowedDeptIds)) {
                $in  = deptInClause($allowedDeptIds);
                $params = array_merge([$tt_id, $collegeId], $allowedDeptIds);
                $chk = $db->prepare("SELECT id FROM timetables WHERE id = ? AND college_id = ? AND department_id IN ($in)");
                $chk->execute($params);
            } else {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $slots = $db->prepare('
                SELECT s.period_id, s.day,
                       c.name AS course_name, c.code AS course_code, c.id AS course_id,
                       u.full_name AS faculty_name, u.id AS faculty_id,
                       r.name AS room_name, r.type AS room_type, r.id AS room_id
                FROM tt_slots s
                LEFT JOIN courses  c ON c.id = s.course_id
                LEFT JOIN users    u ON u.id = s.faculty_id
                LEFT JOIN tt_rooms r ON r.id = s.room_id
                WHERE s.timetable_id = ?
            ');
            $slots->execute([$tt_id]);
            echo json_encode(['success' => true, 'slots' => $slots->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        /* ── Load MY schedule (only slots where I'm the faculty) ───── */
        if ($action === 'load_my_schedule') {
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            if (in_array($role, ['college_admin', 'super_admin'])) {
                $chk = $db->prepare('SELECT id FROM timetables WHERE id = ? AND college_id = ?');
                $chk->execute([$tt_id, $collegeId]);
            } elseif (!empty($allowedDeptIds)) {
                $in = deptInClause($allowedDeptIds);
                $params = array_merge([$tt_id, $collegeId], $allowedDeptIds);
                $chk = $db->prepare("SELECT id FROM timetables WHERE id = ? AND college_id = ? AND department_id IN ($in)");
                $chk->execute($params);
            } else {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $slots = $db->prepare('
                SELECT s.period_id, s.day,
                       c.name AS course_name, c.code AS course_code, c.id AS course_id,
                       r.name AS room_name, r.type AS room_type, r.id AS room_id
                FROM tt_slots s
                LEFT JOIN courses  c ON c.id = s.course_id
                LEFT JOIN tt_rooms r ON r.id = s.room_id
                WHERE s.timetable_id = ? AND s.faculty_id = ?
            ');
            $slots->execute([$tt_id, $userId]);
            echo json_encode(['success' => true, 'slots' => $slots->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        /* ── Create Timetable (admin/hod only, scoped to own dept) ─── */
        if ($action === 'create_timetable') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can create timetables']);
                exit;
            }
            $sem_id  = (int)($_POST['sem_id']   ?? 0);
            $sec_id  = (int)($_POST['sec_id']   ?? 0);
            $year    = trim($_POST['acad_year'] ?? date('Y') . '-' . substr((string)((int)date('Y')+1), 2));
            if (!$sem_id || !$sec_id) {
                echo json_encode(['success' => false, 'message' => 'Semester and Section required']);
                exit;
            }
            $exists = $db->prepare(
                'SELECT id FROM timetables
                 WHERE department_id = ? AND semester_id = ? AND section_id = ? AND college_id = ?'
            );
            $exists->execute([$deptId, $sem_id, $sec_id, $collegeId]);
            $row = $exists->fetch();
            if ($row) {
                echo json_encode(['success' => true, 'tt_id' => $row['id'], 'existing' => true]);
            } else {
                $db->prepare(
                    "INSERT INTO timetables
                     (college_id, department_id, semester_id, section_id, academic_year, status, created_by)
                     VALUES (?,?,?,?,?,'draft',?)"
                )->execute([$collegeId, $deptId, $sem_id, $sec_id, $year, $userId]);
                echo json_encode(['success' => true, 'tt_id' => $db->lastInsertId(), 'existing' => false]);
            }
            exit;
        }

        /* ── Save Slot (admin only) ────────────────────────────────── */
        if ($action === 'save_slot') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can edit slots']);
                exit;
            }
            $tt_id      = (int)($_POST['tt_id']      ?? 0);
            $period_id  = (int)($_POST['period_id']  ?? 0);
            $day        = trim($_POST['day']         ?? '');
            $course_id  = (int)($_POST['course_id']  ?? 0) ?: null;
            $faculty_id = (int)($_POST['faculty_id'] ?? 0) ?: null;
            $room_id    = (int)($_POST['room_id']    ?? 0) ?: null;
            // Verify timetable belongs to this college (admin already role-checked above)
            $chk = $db->prepare(
                'SELECT id FROM timetables WHERE id=? AND college_id=?'
            );
            $chk->execute([$tt_id, $collegeId]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $db->prepare('
                INSERT INTO tt_slots (timetable_id, period_id, day, course_id, faculty_id, room_id)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  course_id=VALUES(course_id),
                  faculty_id=VALUES(faculty_id),
                  room_id=VALUES(room_id),
                  updated_at=current_timestamp()
            ')->execute([$tt_id, $period_id, $day, $course_id, $faculty_id, $room_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        /* ── Clear Slot (admin only) ───────────────────────────────── */
        if ($action === 'clear_slot') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can clear slots']);
                exit;
            }
            $tt_id     = (int)($_POST['tt_id']     ?? 0);
            $period_id = (int)($_POST['period_id'] ?? 0);
            $day       = trim($_POST['day']        ?? '');
            $chk = $db->prepare(
                'SELECT id FROM timetables WHERE id=? AND college_id=?'
            );
            $chk->execute([$tt_id, $collegeId]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $db->prepare(
                'DELETE FROM tt_slots WHERE timetable_id=? AND period_id=? AND day=?'
            )->execute([$tt_id, $period_id, $day]);
            echo json_encode(['success' => true]);
            exit;
        }

        /* ── Clear all slots (admin only) ─────────────────────────── */
        if ($action === 'clear_timetable') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can clear timetables']);
                exit;
            }
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $chk = $db->prepare(
                'SELECT id FROM timetables WHERE id=? AND college_id=?'
            );
            $chk->execute([$tt_id, $collegeId]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $db->prepare('DELETE FROM tt_slots WHERE timetable_id=?')->execute([$tt_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        /* ── Activate timetable (admin only) ──────────────────────── */
        if ($action === 'activate_timetable') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can activate timetables']);
                exit;
            }
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $db->prepare(
                "UPDATE timetables SET status='active', updated_at=current_timestamp()
                 WHERE id=? AND college_id=?"
            )->execute([$tt_id, $collegeId]);
            echo json_encode(['success' => true]);
            exit;
        }

        /* ── Auto-generate (admin only) ────────────────────────────── */
        if ($action === 'auto_generate') {
            if (!in_array($role, ['college_admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admins can auto-generate']);
                exit;
            }
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $chk = $db->prepare(
                'SELECT id FROM timetables WHERE id=? AND college_id=?'
            );
            $chk->execute([$tt_id, $collegeId]);
            if (!$chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            $db->prepare('DELETE FROM tt_slots WHERE timetable_id=?')->execute([$tt_id]);
            $periods = $db->prepare(
                "SELECT id FROM tt_periods WHERE college_id=? AND type='Period' ORDER BY sort_order"
            );
            $periods->execute([$collegeId]);
            $periods = $periods->fetchAll(PDO::FETCH_ASSOC);
            $courses = $db->prepare(
                "SELECT id FROM courses WHERE college_id=? AND department_id=? AND status='active'"
            );
            $courses->execute([$collegeId, $deptId]);
            $courses = $courses->fetchAll(PDO::FETCH_ASSOC);
            $faculty = $db->prepare(
                "SELECT id FROM users WHERE college_id=? AND department_id=? AND role='faculty' AND status='active'"
            );
            $faculty->execute([$collegeId, $deptId]);
            $faculty = $faculty->fetchAll(PDO::FETCH_ASSOC);
            $rooms = $db->prepare("SELECT id FROM tt_rooms WHERE college_id=? AND status='active'");
            $rooms->execute([$collegeId]);
            $rooms = $rooms->fetchAll(PDO::FETCH_ASSOC);
            if (!$periods || !$courses || !$faculty || !$rooms) {
                echo json_encode(['success' => false, 'message' => 'Ensure courses, faculty and rooms exist first.']);
                exit;
            }
            $days = ['Mon','Tue','Wed','Thu','Fri','Sat'];
            $si = 0; $fi = 0; $ri = 0;
            $stmt = $db->prepare(
                'INSERT IGNORE INTO tt_slots (timetable_id,period_id,day,course_id,faculty_id,room_id)
                 VALUES (?,?,?,?,?,?)'
            );
            foreach ($periods as $p) {
                foreach ($days as $d) {
                    if ($d === 'Sat' && rand(0,1)) continue;
                    $stmt->execute([
                        $tt_id, $p['id'], $d,
                        $courses[$si % count($courses)]['id'],
                        $faculty[$fi % count($faculty)]['id'],
                        $rooms[$ri  % count($rooms)]['id'],
                    ]);
                    $si++; $fi++; $ri++;
                }
            }
            $db->prepare("UPDATE timetables SET status='active' WHERE id=?")->execute([$tt_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        /* ── Check conflict ────────────────────────────────────────── */
        if ($action === 'check_conflict') {
            $tt_id      = (int)($_POST['tt_id']      ?? 0);
            $period_id  = (int)($_POST['period_id']  ?? 0);
            $day        = trim($_POST['day']          ?? '');
            $faculty_id = (int)($_POST['faculty_id'] ?? 0) ?: null;
            $room_id    = (int)($_POST['room_id']    ?? 0) ?: null;
            $conflicts  = [];
            if ($faculty_id) {
                $chk = $db->prepare('
                    SELECT t.id AS tt_id, d.code AS dept, sec.label AS section
                    FROM tt_slots sl
                    JOIN timetables t   ON t.id  = sl.timetable_id
                    JOIN departments d  ON d.id  = t.department_id
                    JOIN tt_sections sec ON sec.id = t.section_id
                    WHERE sl.faculty_id=? AND sl.period_id=? AND sl.day=?
                      AND sl.timetable_id != ? AND t.college_id=?
                ');
                $chk->execute([$faculty_id, $period_id, $day, $tt_id, $collegeId]);
                foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $conflicts[] = "Faculty already assigned in {$r['dept']} Section {$r['section']}";
                }
            }
            if ($room_id) {
                $chk2 = $db->prepare('
                    SELECT t.id AS tt_id, d.code AS dept, sec.label AS section
                    FROM tt_slots sl
                    JOIN timetables t   ON t.id  = sl.timetable_id
                    JOIN departments d  ON d.id  = t.department_id
                    JOIN tt_sections sec ON sec.id = t.section_id
                    WHERE sl.room_id=? AND sl.period_id=? AND sl.day=?
                      AND sl.timetable_id != ? AND t.college_id=?
                ');
                $chk2->execute([$room_id, $period_id, $day, $tt_id, $collegeId]);
                foreach ($chk2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $conflicts[] = "Room already booked in {$r['dept']} Section {$r['section']}";
                }
            }
            echo json_encode(['success' => true, 'conflicts' => $conflicts]);
            exit;
        }

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   PAGE DATA LOAD — everything scoped to this faculty's college+dept
══════════════════════════════════════════════════════════════════ */

// College info
$collegeName = 'Your College';
$collegeCode = '';
$st = $db->prepare('SELECT name, code FROM colleges WHERE id=? AND status="active" LIMIT 1');
$st->execute([$collegeId]);
if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $collegeName = $row['name'];
    $collegeCode = $row['code'];
}

// Department info
$deptName = 'Your Department';
$deptCode = '';
$hodName  = '';
if ($deptId) {
    $st = $db->prepare('SELECT name, code, hod_name FROM departments WHERE id=? AND college_id=? LIMIT 1');
    $st->execute([$deptId, $collegeId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $deptName = $row['name'];
        $deptCode = $row['code'];
        $hodName  = $row['hod_name'] ?? '';
    }
}

// Periods — college-wide (shared across depts, same college)
$st = $db->prepare(
    'SELECT id, label, type, start_time, end_time, sort_order
     FROM tt_periods WHERE college_id=? ORDER BY sort_order'
);
$st->execute([$collegeId]);
$periods = $st->fetchAll(PDO::FETCH_ASSOC);

// Semesters — college-wide
$st = $db->prepare(
    'SELECT id, name, start_date, end_date, status
     FROM tt_semesters WHERE college_id=? ORDER BY id'
);
$st->execute([$collegeId]);
$semesters = $st->fetchAll(PDO::FETCH_ASSOC);

// Sections — my assigned departments (or own dept for single-dept faculty)
if (!empty($allowedDeptIds)) {
    $inClause = implode(',', array_fill(0, count($allowedDeptIds), '?'));
    $st = $db->prepare("
        SELECT s.id, s.label, s.strength, s.semester_id, s.department_id,
               sm.name AS sem_name,
               d.name AS dept_name, d.code AS dept_code
        FROM tt_sections s
        LEFT JOIN tt_semesters sm ON sm.id = s.semester_id
        LEFT JOIN departments  d  ON d.id  = s.department_id
        WHERE s.college_id=? AND s.department_id IN ($inClause)
        ORDER BY sm.name, s.label
    ");
    $st->execute(array_merge([$collegeId], $allowedDeptIds));
} else {
    // Admin: all sections in college
    $st = $db->prepare('
        SELECT s.id, s.label, s.strength, s.semester_id, s.department_id,
               sm.name AS sem_name,
               d.name AS dept_name, d.code AS dept_code
        FROM tt_sections s
        LEFT JOIN tt_semesters sm ON sm.id = s.semester_id
        LEFT JOIN departments  d  ON d.id  = s.department_id
        WHERE s.college_id=?
        ORDER BY sm.name, s.label
    ');
    $st->execute([$collegeId]);
}
$sections = $st->fetchAll(PDO::FETCH_ASSOC);

// Courses — all assigned departments
if (!empty($allowedDeptIds)) {
    $inClause = implode(',', array_fill(0, count($allowedDeptIds), '?'));
    $st = $db->prepare(
        "SELECT id, name, code, credits, semester, department_id
         FROM courses
         WHERE college_id=? AND department_id IN ($inClause) AND status='active'
         ORDER BY semester, name"
    );
    $st->execute(array_merge([$collegeId], $allowedDeptIds));
} else {
    $st = $db->prepare(
        "SELECT id, name, code, credits, semester, department_id
         FROM courses
         WHERE college_id=? AND status='active'
         ORDER BY semester, name"
    );
    $st->execute([$collegeId]);
}
$courses = $st->fetchAll(PDO::FETCH_ASSOC);

// Faculty — departments this user has access to
if (!empty($allowedDeptIds)) {
    $inClause = implode(',', array_fill(0, count($allowedDeptIds), '?'));
    $st = $db->prepare("
        SELECT id, full_name, designation, department_id
        FROM users
        WHERE college_id=? AND department_id IN ($inClause) AND role='faculty' AND status='active'
        ORDER BY full_name
    ");
    $st->execute(array_merge([$collegeId], $allowedDeptIds));
} else {
    $st = $db->prepare("
        SELECT id, full_name, designation, department_id
        FROM users
        WHERE college_id=? AND role='faculty' AND status='active'
        ORDER BY full_name
    ");
    $st->execute([$collegeId]);
}
$deptFaculty = $st->fetchAll(PDO::FETCH_ASSOC);

// Rooms — college-wide (shared)
$st = $db->prepare("SELECT id, name, type, block FROM tt_rooms WHERE college_id=? AND status='active' ORDER BY type, name");
$st->execute([$collegeId]);
$rooms = $st->fetchAll(PDO::FETCH_ASSOC);

// Stats
$statCourses  = count($courses);
$statFaculty  = count($deptFaculty);
$statSections = count($sections);
$statRooms    = count($rooms);

// Weekly summary: my personal timetable across all sections I'm assigned to
$todayAbbr = date('D');
if (!empty($allowedDeptIds)) {
    $inClause = implode(',', array_fill(0, count($allowedDeptIds), '?'));
    $params = array_merge([$userId, $collegeId], $allowedDeptIds);
    $st = $db->prepare("
        SELECT p.id AS period_id, p.start_time, p.end_time, p.label AS period_label,
               s.course_id, c.name AS course_name, c.code AS course_code,
               r.name AS room_name, s.day,
               sec.label AS section_label,
               sm.name  AS sem_name,
               d.name   AS dept_name, d.code AS dept_code
        FROM tt_slots s
        JOIN timetables tt      ON tt.id  = s.timetable_id
        JOIN tt_periods p       ON p.id   = s.period_id
        JOIN departments d      ON d.id   = tt.department_id
        LEFT JOIN courses  c    ON c.id   = s.course_id
        LEFT JOIN tt_rooms r    ON r.id   = s.room_id
        LEFT JOIN tt_sections sec ON sec.id = tt.section_id
        LEFT JOIN tt_semesters sm ON sm.id  = tt.semester_id
        WHERE s.faculty_id = ?
          AND tt.college_id = ?
          AND tt.department_id IN ($inClause)
        ORDER BY s.day, p.sort_order
    ");
    $st->execute($params);
} else {
    $st = $db->prepare('
        SELECT p.id AS period_id, p.start_time, p.end_time, p.label AS period_label,
               s.course_id, c.name AS course_name, c.code AS course_code,
               r.name AS room_name, s.day,
               sec.label AS section_label,
               sm.name  AS sem_name,
               d.name   AS dept_name, d.code AS dept_code
        FROM tt_slots s
        JOIN timetables tt      ON tt.id  = s.timetable_id
        JOIN tt_periods p       ON p.id   = s.period_id
        JOIN departments d      ON d.id   = tt.department_id
        LEFT JOIN courses  c    ON c.id   = s.course_id
        LEFT JOIN tt_rooms r    ON r.id   = s.room_id
        LEFT JOIN tt_sections sec ON sec.id = tt.section_id
        LEFT JOIN tt_semesters sm ON sm.id  = tt.semester_id
        WHERE s.faculty_id = ?
          AND tt.college_id = ?
        ORDER BY s.day, p.sort_order
    ');
    $st->execute([$userId, $collegeId]);
}
$myAllSlots = $st->fetchAll(PDO::FETCH_ASSOC);

$todaySlots = array_filter($myAllSlots, fn($s) => $s['day'] === $todayAbbr);
$todaySlots = array_values($todaySlots);

// Group by day for weekly view
$weekSlots = [];
foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d) {
    $weekSlots[$d] = array_values(array_filter($myAllSlots, fn($s) => $s['day'] === $d));
}

// Is admin?
$isAdmin = in_array($role, ['college_admin', 'super_admin']);

// Super-admin: load all colleges and departments for the switcher banner
$allCollegesForSA = [];
$allDeptsForSA    = [];
if ($role === 'super_admin') {
    $st = $db->query("SELECT id, name, code FROM colleges WHERE status='active' ORDER BY name");
    $allCollegesForSA = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->query("SELECT id, name, code, college_id FROM departments ORDER BY name");
    $allDeptsForSA = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduNexus — Timetable · <?= esc($deptName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   TIMETABLE  —  Design system from staff_noticeboard.php
   Teal #0F766E · Amber #F59E0B · Light page bg #F8FAFC
   Glassmorphic sidebar (teal-dark gradient + blur)
═══════════════════════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* ── Brand ── */
  --teal:#0F766E;--teal-dark:#0D5C56;--teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);--amber-soft2:rgba(245,158,11,.22);

  /* ── Page ── */
  --page-bg:#F8FAFC;--card:#FFFFFF;--card-hover:#F0FDFA;
  --text:#0F172A;--muted:#475569;--text-light:#94A3B8;
  --border:rgba(15,118,110,.10);--border-accent:rgba(20,184,166,.30);

  /* ── Status ── */
  --green:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;  --red2:rgba(220,38,38,.12);
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8; --blue2:rgba(29,78,216,.12);

  /* ── Sidebar tokens ── */
  --sb-text:rgba(255,255,255,.78);--sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);--sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);--sb-active-border:rgba(245,158,11,.38);

  --sidebar-w:264px;--sb-collapsed-w:72px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;

  /* backward compat aliases */
  --navy:#0D5C56;--navy2:#F8FAFC;
  --teal3:rgba(20,184,166,.10);--teal4:rgba(20,184,166,.18);
  --teal2:#0D5C56;
  --white:#FFFFFF;
}

html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* Subtle teal radial + grid overlay on light bg */
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

/* ══════════════════════════════════════════════════════════════════════════
   SIDEBAR — Glassmorphic deep-teal gradient (mirrors staff_noticeboard)
══════════════════════════════════════════════════════════════════════════ */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,
    rgba(13,92,86,.98)  0%,
    rgba(15,118,110,.95) 40%,
    rgba(17,140,130,.92) 72%,
    rgba(13,92,86,.98)  100%
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
/* Gold shimmer top line */
.sidebar::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;pointer-events:none;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);
}
/* Right glowing edge */
.sidebar::after{
  content:'';position:absolute;top:0;right:0;width:1px;bottom:0;pointer-events:none;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);
}

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
/* Dept row */
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

.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{
  display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;
  color:var(--sb-text);font-size:.82rem;font-weight:500;
  cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent;
}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{
  background:var(--sb-active-bg);color:var(--amber-acc);
  border-color:var(--sb-active-border);
  box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10);
}
.nav-item.active::before{
  content:'';position:absolute;left:0;top:18%;height:64%;width:3px;
  background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0;
}
.nav-badge{margin-left:auto;background:var(--amber-acc);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}

.sidebar-user{
  padding:12px 14px;border-top:1px solid var(--sb-border);
  display:flex;align-items:center;gap:10px;flex-shrink:0;
  background:rgba(0,0,0,.16);backdrop-filter:blur(10px);
  position:relative;z-index:1;
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

/* ── Sidebar collapse button ─────────────────────────────────────────────── */
.sb-collapse-btn{
  margin-left:auto;flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);cursor:pointer;
  display:grid;place-items:center;font-size:.68rem;transition:all .2s;
}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber-acc)}

/* ── Collapsed sidebar ───────────────────────────────────────────────────── */
.sidebar.collapsed{width:var(--sb-collapsed-w)}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0}
.sidebar.collapsed .logo-text{display:none !important}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important}
.sidebar.collapsed .nav-item.active,
.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12)!important;border-color:rgba(255,255,255,.15)!important;box-shadow:none!important;color:#ffffff!important}
.sidebar.collapsed .nav-item.active i,
.sidebar.collapsed .nav-item.active:hover i,
.sidebar.collapsed a.nav-item.active i{color:#ffffff!important;opacity:1!important;visibility:visible!important;-webkit-text-fill-color:#ffffff!important}
.sidebar.collapsed .scope-chip,
.sidebar.collapsed .scope-chip *,
.sidebar.collapsed .scope-chip::before{display:none!important;height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:none!important;overflow:hidden!important;visibility:hidden!important;opacity:0!important}
.sidebar.collapsed .nav-label{display:none!important;height:0!important;margin:0!important;padding:0!important;overflow:hidden!important}
.sidebar.collapsed .sidebar-nav{padding:6px 8px 16px;margin-top:46px}
.sidebar.collapsed .nav-item{display:flex!important;justify-content:center!important;align-items:center!important;padding:10px 0!important;gap:0!important;width:100%;overflow:hidden;border-radius:10px}
.sidebar.collapsed .nav-item i{width:20px;text-align:center;font-size:.9rem;flex-shrink:0;margin:0;padding:0}
.sidebar.collapsed .nav-text,
.sidebar.collapsed .nav-badge{display:none!important;width:0!important;height:0!important;overflow:hidden!important;padding:0!important;margin:0!important}
.sidebar.collapsed .sidebar-user{padding:10px 0;justify-content:center;gap:0}
.sidebar.collapsed .user-info,
.sidebar.collapsed .logout-btn{display:none!important;width:0!important;overflow:hidden!important}
.sidebar.collapsed .user-avatar{margin:0 auto;flex-shrink:0}
.sidebar.collapsed .nav-item::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#0F172A;color:#fff;font-size:.71rem;font-weight:500;font-family:var(--font);padding:5px 11px;border-radius:7px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08)}
.sidebar.collapsed .nav-item:hover::after{opacity:1}
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}

/* ══════════════════════════════════════════════════════════════════════════
   MAIN AREA — light, clean, matches noticeboard
══════════════════════════════════════════════════════════════════════════ */
.main{
  margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;
  min-height:100vh;min-width:0;
  width:calc(100% - var(--sidebar-w));
  transition:margin-left .3s ease,width .3s ease;
}
.sidebar.collapsed ~ .main,
.shell:has(.sidebar.collapsed) .main{
  margin-left:var(--sb-collapsed-w);
  width:calc(100% - var(--sb-collapsed-w));
}

/* Topbar — teal gradient (matches noticeboard exactly) */
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:rgba(255,255,255,.52);font-weight:400;font-size:.78rem;margin-left:6px}

.topbar-title span{color:#fff;font-weight:400;font-size:.78rem;margin-left:6px}

.topbar-btn{
  width:36px;height:36px;border-radius:9px;
  border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);
  color:#ffffff;
  display:grid;place-items:center;cursor:pointer;transition:all .18s;font-size:.82rem;text-decoration:none;
}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}

.date-chip{
  height:36px;display:flex;align-items:center;
  font-size:.72rem;color:#ffffff;
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);
  padding:0 11px;border-radius:9px;font-family:var(--mono);
}

/* Topbar avatar */
.topbar-avatar-wrap{position:relative}
.topbar-avatar{
  width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;
  cursor:pointer;border:2px solid rgba(245,158,11,.30);
  transition:border-color .2s,box-shadow .2s;user-select:none;flex-shrink:0;
}
.topbar-avatar:hover,.topbar-avatar.open{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{
  position:absolute;top:calc(100% + 10px);right:0;min-width:200px;
  background:#fff;border:1px solid var(--border);
  border-radius:12px;padding:6px;
  box-shadow:0 16px 48px rgba(15,118,110,.12);z-index:200;
  opacity:0;pointer-events:none;transform:translateY(-8px);
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

.content{padding:22px 26px;flex:1}

/* ── Scope banner ── */
.scope-banner{
  display:flex;align-items:center;flex-wrap:wrap;gap:8px;
  background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.20);
  border-radius:10px;padding:10px 16px;margin-bottom:18px;font-size:.79rem;
  animation:slideUp .4s ease both;
}
.scope-banner i{color:var(--teal);flex-shrink:0}
.scope-banner strong{color:var(--text)}
.scope-banner .sep{color:var(--muted)}
.scope-banner .role-tag{
  margin-left:auto;font-size:.68rem;font-family:var(--mono);
  background:var(--teal-soft);color:var(--teal);padding:3px 8px;border-radius:6px;
}

/* ── Stats ── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px;display:flex;flex-direction:column;gap:14px;
  animation:slideUp .35s ease both;transition:transform .2s,box-shadow .2s;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.stat:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.10)}
.stat:nth-child(1){animation-delay:.05s}.stat:nth-child(2){animation-delay:.10s}
.stat:nth-child(3){animation-delay:.15s}.stat:nth-child(4){animation-delay:.20s}
.stat-top{display:flex;align-items:flex-start;justify-content:space-between}
.stat-icon{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1rem;flex-shrink:0}
.si-teal  {background:var(--teal-soft); color:var(--teal)}
.si-amber {background:var(--amber-soft);color:var(--amber-acc)}
.si-green {background:var(--green2);    color:var(--green)}
.si-purple{background:var(--purple2);   color:var(--purple)}
.si-blue  {background:var(--blue2);     color:var(--blue)}
.stat-val{font-size:1.8rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.74rem;color:var(--muted);margin-top:2px}
.tag{font-size:.64rem;padding:3px 8px;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:3px;font-family:var(--mono)}
.tag.up  {background:var(--green2);color:var(--green)}
.tag.info{background:var(--blue2); color:var(--blue)}
.tag.warn{background:var(--amber2);color:var(--amber)}

/* ── Tabs ── */
.tabs{
  display:flex;gap:5px;flex-wrap:wrap;
  background:var(--card);border:1px solid var(--border);
  border-radius:12px;padding:6px;margin-bottom:20px;
  box-shadow:0 1px 6px rgba(15,118,110,.06);
}
.tab-btn{
  display:flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;
  border:none;font-size:.8rem;font-weight:600;cursor:pointer;
  color:var(--muted);background:transparent;transition:all .2s;
  white-space:nowrap;font-family:var(--font);
}
.tab-btn:hover{background:var(--teal-soft);color:var(--teal)}
.tab-btn.active{
  background:var(--teal-soft);color:var(--teal);
  border:1px solid var(--border-accent);
  box-shadow:0 1px 8px rgba(20,184,166,.12);
}
.panel{display:none;animation:slideUp .35s ease both}
.panel.active{display:block}

/* ── Cards ── */
.card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;margin-bottom:18px;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.card-hd{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-bottom:1px solid var(--border);
  background:#F0FDFA;
}
.card-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal);font-size:.82rem}
.card-link{
  font-size:.72rem;color:var(--teal);text-decoration:none;
  border:1px solid var(--border-accent);padding:4px 10px;border-radius:6px;
  transition:all .18s;white-space:nowrap;
}
.card-link:hover{background:var(--teal-soft)}

/* ── Today's classes ── */
.tt-wrap{padding:14px 18px;display:flex;flex-direction:column;gap:9px}
.tt-empty{padding:28px;text-align:center;color:var(--muted);font-size:.82rem}
.tt-empty i{font-size:1.6rem;display:block;margin-bottom:10px;opacity:.22}
.tt-item{
  display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;
  background:#F8FAFC;border:1px solid var(--border);transition:border-color .2s,background .2s;
}
.tt-item:hover{border-color:var(--border-accent);background:var(--card-hover)}
.tt-item.now{border-color:rgba(22,163,74,.28)!important;background:rgba(22,163,74,.04)!important}
.tt-time{font-family:var(--mono);font-size:.76rem;color:var(--teal);min-width:110px;flex-shrink:0}
.tt-time.now{color:var(--green)}
.tt-info{flex:1;min-width:0}
.tt-name{font-size:.84rem;font-weight:600;color:var(--text)}
.tt-sub{font-size:.7rem;color:var(--muted);margin-top:2px;font-family:var(--mono)}
.tt-room{
  font-size:.7rem;color:var(--muted);background:#fff;border:1px solid var(--border);
  padding:3px 8px;border-radius:6px;display:flex;align-items:center;gap:5px;white-space:nowrap;
}
.tt-room i{color:var(--teal);font-size:.62rem}
.now-badge{
  font-size:.58rem;font-weight:700;background:var(--green);color:#fff;
  padding:2px 6px;border-radius:4px;margin-left:6px;font-family:var(--mono);
  animation:pulse 2s infinite;
}
.tt-sec-badge{font-size:.62rem;background:var(--teal-soft);color:var(--teal);padding:2px 7px;border-radius:5px;font-family:var(--mono);flex-shrink:0}

/* ── Weekly grid ── */
.week-wrap{overflow-x:auto;padding:16px 20px}
.week-grid{width:100%;border-collapse:separate;border-spacing:3px;min-width:700px}
.week-grid th{
  padding:9px 12px;text-align:center;font-size:.7rem;font-weight:700;
  letter-spacing:.06em;text-transform:uppercase;color:var(--muted);
  background:#F1F5F9;border-radius:8px;
}
.week-grid th.day-h  {color:var(--teal);  background:var(--teal-soft); border:1px solid var(--border-accent)}
.week-grid th.today-h{color:var(--green); background:var(--green2);    border:1px solid rgba(22,163,74,.25)}
.week-grid td{
  padding:0;height:70px;vertical-align:top;border-radius:10px;
  background:#FAFAFA;border:1px solid var(--border);min-width:100px;
}
.week-grid td.empty    {background:#F1F5F9;cursor:default}
.week-grid td.break-td {background:rgba(245,158,11,.06);border-color:rgba(245,158,11,.18);cursor:default}
.week-grid td.lunch-td {background:rgba(22,163,74,.05); border-color:rgba(22,163,74,.18); cursor:default}
.week-grid td.filled   {cursor:pointer;transition:border-color .2s,box-shadow .2s}
.week-grid td.filled:hover{border-color:var(--border-accent);box-shadow:0 2px 10px rgba(20,184,166,.10)}
.slot-inner{padding:8px 10px;height:100%;display:flex;flex-direction:column;justify-content:space-between}
.s-name{font-size:.76rem;font-weight:700;color:var(--text);line-height:1.2}
.s-code{font-size:.66rem;color:var(--muted);margin-top:2px;font-family:var(--mono)}
.s-room{font-size:.63rem;color:var(--teal);margin-top:2px}
.s-sec {font-size:.6rem;color:var(--purple);font-family:var(--mono)}
.break-lbl{font-size:.7rem;font-weight:700;color:var(--amber);  display:flex;align-items:center;justify-content:center;gap:5px;height:100%}
.lunch-lbl{font-size:.7rem;font-weight:700;color:var(--green);  display:flex;align-items:center;justify-content:center;gap:5px;height:100%}
.time-col{font-size:.7rem;color:var(--muted);background:transparent!important;border:none!important;text-align:right;padding-right:10px;min-width:90px;cursor:default!important}
.time-col:hover{background:transparent!important;border-color:transparent!important}

/* Slot accent bar colours */
.slot-c1 .slot-inner{border-left:3px solid var(--teal)}
.slot-c2 .slot-inner{border-left:3px solid var(--amber-acc)}
.slot-c3 .slot-inner{border-left:3px solid #8B5CF6}
.slot-c4 .slot-inner{border-left:3px solid var(--red)}
.slot-c5 .slot-inner{border-left:3px solid #3B82F6}
.slot-c6 .slot-inner{border-left:3px solid var(--green)}
.slot-c7 .slot-inner{border-left:3px solid #EC4899}
.slot-c8 .slot-inner{border-left:3px solid #F97316}

/* JS COLOR_HEX must match — kept in JS as inline hex */

/* ── Admin grid / controls ── */
.tt-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;padding:16px 20px;border-bottom:1px solid var(--border);background:#FAFFFE}
.fg{display:flex;flex-direction:column;gap:6px;justify-content:flex-end}
.fg label{font-size:.7rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;display:block}
.form-control{
  padding:9px 13px;border-radius:9px;
  background:rgba(20,184,166,.07);
  border:1px solid rgba(20,184,166,.30);color:var(--text);font-size:.84rem;
  outline:none;transition:border-color .2s,background .2s;font-family:var(--font);
}
.form-control:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.12);background:rgba(20,184,166,.10)}
.form-control option{background:#fff;color:var(--text)}

/* Editable cell states */
.week-grid td.filled.editable:hover .s-del{opacity:1}
.week-grid td.empty.editable{cursor:pointer}
.week-grid td.empty.editable:hover{background:var(--teal-soft);border-color:var(--border-accent)}
.week-grid td.empty.editable:hover .slot-add{opacity:1}
.slot-add{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;color:var(--teal);font-size:1rem;pointer-events:none}
.s-del{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:4px;background:var(--red2);border:none;color:var(--red);font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s}
.week-grid td{position:relative}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;border:none;font-size:.81rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap;font-family:var(--font)}
.btn-primary{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,118,110,.28)}
.btn-ghost{background:#F8FAFC;color:var(--text);border:1px solid var(--border)}
.btn-teal{background:#0F766E;color:#fff;border:1px solid #0F766E}
.btn-teal:hover{background:#0D5C56;border-color:#0D5C56;color:#fff}
.btn-ghost:hover{border-color:var(--border-accent);color:var(--teal);background:var(--teal-soft)}
.btn-amber{background:var(--amber-soft);color:var(--amber-acc);border:1px solid rgba(245,158,11,.22)}
.btn-red  {background:var(--red2);   color:var(--red);  border:1px solid rgba(220,38,38,.22)}
.btn-green{background:var(--green2); color:var(--green);border:1px solid rgba(22,163,74,.22)}
.btn-purple{background:var(--purple2);color:var(--purple);border:1px solid rgba(124,58,237,.22)}
.btn-sm{padding:6px 11px;font-size:.75rem;border-radius:7px}

/* ── Modals ── */
.overlay{position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.50);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s}
.overlay.open{opacity:1;pointer-events:all}
.modal{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  width:100%;max-width:500px;transform:scale(.95) translateY(8px);
  transition:transform .25s;max-height:90vh;overflow-y:auto;
  box-shadow:0 28px 60px rgba(15,118,110,.18);
}
.overlay.open .modal{transform:scale(1) translateY(0)}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;border-bottom:1px solid var(--border);background:#F0FDFA}
.modal-title{font-size:.95rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:9px}
.modal-title i{color:var(--teal)}
.modal-x{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:#F1F5F9;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;font-size:.8rem}
.modal-x:hover{color:var(--red);border-color:rgba(220,38,38,.3);background:var(--red2)}
.modal-body{padding:22px}
.modal-ft{padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.full{grid-column:1/-1}

/* ── Alert boxes ── */
.alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.81rem}
.alert-info{background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--text)}
.alert-info i{color:var(--teal);flex-shrink:0}
.alert-warn{background:var(--amber-soft);border:1px solid rgba(245,158,11,.22);color:var(--text)}
.alert-warn i{color:var(--amber-acc);flex-shrink:0}

/* ── Legend ── */
.legend{display:flex;flex-wrap:wrap;gap:14px;padding:12px 20px;border-top:1px solid var(--border);font-size:.72rem}
.legend-item{display:flex;align-items:center;gap:5px}

/* ── Badge ── */
.badge{font-size:.65rem;font-weight:700;padding:3px 9px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;text-align:center}
.bg-teal{background:#0F766E;color:#ffffff}
.bg-green{background:var(--green2);    color:var(--green)}
.bg-amber{background:var(--amber-soft);color:var(--amber-acc)}

/* ── Empty ── */
.empty-state{text-align:center;padding:40px 24px;color:var(--muted)}
.empty-state i{font-size:2rem;display:block;margin-bottom:10px;opacity:.22}
.empty-state p{font-size:.84rem}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* Animations */
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}

/* Responsive */
@media(max-width:1180px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%}.content{padding:14px}
  .stats{grid-template-columns:1fr 1fr}.hamburger{display:grid}
}
/* Print */
@media print{
  .sidebar,.topbar,.tabs,.scope-banner,.tt-controls,.btn,#toast-container{display:none!important}
  .main{margin-left:0}.bg,.bg-grid{display:none}
  .card{border:1px solid #ccc;background:#fff;box-shadow:none}
  .week-grid td,.week-grid th{border:1px solid #ddd!important;color:#000!important;background:#fff!important}
  .s-name{color:#000!important}.s-code,.s-room{color:#555!important}
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>

<div class="shell">

<!-- ══ SIDEBAR ═══════════════════════════════════════════════════════════════ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">

  <div class="sidebar-logo">
    <div class="logo-mark">PE</div>
    <div class="logo-text">PEPA <span>ERP Platform</span></div>
    <button class="sb-collapse-btn" id="sidebarCollapseBtn" onclick="collapseSidebar()" title="Collapse sidebar">
      <i class="fas fa-angles-left" id="collapseIcon"></i>
    </button>
  </div>

  <!-- Scrollable area: scope chip + nav -->
  <div class="sidebar-scroll">
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
        <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($deptName) ?></span>
      </div>
    </div>

    <nav class="sidebar-nav" style="flex:1;min-height:0">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard">
        <i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span>
      </a>
      <a href="students.php" class="nav-item" data-tip="Students">
        <i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span>
      </a>

      <div class="nav-label nav-section-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance">
        <i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span>
      </a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results">
        <i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
      </a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations">
        <i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
      </a>
      <a href="timetable.php" class="nav-item active" data-tip="Timetable">
        <i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span>
      </a>

      <div class="nav-label nav-section-label">Communication</div>
      <a href="staff_noticeboard.php" class="nav-item" data-tip="Staff Noticeboard">
        <i class="fas fa-clipboard-list"></i><span class="nav-text"> Staff Noticeboard</span>
      </a>
      <a href="leave_application.php" class="nav-item" data-tip="Leave Application">
        <i class="fas fa-calendar-minus"></i><span class="nav-text"> Leave Application</span>
      </a>
      <a href="expense_apply.php" class="nav-item" data-tip="Expense Apply">
        <i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span>
      </a>

      <div class="nav-label nav-section-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile">
        <i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span>
      </a>
    </nav>
  </div><!-- /sidebar-scroll -->

  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag">Faculty · <?= esc($deptCode ?: $deptName) ?></div>
    </div>
    <button class="logout-btn" title="Logout" onclick="doLogout()">
      <i class="fas fa-arrow-right-from-bracket"></i>
    </button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">

  <header class="topbar">
    <div class="topbar-title">Timetable</div>
    <div style="display:flex;align-items:center;gap:8px">
      <div class="topbar-btn" title="Notifications"><i class="fas fa-bell"></i></div>
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

    <?php if ($role === 'super_admin'): ?>
    <!-- Super-admin scope switcher -->
    <div style="background:var(--purple2);border:1px solid rgba(124,58,237,.22);border-radius:10px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;flex-wrap:wrap;gap:12px">
      <i class="fas fa-user-shield" style="color:var(--purple)"></i>
      <span style="font-size:.8rem;font-weight:700;color:var(--text)">Super Admin — Switch Department View:</span>
      <form method="GET" action="" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0">
        <select name="sa_college" id="sa-college-sel" onchange="saDeptFilter()" style="background:#fff;border:1px solid rgba(124,58,237,.25);border-radius:8px;color:var(--text);padding:6px 12px;font-size:.78rem;outline:none;font-family:var(--font)">
          <option value="">— College —</option>
          <?php foreach ($allCollegesForSA as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id'] == $collegeId ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="sa_dept" id="sa-dept-sel" style="background:#fff;border:1px solid rgba(124,58,237,.25);border-radius:8px;color:var(--text);padding:6px 12px;font-size:.78rem;outline:none;font-family:var(--font)">
          <option value="">— Department —</option>
          <?php foreach ($allDeptsForSA as $d): ?>
          <option value="<?= $d['id'] ?>" data-college="<?= $d['college_id'] ?>" <?= $d['id'] == $deptId ? 'selected' : '' ?> style="display:<?= ($d['college_id'] == $collegeId) ? 'block' : 'none' ?>"><?= esc($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" style="background:linear-gradient(135deg,var(--purple),#6D28D9);color:#fff;border:none;padding:6px 16px;border-radius:8px;font-size:.78rem;font-weight:600;cursor:pointer;font-family:var(--font)">
          <i class="fas fa-arrow-right"></i> Switch
        </button>
        <a href="timetable.php" style="font-size:.75rem;color:var(--muted);text-decoration:none;padding:6px 10px;border-radius:8px;border:1px solid var(--border);background:#fff">
          <i class="fas fa-rotate-left"></i> Reset
        </a>
      </form>
    </div>
    <script>
    function saDeptFilter() {
      const cid = parseInt(document.getElementById('sa-college-sel').value) || 0;
      document.querySelectorAll('#sa-dept-sel option').forEach(o => {
        if (!o.value) return;
        o.style.display = (!cid || parseInt(o.dataset.college) === cid) ? 'block' : 'none';
      });
      document.getElementById('sa-dept-sel').value = '';
    }
    </script>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats">
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-teal"><i class="fas fa-book-open"></i></div>
          <div class="tag up"><i class="fas fa-check"></i> Active</div>
        </div>
        <div>
          <div class="stat-val"><?= $statCourses ?></div>
          <div class="stat-lbl">Dept Courses</div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-amber"><i class="fas fa-chalkboard-teacher"></i></div>
          <div class="tag info"><i class="fas fa-users"></i> Dept</div>
        </div>
        <div>
          <div class="stat-val"><?= $statFaculty ?></div>
          <div class="stat-lbl">Faculty Members</div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-purple"><i class="fas fa-users"></i></div>
          <div class="tag info"><i class="fas fa-layer-group"></i> Sections</div>
        </div>
        <div>
          <div class="stat-val"><?= $statSections ?></div>
          <div class="stat-lbl">Dept Sections</div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-green"><i class="fas fa-calendar-check"></i></div>
          <div class="tag up"><i class="fas fa-clock"></i> Today</div>
        </div>
        <div>
          <div class="stat-val"><?= count($todaySlots) ?></div>
          <div class="stat-lbl">Classes Today</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" id="tab-today"   onclick="switchTab('today')">
        <i class="fas fa-calendar-day"></i> Today's Classes
      </button>
      <button class="tab-btn"         id="tab-weekly" onclick="switchTab('weekly')">
        <i class="fas fa-calendar-week"></i> My Weekly Schedule
      </button>
      <button class="tab-btn"         id="tab-full"   onclick="switchTab('full')">
        <i class="fas fa-table"></i> Full Dept Timetable
      </button>
      <?php if ($isAdmin): ?>
      <button class="tab-btn"         id="tab-edit"   onclick="switchTab('edit')">
        <i class="fas fa-pen-to-square"></i> Edit Timetable
      </button>
      <?php endif; ?>
    </div>

    <!-- ══ TAB: Today ══ -->
    <div class="panel active" id="panel-today">
      <div class="card">
        <div class="card-hd">
          <div class="card-title">
            <i class="fas fa-calendar-day"></i>
            Today's Classes
            <span style="font-size:.68rem;color:var(--muted);font-weight:400;font-family:var(--mono)"><?= date('l, d M Y') ?></span>
          </div>
          <span class="badge bg-teal"><?= count($todaySlots) ?> class<?= count($todaySlots) !== 1 ? 'es' : '' ?></span>
        </div>
        <?php if (empty($todaySlots)): ?>
          <div class="tt-empty">
            <i class="fas fa-mug-hot"></i>
            No classes assigned to you today — enjoy the day!<br>
            <span style="font-size:.7rem;margin-top:6px;display:block">Check "My Weekly Schedule" for other days.</span>
          </div>
        <?php else: ?>
          <div class="tt-wrap">
            <?php
              $nowMins = (int)date('H') * 60 + (int)date('i');
              foreach ($todaySlots as $t):
                [$sh, $sm] = explode(':', substr($t['start_time'],0,5));
                [$eh, $em] = explode(':', substr($t['end_time'],0,5));
                $startMins = (int)$sh*60+(int)$sm;
                $endMins   = (int)$eh*60+(int)$em;
                $isNow     = ($nowMins >= $startMins && $nowMins < $endMins);
            ?>
            <div class="tt-item <?= $isNow ? 'now' : '' ?>">
              <div class="tt-time <?= $isNow ? 'now' : '' ?>">
                <?= esc(substr($t['start_time'],0,5)) ?> – <?= esc(substr($t['end_time'],0,5)) ?>
                <?php if ($isNow): ?><span class="now-badge">NOW</span><?php endif; ?>
              </div>
              <div class="tt-info">
                <div class="tt-name"><?= esc($t['course_name'] ?? $t['period_label']) ?></div>
                <div class="tt-sub"><?= esc($t['course_code'] ?? '') ?>
                  <?php if (!empty($t['section_label'])): ?>
                    · Sec <?= esc($t['section_label']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <?php if (!empty($t['sem_name'])): ?>
                <span class="tt-sec-badge"><?= esc($t['sem_name']) ?></span>
              <?php endif; ?>
              <?php if (!empty($t['room_name'])): ?>
                <div class="tt-room"><i class="fas fa-location-dot"></i><?= esc($t['room_name']) ?></div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Periods timeline reference -->
      <?php if (!empty($periods)): ?>
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-clock"></i> Daily Period Schedule</div>
          <span class="badge bg-teal"><?= count($periods) ?> slots</span>
        </div>
        <div style="padding:14px 18px;display:flex;flex-direction:column;gap:7px">
          <?php foreach ($periods as $p):
            $isBreak = $p['type'] === 'Break';
            $isLunch = $p['type'] === 'Lunch';
            $dur = (strtotime($p['end_time']) - strtotime($p['start_time'])) / 60;
          ?>
          <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:9px;background:#F8FAFC;border:1px solid var(--border)">
            <div style="width:26px;height:26px;border-radius:7px;flex-shrink:0;display:grid;place-items:center;font-size:.72rem;font-weight:700;background:<?= $isBreak ? 'rgba(217,119,6,.10)' : ($isLunch ? 'rgba(22,163,74,.10)' : 'var(--teal-soft)') ?>;color:<?= $isBreak ? 'var(--amber)' : ($isLunch ? 'var(--green)' : 'var(--teal)') ?>">
              <?= $isBreak ? '☕' : ($isLunch ? '🍽' : $p['sort_order']) ?>
            </div>
            <div style="font-family:var(--mono);font-size:.78rem;color:<?= $isBreak ? 'var(--amber)' : ($isLunch ? 'var(--green)' : 'var(--teal)') ?>;min-width:120px">
              <?= substr($p['start_time'],0,5) ?> – <?= substr($p['end_time'],0,5) ?>
            </div>
            <div style="flex:1;font-size:.78rem;color:var(--text)"><?= esc($p['label']) ?></div>
            <div style="font-size:.72rem;color:var(--muted);font-family:var(--mono)"><?= $dur ?> min</div>
            <span class="badge <?= $isBreak ? 'bg-amber' : ($isLunch ? 'bg-green' : 'bg-teal') ?>"><?= $p['type'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ TAB: My Weekly Schedule ══ -->
    <div class="panel" id="panel-weekly">
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-calendar-week"></i> My Weekly Teaching Schedule</div>
          <div style="display:flex;gap:8px">
            <span class="badge bg-teal"><?= count($myAllSlots) ?> total slot<?= count($myAllSlots) !== 1 ? 's' : '' ?></span>
            <button class="btn btn-teal btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
          </div>
        </div>

        <?php if (empty($myAllSlots)): ?>
          <div class="empty-state">
            <i class="fas fa-calendar-xmark"></i>
            <p>No teaching slots assigned to you yet.<br>Contact your department HOD or admin.</p>
          </div>
        <?php else: ?>
          <div class="week-wrap">
            <?php
              $sortedPeriods = $periods; // already sorted by sort_order
              $dayList       = ['Mon','Tue','Wed','Thu','Fri','Sat'];
              $dayFull       = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday'];
              $todayD        = date('D');

              // Color map per course
              $colColors = ['slot-c1','slot-c2','slot-c3','slot-c4','slot-c5','slot-c6','slot-c7','slot-c8'];
              $courseColorMap = [];
              $ci = 0;
              foreach ($myAllSlots as $sl) {
                  if ($sl['course_id'] && !isset($courseColorMap[$sl['course_id']])) {
                      $courseColorMap[$sl['course_id']] = $colColors[$ci % 8];
                      $ci++;
                  }
              }

              // Build slot lookup keyed by period_id + day
              $slotLookup = [];
              foreach ($myAllSlots as $sl) {
                  $slotLookup[$sl['period_id']][$sl['day']] = $sl;
              }
            ?>
            <table class="week-grid">
              <thead>
                <tr>
                  <th style="min-width:90px">Time</th>
                  <?php foreach ($dayList as $d): ?>
                    <th class="<?= $d === $todayD ? 'today-h' : 'day-h' ?>">
                      <?= $dayFull[$d] ?>
                      <?php if ($d === $todayD): ?> <span style="font-size:.6rem;display:block;color:var(--green)">(Today)</span><?php endif; ?>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sortedPeriods as $p):
                  $isBreak = $p['type'] === 'Break';
                  $isLunch = $p['type'] === 'Lunch';
                ?>
                <tr>
                  <td class="time-col">
                    <?= substr($p['start_time'],0,5) ?> – <?= substr($p['end_time'],0,5) ?>
                    <br><small style="font-size:.58rem"><?= esc($p['label']) ?></small>
                  </td>
                  <?php if ($isBreak): ?>
                    <td class="break-td" colspan="6"><div class="break-lbl"><i class="fas fa-mug-hot"></i> Short Break (<?= (strtotime($p['end_time'])-strtotime($p['start_time']))/60 ?> min)</div></td>
                  <?php elseif ($isLunch): ?>
                    <td class="lunch-td" colspan="6"><div class="lunch-lbl"><i class="fas fa-utensils"></i> Lunch Break (<?= (strtotime($p['end_time'])-strtotime($p['start_time']))/60 ?> min)</div></td>
                  <?php else: ?>
                    <?php foreach ($dayList as $d):
                      $sl = ($slotLookup[$p['id']] ?? [])[$d] ?? null;
                      $cc = $sl && $sl['course_id'] ? ($courseColorMap[$sl['course_id']] ?? 'slot-c1') : '';
                    ?>
                    <?php if ($sl): ?>
                      <td class="filled <?= $cc ?>">
                        <div class="slot-inner">
                          <div>
                            <div class="s-name"><?= esc($sl['course_name'] ?? '—') ?></div>
                            <div class="s-code"><?= esc($sl['course_code'] ?? '') ?></div>
                          </div>
                          <div>
                            <?php if (!empty($sl['room_name'])): ?>
                              <div class="s-room"><i class="fas fa-location-dot" style="font-size:.58rem"></i> <?= esc($sl['room_name']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($sl['section_label'])): ?>
                              <div class="s-sec">Sec <?= esc($sl['section_label']) ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    <?php else: ?>
                      <td class="empty"></td>
                    <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Legend -->
          <div class="legend">
            <span style="font-size:.72rem;color:var(--muted);font-weight:600">Courses:</span>
            <?php
              $seenNames = [];
              foreach ($myAllSlots as $sl) {
                  if ($sl['course_id'] && !in_array($sl['course_id'], $seenNames)) {
                      $seenNames[] = $sl['course_id'];
                      $cc = $courseColorMap[$sl['course_id']] ?? 'slot-c1';
                      $colorHex = ['slot-c1'=>'#00d4bb','slot-c2'=>'#f59e0b','slot-c3'=>'#a78bfa','slot-c4'=>'#ef4444','slot-c5'=>'#60a5fa','slot-c6'=>'#10b981','slot-c7'=>'#f472b6','slot-c8'=>'#f59e0b'];
                      $hex = $colorHex[$cc] ?? '#00d4bb';
                      echo '<span class="legend-item" style="color:' . $hex . '"><i class="fas fa-square" style="font-size:.7rem"></i>' . esc($sl['course_name']) . '</span>';
                  }
              }
            ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ TAB: Full Dept Timetable (view all sections) ══ -->
    <div class="panel" id="panel-full">
      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-table"></i> Full Department Timetable</div>
          <button class="btn btn-teal btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
        <div class="tt-controls">
          <div class="fg">
            <label>Semester</label>
            <select class="form-control" id="full-sem" onchange="loadFullSections()" style="min-width:180px">
              <?php foreach ($semesters as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($s['status'] === 'Current' ? 'selected' : '') ?>>
                  <?= esc($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Section</label>
            <select class="form-control" id="full-sec" style="min-width:140px"></select>
          </div>
          <div class="fg">
            <label>&nbsp;</label>
            <button class="btn btn-primary" onclick="viewFullTimetable()" style="padding:9px 13px;height:auto;width:100%;justify-content:center">
              <i class="fas fa-eye"></i> View
            </button>
          </div>
        </div>
        <div id="full-grid-wrap">
          <div class="empty-state">
            <i class="fas fa-calendar-days"></i>
            <p>Select a semester and section, then click View.</p>
          </div>
        </div>
        <div class="legend" id="full-legend" style="display:none"></div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- ══ TAB: Edit Timetable (admin only) ══ -->
    <div class="panel" id="panel-edit">
      <div class="alert alert-warn">
        <i class="fas fa-triangle-exclamation"></i>
        <span>Edit mode is restricted to <strong>college admins</strong>. All changes are scoped to <strong><?= esc($deptName) ?></strong> in <strong><?= esc($collegeName) ?></strong>.</span>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-sliders"></i> Select Timetable to Edit</div>
          <span class="badge bg-teal" id="edit-status-badge">No timetable loaded</span>
        </div>
        <div class="tt-controls">
          <div class="fg">
            <label>Semester</label>
            <select class="form-control" id="edit-sem" onchange="loadEditSections()" style="min-width:180px">
              <?php foreach ($semesters as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($s['status'] === 'Current' ? 'selected' : '') ?>>
                  <?= esc($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Section</label>
            <select class="form-control" id="edit-sec" style="min-width:130px"></select>
          </div>
          <button class="btn btn-primary" onclick="loadEditTimetable()" style="align-self:flex-end">
            <i class="fas fa-eye"></i> Load
          </button>
          <button class="btn btn-ghost" onclick="openCreateTTModal()" style="align-self:flex-end">
            <i class="fas fa-plus"></i> New
          </button>
          <div style="margin-left:auto;display:flex;gap:8px;align-self:flex-end">
            <button class="btn btn-purple" onclick="autoGenerate()"><i class="fas fa-wand-magic-sparkles"></i> Auto-Gen</button>
            <button class="btn btn-green"  onclick="activateTimetable()"><i class="fas fa-floppy-disk"></i> Activate</button>
            <button class="btn btn-red btn-sm" onclick="clearAll()"><i class="fas fa-trash"></i> Clear</button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-hd">
          <div class="card-title"><i class="fas fa-pen-ruler"></i> Timetable Grid — Click a cell to assign</div>
        </div>
        <div id="edit-grid-wrap">
          <div class="empty-state">
            <i class="fas fa-calendar-plus"></i>
            <p>Load a timetable above to start editing.</p>
          </div>
        </div>
        <div class="legend" id="edit-legend" style="display:none"></div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ══ Modal: Create Timetable ════════════════════════════════════════════════ -->
<div class="overlay" id="modal-create-tt">
  <div class="modal">
    <div class="modal-hd">
      <div class="modal-title"><i class="fas fa-calendar-plus"></i> Create Timetable</div>
      <button class="modal-x" onclick="closeModal('modal-create-tt')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info"><i class="fas fa-circle-info"></i> Creates a timetable for your department's section. You can then assign slots.</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Semester *</label>
          <select class="form-control" id="ctt-sem" onchange="loadCTTSections()">
            <?php foreach ($semesters as $s): ?>
              <option value="<?= $s['id'] ?>"><?= esc($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Section *</label>
          <select class="form-control" id="ctt-sec"></select>
        </div>
        <div class="form-group full">
          <label class="form-label">Academic Year</label>
          <input class="form-control" id="ctt-year" placeholder="e.g. 2025-26">
        </div>
      </div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-ghost" onclick="closeModal('modal-create-tt')">Cancel</button>
      <button class="btn btn-primary" onclick="createTimetable()"><i class="fas fa-check"></i> Create</button>
    </div>
  </div>
</div>

<!-- ══ Modal: Assign Slot ═════════════════════════════════════════════════════ -->
<div class="overlay" id="modal-slot">
  <div class="modal">
    <div class="modal-hd">
      <div class="modal-title"><i class="fas fa-pencil-ruler"></i> Assign Slot</div>
      <button class="modal-x" onclick="closeModal('modal-slot')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" id="slot-info"><i class="fas fa-circle-info"></i> …</div>
      <div class="form-grid">
        <div class="form-group full">
          <label class="form-label">Course *</label>
          <select class="form-control" id="slot-course">
            <option value="">— Select Course —</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= $c['id'] ?>"><?= esc($c['name']) ?> (<?= esc($c['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group full">
          <label class="form-label">Faculty *</label>
          <select class="form-control" id="slot-faculty">
            <option value="">— Select Faculty —</option>
            <?php foreach ($deptFaculty as $f): ?>
              <option value="<?= $f['id'] ?>"><?= esc($f['full_name']) ?>
                <?php if ($f['designation']): ?>(<?= esc($f['designation']) ?>)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group full">
          <label class="form-label">Room / Lab *</label>
          <select class="form-control" id="slot-room">
            <option value="">— Select Room —</option>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= $r['id'] ?>"><?= esc($r['name']) ?> (<?= esc($r['type']) ?><?= $r['block'] ? ' · ' . esc($r['block']) : '' ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-red btn-sm" style="margin-right:auto" onclick="clearSlot()"><i class="fas fa-eraser"></i> Clear</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-slot')">Cancel</button>
      <button class="btn btn-primary" onclick="saveSlot()"><i class="fas fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>

<!-- ══ Delete Confirm ═════════════════════════════════════════════════════════ -->
<div class="overlay" id="modal-del">
  <div class="modal" style="max-width:380px">
    <div class="modal-hd">
      <div class="modal-title" style="color:var(--red)"><i class="fas fa-triangle-exclamation"></i> Confirm</div>
      <button class="modal-x" onclick="closeModal('modal-del')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body"><p style="font-size:.88rem;line-height:1.6">Clear all slots in this timetable? This is <strong style="color:var(--red)">permanent</strong>.</p></div>
    <div class="modal-ft">
      <button class="btn btn-ghost" onclick="closeModal('modal-del')">Cancel</button>
      <button class="btn btn-red" id="del-confirm-btn"><i class="fas fa-trash"></i> Clear All</button>
    </div>
  </div>
</div>

<div id="toast-container" style="position:fixed;bottom:24px;right:24px;z-index:999;display:flex;flex-direction:column;gap:10px"></div>

<script>
/* ── Constants from PHP ─────────────────────────────────────────────────────── */
const PERIODS  = <?= json_encode(array_map(fn($p) => ['id'=>$p['id'],'label'=>$p['label'],'type'=>$p['type'],'start'=>substr($p['start_time'],0,5),'end'=>substr($p['end_time'],0,5),'order'=>$p['sort_order']], $periods)) ?>;
const SEMESTERS= <?= json_encode(array_map(fn($s) => ['id'=>$s['id'],'name'=>$s['name']], $semesters)) ?>;
const SECTIONS = <?= json_encode(array_map(fn($s) => ['id'=>$s['id'],'label'=>$s['label'],'sem_id'=>$s['semester_id']], $sections)) ?>;
const COURSES  = <?= json_encode(array_map(fn($c) => ['id'=>$c['id'],'name'=>$c['name'],'code'=>$c['code']], $courses)) ?>;
const FACULTY  = <?= json_encode(array_map(fn($f) => ['id'=>$f['id'],'name'=>$f['full_name']], $deptFaculty)) ?>;
const ROOMS    = <?= json_encode(array_map(fn($r) => ['id'=>$r['id'],'name'=>$r['name'],'type'=>$r['type']], $rooms)) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const DAYS     = ['Mon','Tue','Wed','Thu','Fri','Sat'];
const DAY_FULL = {Mon:'Monday',Tue:'Tuesday',Wed:'Wednesday',Thu:'Thursday',Fri:'Friday',Sat:'Saturday'};
const COLORS   = ['slot-c1','slot-c2','slot-c3','slot-c4','slot-c5','slot-c6','slot-c7','slot-c8'];
const COLOR_HEX= ['#0F766E','#F59E0B','#8B5CF6','#DC2626','#3B82F6','#16A34A','#EC4899','#F97316'];

let activeTTId = null;
let _slotPeriodId = null, _slotDay = null;

/* ── Tab switch ─────────────────────────────────────────────────────────────── */
const tabMap = {today:'panel-today',weekly:'panel-weekly',full:'panel-full',edit:'panel-edit'};
function switchTab(id){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('tab-'+id)?.classList.add('active');
  document.getElementById(tabMap[id])?.classList.add('active');
  if(id==='full') initFullTab();
  if(id==='edit') initEditTab();
}

/* ── Modal ──────────────────────────────────────────────────────────────────── */
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(el=>{
  el.addEventListener('click',e=>{if(e.target===el) el.classList.remove('open');});
});

/* ── Toast ──────────────────────────────────────────────────────────────────── */
function showToast(msg,type='success'){
  const cfg={success:{bg:'rgba(22,163,74,.10)',  bd:'rgba(22,163,74,.28)', ic:'#16A34A',fa:'fa-circle-check'},
             warning:{bg:'rgba(217,119,6,.10)',   bd:'rgba(217,119,6,.28)', ic:'#D97706',fa:'fa-triangle-exclamation'},
             error  :{bg:'rgba(220,38,38,.10)',   bd:'rgba(220,38,38,.28)', ic:'#DC2626',fa:'fa-circle-xmark'}}[type]||{};
  const t=document.createElement('div');
  t.style.cssText=`display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:12px;background:${cfg.bg};border:1px solid ${cfg.bd};color:#0F172A;font-size:.84rem;box-shadow:0 6px 24px rgba(15,118,110,.14);min-width:220px;animation:toastIn .3s ease;font-family:${getComputedStyle(document.documentElement).getPropertyValue('--font')}`;
  t.innerHTML=`<i class="fas ${cfg.fa}" style="color:${cfg.ic}"></i>${msg}`;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(20px)';t.style.transition='all .3s';setTimeout(()=>t.remove(),300);},3000);
}

/* ── Ajax helper ────────────────────────────────────────────────────────────── */
async function ajax(data){
  const fd=new FormData();
  for(const k in data) fd.append(k,data[k]);
  const r=await fetch(location.href,{method:'POST',body:fd});
  return r.json();
}

/* ── Grid renderer ──────────────────────────────────────────────────────────── */
function renderGrid(slots, wrapId, legendId, editable=false){
  const periods=[...PERIODS].sort((a,b)=>a.order-b.order);
  if(!periods.length){
    document.getElementById(wrapId).innerHTML='<div class="empty-state"><i class="fas fa-clock"></i><p>No periods defined for this college.</p></div>';
    return;
  }
  const slotMap={};
  slots.forEach(s=>{
    if(!slotMap[s.period_id]) slotMap[s.period_id]={};
    slotMap[s.period_id][s.day]=s;
  });
  const colorMap={};
  let ci=0;
  slots.forEach(s=>{if(s.course_id&&!colorMap[s.course_id]){colorMap[s.course_id]=[COLORS[ci%8],COLOR_HEX[ci%8]];ci++;}});
  const today=new Date().toLocaleString('en',{weekday:'short'});

  let html=`<div style="overflow-x:auto;padding:16px 20px"><table class="week-grid"><thead><tr>
    <th style="min-width:90px">Time</th>
    ${DAYS.map(d=>`<th class="${d===today?'today-h':'day-h'}">${DAY_FULL[d]}${d===today?'<span style="font-size:.6rem;display:block;color:var(--green)">(Today)</span>':''}</th>`).join('')}
  </tr></thead><tbody>`;

  periods.forEach(p=>{
    const isBreak=p.type==='Break', isLunch=p.type==='Lunch';
    html+=`<tr><td class="time-col">${p.start} – ${p.end}<br><small style="font-size:.58rem">${esc(p.label)}</small></td>`;
    if(isBreak){
      html+=`<td class="break-td" colspan="6"><div class="break-lbl"><i class="fas fa-mug-hot"></i> Break – ${calcDur(p.start,p.end)} min</div></td>`;
    } else if(isLunch){
      html+=`<td class="lunch-td" colspan="6"><div class="lunch-lbl"><i class="fas fa-utensils"></i> Lunch – ${calcDur(p.start,p.end)} min</div></td>`;
    } else {
      DAYS.forEach(day=>{
        const slot=(slotMap[p.id]||{})[day];
        if(slot){
          const [cc,hex]=colorMap[slot.course_id]||[COLORS[0],COLOR_HEX[0]];
          const isLab=slot.room_type==='Lab';
          html+=`<td class="filled ${cc}${editable?' editable':''}" onclick="${editable?`openSlotModal(${p.id},'${day}',${slot.course_id||0},${slot.faculty_id||0},${slot.room_id||0})`:''}">
            <div class="slot-inner">
              <div>
                <div class="s-name">${esc(slot.course_name||'?')}</div>
                <div class="s-code">${esc(slot.course_code||'')}</div>
              </div>
              <div>
                ${slot.room_name?`<div class="s-room"><i class="fas fa-${isLab?'flask':'door-open'}" style="font-size:.58rem${isLab?';color:var(--amber)':''}"></i> ${esc(slot.room_name)}</div>`:''}
                ${slot.faculty_name?`<div class="s-sec" style="color:var(--muted)">${esc(slot.faculty_name.split(' ')[0])}</div>`:''}
              </div>
            </div>
            ${editable?`<button class="s-del" onclick="event.stopPropagation();deleteSlot(${p.id},'${day}')" title="Clear"><i class="fas fa-times"></i></button>`:''}
          </td>`;
        } else {
          html+=`<td class="empty${editable?' editable':''}"`+
            (editable?` onclick="openSlotModal(${p.id},'${day}')"`:'')+
            `>${editable?'<div class="slot-add"><i class="fas fa-plus-circle"></i></div>':''}</td>`;
        }
      });
    }
    html+='</tr>';
  });
  html+='</tbody></table></div>';
  document.getElementById(wrapId).innerHTML=html;

  // Legend
  const leg=document.getElementById(legendId);
  let legHtml='<span style="font-size:.72rem;color:var(--muted);font-weight:600">Courses: </span>';
  for(const[sid,[cc,hex]] of Object.entries(colorMap)){
    const s=slots.find(x=>x.course_id==sid);
    if(s) legHtml+=`<span class="legend-item" style="color:${hex}"><i class="fas fa-square" style="font-size:.68rem"></i>${esc(s.course_name)}</span>`;
  }
  leg.innerHTML=legHtml;
  leg.style.display='flex';
}

/* ── Full Dept Timetable ────────────────────────────────────────────────────── */
function initFullTab(){
  loadFullSections();
}
async function loadFullSections(){
  const sem_id=document.getElementById('full-sem').value;
  const r=await ajax({ajax_action:'get_sections',sem_id});
  const sel=document.getElementById('full-sec');
  sel.innerHTML=r.sections&&r.sections.length
    ? r.sections.map(s=>`<option value="${s.id}">Section ${esc(s.label)}</option>`).join('')
    : '<option value="">No sections</option>';
}
async function viewFullTimetable(){
  const sem_id=document.getElementById('full-sem').value;
  const sec_id=document.getElementById('full-sec').value;
  if(!sec_id){showToast('Select a section first.','warning');return;}
  const fr=await ajax({ajax_action:'find_tt',sem_id,sec_id});
  if(!fr.timetable){
    document.getElementById('full-grid-wrap').innerHTML='<div class="empty-state"><i class="fas fa-calendar-days"></i><p>No timetable found for this selection.<br>'+(IS_ADMIN?'Go to <strong>Edit Timetable</strong> tab to create one.':'Contact your department admin.')+'</p></div>';
    document.getElementById('full-legend').style.display='none';
    return;
  }
  const lr=await ajax({ajax_action:'load_tt',tt_id:fr.timetable.id});
  renderGrid(lr.slots||[],'full-grid-wrap','full-legend',false);
}

/* ── Edit Timetable tab ─────────────────────────────────────────────────────── */
function initEditTab(){
  loadEditSections();
}
async function loadEditSections(){
  const sem_id=document.getElementById('edit-sem').value;
  const r=await ajax({ajax_action:'get_sections',sem_id});
  const sel=document.getElementById('edit-sec');
  sel.innerHTML=r.sections&&r.sections.length
    ? r.sections.map(s=>`<option value="${s.id}">Section ${esc(s.label)}</option>`).join('')
    : '<option value="">No sections</option>';
}
async function loadEditTimetable(){
  const sem_id=document.getElementById('edit-sem').value;
  const sec_id=document.getElementById('edit-sec').value;
  if(!sec_id){showToast('Select a section.','warning');return;}
  const fr=await ajax({ajax_action:'find_tt',sem_id,sec_id});
  if(!fr.timetable){
    document.getElementById('edit-grid-wrap').innerHTML='<div class="empty-state"><i class="fas fa-calendar-plus"></i><p>No timetable yet. Click <strong>New</strong> to create one.</p></div>';
    document.getElementById('edit-status-badge').textContent='No timetable';
    document.getElementById('edit-legend').style.display='none';
    activeTTId=null;
    return;
  }
  activeTTId=fr.timetable.id;
  document.getElementById('edit-status-badge').textContent='ID #'+activeTTId+' · '+fr.timetable.status+' · '+fr.timetable.academic_year;
  const lr=await ajax({ajax_action:'load_tt',tt_id:activeTTId});
  renderGrid(lr.slots||[],'edit-grid-wrap','edit-legend',true);
}

/* ── Create timetable modal ─────────────────────────────────────────────────── */
async function loadCTTSections(){
  const sem_id=document.getElementById('ctt-sem').value;
  const r=await ajax({ajax_action:'get_sections',sem_id});
  document.getElementById('ctt-sec').innerHTML=r.sections&&r.sections.length
    ? r.sections.map(s=>`<option value="${s.id}">Section ${esc(s.label)}</option>`).join('')
    : '<option value="">No sections available</option>';
}
function openCreateTTModal(){
  loadCTTSections();
  document.getElementById('ctt-year').value='';
  openModal('modal-create-tt');
}
async function createTimetable(){
  const r=await ajax({ajax_action:'create_timetable',sem_id:document.getElementById('ctt-sem').value,sec_id:document.getElementById('ctt-sec').value,acad_year:document.getElementById('ctt-year').value});
  if(r.success){
    closeModal('modal-create-tt');
    activeTTId=r.tt_id;
    showToast(r.existing?'Timetable loaded!':'Timetable created!','success');
    // Sync edit tab selects and reload
    document.getElementById('edit-sem').value=document.getElementById('ctt-sem').value;
    await loadEditSections();
    document.getElementById('edit-sec').value=document.getElementById('ctt-sec').value;
    loadEditTimetable();
  } else showToast(r.message||'Error','error');
}

/* ── Slot modal ─────────────────────────────────────────────────────────────── */
function openSlotModal(periodId,day,existingCourse=0,existingFaculty=0,existingRoom=0){
  if(!activeTTId){showToast('Load a timetable first.','warning');return;}
  _slotPeriodId=periodId; _slotDay=day;
  const p=PERIODS.find(x=>x.id==periodId);
  document.getElementById('slot-info').innerHTML=`<i class="fas fa-circle-info"></i> <strong>${DAY_FULL[day]}</strong> — ${p?p.label:''} (${p?p.start+' – '+p.end:''})`;
  if(existingCourse)  document.getElementById('slot-course').value=existingCourse;
  if(existingFaculty) document.getElementById('slot-faculty').value=existingFaculty;
  if(existingRoom)    document.getElementById('slot-room').value=existingRoom;
  if(!existingCourse){ document.getElementById('slot-course').value=''; }
  if(!existingFaculty){ document.getElementById('slot-faculty').value=''; }
  if(!existingRoom){ document.getElementById('slot-room').value=''; }
  openModal('modal-slot');
}
async function saveSlot(){
  if(!activeTTId) return;
  const faculty_id=document.getElementById('slot-faculty').value;
  const room_id   =document.getElementById('slot-room').value;
  // Conflict check
  const cr=await ajax({ajax_action:'check_conflict',tt_id:activeTTId,period_id:_slotPeriodId,day:_slotDay,faculty_id,room_id});
  if(cr.conflicts&&cr.conflicts.length){
    if(!confirm('⚠️ Conflict detected:\n\n'+cr.conflicts.join('\n')+'\n\nSave anyway?')) return;
  }
  const r=await ajax({ajax_action:'save_slot',tt_id:activeTTId,period_id:_slotPeriodId,day:_slotDay,course_id:document.getElementById('slot-course').value,faculty_id,room_id});
  if(r.success){closeModal('modal-slot');showToast('Slot saved!','success');loadEditTimetable();}
  else showToast(r.message||'Error','error');
}
async function clearSlot(){
  if(!activeTTId) return;
  const r=await ajax({ajax_action:'clear_slot',tt_id:activeTTId,period_id:_slotPeriodId,day:_slotDay});
  if(r.success){closeModal('modal-slot');showToast('Slot cleared.','warning');loadEditTimetable();}
  else showToast(r.message,'error');
}
async function deleteSlot(periodId,day){
  if(!activeTTId) return;
  const r=await ajax({ajax_action:'clear_slot',tt_id:activeTTId,period_id:periodId,day});
  if(r.success){showToast('Slot cleared.','warning');loadEditTimetable();}
  else showToast(r.message,'error');
}

/* ── Admin actions ──────────────────────────────────────────────────────────── */
async function activateTimetable(){
  if(!activeTTId){showToast('Load a timetable first.','warning');return;}
  const r=await ajax({ajax_action:'activate_timetable',tt_id:activeTTId});
  if(r.success){showToast('Timetable activated!','success');loadEditTimetable();}
  else showToast(r.message||'Error','error');
}
function clearAll(){
  if(!activeTTId){showToast('No timetable loaded.','warning');return;}
  document.getElementById('del-confirm-btn').onclick=async()=>{
    const r=await ajax({ajax_action:'clear_timetable',tt_id:activeTTId});
    if(r.success){closeModal('modal-del');showToast('All slots cleared.','warning');loadEditTimetable();}
    else showToast(r.message,'error');
  };
  openModal('modal-del');
}
async function autoGenerate(){
  if(!activeTTId){showToast('Load a timetable first.','warning');return;}
  showToast('Auto-generating…','warning');
  const r=await ajax({ajax_action:'auto_generate',tt_id:activeTTId});
  if(r.success){showToast('Auto-generated!','success');loadEditTimetable();}
  else showToast(r.message||'Error','error');
}

/* ── Utility ────────────────────────────────────────────────────────────────── */
function esc(s){return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function calcDur(s,e){const[sh,sm]=s.split(':').map(Number),[eh,em]=e.split(':').map(Number);return(eh*60+em)-(sh*60+sm);}

/* ── Clock ──────────────────────────────────────────────────────────────────── */
function tick(){
  const now=new Date();
  // topbarDate removed
}
tick(); setInterval(tick,30000);

/* ── Sidebar collapse (desktop) ─────────────────────────────────────────── */
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
// Restore collapse state on load
(function(){
  if(localStorage.getItem('sbCollapsed') === '1'){
    const sb = document.getElementById('sidebar');
    const icon = document.getElementById('collapseIcon');
    if(sb){ sb.classList.add('collapsed'); }
    if(icon){ icon.classList.replace('fa-angles-left','fa-angles-right'); }
  }
})();

/* ── Sidebar toggle ─────────────────────────────────────────────────────── */
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  const open = sb.classList.toggle('open');
  if(ov) ov.classList.toggle('active', open);
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=800&&sb.classList.contains('open')&&
     !sb.contains(e.target)&&!document.getElementById('menuToggle').contains(e.target)){
    sb.classList.remove('open');
    const ov=document.getElementById('sidebarOverlay');
    if(ov) ov.classList.remove('active');
  }
});

/* ── Avatar dropdown ────────────────────────────────────────────────────────── */
function toggleAvatarMenu(e){
  e.stopPropagation();
  const btn=document.getElementById('topbarAvatar');
  const dd=document.getElementById('avatarDropdown');
  const isOpen=dd.classList.contains('open');
  // Close any open dropdowns first
  closeAvatarMenu();
  if(!isOpen){
    btn.classList.add('open');
    dd.classList.add('open');
  }
}
function closeAvatarMenu(){
  document.getElementById('topbarAvatar')?.classList.remove('open');
  document.getElementById('avatarDropdown')?.classList.remove('open');
}
document.addEventListener('click',function(e){
  const wrap=document.querySelector('.topbar-avatar-wrap');
  if(wrap&&!wrap.contains(e.target)) closeAvatarMenu();
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape') closeAvatarMenu();
});

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res=await fetch('../auth/auth_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
    const data=await res.json();
    if(data.redirect) window.location.href=data.redirect;
  } catch{ window.location.href='../login.php'; }
}

/* ── Init full-tab on page load so it's ready ───────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  loadFullSections();
  if(IS_ADMIN) loadEditSections();
});
</script>
</body>
</html>