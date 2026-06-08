<?php
// ═══════════════════════════════════════════════════════════════════
//  EduNexus ERP — examinations.php
//  All queries scoped to: college_id + department_id of logged-in user
//  Features: Schedule (SA-created) · Hall Allocation (SA view) ·
//            Seating (SA view) · Admit Cards (SA view) ·
//            Invigilators (SA view) · Q.Papers · Question Bank · Blueprint
// ═══════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']          ?? 'faculty';
$userId    = (int)($user['id']      ?? 0);
$collegeId = (int)($user['college_id']    ?? 0);
$deptId    = (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']     ?? 'Faculty';

if (!in_array($role, ['faculty', 'college_admin', 'super_admin'])) {
    header('Location: students.php'); exit;
}

// ── Faculty Assignment Scope ───────────────────────────────────────
// If logged-in user is 'faculty', restrict course access to their
// faculty_assignments. super_admin / college_admin see all dept courses.
function getFacultyAssignedCourseIds(PDO $db, int $userId, int $collegeId, int $deptId, string $role): array {
    if ($role === 'faculty') {
        // HOD grants faculty access to specific dept+course via Assign Faculty panel.
        // Only those EXACT assigned courses are visible in Exams and Q.Papers.
        // The double department_id guard prevents cross-dept data bleeding.
        $st = $db->prepare(
            "SELECT DISTINCT fa.course_id
             FROM faculty_assignments fa
             JOIN courses c ON c.id = fa.course_id
             WHERE fa.faculty_id    = ?
               AND fa.college_id    = ?
               AND fa.department_id = ?
               AND c.department_id  = ?
               AND (fa.status = 'active' OR fa.status IS NULL OR fa.status = '')"
        );
        $st->execute([$userId, $collegeId, $deptId, $deptId]);
        $ids = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'course_id');
        return array_values(array_unique(array_map('intval', $ids)));
    }
    return []; // empty = no restriction — caller WHERE already scopes by college+dept
}

/**
 * True when the faculty has at least one active course assignment in this dept.
 * Used to gate the Create Exam / Create Q.Paper action buttons in the UI.
 */
function facultyHasAnyAccess(PDO $db, int $userId, int $collegeId, int $deptId): bool {
    $st = $db->prepare(
        "SELECT 1 FROM faculty_assignments fa
         JOIN courses c ON c.id = fa.course_id
         WHERE fa.faculty_id    = ?
           AND fa.college_id    = ?
           AND fa.department_id = ?
           AND c.department_id  = ?
           AND (fa.status = 'active' OR fa.status IS NULL OR fa.status = '')
         LIMIT 1"
    );
    $st->execute([$userId, $collegeId, $deptId, $deptId]);
    return (bool) $st->fetch();
}

// ── PDO helper (reuses config.php's getDB()) ───────────────────────
function pdo(): PDO { return getDB(); }

// ── Escape helper ──────────────────────────────────────────────────
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));
}

// ── Dept scope guard: col must match logged-in user ────────────────
// All AJAX endpoints enforce college_id and department_id.

// ── Lazy migration: ensure question_bank has table_html column ────
function ensureQuestionMediaColumns(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = array_column($db->query("SHOW COLUMNS FROM question_bank")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('table_html', $cols)) {
        $db->exec("ALTER TABLE question_bank ADD COLUMN `table_html` MEDIUMTEXT DEFAULT NULL COMMENT 'Optional HTML table attached to question'");
    }
    // image_url already exists per schema, but guard anyway
    if (!in_array('image_url', $cols)) {
        $db->exec("ALTER TABLE question_bank ADD COLUMN `image_url` VARCHAR(512) DEFAULT NULL");
    }
}

// ══ AJAX DISPATCHER ═══════════════════════════════════════════════
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    $db     = pdo();

    // ── QUESTION MEDIA UPLOAD ─────────────────────────────────────
    // Handles per-question image uploads from the Create / Edit paper modals.
    // Returns { success, url } — the URL is stored in question.image_url on the JS state.
    if ($action === 'upload_question_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        @ini_set('upload_max_filesize', '10M');
        @ini_set('post_max_size',       '12M');
        if (empty($_FILES['image']['tmp_name'])) {
            echo json_encode(['error' => 'No file received']); exit;
        }
        $fileErr = $_FILES['image']['error'];
        if ($fileErr !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit. Raise upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing.',
                UPLOAD_ERR_CANT_WRITE => 'Server cannot write file.',
            ];
            echo json_encode(['error' => $uploadErrors[$fileErr] ?? "Upload error: $fileErr"]); exit;
        }
        $ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['error' => 'Only JPG, PNG, GIF, WEBP images allowed.']); exit;
        }
        $uploadDir = __DIR__ . '/uploads/qpapers/media/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . '.htaccess',
                "Options -ExecCGI\n<FilesMatch \"\.(php|phtml|pl|py)$\">\n  Deny from all\n</FilesMatch>\n");
        }
        if (!is_writable($uploadDir)) {
            echo json_encode(['error' => 'Upload directory not writable.']); exit;
        }
        $fname = 'qimg_' . $collegeId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fname)) {
            echo json_encode(['error' => 'Failed to save image.']); exit;
        }
        echo json_encode(['success' => true, 'url' => 'uploads/qpapers/media/' . $fname]);
        exit;
    }

    // ── STATS ────────────────────────────────────────────────────
    if ($action === 'stats') {
        $statCourses = ($role==='faculty') ? getFacultyAssignedCourseIds($db,$userId,$collegeId,$deptId,$role) : [];
        $statF=''; $statP=[$collegeId,$deptId];
        if ($role==='faculty') {
            if (empty($statCourses)) { $statF=' AND 1=0'; }
            else { $ph2=implode(',',array_fill(0,count($statCourses),'?')); $statF=" AND course_id IN ($ph2)"; $statP=array_merge($statP,$statCourses); }
        }
        $total = $db->prepare("SELECT COUNT(*) FROM exams WHERE college_id=? AND department_id=? AND status NOT IN ('cancelled')$statF");
        $total->execute($statP);
        
        $ongoing = $db->prepare('SELECT COUNT(*) FROM exam_schedule es JOIN exams e ON e.id=es.exam_id WHERE e.college_id=? AND e.department_id=? AND es.exam_date=CURDATE() AND CURTIME() BETWEEN es.start_time AND es.end_time AND es.status="scheduled"');
        $ongoing->execute([$collegeId,$deptId]);
        
        $conflicts = $db->prepare('SELECT COUNT(*) FROM schedule_conflicts sc JOIN exam_schedule es1 ON es1.id=sc.schedule_id_1 JOIN exams e1 ON e1.id=es1.exam_id WHERE e1.college_id=? AND sc.is_resolved=0');
        $conflicts->execute([$collegeId]);
        
        $admits = $db->prepare('SELECT COUNT(*) FROM admit_cards ac JOIN exam_schedule es ON es.id=ac.schedule_id JOIN exams e ON e.id=es.exam_id WHERE e.college_id=? AND e.department_id=? AND ac.is_valid=1');
        $admits->execute([$collegeId,$deptId]);
        
        $invigs = $db->prepare('SELECT COUNT(DISTINCT ei.faculty_id) FROM exam_invigilators ei JOIN exam_schedule es ON es.id=ei.schedule_id JOIN exams e ON e.id=es.exam_id WHERE e.college_id=? AND e.department_id=? AND es.status="scheduled"');
        $invigs->execute([$collegeId,$deptId]);
        
        $qbank = $db->prepare('SELECT COUNT(*) FROM question_bank WHERE college_id=? AND is_active=1');
        $qbank->execute([$collegeId]);
        
        $papers = $db->prepare('SELECT COUNT(*) FROM question_papers qp JOIN exams e ON e.id=qp.exam_id WHERE e.college_id=? AND e.department_id=?');
        $papers->execute([$collegeId,$deptId]);

        echo json_encode([
            'total'     => (int)$total->fetchColumn(),
            'ongoing'   => (int)$ongoing->fetchColumn(),
            'conflicts' => (int)$conflicts->fetchColumn(),
            'admits'    => (int)$admits->fetchColumn(),
            'invigs'    => (int)$invigs->fetchColumn(),
            'qbank'     => (int)$qbank->fetchColumn(),
            'papers'    => (int)$papers->fetchColumn(),
        ]);
        exit;
    }

    // ── EXAM LIST ────────────────────────────────────────────────
    if ($action === 'exams') {
        $type   = $_GET['type']   ?? '';
        $status = $_GET['status'] ?? '';
        $q      = $_GET['q']      ?? '';
        $params = [$collegeId, $deptId, $deptId]; // extra dept guard
        $where  = 'WHERE e.college_id=? AND e.department_id=? AND c.department_id=?';

        // Restrict faculty to only their assigned courses
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) {
                echo json_encode([]);
                exit;
            }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $where  .= " AND e.course_id IN ($ph)";
            $params  = array_merge($params, $myCourses);
        }

        if ($type   && $type !== 'All Types')   { $where .= ' AND e.type=?';   $params[] = $type; }
        if ($status && $status !== 'All Status') { $where .= ' AND e.status=?'; $params[] = $status; }
        if ($q) { $where .= ' AND (e.title LIKE ? OR c.code LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        
        $st = $db->prepare("SELECT e.id,e.title,e.type,e.status,e.max_marks,e.pass_marks,e.exam_date,e.semester,e.academic_year,
                c.name course_name,c.code course_code,d.name dept_name,u.full_name faculty_name,
                es.id schedule_id,es.exam_date sched_date,es.start_time,es.end_time,es.duration_mins,
                h.name hall_name,h.id hall_id,
                (SELECT COUNT(*) FROM admit_cards ac WHERE ac.schedule_id=es.id AND ac.is_valid=1) admit_count,
                (SELECT COUNT(*) FROM exam_invigilators ei WHERE ei.schedule_id=es.id) invig_count
            FROM exams e
            JOIN courses c ON c.id=e.course_id
            JOIN departments d ON d.id=e.department_id
            LEFT JOIN users u ON u.id=e.created_by
            LEFT JOIN exam_schedule es ON es.exam_id=e.id AND es.status='scheduled'
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            $where ORDER BY es.exam_date ASC, es.start_time ASC");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── EXAM DETAIL ──────────────────────────────────────────────
    if ($action === 'exam_detail') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT e.*,c.name course_name,c.code course_code,d.name dept_name,
                u.full_name faculty_name,es.exam_date sched_date,es.start_time,es.end_time,
                es.duration_mins,es.id schedule_id,es.notes sched_notes,h.name hall_name,h.id hall_id,h.capacity
            FROM exams e
            JOIN courses c ON c.id=e.course_id
            JOIN departments d ON d.id=e.department_id
            LEFT JOIN users u ON u.id=e.created_by
            LEFT JOIN exam_schedule es ON es.exam_id=e.id AND es.status='scheduled'
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            WHERE e.id=? AND e.college_id=? AND e.department_id=? LIMIT 1");
        $st->execute([$id, $collegeId, $deptId]);
        echo json_encode($st->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    // ── CREATE EXAM ──────────────────────────────────────────────
    if ($action === 'create_exam' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $courseId = (int)($d['course_id'] ?? 0);
        // Security: faculty may only create exams for HOD-assigned courses
        if ($role === 'faculty') {
            $allowed = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($allowed) || !in_array($courseId, $allowed)) {
                echo json_encode(['error' => 'You are not assigned to this course. Contact HOD.']); exit;
            }
        }
        $st = $db->prepare("INSERT INTO exams (college_id,department_id,course_id,created_by,title,type,max_marks,pass_marks,exam_date,semester,academic_year,status)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,'upcoming')");
        $st->execute([$collegeId,$deptId,$courseId,$userId,$d['title'],$d['type'],
            $d['max_marks']??100,$d['pass_marks']??40,$d['exam_date'],$d['semester']??1,$d['academic_year']??'2025-26']);
        $eid = $db->lastInsertId();
        if (!empty($d['hall_id']) && !empty($d['start_time']) && !empty($d['end_time'])) {
            $st2 = $db->prepare("INSERT INTO exam_schedule (exam_id,hall_id,exam_date,start_time,end_time,semester,academic_year,created_by) VALUES(?,?,?,?,?,?,?,?)");
            $st2->execute([$eid,$d['hall_id'],$d['exam_date'],$d['start_time'],$d['end_time'],$d['semester']??1,$d['academic_year']??'2025-26',$userId]);
        }
        echo json_encode(['success'=>true,'exam_id'=>$eid]);
        exit;
    }

    // ── UPDATE EXAM ──────────────────────────────────────────────
    if ($action === 'update_exam' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id']??0);
        $st = $db->prepare("UPDATE exams SET title=?,type=?,status=?,max_marks=?,pass_marks=?,remarks=? WHERE id=? AND college_id=? AND department_id=?");
        $st->execute([$d['title'],$d['type'],$d['status'],$d['max_marks'],$d['pass_marks'],$d['remarks']??'',$id,$collegeId,$deptId]);
        if (!empty($d['schedule_id'])) {
            $st2 = $db->prepare("UPDATE exam_schedule SET exam_date=?,start_time=?,end_time=?,hall_id=? WHERE id=? AND exam_id=?");
            $st2->execute([$d['exam_date'],$d['start_time'],$d['end_time'],$d['hall_id'],$d['schedule_id'],$id]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── DELETE EXAM ──────────────────────────────────────────────
    if ($action === 'delete_exam' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("DELETE FROM exams WHERE id=? AND college_id=? AND department_id=?");
        $st->execute([(int)$d['id'],$collegeId,$deptId]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── SCHEDULE / CONFLICTS ─────────────────────────────────────
    if ($action === 'schedule') {
        $from = $_GET['from'] ?? date('Y-m-d');
        $to   = $_GET['to']   ?? date('Y-m-d', strtotime('+13 days'));
        $st = $db->prepare("SELECT es.id,es.exam_date,es.start_time,es.end_time,es.duration_mins,es.status,
                e.title,e.type,e.status exam_status,c.code course_code,h.name hall_name,d.code dept_code
            FROM exam_schedule es
            JOIN exams e ON e.id=es.exam_id
            JOIN courses c ON c.id=e.course_id
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            LEFT JOIN departments d ON d.id=e.department_id
            WHERE e.college_id=? AND e.department_id=? AND es.exam_date BETWEEN ? AND ?
            ORDER BY es.exam_date,es.start_time");
        $st->execute([$collegeId,$deptId,$from,$to]);
        $slots = $st->fetchAll(PDO::FETCH_ASSOC);
        
        $cs = $db->prepare("SELECT sc.schedule_id_1,sc.schedule_id_2 FROM schedule_conflicts sc
            JOIN exam_schedule es1 ON es1.id=sc.schedule_id_1
            JOIN exams e1 ON e1.id=es1.exam_id
            WHERE e1.college_id=? AND sc.is_resolved=0");
        $cs->execute([$collegeId]);
        $cids = [];
        foreach($cs->fetchAll(PDO::FETCH_ASSOC) as $c) { $cids[] = (int)$c['schedule_id_1']; $cids[] = (int)$c['schedule_id_2']; }
        echo json_encode(['slots'=>$slots,'conflict_ids'=>array_values(array_unique($cids))]);
        exit;
    }

    if ($action === 'conflicts') {
        $st = $db->prepare("SELECT sc.id,sc.conflict_type,sc.description,sc.is_resolved,sc.created_at,
                es1.exam_date date1,es1.start_time time1,h1.name hall1,e1.title exam1,
                es2.exam_date date2,es2.start_time time2,h2.name hall2,e2.title exam2
            FROM schedule_conflicts sc
            JOIN exam_schedule es1 ON es1.id=sc.schedule_id_1
            JOIN exam_schedule es2 ON es2.id=sc.schedule_id_2
            JOIN exams e1 ON e1.id=es1.exam_id
            JOIN exams e2 ON e2.id=es2.exam_id
            LEFT JOIN exam_halls h1 ON h1.id=es1.hall_id
            LEFT JOIN exam_halls h2 ON h2.id=es2.hall_id
            WHERE e1.college_id=? AND sc.is_resolved=0
            ORDER BY sc.created_at DESC");
        $st->execute([$collegeId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'resolve_conflict' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("UPDATE schedule_conflicts SET is_resolved=1,resolved_by=?,resolved_at=NOW() WHERE id=?");
        $st->execute([$userId,(int)$d['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'add_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $check = $db->prepare("SELECT id FROM exams WHERE id=? AND college_id=? AND department_id=?");
        $check->execute([(int)$d['exam_id'],$collegeId,$deptId]);
        if (!$check->fetch()) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $st = $db->prepare("INSERT INTO exam_schedule (exam_id,hall_id,exam_date,start_time,end_time,semester,academic_year,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)");
        $st->execute([$d['exam_id'],$d['hall_id'],$d['exam_date'],$d['start_time'],$d['end_time'],$d['semester']??1,$d['academic_year']??'2025-26',$d['notes']??'',$userId]);
        echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);
        exit;
    }

    // ── HALLS ────────────────────────────────────────────────────
    if ($action === 'halls') {
        $st = $db->prepare("SELECT h.id,h.name,h.code,h.capacity,h.building,h.floor,h.has_projector,h.has_ac,h.status,
                (SELECT COUNT(*) FROM exam_schedule es WHERE es.hall_id=h.id AND es.exam_date>=CURDATE() AND es.status='scheduled') bookings,
                (SELECT GROUP_CONCAT(CONCAT(e.title,'|',DATE_FORMAT(es.exam_date,'%d %b')) SEPARATOR ';;')
                 FROM exam_schedule es JOIN exams e ON e.id=es.exam_id WHERE es.hall_id=h.id AND es.status='scheduled' AND es.exam_date>=CURDATE()) booking_list,
                (SELECT COUNT(*) FROM schedule_conflicts sc WHERE (sc.schedule_id_1 IN (SELECT id FROM exam_schedule WHERE hall_id=h.id) OR sc.schedule_id_2 IN (SELECT id FROM exam_schedule WHERE hall_id=h.id)) AND sc.is_resolved=0) conflicts
            FROM exam_halls h
            WHERE h.college_id=? AND h.status='active' ORDER BY h.name");
        $st->execute([$collegeId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'halls_list') {
        $st = $db->prepare("SELECT id,name,capacity FROM exam_halls WHERE college_id=? AND status='active' ORDER BY name");
        $st->execute([$collegeId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'hall_utilization') {
        $st = $db->prepare("SELECT h.name,h.capacity,COUNT(sa.id) occupied
            FROM exam_halls h
            LEFT JOIN exam_schedule es ON es.hall_id=h.id AND es.status='scheduled'
            LEFT JOIN seating_arrangement sa ON sa.hall_id=h.id AND sa.schedule_id=es.id
            WHERE h.college_id=? GROUP BY h.id ORDER BY h.name");
        $st->execute([$collegeId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── SEATING ──────────────────────────────────────────────────
    if ($action === 'seating') {
        $hall_id = (int)($_GET['hall_id'] ?? 0);
        $sched_id = (int)($_GET['schedule_id'] ?? 0);
        $where = $hall_id ? "sa.hall_id=$hall_id" : "1=1";
        if ($sched_id) $where .= " AND sa.schedule_id=$sched_id";
        $st = $db->prepare("SELECT sa.seat_number,sa.row_no,sa.col_no,sa.is_present,
                u.full_name,u.roll_number,d.code dept_code,d.name dept_name,
                es.exam_date,e.title exam_title,h.name hall_name,h.capacity,h.id hall_id,
                es.id schedule_id
            FROM seating_arrangement sa
            JOIN users u ON u.id=sa.student_id
            JOIN exam_schedule es ON es.id=sa.schedule_id
            JOIN exams e ON e.id=es.exam_id AND e.college_id=?
            JOIN exam_halls h ON h.id=sa.hall_id
            LEFT JOIN departments d ON d.id=u.department_id
            WHERE $where ORDER BY sa.row_no,sa.col_no");
        $st->execute([$collegeId]);
        $seats = $st->fetchAll(PDO::FETCH_ASSOC);
        $hi = $hall_id ? $db->prepare("SELECT * FROM exam_halls WHERE id=? AND college_id=?") : null;
        $hall = null;
        if ($hi) { $hi->execute([$hall_id,$collegeId]); $hall = $hi->fetch(PDO::FETCH_ASSOC); }
        echo json_encode(['seats'=>$seats,'hall'=>$hall]);
        exit;
    }

    if ($action === 'assign_seat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $check = $db->prepare("SELECT e.id FROM exam_schedule es JOIN exams e ON e.id=es.exam_id WHERE es.id=? AND e.college_id=? AND e.department_id=?");
        $check->execute([(int)$d['schedule_id'],$collegeId,$deptId]);
        if (!$check->fetch()) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $st = $db->prepare("INSERT INTO seating_arrangement (schedule_id,student_id,hall_id,seat_number,row_no,col_no)
            VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hall_id=VALUES(hall_id),seat_number=VALUES(seat_number)");
        $st->execute([$d['schedule_id'],$d['student_id'],$d['hall_id'],$d['seat_number'],$d['row_no']??1,$d['col_no']??1]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── ADMIT CARDS ──────────────────────────────────────────────
    if ($action === 'admit_cards') {
        $q = $_GET['q'] ?? '';
        $params = [$collegeId,$deptId];
        $qw = '';
        if ($q) { $qw = 'AND (u.full_name LIKE ? OR u.roll_number LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $st = $db->prepare("SELECT ac.id,ac.admit_card_no,ac.is_valid,ac.is_downloaded,ac.issued_at,
                u.id student_id,u.full_name student_name,u.roll_number,u.email,
                col.name college_name,e.title exam_title,e.type exam_type,
                es.exam_date,es.start_time,es.end_time,h.name hall_name,sa.seat_number
            FROM admit_cards ac
            JOIN users u ON u.id=ac.student_id
            JOIN exam_schedule es ON es.id=ac.schedule_id
            JOIN exams e ON e.id=es.exam_id
            JOIN colleges col ON col.id=e.college_id
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            LEFT JOIN seating_arrangement sa ON sa.schedule_id=es.id AND sa.student_id=u.id
            WHERE e.college_id=? AND e.department_id=? $qw
            ORDER BY u.roll_number LIMIT 100");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'gen_admit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $no = 'AC-'.date('Y').'-'.str_pad(mt_rand(1,99999),5,'0',STR_PAD_LEFT);
        $st = $db->prepare("INSERT INTO admit_cards (student_id,schedule_id,admit_card_no,is_valid,created_by)
            VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE is_valid=1");
        $st->execute([(int)$d['student_id'],(int)$d['schedule_id'],$no,$userId]);
        echo json_encode(['success'=>true,'admit_card_no'=>$no]);
        exit;
    }

    if ($action === 'revoke_admit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("UPDATE admit_cards SET is_valid=0,revoked_reason=? WHERE id=?");
        $st->execute([$d['reason']??'Revoked',(int)$d['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'students_list') {
        $st = $db->prepare("SELECT id,full_name,roll_number FROM users WHERE role='student' AND status='active' AND college_id=? AND department_id=? ORDER BY roll_number LIMIT 300");
        $st->execute([$collegeId,$deptId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── INVIGILATORS ─────────────────────────────────────────────
    if ($action === 'invigilators') {
        $st = $db->prepare("SELECT ei.id,ei.duty_type,ei.is_confirmed,
                u.full_name faculty_name,u.email faculty_email,d.name dept_name,
                e.title exam_title,e.type exam_type,es.exam_date,es.start_time,
                h.name hall_name,es.id schedule_id
            FROM exam_invigilators ei
            JOIN users u ON u.id=ei.faculty_id
            JOIN exam_schedule es ON es.id=ei.schedule_id
            JOIN exams e ON e.id=es.exam_id
            LEFT JOIN departments d ON d.id=u.department_id
            LEFT JOIN exam_halls h ON h.id=ei.hall_id
            WHERE e.college_id=? AND e.department_id=?
            ORDER BY es.exam_date,es.start_time");
        $st->execute([$collegeId,$deptId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'assign_invig' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $check = $db->prepare("SELECT id FROM exams e JOIN exam_schedule es ON es.exam_id=e.id WHERE es.id=? AND e.college_id=? AND e.department_id=?");
        $check->execute([(int)$d['schedule_id'],$collegeId,$deptId]);
        if (!$check->fetch()) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $st = $db->prepare("INSERT INTO exam_invigilators (schedule_id,faculty_id,hall_id,duty_type,is_confirmed,created_by)
            VALUES(?,?,?,?,0,?) ON DUPLICATE KEY UPDATE duty_type=VALUES(duty_type)");
        $st->execute([(int)$d['schedule_id'],(int)$d['faculty_id'],(int)($d['hall_id']??0),$d['duty_type']??'assistant',$userId]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'confirm_invig' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("UPDATE exam_invigilators SET is_confirmed=1 WHERE id=?");
        $st->execute([(int)$d['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'remove_invig' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("DELETE FROM exam_invigilators WHERE id=?");
        $st->execute([(int)$d['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'faculty_list') {
        $st = $db->prepare("SELECT id,full_name,designation FROM users WHERE role IN ('faculty','college_admin') AND college_id=? AND status='active' ORDER BY full_name");
        $st->execute([$collegeId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── QUESTION PAPERS ──────────────────────────────────────────
    if ($action === 'qpapers') {
        $qpParams = [$collegeId, $deptId, $deptId]; // extra $deptId for c.department_id guard
        $qpWhere  = 'WHERE qp.college_id=? AND e.department_id=? AND c.department_id=?';

        // Faculty: restrict to only their assigned courses
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) {
                echo json_encode([]);
                exit;
            }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $qpWhere .= " AND e.course_id IN ($ph)";
            $qpParams = array_merge($qpParams, $myCourses);
        }

        $st = $db->prepare("SELECT qp.id,qp.title,qp.version,qp.total_marks,qp.total_questions,
                qp.is_confidential,qp.released_at,qp.status,qp.created_at,qp.file_path,
                e.title exam_title,e.type exam_type,c.code course_code,c.name course_name,
                u.full_name created_by_name
            FROM question_papers qp
            JOIN exams e ON e.id=qp.exam_id
            JOIN courses c ON c.id=e.course_id
            LEFT JOIN users u ON u.id=qp.created_by
            $qpWhere
            ORDER BY qp.created_at DESC");
        $st->execute($qpParams);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'create_qpaper' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Raise limits so large PDFs are accepted (XAMPP defaults to 2M)
        @ini_set('upload_max_filesize', '20M');
        @ini_set('post_max_size',       '22M');

        // Supports both JSON body (no file) and multipart FormData (with file)
        $isMultipart = strpos($_SERVER['CONTENT_TYPE']??'','multipart') !== false;
        $d = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);
        $check = $db->prepare("SELECT id,course_id FROM exams WHERE id=? AND college_id=? AND department_id=?");
        $check->execute([(int)$d['exam_id'],$collegeId,$deptId]);
        $examRow = $check->fetch(PDO::FETCH_ASSOC);
        if (!$examRow) { echo json_encode(['error'=>'Unauthorized']); exit; }

        // Faculty: must be assigned to this exam's course
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (!empty($myCourses) && !in_array((int)$examRow['course_id'], array_map('intval', $myCourses))) {
                echo json_encode(['error' => 'You are not assigned to this course.']); exit;
            }
        }

        $filePath = null;
        if ($isMultipart && isset($_FILES['paper_file'])) {
            $fileErr = $_FILES['paper_file']['error'];
            if ($fileErr !== UPLOAD_ERR_NO_FILE) {
                // Surface PHP upload errors instead of silently dropping the file
                if ($fileErr !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit. Open php.ini and raise upload_max_filesize to 20M.',
                        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
                        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing. Contact your admin.',
                        UPLOAD_ERR_CANT_WRITE => 'Server cannot write the file to disk. Check folder permissions.',
                        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
                    ];
                    $msg = $uploadErrors[$fileErr] ?? "PHP upload error code: $fileErr";
                    echo json_encode(['error' => $msg]); exit;
                }
                // Validate extension
                $ext     = strtolower(pathinfo($_FILES['paper_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf','doc','docx','jpg','jpeg','png'];
                if (!in_array($ext, $allowed)) {
                    echo json_encode(['error' => 'File type not allowed. Use PDF, DOCX, JPG, or PNG.']); exit;
                }
                // Create upload directory if missing, with .htaccess to block PHP execution
                $uploadDir = __DIR__ . '/uploads/qpapers/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        echo json_encode(['error' => 'Cannot create upload directory. Check write permissions on the pages/ folder.']); exit;
                    }
                    file_put_contents($uploadDir . '.htaccess',
                        "Options -ExecCGI\nAddHandler cgi-script .php .pl .py\n" .
                        "<FilesMatch \"\.(php|phtml|pl|py)$\">\n  Deny from all\n</FilesMatch>\n");
                }
                if (!is_writable($uploadDir)) {
                    echo json_encode(['error' => 'Upload directory is not writable. Run: chmod 755 pages/uploads/qpapers/']); exit;
                }
                $fname = 'qp_' . $collegeId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['paper_file']['tmp_name'], $uploadDir . $fname)) {
                    $filePath = 'uploads/qpapers/' . $fname;
                } else {
                    echo json_encode(['error' => 'move_uploaded_file() failed. Check that pages/uploads/qpapers/ exists and is writable.']); exit;
                }
            }
        }
        // Check unique constraint: one version (Set A/B/C) per exam
        $dupChk = $db->prepare("SELECT id, title FROM question_papers WHERE exam_id=? AND college_id=? AND version=?");
        $dupChk->execute([(int)$d['exam_id'], $collegeId, $d['version'] ?? 'A']);
        if ($dup = $dupChk->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['error' => 'A question paper with Set ' . ($d['version'] ?? 'A') . ' already exists for this exam ("' . $dup['title'] . '"). Choose a different Set Version.']);
            exit;
        }

        ensureQuestionMediaColumns($db);

        try {
            $db->beginTransaction();

            // 1. Insert paper header
            $st = $db->prepare("INSERT INTO question_papers
                (exam_id, college_id, title, version, total_marks, total_questions, instructions, file_path, is_confidential, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'draft')");
            $st->execute([
                (int)$d['exam_id'],
                $collegeId,
                trim($d['title']),
                $d['version']      ?? 'A',
                (float)($d['total_marks']   ?? 100),
                (int)($d['total_questions'] ?? 0),
                trim($d['instructions']     ?? ''),
                $filePath,
                $userId
            ]);
            $paperId = (int)$db->lastInsertId();

            // 2. Insert blueprint sections + questions if provided
            $sections   = $d['sections'] ?? [];
            $displayOrder = 0;

            // Get course_id for this exam (needed for question_bank)
            $cSt = $db->prepare("SELECT course_id FROM exams WHERE id=?");
            $cSt->execute([(int)$d['exam_id']]);
            $courseId = (int)($cSt->fetchColumn() ?? 0);

            foreach ($sections as $sec) {
                $displayOrder++;
                $secName    = trim($sec['name']    ?? 'Section');
                $qType      = $sec['type']          ?? 'short';
                $attend     = max(1, (int)($sec['attend']     ?? 1));
                $marksEach  = max(0, (float)($sec['marksEach'] ?? 1));
                $numQs      = count($sec['questions'] ?? []);
                $totalMrks  = $attend * $marksEach;

                // Insert blueprint row
                $bpSt = $db->prepare("INSERT INTO exam_blueprint
                    (exam_id, college_id, section_name, question_type, num_questions, marks_per_q,
                     is_compulsory, display_order, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
                $bpSt->execute([
                    (int)$d['exam_id'], $collegeId, $secName,
                    $qType, $numQs, $marksEach, $displayOrder, $userId
                ]);
                $blueprintId = (int)$db->lastInsertId();

                // Insert questions
                $qOrder = 0;
                foreach (($sec['questions'] ?? []) as $q) {
                    $qText = trim($q['text'] ?? '');
                    if (!$qText && empty($q['image_url']) && empty($q['table_html'])) continue;

                    // Add to question_bank
                    $qbSt = $db->prepare("INSERT INTO question_bank
                        (college_id, course_id, created_by, question_text, question_type,
                         difficulty, marks, image_url, table_html, is_active)
                        VALUES (?, ?, ?, ?, ?, 'medium', ?, ?, ?, 1)");
                    $qbSt->execute([
                        $collegeId, $courseId, $userId,
                        $qText, $qType, $marksEach,
                        $q['image_url'] ?? null,
                        $q['table_html'] ?? null
                    ]);
                    $qbId = (int)$db->lastInsertId();

                    // MCQ options
                    if ($qType === 'mcq' && !empty($q['opts'])) {
                        $optSt = $db->prepare("INSERT INTO question_bank_options
                            (question_id, option_text, is_correct, display_order) VALUES (?,?,0,?)");
                        foreach (array_values($q['opts']) as $oi => $optText) {
                            $optText = trim($optText);
                            if ($optText) {
                                $optSt->execute([$qbId, $optText, $oi + 1]);
                            }
                        }
                    }

                    // Link to paper
                    $qOrder++;
                    $pqSt = $db->prepare("INSERT INTO question_paper_questions
                        (paper_id, question_id, blueprint_id, display_order, marks_override)
                        VALUES (?, ?, ?, ?, ?)");
                    $pqSt->execute([$paperId, $qbId, $blueprintId, $qOrder, $marksEach]);
                }
            }

            $db->commit();
            echo json_encode([
                'success'    => true,
                'id'         => $paperId,
                'file_saved' => !is_null($filePath),
            ]);
        } catch (PDOException $e) {
            $db->rollBack();
            $msg = $e->getMessage();
            if (strpos($msg, 'uq_paper_version') !== false || strpos($msg, 'Duplicate entry') !== false) {
                $msg = 'A paper with Set ' . ($d['version'] ?? 'A') . ' already exists for this exam. Choose Set B or Set C.';
            }
            echo json_encode(['error' => 'Database error: ' . $msg]);
        }
        exit;
    }

    if ($action === 'upload_paper_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $paperId = (int)($_POST['paper_id'] ?? 0);
        if (!$paperId || empty($_FILES['paper_file']['tmp_name'])) {
            echo json_encode(['error'=>'Missing file or paper ID']); exit;
        }
        // Verify paper belongs to this college
        $chk = $db->prepare("SELECT id FROM question_papers WHERE id=? AND college_id=?");
        $chk->execute([$paperId, $collegeId]);
        if (!$chk->fetch()) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $uploadDir = __DIR__ . '/uploads/qpapers/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext  = strtolower(pathinfo($_FILES['paper_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) { echo json_encode(['error'=>'File type not allowed']); exit; }
        $fname = 'qp_' . $collegeId . '_' . $paperId . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['paper_file']['tmp_name'], $uploadDir . $fname)) {
            echo json_encode(['error'=>'File save failed']); exit;
        }
        $filePath = 'uploads/qpapers/' . $fname;
        $db->prepare("UPDATE question_papers SET file_path=? WHERE id=? AND college_id=?")
           ->execute([$filePath, $paperId, $collegeId]);
        echo json_encode(['success'=>true,'file_path'=>$filePath]);
        exit;
    }

    if ($action === 'update_qpaper_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("UPDATE question_papers SET status=? WHERE id=? AND college_id=?");
        $st->execute([$d['status'],(int)$d['id'],$collegeId]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── GET QUESTION PAPER DETAIL (for edit modal) ────────────────────────────
    if ($action === 'get_qpaper_detail') {
        $id = (int)($_GET['id'] ?? 0);

        // Build scope: faculty can only access papers for their assigned courses
        $scopeWhere = 'AND qp.college_id=?';
        $scopeParams = [$id, $collegeId];
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) { echo json_encode(['error'=>'Access denied']); exit; }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $scopeWhere .= " AND e.course_id IN ($ph)";
            $scopeParams = array_merge($scopeParams, $myCourses);
        }

        // Paper header
        $pSt = $db->prepare("SELECT qp.id,qp.title,qp.version,qp.total_marks,qp.instructions,qp.status,qp.exam_id
            FROM question_papers qp JOIN exams e ON e.id=qp.exam_id
            WHERE qp.id=? $scopeWhere");
        $pSt->execute($scopeParams);
        $paper = $pSt->fetch(PDO::FETCH_ASSOC);
        if (!$paper) { echo json_encode(['error'=>'Not found']); exit; }

        // Blueprint sections for this paper's exam
        $bpSt = $db->prepare("SELECT id,section_name,question_type,num_questions,marks_per_q,display_order
            FROM exam_blueprint WHERE exam_id=? AND college_id=? ORDER BY display_order ASC");
        $bpSt->execute([(int)$paper['exam_id'],$collegeId]);
        $blueprints = $bpSt->fetchAll(PDO::FETCH_ASSOC);

        // Questions linked to this paper, grouped by blueprint_id
        $qSt = $db->prepare("SELECT qpq.id AS pq_id, qpq.blueprint_id, qpq.display_order, qpq.marks_override,
                qb.id AS qb_id, qb.question_text, qb.question_type, qb.image_url, qb.table_html
            FROM question_paper_questions qpq
            JOIN question_bank qb ON qb.id=qpq.question_id
            WHERE qpq.paper_id=? ORDER BY qpq.display_order ASC");
        $qSt->execute([$id]);
        $allQs = $qSt->fetchAll(PDO::FETCH_ASSOC);

        // MCQ options
        $optMap = [];
        $qbIds = array_unique(array_column($allQs,'qb_id'));
        if ($qbIds) {
            $in = implode(',', array_map('intval',$qbIds));
            foreach ($db->query("SELECT question_id,option_text,display_order FROM question_bank_options WHERE question_id IN($in) ORDER BY question_id,display_order")->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $optMap[$o['question_id']][] = $o['option_text'];
            }
        }

        // Build sections array
        $sections = [];
        foreach ($blueprints as $bp) {
            $qs = array_values(array_filter($allQs, fn($q) => (int)$q['blueprint_id'] === (int)$bp['id']));
            $sections[] = [
                'blueprint_id'  => (int)$bp['id'],
                'name'          => $bp['section_name'],
                'type'          => $bp['question_type'],
                'attend'        => (int)$bp['num_questions'],
                'marksEach'     => (float)$bp['marks_per_q'],
                'questions'     => array_map(fn($q) => [
                    'pq_id'          => (int)$q['pq_id'],
                    'qb_id'          => (int)$q['qb_id'],
                    'text'           => $q['question_text'],
                    'marks_override' => (float)$q['marks_override'],
                    'opts'           => $optMap[(int)$q['qb_id']] ?? [],
                    'image_url'      => $q['image_url']   ?? null,
                    'table_html'     => $q['table_html']  ?? null,
                ], $qs),
            ];
        }
        // Questions with no blueprint section
        $orphans = array_values(array_filter($allQs, fn($q)=>!(int)$q['blueprint_id']));
        if ($orphans) {
            $sections[] = [
                'blueprint_id' => 0,
                'name'  => 'General',
                'type'  => $orphans[0]['question_type'] ?? 'short',
                'attend'=> count($orphans),
                'marksEach' => (float)($orphans[0]['marks_override']??1),
                'questions' => array_map(fn($q)=>[
                    'pq_id'=>(int)$q['pq_id'],'qb_id'=>(int)$q['qb_id'],
                    'text'=>$q['question_text'],'marks_override'=>(float)$q['marks_override'],
                    'opts'=>$optMap[(int)$q['qb_id']]??[],
                    'image_url'=>$q['image_url']??null,'table_html'=>$q['table_html']??null,
                ], $orphans),
            ];
        }
        $paper['sections'] = $sections;
        echo json_encode($paper);
        exit;
    }

    // ── SAVE QUESTION PAPER EDITS (title, version, marks, sections, questions) ──
    if ($action === 'edit_qpaper' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $chk = $db->prepare("SELECT qp.id,qp.status,qp.exam_id,e.course_id FROM question_papers qp JOIN exams e ON e.id=qp.exam_id WHERE qp.id=? AND qp.college_id=?");
        $chk->execute([$id,$collegeId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error'=>'Not found or access denied.']); exit; }
        // Faculty: ensure they own this course
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (!empty($myCourses) && !in_array((int)$row['course_id'], array_map('intval', $myCourses))) {
                echo json_encode(['error'=>'Access denied to this paper.']); exit;
            }
        }
        if (!in_array($row['status'],['draft','pending_approval'])) {
            echo json_encode(['error'=>'Only Draft or Pending Approval papers can be edited.']); exit;
        }

        ensureQuestionMediaColumns($db);

        try {
            $db->beginTransaction();

            // 1. Update paper header
            $db->prepare("UPDATE question_papers SET title=?,version=?,total_marks=?,instructions=?,updated_at=NOW() WHERE id=? AND college_id=?")
               ->execute([trim($d['title']??''), strtoupper(trim($d['version']??'A')), (float)($d['total_marks']??100), trim($d['instructions']??''), $id, $collegeId]);

            // Get course_id for question_bank
            $courseId = (int)$db->query("SELECT course_id FROM exams WHERE id=".(int)$row['exam_id'])->fetchColumn();

            // 2. Rebuild sections and questions
            foreach ($d['sections'] ?? [] as $sec) {
                $bpId    = (int)($sec['blueprint_id'] ?? 0);
                $secName = trim($sec['name'] ?? 'Section');
                $qType   = $sec['type'] ?? 'short';
                $attend  = max(1,(int)($sec['attend']??1));
                $mEach   = max(0,(float)($sec['marksEach']??1));
                $numQs   = count($sec['questions']??[]);

                if ($bpId) {
                    // Update existing blueprint row
                    $db->prepare("UPDATE exam_blueprint SET section_name=?,question_type=?,num_questions=?,marks_per_q=? WHERE id=? AND college_id=?")
                       ->execute([$secName,$qType,$numQs,$mEach,$bpId,$collegeId]);
                } else {
                    // New section — insert blueprint
                    $db->prepare("INSERT INTO exam_blueprint (exam_id,college_id,section_name,question_type,num_questions,marks_per_q,is_compulsory,display_order,created_by) VALUES(?,?,?,?,?,?,1,99,?)")
                       ->execute([(int)$row['exam_id'],$collegeId,$secName,$qType,$numQs,$mEach,$userId]);
                    $bpId = (int)$db->lastInsertId();
                }

                // Process questions
                $qOrder = 0;
                foreach ($sec['questions'] ?? [] as $q) {
                    $qOrder++;
                    $qText  = trim($q['text'] ?? '');
                    $pqId   = (int)($q['pq_id'] ?? 0);
                    $qbId   = (int)($q['qb_id'] ?? 0);
                    $mOver  = (float)($q['marks_override'] ?? $mEach);

                    if ($qbId) {
                        // Update existing question_bank entry
                        $db->prepare("UPDATE question_bank SET question_text=?,question_type=?,marks=?,image_url=?,table_html=? WHERE id=? AND college_id=?")
                           ->execute([$qText,$qType,$mOver,$q['image_url']??null,$q['table_html']??null,$qbId,$collegeId]);
                        if ($pqId) {
                            $db->prepare("UPDATE question_paper_questions SET marks_override=?,display_order=?,blueprint_id=? WHERE id=? AND paper_id=?")
                               ->execute([$mOver,$qOrder,$bpId,$pqId,$id]);
                        }
                    } elseif ($qText || !empty($q['image_url']) || !empty($q['table_html'])) {
                        // New question — insert into question_bank + link to paper
                        $db->prepare("INSERT INTO question_bank (college_id,course_id,created_by,question_text,question_type,difficulty,marks,image_url,table_html,is_active) VALUES(?,?,?,?,?,'medium',?,?,?,1)")
                           ->execute([$collegeId,$courseId,$userId,$qText,$qType,$mOver,$q['image_url']??null,$q['table_html']??null]);
                        $newQbId = (int)$db->lastInsertId();
                        $db->prepare("INSERT INTO question_paper_questions (paper_id,question_id,blueprint_id,display_order,marks_override) VALUES(?,?,?,?,?)")
                           ->execute([$id,$newQbId,$bpId,$qOrder,$mOver]);
                    }
                }

                // Remove questions deleted by user (pq_ids present in DB but not submitted)
                $submittedPqIds = array_filter(array_map(fn($q)=>(int)($q['pq_id']??0), $sec['questions']??[]), fn($x)=>$x>0);
                if ($bpId) {
                    $existingPqs = $db->prepare("SELECT id FROM question_paper_questions WHERE paper_id=? AND blueprint_id=?");
                    $existingPqs->execute([$id,$bpId]);
                    foreach ($existingPqs->fetchAll(PDO::FETCH_COLUMN) as $existId) {
                        if (!in_array((int)$existId, $submittedPqIds)) {
                            $db->prepare("DELETE FROM question_paper_questions WHERE id=? AND paper_id=?")->execute([$existId,$id]);
                        }
                    }
                }
            }

            // ── DELETE sections removed by the user ──────────────────────────
            // Collect blueprint_ids that were actually submitted (non-zero = existing rows)
            $submittedBpIds = array_filter(
                array_map(fn($s) => (int)($s['blueprint_id'] ?? 0), $d['sections'] ?? []),
                fn($x) => $x > 0
            );
            // Find every blueprint row currently in DB for this exam
            $allBpSt = $db->prepare(
                "SELECT id FROM exam_blueprint WHERE exam_id=? AND college_id=?"
            );
            $allBpSt->execute([(int)$row['exam_id'], $collegeId]);
            foreach ($allBpSt->fetchAll(PDO::FETCH_COLUMN) as $existBpId) {
                if (!in_array((int)$existBpId, $submittedBpIds)) {
                    // Remove all paper-question links that belonged to this section
                    $db->prepare(
                        "DELETE FROM question_paper_questions WHERE paper_id=? AND blueprint_id=?"
                    )->execute([$id, $existBpId]);
                    // Remove the blueprint row itself
                    $db->prepare(
                        "DELETE FROM exam_blueprint WHERE id=? AND college_id=?"
                    )->execute([$existBpId, $collegeId]);
                }
            }

            // Recalculate total_questions on paper
            $totalQs = (int)$db->query("SELECT COUNT(*) FROM question_paper_questions WHERE paper_id=$id")->fetchColumn();
            $db->prepare("UPDATE question_papers SET total_questions=? WHERE id=?")->execute([$totalQs,$id]);

            $db->commit();
            echo json_encode(['success'=>true,'msg'=>'Question paper saved successfully.']);
        } catch (PDOException $e) {
            $db->rollBack();
            echo json_encode(['error'=>'Database error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── DELETE QUESTION PAPER ────────────────────────────────────────────────
    if ($action === 'delete_qpaper' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $chk = $db->prepare("SELECT qp.id,qp.status,qp.file_path,e.course_id FROM question_papers qp JOIN exams e ON e.id=qp.exam_id WHERE qp.id=? AND qp.college_id=?");
        $chk->execute([$id,$collegeId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error'=>'Not found.']); exit; }
        // Faculty: must own the course
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (!empty($myCourses) && !in_array((int)$row['course_id'], array_map('intval', $myCourses))) {
                echo json_encode(['error'=>'Access denied.']); exit;
            }
        }
        if (!in_array($row['status'],['draft','pending_approval'])) {
            echo json_encode(['error'=>'Only Draft or Pending Approval papers can be deleted.']); exit;
        }
        $db->prepare("DELETE qpq FROM question_paper_questions qpq WHERE qpq.paper_id=?")->execute([$id]);
        $db->prepare("DELETE FROM question_papers WHERE id=? AND college_id=?")->execute([$id,$collegeId]);
        if (!empty($row['file_path'])) { $fp=__DIR__.'/'.$row['file_path']; if(file_exists($fp)) @unlink($fp); }
        echo json_encode(['success'=>true,'msg'=>'Paper deleted.']);
        exit;
    }

    // ── QUESTION BANK ────────────────────────────────────────────
    // ── PRINT / VIEW QUESTION PAPER ──────────────────────────────────────
    if ($action === 'print_qpaper') {
        $paperId = (int)($_GET['id'] ?? 0);
        if (!$paperId) {
            echo '<p style="color:red;padding:30px;font-family:sans-serif">Error: No paper ID provided.</p>';
            exit;
        }

        // 1. Paper + exam + college info
        $pst = $db->prepare("
            SELECT
                qp.id, qp.title, qp.version, qp.total_marks, qp.total_questions,
                qp.instructions, qp.file_path, qp.status, qp.is_confidential, qp.created_at,
                e.id AS exam_id,
                e.title AS exam_title, e.type AS exam_type, e.exam_date,
                e.max_marks, e.pass_marks, e.semester, e.academic_year,
                c.name AS course_name, c.code AS course_code,
                d.name AS dept_name, d.code AS dept_code,
                col.name AS college_name, col.code AS college_code,
                col.email AS college_email, col.phone AS college_phone,
                u.full_name AS created_by_name,
                es.duration_mins, es.start_time, es.end_time
            FROM question_papers qp
            JOIN exams       e   ON e.id   = qp.exam_id
            JOIN courses     c   ON c.id   = e.course_id
            JOIN departments d   ON d.id   = e.department_id
            JOIN colleges    col ON col.id = qp.college_id
            LEFT JOIN users  u   ON u.id   = qp.created_by
            LEFT JOIN exam_schedule es ON es.exam_id = e.id AND es.status != 'cancelled'
            WHERE qp.id = ? AND qp.college_id = ?
            ORDER BY es.exam_date DESC LIMIT 1
        ");
        $pst->execute([$paperId, $collegeId]);
        $p = $pst->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            echo '<p style="color:red;padding:30px;font-family:sans-serif">Error: Paper not found or access denied (ID=' . $paperId . ').</p>';
            exit;
        }

        // 2. Blueprint sections for this exam
        $bpSt = $db->prepare("
            SELECT id, section_name, question_type, num_questions,
                   marks_per_q, total_marks, is_compulsory, display_order, unit_no, topic
            FROM exam_blueprint
            WHERE exam_id = ? AND college_id = ?
            ORDER BY display_order ASC
        ");
        $bpSt->execute([(int)$p['exam_id'], $collegeId]);
        $sections = $bpSt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Questions assigned to this paper
        $qSt = $db->prepare("
            SELECT
                qpq.display_order, qpq.marks_override, qpq.blueprint_id,
                qb.id AS qb_id, qb.question_text, qb.question_type,
                qb.difficulty, qb.marks, qb.topic, qb.unit_no, qb.bloom_level,
                qb.image_url, qb.table_html
            FROM question_paper_questions qpq
            JOIN question_bank qb ON qb.id = qpq.question_id
            WHERE qpq.paper_id = ?
            ORDER BY qpq.display_order ASC
        ");
        $qSt->execute([$paperId]);
        $questions = $qSt->fetchAll(PDO::FETCH_ASSOC);

        // 4. MCQ options for all questions
        $optMap = [];
        if (!empty($questions)) {
            $qIds = implode(',', array_map('intval', array_column($questions, 'qb_id')));
            $optRows = $db->query("
                SELECT question_id, option_text, is_correct, display_order
                FROM question_bank_options
                WHERE question_id IN ($qIds)
                ORDER BY question_id, display_order
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($optRows as $opt) {
                $optMap[$opt['question_id']][] = $opt;
            }
        }

        // Helpers
        $examDate  = $p['exam_date']  ? date('d F Y', strtotime($p['exam_date']))           : '---';
        $timeRange = ($p['start_time'] && $p['end_time'])
                     ? date('h:i A', strtotime($p['start_time'])) . ' - ' . date('h:i A', strtotime($p['end_time']))
                     : '---';
        $duration  = $p['duration_mins'] ? $p['duration_mins'] . ' mins' : '---';
        $examType  = ucwords(str_replace('_', ' ', $p['exam_type'] ?? ''));
        $confLabel = $p['is_confidential'] ? 'CONFIDENTIAL' : 'GENERAL';
        $confClass = $p['is_confidential'] ? 'conf' : 'open';
        $createdAt = $p['created_at'] ? date('d M Y', strtotime($p['created_at'])) : '';
        $typeLabel = [
            'mcq'=>'Multiple Choice Questions','short'=>'Short Answer Questions',
            'long'=>'Long Answer / Essay','true_false'=>'True or False',
            'fill_blank'=>'Fill in the Blanks','match'=>'Match the Following'
        ];
        $optLetters = ['A','B','C','D','E','F'];

        // Group questions by blueprint section.
        // If a question has no blueprint_id (saved before sections were created, or
        // blueprint_id was not written correctly), redistribute it into the first
        // section whose question_type matches. Any remaining true orphans go to $qNoSection.
        $qBySection = [];
        $qNoSection = [];

        // Build a quick map: question_type → first section id (for redistribution)
        $typeToSecId = [];
        foreach ($sections as $sec) {
            $qt = $sec['question_type'];
            if (!isset($typeToSecId[$qt])) {
                $typeToSecId[$qt] = (int)$sec['id'];
            }
        }

        foreach ($questions as $q) {
            $bid = (int)($q['blueprint_id'] ?? 0);
            if ($bid) {
                $qBySection[$bid][] = $q;
            } else {
                // Try to redistribute into a matching section
                $matchSecId = $typeToSecId[$q['question_type']] ?? null;
                if ($matchSecId) {
                    $qBySection[$matchSecId][] = $q;
                } else {
                    $qNoSection[] = $q;
                }
            }
        }

        // Deduplicate blueprint sections: if two rows share the same section_name,
        // keep only the one that has questions assigned; drop the empty duplicate.
        // This handles the case where the wizard created a stale extra blueprint row.
        $seenSectionNames = [];
        $sectionsWithQ    = array_keys($qBySection); // blueprint ids that have at least 1 question
        $filteredSections = [];
        foreach ($sections as $sec) {
            $name = strtolower(trim($sec['section_name']));
            $hasQ = in_array((int)$sec['id'], $sectionsWithQ);
            if (isset($seenSectionNames[$name])) {
                // Already saw this name. Keep this one only if it has questions
                // AND the previously kept one does NOT have questions.
                $prevIdx = $seenSectionNames[$name];
                $prevHasQ = in_array((int)$filteredSections[$prevIdx]['id'], $sectionsWithQ);
                if ($hasQ && !$prevHasQ) {
                    // Replace the previously kept empty one with this one
                    $filteredSections[$prevIdx] = $sec;
                }
                // else: skip this duplicate
            } else {
                $seenSectionNames[$name] = count($filteredSections);
                $filteredSections[] = $sec;
            }
        }
        $sections = array_values($filteredSections);

        header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($p['college_name']) ?> - <?= htmlspecialchars($p['title']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Times New Roman",Times,serif;background:#e8e8e8;color:#111;font-size:12pt;line-height:1.65}

.toolbar{font-family:Arial,sans-serif;background:linear-gradient(135deg,#0F766E,#14B8A6);color:#fff;padding:9px 20px;
         display:flex;align-items:center;justify-content:space-between;
         position:sticky;top:0;z-index:100;border-bottom:3px solid #F59E0B}
.toolbar h2{font-size:13px;font-weight:600;opacity:.9}
.tb-btns{display:flex;gap:8px}
.btn-p{background:#F59E0B;color:#0D5C56;border:none;border-radius:6px;
       padding:6px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif}
.btn-c{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.30);
       border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;font-family:Arial,sans-serif}
.btn-p:hover{background:#D97706}.btn-c:hover{background:rgba(255,255,255,.25)}

/* Paper */
.paper{max-width:794px;margin:20px auto 40px;background:#fff;
       box-shadow:0 6px 36px rgba(0,0,0,.22)}

/* Header */
.qp-head{padding:20px 36px 14px;text-align:center;border-bottom:3px double #111}
.qp-college{font-size:18pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.qp-dept{font-size:12pt;font-weight:600;margin-top:2px}
.qp-contact{font-size:9pt;color:#555;margin-top:3px}
.qp-divider{border:none;border-top:1px solid #aaa;margin:10px 0}
.qp-exam{font-size:14pt;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.qp-course{font-size:10pt;margin-top:3px;color:#333}
.conf-badge{display:inline-block;margin-top:7px;padding:2px 14px;font-size:9pt;
            font-weight:700;letter-spacing:.1em;border:1px solid #c00;color:#c00}
.conf-badge.open{border-color:green;color:green}

/* Meta table */
.meta-wrap{padding:0 36px}
.meta-tbl{width:100%;border-collapse:collapse;font-size:10pt;
          border-top:2px solid #111;border-bottom:2px solid #111}
.meta-tbl td{padding:5px 10px;border:1px solid #ccc}
.meta-tbl .lbl{font-weight:700;background:#f5f5f5;width:110px}

/* Instructions */
.instr-wrap{margin:0 36px;padding:9px 12px;background:#fffde7;
            border-left:4px solid #f0c000;margin-top:0}
.instr-head{font-size:10pt;font-weight:700;text-transform:uppercase;
            letter-spacing:.05em;margin-bottom:5px}
.instr-ol{padding-left:18px;font-size:10pt;line-height:1.7}
.instr-ol li{margin-bottom:1px}
.instr-para{font-size:10pt;line-height:1.7;white-space:pre-wrap}

/* Section */
.sec-head{margin:16px 36px 6px;padding:5px 10px;
          background:#111;color:#fff;display:flex;justify-content:space-between;align-items:center}
.sec-title{font-size:11pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.sec-meta{font-size:9pt;opacity:.85}

/* Question */
.q-block{margin:4px 36px;padding:8px 0 8px;border-bottom:1px dashed #ccc}
.q-block:last-child{border-bottom:none}
.q-row{display:flex;gap:8px;align-items:flex-start}
.q-n{font-weight:700;min-width:26px;font-size:11pt;padding-top:1px;flex-shrink:0}
.q-txt{flex:1;font-size:11pt;line-height:1.7}
.q-m{font-size:10pt;font-weight:700;white-space:nowrap;padding-top:2px;color:#222}

/* MCQ options */
.opts{display:grid;grid-template-columns:1fr 1fr;gap:3px 16px;margin:7px 0 0 34px;font-size:10.5pt}
.opt{display:flex;gap:6px}
.opt-l{font-weight:700;min-width:18px;flex-shrink:0}

/* True/False */
.tf{margin:6px 0 0 34px;font-size:10.5pt;display:flex;gap:20px}

/* Fill blank */
.blank{display:inline-block;width:110px;border-bottom:1px solid #555;margin:0 3px;vertical-align:bottom}

/* Question image */
.q-img{display:block;max-width:100%;max-height:280px;margin:8px 0 4px 34px;border:1px solid #ccc;border-radius:3px;object-fit:contain}

/* Question table */
.q-table{margin:8px 0 4px 34px;border-collapse:collapse;font-size:10.5pt}
.q-table th,.q-table td{border:1px solid #555;padding:4px 10px;text-align:left}
.q-table th{background:#f0f0f0;font-weight:700}
@media print{.q-img{max-height:220px}.q-table{page-break-inside:avoid}}

/* No-questions notice */
.no-q{margin:12px 36px;padding:12px 16px;background:#fff8e1;border:1px solid #ffd54f;
      font-family:Arial,sans-serif;font-size:10pt;color:#5d4037;border-radius:3px}

/* Footer */
.qp-foot{border-top:2px double #111;margin:8px 0 0;padding:9px 36px;
         display:flex;justify-content:space-between;font-size:9pt;color:#444}

/* Print */
@media print{
  body{background:#fff}
  .toolbar{display:none!important}
  .paper{box-shadow:none;margin:0;max-width:100%}
  .qp-head,.sec-head{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .q-block{page-break-inside:avoid}
  .sec-head{page-break-after:avoid}
  @page{size:A4;margin:15mm 18mm}
}
</style>
</head>
<body>

<div class="toolbar">
  <h2>&#128196; <?= htmlspecialchars($p['title']) ?> &mdash; Print Preview</h2>
  <div class="tb-btns">
    <button class="btn-p" onclick="window.print()">&#128438; Print / Save PDF</button>
    <button class="btn-c" onclick="window.close()">&#10005; Close</button>
  </div>
</div>

<div class="paper">

  <!-- ── Header ──────────────────────────────────────────────── -->
  <div class="qp-head">
    <div class="qp-college"><?= htmlspecialchars($p['college_name']) ?></div>
    <div class="qp-dept"><?= htmlspecialchars($p['dept_name']) ?> Department</div>
    <?php if ($p['college_email'] || $p['college_phone']): ?>
    <div class="qp-contact">
      <?= htmlspecialchars($p['college_email'] ?? '') ?>
      <?= ($p['college_email'] && $p['college_phone']) ? ' | ' : '' ?>
      <?= htmlspecialchars($p['college_phone'] ?? '') ?>
    </div>
    <?php endif; ?>
    <hr class="qp-divider">
    <div class="qp-exam"><?= htmlspecialchars($p['exam_title'] ?: $p['title']) ?></div>
    <div class="qp-course">
      <?= htmlspecialchars($p['course_name']) ?> (<?= htmlspecialchars($p['course_code']) ?>)
      &nbsp;|&nbsp; <?= $examType ?>
      <?= $p['semester'] ? ' &nbsp;|&nbsp; Semester ' . (int)$p['semester'] : '' ?>
      <?= $p['academic_year'] ? ' &nbsp;|&nbsp; ' . htmlspecialchars($p['academic_year']) : '' ?>
    </div>
    <div><span class="conf-badge <?= $confClass ?>"><?= $confLabel ?> &mdash; Set <?= htmlspecialchars($p['version']) ?></span></div>
  </div>

  <!-- ── Meta ────────────────────────────────────────────────── -->
  <div class="meta-wrap">
    <table class="meta-tbl">
      <tr>
        <td class="lbl">Date</td><td><?= $examDate ?></td>
        <td class="lbl">Time</td><td><?= $timeRange ?></td>
        <td class="lbl">Duration</td><td><?= $duration ?></td>
      </tr>
      <tr>
        <td class="lbl">Max Marks</td><td><?= (int)$p['total_marks'] ?></td>
        <td class="lbl">Pass Marks</td><td><?= $p['pass_marks'] ? (int)$p['pass_marks'] : '---' ?></td>
        <td class="lbl">Questions</td><td><?= $p['total_questions'] ?: count($questions) ?></td>
      </tr>
    </table>
  </div>

  <!-- ── Instructions ─────────────────────────────────────────── -->
  <?php if ($p['instructions']): ?>
  <div class="instr-wrap" style="margin-top:10px">
    <div class="instr-head">Instructions to Candidates</div>
    <?php $lines = array_values(array_filter(array_map('trim', explode("\n", $p['instructions'])))); ?>
    <?php if (count($lines) > 1): ?>
    <ol class="instr-ol">
      <?php foreach ($lines as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
    </ol>
    <?php else: ?>
    <p class="instr-para"><?= htmlspecialchars($p['instructions']) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── Questions ─────────────────────────────────────────────── -->
  <?php
  $gNum = 1; // global question counter

  if (!empty($sections)):
    foreach ($sections as $sec):
      $secQs = $qBySection[$sec['id']] ?? [];
  ?>
  <div class="sec-head">
    <span class="sec-title"><?= htmlspecialchars($sec['section_name']) ?></span>
    <span class="sec-meta">
      <?= $typeLabel[$sec['question_type']] ?? ucfirst($sec['question_type']) ?>
      &nbsp;| <?= $sec['num_questions'] ?> Qs
      &nbsp;| <?= number_format($sec['marks_per_q'],0) ?> Mark<?= $sec['marks_per_q']!=1?'s':'' ?> each
      &nbsp;| Total: <?= number_format($sec['total_marks'],0) ?> Marks
      <?= $sec['is_compulsory'] ? ' | Compulsory' : '' ?>
    </span>
  </div>

  <?php if (!empty($secQs)):
    foreach ($secQs as $q):
      $marks = $q['marks_override'] ?? $q['marks'];
  ?>
  <div class="q-block">
    <div class="q-row">
      <span class="q-n"><?= $gNum ?>.</span>
      <span class="q-txt"><?= nl2br(htmlspecialchars($q['question_text'])) ?></span>
      <span class="q-m">(<?= number_format($marks,0) ?> M)</span>
    </div>
    <?php if ($q['question_type']==='mcq' && !empty($optMap[$q['qb_id']])): ?>
    <div class="opts">
      <?php foreach ($optMap[$q['qb_id']] as $oi=>$opt): ?>
      <div class="opt"><span class="opt-l"><?= $optLetters[$oi] ?>.</span><span><?= htmlspecialchars($opt['option_text']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($q['question_type']==='true_false'): ?>
    <div class="tf"><span>&#9744; True</span><span>&#9744; False</span></div>
    <?php endif; ?>
    <?php if (!empty($q['image_url'])): ?>
    <img class="q-img" src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question image">
    <?php endif; ?>
    <?php if (!empty($q['table_html'])): ?>
    <div style="margin:8px 0 4px 34px"><?= $q['table_html'] ?></div>
    <?php endif; ?>
  </div>
  <?php $gNum++; endforeach;
  else:
    // Only show placeholder slots when the entire paper has no questions yet
    if (empty($questions)): ?>
  <div class="no-q">
    &#9432; No questions assigned to this section yet. Add questions from the Question Bank.
  </div>
  <?php for ($i=0;$i<$sec['num_questions'];$i++): ?>
  <div class="q-block">
    <div class="q-row">
      <span class="q-n"><?= $gNum ?>.</span>
      <span class="q-txt" style="color:#bbb;font-style:italic">[Question <?= $gNum ?>]</span>
      <span class="q-m">(<?= number_format($sec['marks_per_q'],0) ?> M)</span>
    </div>
  </div>
  <?php $gNum++; endfor;
  endif;
  endif;

  endforeach;
  endif;

  // Questions not in any section (true orphans — no matching section type)
  if (!empty($qNoSection)):
  ?>
  <div class="sec-head">
    <span class="sec-title">Questions</span>
    <span class="sec-meta"><?= count($qNoSection) ?> Questions</span>
  </div>
  <?php foreach ($qNoSection as $q):
    $marks = $q['marks_override'] ?? $q['marks'];
  ?>
  <div class="q-block">
    <div class="q-row">
      <span class="q-n"><?= $gNum ?>.</span>
      <span class="q-txt"><?= nl2br(htmlspecialchars($q['question_text'])) ?></span>
      <span class="q-m">(<?= number_format($marks,0) ?> M)</span>
    </div>
    <?php if ($q['question_type']==='mcq' && !empty($optMap[$q['qb_id']])): ?>
    <div class="opts">
      <?php foreach ($optMap[$q['qb_id']] as $oi=>$opt): ?>
      <div class="opt"><span class="opt-l"><?= $optLetters[$oi] ?>.</span><span><?= htmlspecialchars($opt['option_text']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($q['question_type']==='true_false'): ?>
    <div class="tf"><span>&#9744; True</span><span>&#9744; False</span></div>
    <?php endif; ?>
    <?php if (!empty($q['image_url'])): ?>
    <img class="q-img" src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question image">
    <?php endif; ?>
    <?php if (!empty($q['table_html'])): ?>
    <div style="margin:8px 0 4px 34px"><?= $q['table_html'] ?></div>
    <?php endif; ?>
  </div>
  <?php $gNum++; endforeach;
  endif;

  if (empty($sections) && empty($questions)):
  ?>
  <div class="no-q" style="margin:24px 36px">
    &#9432; No questions have been added to this paper yet. Use the Question Bank tab to add questions.
  </div>
  <?php endif; ?>

  <!-- ── Footer ───────────────────────────────────────────────── -->
  <div class="qp-foot">
    <span>Set <?= htmlspecialchars($p['version']) ?> | <?= htmlspecialchars($p['course_code']) ?></span>
    <span>*** All the Best ***</span>
    <span><?= htmlspecialchars($p['college_name']) ?></span>
  </div>

</div><!-- /paper -->
</body>
</html>
<?php
        exit;
    }


    if ($action === 'qbank') {
        $q    = $_GET['q']       ?? '';
        $type = $_GET['qtype']   ?? '';
        $diff = $_GET['diff']    ?? '';
        $sub  = $_GET['subject'] ?? '';
        $params = [$collegeId];
        $where = 'WHERE qb.is_active=1 AND qb.college_id=?';
        if ($type) { $where .= ' AND qb.question_type=?'; $params[] = $type; }
        if ($diff) { $where .= ' AND qb.difficulty=?';    $params[] = $diff; }
        if ($q)    { $where .= ' AND qb.question_text LIKE ?'; $params[] = "%$q%"; }
        if ($sub)  { $where .= ' AND c.name=?'; $params[] = $sub; }
        $st = $db->prepare("SELECT qb.id,qb.question_text,qb.question_type,qb.difficulty,qb.marks,
                qb.topic,qb.bloom_level,qb.usage_count,qb.created_at,
                c.name subject_name,c.code course_code,u.full_name created_by_name,
                (SELECT GROUP_CONCAT(CONCAT(o.option_text,'|',o.is_correct) SEPARATOR ';;') FROM question_bank_options o WHERE o.question_id=qb.id) options
            FROM question_bank qb
            JOIN courses c ON c.id=qb.course_id
            LEFT JOIN users u ON u.id=qb.created_by
            $where ORDER BY qb.created_at DESC LIMIT 60");
        $st->execute($params);
        $questions = $st->fetchAll(PDO::FETCH_ASSOC);
        
        $stats_st = $db->prepare("SELECT question_type,difficulty,COUNT(*) cnt FROM question_bank WHERE is_active=1 AND college_id=? GROUP BY question_type,difficulty");
        $stats_st->execute([$collegeId]);
        $stats = $stats_st->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['questions'=>$questions,'stats'=>$stats]);
        exit;
    }

    if ($action === 'add_question' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("INSERT INTO question_bank (college_id,course_id,created_by,question_text,question_type,difficulty,marks,topic,bloom_level)
            VALUES(?,?,?,?,?,?,?,?,?)");
        $st->execute([$collegeId,$d['course_id'],$userId,$d['question_text'],$d['question_type'],
            $d['difficulty'],$d['marks']??1,$d['topic']??'',$d['bloom_level']??'remember']);
        $qid = $db->lastInsertId();
        if (!empty($d['options']) && is_array($d['options'])) {
            $opt = $db->prepare("INSERT INTO question_bank_options (question_id,option_text,is_correct,display_order) VALUES(?,?,?,?)");
            foreach($d['options'] as $i => $o) {
                $opt->execute([$qid,$o['text'],(int)$o['is_correct'],$i+1]);
            }
        }
        echo json_encode(['success'=>true,'id'=>$qid]);
        exit;
    }

    if ($action === 'delete_question' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("UPDATE question_bank SET is_active=0 WHERE id=? AND college_id=?");
        $st->execute([(int)$d['id'],$collegeId]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── BLUEPRINT ────────────────────────────────────────────────
    if ($action === 'blueprint') {
        $exam_id = (int)($_GET['exam_id'] ?? 0);
        $st = $db->prepare("SELECT bp.* FROM exam_blueprint bp JOIN exams e ON e.id=bp.exam_id
            WHERE bp.exam_id=? AND e.college_id=? AND e.department_id=? ORDER BY bp.display_order");
        $st->execute([$exam_id,$collegeId,$deptId]);
        $sections = $st->fetchAll(PDO::FETCH_ASSOC);
        $ei = $db->prepare("SELECT e.id,e.title,e.max_marks,e.pass_marks,es.duration_mins
            FROM exams e LEFT JOIN exam_schedule es ON es.exam_id=e.id AND es.status='scheduled'
            WHERE e.id=? AND e.college_id=? AND e.department_id=? LIMIT 1");
        $ei->execute([$exam_id,$collegeId,$deptId]);
        echo json_encode(['sections'=>$sections,'exam'=>$ei->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'add_blueprint_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $check = $db->prepare("SELECT id FROM exams WHERE id=? AND college_id=? AND department_id=?");
        $check->execute([(int)$d['exam_id'],$collegeId,$deptId]);
        if (!$check->fetch()) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $ord = $db->prepare("SELECT COALESCE(MAX(display_order),0)+1 FROM exam_blueprint WHERE exam_id=?");
        $ord->execute([$d['exam_id']]); $next = (int)$ord->fetchColumn();
        $st = $db->prepare("INSERT INTO exam_blueprint (exam_id,college_id,section_name,question_type,num_questions,marks_per_q,topic,bloom_levels,is_compulsory,display_order,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$d['exam_id'],$collegeId,$d['section_name'],$d['question_type']??'mcq',
            $d['num_questions']??5,$d['marks_per_q']??1,$d['topic']??'',$d['bloom_levels']??'',$d['is_compulsory']??1,$next,$userId]);
        echo json_encode(['success'=>true,'id'=>$db->lastInsertId()]);
        exit;
    }

    if ($action === 'delete_blueprint_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $st = $db->prepare("DELETE bp FROM exam_blueprint bp JOIN exams e ON e.id=bp.exam_id WHERE bp.id=? AND e.college_id=? AND e.department_id=?");
        $st->execute([(int)$d['id'],$collegeId,$deptId]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── DROPDOWN HELPERS ─────────────────────────────────────────
    if ($action === 'courses_list') {
        // Faculty: only show courses they are assigned to by the HOD.
        // college_admin / super_admin see all active dept courses.
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $st = $db->prepare("SELECT c.id,c.name,c.code,c.semester,c.department_id,d.name dept_name
                FROM courses c JOIN departments d ON d.id=c.department_id
                WHERE c.id IN ($ph) AND c.college_id=? AND c.department_id=? AND c.status='active'
                ORDER BY c.semester,c.name");
            $st->execute(array_merge($myCourses, [$collegeId, $deptId]));
        } else {
            $st = $db->prepare("SELECT c.id,c.name,c.code,c.semester,c.department_id,d.name dept_name
                FROM courses c JOIN departments d ON d.id=c.department_id
                WHERE c.college_id=? AND c.department_id=? AND c.status='active'
                ORDER BY c.semester,c.name");
            $st->execute([$collegeId, $deptId]);
        }
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'exams_dropdown') {
        $edParams = [$collegeId, $deptId];
        $edWhere  = "WHERE e.college_id=? AND e.department_id=? AND e.status NOT IN ('cancelled')";

        // Faculty: only exams for their assigned courses
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) {
                echo json_encode([]);
                exit;
            }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $edWhere .= " AND e.course_id IN ($ph)";
            $edParams = array_merge($edParams, $myCourses);
        }

        $st = $db->prepare("SELECT e.id, CONCAT(e.title,' (',c.code,')') label
            FROM exams e JOIN courses c ON c.id=e.course_id
            $edWhere ORDER BY e.title LIMIT 100");
        $st->execute($edParams);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Return which Set versions are already taken for a given exam
    if ($action === 'used_versions') {
        $examId = (int)($_GET['exam_id'] ?? 0);
        if (!$examId) { echo json_encode([]); exit; }
        $st = $db->prepare("SELECT version FROM question_papers WHERE exam_id=? AND college_id=?");
        $st->execute([$examId, $collegeId]);
        echo json_encode(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'version'));
        exit;
    }

    if ($action === 'schedules_list') {
        $st = $db->prepare("SELECT es.id,CONCAT(e.title,' — ',DATE_FORMAT(es.exam_date,'%d %b'),', ',TIME_FORMAT(es.start_time,'%h:%i %p')) label
            FROM exam_schedule es JOIN exams e ON e.id=es.exam_id
            WHERE e.college_id=? AND e.department_id=? AND es.status='scheduled' AND es.exam_date>=CURDATE() ORDER BY es.exam_date LIMIT 60");
        $st->execute([$collegeId,$deptId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }


    // ── FACULTY ASSIGNMENTS (read-only view for examinations.php) ───
    if ($action === 'my_faculty_assignments') {
        $st = $db->prepare("SELECT fa.id, fa.course_id, fa.semester, fa.academic_year, fa.period, fa.is_primary, fa.status,
                c.name course_name, c.code course_code, c.credits,
                d.name dept_name, d.code dept_code,
                ab.full_name assigned_by_name, fa.created_at
            FROM faculty_assignments fa
            JOIN courses c ON c.id=fa.course_id
            JOIN departments d ON d.id=fa.department_id
            LEFT JOIN users ab ON ab.id=fa.assigned_by
            WHERE fa.faculty_id=? AND fa.college_id=? AND fa.department_id=? AND fa.status='active'
            ORDER BY fa.academic_year DESC, c.name");
        $st->execute([$userId, $collegeId, $deptId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── SUPERADMIN EXAM LIST (read-only for examinations.php) ────────
    if ($action === 'superadmin_exams') {
        // Apply optional filters from GET params
        $typeFilter   = $_GET['type']   ?? '';
        $statusFilter = $_GET['status'] ?? '';
        $qFilter      = $_GET['q']      ?? '';
        $params = [$collegeId, $deptId, $deptId]; // extra $deptId for c.department_id guard
        $where  = 'WHERE e.college_id=? AND e.department_id=? AND c.department_id=?';

        // Faculty: restrict to courses assigned to them by superadmin
        if ($role === 'faculty') {
            $myCourses = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
            if (empty($myCourses)) {
                echo json_encode([]);
                exit;
            }
            $ph = implode(',', array_fill(0, count($myCourses), '?'));
            $where  .= " AND e.course_id IN ($ph)";
            $params  = array_merge($params, $myCourses);
        }

        if ($typeFilter)   { $where .= ' AND e.type=?';   $params[] = $typeFilter; }
        if ($statusFilter) { $where .= ' AND e.status=?'; $params[] = $statusFilter; }
        if ($qFilter)      { $where .= ' AND (e.title LIKE ? OR c.code LIKE ?)'; $params[] = "%$qFilter%"; $params[] = "%$qFilter%"; }
        $st = $db->prepare("SELECT e.id,e.title,e.type,e.status,e.max_marks,e.pass_marks,e.exam_date,e.semester,e.academic_year,
                e.remarks,e.created_at,
                c.name course_name,c.code course_code, d.name dept_name,
                creator.full_name created_by_name, creator.role created_by_role,
                es.id schedule_id,es.exam_date sched_date,es.start_time,es.end_time,es.duration_mins,es.status sched_status,
                h.name hall_name,h.capacity hall_capacity,h.id hall_id,
                (SELECT COUNT(*) FROM admit_cards ac WHERE ac.schedule_id=es.id AND ac.is_valid=1) admit_count,
                (SELECT COUNT(*) FROM exam_invigilators ei WHERE ei.schedule_id=es.id) invig_count
            FROM exams e
            JOIN courses c ON c.id=e.course_id
            JOIN departments d ON d.id=e.department_id
            LEFT JOIN users creator ON creator.id=e.created_by
            LEFT JOIN exam_schedule es ON es.exam_id=e.id AND es.status='scheduled'
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            $where ORDER BY es.exam_date ASC, es.start_time ASC, e.created_at DESC");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── ASSIGNED SCHEDULE (scoped to faculty's courses) ───────────────
    if ($action === 'assigned_schedule') {
        $from = $_GET['from'] ?? date('Y-m-d');
        $to   = $_GET['to']   ?? date('Y-m-d', strtotime('+30 days'));
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        $params = [$collegeId, $deptId, $from, $to];
        $courseFilter = '';
        // Faculty: MUST be restricted to their HOD-assigned courses only.
        // Never fall back to showing the whole department schedule.
        if ($role === 'faculty') {
            if (empty($courseIds)) {
                echo json_encode(['slots'=>[], 'conflict_ids'=>[]]); exit;
            }
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND e.course_id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        $st = $db->prepare("SELECT es.id,es.exam_date,es.start_time,es.end_time,es.duration_mins,es.status,
                e.title,e.type,e.status exam_status,c.code course_code,c.name course_name,
                h.name hall_name,h.capacity,d.code dept_code,
                creator.full_name created_by_name, creator.role created_by_role,
                (SELECT COUNT(*) FROM exam_invigilators ei WHERE ei.schedule_id=es.id) invig_count,
                (SELECT COUNT(*) FROM admit_cards ac WHERE ac.schedule_id=es.id AND ac.is_valid=1) admit_count
            FROM exam_schedule es
            JOIN exams e ON e.id=es.exam_id
            JOIN courses c ON c.id=e.course_id
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            LEFT JOIN departments d ON d.id=e.department_id
            LEFT JOIN users creator ON creator.id=e.created_by
            WHERE e.college_id=? AND e.department_id=? AND es.exam_date BETWEEN ? AND ? $courseFilter
            ORDER BY es.exam_date,es.start_time");
        $st->execute($params);
        echo json_encode(['slots'=>$st->fetchAll(PDO::FETCH_ASSOC), 'conflict_ids'=>[]]);
        exit;
    }

    // ── ASSIGNED HALLS (superadmin-allocated, scoped to faculty courses) ─
    if ($action === 'assigned_halls') {
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        $params = [$collegeId, $deptId];
        $courseFilter = '';
        if ($role === 'faculty') {
            if (empty($courseIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND e.course_id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        $st = $db->prepare("SELECT h.id,h.name,h.code,h.capacity,h.building,h.floor,h.has_projector,h.has_ac,h.status,
                es.exam_date,es.start_time,es.end_time,es.id schedule_id,
                e.title exam_title,e.type exam_type,c.name course_name,c.code course_code,
                (SELECT COUNT(*) FROM seating_arrangement sa WHERE sa.hall_id=h.id AND sa.schedule_id=es.id) seats_assigned
            FROM exam_halls h
            JOIN exam_schedule es ON es.hall_id=h.id AND es.status='scheduled'
            JOIN exams e ON e.id=es.exam_id
            JOIN courses c ON c.id=e.course_id
            WHERE e.college_id=? AND e.department_id=? $courseFilter AND es.exam_date>=CURDATE()
            ORDER BY es.exam_date,es.start_time");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── ASSIGNED INVIGILATORS (superadmin-assigned, scoped to faculty courses) ─
    if ($action === 'assigned_invigilators') {
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        $params = [$collegeId, $deptId];
        $courseFilter = '';
        if ($role === 'faculty') {
            if (empty($courseIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND e.course_id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        $st = $db->prepare("SELECT ei.id,ei.duty_type,ei.is_confirmed,
                u.full_name faculty_name,u.email faculty_email,u.phone faculty_phone,
                d.name dept_name, d.code dept_code,
                e.title exam_title,e.type exam_type,c.name course_name,c.code course_code,
                es.exam_date,es.start_time,es.end_time,es.id schedule_id,
                h.name hall_name,h.capacity,
                assigner.full_name assigned_by_name
            FROM exam_invigilators ei
            JOIN users u ON u.id=ei.faculty_id
            JOIN exam_schedule es ON es.id=ei.schedule_id
            JOIN exams e ON e.id=es.exam_id
            JOIN courses c ON c.id=e.course_id
            LEFT JOIN departments d ON d.id=u.department_id
            LEFT JOIN exam_halls h ON h.id=ei.hall_id
            LEFT JOIN users assigner ON assigner.id=ei.created_by
            WHERE e.college_id=? AND e.department_id=? $courseFilter
            ORDER BY es.exam_date,es.start_time,u.full_name");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── ASSIGNED SEATING (scoped to faculty's courses) ───────────────
    if ($action === 'assigned_seating') {
        $schedule_id = (int)($_GET['schedule_id'] ?? 0);
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        $params = [$collegeId];
        $courseFilter = '';
        if ($role === 'faculty') {
            if (empty($courseIds)) { echo json_encode(['seats'=>[],'hall'=>null]); exit; }
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND e.course_id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        $schedWhere = $schedule_id ? " AND sa.schedule_id=$schedule_id" : '';
        $st = $db->prepare("SELECT sa.seat_number,sa.row_no,sa.col_no,sa.is_present,sa.schedule_id,
                u.full_name,u.roll_number,d2.code dept_code,d2.name dept_name,
                es.exam_date,e.title exam_title,c.name course_name,c.code course_code,
                h.name hall_name,h.capacity,h.id hall_id
            FROM seating_arrangement sa
            JOIN users u ON u.id=sa.student_id
            JOIN exam_schedule es ON es.id=sa.schedule_id
            JOIN exams e ON e.id=es.exam_id AND e.college_id=?
            JOIN courses c ON c.id=e.course_id
            JOIN exam_halls h ON h.id=sa.hall_id
            LEFT JOIN departments d2 ON d2.id=u.department_id
            WHERE 1=1 $schedWhere $courseFilter
            ORDER BY sa.row_no,sa.col_no");
        $st->execute($params);
        $seats = $st->fetchAll(PDO::FETCH_ASSOC);
        $hall = null;
        if ($schedule_id) {
            $hi = $db->prepare("SELECT h.* FROM exam_halls h JOIN exam_schedule es ON es.hall_id=h.id WHERE es.id=? AND h.college_id=?");
            $hi->execute([$schedule_id, $collegeId]);
            $hall = $hi->fetch(PDO::FETCH_ASSOC);
        }
        echo json_encode(['seats'=>$seats, 'hall'=>$hall]);
        exit;
    }

    // ── ASSIGNED ADMIT CARDS (superadmin-issued, scoped to faculty courses) ─
    if ($action === 'assigned_admits') {
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        $q = $_GET['q'] ?? '';
        $params = [$collegeId, $deptId];
        $courseFilter = '';
        $searchFilter = '';
        if ($role === 'faculty') {
            if (empty($courseIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND e.course_id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        if ($q) { $searchFilter = " AND (u.full_name LIKE ? OR u.roll_number LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        $st = $db->prepare("SELECT ac.id,ac.admit_card_no,ac.is_valid,ac.is_downloaded,ac.issued_at,
                u.id student_id,u.full_name student_name,u.roll_number,u.email,
                col.name college_name,e.title exam_title,e.type exam_type,c.name course_name,c.code course_code,
                es.exam_date,es.start_time,es.end_time,h.name hall_name,sa.seat_number,
                issuer.full_name issued_by_name
            FROM admit_cards ac
            JOIN users u ON u.id=ac.student_id
            JOIN exam_schedule es ON es.id=ac.schedule_id
            JOIN exams e ON e.id=es.exam_id
            JOIN courses c ON c.id=e.course_id
            JOIN colleges col ON col.id=e.college_id
            LEFT JOIN exam_halls h ON h.id=es.hall_id
            LEFT JOIN seating_arrangement sa ON sa.schedule_id=es.id AND sa.student_id=u.id
            LEFT JOIN users issuer ON issuer.id=ac.created_by
            WHERE e.college_id=? AND e.department_id=? $courseFilter $searchFilter
            ORDER BY u.roll_number LIMIT 200");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── APPROVE/REJECT Q.PAPER (superadmin/college_admin only) ──────
    if ($action === 'approve_qpaper' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!in_array($role, ['super_admin','college_admin'])) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $d = json_decode(file_get_contents('php://input'), true);
        $newStatus = ($d['decision'] === 'approve') ? 'approved' : 'draft';
        $st = $db->prepare("UPDATE question_papers SET status=?, approved_by=?, updated_at=NOW() WHERE id=? AND college_id=?");
        $st->execute([$newStatus, $userId, (int)$d['id'], $collegeId]);
        $msg = ($d['decision'] === 'approve') ? 'Q.Paper approved successfully.' : 'Q.Paper sent back to draft.';
        echo json_encode(['success'=>true, 'msg'=>$msg, 'new_status'=>$newStatus]);
        exit;
    }

    // ── PENDING APPROVALS LIST (for superadmin panel within examinations.php) ─
    if ($action === 'pending_approvals') {
        if (!in_array($role, ['super_admin','college_admin'])) { echo json_encode([]); exit; }
        $st = $db->prepare("SELECT qp.id,qp.title,qp.version,qp.status,qp.created_at,qp.total_marks,
                e.title exam_title,e.type exam_type,
                c.name course_name,c.code course_code,
                d.name dept_name,
                creator.full_name created_by_name, creator.id created_by_id
            FROM question_papers qp
            JOIN exams e ON e.id=qp.exam_id
            JOIN courses c ON c.id=e.course_id
            JOIN departments d ON d.id=e.department_id
            LEFT JOIN users creator ON creator.id=qp.created_by
            WHERE qp.college_id=? AND e.department_id=? AND qp.status='pending_approval'
            ORDER BY qp.created_at DESC");
        $st->execute([$collegeId, $deptId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── MY ASSIGNED COURSES (for examinations.php faculty assignments tab) ─
    if ($action === 'my_assigned_courses') {
        $courseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
        if ($role === 'faculty' && empty($courseIds)) { echo json_encode([]); exit; }
        $params = [$userId, $collegeId, $deptId];
        $courseFilter = '';
        if ($role === 'faculty') {
            $ph = implode(',', array_fill(0, count($courseIds), '?'));
            $courseFilter = " AND c.id IN ($ph)";
            $params = array_merge($params, $courseIds);
        }
        $st = $db->prepare("SELECT c.id,c.name,c.code,c.semester,c.credits,
                fa.is_primary,fa.period,fa.academic_year,fa.assigned_by,
                ab.full_name assigned_by_name
            FROM courses c
            LEFT JOIN faculty_assignments fa ON fa.course_id=c.id AND fa.faculty_id=? AND fa.status='active'
            LEFT JOIN users ab ON ab.id=fa.assigned_by
            WHERE c.college_id=? AND c.department_id=? AND c.status='active' $courseFilter
            ORDER BY c.semester,c.name");
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// ══ PAGE-LEVEL DATA ════════════════════════════════════════════════
$db = pdo();

// College & dept info
$col_st = $db->prepare("SELECT name,code,email,phone,established FROM colleges WHERE id=? AND status='active' LIMIT 1");
$col_st->execute([$collegeId]);
$college = $col_st->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'Your College','code'=>'','email'=>'','phone'=>'','established'=>''];
$collegeName = $college['name']; $collegeCode = $college['code'];

$dept_st = $db->prepare("SELECT name,code,hod_name FROM departments WHERE id=? AND college_id=? LIMIT 1");
$dept_st->execute([$deptId,$collegeId]);
$dept = $dept_st->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'Your Department','code'=>'','hod_name'=>''];
$deptName = $dept['name']; $deptCode = $dept['code'];

$avatarInitials = initials($fullName);
$firstName      = explode(' ', trim($fullName))[0];

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

// Pending marks badge
$pm = $db->prepare("SELECT COUNT(*) FROM marks m JOIN exams e ON e.id=m.exam_id WHERE e.college_id=? AND e.department_id=? AND m.obtained_marks IS NULL AND m.is_absent=0");
$pm->execute([$collegeId,$deptId]);
$pendingMarks = (int)$pm->fetchColumn();

// Upcoming exams badge
$ue = $db->prepare("SELECT COUNT(*) FROM exams WHERE college_id=? AND department_id=? AND exam_date>=CURDATE() AND status IN ('upcoming','ongoing')");
$ue->execute([$collegeId,$deptId]);
$upcomingExams = (int)$ue->fetchColumn();

// My courses count — for faculty: only assigned courses
$assignedCourseIds = getFacultyAssignedCourseIds($db, $userId, $collegeId, $deptId, $role);
if ($role === 'faculty' && !empty($assignedCourseIds)) {
    $ph = implode(',', array_fill(0, count($assignedCourseIds), '?'));
    $mc = $db->prepare("SELECT COUNT(*) FROM courses WHERE id IN ($ph) AND college_id=? AND department_id=? AND status='active'");
    $mc->execute(array_merge($assignedCourseIds, [$collegeId, $deptId]));
} else {
    $mc = $db->prepare("SELECT COUNT(*) FROM courses WHERE college_id=? AND department_id=? AND status='active'");
    $mc->execute([$collegeId,$deptId]);
}
$myCourses = (int)$mc->fetchColumn();

// Pending approvals badge (superadmin/college_admin only)
$pendingApprovalsCount = 0;
if (in_array($role, ['super_admin','college_admin'])) {
    $pa = $db->prepare("SELECT COUNT(*) FROM question_papers qp JOIN exams e ON e.id=qp.exam_id WHERE qp.college_id=? AND e.department_id=? AND qp.status='pending_approval'");
    $pa->execute([$collegeId, $deptId]);
    $pendingApprovalsCount = (int)$pa->fetchColumn();
}

// My students count
$ms = $db->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND college_id=? AND department_id=? AND status='active'");
$ms->execute([$collegeId,$deptId]);
$myStudents = (int)$ms->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduNexus — Examinations · <?= esc($deptName) ?> · <?= esc($collegeName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ───────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --teal:#0F766E;--teal-dark:#0D5C56;--teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --teal3:rgba(20,184,166,.10);--teal4:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;--amber:#D97706;--amber2:rgba(217,119,6,.14);
  --amber-soft:rgba(245,158,11,.12);
  --page-bg:#F8FAFC;
  --success:#16A34A;--green:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;--text:#0F172A;--muted:#475569;
  --border:rgba(15,118,110,.10);--border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;--card-hover:#F0FDFA;
  --sidebar-w:264px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  --sb-text:rgba(255,255,255,.78);--sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);--sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);--sb-active-border:rgba(245,158,11,.38);
}
html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}
.bg{display:none}
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;}
.shell{position:relative;z-index:1;display:flex;min-height:100vh}
/* ── Sidebar ──────────────────────────────────────────────────────────────── */
.sidebar{width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);-webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform .3s ease;overflow:hidden;}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);pointer-events:none}
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0;}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}
.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12)}
.logo-mark{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15);}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}
.scope-chip{margin:10px 12px 0;background:linear-gradient(135deg,rgba(20,184,166,.13) 0%,rgba(15,118,110,.09) 100%);border:1px solid rgba(20,184,166,.32);border-radius:12px;padding:11px 13px 12px;box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 4px 16px rgba(0,0,0,.12);position:relative;overflow:hidden;}
.scope-chip::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent);}
.sc-label{font-size:.52rem;color:rgba(94,234,212,.90);text-transform:uppercase;letter-spacing:.16em;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.sc-label i{font-size:.54rem;}
.sc-college{font-size:.86rem;font-weight:800;color:#FFFFFF;line-height:1.25;margin-bottom:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;letter-spacing:.01em;}
.sc-depts{display:flex;flex-direction:column;gap:4px;margin-top:0}
.sc-dept-tag{font-size:.72rem;color:rgba(186,230,253,.90);display:inline-flex;align-items:center;gap:5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
.sc-dept-tag i{font-size:.62rem;flex-shrink:0;color:rgba(94,234,212,.80);}
.sc-dept i{color:var(--teal-light);font-size:.62rem}
.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent;}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-badge{font-size:.58rem;font-weight:700;padding:2px 6px;border-radius:5px;margin-left:auto;background:var(--teal-soft2);color:var(--teal);font-family:var(--mono)}
.nav-badge.red{background:var(--red2);color:var(--red)}
.nav-badge.amber{background:var(--amber-soft);color:var(--amber-acc)}
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;box-shadow:0 2px 10px rgba(245,158,11,.28);}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.65rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}
/* ── Main ─────────────────────────────────────────────────────────────────── */
.sb-collapse-btn{margin-left:auto;flex-shrink:0;width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.55);cursor:pointer;display:grid;place-items:center;font-size:.68rem;transition:all .2s;}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber-acc);}
:root{--sb-collapsed-w:72px}
.sidebar.collapsed{width:var(--sb-collapsed-w)}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0}
.sidebar.collapsed .logo-text{display:none !important}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important}
.sidebar.collapsed .nav-item.active,.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12) !important;border-color:rgba(255,255,255,.15) !important;box-shadow:none !important;color:#ffffff !important}
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i,.sidebar.collapsed a.nav-item.active i{color:#ffffff !important;opacity:1 !important;visibility:visible !important;-webkit-text-fill-color:#ffffff !important}
.sidebar.collapsed .scope-chip,.sidebar.collapsed .scope-chip *,.sidebar.collapsed .scope-chip::before{display:none !important;height:0 !important;max-height:0 !important;padding:0 !important;margin:0 !important;border:none !important;overflow:hidden !important;visibility:hidden !important;opacity:0 !important}
.sidebar.collapsed .nav-label{display:none !important;height:0 !important;margin:0 !important;padding:0 !important;overflow:hidden !important}
.sidebar.collapsed .sidebar-nav{padding:6px 8px 16px;margin-top:46px}
.sidebar.collapsed .nav-item{display:flex !important;justify-content:center !important;align-items:center !important;padding:10px 0 !important;gap:0 !important;width:100%;overflow:hidden;border-radius:10px}
.sidebar.collapsed .nav-item i{width:20px;text-align:center;font-size:.9rem;flex-shrink:0;margin:0;padding:0}
.sidebar.collapsed .nav-text,.sidebar.collapsed .nav-badge{display:none !important;width:0 !important;height:0 !important;overflow:hidden !important;padding:0 !important;margin:0 !important}
.sidebar.collapsed .sidebar-user{padding:10px 0;justify-content:center;gap:0}
.sidebar.collapsed .user-info,.sidebar.collapsed .logout-btn{display:none !important;width:0 !important;overflow:hidden !important}
.sidebar.collapsed .user-avatar{margin:0 auto;flex-shrink:0}
.sidebar.collapsed .nav-item::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#0F172A;color:#fff;font-size:.71rem;font-weight:500;font-family:var(--font);padding:5px 11px;border-radius:7px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08)}
.sidebar.collapsed .nav-item:hover::after{opacity:1}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease}
.sidebar.collapsed~.main,.shell:has(.sidebar.collapsed) .main{margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w))}
.topbar{height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;}
.hamburger{display:none;width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);cursor:pointer;place-items:center;font-size:.9rem;}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:#fff;font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);color:#ffffff;display:grid;place-items:center;cursor:pointer;transition:all .18s;position:relative;font-size:.82rem;}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{height:36px;display:flex;align-items:center;font-size:.72rem;color:#ffffff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);padding:0 11px;border-radius:9px;font-family:var(--mono)}
.topbar-avatar-wrap{position:relative}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;cursor:pointer;border:2px solid rgba(245,158,11,.30);transition:border-color .2s,box-shadow .2s;user-select:none;flex-shrink:0;}
.topbar-avatar:hover,.topbar-avatar.open{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{position:absolute;top:calc(100% + 10px);right:0;min-width:200px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);z-index:200;opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease;}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;color:var(--muted);font-size:.8rem;font-weight:500;text-decoration:none;transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:#94A3B8;flex-shrink:0}
.ad-item:hover{background:var(--card-hover);color:var(--text)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}
/* ── Content ──────────────────────────────────────────────────────────────── */
.content{padding:20px 24px;flex:1}
.scope-banner{display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:var(--purple2);
  border:1px solid rgba(124,58,237,.2);border-radius:10px;padding:10px 16px;margin-bottom:20px;font-size:.79rem;animation:slideUp .4s ease both;}
.scope-banner i{color:var(--purple);flex-shrink:0}
.scope-banner strong{color:var(--text)}
.scope-banner .sep{color:var(--muted)}
.scope-banner .role-tag{margin-left:auto;font-size:.68rem;font-family:var(--mono);background:rgba(124,58,237,.15);color:var(--purple);padding:3px 8px;border-radius:6px}
.college-strip{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--teal-soft);
  border:1px solid var(--border-accent);border-radius:10px;padding:12px 18px;margin-bottom:20px;animation:slideUp .4s .05s ease both;}
.cs-icon{width:40px;height:40px;border-radius:10px;background:var(--teal-soft2);display:grid;place-items:center;color:var(--teal);font-size:1rem;flex-shrink:0}
.cs-name{font-size:.9rem;font-weight:700;color:var(--text)}
.cs-meta{font-size:.7rem;color:var(--muted);margin-top:2px;display:flex;gap:14px;flex-wrap:wrap}
.cs-meta span{display:flex;align-items:center;gap:4px}
.cs-meta i{color:var(--teal);font-size:.62rem}
.cs-right{margin-left:auto;text-align:right}
.cs-dept-badge{font-size:.72rem;background:var(--teal-soft2);border:1px solid var(--border-accent);color:var(--teal);padding:4px 10px;border-radius:7px;font-weight:600;font-family:var(--mono)}
.cs-hod{font-size:.68rem;color:var(--muted);margin-top:4px}
/* ── Tab navigation ───────────────────────────────────────────────────────── */
.tab-bar{display:flex;gap:4px;flex-wrap:wrap;background:#fff;border:1px solid var(--border);
  border-radius:12px;padding:5px;margin-bottom:20px;animation:slideUp .4s .1s ease both;box-shadow:0 1px 8px rgba(15,118,110,.05);}
.tab-btn{display:flex;align-items:center;gap:7px;padding:9px 14px;border-radius:9px;border:none;background:none;
  color:var(--muted);font-size:.78rem;font-weight:500;cursor:pointer;transition:all .18s;white-space:nowrap;font-family:var(--font);}
.tab-btn i{font-size:.78rem}
.tab-btn:hover{color:var(--text);background:var(--teal-soft)}
.tab-btn.active{background:var(--teal-soft2);color:var(--teal);border:1px solid var(--border-accent)}
.tab-badge{font-size:.58rem;background:var(--red2);color:var(--red);padding:2px 5px;border-radius:4px;font-family:var(--mono);font-weight:700}
.tab-badge.amber{background:var(--amber-soft);color:var(--amber-acc)}
.tab-badge.teal{background:var(--teal-soft);color:var(--teal)}
/* ── Stats ────────────────────────────────────────────────────────────────── */
.stats{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;margin-bottom:20px;animation:slideUp .4s .12s ease both}
.stat{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;
  text-align:center;transition:transform .2s,box-shadow .2s;box-shadow:0 1px 8px rgba(15,118,110,.05);}
.stat:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.10)}
.stat-ico{font-size:1.1rem;margin-bottom:6px}
.stat-val{font-family:var(--mono);font-size:1.4rem;font-weight:800;color:var(--text);line-height:1}
.stat-lbl{font-size:.65rem;color:var(--muted);margin-top:4px}
.si-teal{color:var(--teal)}.si-amber{color:var(--amber-acc)}.si-red{color:var(--red)}
.si-green{color:var(--success)}.si-purple{color:var(--purple)}.si-blue{color:var(--blue)}
.tab-panel{display:none;animation:slideUp .3s ease both}
.tab-panel.active{display:block}
/* ── Card ─────────────────────────────────────────────────────────────────── */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:#F0FDFA}
.card-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal);font-size:.8rem}
.card-link{font-size:.72rem;color:var(--teal);text-decoration:none;border:1px solid var(--border-accent);padding:5px 10px;border-radius:6px;transition:all .18s;white-space:nowrap;cursor:pointer;background:var(--teal-soft);font-weight:600}
.card-link:hover{background:var(--teal-soft2);border-color:var(--teal)}
/* ── Filter bar ───────────────────────────────────────────────────────────── */
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border);background:#F8FAFC}
.filter-input,.filter-sel{background:#fff;border:1px solid var(--border);border-radius:8px;
  color:var(--text);font-size:.78rem;padding:7px 12px;outline:none;font-family:var(--font);transition:border-color .18s;}
.filter-input:focus,.filter-sel:focus{border-color:var(--teal-light)}
.filter-input{flex:1;min-width:180px}
.filter-sel option{background:#fff;color:var(--text)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;border:none;
  font-size:.78rem;font-weight:600;cursor:pointer;transition:all .18s;font-family:var(--font);}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-teal:hover{filter:brightness(1.06);transform:translateY(-1px)}
.btn-ghost{background:#F8FAFC;border:1px solid var(--border);color:var(--muted)}
.btn-ghost:hover{border-color:var(--border-accent);color:var(--teal);background:var(--teal-soft)}
.btn-red{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.25)}
.btn-red:hover{background:rgba(220,38,38,.2)}
.btn-sm{padding:5px 10px;font-size:.72rem}
.btn-amber{background:var(--amber-soft);color:var(--amber-acc);border:1px solid rgba(245,158,11,.25)}
/* ── Table ────────────────────────────────────────────────────────────────── */
.t-wrap{overflow-x:auto;padding:0 0 4px}
table{width:100%;border-collapse:collapse;min-width:600px}
thead th{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);
  padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;background:#F8FAFC}
tbody td{padding:12px 16px;font-size:.79rem;color:var(--text);vertical-align:middle}
tbody tr:not(:last-child) td{border-bottom:1px solid var(--border)}
tbody tr:hover td{background:#F0FDFA}
.mono{font-family:var(--mono);font-size:.72rem;color:var(--muted)}
.s-name{font-size:.82rem;font-weight:600;color:var(--text)}
.s-sub{font-size:.68rem;color:var(--muted);margin-top:2px}
/* Pills */
.pill{font-size:.64rem;font-weight:700;padding:3px 8px;border-radius:6px;display:inline-block;white-space:nowrap}
.pill.upcoming{background:var(--blue2);color:var(--blue)}
.pill.ongoing{background:var(--amber-soft);color:var(--amber-acc)}
.pill.draft{background:#F1F5F9;color:var(--muted)}
.pill.published{background:var(--green2);color:var(--success)}
.pill.completed{background:var(--teal-soft);color:var(--teal)}
.pill.cancelled{background:var(--red2);color:var(--red)}
.pill.active{background:var(--green2);color:var(--success)}
.pill.inactive{background:#F1F5F9;color:var(--muted)}
.pill.chief{background:var(--purple2);color:var(--purple)}
.pill.assistant{background:var(--blue2);color:var(--blue)}
.pill.flying_squad{background:var(--amber-soft);color:var(--amber-acc)}
.pill.approved{background:var(--green2);color:var(--success)}
.pill.pending_approval{background:var(--amber-soft);color:var(--amber-acc)}
.pill.released{background:var(--teal-soft);color:var(--teal)}
.pill.archived{background:#F1F5F9;color:var(--muted)}
.pill.easy{background:var(--green2);color:var(--success)}
.pill.medium{background:var(--amber-soft);color:var(--amber-acc)}
.pill.hard{background:var(--red2);color:var(--red)}
.pill.valid{background:var(--green2);color:var(--success)}
.pill.revoked{background:var(--red2);color:var(--red)}
.pill.confirmed{background:var(--green2);color:var(--success)}
.pill.unconfirmed{background:#F1F5F9;color:var(--muted)}
.empty-state{padding:48px;text-align:center;color:var(--muted);font-size:.82rem}
.empty-state i{font-size:2.2rem;display:block;margin-bottom:12px;opacity:.2}
/* ── Exam cards ───────────────────────────────────────────────────────────── */
.exam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;padding:18px 20px}
.exam-card{background:#F8FAFC;border:1px solid var(--border);border-radius:12px;padding:16px;
  transition:border-color .2s,transform .2s,box-shadow .2s;cursor:pointer;}
.exam-card:hover{border-color:var(--border-accent);transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.08)}
.ec-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px}
.ec-title{font-size:.88rem;font-weight:700;color:var(--text);flex:1}
.ec-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.ec-meta-item{font-size:.7rem;color:var(--muted);display:flex;align-items:center;gap:4px}
.ec-meta-item i{color:var(--teal);font-size:.62rem}
.ec-foot{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid var(--border)}
.ec-chips{display:flex;gap:6px;flex-wrap:wrap}
.ec-chip{font-size:.65rem;padding:2px 7px;border-radius:5px;background:#F1F5F9;color:var(--muted);font-family:var(--mono)}
.ec-chip.has{background:var(--green2);color:var(--success)}
.ec-actions{display:flex;gap:5px}
/* ── Schedule calendar ────────────────────────────────────────────────────── */
.week-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;padding:18px 20px}
.day-col{background:#F8FAFC;border:1px solid var(--border);border-radius:10px;padding:10px 8px;min-height:120px}
.day-hdr{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:8px;text-align:center}
.day-num{font-size:1.1rem;font-weight:800;color:var(--text);text-align:center;margin-bottom:6px;font-family:var(--mono)}
.day-today .day-num{color:var(--teal)}
.day-today{border-color:var(--border-accent);background:var(--teal-soft)}
.sched-slot{background:var(--teal-soft);border:1px solid var(--border-accent);border-radius:6px;padding:5px 7px;margin-bottom:5px;cursor:pointer;transition:background .18s}
.sched-slot:hover{background:var(--teal-soft2)}
.sched-slot.conflict{background:var(--red2);border-color:rgba(220,38,38,.3)}
.slot-time{font-size:.63rem;color:var(--teal);font-family:var(--mono)}
.slot-title{font-size:.7rem;color:var(--text);font-weight:600;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-hall{font-size:.63rem;color:var(--muted)}
.week-nav{display:flex;align-items:center;gap:10px}
.week-label{font-size:.82rem;font-weight:600;color:var(--text);font-family:var(--mono)}
/* ── Conflicts ────────────────────────────────────────────────────────────── */
.conflict-list{padding:14px 20px;display:flex;flex-direction:column;gap:10px}
.conflict-card{background:rgba(220,38,38,.03);border:1px solid rgba(220,38,38,.18);border-radius:10px;padding:14px 16px}
.cc-type{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--red);margin-bottom:6px;display:flex;align-items:center;gap:5px}
.cc-desc{font-size:.8rem;color:var(--text);margin-bottom:10px}
.cc-sides{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
.cc-side{background:#F8FAFC;border-radius:8px;padding:8px 12px;font-size:.74rem;border:1px solid var(--border)}
.cc-side-label{color:var(--muted);font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.cc-side strong{color:var(--text)}
/* ── Hall cards ───────────────────────────────────────────────────────────── */
.hall-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:18px 20px}
.hall-card{background:#F8FAFC;border:1px solid var(--border);border-radius:12px;padding:16px;transition:border-color .2s,box-shadow .2s}
.hall-card:hover{border-color:var(--border-accent);box-shadow:0 6px 20px rgba(15,118,110,.08)}
.hc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px}
.hc-name{font-size:.92rem;font-weight:700;color:var(--text)}
.hc-code{font-size:.68rem;color:var(--muted);font-family:var(--mono);margin-top:2px}
.hc-cap{font-size:1.4rem;font-weight:800;color:var(--teal);font-family:var(--mono)}
.hc-cap-lbl{font-size:.62rem;color:var(--muted)}
.util-bar{height:6px;background:rgba(15,118,110,.08);border-radius:3px;overflow:hidden;margin-top:8px}
.util-fill{height:100%;border-radius:3px;background:var(--teal);transition:width .5s}
.util-fill.warn{background:var(--amber-acc)}
.util-fill.full{background:var(--red)}
.hc-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}
.hc-feature{font-size:.68rem;color:var(--muted);display:flex;align-items:center;gap:4px}
.hc-feature.yes{color:var(--success)}
.hc-feature i{font-size:.6rem}
.hc-bookings{margin-top:8px;font-size:.7rem;color:var(--muted)}
.booking-pill{display:inline-block;background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--teal);padding:2px 7px;border-radius:5px;font-size:.63rem;margin:2px;font-family:var(--mono);}
/* ── Seating ──────────────────────────────────────────────────────────────── */
.seat-controls{padding:14px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.seat-grid-wrap{padding:18px 20px;overflow-x:auto;background:#F1F5F9;border-radius:8px}
.seat-legend{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.sleg{display:flex;align-items:center;gap:5px;font-size:.7rem;color:var(--muted)}
.sleg-dot{width:12px;height:12px;border-radius:3px}
.seat-grid{display:inline-grid;gap:6px}
.seat{width:52px;height:52px;border-radius:7px;display:flex;flex-direction:column;align-items:center;justify-content:center;border:1px solid var(--border);font-size:.6rem;cursor:pointer;transition:all .18s;position:relative;}
.seat.occupied{background:var(--teal-soft);border-color:var(--border-accent)}
.seat.occupied:hover{background:var(--teal-soft2)}
.seat.empty{background:#EFF6FF;border-color:#94A3B8;border-style:dashed}
.seat.empty:hover{border-color:var(--teal);border-style:solid;background:var(--teal-soft)}
.seat.present{background:var(--green2);border-color:rgba(22,163,74,.3)}
.seat.absent{background:var(--red2);border-color:rgba(220,38,38,.3)}
.seat-no{font-family:var(--mono);font-size:.65rem;font-weight:700;color:var(--text)}
.seat-roll{font-size:.58rem;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:46px;text-align:center}
/* ── Admit card ───────────────────────────────────────────────────────────── */
.admit-card-view{background:var(--teal-soft);border:1px solid var(--border-accent);border-radius:12px;padding:20px;position:relative;overflow:hidden;}
.admit-card-view::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:radial-gradient(circle,rgba(20,184,166,.12),transparent);border-radius:50%;}
.ac-header{display:flex;align-items:center;gap:12px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border-accent)}
.ac-logo{width:42px;height:42px;background:var(--teal-soft2);border-radius:10px;display:grid;place-items:center;font-weight:800;font-size:.9rem;color:var(--teal)}
.ac-college{font-size:.78rem;font-weight:700;color:var(--text)}
.ac-sub{font-size:.65rem;color:var(--muted);margin-top:2px}
.ac-no{font-family:var(--mono);font-size:.7rem;color:var(--teal);margin-left:auto;background:var(--teal-soft2);padding:4px 10px;border-radius:6px;border:1px solid var(--border-accent)}
.ac-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ac-field label{font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:2px}
.ac-field span{font-size:.78rem;color:var(--text);font-weight:500}
/* ── Question bank ────────────────────────────────────────────────────────── */
.qb-grid{display:grid;grid-template-columns:280px 1fr;gap:16px;padding:18px 20px}
.qb-sidebar{background:#F8FAFC;border:1px solid var(--border);border-radius:10px;padding:14px;height:fit-content}
.qb-stat-item{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:.76rem}
.qb-stat-item:last-child{border-bottom:none}
.qb-list{display:flex;flex-direction:column;gap:10px}
.q-card{background:#F8FAFC;border:1px solid var(--border);border-radius:10px;padding:14px 16px;transition:border-color .18s,box-shadow .18s}
.q-card:hover{border-color:var(--border-accent);box-shadow:0 4px 12px rgba(15,118,110,.06)}
.q-text{font-size:.82rem;color:var(--text);margin-bottom:8px;line-height:1.5}
.q-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.q-opts{display:flex;flex-direction:column;gap:4px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border)}
.q-opt{font-size:.74rem;color:var(--muted);display:flex;align-items:center;gap:6px;padding:3px 0}
.q-opt.correct{color:var(--success)}
.q-opt i{font-size:.65rem}
/* ── Blueprint ────────────────────────────────────────────────────────────── */
.bp-summary{display:flex;gap:10px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border)}
.bp-sum-box{background:var(--teal-soft);border:1px solid var(--border);border-radius:8px;padding:10px 16px;text-align:center;min-width:90px}
.bp-sum-val{font-size:1.1rem;font-weight:800;font-family:var(--mono);color:var(--text)}
.bp-sum-lbl{font-size:.64rem;color:var(--muted);margin-top:2px}
.bp-body{display:grid;grid-template-columns:1fr 320px;gap:16px;padding:18px 20px}
.bp-sections{display:flex;flex-direction:column;gap:10px}
.bp-row{background:#F8FAFC;border:1px solid var(--border);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.bp-info{flex:1;min-width:0}
.bp-sec-name{font-size:.84rem;font-weight:700;color:var(--text)}
.bp-sec-sub{font-size:.7rem;color:var(--muted);margin-top:3px;font-family:var(--mono)}
.bp-bar-wrap{flex:1;max-width:120px}
.bp-bar{height:6px;background:rgba(15,118,110,.08);border-radius:3px;overflow:hidden}
.bp-fill{height:100%;border-radius:3px;background:var(--teal)}
.bp-marks{font-family:var(--mono);font-size:.82rem;color:var(--teal);font-weight:700;min-width:40px;text-align:right}
.bp-pct{font-size:.68rem;color:var(--muted);min-width:36px;text-align:right}
.bloom-tag{font-size:.6rem;padding:2px 6px;border-radius:4px;background:var(--purple2);color:var(--purple);font-family:var(--mono)}
/* ── Modal ────────────────────────────────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:200;
  display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px;}
.modal-overlay.open{opacity:1;pointer-events:auto}
.modal{background:#fff;border:1px solid var(--border);border-radius:16px;width:100%;max-width:520px;
  max-height:90vh;overflow-y:auto;transform:translateY(16px);transition:transform .25s;box-shadow:0 28px 60px rgba(15,118,110,.15);}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-wide{max-width:680px}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--border);background:#F0FDFA}
.modal-title{font-size:.95rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.modal-title i{color:var(--teal)}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px;transition:color .18s}
.modal-close:hover{color:var(--red)}
.modal-body{padding:22px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.single{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.form-control{background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text);
  font-size:.82rem;padding:9px 12px;outline:none;font-family:var(--font);transition:border-color .18s;width:100%;}
.form-control:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.08)}
.form-control option{background:#fff;color:var(--text)}
textarea.form-control{resize:vertical;min-height:80px}
.modal-foot{display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--border);margin-top:14px}
/* ── Toast ────────────────────────────────────────────────────────────────── */
.toast{position:fixed;bottom:24px;right:24px;z-index:500;display:flex;flex-direction:column;gap:8px}
.toast-item{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;
  font-size:.8rem;display:flex;align-items:center;gap:8px;transform:translateX(110%);transition:transform .3s;max-width:320px;box-shadow:0 8px 24px rgba(15,118,110,.10);}
.toast-item.show{transform:translateX(0)}
.toast-item.success{border-color:rgba(22,163,74,.35)}
.toast-item.success i{color:var(--success)}
.toast-item.error{border-color:rgba(220,38,38,.35)}
.toast-item.error i{color:var(--red)}
.toast-item.info{border-color:var(--border-accent)}
.toast-item.info i{color:var(--teal)}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}
.spinner{width:32px;height:32px;border:2px solid var(--border);border-top-color:var(--teal);border-radius:50%;animation:spin .7s linear infinite;margin:40px auto;}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-wrap{padding:32px;text-align:center;color:var(--muted);font-size:.8rem}
.loading-wrap p{margin-top:10px}
@media(max-width:1200px){.stats{grid-template-columns:repeat(4,1fr)}.qb-grid{grid-template-columns:1fr}.bp-body{grid-template-columns:1fr}}
@media(max-width:900px){.week-grid{grid-template-columns:repeat(3,1fr)}.cc-sides{grid-template-columns:1fr}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}.content{padding:14px 12px}
  .stats{grid-template-columns:repeat(2,1fr)}.hamburger{display:grid}
  .tab-btn span{display:none}.tab-btn{padding:9px 10px}
  .exam-grid{grid-template-columns:1fr}.hall-grid{grid-template-columns:1fr}
  .form-row{grid-template-columns:1fr}
}
@media(max-width:480px){.stats{grid-template-columns:repeat(2,1fr)}}
/* ── SA Overview cards ────────────────────────────────────────────────────── */
.sa-exam-card{background:#F8FAFC;border:1px solid var(--border);border-radius:14px;padding:18px;transition:border-color .2s,transform .2s,box-shadow .2s;position:relative;overflow:hidden}
.sa-exam-card:hover{border-color:rgba(245,158,11,.35);transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.08)}
.sa-exam-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--amber-acc),var(--teal));opacity:.7}
.sa-crown-badge{display:inline-flex;align-items:center;gap:4px;font-size:.62rem;font-weight:700;background:var(--amber-soft);color:var(--amber-acc);padding:3px 8px;border-radius:6px;border:1px solid rgba(245,158,11,.25)}
.sa-exam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px;padding:18px 20px}
.sa-detail-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
.sa-chip{font-size:.68rem;padding:3px 9px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;font-family:var(--mono)}
.sa-chip.hall{background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent)}
.sa-chip.invigs{background:var(--purple2);color:var(--purple);border:1px solid rgba(124,58,237,.2)}
.sa-chip.admits{background:var(--green2);color:var(--success);border:1px solid rgba(22,163,74,.2)}
.sa-chip.nodata{background:#F1F5F9;color:var(--muted);border:1px solid var(--border)}
.sa-action-bar{display:flex;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
/* ── 2D Seat Grid ─────────────────────────────────────────────────────────── */
.room-2d-wrap{overflow-x:auto;padding:20px;background:#F1F5F9;border-radius:8px}
.room-header{background:var(--teal-soft);border:1px solid var(--border-accent);border-radius:10px;padding:10px 16px;margin-bottom:16px;font-size:.78rem;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.room-header strong{color:var(--teal);font-size:.9rem}
.room-header span{color:var(--muted)}
.room-board{font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--teal);background:var(--teal-soft);border:1px solid var(--border-accent);padding:8px 24px;border-radius:8px;text-align:center;margin-bottom:20px;width:100%}
.seat-grid-2d{display:grid;gap:6px;width:max-content}
.seat-row-label{font-size:.6rem;color:var(--muted);font-family:var(--mono);display:flex;align-items:center;justify-content:center;width:22px;font-weight:700}
.seat-col-labels{display:flex;gap:6px;margin-left:28px;margin-bottom:2px}
.seat-col-label{width:54px;text-align:center;font-size:.58rem;color:var(--muted);font-family:var(--mono);font-weight:700}
.seat-row{display:flex;gap:6px;align-items:center}
.seat2d{width:54px;height:54px;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;border:1.5px solid #CBD5E1;font-size:.6rem;transition:all .18s;position:relative;cursor:default}
.seat2d.occupied{background:var(--teal-soft);border-color:var(--border-accent)}
.seat2d.occupied:hover{background:var(--teal-soft2);border-color:var(--teal);transform:scale(1.08);z-index:5}
.seat2d.present{background:var(--green2);border-color:rgba(22,163,74,.3)}
.seat2d.absent{background:var(--red2);border-color:rgba(220,38,38,.3)}
.seat2d.empty{background:#EFF6FF;border-color:#94A3B8;border-style:dashed}
.seat2d-no{font-family:var(--mono);font-size:.67rem;font-weight:700;color:var(--text);line-height:1}
.seat2d-roll{font-size:.56rem;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:48px;text-align:center}
.seat2d[data-tooltip]{position:relative}
.seat2d[data-tooltip]:hover::after{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#fff;border:1px solid var(--border);color:var(--text);font-size:.65rem;padding:5px 10px;border-radius:7px;white-space:nowrap;z-index:20;pointer-events:none;box-shadow:0 4px 12px rgba(15,118,110,.10);font-family:var(--font);line-height:1.5}
.seat2d[data-tooltip]:hover::before{content:'';position:absolute;bottom:calc(100% + 2px);left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:var(--border);z-index:21}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="shell">

<!-- ══ SIDEBAR ═══════════════════════════════════════════════════════════════ -->
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
      <div class="sc-college"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
        <?php if ($deptName): ?>
          <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($deptName) ?></span>
        <?php else: ?>
          <span class="sc-dept-tag"><i class="fas fa-building-columns"></i><?= esc($collegeName) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard"><i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span></a>
      <a href="students.php"  class="nav-item" data-tip="Students"><i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span></a>
      <div class="nav-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance"><i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span></a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results"><i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
        <?php if($pendingMarks): ?><span class="nav-badge red"><?= $pendingMarks ?></span><?php endif; ?></a>
      <a href="examinations.php" class="nav-item active" data-tip="Examinations"><i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
        <?php if($upcomingExams): ?><span class="nav-badge amber"><?= $upcomingExams ?></span><?php endif; ?>
        <?php if(($pendingApprovalsCount??0)>0): ?><span class="nav-badge red" title="Pending Q.Paper approvals"><?= $pendingApprovalsCount ?></span><?php endif; ?></a>
      <a href="timetable.php" class="nav-item" data-tip="Timetable"><i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span></a>
      <div class="nav-label">Communication</div>
      <a href="staff_noticeboard.php" class="nav-item" data-tip="Staff Noticeboard"><i class="fas fa-clipboard-list"></i><span class="nav-text"> Staff Noticeboard</span></a>
      <a href="leave_application.php" class="nav-item" data-tip="Leave Application"><i class="fas fa-calendar-minus"></i><span class="nav-text"> Leave Application</span></a>
      <a href="expense_apply.php" class="nav-item" data-tip="Expense Apply"><i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span></a>
      <div class="nav-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile"><i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span></a>
    </nav>
  </div><!-- /sidebar-scroll -->
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= esc(ucfirst($role)) ?> · <?= esc($deptCode) ?></div>
    </div>
    <button class="logout-btn" onclick="doLogout()" title="Logout"><i class="fas fa-arrow-right-from-bracket"></i></button>
  </div>
</aside>

<!-- ══ MAIN ═══════════════════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="menuToggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="topbar-title">Examinations</div>
    <div class="topbar-actions">
      <div class="date-chip" id="topbarDate"></div>
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
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= esc(ucfirst($role)) ?></div>
          </div>
          <a href="profile.php" class="ad-item"><i class="fas fa-circle-user"></i> My Profile</a>
          <a href="settings.php" class="ad-item"><i class="fas fa-gear"></i> Settings</a>
          <div class="ad-sep"></div>
          <button class="ad-item danger" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Scope banner -->

    <?php if ($role === 'faculty'): ?>
    <?php if (!empty($assignedCourseIds)): ?>
      <?php
        $ph2 = implode(',', array_fill(0, count($assignedCourseIds), '?'));
        $cs2 = $db->prepare("SELECT c.name, c.code, c.semester FROM courses c WHERE c.id IN ($ph2) AND c.college_id=? ORDER BY c.semester, c.name");
        $cs2->execute(array_merge($assignedCourseIds, [$collegeId]));
        $assignedCourseNames = $cs2->fetchAll(PDO::FETCH_ASSOC);
      ?>
      <div style="display:flex;align-items:flex-start;flex-wrap:wrap;gap:8px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.28);border-radius:10px;padding:11px 16px;margin-bottom:12px">
        <i class="fas fa-chalkboard-teacher" style="color:var(--teal);margin-top:2px;flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <div style="font-size:.78rem;font-weight:700;color:var(--teal);margin-bottom:6px">HOD-Granted Access &mdash; Exams &amp; Question Papers restricted to:</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach ($assignedCourseNames as $ac): ?>
            <span style="background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.3);color:var(--teal);padding:3px 10px;border-radius:6px;font-size:.74rem;font-weight:600;font-family:var(--mono)">
              <?= esc($ac['code']) ?> &mdash; <?= esc($ac['name']) ?> <span style="opacity:.65">(Sem <?= (int)$ac['semester'] ?>)</span>
            </span>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div style="display:flex;align-items:center;gap:10px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.30);border-radius:10px;padding:12px 16px;margin-bottom:12px">
        <i class="fas fa-lock" style="color:#f59e0b;font-size:1.1rem;flex-shrink:0"></i>
        <div>
          <div style="font-size:.82rem;font-weight:700;color:#b45309">No Course Access Granted</div>
          <div style="font-size:.75rem;color:var(--muted);margin-top:3px">Your HOD has not assigned you to any course yet. Contact your HOD to grant access via <strong>Assign Faculty</strong> in the HOD Dashboard.</div>
        </div>
      </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- College strip -->

    <!-- ════════ TAB BAR ════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;gap:6px;padding:10px 24px;margin:0 -24px;border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:#fff;flex-wrap:wrap;">
      <button class="tab-btn active" id="tabExams"    onclick="switchTab('exams')">
        <i class="fas fa-file-invoice"></i> <span>Exams</span>
      </button>
      <button class="tab-btn"         id="tabQPapers" onclick="switchTab('qpapers')">
        <i class="fas fa-scroll"></i> <span>Question Papers</span>
        <?php if(($pendingApprovalsCount??0)>0): ?>
          <span class="nav-badge red"><?= $pendingApprovalsCount ?></span>
        <?php endif; ?>
      </button>
      <div style="margin-left:auto;display:flex;gap:6px;">
        <!-- buttons hidden but IDs kept for JS compatibility -->
        <span id="tabCreateLabel" style="display:none">Create Exam</span>
      </div>
    </div>

    <!-- ════════ EXAM VIEW ════════════════════════════════════════════ -->
    <div id="examView">
      <!-- Filter bar -->
      <div class="filter-bar" style="margin:0 -24px;border-bottom:1px solid var(--border);padding:12px 24px">
        <input type="text" class="filter-input" id="examSearch" placeholder="Search exam or course…" oninput="debounce(loadExamView,350)()">
        <select class="filter-sel" id="examTypeFilter" onchange="loadExamView()">
          <option value="">All Types</option>
          <option value="unit_test">Unit Test</option>
          <option value="mid_term">Mid Term</option>
          <option value="final">Final</option>
          <option value="practical">Practical</option>
          <option value="assignment">Assignment</option>
          <option value="viva">Viva</option>
        </select>
        <select class="filter-sel" id="examStatusFilter" onchange="loadExamView()">
          <option value="">All Status</option>
          <option value="upcoming">Upcoming</option>
          <option value="ongoing">Ongoing</option>
          <option value="completed">Completed</option>
          <option value="draft">Draft</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="loadExamView()" style="display:none"><i class="fas fa-rotate-right"></i> Refresh</button>
      </div>
      <!-- Exam cards rendered here -->
      <div id="examCardsWrap" style="padding:20px 0">
        <div class="loading-wrap"><div class="spinner"></div><p>Loading exams…</p></div>
      </div>
    </div><!-- /examView -->

    <!-- ════════ QUESTION PAPERS VIEW ════════════════════════════════ -->
    <div id="qpapersView" style="display:none">
      <!-- Filter bar -->
      <div class="filter-bar" style="margin:0 -24px;border-bottom:1px solid var(--border);padding:12px 24px">
        <input type="text" class="filter-input" id="qpSearch" placeholder="Search paper title or exam…"
               oninput="filterQPapersTable()">
        <select class="filter-sel" id="qpStatusFilter" onchange="filterQPapersTable()">
          <option value="">All Status</option>
          <option value="draft">Draft</option>
          <option value="pending_approval">Pending Approval</option>
          <option value="approved">Approved</option>
          <option value="released">Released</option>
          <option value="archived">Archived</option>
        </select>
      </div>
      <!-- Papers table rendered here -->
      <div id="qpapersWrap" style="padding:20px 0">
        <div class="loading-wrap"><div class="spinner"></div><p>Loading papers…</p></div>
      </div>
    </div><!-- /qpapersView -->

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ═══════════════════ MODALS ═══════════════════════════════════════════════ -->

<!-- Create Exam -->
<div class="modal-overlay" id="createModal">
  <div class="modal modal-wide">
    <div class="modal-hd"><div class="modal-title"><i class="fas fa-file-invoice"></i> Create New Exam</div><button class="modal-close" onclick="closeModal('createModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group form-row single"><label>Exam Title</label><input type="text" class="form-control" id="newExamTitle" placeholder="e.g. Data Structures – Unit Test 1"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Course</label><select class="form-control" id="newExamCourse"><option value="">Loading…</option></select></div>
        <div class="form-group"><label>Type</label>
          <select class="form-control" id="newExamType">
            <option value="unit_test">Unit Test</option><option value="mid_term">Mid Term</option>
            <option value="final">Final</option><option value="practical">Practical</option>
            <option value="assignment">Assignment</option><option value="project">Project</option><option value="viva">Viva</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Max Marks</label><input type="number" class="form-control" id="newExamMax" value="100" min="1"></div>
        <div class="form-group"><label>Pass Marks</label><input type="number" class="form-control" id="newExamPass" value="40" min="1"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Date</label><input type="date" class="form-control" id="newExamDate" min="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label>Semester</label><input type="number" class="form-control" id="newExamSem" value="3" min="1" max="8"></div>
        <div class="form-group"><label>Academic Year</label><input type="text" class="form-control" id="newExamYear" value="2025-26"></div>
      </div>
      <div style="background:rgba(0,212,187,.04);border:1px solid rgba(0,212,187,.12);border-radius:10px;padding:14px;margin-top:4px">
        <div style="font-size:.72rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Schedule (optional)</div>
        <div class="form-row">
          <div class="form-group"><label>Hall</label><select class="form-control" id="newExamHall"><option value="">No hall yet</option></select></div>
          <div class="form-group"><label>Start Time</label><input type="time" class="form-control" id="newExamStart"></div>
          <div class="form-group"><label>End Time</label><input type="time" class="form-control" id="newExamEnd"></div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('createModal')">Cancel</button>
        <button class="btn btn-teal" onclick="createExam()"><i class="fas fa-check"></i> Create Exam</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Exam -->
<div class="modal-overlay" id="editModal">
  <div class="modal modal-wide">
    <div class="modal-hd"><div class="modal-title"><i class="fas fa-pen"></i> Edit Exam</div><button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="editExamId"><input type="hidden" id="editSchedId">
      <div class="form-row single"><div class="form-group"><label>Title</label><input type="text" class="form-control" id="editTitle"></div></div>
      <div class="form-row">
        <div class="form-group"><label>Type</label>
          <select class="form-control" id="editType">
            <option value="unit_test">Unit Test</option><option value="mid_term">Mid Term</option>
            <option value="final">Final</option><option value="practical">Practical</option>
            <option value="assignment">Assignment</option><option value="project">Project</option><option value="viva">Viva</option>
          </select>
        </div>
        <div class="form-group"><label>Status</label>
          <select class="form-control" id="editStatus">
            <option value="draft">Draft</option><option value="upcoming">Upcoming</option>
            <option value="ongoing">Ongoing</option><option value="completed">Completed</option>
            <option value="published">Published</option><option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Max Marks</label><input type="number" class="form-control" id="editMax"></div>
        <div class="form-group"><label>Pass Marks</label><input type="number" class="form-control" id="editPass"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Exam Date</label><input type="date" class="form-control" id="editDate"></div>
        <div class="form-group"><label>Start</label><input type="time" class="form-control" id="editStart"></div>
        <div class="form-group"><label>End</label><input type="time" class="form-control" id="editEnd"></div>
      </div>
      <div class="form-row single"><div class="form-group"><label>Hall</label><select class="form-control" id="editHall"></select></div></div>
      <div class="form-row single"><div class="form-group"><label>Remarks</label><textarea class="form-control" id="editRemarks" rows="2"></textarea></div></div>
      <div class="modal-foot">
        <button class="btn btn-red" onclick="deleteExam()"><i class="fas fa-trash"></i> Delete</button>
        <button class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
        <button class="btn btn-teal" onclick="saveExam()"><i class="fas fa-check"></i> Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Create Question Paper -->
<div class="modal-overlay" id="qpaperModal">
  <div class="modal" style="max-width:780px;width:96vw">
    <div class="modal-hd">
      <div class="modal-title"><i class="fas fa-scroll"></i> Create Question Paper</div>
      <button class="modal-close" onclick="closeModal('qpaperModal')"><i class="fas fa-times"></i></button>
    </div>

    <!-- Step indicator -->
    <div id="qpStepBar" style="display:flex;gap:0;border-bottom:1px solid var(--border)">
      <div class="qp-step active" id="qpStep1Btn" style="flex:1;padding:10px 0;text-align:center;font-size:.78rem;font-weight:700;cursor:default;border-bottom:3px solid var(--teal);color:var(--teal)">
        <i class="fas fa-circle-1"></i> 1. Paper Details
      </div>
      <div class="qp-step" id="qpStep2Btn" style="flex:1;padding:10px 0;text-align:center;font-size:.78rem;font-weight:700;cursor:default;border-bottom:3px solid transparent;color:var(--muted)">
        <i class="fas fa-circle-2"></i> 2. Sections &amp; Questions
      </div>
    </div>

    <div class="modal-body" style="max-height:72vh;overflow-y:auto">

      <!-- ══ STEP 1: Paper Details ══════════════════════════════════════ -->
      <div id="qpStep1">
        <div class="form-row single"><div class="form-group">
          <label>Exam</label>
          <select class="form-control" id="qpExam" onchange="qpOnExamChange()"></select>
        </div></div>
        <div class="form-row single"><div class="form-group"><label>Paper Title</label><input type="text" class="form-control" id="qpTitle" placeholder="e.g. DBMS – Mid Term — Question Paper"></div></div>
        <div class="form-row">
          <div class="form-group">
            <label>Set Version <span style="font-size:.7rem;color:var(--muted);font-weight:400">(A, B, C, D… or any label)</span></label>
            <input type="text" class="form-control" id="qpVersion" value="A" maxlength="10"
                   placeholder="e.g. A, B, C, D, 1, 2…"
                   oninput="qpCheckVersionConflict()">
            <div id="qpVersionHint" style="font-size:.72rem;margin-top:5px;min-height:16px;line-height:1.5"></div>
          </div>
          <div class="form-group"><label>Total Marks</label><input type="number" class="form-control" id="qpMarks" value="100" min="1"></div>
        </div>
        <div class="form-row single"><div class="form-group">
          <label>Instructions <span style="font-size:.72rem;color:var(--muted)">(one per line — shown as numbered list on paper)</span></label>
          <textarea class="form-control" id="qpInstructions" rows="3" placeholder="Attempt all questions.&#10;All questions carry equal marks.&#10;Duration: 3 hours."></textarea>
        </div></div>
        <div class="modal-foot" style="border-top:none;padding-top:0">
          <button class="btn btn-ghost" onclick="closeModal('qpaperModal')">Cancel</button>
          <button class="btn btn-teal" onclick="qpGoStep2()"><i class="fas fa-arrow-right"></i> Next: Add Questions</button>
        </div>
      </div>

      <!-- ══ STEP 2: Sections & Questions ══════════════════════════════ -->
      <div id="qpStep2" style="display:none">

        <!-- Summary bar -->
        <div id="qpSummaryBar" style="background:var(--card);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;gap:18px;flex-wrap:wrap;font-size:.78rem">
          <span><i class="fas fa-file-lines" style="color:var(--teal)"></i> <span id="qpSumTitle" style="font-weight:700"></span></span>
          <span><i class="fas fa-tag" style="color:var(--teal)"></i> Set <span id="qpSumVersion"></span></span>
          <span><i class="fas fa-star" style="color:var(--teal)"></i> Total: <span id="qpSumMarks" style="font-weight:700"></span> marks</span>
          <span id="qpMarkAlert" style="color:#ef4444;font-weight:700;display:none"><i class="fas fa-triangle-exclamation"></i> Marks mismatch!</span>
        </div>

        <!-- Sections container -->
        <div id="qpSectionsWrap"></div>

        <!-- Add section button -->
        <button class="btn btn-ghost btn-sm" onclick="qpAddSection()" style="margin:6px 0 14px;width:100%">
          <i class="fas fa-plus"></i> Add Section
        </button>

        <div class="modal-foot" style="border-top:1px solid var(--border);padding-top:12px">
          <button class="btn btn-ghost" onclick="qpGoStep1()"><i class="fas fa-arrow-left"></i> Back</button>
          <span id="qpCreateStatus" style="font-size:.78rem;color:var(--muted)"></span>
          <button class="btn btn-teal" onclick="createQPaper()"><i class="fas fa-check"></i> Create Paper</button>
        </div>
      </div>

    </div>
  </div>
  </div>
</div>

<!-- Upload Paper File (for existing papers) -->
<div class="modal-overlay" id="uploadPaperModal">
  <div class="modal">
    <div class="modal-hd"><div class="modal-title"><i class="fas fa-upload"></i> Upload Paper File</div><button class="modal-close" onclick="closeModal('uploadPaperModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="uploadPaperId">
      <div class="scope-banner" style="margin:0 0 14px;font-size:.75rem;padding:10px 14px;">
        <i class="fas fa-info-circle" style="color:var(--teal)"></i>
        <span>Attach the final question paper file. Supported formats: PDF, DOCX, JPG, PNG.</span>
      </div>
      <div class="form-group">
        <label>Paper Title</label>
        <div id="uploadPaperTitle" style="font-weight:600;color:var(--white);padding:6px 0;font-size:.9rem"></div>
      </div>
      <div class="form-group">
        <label>Select File <span style="font-size:.72rem;color:var(--muted)">(PDF / DOCX / Image)</span></label>
        <input type="file" class="form-control" id="uploadPaperFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
      </div>
      <div id="uploadPaperProgress" style="display:none;margin-top:8px">
        <div style="height:6px;background:var(--border);border-radius:4px;overflow:hidden">
          <div id="uploadPaperBar" style="height:100%;width:0%;background:var(--teal);border-radius:4px;transition:width .3s"></div>
        </div>
        <div id="uploadPaperMsg" style="font-size:.75rem;color:var(--muted);margin-top:4px">Uploading…</div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('uploadPaperModal')">Cancel</button>
        <button class="btn btn-teal" onclick="uploadPaperFile()"><i class="fas fa-upload"></i> Upload</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Question Paper -->
<div class="modal-overlay" id="editQPaperModal">
  <div class="modal" style="max-width:860px;width:97vw">
    <div class="modal-hd">
      <div class="modal-title"><i class="fas fa-pen-to-square"></i> Edit Question Paper</div>
      <button class="modal-close" onclick="closeModal('editQPaperModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="max-height:80vh;overflow-y:auto;padding:0">

      <!-- Step tabs -->
      <div style="display:flex;border-bottom:1px solid var(--border)">
        <div id="eqpTab1" onclick="eqpShowTab(1)"
             style="flex:1;padding:10px 0;text-align:center;font-size:.78rem;font-weight:700;cursor:pointer;border-bottom:3px solid var(--teal);color:var(--teal)">
          <i class="fas fa-file-lines"></i> Paper Details
        </div>
        <div id="eqpTab2" onclick="eqpShowTab(2)"
             style="flex:1;padding:10px 0;text-align:center;font-size:.78rem;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;color:var(--muted)">
          <i class="fas fa-list-ol"></i> Sections &amp; Questions
        </div>
      </div>

      <div style="padding:18px 20px">

        <!-- ── TAB 1: Paper Details ── -->
        <div id="eqpPane1">
          <input type="hidden" id="eqpId">
          <div class="form-row single">
            <div class="form-group">
              <label>Paper Title</label>
              <input type="text" class="form-control" id="eqpTitle" placeholder="e.g. DBMS – Mid Term Question Paper">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Set Version</label>
              <select class="form-control" id="eqpVersion">
                <option value="A">Set A</option><option value="B">Set B</option>
                <option value="C">Set C</option><option value="D">Set D</option><option value="E">Set E</option>
              </select>
            </div>
            <div class="form-group">
              <label>Total Marks</label>
              <input type="number" class="form-control" id="eqpMarks" min="1" value="100" oninput="eqpUpdateSummary()">
            </div>
          </div>
          <div class="form-row single">
            <div class="form-group">
              <label>Instructions <span style="color:var(--muted);font-weight:400;font-size:.75rem">(optional — one per line)</span></label>
              <textarea class="form-control" id="eqpInstructions" rows="3" placeholder="Attempt all questions.&#10;All questions carry equal marks."></textarea>
            </div>
          </div>
          <!-- Marks summary -->
          <div style="background:rgba(0,212,187,.05);border:1px solid rgba(0,212,187,.15);border-radius:8px;padding:10px 14px;font-size:.78rem;display:flex;gap:18px;flex-wrap:wrap;align-items:center">
            <span><i class="fas fa-star" style="color:var(--teal)"></i> Declared total: <strong id="eqpSumDeclared" style="color:var(--white)">—</strong></span>
            <span><i class="fas fa-layer-group" style="color:var(--teal)"></i> Section total: <strong id="eqpSumSection" style="color:var(--white)">—</strong></span>
            <span id="eqpMarkAlert" style="display:none;color:#ef4444;font-weight:700"><i class="fas fa-triangle-exclamation"></i> Marks mismatch!</span>
          </div>
        </div>

        <!-- ── TAB 2: Sections & Questions ── -->
        <div id="eqpPane2" style="display:none">
          <div id="eqpSectionsWrap"></div>
          <button class="btn btn-ghost btn-sm" onclick="eqpAddSection()" style="margin:6px 0 10px;width:100%">
            <i class="fas fa-plus"></i> Add Section
          </button>
        </div>

        <!-- Error banner (shared) -->
        <div id="eqpError" style="display:none;margin-top:12px;padding:9px 12px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:8px;font-size:.8rem;color:#e76f51;font-weight:600">
          <i class="fas fa-triangle-exclamation"></i> <span id="eqpErrorMsg"></span>
        </div>
      </div>

      <!-- Footer -->
      <div class="modal-foot" style="justify-content:space-between;border-top:1px solid var(--border);padding:12px 20px">
        <button class="btn btn-red" onclick="eqpDelete()"><i class="fas fa-trash"></i> Delete Paper</button>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost" onclick="closeModal('editQPaperModal')">Cancel</button>
          <button class="btn btn-teal" id="eqpSaveBtn" onclick="eqpSave()"><i class="fas fa-check"></i> Save Changes</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Question -->
<div class="modal-overlay" id="addQModal">
  <div class="modal modal-wide">
    <div class="modal-hd"><div class="modal-title"><i class="fas fa-plus"></i> Add Question</div><button class="modal-close" onclick="closeModal('addQModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <div class="form-row single"><div class="form-group"><label>Course / Subject</label><select class="form-control" id="qCourse"><option value="">Loading…</option></select></div></div>
      <div class="form-row single"><div class="form-group"><label>Question Text</label><textarea class="form-control" id="qText" rows="3" placeholder="Enter the question…"></textarea></div></div>
      <div class="form-row">
        <div class="form-group"><label>Type</label>
          <select class="form-control" id="qType" onchange="toggleQuestionFields()">
            <optgroup label="Objective">
              <option value="mcq">Multiple Choice (MCQ)</option>
              <option value="true_false">True / False</option>
              <option value="fill_blank">Fill in the Blanks</option>
              <option value="match">Matching Questions</option>
              <option value="assertion_reason">Assertion &amp; Reason</option>
              <option value="objective">Objective</option>
            </optgroup>
            <optgroup label="Short / Medium">
              <option value="very_short">Very Short Answer</option>
              <option value="short">Short Answer</option>
              <option value="open_ended">Open-Ended</option>
            </optgroup>
            <optgroup label="Long / Applied">
              <option value="long">Long Answer</option>
              <option value="descriptive">Descriptive</option>
              <option value="problem_solving">Problem-Solving</option>
              <option value="case_study">Case Study</option>
              <option value="diagram">Diagram-Based</option>
              <option value="subjective">Subjective</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group"><label>Difficulty</label>
          <select class="form-control" id="qDiff">
            <option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option>
          </select>
        </div>
        <div class="form-group"><label>Marks</label><input type="number" class="form-control" id="qMarks" value="1" min="1" max="20"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Topic</label><input type="text" class="form-control" id="qTopic" placeholder="e.g. Searching Algorithms"></div>
        <div class="form-group"><label>Bloom's Level</label>
          <select class="form-control" id="qBloom">
            <option value="remember">Remember</option><option value="understand">Understand</option>
            <option value="apply">Apply</option><option value="analyse">Analyse</option>
            <option value="evaluate">Evaluate</option><option value="create">Create</option>
          </select>
        </div>
      </div>
      <!-- ── Dynamic question-type fields ── -->
      <div id="qDynFields" style="margin-top:4px">

        <!-- MCQ options -->
        <div id="qf-mcq" class="qf-panel" style="display:none">
          <label class="qf-label">Options <span style="color:var(--teal)">(✓ = correct answer)</span></label>
          <div id="mcqOptions"></div>
          <button class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="addMCQOption()"><i class="fas fa-plus"></i> Add Option</button>
        </div>

        <!-- True / False -->
        <div id="qf-true_false" class="qf-panel" style="display:none">
          <label class="qf-label">Correct Answer</label>
          <div style="display:flex;gap:12px;margin-top:6px">
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer">
              <input type="radio" name="tfAnswer" value="true" style="accent-color:var(--teal)"> True
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer">
              <input type="radio" name="tfAnswer" value="false" style="accent-color:var(--red)"> False
            </label>
          </div>
        </div>

        <!-- Fill in the Blanks -->
        <div id="qf-fill_blank" class="qf-panel" style="display:none">
          <label class="qf-label">Blank Answer(s) <span style="color:var(--muted);font-weight:400">(one answer per line)</span></label>
          <textarea class="form-control" id="fillBlankAnswers" rows="2" placeholder="e.g.
Stack
Queue"></textarea>
          <div style="font-size:.68rem;color:var(--muted);margin-top:4px"><i class="fas fa-info-circle"></i> Use ___ in question text to mark blank positions.</div>
        </div>

        <!-- Match the Following -->
        <div id="qf-match" class="qf-panel" style="display:none">
          <label class="qf-label">Match Pairs <span style="color:var(--muted);font-weight:400">(Left column → Right column)</span></label>
          <div id="matchPairs"></div>
          <button class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="addMatchPair()"><i class="fas fa-plus"></i> Add Pair</button>
        </div>

        <!-- Assertion & Reason -->
        <div id="qf-assertion_reason" class="qf-panel" style="display:none">
          <div class="form-row single" style="margin-bottom:8px">
            <div class="form-group"><label class="qf-label">Assertion (A)</label>
              <textarea class="form-control" id="arAssertion" rows="2" placeholder="Assertion statement…"></textarea>
            </div>
          </div>
          <div class="form-row single" style="margin-bottom:8px">
            <div class="form-group"><label class="qf-label">Reason (R)</label>
              <textarea class="form-control" id="arReason" rows="2" placeholder="Reason statement…"></textarea>
            </div>
          </div>
          <label class="qf-label">Correct Code</label>
          <select class="form-control" id="arCode" style="margin-top:4px">
            <option value="A">A – Both A and R are true, R is the correct explanation</option>
            <option value="B">B – Both A and R are true, R is NOT the correct explanation</option>
            <option value="C">C – A is true but R is false</option>
            <option value="D">D – A is false but R is true</option>
            <option value="E">E – Both A and R are false</option>
          </select>
        </div>

        <!-- Diagram-Based -->
        <div id="qf-diagram" class="qf-panel" style="display:none">
          <label class="qf-label">Diagram Description / Reference</label>
          <textarea class="form-control" id="diagramDesc" rows="2" placeholder="Describe the diagram or paste a reference label (e.g. 'Refer to Fig. 2.3')…"></textarea>
          <label class="qf-label" style="margin-top:8px">Expected Answer / Labels</label>
          <textarea class="form-control" id="diagramAnswer" rows="2" placeholder="Expected labels or answer key…"></textarea>
        </div>

        <!-- Short / Long / Descriptive / Problem / Case Study / Open-Ended / Very Short / Subjective / Objective hint -->
        <div id="qf-text" class="qf-panel" style="display:none">
          <label class="qf-label">Answer Hint / Model Answer <span style="color:var(--muted);font-weight:400">(optional)</span></label>
          <textarea class="form-control" id="textHint" rows="3" placeholder="Model answer or marking hints for the examiner…"></textarea>
        </div>

      </div><!-- /qDynFields -->
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('addQModal')">Cancel</button>
        <button class="btn btn-teal" onclick="saveQuestion()"><i class="fas fa-check"></i> Add Question</button>
      </div>
    </div>
  </div>
</div>

<!-- Blueprint Add Section -->
<div class="modal-overlay" id="bpModal">
  <div class="modal">
    <div class="modal-hd"><div class="modal-title"><i class="fas fa-sitemap"></i> Add Blueprint Section</div><button class="modal-close" onclick="closeModal('bpModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <div class="form-row single"><div class="form-group"><label>Section Name</label><input type="text" class="form-control" id="bpSecName" placeholder="e.g. Section A – Objective"></div></div>
      <div class="form-row">
        <div class="form-group"><label>Question Type</label>
          <select class="form-control" id="bpQType">
<option value="mcq">MCQ</option>
              <option value="true_false">True/False</option>
              <option value="fill_blank">Fill in the Blanks</option>
              <option value="match">Matching</option>
              <option value="assertion_reason">Assertion &amp; Reason</option>
              <option value="objective">Objective</option>
              <option value="very_short">Very Short Answer</option>
              <option value="short">Short Answer</option>
              <option value="open_ended">Open-Ended</option>
              <option value="long">Long Answer</option>
              <option value="descriptive">Descriptive</option>
              <option value="problem_solving">Problem-Solving</option>
              <option value="case_study">Case Study</option>
              <option value="diagram">Diagram-Based</option>
              <option value="subjective">Subjective</option>
          </select>
        </div>
        <div class="form-group"><label>Num Questions</label><input type="number" class="form-control" id="bpNumQ" value="5" min="1"></div>
        <div class="form-group"><label>Marks/Question</label><input type="number" class="form-control" id="bpMarksPerQ" value="2" min="0.5" step="0.5"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Topic</label><input type="text" class="form-control" id="bpTopic" placeholder="Optional topic"></div>
        <div class="form-group"><label>Bloom Levels</label><input type="text" class="form-control" id="bpBloom" placeholder="remember,apply"></div>
        <div class="form-group"><label>Compulsory?</label>
          <select class="form-control" id="bpCompulsory"><option value="1">Yes</option><option value="0">No</option></select>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" onclick="closeModal('bpModal')">Cancel</button>
        <button class="btn btn-teal" onclick="addBlueprintSection()"><i class="fas fa-check"></i> Add Section</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast" id="toastContainer"></div>

<script>
// ════════════════════════════════════════════════════════════════
//  EduNexus Examinations – Faculty View
//  Shows assigned exams with 2D hall seating + Create Q.Paper
// ════════════════════════════════════════════════════════════════
const COLLEGE_ID       = <?= $collegeId ?>;
const DEPT_ID          = <?= $deptId ?>;
const USER_ROLE        = <?= json_encode($role) ?>;
// Courses the HOD has assigned to this faculty (integers). Empty = admin sees all.
const ASSIGNED_COURSES = <?= json_encode(array_values(array_map('intval', $assignedCourseIds))) ?>;

// ── API helper ────────────────────────────────────────────────────
async function api(action, extra = '', method = 'GET', body = null) {
    const url = `?ajax=${action}${extra}`;
    const opts = { method, headers: {} };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    try {
        const res = await fetch(url, opts);
        if (!res.ok) {
            console.error(`API Error: ${action} returned ${res.status}`);
            throw new Error(`Server returned ${res.status}: ${res.statusText}`);
        }
        const data = await res.json();
        console.log(`API Success: ${action}`, data);
        return data;
    } catch(e) { 
        console.error(`API Exception for ${action}:`, e); 
        showToast(`Failed to load ${action}: ${e.message}`, 'error');
        return null; 
    }
}

// ── Debounce ──────────────────────────────────────────────────────
function debounce(fn, ms) {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ── Toast ─────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const el = document.createElement('div');
    const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-times-circle' : 'fa-info-circle';
    el.className = `toast-item ${type}`;
    el.innerHTML = `<i class="fas ${icon}"></i><span>${msg}</span>`;
    c.appendChild(el);
    setTimeout(() => el.classList.add('show'), 10);
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 400); }, 3500);
}

// ── Modal helpers ─────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Date clock ───────────────────────────────────────────────────
function tick() {
    const n = new Date();
    const d = document.getElementById('topbarDate');
    if (d) d.textContent = n.toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
}
tick(); setInterval(tick, 30000);

// ── Sidebar toggle ────────────────────────────────────────────────
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

function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  var o=document.getElementById('sidebarOverlay');
  if(o) o.classList.toggle('open');
}
function toggleAvatarMenu(e){
  e.stopPropagation();
  const dd=document.getElementById('avatarDropdown');
  const av=document.getElementById('topbarAvatar');
  if(!dd) return;
  const open=dd.classList.toggle('open');
  if(av) av.classList.toggle('open',open);
}
document.addEventListener('click',function(e){
  const dd=document.getElementById('avatarDropdown');
  const av=document.getElementById('topbarAvatar');
  if(dd&&dd.classList.contains('open')&&!dd.contains(e.target)&&e.target!==av&&!av?.contains(e.target)){
    dd.classList.remove('open');if(av)av.classList.remove('open');
  }
});
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    if (window.innerWidth <= 800 && sb.classList.contains('open') &&
        !sb.contains(e.target) && !document.getElementById('menuToggle').contains(e.target))
        sb.classList.remove('open');
});

// ── Tab switching (no-op stub — only one view now) ────────────────
let currentTab = 'exams';
// ── Active tab tracker ────────────────────────────────────────────
let _activeTab = 'exams';

function switchTab(tab) {
    _activeTab = tab;
    // Toggle views
    document.getElementById('examView').style.display    = (tab === 'exams')    ? '' : 'none';
    document.getElementById('qpapersView').style.display = (tab === 'qpapers')  ? '' : 'none';
    // Toggle tab button active state
    document.getElementById('tabExams').classList.toggle('active',    tab === 'exams');
    document.getElementById('tabQPapers').classList.toggle('active',  tab === 'qpapers');
    // Update create button label
    document.getElementById('tabCreateLabel').textContent =
        tab === 'qpapers' ? 'Create Question Paper' : 'Create Exam';
    // Load data for the newly selected tab
    if (tab === 'exams')    loadExamView();
    if (tab === 'qpapers')  loadQPapers();
}

function handleTabCreate() {
    if (_activeTab === 'qpapers') {
        // Full reset of form + section state
        ['qpTitle','qpInstructions'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
        const qpMarks = document.getElementById('qpMarks'); if (qpMarks) qpMarks.value = '100';
        const qpVersion = document.getElementById('qpVersion'); if (qpVersion) { qpVersion.value = 'A'; qpVersion.style.border = ''; }
        _qpUsedVersions = [];
        const status = document.getElementById('qpCreateStatus'); if (status) { status.textContent=''; }
        // Reset section builder state
        _qpSections = []; _qpSectId = 0;
        if (document.getElementById('qpSectionsWrap')) document.getElementById('qpSectionsWrap').innerHTML = '';
        // Always start on step 1
        qpGoStep1();
        openModal('qpaperModal');
    } else {
        openModal('createModal');
    }
}

function refreshCurrentTab() {
    if (_activeTab === 'qpapers') loadQPapers();
    else loadExamView();
}

// ── Client-side filter for Q.Papers table ─────────────────────────
function filterQPapersTable() {
    const q      = (document.getElementById('qpSearch')?.value || '').toLowerCase();
    const status = document.getElementById('qpStatusFilter')?.value || '';
    const rows   = document.querySelectorAll('#qpapersWrap tbody tr');
    rows.forEach(row => {
        const text   = row.textContent.toLowerCase();
        const sCell  = row.querySelector('[data-status]');
        const rStatus = sCell ? sCell.dataset.status : '';
        const matchQ = !q      || text.includes(q);
        const matchS = !status || rStatus === status;
        row.style.display = (matchQ && matchS) ? '' : 'none';
    });
}

// ═══ SA OVERVIEW ═══════════════════════════════════════════════════
async function loadSAOverview() {
    const wrap = document.getElementById('saOverviewWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading exams…</p></div>';
    try {
        const q      = document.getElementById('saOvSearch')?.value || '';
        const type   = document.getElementById('saOvType')?.value   || '';
        const status = document.getElementById('saOvStatus')?.value || '';
        const data = await api('superadmin_exams', `&q=${encodeURIComponent(q)}&type=${encodeURIComponent(type)}&status=${encodeURIComponent(status)}`);
        if (!data || !data.length) {
            wrap.innerHTML = '<div class="empty-state"><i class="fas fa-crown"></i>No superadmin-created exams found for this department.</div>';
            return;
        }
    const statusColors = {draft:'draft',upcoming:'upcoming',ongoing:'ongoing',completed:'completed',published:'published',cancelled:'cancelled'};
    let html = '<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Exam</th><th>Course</th><th>Type</th><th>Scheduled Date</th><th>Hall</th><th>Time</th><th>Admits</th><th>Invigs</th><th>Status</th></tr></thead><tbody>';
    data.forEach(e => {
        const saIcon = (e.created_by_role==='super_admin'||e.created_by_role==='college_admin')
            ? `<span style="color:var(--amber);font-size:.62rem;margin-left:4px"><i class="fas fa-crown"></i> SA</span>` : '';
        const schedDate = e.sched_date || e.exam_date || '—';
        const timeStr   = e.start_time ? `${e.start_time.slice(0,5)}–${e.end_time.slice(0,5)}` : '—';
        html += `<tr>
            <td><strong>${e.title}</strong>${saIcon}<br><span style="font-size:.7rem;color:var(--muted)">${e.dept_name||''} · Sem ${e.semester||'—'} · ${e.academic_year||''}</span></td>
            <td>${e.course_name}<br><span style="font-family:var(--mono);font-size:.7rem;color:var(--muted)">${e.course_code}</span></td>
            <td><span class="pill">${e.type||'—'}</span></td>
            <td style="font-size:.78rem">${schedDate!=='—'?fmtDate(schedDate):schedDate}</td>
            <td style="font-size:.78rem">${e.hall_name||'<span style="color:var(--muted)">—</span>'}</td>
            <td style="font-family:var(--mono);font-size:.75rem">${timeStr}</td>
            <td style="font-size:.78rem"><span style="color:${parseInt(e.admit_count)>0?'var(--green)':'var(--muted)'}">${e.admit_count||0}</span></td>
            <td style="font-size:.78rem"><span style="color:${parseInt(e.invig_count)>0?'var(--purple)':'var(--muted)'}">${e.invig_count||0}</span></td>
            <td><span class="pill ${statusColors[e.status]||''}">${e.status||'—'}</span></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading SA Overview:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load exams. Please refresh the page.<br><small style="color:var(--muted);font-size:.75rem">Error: ' + error.message + '</small></div>';
    }
}

// ═══ EXAM LIST ════════════════════════════════════════════════════
async function loadExams() {
    const wrap = document.getElementById('examListWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading…</p></div>';
    const q = document.getElementById('searchExam')?.value || '';
    const type = document.getElementById('filterType')?.value || '';
    const status = document.getElementById('filterStatus')?.value || '';
    const data = await api('exams', `&q=${encodeURIComponent(q)}&type=${encodeURIComponent(type)}&status=${encodeURIComponent(status)}`);
    if (!data || !data.length) {
        wrap.innerHTML = '<div class="empty-state"><i class="fas fa-file-invoice"></i>No exams found for this department.</div>';
        return;
    }
    const html = `<div class="exam-grid">${data.map(e => examCard(e)).join('')}</div>`;
    wrap.innerHTML = html;
}

function fmtType(t) {
    return {unit_test:'Unit Test',mid_term:'Mid Term',final:'Final',practical:'Practical',
        assignment:'Assignment',project:'Project',viva:'Viva'}[t] || t;
}
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'}) : '—'; }
function fmtTime(t) {
    if (!t) return '—';
    const [h,m] = t.split(':'); const hh = parseInt(h); return `${hh%12||12}:${m} ${hh<12?'AM':'PM'}`;
}
function fmtDur(mins) { if(!mins) return '—'; const h=Math.floor(mins/60),m=mins%60; return h?(m?`${h}h ${m}m`:`${h}h`):`${m}m`; }

function examCard(e) {
    const hasSchedule = e.sched_date || e.exam_date;
    const hasHall     = e.hall_name;
    const hasAdmits   = parseInt(e.admit_count||0) > 0;
    const hasInvigs   = parseInt(e.invig_count||0) > 0;
    return `<div class="exam-card" onclick="openEditModal(${e.id})">
      <div class="ec-top">
        <div class="ec-title">${e.title}</div>
        <span class="pill ${e.status}">${ucf(e.status)}</span>
      </div>
      <div class="ec-meta">
        <div class="ec-meta-item"><i class="fas fa-book-open"></i>${e.course_name} <span style="font-family:var(--mono);font-size:.65rem;margin-left:4px">(${e.course_code})</span></div>
        ${hasSchedule ? `<div class="ec-meta-item"><i class="fas fa-calendar"></i>${fmtDate(e.sched_date||e.exam_date)}</div>` : ''}
        ${e.start_time ? `<div class="ec-meta-item"><i class="fas fa-clock"></i>${fmtTime(e.start_time)} – ${fmtTime(e.end_time)}</div>` : ''}
        ${e.duration_mins ? `<div class="ec-meta-item"><i class="fas fa-hourglass-half"></i>${fmtDur(e.duration_mins)}</div>` : ''}
        ${hasHall ? `<div class="ec-meta-item"><i class="fas fa-door-open"></i>${e.hall_name}</div>` : ''}
        <div class="ec-meta-item"><i class="fas fa-chart-bar"></i>${e.max_marks} marks · pass ${e.pass_marks}</div>
        ${e.semester ? `<div class="ec-meta-item"><i class="fas fa-layer-group"></i>Sem ${e.semester}</div>` : ''}
      </div>
      <div class="ec-foot">
        <div class="ec-chips">
          <span class="ec-chip">${fmtType(e.type)}</span>
          ${hasAdmits ? `<span class="ec-chip has"><i class="fas fa-id-card"></i> ${e.admit_count} admits</span>` : '<span class="ec-chip">No admits</span>'}
          ${hasInvigs ? `<span class="ec-chip has"><i class="fas fa-user-tie"></i> ${e.invig_count} invigs</span>` : '<span class="ec-chip">No invigs</span>'}
        </div>
        <div class="ec-actions">
          <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();openEditModal(${e.id})"><i class="fas fa-pen"></i></button>
        </div>
      </div>
    </div>`;
}

// Open edit modal
async function openEditModal(id) {
    const e = await api('exam_detail', `&id=${id}`);
    if (!e) { showToast('Could not load exam', 'error'); return; }
    document.getElementById('editExamId').value   = e.id;
    document.getElementById('editSchedId').value  = e.schedule_id || '';
    document.getElementById('editTitle').value    = e.title;
    document.getElementById('editType').value     = e.type;
    document.getElementById('editStatus').value   = e.status;
    document.getElementById('editMax').value      = e.max_marks;
    document.getElementById('editPass').value     = e.pass_marks;
    document.getElementById('editDate').value     = e.sched_date || e.exam_date || '';
    document.getElementById('editStart').value    = e.start_time ? e.start_time.substr(0,5) : '';
    document.getElementById('editEnd').value      = e.end_time   ? e.end_time.substr(0,5) : '';
    document.getElementById('editRemarks').value  = e.remarks || '';
    // Populate halls
    const halls = await api('halls_list');
    const sel = document.getElementById('editHall');
    sel.innerHTML = '<option value="">No hall</option>' + (halls||[]).map(h => `<option value="${h.id}" ${h.id==e.hall_id?'selected':''}>${h.name} (${h.capacity} seats)</option>`).join('');
    openModal('editModal');
}

async function saveExam() {
    const id = document.getElementById('editExamId').value;
    const body = {
        id, title: document.getElementById('editTitle').value,
        type: document.getElementById('editType').value,
        status: document.getElementById('editStatus').value,
        max_marks: document.getElementById('editMax').value,
        pass_marks: document.getElementById('editPass').value,
        exam_date: document.getElementById('editDate').value,
        start_time: document.getElementById('editStart').value,
        end_time: document.getElementById('editEnd').value,
        hall_id: document.getElementById('editHall').value,
        remarks: document.getElementById('editRemarks').value,
        schedule_id: document.getElementById('editSchedId').value,
    };
    const r = await api('update_exam', '', 'POST', body);
    if (r?.success) { showToast('Exam updated'); closeModal('editModal'); loadExams(); loadStats(); }
    else showToast('Update failed', 'error');
}

async function deleteExam() {
    if (!confirm('Delete this exam and all related data? This cannot be undone.')) return;
    const id = document.getElementById('editExamId').value;
    const r = await api('delete_exam', '', 'POST', {id});
    if (r?.success) { showToast('Exam deleted'); closeModal('editModal'); loadExams(); loadStats(); }
    else showToast('Delete failed', 'error');
}

async function createExam() {
    const title = document.getElementById('newExamTitle').value.trim();
    const course = document.getElementById('newExamCourse').value;
    const date   = document.getElementById('newExamDate').value;
    if (!title) { showToast('Enter exam title', 'error'); return; }
    if (!course) { showToast('Select a course', 'error'); return; }
    if (!date)   { showToast('Select exam date', 'error'); return; }
    const body = {
        title, course_id: course, type: document.getElementById('newExamType').value,
        max_marks: document.getElementById('newExamMax').value,
        pass_marks: document.getElementById('newExamPass').value,
        exam_date: date, semester: document.getElementById('newExamSem').value,
        academic_year: document.getElementById('newExamYear').value,
        hall_id: document.getElementById('newExamHall').value,
        start_time: document.getElementById('newExamStart').value,
        end_time: document.getElementById('newExamEnd').value,
    };
    const r = await api('create_exam', '', 'POST', body);
    if (r?.success) { showToast('Exam created!'); closeModal('createModal'); loadExams(); loadStats(); }
    else showToast('Creation failed', 'error');
}

// ═══ SCHEDULE & CONFLICTS ════════════════════════════════════════
let weekOffset = 0;

async function loadConflicts() {
    const wrap = document.getElementById('conflictsWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Checking for conflicts…</p></div>';
    try {
        const data = await api('conflicts');
        if (!data || !data.length) {
            wrap.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle" style="color:var(--green)"></i>No active conflicts — all clear!</div>';
            return;
        }
        const typeLabels = {hall_double_booked:'Hall Double Booked',invigilator_double_assigned:'Invigilator Double Assigned',student_double_exam:'Student Double Exam',time_overlap:'Time Overlap'};
        wrap.innerHTML = `<div class="conflict-list">${data.map(c => `
          <div class="conflict-card">
            <div class="cc-type"><i class="fas fa-triangle-exclamation"></i>${typeLabels[c.conflict_type]||c.conflict_type}</div>
            <div class="cc-desc">${c.description||'Conflict detected'}</div>
            <div class="cc-sides">
              <div class="cc-side"><div class="cc-side-label">Exam 1</div><strong>${c.exam1}</strong><br><span style="font-size:.7rem;color:var(--muted)">${fmtDate(c.date1)} ${fmtTime(c.time1)} · ${c.hall1||'No hall'}</span></div>
              <div class="cc-side"><div class="cc-side-label">Exam 2</div><strong>${c.exam2}</strong><br><span style="font-size:.7rem;color:var(--muted)">${fmtDate(c.date2)} ${fmtTime(c.time2)} · ${c.hall2||'No hall'}</span></div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="resolveConflict(${c.id})"><i class="fas fa-check"></i> Mark Resolved</button>
          </div>`).join('')}</div>`;
    } catch (error) {
        console.error('Error loading conflicts:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load conflicts. Please refresh.</div>';
    }
}

async function resolveConflict(id) {
    const r = await api('resolve_conflict', '', 'POST', {id});
    if (r?.success) { showToast('Conflict resolved'); loadConflicts(); loadStats(); }
}

function moveWeek(dir) { weekOffset += dir; loadWeek(); }

async function loadWeek() {
    const wrap = document.getElementById('weekGridWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    const base = new Date(); base.setDate(base.getDate() + weekOffset * 7);
    const mon = new Date(base); mon.setDate(base.getDate() - ((base.getDay()||7) - 1));
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    document.getElementById('weekLabel').textContent =
        mon.toLocaleDateString('en-IN',{day:'numeric',month:'short'}) + ' – ' +
        sun.toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
    const fmt = d => d.toISOString().slice(0,10);
    const data = await api('schedule', `&from=${fmt(mon)}&to=${fmt(sun)}`);
    if (!data) { wrap.innerHTML = '<div class="empty-state"><i class="fas fa-calendar"></i>No data.</div>'; return; }
    const conflictIds = new Set(data.conflict_ids || []);
    const days = []; for(let i=0;i<7;i++) { const d=new Date(mon); d.setDate(mon.getDate()+i); days.push(d); }
    const today = new Date().toISOString().slice(0,10);
    let html = '<div class="week-grid">';
    days.forEach(day => {
        const ds = fmt(day);
        const slots = (data.slots||[]).filter(s => s.exam_date === ds);
        const isToday = ds === today;
        html += `<div class="day-col ${isToday?'day-today':''}">
          <div class="day-hdr">${day.toLocaleDateString('en-IN',{weekday:'short'})}</div>
          <div class="day-num">${day.getDate()}</div>
          ${slots.map(s => `<div class="sched-slot ${conflictIds.has(s.id)?'conflict':''}" title="${s.title}">
            <div class="slot-time">${fmtTime(s.start_time)}</div>
            <div class="slot-title">${s.title}</div>
            <div class="slot-hall">${s.hall_name||'No hall'}</div>
          </div>`).join('')}
          ${!slots.length ? '<div style="font-size:.65rem;color:var(--border);text-align:center;padding:6px 0;">—</div>' : ''}
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

async function addScheduleSlot() {
    const exam_id  = document.getElementById('slotExam').value;
    const hall_id  = document.getElementById('slotHall').value;
    const exam_date = document.getElementById('slotDate').value;
    const start_time = document.getElementById('slotStart').value;
    const end_time   = document.getElementById('slotEnd').value;
    if (!exam_id||!exam_date||!start_time||!end_time) { showToast('Fill all required fields','error'); return; }
    const r = await api('add_schedule','','POST',{exam_id,hall_id,exam_date,start_time,end_time,notes:document.getElementById('slotNotes').value});
    if (r?.success) { showToast('Schedule added'); loadAssignedSchedule(); loadConflicts(); loadStats(); }
    else showToast(r?.error||'Failed','error');
}

// ═══ HALLS ════════════════════════════════════════════════════════
async function loadHalls() {
    const wrap = document.getElementById('hallsWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading halls…</p></div>';
    const [halls, util] = await Promise.all([api('halls'), api('hall_utilization')]);
    const utilMap = {};
    (util||[]).forEach(u => utilMap[u.name] = {cap:parseInt(u.capacity),occ:parseInt(u.occupied)});
    if (!halls || !halls.length) { wrap.innerHTML = '<div class="empty-state"><i class="fas fa-door-open"></i>No halls configured for this college.</div>'; return; }
    let html = '<div class="hall-grid">';
    halls.forEach(h => {
        const u = utilMap[h.name] || {cap:parseInt(h.capacity),occ:0};
        const pct = u.cap > 0 ? Math.round((u.occ/u.cap)*100) : 0;
        const fillClass = pct >= 100 ? 'full' : pct >= 75 ? 'warn' : '';
        const books = h.booking_list ? h.booking_list.split(';;').filter(Boolean) : [];
        html += `<div class="hall-card">
          <div class="hc-top">
            <div><div class="hc-name">${h.name}</div><div class="hc-code">${h.code}</div></div>
            <div style="text-align:right"><div class="hc-cap">${h.capacity}</div><div class="hc-cap-lbl">seats</div></div>
          </div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:4px">${h.building||''}${h.floor?` · Floor ${h.floor}`:''}</div>
          <div class="util-bar" title="${pct}% occupied"><div class="util-fill ${fillClass}" style="width:${Math.min(pct,100)}%"></div></div>
          <div style="font-size:.68rem;color:var(--muted);margin-top:4px">${u.occ} / ${u.cap} seats allocated (${pct}%)</div>
          <div class="hc-meta">
            <div class="hc-feature ${h.has_projector?'yes':''}"><i class="fas fa-${h.has_projector?'check':'times'}"></i> Projector</div>
            <div class="hc-feature ${h.has_ac?'yes':''}"><i class="fas fa-${h.has_ac?'check':'times'}"></i> AC</div>
            ${h.conflicts>0 ? `<div class="hc-feature" style="color:var(--red)"><i class="fas fa-triangle-exclamation"></i> ${h.conflicts} conflict${h.conflicts>1?'s':''}</div>` : ''}
          </div>
          ${books.length ? `<div class="hc-bookings">Upcoming: ${books.map(b=>{ const [t,d]=b.split('|'); return `<span class="booking-pill">${t||b} · ${d||''}</span>`; }).join('')}</div>` : ''}
          <div style="margin-top:10px"><span class="pill ${h.status}">${ucf(h.status)}</span></div>
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

// ═══ SEATING ═════════════════════════════════════════════════════
async function populateSeatingDropdowns() {
    const [halls, scheds] = await Promise.all([api('halls_list'), api('schedules_list')]);
    const hSel = document.getElementById('seatHallSel');
    hSel.innerHTML = '<option value="">All Halls</option>' + (halls||[]).map(h=>`<option value="${h.id}">${h.name}</option>`).join('');
    const sSel = document.getElementById('seatSchedSel');
    sSel.innerHTML = '<option value="">All Exams</option>' + (scheds||[]).map(s=>`<option value="${s.id}">${s.label}</option>`).join('');
    // Also populate seat modal dropdowns
    document.getElementById('seatHallInput').innerHTML = '<option value="">Select Hall</option>' + (halls||[]).map(h=>`<option value="${h.id}">${h.name} (${h.capacity})</option>`).join('');
    document.getElementById('seatSchedInput').innerHTML = '<option value="">Select Exam</option>' + (scheds||[]).map(s=>`<option value="${s.id}">${s.label}</option>`).join('');
    const stud = await api('students_list');
    document.getElementById('seatStudentInput').innerHTML = '<option value="">Select Student</option>' + (stud||[]).map(s=>`<option value="${s.id}">${s.full_name} ${s.roll_number?'('+s.roll_number+')':''}</option>`).join('');
}

async function loadSeating() {
    const wrap = document.getElementById('seatingWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    const hall_id  = document.getElementById('seatHallSel').value;
    const sched_id = document.getElementById('seatSchedSel').value;
    const data = await api('seating', `&hall_id=${hall_id}&schedule_id=${sched_id}`);
    if (!data?.seats?.length) {
        wrap.innerHTML = '<div class="empty-state"><i class="fas fa-chair"></i>No seating assigned yet. Use "Assign Seat" to begin.</div>';
        return;
    }
    const seats = data.seats;
    // Build grid
    const maxRow = Math.max(...seats.map(s=>parseInt(s.row_no)||1));
    const maxCol = Math.max(...seats.map(s=>parseInt(s.col_no)||1));
    const seatMap = {};
    seats.forEach(s => seatMap[`${s.row_no}_${s.col_no}`] = s);
    let html = `<div class="seat-grid-wrap">
      <div class="seat-legend">
        <div class="sleg"><div class="sleg-dot" style="background:rgba(0,212,187,.1);border:1px solid rgba(0,212,187,.3)"></div> Occupied</div>
        <div class="sleg"><div class="sleg-dot" style="background:rgba(255,255,255,.02);border:1px solid var(--border)"></div> Empty</div>
        <div class="sleg"><div class="sleg-dot" style="background:var(--green2);border:1px solid rgba(16,185,129,.3)"></div> Present</div>
        <div class="sleg"><div class="sleg-dot" style="background:var(--red2);border:1px solid rgba(239,68,68,.3)"></div> Absent</div>
      </div>
      <div class="seat-grid" style="grid-template-columns:repeat(${maxCol},52px)">`;
    for(let r=1;r<=maxRow;r++) {
        for(let c=1;c<=maxCol;c++) {
            const s = seatMap[`${r}_${c}`];
            if (s) {
                const cls = s.is_present===null?'occupied':s.is_present==1?'present':'absent';
                html += `<div class="seat ${cls}" title="${s.full_name||''}\n${s.roll_number||''}\n${s.exam_title||''}">
                  <div class="seat-no">${s.seat_number||`R${r}C${c}`}</div>
                  <div class="seat-roll">${s.roll_number||s.full_name?.split(' ')[0]||'—'}</div>
                </div>`;
            } else {
                html += `<div class="seat empty" title="Empty seat"><div class="seat-no" style="color:var(--border)">—</div></div>`;
            }
        }
    }
    html += '</div></div>';
    // Table view too
    html += `<div class="t-wrap" style="padding:0 20px 18px"><table>
      <thead><tr><th>Seat</th><th>Student</th><th>Roll No.</th><th>Dept</th><th>Exam</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>${seats.map(s=>`<tr>
        <td><span class="mono">${s.seat_number}</span></td>
        <td><div class="s-name">${s.full_name}</div></td>
        <td><span class="mono">${s.roll_number||'—'}</span></td>
        <td><span class="mono">${s.dept_code||'—'}</span></td>
        <td><div style="font-size:.78rem">${s.exam_title}</div></td>
        <td><span class="mono">${fmtDate(s.exam_date)}</span></td>
        <td>${s.is_present===null?'<span class="pill draft">Not Marked</span>':s.is_present?'<span class="pill published">Present</span>':'<span class="pill cancelled">Absent</span>'}</td>
      </tr>`).join('')}</tbody>
    </table></div>`;
    wrap.innerHTML = html;
}

async function assignSeat() {
    const body = {
        schedule_id: document.getElementById('seatSchedInput').value,
        hall_id:     document.getElementById('seatHallInput').value,
        student_id:  document.getElementById('seatStudentInput').value,
        seat_number: document.getElementById('seatNo').value,
        row_no:      document.getElementById('seatRow').value,
        col_no:      document.getElementById('seatCol').value,
    };
    if (!body.schedule_id||!body.hall_id||!body.student_id||!body.seat_number) { showToast('Fill all fields','error'); return; }
    const r = await api('assign_seat','','POST',body);
    if (r?.success) { showToast('Seat assigned'); loadSeating(); }
    else showToast(r?.error||'Failed','error');
}

// ═══ ADMIT CARDS ═════════════════════════════════════════════════
async function loadAdmits() {
    const wrap = document.getElementById('admitsWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    const q = document.getElementById('admitSearch')?.value||'';
    const data = await api('admit_cards', `&q=${encodeURIComponent(q)}`);
    if (!data||!data.length) { wrap.innerHTML='<div class="empty-state"><i class="fas fa-id-card"></i>No admit cards found. Generate cards using the button above.</div>'; return; }
    wrap.innerHTML = `<div class="t-wrap"><table>
      <thead><tr><th>Admit No.</th><th>Student</th><th>Exam</th><th>Date</th><th>Hall</th><th>Seat</th><th>Status</th><th></th></tr></thead>
      <tbody>${data.map(ac=>`<tr>
        <td><span class="mono">${ac.admit_card_no}</span></td>
        <td><div class="s-name">${ac.student_name}</div><div class="s-sub">${ac.roll_number||ac.email}</div></td>
        <td><div style="font-size:.78rem;font-weight:600;color:var(--white)">${ac.exam_title}</div><div class="s-sub">${ucf(ac.exam_type)}</div></td>
        <td><span class="mono">${fmtDate(ac.exam_date)}</span><br><span class="mono">${fmtTime(ac.start_time)}</span></td>
        <td><span class="mono">${ac.hall_name||'—'}</span></td>
        <td><span class="mono">${ac.seat_number||'—'}</span></td>
        <td><span class="pill ${ac.is_valid?'valid':'revoked'}">${ac.is_valid?'Valid':'Revoked'}</span></td>
        <td><button class="btn btn-red btn-sm" onclick="revokeAdmit(${ac.id})"><i class="fas fa-ban"></i></button></td>
      </tr>`).join('')}</tbody>
    </table></div>`;
}

async function generateAdmit() {
    const sid  = document.getElementById('admitStudent').value;
    const schid = document.getElementById('admitSched').value;
    if (!sid||!schid) { showToast('Select student and exam','error'); return; }
    const r = await api('gen_admit','','POST',{student_id:sid,schedule_id:schid});
    if (r?.success) { showToast(`Admit card generated: ${r.admit_card_no}`); loadStats(); }
    else showToast('Failed','error');
}

async function revokeAdmit(id) {
    if (!confirm('Revoke this admit card?')) return;
    const r = await api('revoke_admit','','POST',{id,reason:'Revoked by faculty'});
    if (r?.success) { showToast('Admit card revoked','info'); loadAdmits(); }
}

// ═══ INVIGILATORS ════════════════════════════════════════════════
async function loadInvigilators() {
    const wrap = document.getElementById('invigilatorsWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    const data = await api('invigilators');
    if (!data||!data.length) { wrap.innerHTML='<div class="empty-state"><i class="fas fa-user-tie"></i>No invigilators assigned yet.</div>'; return; }
    wrap.innerHTML = `<div class="t-wrap"><table>
      <thead><tr><th>Faculty</th><th>Dept</th><th>Exam</th><th>Date</th><th>Hall</th><th>Duty</th><th>Status</th><th></th></tr></thead>
      <tbody>${data.map(i=>`<tr>
        <td><div class="s-name">${i.faculty_name}</div><div class="s-sub">${i.faculty_email||''}</div></td>
        <td><span class="mono">${i.dept_name||'—'}</span></td>
        <td><div style="font-size:.78rem;font-weight:600;color:var(--white)">${i.exam_title}</div><div class="s-sub">${ucf(i.exam_type)}</div></td>
        <td><span class="mono">${fmtDate(i.exam_date)}</span><br><span class="mono">${fmtTime(i.start_time)}</span></td>
        <td><span class="mono">${i.hall_name||'—'}</span></td>
        <td><span class="pill ${i.duty_type}">${ucf(i.duty_type.replace('_',' '))}</span></td>
        <td><span class="pill ${i.is_confirmed?'confirmed':'unconfirmed'}">${i.is_confirmed?'Confirmed':'Pending'}</span></td>
        <td style="display:flex;gap:4px">
          ${!i.is_confirmed ? `<button class="btn btn-ghost btn-sm" onclick="confirmInvig(${i.id})" title="Confirm"><i class="fas fa-check"></i></button>` : ''}
          <button class="btn btn-red btn-sm" onclick="removeInvig(${i.id})" title="Remove"><i class="fas fa-times"></i></button>
        </td>
      </tr>`).join('')}</tbody>
    </table></div>`;
}

async function assignInvig() {
    const body = {
        schedule_id: document.getElementById('invSched').value,
        faculty_id:  document.getElementById('invFaculty').value,
        hall_id:     document.getElementById('invHall').value,
        duty_type:   document.getElementById('invDuty').value,
    };
    if (!body.schedule_id||!body.faculty_id) { showToast('Select exam and faculty','error'); return; }
    const r = await api('assign_invig','','POST',body);
    if (r?.success) { showToast('Invigilator assigned'); loadStats(); }
    else showToast(r?.error||'Failed','error');
}
async function confirmInvig(id) {
    const r = await api('confirm_invig','','POST',{id});
    if (r?.success) { showToast('Confirmed'); loadInvigilators(); }
}
async function removeInvig(id) {
    if (!confirm('Remove this invigilator?')) return;
    const r = await api('remove_invig','','POST',{id});
    if (r?.success) { showToast('Removed','info'); loadInvigilators(); loadStats(); }
}

// ═══ QUESTION PAPERS ════════════════════════════════════════════
async function loadQPapers() {
    const wrap = document.getElementById('qpapersWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading papers…</p></div>';
    const data = await api('qpapers');
    if (!data || !Array.isArray(data)) {
        wrap.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i>Failed to load papers. Check your connection.</div>';
        return;
    }
    if (!data.length) {
        wrap.innerHTML = '<div class="empty-state"><i class="fas fa-scroll"></i>No question papers yet.<br><small style="color:var(--muted)">Click “Create Question Paper” above to add one.</small></div>';
        return;
    }
    const statusColors = {
        draft:'draft', pending_approval:'ongoing',
        approved:'published', released:'completed', archived:'inactive'
    };
    wrap.innerHTML = `
      <div style="overflow-x:auto">
        <table style="width:100%;table-layout:fixed;border-collapse:collapse">
          <colgroup>
            <col style="width:170px">  <!-- Paper Title -->
            <col style="width:140px">  <!-- Exam -->
            <col style="width:70px">   <!-- Set -->
            <col style="width:70px">   <!-- Marks -->
            <col style="width:80px">   <!-- Questions -->
            <col style="width:100px">  <!-- File -->
            <col style="width:90px">   <!-- Created -->
            <col style="width:110px">  <!-- Status -->
            <col style="width:90px">   <!-- Lock -->
            <col style="width:200px">  <!-- Actions -->
          </colgroup>
          <thead>
            <tr>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Paper Title</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Exam</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Set</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Marks</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Questions</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">File</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Created</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Status</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Lock</th>
              <th style="padding:10px 12px;font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${data.map(p => {
              const safeTitle = (p.title||'').replace(/'/g, '&#39;');
              const statusClass = statusColors[p.status] || 'draft';
              const statusLabel = ucf((p.status||'draft').replace(/_/g,' '));
              return `<tr style="border-bottom:1px solid rgba(255,255,255,.04)">
                <td style="padding:10px 12px;vertical-align:middle">
                  <div class="s-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px" title="${safeTitle}">${p.title||'—'}</div>
                  <div class="s-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">By ${p.created_by_name||'—'}</div>
                </td>
                <td style="padding:10px 12px;vertical-align:middle">
                  <div style="font-size:.78rem;font-weight:600;color:var(--white);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px" title="${p.exam_title||''}">${p.exam_title||'—'}</div>
                  <div class="s-sub">${p.course_code||''}</div>
                </td>
                <td style="padding:10px 12px;vertical-align:middle"><span class="pill info">Set ${p.version||'A'}</span></td>
                <td style="padding:10px 12px;vertical-align:middle"><span class="mono">${p.total_marks||0}</span></td>
                <td style="padding:10px 12px;vertical-align:middle;text-align:center"><span class="mono">${p.total_questions||'—'}</span></td>
                <td style="padding:10px 12px;vertical-align:middle;white-space:nowrap">
                  ${p.file_path
                    ? `<a href="${p.file_path}" target="_blank" class="btn btn-ghost btn-sm" title="Download"><i class="fas fa-download"></i> File</a>
                       <button class="btn btn-ghost btn-sm" onclick="openUploadPaperModal(${p.id},'${safeTitle}')" title="Replace file"><i class="fas fa-arrows-rotate"></i></button>`
                    : `<button class="btn btn-ghost btn-sm" onclick="openUploadPaperModal(${p.id},'${safeTitle}')" title="Attach file"><i class="fas fa-upload"></i> Attach</button>`
                  }
                </td>
                <td style="padding:10px 12px;vertical-align:middle"><span class="mono" style="font-size:.75rem">${fmtDate(p.created_at)}</span></td>
                <td style="padding:10px 12px;vertical-align:middle">
                  <span class="pill ${statusClass}" data-status="${p.status||'draft'}">${statusLabel}</span>
                </td>
                <td style="padding:10px 12px;vertical-align:middle">
                  ${p.is_confidential
                    ? '<span class="pill upcoming"><i class="fas fa-lock"></i> Locked</span>'
                    : '<span class="pill published"><i class="fas fa-lock-open"></i> Open</span>'}
                </td>
                <td style="padding:10px 12px;vertical-align:middle;white-space:nowrap">
                  <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap">
                    ${p.status === 'draft'
                      ? `<button class="btn btn-ghost btn-sm" onclick="updateQPaperStatus(${p.id},'pending_approval')" title="Submit for approval">
                           <i class="fas fa-paper-plane"></i> Submit
                         </button>` : ''}
                    ${p.status === 'approved'
                      ? `<button class="btn btn-teal btn-sm" onclick="updateQPaperStatus(${p.id},'released')" title="Release to students">
                           <i class="fas fa-share-from-square"></i> Release
                         </button>` : ''}
                    ${['draft','pending_approval'].includes(p.status)
                      ? `<button class="btn btn-ghost btn-sm" style="color:#a78bfa;border-color:rgba(167,139,250,.35)"
                           onclick="openEditQPaperModal(${p.id})" title="Edit">
                           <i class="fas fa-pen"></i> Edit
                         </button>
                         <button class="btn btn-ghost btn-sm" style="color:#ef4444;border-color:rgba(239,68,68,.35);padding:5px 8px"
                           onclick="eqpConfirmDelete(${p.id},'${safeTitle}')" title="Delete">
                           <i class="fas fa-trash"></i>
                         </button>` : ''}
                    <button class="btn btn-ghost btn-sm" style="padding:5px 8px" onclick="generateQPaperPDF(${p.id},'${safeTitle}')" title="Print">
                      <i class="fas fa-print"></i>
                    </button>
                  </div>
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>`;
}

// ══════════════════════════════════════════════════════════════════════════════
// EDIT QUESTION PAPER — full sections + questions editor
// ══════════════════════════════════════════════════════════════════════════════
let _eqpSections = [];   // working copy of sections
let _eqpSecId    = 0;    // local id counter for new sections
let _eqpPaperId  = null;

const _eqpTypeLabels = {
  mcq:'MCQ', short:'Short Answer', long:'Long Answer',
  true_false:'True / False', fill_blank:'Fill in the Blank',
  descriptive:'Descriptive', problem_solving:'Problem Solving',
  case_study:'Case Study', subjective:'Subjective'
};

async function openEditQPaperModal(id) {
  _eqpPaperId  = id;
  _eqpSections = [];
  _eqpSecId    = 0;
  document.getElementById('eqpError').style.display = 'none';
  document.getElementById('eqpSaveBtn').disabled = true;
  document.getElementById('eqpSaveBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
  eqpShowTab(1);
  openModal('editQPaperModal');

  const r = await fetch(`examinations.php?ajax=get_qpaper_detail&id=${id}`).then(x=>x.json());
  if (r.error) {
    document.getElementById('eqpErrorMsg').textContent = r.error;
    document.getElementById('eqpError').style.display = 'block';
    document.getElementById('eqpSaveBtn').disabled = false;
    document.getElementById('eqpSaveBtn').innerHTML = '<i class="fas fa-check"></i> Save Changes';
    return;
  }

  // Fill tab 1 fields
  document.getElementById('eqpId').value            = r.id;
  document.getElementById('eqpTitle').value         = r.title || '';
  document.getElementById('eqpVersion').value       = r.version || 'A';
  document.getElementById('eqpMarks').value         = r.total_marks || 100;
  document.getElementById('eqpInstructions').value  = r.instructions || '';

  // Load sections into state
  _eqpSections = (r.sections || []).map((s, i) => ({
    id: ++_eqpSecId,
    blueprint_id: s.blueprint_id || 0,
    name:       s.name || ('Section ' + String.fromCharCode(65 + i)),
    type:       s.type || 'short',
    attend:     s.attend || 1,
    marksEach:  s.marksEach || 1,
    questions:  (s.questions || []).map(q => ({
      pq_id: q.pq_id || 0,
      qb_id: q.qb_id || 0,
      text:  q.text  || '',
      marks_override: q.marks_override || s.marksEach || 1,
      opts:  q.opts  || [],
      image_url:  q.image_url  || null,
      table_html: q.table_html || null,
    }))
  }));
  if (_eqpSections.length === 0) eqpAddSection();

  eqpRenderSections();
  eqpUpdateSummary();

  document.getElementById('eqpSaveBtn').disabled = false;
  document.getElementById('eqpSaveBtn').innerHTML = '<i class="fas fa-check"></i> Save Changes';
}

function eqpShowTab(n) {
  document.getElementById('eqpPane1').style.display = n === 1 ? '' : 'none';
  document.getElementById('eqpPane2').style.display = n === 2 ? '' : 'none';
  [1,2].forEach(i => {
    const t = document.getElementById('eqpTab'+i);
    t.style.borderBottomColor = i === n ? 'var(--teal)' : 'transparent';
    t.style.color             = i === n ? 'var(--teal)' : 'var(--muted)';
  });
}

// ── Section management ──────────────────────────────────────────────────────
function eqpAddSection() {
  const id = ++_eqpSecId;
  _eqpSections.push({ id, blueprint_id: 0,
    name: 'Section ' + String.fromCharCode(64 + _eqpSections.length + 1),
    type: 'short', attend: 5, marksEach: 2, questions: [] });
  eqpRenderSections();
  setTimeout(() => {
    const el = document.getElementById('eqpSec_' + id);
    if (el) el.scrollIntoView({ behavior:'smooth', block:'start' });
  }, 80);
}

function eqpRemoveSection(id) {
  if (!confirm('Remove this section and all its questions?')) return;
  _eqpSections = _eqpSections.filter(s => s.id !== id);
  eqpRenderSections();
  eqpUpdateSummary();
}

function eqpAddQuestion(secId) {
  const sec = _eqpSections.find(s => s.id === secId);
  if (!sec) return;
  sec.questions.push({ pq_id:0, qb_id:0, text:'', marks_override: sec.marksEach,
                       opts: sec.type === 'mcq' ? ['','','',''] : [],
                       image_url: null, table_html: null });
  eqpRenderSections();
  setTimeout(() => {
    const inputs = document.querySelectorAll(`#eqpSec_${secId} .eqp-q-txt`);
    if (inputs.length) inputs[inputs.length-1].focus();
  }, 60);
}

function eqpRemoveQuestion(secId, qIdx) {
  const sec = _eqpSections.find(s => s.id === secId);
  if (!sec) return;
  sec.questions.splice(qIdx, 1);
  eqpRenderSections();
  eqpUpdateSummary();
}

function eqpSyncField(secId, field, val) {
  const sec = _eqpSections.find(s => s.id === secId);
  if (!sec) return;
  if (field === 'type') {
    sec.type = val;
    sec.questions = sec.questions.map(q => ({
      ...q, opts: val === 'mcq' ? (q.opts.length ? q.opts : ['','','','']) : []
    }));
    eqpRenderSections();
    return;
  }
  sec[field] = val;
  eqpUpdateSummary();
}

function eqpSyncQuestion(secId, qIdx, field, val) {
  const sec = _eqpSections.find(s => s.id === secId);
  if (!sec || !sec.questions[qIdx]) return;
  if (field === 'text')              sec.questions[qIdx].text = val;
  else if (field === 'marks')        sec.questions[qIdx].marks_override = parseFloat(val) || 0;
  else if (field.startsWith('opt')) { const oi = parseInt(field.replace('opt','')); sec.questions[qIdx].opts[oi] = val; }
}

function eqpUpdateSummary() {
  const declared = parseFloat(document.getElementById('eqpMarks')?.value) || 0;
  let secTotal = 0;
  _eqpSections.forEach(s => {
    secTotal += (parseFloat(s.marksEach)||0) * (parseInt(s.attend)||0);
    const el = document.getElementById('eqpTotal_'+s.id);
    if (el) el.textContent = '= ' + ((parseFloat(s.marksEach)||0)*(parseInt(s.attend)||0)) + ' Marks';
  });
  const ds = document.getElementById('eqpSumDeclared');
  const ss = document.getElementById('eqpSumSection');
  const al = document.getElementById('eqpMarkAlert');
  if (ds) ds.textContent = declared;
  if (ss) ss.textContent = secTotal;
  if (al) al.style.display = (declared > 0 && Math.abs(secTotal - declared) > 0.01) ? '' : 'none';
}

function _eqpEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function eqpRenderSections() {
  const wrap = document.getElementById('eqpSectionsWrap');
  if (!wrap) return;
  wrap.innerHTML = _eqpSections.map((sec) => {
    const total  = (parseFloat(sec.marksEach)||0) * (parseInt(sec.attend)||0);
    const totalQs = sec.questions.length;
    return `
    <div class="qp-section-card" id="eqpSec_${sec.id}" style="background:var(--card);border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden">
      <!-- Header -->
      <div style="background:var(--bg);padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid var(--border)">
        <input class="form-control" style="max-width:180px;font-weight:700;font-size:.85rem"
               value="${_eqpEsc(sec.name)}" oninput="eqpSyncField(${sec.id},'name',this.value)" placeholder="Section name">
        <select class="form-control" style="max-width:160px;font-size:.8rem" onchange="eqpSyncField(${sec.id},'type',this.value)">
          ${Object.entries(_eqpTypeLabels).map(([v,l])=>`<option value="${v}" ${sec.type===v?'selected':''}>${l}</option>`).join('')}
        </select>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <label style="font-size:.75rem;color:var(--muted);white-space:nowrap">Attempt:</label>
          <input type="number" min="1" class="form-control" style="width:60px;font-size:.82rem;text-align:center"
                 value="${sec.attend}" oninput="eqpSyncField(${sec.id},'attend',this.value);eqpUpdateSummary()">
          <span style="font-size:.75rem;color:var(--muted)">×</span>
          <input type="number" min="0" step="0.5" class="form-control" style="width:64px;font-size:.82rem;text-align:center"
                 value="${sec.marksEach}" oninput="eqpSyncField(${sec.id},'marksEach',this.value);eqpUpdateSummary()">
          <span style="font-size:.75rem;color:var(--muted)">M each</span>
          <span id="eqpTotal_${sec.id}" style="font-size:.82rem;font-weight:700;color:var(--teal);white-space:nowrap">= ${total} Marks</span>
        </div>
        <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
          <span style="font-size:.72rem;color:var(--muted)">${totalQs} Q${totalQs!==1?'s':''}</span>
          ${_eqpSections.length > 1
            ? `<button class="btn btn-ghost btn-sm" style="color:#ef4444" onclick="eqpRemoveSection(${sec.id})" title="Remove section"><i class="fas fa-trash-can"></i></button>`
            : ''}
        </div>
      </div>

      <!-- Notice -->
      <div style="padding:6px 14px;background:rgba(0,212,187,.07);border-bottom:1px solid var(--border);font-size:.75rem;color:var(--teal)">
        <i class="fas fa-circle-info"></i>
        Attempt any <b>${sec.attend}</b> of <b>${totalQs}</b> questions &nbsp;|&nbsp; <b>${sec.marksEach} × ${sec.attend} = ${total} marks</b>
      </div>

      <!-- Questions -->
      <div style="padding:10px 14px">
        ${sec.questions.length === 0
          ? `<div style="color:var(--muted);font-size:.8rem;font-style:italic;text-align:center;padding:8px 0">No questions yet — click "Add Question" below.</div>`
          : sec.questions.map((q, qi) => `
          <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;padding:10px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px">
            <span style="font-weight:700;font-size:.82rem;color:var(--muted);padding-top:8px;min-width:22px">${qi+1}.</span>
            <div style="flex:1">
              <textarea class="form-control eqp-q-txt" rows="2"
                style="font-size:.85rem;resize:vertical"
                placeholder="Enter question ${qi+1}…"
                oninput="eqpSyncQuestion(${sec.id},${qi},'text',this.value)">${_eqpEsc(q.text)}</textarea>
              ${sec.type === 'mcq' ? `
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;margin-top:5px">
                ${['A','B','C','D'].map((l,oi)=>`
                <div style="display:flex;gap:5px;align-items:center">
                  <span style="font-size:.75rem;font-weight:700;min-width:14px;color:var(--muted)">${l}.</span>
                  <input class="form-control" style="font-size:.8rem;padding:4px 8px"
                         value="${_eqpEsc(q.opts[oi]||'')}"
                         placeholder="Option ${l}"
                         oninput="eqpSyncQuestion(${sec.id},${qi},'opt${oi}',this.value)">
                </div>`).join('')}
              </div>` : ''}
              <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                <label style="font-size:.73rem;color:var(--muted);white-space:nowrap">Marks:</label>
                <input type="number" min="0" step="0.5" class="form-control"
                       style="width:70px;font-size:.8rem;padding:4px 8px"
                       value="${q.marks_override || sec.marksEach}"
                       oninput="eqpSyncQuestion(${sec.id},${qi},'marks',this.value)">
              </div>

              <!-- ── Media toolbar (edit modal) ── -->
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:7px">
                <label style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
                              background:rgba(15,118,110,.08);border:1px solid rgba(15,118,110,.25);
                              border-radius:6px;font-size:.74rem;color:var(--teal);transition:background .15s"
                       title="Upload an image for this question"
                       onmouseover="this.style.background='rgba(15,118,110,.16)'" onmouseout="this.style.background='rgba(15,118,110,.08)'">
                  <i class="fas fa-image"></i> Image
                  <input type="file" accept="image/*" style="display:none"
                         onchange="eqpUploadImage(event,${sec.id},${qi})">
                </label>
                <button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 10px;border:1px solid var(--border)"
                        onclick="eqpToggleTable(${sec.id},${qi})" title="Add / edit a table">
                  <i class="fas fa-table"></i> Table
                </button>
                ${q.image_url
                  ? `<span style="font-size:.7rem;color:#10b981;display:inline-flex;align-items:center;gap:4px">
                       <i class="fas fa-circle-check"></i> Image attached
                       <button class="btn btn-ghost btn-sm" style="color:#ef4444;padding:1px 5px;font-size:.65rem"
                               onclick="eqpRemoveImage(${sec.id},${qi})" title="Remove image">✕</button>
                     </span>` : ''}
              </div>

              <!-- Image preview -->
              ${q.image_url ? `
              <div style="margin-top:6px">
                <img src="${_eqpEsc(q.image_url)}" alt="Q${qi+1} image"
                     style="max-width:100%;max-height:180px;border-radius:6px;border:1px solid var(--border);object-fit:contain">
              </div>` : ''}

              <!-- Table builder (shown/hidden) -->
              <div id="eqpTblBuilder_${sec.id}_${qi}"
                   style="display:${q.table_html ? '' : 'none'};margin-top:8px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:8px">
                <div style="font-size:.74rem;font-weight:600;color:var(--teal);margin-bottom:6px">
                  <i class="fas fa-table"></i> Table Editor
                  <button class="btn btn-ghost btn-sm" style="float:right;font-size:.68rem;padding:2px 8px"
                          onclick="eqpApplyTable(${sec.id},${qi})">
                    <i class="fas fa-check"></i> Apply
                  </button>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                  <label style="font-size:.74rem;color:var(--muted)">Rows:</label>
                  <input type="number" min="1" max="20" value="3" id="eqpTblRows_${sec.id}_${qi}"
                         class="form-control" style="width:58px;font-size:.8rem;padding:3px 7px">
                  <label style="font-size:.74rem;color:var(--muted)">Cols:</label>
                  <input type="number" min="1" max="10" value="3" id="eqpTblCols_${sec.id}_${qi}"
                         class="form-control" style="width:58px;font-size:.8rem;padding:3px 7px">
                  <button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 9px"
                          onclick="eqpBuildTableGrid(${sec.id},${qi})">
                    <i class="fas fa-refresh"></i> Generate
                  </button>
                  ${q.table_html ? `<button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 9px;color:#ef4444"
                          onclick="eqpRemoveTable(${sec.id},${qi})"><i class="fas fa-trash-can"></i> Remove Table</button>` : ''}
                </div>
                <div id="eqpTblGrid_${sec.id}_${qi}" style="overflow-x:auto">
                  ${q.table_html ? eqpTableToEditGrid(q.table_html, sec.id, qi) : '<div style="font-size:.75rem;color:var(--muted)">Set rows/cols and click Generate.</div>'}
                </div>
              </div>
            </div>
            <button class="btn btn-ghost btn-sm" style="color:#ef4444;padding:4px 7px;margin-top:4px"
                    onclick="eqpRemoveQuestion(${sec.id},${qi})" title="Remove question">
              <i class="fas fa-times"></i>
            </button>
          </div>`).join('')
        }
        <button class="btn btn-ghost btn-sm" onclick="eqpAddQuestion(${sec.id})" style="margin-top:4px;width:100%">
          <i class="fas fa-plus"></i> Add Question
        </button>
      </div>
    </div>`;
  }).join('');
  eqpUpdateSummary();
}

// ── Save ────────────────────────────────────────────────────────────────────
async function eqpSave() {
  const id           = parseInt(document.getElementById('eqpId').value);
  const title        = document.getElementById('eqpTitle').value.trim();
  const version      = document.getElementById('eqpVersion').value;
  const total_marks  = parseFloat(document.getElementById('eqpMarks').value);
  const instructions = document.getElementById('eqpInstructions').value.trim();
  const errBanner    = document.getElementById('eqpError');
  const errMsg       = document.getElementById('eqpErrorMsg');
  const showErr = msg => { errMsg.textContent = msg; errBanner.style.display = 'block'; errBanner.scrollIntoView({behavior:'smooth',block:'nearest'}); };

  errBanner.style.display = 'none';
  if (!title)                       { showErr('Paper title is required.'); eqpShowTab(1); return; }
  if (!total_marks || total_marks<1){ showErr('Total marks must be at least 1.'); eqpShowTab(1); return; }
  if (_eqpSections.length === 0)    { showErr('Add at least one section.'); eqpShowTab(2); return; }

  const btn = document.getElementById('eqpSaveBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const payload = { id, title, version, total_marks, instructions, sections: _eqpSections };
  const res  = await fetch('examinations.php?ajax=edit_qpaper', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
  });
  const data = await res.json();
  btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Save Changes';
  if (data.error) { showErr(data.error); return; }
  closeModal('editQPaperModal');
  showToast(data.msg || 'Paper saved.', 'success');
  loadQPapers();
}

// ── Delete ──────────────────────────────────────────────────────────────────
function eqpConfirmDelete(id, title) {
  _eqpPaperId = id;
  if (!confirm(`Delete "${title}"?\n\nThis will permanently remove the paper and all its questions. This cannot be undone.`)) return;
  eqpDelete();
}

async function eqpDelete() {
  const id = _eqpPaperId || parseInt(document.getElementById('eqpId')?.value);
  if (!id) return;
  const res  = await fetch('examinations.php?ajax=delete_qpaper', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})
  });
  const data = await res.json();
  if (data.error) {
    const errBanner = document.getElementById('eqpError');
    const errMsg    = document.getElementById('eqpErrorMsg');
    if (errBanner && errMsg) { errMsg.textContent = data.error; errBanner.style.display = 'block'; }
    else showToast(data.error, 'error');
    return;
  }
  _eqpPaperId = null;
  closeModal('editQPaperModal');
  showToast(data.msg || 'Paper deleted.', 'success');
  loadQPapers();
}

function openUploadPaperModal(id, title) {
    document.getElementById('uploadPaperId').value = id;
    document.getElementById('uploadPaperTitle').textContent = title;
    document.getElementById('uploadPaperFile').value = '';
    document.getElementById('uploadPaperProgress').style.display = 'none';
    openModal('uploadPaperModal');
}

async function uploadPaperFile() {
    const id   = document.getElementById('uploadPaperId').value;
    const file = document.getElementById('uploadPaperFile').files[0];
    if (!file) { showToast('Select a file first','error'); return; }
    const fd = new FormData();
    fd.append('paper_id', id);
    fd.append('paper_file', file);
    const prog = document.getElementById('uploadPaperProgress');
    const bar  = document.getElementById('uploadPaperBar');
    const msg  = document.getElementById('uploadPaperMsg');
    prog.style.display = '';
    bar.style.width = '30%';
    msg.textContent = 'Uploading…';
    try {
        const resp = await fetch('?ajax=upload_paper_file', {method:'POST', body: fd});
        bar.style.width = '100%';
        const r = await resp.json();
        if (r?.success) {
            msg.textContent = 'Upload complete ✓';
            setTimeout(() => { closeModal('uploadPaperModal'); loadQPapers(); }, 700);
            showToast('File attached successfully','success');
        } else {
            msg.textContent = r?.error || 'Upload failed';
            showToast(r?.error||'Failed','error');
        }
    } catch(e) { msg.textContent = 'Upload error'; showToast('Upload failed','error'); }
}

function generateQPaperPDF(id, title) {
    // Open a print-friendly page for the paper (server-rendered)
    window.open(`?ajax=print_qpaper&id=${id}`, '_blank');
}

// ─── Section/Question builder state ─────────────────────────────
let _qpSections = [];   // [{name, attend, marksEach, type, questions:[{text,opts:[]}]}]
let _qpSectId   = 0;    // running section id

// Called when user changes exam — fetch & show which sets are already taken
// Tracks used versions for the selected exam
let _qpUsedVersions = [];

async function qpOnExamChange() {
    const examId = document.getElementById('qpExam').value;
    const hint   = document.getElementById('qpVersionHint');
    const input  = document.getElementById('qpVersion');
    _qpUsedVersions = [];

    if (!examId) { hint.innerHTML = ''; input.value = 'A'; return; }

    hint.innerHTML = '<span style="color:var(--muted)">Checking…</span>';
    const used = await api('used_versions&exam_id=' + examId);
    _qpUsedVersions = Array.isArray(used) ? used : [];

    // Auto-suggest next free label
    const allLabels = ['A','B','C','D','E','F','G','H'];
    const nextFree  = allLabels.find(v => !_qpUsedVersions.includes(v)) || '';
    input.value = nextFree;

    qpCheckVersionConflict(); // render hint based on new value
}

// Called on every keystroke in the version input
function qpCheckVersionConflict() {
    const input  = document.getElementById('qpVersion');
    const hint   = document.getElementById('qpVersionHint');
    const val    = (input.value || '').trim().toUpperCase();
    input.value  = val; // normalise to uppercase

    if (!val) {
        hint.innerHTML = '<span style="color:#ef4444"><i class="fas fa-triangle-exclamation"></i> Set Version cannot be empty.</span>';
        input.style.border = '2px solid #ef4444';
        return;
    }

    // Build taken badges
    const takenBadges = _qpUsedVersions.map(v =>
        `<span style="display:inline-flex;align-items:center;gap:3px;background:rgba(239,68,68,.12);
                      border:1px solid rgba(239,68,68,.35);color:#ef4444;border-radius:4px;
                      padding:1px 7px;font-size:.7rem;font-weight:700">
           <i class="fas fa-lock" style="font-size:.6rem"></i> Set ${v}
         </span>`
    ).join(' ');

    if (_qpUsedVersions.includes(val)) {
        input.style.border = '2px solid #ef4444';
        hint.innerHTML = `<span style="color:#ef4444"><i class="fas fa-triangle-exclamation"></i>
            <b>Set ${val}</b> already exists for this exam.</span>
            ${takenBadges ? '<br><span style="color:var(--muted)">Taken: ' + takenBadges + '</span>' : ''}`;
    } else {
        input.style.border = '2px solid #10b981';
        if (_qpUsedVersions.length === 0) {
            hint.innerHTML = '<span style="color:#10b981"><i class="fas fa-circle-check"></i> No papers exist yet for this exam.</span>';
        } else {
            hint.innerHTML = `<span style="color:#10b981"><i class="fas fa-circle-check"></i>
                <b>Set ${val}</b> is available.</span>
                ${takenBadges ? ' &nbsp; <span style="color:var(--muted)">Taken: ' + takenBadges + '</span>' : ''}`;
        }
    }
}

// Step 1 → Step 2
function qpGoStep2() {
    const exam_id = document.getElementById('qpExam').value;
    const title   = document.getElementById('qpTitle').value.trim();
    if (!exam_id) { showToast('Please select an exam', 'error'); return; }
    if (!title)   { showToast('Please enter a paper title', 'error'); return; }
    // Validate version not taken
    const versionVal = (document.getElementById('qpVersion').value || '').trim().toUpperCase();
    if (!versionVal) { showToast('Please enter a Set Version', 'error'); return; }
    if (_qpUsedVersions.includes(versionVal)) {
        showToast(`Set ${versionVal} is already taken. Please choose a different version.`, 'error');
        document.getElementById('qpVersion').focus();
        return;
    }
    document.getElementById('qpVersion').value = versionVal; // normalise

    // Update summary bar
    document.getElementById('qpSumTitle').textContent   = title;
    document.getElementById('qpSumVersion').textContent = versionVal;
    document.getElementById('qpSumMarks').textContent   = document.getElementById('qpMarks').value;
    // Switch steps
    document.getElementById('qpStep1').style.display = 'none';
    document.getElementById('qpStep2').style.display = '';
    document.getElementById('qpStep1Btn').style.borderBottomColor = 'transparent';
    document.getElementById('qpStep1Btn').style.color = 'var(--muted)';
    document.getElementById('qpStep2Btn').style.borderBottomColor = 'var(--teal)';
    document.getElementById('qpStep2Btn').style.color = 'var(--teal)';
    // Auto-add first section if empty
    if (_qpSections.length === 0) qpAddSection();
    else qpRenderSections();
}

function qpGoStep1() {
    document.getElementById('qpStep1').style.display = '';
    document.getElementById('qpStep2').style.display = 'none';
    document.getElementById('qpStep1Btn').style.borderBottomColor = 'var(--teal)';
    document.getElementById('qpStep1Btn').style.color = 'var(--teal)';
    document.getElementById('qpStep2Btn').style.borderBottomColor = 'transparent';
    document.getElementById('qpStep2Btn').style.color = 'var(--muted)';
}

// Add a new blank section
function qpAddSection() {
    const id = ++_qpSectId;
    _qpSections.push({ id, name: 'Section ' + String.fromCharCode(64 + _qpSections.length + 1),
                       type: 'short', attend: 5, marksEach: 2, questions: [] });
    qpRenderSections();
    // Auto-scroll to new section
    setTimeout(() => {
        const el = document.getElementById('qpSec_' + id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
}

function qpRemoveSection(id) {
    _qpSections = _qpSections.filter(s => s.id !== id);
    qpRenderSections();
}

function qpAddQuestion(secId) {
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec) return;
    sec.questions.push({ text: '', opts: sec.type === 'mcq' ? ['','','',''] : [], image_url: null, table_html: null });
    qpRenderSections();
    setTimeout(() => {
        const inputs = document.querySelectorAll(`#qpSec_${secId} .qp-q-txt`);
        if (inputs.length) inputs[inputs.length - 1].focus();
    }, 60);
}

function qpRemoveQuestion(secId, qIdx) {
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec) return;
    sec.questions.splice(qIdx, 1);
    qpRenderSections();
}

// Sync live field changes back to state
// Helper: build the notice bar text for a section
function qpNoticeText(sec, totalQs) {
    const attend    = parseInt(sec.attend)    || 0;
    const marksEach = parseFloat(sec.marksEach) || 0;
    const total     = attend * marksEach;
    const qCount    = (totalQs !== undefined ? totalQs : sec.questions.length) || 0;
    // "Attempt all" only when attend equals the total number of questions entered
    if (qCount > 0 && attend === qCount) {
        return `Attempt all <b>${qCount}</b> questions &nbsp;|&nbsp; <b>${marksEach} × ${attend} = ${total} marks</b>`;
    }
    // attend > qCount: more attempts than questions entered (user still adding questions)
    if (qCount > 0 && attend > qCount) {
        return `Attempt any <b>${attend}</b> questions (${qCount} entered so far) &nbsp;|&nbsp; <b>${marksEach} × ${attend} = ${total} marks</b>`;
    }
    // attend < qCount: normal case — choose N from more
    return `Attempt any <b>${attend}</b> out of <b>${qCount}</b> questions &nbsp;|&nbsp; <b>${marksEach} × ${attend} = ${total} marks</b>`;
}

function qpSyncField(secId, field, val) {
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec) return;
    if (field === 'type') {
        sec.type = val;
        sec.questions = sec.questions.map(q => ({
            text: q.text,
            opts: val === 'mcq' ? (q.opts.length ? q.opts : ['','','','']) : [],
            image_url: q.image_url || null,
            table_html: q.table_html || null,
        }));
        qpRenderSections();
        return;
    }
    sec[field] = val;
    // Patch live DOM elements directly — no full re-render needed
    const attend    = parseInt(sec.attend)    || 0;
    const marksEach = parseFloat(sec.marksEach) || 0;
    const total     = attend * marksEach;
    const totalEl   = document.getElementById('qpTotal_' + secId);
    if (totalEl) totalEl.textContent = '= ' + total + ' Marks';
    const noticeEl  = document.getElementById('qpNoticeText_' + secId);
    if (noticeEl) noticeEl.innerHTML = qpNoticeText(sec, sec.questions.length);
    qpUpdateSummary();
}

function qpSyncQuestion(secId, qIdx, field, val) {
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec || !sec.questions[qIdx]) return;
    if (field === 'text') sec.questions[qIdx].text = val;
    else if (field.startsWith('opt')) {
        const oi = parseInt(field.replace('opt',''));
        sec.questions[qIdx].opts[oi] = val;
    }
}

function qpUpdateSummary() {
    let sectionTotal = 0;
    _qpSections.forEach(s => {
        const attend    = parseInt(s.attend)    || 0;
        const marksEach = parseFloat(s.marksEach) || 0;
        const t = attend * marksEach;
        sectionTotal += t;
        // Also patch the inline total span for this section (covers any missed oninput)
        const totalEl = document.getElementById('qpTotal_' + s.id);
        if (totalEl) totalEl.textContent = '= ' + t + ' Marks';
        const noticeEl = document.getElementById('qpNoticeText_' + s.id);
        if (noticeEl) noticeEl.innerHTML = qpNoticeText(s, s.questions.length);
    });
    const declared = parseFloat(document.getElementById('qpMarks').value) || 0;
    document.getElementById('qpSumMarks').textContent = declared;
    const alertEl = document.getElementById('qpMarkAlert');
    if (declared > 0 && Math.abs(sectionTotal - declared) > 0.01) {
        alertEl.style.display = '';
        alertEl.innerHTML = `<i class="fas fa-triangle-exclamation"></i> Section marks total: <b>${sectionTotal}</b> but paper total is <b>${declared}</b>`;
    } else {
        alertEl.style.display = 'none';
    }
}

const _qTypeLabels = {
    mcq:'MCQ', short:'Short Answer', long:'Long Answer / Essay',
    true_false:'True / False', fill_blank:'Fill in the Blank'
};

function qpRenderSections() {
    const wrap = document.getElementById('qpSectionsWrap');
    if (!wrap) return;
    wrap.innerHTML = _qpSections.map((sec, si) => {
        const total = (parseFloat(sec.marksEach)||0) * (parseInt(sec.attend)||0);
        const totalQs = sec.questions.length;
        return `
        <div class="qp-section-card" id="qpSec_${sec.id}" style="
            background:var(--card);border:1px solid var(--border);border-radius:10px;
            margin-bottom:12px;overflow:hidden">
          <!-- Section header -->
          <div style="background:var(--bg);padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid var(--border)">
            <input class="form-control" style="max-width:180px;font-weight:700;font-size:.85rem"
                   value="${esc(sec.name)}" oninput="qpSyncField(${sec.id},'name',this.value)"
                   placeholder="Section name">
            <select class="form-control" style="max-width:150px;font-size:.8rem"
                    onchange="qpSyncField(${sec.id},'type',this.value)">
              ${Object.entries(_qTypeLabels).map(([v,l])=>
                `<option value="${v}" ${sec.type===v?'selected':''}>${l}</option>`).join('')}
            </select>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <label style="font-size:.75rem;color:var(--muted);white-space:nowrap">Attempt:</label>
              <input type="number" min="1" class="form-control" style="width:60px;font-size:.82rem;text-align:center"
                     value="${sec.attend}" oninput="qpSyncField(${sec.id},'attend',this.value);qpUpdateSummary()">
              <span style="font-size:.75rem;color:var(--muted)">×</span>
              <input type="number" min="0" step="0.5" class="form-control" style="width:64px;font-size:.82rem;text-align:center"
                     value="${sec.marksEach}" oninput="qpSyncField(${sec.id},'marksEach',this.value);qpUpdateSummary()">
              <span style="font-size:.75rem;color:var(--muted)">M each</span>
              <span id="qpTotal_${sec.id}" style="font-size:.82rem;font-weight:700;color:var(--teal);white-space:nowrap">
                = ${total} Marks
              </span>
            </div>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
              <span id="qpQCount_${sec.id}" style="font-size:.72rem;color:var(--muted)">${totalQs} Q${totalQs!==1?'s':''} entered</span>
              ${_qpSections.length > 1
                ? `<button class="btn btn-ghost btn-sm" style="color:#ef4444" onclick="qpRemoveSection(${sec.id})" title="Remove section"><i class="fas fa-trash-can"></i></button>`
                : ''}
            </div>
          </div>

          <!-- Attend notice -->
          <div id="qpNotice_${sec.id}" style="padding:7px 14px;background:rgba(0,212,187,.07);border-bottom:1px solid var(--border);font-size:.76rem;color:var(--teal)">
            <i class="fas fa-circle-info"></i>
            <span id="qpNoticeText_${sec.id}">${qpNoticeText(sec, totalQs)}</span>
          </div>

          <!-- Questions list -->
          <div style="padding:10px 14px">
            ${sec.questions.length === 0
              ? `<div style="color:var(--muted);font-size:.8rem;font-style:italic;text-align:center;padding:8px">
                   No questions yet — click "Add Question" below.
                 </div>`
              : sec.questions.map((q, qi) => `
                <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;padding:10px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px">
                  <span style="font-weight:700;font-size:.82rem;color:var(--muted);padding-top:8px;min-width:22px">${qi+1}.</span>
                  <div style="flex:1">
                    <textarea class="form-control qp-q-txt" rows="2"
                      style="font-size:.85rem;resize:vertical"
                      placeholder="Enter question ${qi+1}…"
                      oninput="qpSyncQuestion(${sec.id},${qi},'text',this.value)">${esc(q.text)}</textarea>
                    ${sec.type === 'mcq' ? `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;margin-top:5px">
                      ${['A','B','C','D'].map((l,oi) => `
                      <div style="display:flex;gap:5px;align-items:center">
                        <span style="font-size:.75rem;font-weight:700;min-width:14px;color:var(--muted)">${l}.</span>
                        <input class="form-control" style="font-size:.8rem;padding:4px 8px"
                               value="${esc(q.opts[oi]||'')}"
                               placeholder="Option ${l}"
                               oninput="qpSyncQuestion(${sec.id},${qi},'opt${oi}',this.value)">
                      </div>`).join('')}
                    </div>` : ''}
                    ${sec.type === 'true_false'
                      ? `<div style="font-size:.78rem;color:var(--muted);margin-top:4px"><i class="fas fa-circle-info"></i> True / False — options printed automatically</div>` : ''}

                    <!-- ── Media toolbar ── -->
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:7px">
                      <label style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
                                    background:rgba(15,118,110,.08);border:1px solid rgba(15,118,110,.25);
                                    border-radius:6px;font-size:.74rem;color:var(--teal);transition:background .15s"
                             title="Upload an image for this question"
                             onmouseover="this.style.background='rgba(15,118,110,.16)'" onmouseout="this.style.background='rgba(15,118,110,.08)'">
                        <i class="fas fa-image"></i> Image
                        <input type="file" accept="image/*" style="display:none"
                               onchange="qpUploadImage(event,${sec.id},${qi})">
                      </label>
                      <button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 10px;border:1px solid var(--border)"
                              onclick="qpToggleTable(${sec.id},${qi})" title="Add / edit a table">
                        <i class="fas fa-table"></i> Table
                      </button>
                      ${q.image_url
                        ? `<span style="font-size:.7rem;color:#10b981;display:inline-flex;align-items:center;gap:4px">
                             <i class="fas fa-circle-check"></i> Image attached
                             <button class="btn btn-ghost btn-sm" style="color:#ef4444;padding:1px 5px;font-size:.65rem"
                                     onclick="qpRemoveImage(${sec.id},${qi})" title="Remove image">✕</button>
                           </span>` : ''}
                    </div>

                    <!-- Image preview -->
                    ${q.image_url ? `
                    <div style="margin-top:6px">
                      <img src="${esc(q.image_url)}" alt="Q${qi+1} image"
                           style="max-width:100%;max-height:180px;border-radius:6px;border:1px solid var(--border);object-fit:contain">
                    </div>` : ''}

                    <!-- Table builder (shown/hidden) -->
                    <div id="qpTblBuilder_${sec.id}_${qi}"
                         style="display:${q.table_html ? '' : 'none'};margin-top:8px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:8px">
                      <div style="font-size:.74rem;font-weight:600;color:var(--teal);margin-bottom:6px">
                        <i class="fas fa-table"></i> Table Editor
                        <button class="btn btn-ghost btn-sm" style="float:right;font-size:.68rem;padding:2px 8px"
                                onclick="qpApplyTable(${sec.id},${qi})">
                          <i class="fas fa-check"></i> Apply
                        </button>
                      </div>
                      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                        <label style="font-size:.74rem;color:var(--muted)">Rows:</label>
                        <input type="number" min="1" max="20" value="3" id="qpTblRows_${sec.id}_${qi}"
                               class="form-control" style="width:58px;font-size:.8rem;padding:3px 7px">
                        <label style="font-size:.74rem;color:var(--muted)">Cols:</label>
                        <input type="number" min="1" max="10" value="3" id="qpTblCols_${sec.id}_${qi}"
                               class="form-control" style="width:58px;font-size:.8rem;padding:3px 7px">
                        <button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 9px"
                                onclick="qpBuildTableGrid(${sec.id},${qi})">
                          <i class="fas fa-refresh"></i> Generate
                        </button>
                        ${q.table_html ? `<button class="btn btn-ghost btn-sm" style="font-size:.74rem;padding:3px 9px;color:#ef4444"
                                onclick="qpRemoveTable(${sec.id},${qi})"><i class="fas fa-trash-can"></i> Remove Table</button>` : ''}
                      </div>
                      <div id="qpTblGrid_${sec.id}_${qi}" style="overflow-x:auto">
                        ${q.table_html ? qpTableToEditGrid(q.table_html, sec.id, qi) : '<div style="font-size:.75rem;color:var(--muted)">Set rows/cols and click Generate.</div>'}
                      </div>
                    </div>

                    <!-- Table preview (when table is set but builder is hidden) -->
                    ${q.table_html && document.getElementById('qpTblBuilder_'+sec.id+'_'+qi)?.style.display === 'none' ? `
                    <div style="margin-top:6px;overflow-x:auto;font-size:.82rem">${q.table_html}</div>` : ''}

                  </div>
                  <button class="btn btn-ghost btn-sm" style="color:#ef4444;flex-shrink:0;margin-top:4px"
                          onclick="qpRemoveQuestion(${sec.id},${qi})" title="Remove"><i class="fas fa-xmark"></i></button>
                </div>`).join('')
            }
            <button class="btn btn-ghost btn-sm" style="margin-top:4px" onclick="qpAddQuestion(${sec.id})">
              <i class="fas fa-plus"></i> Add Question
            </button>
          </div>
        </div>`;
    }).join('');
    qpUpdateSummary();
}

function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ══════════════════════════════════════════════════════════════════
//  QUESTION MEDIA HELPERS — Image Upload + Table Builder
//  Used by both Create (qp*) and Edit (eqp*) question paper modals
// ══════════════════════════════════════════════════════════════════

// ── Table helpers ─────────────────────────────────────────────────

/** Build an editable HTML grid from a stored table_html string */
function _tableToEditGrid(tblHtml, prefix, secId, qi) {
    const tmp = document.createElement('div');
    tmp.innerHTML = tblHtml;
    const rows = tmp.querySelectorAll('tr');
    if (!rows.length) return '<div style="font-size:.75rem;color:var(--muted)">Set rows/cols and click Generate.</div>';
    let html = '<table style="border-collapse:collapse;width:100%;font-size:.8rem">';
    rows.forEach((row, ri) => {
        html += '<tr>';
        row.querySelectorAll('th,td').forEach((cell, ci) => {
            const isHead = ri === 0;
            const bg = isHead ? 'background:rgba(15,118,110,.1)' : '';
            html += `<td style="padding:0;border:1px solid var(--border);${bg}">
                <input type="text" value="${(cell.textContent||'').replace(/"/g,'&quot;')}"
                       style="width:100%;box-sizing:border-box;padding:4px 6px;border:none;background:transparent;font-size:.8rem;font-weight:${isHead?700:400}"
                       oninput="" data-ri="${ri}" data-ci="${ci}">
            </td>`;
        });
        html += '</tr>';
    });
    html += '</table>';
    return html;
}

function qpTableToEditGrid(tblHtml, secId, qi)  { return _tableToEditGrid(tblHtml, 'qp', secId, qi); }
function eqpTableToEditGrid(tblHtml, secId, qi) { return _tableToEditGrid(tblHtml, 'eqp', secId, qi); }

/** Read a rendered edit grid back to an HTML table string */
function _readTableGrid(gridId) {
    const grid = document.getElementById(gridId);
    if (!grid) return null;
    const rows = grid.querySelectorAll('tr');
    if (!rows.length) return null;
    let html = '<table style="border-collapse:collapse;width:100%;font-size:.9rem">';
    rows.forEach((row, ri) => {
        html += '<tr>';
        row.querySelectorAll('input').forEach((inp, ci) => {
            const val = (inp.value || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            if (ri === 0) {
                html += `<th style="border:1px solid #ccc;padding:5px 8px;background:#e8f5f3;font-weight:700">${val}</th>`;
            } else {
                html += `<td style="border:1px solid #ccc;padding:5px 8px">${val}</td>`;
            }
        });
        html += '</tr>';
    });
    html += '</table>';
    return html;
}

/** Build a blank editable grid given row/col inputs */
function _buildBlankGrid(rowsInputId, colsInputId, gridId) {
    const rows = Math.max(1, parseInt(document.getElementById(rowsInputId)?.value) || 3);
    const cols = Math.max(1, parseInt(document.getElementById(colsInputId)?.value) || 3);
    const grid = document.getElementById(gridId);
    if (!grid) return;
    let html = '<table style="border-collapse:collapse;width:100%;font-size:.8rem">';
    for (let ri = 0; ri < rows; ri++) {
        html += '<tr>';
        for (let ci = 0; ci < cols; ci++) {
            const isHead = ri === 0;
            const bg = isHead ? 'background:rgba(15,118,110,.1)' : '';
            html += `<td style="padding:0;border:1px solid var(--border);${bg}">
                <input type="text" placeholder="${isHead ? 'Header ' + (ci+1) : ''}"
                       style="width:100%;box-sizing:border-box;padding:4px 6px;border:none;background:transparent;font-size:.8rem;font-weight:${isHead?700:400}"
                       data-ri="${ri}" data-ci="${ci}">
            </td>`;
        }
        html += '</tr>';
    }
    html += '</table>';
    grid.innerHTML = html;
}

// ── Create-modal table functions ──────────────────────────────────

function qpToggleTable(secId, qi) {
    const el = document.getElementById(`qpTblBuilder_${secId}_${qi}`);
    if (!el) return;
    const willShow = el.style.display === 'none';
    el.style.display = willShow ? '' : 'none';
    if (willShow) {
        const sec = _qpSections.find(s => s.id === secId);
        const q = sec?.questions[qi];
        if (!q?.table_html) {
            _buildBlankGrid(`qpTblRows_${secId}_${qi}`, `qpTblCols_${secId}_${qi}`, `qpTblGrid_${secId}_${qi}`);
        }
    }
}

function qpBuildTableGrid(secId, qi) {
    _buildBlankGrid(`qpTblRows_${secId}_${qi}`, `qpTblCols_${secId}_${qi}`, `qpTblGrid_${secId}_${qi}`);
}

function qpApplyTable(secId, qi) {
    const html = _readTableGrid(`qpTblGrid_${secId}_${qi}`);
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec || !sec.questions[qi]) return;
    sec.questions[qi].table_html = html;
    showToast('Table applied ✓', 'success');
    qpRenderSections();
}

function qpRemoveTable(secId, qi) {
    const sec = _qpSections.find(s => s.id === secId);
    if (!sec || !sec.questions[qi]) return;
    sec.questions[qi].table_html = null;
    qpRenderSections();
}

// ── Create-modal image functions ──────────────────────────────────

async function qpUploadImage(event, secId, qi) {
    const file = event.target.files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('image', file);
    showToast('Uploading image…', 'info');
    try {
        const resp = await fetch('?ajax=upload_question_image', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        const sec = _qpSections.find(s => s.id === secId);
        if (sec?.questions[qi]) {
            sec.questions[qi].image_url = data.url;
            qpRenderSections();
            showToast('Image uploaded ✓', 'success');
        }
    } catch(e) { showToast('Upload failed: ' + e.message, 'error'); }
}

function qpRemoveImage(secId, qi) {
    const sec = _qpSections.find(s => s.id === secId);
    if (sec?.questions[qi]) { sec.questions[qi].image_url = null; qpRenderSections(); }
}

// ── Edit-modal table functions ────────────────────────────────────

function eqpToggleTable(secId, qi) {
    const el = document.getElementById(`eqpTblBuilder_${secId}_${qi}`);
    if (!el) return;
    const willShow = el.style.display === 'none';
    el.style.display = willShow ? '' : 'none';
    if (willShow) {
        const sec = _eqpSections.find(s => s.id === secId);
        const q = sec?.questions[qi];
        if (!q?.table_html) {
            _buildBlankGrid(`eqpTblRows_${secId}_${qi}`, `eqpTblCols_${secId}_${qi}`, `eqpTblGrid_${secId}_${qi}`);
        }
    }
}

function eqpBuildTableGrid(secId, qi) {
    _buildBlankGrid(`eqpTblRows_${secId}_${qi}`, `eqpTblCols_${secId}_${qi}`, `eqpTblGrid_${secId}_${qi}`);
}

function eqpApplyTable(secId, qi) {
    const html = _readTableGrid(`eqpTblGrid_${secId}_${qi}`);
    const sec = _eqpSections.find(s => s.id === secId);
    if (!sec || !sec.questions[qi]) return;
    sec.questions[qi].table_html = html;
    showToast('Table applied ✓', 'success');
    eqpRenderSections();
}

function eqpRemoveTable(secId, qi) {
    const sec = _eqpSections.find(s => s.id === secId);
    if (!sec || !sec.questions[qi]) return;
    sec.questions[qi].table_html = null;
    eqpRenderSections();
}

// ── Edit-modal image functions ────────────────────────────────────

async function eqpUploadImage(event, secId, qi) {
    const file = event.target.files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('image', file);
    showToast('Uploading image…', 'info');
    try {
        const resp = await fetch('?ajax=upload_question_image', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        const sec = _eqpSections.find(s => s.id === secId);
        if (sec?.questions[qi]) {
            sec.questions[qi].image_url = data.url;
            eqpRenderSections();
            showToast('Image uploaded ✓', 'success');
        }
    } catch(e) { showToast('Upload failed: ' + e.message, 'error'); }
}

function eqpRemoveImage(secId, qi) {
    const sec = _eqpSections.find(s => s.id === secId);
    if (sec?.questions[qi]) { sec.questions[qi].image_url = null; eqpRenderSections(); }
}

// ── Create Paper (submit) ─────────────────────────────────────────
async function createQPaper() {
    const exam_id = document.getElementById('qpExam').value;
    const title   = document.getElementById('qpTitle').value.trim();
    if (!exam_id || !title) { showToast('Fill required fields', 'error'); qpGoStep1(); return; }

    // Validate: every section must have at least 1 question
    for (const sec of _qpSections) {
        if (sec.questions.length === 0) {
            showToast(`"${sec.name}" has no questions. Add at least one question or remove the section.`, 'error');
            return;
        }
        const blank = sec.questions.find(q => !q.text.trim() && !q.image_url && !q.table_html);
        if (blank) {
            showToast(`Empty question found in "${sec.name}". Please fill all questions or attach an image.`, 'error');
            return;
        }
    }

    const status = document.getElementById('qpCreateStatus');
    status.textContent = 'Saving…';
    status.style.color = 'var(--muted)';

    // Compute total_questions from all sections
    const totalQs = _qpSections.reduce((a, s) => a + s.questions.length, 0);

    const body = {
        exam_id,
        title,
        version:         document.getElementById('qpVersion').value,
        total_marks:     document.getElementById('qpMarks').value,
        total_questions: totalQs,
        instructions:    document.getElementById('qpInstructions').value,
        sections:        _qpSections
    };

    try {
        const resp = await fetch('?ajax=create_qpaper', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const text = await resp.text();
        let r;
        try { r = JSON.parse(text); }
        catch { status.textContent = 'Error'; showToast('Server error: ' + text.slice(0,120), 'error'); return; }

        if (r?.success) {
            status.textContent = 'Saved ✓';
            status.style.color = 'var(--green)';
            showToast('Question paper created ✓', 'success');
            _qpSections = []; _qpSectId = 0;
            setTimeout(() => closeModal('qpaperModal'), 500);
            loadQPapers(); loadStats();
        } else {
            status.textContent = 'Failed';
            status.style.color = '#ef4444';
            const errMsg = r?.error || 'Failed to create paper';
            showToast(errMsg, 'error');
            // Duplicate Set Version error — jump back to Step 1 and highlight version field
            if (errMsg.toLowerCase().includes('set') && errMsg.toLowerCase().includes('already')) {
                qpGoStep1();
                const versionSel = document.getElementById('qpVersion');
                if (versionSel) {
                    versionSel.style.border = '2px solid #ef4444';
                    versionSel.focus();
                    setTimeout(() => versionSel.style.border = '', 3000);
                }
                qpOnExamChange(); // re-check to show which sets are taken
                document.getElementById('qpVersion').value = ''; // clear so user picks a new one
            }
        }
    } catch(e) {
        status.textContent = 'Network error';
        status.style.color = '#ef4444';
        showToast('Network error: ' + e.message, 'error');
    }
}

async function updateQPaperStatus(id, status) {
    const r = await api('update_qpaper_status','','POST',{id,status});
    if (r?.success) { showToast(`Status → ${ucf(status.replace('_',' '))}`); loadQPapers(); }
}

// ═══ QUESTION BANK ═══════════════════════════════════════════════
async function loadQBank() {
    const wrap = document.getElementById('qbankWrap');
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    const q     = document.getElementById('qbankSearch').value;
    const qtype = document.getElementById('qbankType').value;
    const diff  = document.getElementById('qbankDiff').value;
    const sub   = document.getElementById('qbankSubject').value;
    const data  = await api('qbank', `&q=${encodeURIComponent(q)}&qtype=${encodeURIComponent(qtype)}&diff=${encodeURIComponent(diff)}&subject=${encodeURIComponent(sub)}`);
    if (!data) { wrap.innerHTML='<div class="empty-state"><i class="fas fa-database"></i>Failed to load.</div>'; return; }
    const {questions,stats} = data;
    // Stats row
    const typeCount = {};
    (stats||[]).forEach(s => typeCount[s.question_type] = (typeCount[s.question_type]||0) + parseInt(s.cnt));
    let statsHtml = `<div style="display:flex;gap:10px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border)">`;
    const typeIcons = {
        mcq:'fa-list-ul', true_false:'fa-toggle-on', fill_blank:'fa-underline',
        match:'fa-arrows-left-right', assertion_reason:'fa-scale-balanced',
        objective:'fa-circle-dot', very_short:'fa-minus', short:'fa-pen',
        open_ended:'fa-comment-dots', long:'fa-align-left', descriptive:'fa-file-lines',
        problem_solving:'fa-calculator', case_study:'fa-briefcase',
        diagram:'fa-diagram-project', subjective:'fa-book-open'
    };
    Object.entries(typeCount).forEach(([t,c]) => {
        statsHtml += `<div class="bp-sum-box"><div class="bp-sum-val si-teal" style="color:var(--teal)"><i class="fas ${typeIcons[t]||'fa-question'}"></i></div><div class="bp-sum-val">${c}</div><div class="bp-sum-lbl">${ucf(t.replace('_',' '))}</div></div>`;
    });
    statsHtml += '</div>';
    if (!questions.length) { wrap.innerHTML=statsHtml+'<div class="empty-state"><i class="fas fa-database"></i>No questions match your filters.</div>'; return; }
    let qHtml = `<div class="qb-list" style="padding:16px 20px">`;
    questions.forEach(q => {
        const opts = q.options ? q.options.split(';;').filter(Boolean).map(o => { const [t,c]=o.split('|'); return {text:t,correct:c=='1'}; }) : [];
        qHtml += `<div class="q-card">
          <div class="q-text">${q.question_text}</div>
          ${opts.length ? `<div class="q-opts">${opts.map(o=>`<div class="q-opt ${o.correct?'correct':''}"><i class="fas ${o.correct?'fa-check-circle':'fa-circle'}"></i>${o.text}</div>`).join('')}</div>` : ''}
          <div class="q-meta">
            <span class="pill ${q.difficulty}">${ucf(q.difficulty)}</span>
            <span class="pill draft">${ucf(q.question_type.replace('_',' '))}</span>
            ${q.bloom_level?`<span class="bloom-tag">${ucf(q.bloom_level)}</span>`:''}
            <span class="mono">${q.marks} mark${q.marks!=1?'s':''}</span>
            ${q.topic?`<span style="font-size:.7rem;color:var(--muted)"><i class="fas fa-tag" style="font-size:.6rem"></i> ${q.topic}</span>`:''}
            <span style="font-size:.68rem;color:var(--muted);margin-left:auto"><i class="fas fa-book-open" style="font-size:.6rem"></i> ${q.subject_name}</span>
            <button class="btn btn-red btn-sm" onclick="deleteQuestion(${q.id})"><i class="fas fa-trash"></i></button>
          </div>
        </div>`;
    });
    qHtml += '</div>';
    wrap.innerHTML = statsHtml + qHtml;
}

// MCQ Options in modal
// ── Question type → panel map ──────────────────────────────────
const QF_TEXT_TYPES = new Set(['short','long','very_short','descriptive',
    'problem_solving','case_study','open_ended','objective','subjective']);

function toggleQuestionFields() {
    const t = document.getElementById('qType').value;
    document.querySelectorAll('.qf-panel').forEach(p => p.style.display = 'none');
    if (t === 'mcq')              { document.getElementById('qf-mcq').style.display = ''; if(!document.getElementById('mcqOptions').children.length){addMCQOption();addMCQOption();addMCQOption();addMCQOption();} }
    else if (t === 'true_false')  { document.getElementById('qf-true_false').style.display = ''; }
    else if (t === 'fill_blank')  { document.getElementById('qf-fill_blank').style.display = ''; }
    else if (t === 'match')       { document.getElementById('qf-match').style.display = ''; if(!document.getElementById('matchPairs').children.length){addMatchPair();addMatchPair();} }
    else if (t === 'assertion_reason') { document.getElementById('qf-assertion_reason').style.display = ''; }
    else if (t === 'diagram')     { document.getElementById('qf-diagram').style.display = ''; }
    else if (QF_TEXT_TYPES.has(t)){ document.getElementById('qf-text').style.display = ''; }
}

// Legacy alias
function toggleMCQOptions() { toggleQuestionFields(); }

function openAddQModal() {
    // Reset all dynamic panels & fields
    document.getElementById('qText').value = '';
    document.getElementById('qTopic').value = '';
    document.getElementById('mcqOptions').innerHTML = '';
    document.getElementById('matchPairs').innerHTML = '';
    const tf = document.querySelector('input[name=tfAnswer]:checked');
    if (tf) tf.checked = false;
    ['fillBlankAnswers','arAssertion','arReason','textHint','diagramDesc','diagramAnswer']
        .forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('qType').value = 'mcq';
    toggleQuestionFields();
    openModal('addQModal');
}

function addMCQOption() {
    const c = document.getElementById('mcqOptions');
    const i = c.children.length;
    const div = document.createElement('div');
    div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
    div.innerHTML = `<input type="checkbox" style="flex-shrink:0;accent-color:var(--teal)" title="Mark as correct">
      <input type="text" class="form-control" placeholder="Option ${String.fromCharCode(65+i)}" style="flex:1">
      <button class="btn btn-red btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>`;
    c.appendChild(div);
}

function addMatchPair() {
    const c = document.getElementById('matchPairs');
    const i = c.children.length + 1;
    const div = document.createElement('div');
    div.style.cssText = 'display:grid;grid-template-columns:1fr 28px 1fr 32px;gap:6px;align-items:center;margin-bottom:6px';
    div.innerHTML = `<input type="text" class="form-control" placeholder="Left ${i}" style="font-size:.8rem">
      <span style="text-align:center;color:var(--teal);font-size:.8rem">→</span>
      <input type="text" class="form-control" placeholder="Right ${i}" style="font-size:.8rem">
      <button class="btn btn-red btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>`;
    c.appendChild(div);
}

async function saveQuestion() {
    const text   = document.getElementById('qText').value.trim();
    const course = document.getElementById('qCourse').value;
    const qtype  = document.getElementById('qType').value;
    if (!text || !course) { showToast('Fill required fields','error'); return; }

    // Collect type-specific data as options / extra_data
    const opts = [];
    let extra = {};

    if (qtype === 'mcq') {
        document.querySelectorAll('#mcqOptions>div').forEach(row => {
            const txt     = row.querySelector('input[type=text]').value.trim();
            const correct = row.querySelector('input[type=checkbox]').checked;
            if (txt) opts.push({text:txt, is_correct:correct?1:0});
        });
        if (!opts.length) { showToast('Add at least one MCQ option','error'); return; }

    } else if (qtype === 'true_false') {
        const sel = document.querySelector('input[name=tfAnswer]:checked');
        if (!sel) { showToast('Select True or False answer','error'); return; }
        opts.push({text:'True',  is_correct: sel.value==='true'  ? 1 : 0});
        opts.push({text:'False', is_correct: sel.value==='false' ? 1 : 0});

    } else if (qtype === 'fill_blank') {
        const answers = document.getElementById('fillBlankAnswers').value.trim();
        if (answers) {
            answers.split('\n').filter(Boolean).forEach((a,i) =>
                opts.push({text: a.trim(), is_correct:1}));
        }

    } else if (qtype === 'match') {
        document.querySelectorAll('#matchPairs>div').forEach((row, i) => {
            const inputs = row.querySelectorAll('input[type=text]');
            const left  = inputs[0]?.value.trim();
            const right = inputs[1]?.value.trim();
            if (left && right) opts.push({text: left + ' → ' + right, is_correct:1});
        });
        if (!opts.length) { showToast('Add at least one match pair','error'); return; }

    } else if (qtype === 'assertion_reason') {
        const A = document.getElementById('arAssertion').value.trim();
        const R = document.getElementById('arReason').value.trim();
        const code = document.getElementById('arCode').value;
        if (!A || !R) { showToast('Enter both Assertion and Reason','error'); return; }
        opts.push({text: 'Assertion: ' + A, is_correct:0});
        opts.push({text: 'Reason: '    + R, is_correct:0});
        opts.push({text: 'Answer Code: ' + code, is_correct:1});

    } else if (qtype === 'diagram') {
        const desc = document.getElementById('diagramDesc').value.trim();
        const ans  = document.getElementById('diagramAnswer').value.trim();
        if (desc) opts.push({text: '[Diagram] ' + desc, is_correct:0});
        if (ans)  opts.push({text: '[Answer] '  + ans,  is_correct:1});

    } else if (QF_TEXT_TYPES.has(qtype)) {
        const hint = document.getElementById('textHint').value.trim();
        if (hint) opts.push({text: hint, is_correct:1});
    }

    const body = {
        course_id:       course,
        question_text:   text,
        question_type:   qtype,
        difficulty:      document.getElementById('qDiff').value,
        marks:           document.getElementById('qMarks').value,
        topic:           document.getElementById('qTopic').value,
        bloom_level:     document.getElementById('qBloom').value,
        options:         opts,
    };
    const r = await api('add_question','','POST',body);
    if (r?.success) {
        showToast('Question added ✓','success');
        // Reset dynamic fields
        document.getElementById('mcqOptions').innerHTML = '';
        document.getElementById('matchPairs').innerHTML = '';
        closeModal('addQModal');
        loadQBank();
        loadStats();
    } else showToast('Failed','error');
}

async function deleteQuestion(id) {
    if (!confirm('Remove this question?')) return;
    const r = await api('delete_question','','POST',{id});
    if (r?.success) { showToast('Question removed','info'); loadQBank(); loadStats(); }
}

// ═══ BLUEPRINT ═══════════════════════════════════════════════════
async function loadBlueprint() {
    const exam_id = document.getElementById('bpExamSel').value;
    const sum = document.getElementById('blueprintSummary');
    const wrap = document.getElementById('blueprintWrap');
    const exportBtn = document.getElementById('exportBpBtn');
    if (!exam_id) { sum.innerHTML=''; wrap.innerHTML='<div class="empty-state"><i class="fas fa-sitemap"></i>Select an exam.</div>'; if(exportBtn) exportBtn.style.display='none'; return; }
    const data = await api('blueprint', `&exam_id=${exam_id}`);
    if (!data) { wrap.innerHTML='<div class="empty-state"><i class="fas fa-sitemap"></i>No data.</div>'; return; }
    const {sections, exam} = data;
    const totalMarks = sections.reduce((s,sec)=>s+parseFloat(sec.total_marks||0),0)||1;
    sum.innerHTML = `<div class="bp-summary">
      <div class="bp-sum-box"><div class="bp-sum-val" style="color:var(--teal)">${exam?.max_marks||'—'}</div><div class="bp-sum-lbl">Total Marks</div></div>
      <div class="bp-sum-box"><div class="bp-sum-val" style="color:var(--blue)">${sections.length}</div><div class="bp-sum-lbl">Sections</div></div>
      <div class="bp-sum-box"><div class="bp-sum-val" style="color:var(--green)">${exam?.duration_mins?fmtDur(exam.duration_mins):'—'}</div><div class="bp-sum-lbl">Duration</div></div>
      <div class="bp-sum-box"><div class="bp-sum-val" style="color:var(--amber)">${exam?.pass_marks||'—'}</div><div class="bp-sum-lbl">Pass Marks</div></div>
    </div>`;
    if (!sections.length) { wrap.innerHTML='<div class="empty-state"><i class="fas fa-sitemap"></i>No sections yet. Add one using the button above.</div>'; return; }
    if (exportBtn) exportBtn.style.display = '';
    const typeIcons={
        mcq:'fa-list-ul', true_false:'fa-toggle-on', fill_blank:'fa-underline',
        match:'fa-arrows-left-right', assertion_reason:'fa-scale-balanced',
        objective:'fa-circle-dot', very_short:'fa-minus', short:'fa-pen',
        open_ended:'fa-comment-dots', long:'fa-align-left', descriptive:'fa-file-lines',
        problem_solving:'fa-calculator', case_study:'fa-briefcase',
        diagram:'fa-diagram-project', subjective:'fa-book-open'
    };
    let html = '<div class="bp-body"><div class="bp-sections">';
    sections.forEach(sec => {
        const pct = Math.round((parseFloat(sec.total_marks)/totalMarks)*100);
        html += `<div class="bp-row">
          <div class="bp-info">
            <div class="bp-sec-name">${sec.section_name}</div>
            <div class="bp-sec-sub">${sec.num_questions} × ${sec.marks_per_q} marks · <i class="fas ${typeIcons[sec.question_type]||'fa-question'}"></i> ${ucf(sec.question_type.replace('_',' '))}</div>
            ${sec.bloom_levels?`<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">${sec.bloom_levels.split(',').map(b=>`<span class="bloom-tag">${b.trim()}</span>`).join('')}</div>`:''}
          </div>
          <div class="bp-bar-wrap"><div class="bp-bar"><div class="bp-fill" style="width:${pct}%"></div></div></div>
          <div class="bp-marks">${sec.total_marks}</div>
          <div class="bp-pct">${pct}%</div>
          <button class="btn btn-red btn-sm" onclick="deleteBlueprintSection(${sec.id})"><i class="fas fa-trash"></i></button>
        </div>`;
    });
    html += '</div></div>';
    wrap.innerHTML = html;
}

async function addBlueprintSection() {
    const exam_id = document.getElementById('bpExamSel').value;
    if (!exam_id) { showToast('Select exam first','error'); return; }
    const body = {
        exam_id,section_name:document.getElementById('bpSecName').value.trim(),
        question_type:document.getElementById('bpQType').value,
        num_questions:document.getElementById('bpNumQ').value,
        marks_per_q:document.getElementById('bpMarksPerQ').value,
        topic:document.getElementById('bpTopic').value,
        bloom_levels:document.getElementById('bpBloom').value,
        is_compulsory:document.getElementById('bpCompulsory').value,
    };
    if (!body.section_name) { showToast('Enter section name','error'); return; }
    const r = await api('add_blueprint_section','','POST',body);
    if (r?.success) { showToast('Section added'); closeModal('bpModal'); loadBlueprint(); }
    else showToast(r?.error||'Failed','error');
}

async function deleteBlueprintSection(id) {
    if (!confirm('Remove this section?')) return;
    const r = await api('delete_blueprint_section','','POST',{id});
    if (r?.success) { showToast('Section removed','info'); loadBlueprint(); }
}

// ═══ DROPDOWN POPULATION ════════════════════════════════════════
async function populateCoursesDropdown() {
    const courses = await api('courses_list');
    ['newExamCourse','qCourse'].forEach(id => {
        const sel = document.getElementById(id); if(!sel) return;
        sel.innerHTML = '<option value="">Select Course…</option>' + (courses||[]).map(c=>`<option value="${c.id}">${c.name} (${c.code})</option>`).join('');
    });
    const subSel = document.getElementById('qbankSubject'); if(!subSel) return;
    const existing = [...subSel.options].map(o=>o.textContent);
    (courses||[]).forEach(c => {
        if (!existing.includes(c.name)) { const o=document.createElement('option'); o.value=c.name; o.textContent=c.name; subSel.appendChild(o); }
    });
}

async function loadExamsDropdownFor(elId) {
    const sel = document.getElementById(elId); if(!sel) return;
    const data = await api('exams_dropdown');
    sel.innerHTML = '<option value="">Choose exam…</option>' + (data||[]).map(e=>`<option value="${e.id}">${e.label}</option>`).join('');
}

async function populateAllDropdowns() {
    // Halls
    const halls = await api('halls_list');
    const hallOpts = '<option value="">No hall</option>' + (halls||[]).map(h=>`<option value="${h.id}">${h.name} (${h.capacity})</option>`).join('');
    document.getElementById('newExamHall').innerHTML = hallOpts;
    // Schedules list (kept for qpaper modal)
    const scheds = await api('schedules_list');
    const schedOpts = '<option value="">Select Exam…</option>' + (scheds||[]).map(s=>`<option value="${s.id}">${s.label}</option>`).join('');
    // Exams dropdown for qpaper modal
    await loadExamsDropdownFor('qpExam');
    // Courses
    await populateCoursesDropdown();
    // Init MCQ options
    const qOpts = document.getElementById('mcqOptions');
    qOpts.innerHTML='';
    ['A','B','C','D'].forEach(l => {
        const div=document.createElement('div'); div.style.cssText='display:flex;gap:8px;align-items:center;margin-bottom:6px';
        div.innerHTML=`<input type="checkbox" style="flex-shrink:0;accent-color:var(--teal)" title="Mark as correct"><input type="text" class="form-control" placeholder="Option ${l}" style="flex:1"><button class="btn btn-red btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>`;
        qOpts.appendChild(div);
    });
}

// ── Utility ───────────────────────────────────────────────────────
function ucf(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// ── Logout ────────────────────────────────────────────────────────
async function doLogout() {
    try {
        const res = await fetch('../auth/auth_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
        const d = await res.json();
        if (d.redirect) window.location.href = d.redirect;
    } catch { window.location.href = '../login.php'; }
}


// ═══ FACULTY ASSIGNMENTS TAB ════════════════════════════════════════
async function loadFacultyAssignments() {
    const wrap = document.getElementById('facultyAssignWrap');
    const cWrap = document.getElementById('assignedCoursesWrap');
    if (wrap) wrap.innerHTML='<div class="loading-wrap"><div class="spinner"></div><p>Loading…</p></div>';
    const [assignments, courses] = await Promise.all([
        api('my_faculty_assignments'),
        api('my_assigned_courses')
    ]);

    // Render assignments table
    if (!wrap) return;
    if (!assignments || !assignments.length) {
        wrap.innerHTML='<div class="empty-state"><i class="fas fa-chalkboard-user"></i>No faculty assignments found for your account in this department.<br><small style="color:var(--muted)">Contact superadmin to assign courses.</small></div>';
    } else {
        let html = `<div style="overflow-x:auto"><table class="tbl"><thead><tr>
            <th>Course</th><th>Semester</th><th>Role</th><th>Period</th><th>Academic Year</th><th>Assigned By</th><th>Since</th>
        </tr></thead><tbody>`;
        assignments.forEach(a => {
            html += `<tr>
                <td><strong>${a.course_name}</strong><br><span style="font-size:.72rem;color:var(--muted)">${a.course_code}${a.credits?' · '+a.credits+' cr':''}</span></td>
                <td><span class="pill">${a.semester ? 'Sem '+a.semester : '—'}</span></td>
                <td>${a.is_primary?'<span class="pill approved"><i class="fas fa-star"></i> Primary</span>':'<span class="pill inactive">Assistant</span>'}</td>
                <td style="font-size:.78rem">${a.period||'—'}</td>
                <td style="font-size:.78rem">${a.academic_year||'—'}</td>
                <td style="font-size:.78rem">${a.assigned_by_name?'<span style="color:var(--amber)"><i class="fas fa-crown"></i></span> '+a.assigned_by_name:'System'}</td>
                <td style="font-size:.72rem;color:var(--muted)">${a.created_at?a.created_at.split(' ')[0]:'—'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    }

    // Render assigned courses detail
    if (!cWrap) return;
    if (!courses || !courses.length) {
        cWrap.innerHTML='<div class="empty-state"><i class="fas fa-book"></i>No assigned courses found.</div>'; return;
    }
    let cHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">';
    courses.forEach(c => {
        cHtml += `<div style="background:var(--navy3);border:1px solid var(--border);border-radius:10px;padding:14px">
            <div style="font-weight:700;color:var(--white);margin-bottom:4px">${c.name}</div>
            <div style="font-size:.72rem;color:var(--muted);margin-bottom:8px">${c.code}${c.credits?' · '+c.credits+' credits':''} ${c.semester?'· Sem '+c.semester:''}</div>
            ${c.is_primary!=null?`<div style="margin-bottom:6px">${c.is_primary?'<span class="pill approved" style="font-size:.65rem">Primary Faculty</span>':'<span class="pill inactive" style="font-size:.65rem">Assistant</span>'}</div>`:''}
            ${c.assigned_by_name?`<div style="font-size:.72rem;color:var(--amber)"><i class="fas fa-crown"></i> Assigned by: ${c.assigned_by_name}</div>`:''}
            ${c.academic_year?`<div style="font-size:.7rem;color:var(--muted);margin-top:4px">AY: ${c.academic_year} · ${c.period||'All Periods'}</div>`:''}
        </div>`;
    });
    cHtml += '</div>';
    cWrap.innerHTML = cHtml;
}

// ═══ APPROVALS TAB (superadmin/college_admin only) ══════════════════
async function loadPendingApprovals() {
    const wrap = document.getElementById('approvalsWrap');
    if (!wrap) return;
    wrap.innerHTML='<div class="loading-wrap"><div class="spinner"></div><p>Loading…</p></div>';
    const data = await api('pending_approvals');
    if (!data || !data.length) {
        wrap.innerHTML='<div class="empty-state"><i class="fas fa-check-circle" style="color:var(--green)"></i>No pending approvals. All Q.Papers are reviewed!</div>'; return;
    }
    let html = '<div style="display:flex;flex-direction:column;gap:14px">';
    data.forEach(p => {
        html += `<div style="background:var(--navy3);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:16px">
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <div style="font-weight:700;color:var(--white);font-size:.9rem">${p.title} <span style="font-size:.72rem;color:var(--muted)">v${p.version}</span></div>
                    <div style="font-size:.78rem;color:var(--muted);margin-top:3px">${p.exam_title} · ${p.course_name} (${p.course_code})</div>
                    <div style="font-size:.75rem;color:var(--muted);margin-top:3px">${p.dept_name} · ${p.total_marks} marks · Submitted by <strong style="color:var(--text)">${p.created_by_name}</strong></div>
                    <div style="font-size:.7rem;color:var(--muted);margin-top:2px">Submitted: ${p.created_at}</div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0">
                    <button class="btn btn-teal btn-sm" onclick="approveQPaper(${p.id},'approve')"><i class="fas fa-check"></i> Approve</button>
                    <button class="btn btn-red btn-sm" onclick="approveQPaper(${p.id},'reject')"><i class="fas fa-times"></i> Send Back</button>
                </div>
            </div>
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

async function approveQPaper(id, decision) {
    const label = decision==='approve' ? 'Approve this Q.Paper?' : 'Send this Q.Paper back to draft?';
    if (!confirm(label)) return;
    const r = await api('approve_qpaper','','POST',{id, decision});
    if (r?.success) {
        showToast(r.msg, decision==='approve'?'success':'info');
        loadPendingApprovals();
        // Update badge
        const badge = document.getElementById('badge-approvals');
        if (badge) { const n=parseInt(badge.textContent)-1; badge.textContent=n; if(n<=0) badge.style.display='none'; }
    } else showToast(r?.error||'Failed','error');
}

// ═══ ASSIGNED SCHEDULE (replaces default loadSchedule with scoped version) ═══
async function loadAssignedSchedule() {
    const wrap = document.getElementById('weekGridWrap');
    if (!wrap) return;
    wrap.innerHTML='<div class="loading-wrap"><div class="spinner"></div><p>Loading schedule…</p></div>';
    try {
        const from = document.getElementById('schedFrom')?.value || new Date().toISOString().split('T')[0];
        const to   = document.getElementById('schedTo')?.value   || new Date(Date.now()+30*864e5).toISOString().split('T')[0];
        const data = await api('assigned_schedule', `from=${from}&to=${to}`);
        if (!data?.slots?.length) {
            wrap.innerHTML='<div class="empty-state"><i class="fas fa-calendar"></i>No scheduled exams in this range for your assigned courses.</div>';
            return;
        }
        // Group by date
        const byDate = {};
        data.slots.forEach(s => { if(!byDate[s.exam_date]) byDate[s.exam_date]=[];  byDate[s.exam_date].push(s); });
        let html = '<div style="display:flex;flex-direction:column;gap:10px">';
        Object.entries(byDate).forEach(([date, slots]) => {
            const d = new Date(date); const dayLabel = d.toLocaleDateString('en-IN',{weekday:'short',day:'numeric',month:'short'});
            html += `<div style="background:var(--navy3);border:1px solid var(--border);border-radius:10px;overflow:hidden">
                <div style="background:rgba(0,212,187,.08);padding:10px 14px;font-weight:700;color:var(--teal);font-size:.8rem;border-bottom:1px solid var(--border)">${dayLabel}</div>
                <div style="padding:10px 14px;display:flex;flex-direction:column;gap:8px">`;
            slots.forEach(s => {
                const saIcon = (s.created_by_role==='super_admin'||s.created_by_role==='college_admin') ? '<span style="color:var(--amber);font-size:.65rem;margin-left:4px"><i class="fas fa-crown"></i></span>' : '';
                html += `<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:.8rem">
                    <span style="color:var(--muted);font-family:var(--mono)">${s.start_time?.slice(0,5)}–${s.end_time?.slice(0,5)}</span>
                    <span style="flex:1;font-weight:600;color:var(--white)">${s.title}${saIcon} <span style="font-weight:400;color:var(--muted)">(${s.course_code})</span></span>
                    ${s.hall_name?`<span style="color:var(--muted)"><i class="fas fa-door-open"></i> ${s.hall_name}</span>`:''}
                    ${s.invig_count?`<span style="color:var(--purple);font-size:.72rem"><i class="fas fa-user-tie"></i> ${s.invig_count}</span>`:''}
                    ${s.admit_count?`<span style="color:var(--green);font-size:.72rem"><i class="fas fa-id-card"></i> ${s.admit_count}</span>`:''}
                </div>`;
            });
            html += `</div></div>`;
        });
        html += '</div>';
        wrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading schedule:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load schedule. Please try again.</div>';
    }
}

// ═══ ASSIGNED HALL ALLOCATION VIEW ════════════════════════════════════
async function loadAssignedHalls() {
    const wrap = document.getElementById('hallsWrap');
    if (!wrap) return;
    wrap.innerHTML='<div class="loading-wrap"><div class="spinner"></div><p>Loading halls…</p></div>';
    try {
        const data = await api('assigned_halls');
        if (!data || !data.length) {
            wrap.innerHTML='<div class="empty-state"><i class="fas fa-door-open"></i>No hall allocations yet for your assigned exams. Superadmin assigns halls to schedules.</div>';
            return;
        }
        let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">';
        data.forEach(h => {
            const pct = Math.round((h.seats_assigned/h.capacity)*100)||0;
            html += `<div style="background:var(--navy3);border:1px solid var(--border);border-radius:12px;padding:16px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <div style="width:38px;height:38px;border-radius:9px;background:var(--teal3);display:grid;place-items:center;color:var(--teal)"><i class="fas fa-door-open"></i></div>
                    <div>
                        <div style="font-weight:700;color:var(--white)">${h.name}</div>
                        <div style="font-size:.72rem;color:var(--muted)">${h.code}${h.building?' · '+h.building:''}${h.floor!==null?' · Floor '+h.floor:''}</div>
                    </div>
                </div>
                <div style="font-size:.78rem;color:var(--text);margin-bottom:8px">
                    <strong style="color:var(--amber)"><i class="fas fa-file-invoice"></i> ${h.exam_title}</strong> <span style="color:var(--muted)">(${h.course_code})</span>
                </div>
                <div style="font-size:.75rem;color:var(--muted);margin-bottom:10px">
                    <i class="fas fa-calendar"></i> ${h.exam_date} &nbsp;
                    <i class="fas fa-clock"></i> ${h.start_time?.slice(0,5)}–${h.end_time?.slice(0,5)}
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:6px">
                    <span><i class="fas fa-chair"></i> Capacity: <strong>${h.capacity}</strong></span>
                    <span><i class="fas fa-user-check"></i> Assigned: <strong>${h.seats_assigned}</strong></span>
                </div>
                <div style="height:6px;background:var(--border);border-radius:4px;overflow:hidden">
                    <div style="height:100%;width:${pct}%;background:var(--teal);border-radius:4px;transition:width .4s"></div>
                </div>
                <div style="font-size:.7rem;color:var(--muted);margin-top:4px;text-align:right">${pct}% occupied</div>
            </div>`;
        });
        html += '</div>';
        wrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading halls:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load hall allocations. Please try again.</div>';
    }
}

// ═══ ASSIGNED INVIGILATORS VIEW ════════════════════════════════════════
async function loadAssignedInvigilators() {
    const invigWrap = document.getElementById('invigilatorsWrap');
    if (!invigWrap) return;
    invigWrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading invigilators…</p></div>';
    try {
        const data = await api('assigned_invigilators');
        if (!data || !data.length) {
            invigWrap.innerHTML='<div class="empty-state"><i class="fas fa-user-tie"></i>No invigilators assigned yet by superadmin for your exams.</div>';
            return;
        }
        let html = '<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Faculty</th><th>Exam</th><th>Date & Time</th><th>Hall</th><th>Duty</th><th>Status</th><th>Assigned By</th></tr></thead><tbody>';
        data.forEach(i => {
            const saIcon = i.assigned_by_name ? `<span style="color:var(--amber);font-size:.65rem"><i class="fas fa-crown"></i></span> ${i.assigned_by_name}` : 'System';
            html += `<tr>
                <td><strong>${i.faculty_name}</strong><br><span style="font-size:.7rem;color:var(--muted)">${i.dept_name||''} ${i.dept_code?'('+i.dept_code+')':''}</span></td>
                <td>${i.exam_title}<br><span style="font-size:.7rem;color:var(--muted)">${i.course_name} (${i.course_code})</span></td>
                <td style="font-size:.78rem">${i.exam_date}<br><span style="color:var(--muted)">${i.start_time?.slice(0,5)}–${i.end_time?.slice(0,5)}</span></td>
                <td style="font-size:.78rem">${i.hall_name||'—'}</td>
                <td><span class="pill ${i.duty_type==='chief'?'approved':''}">${i.duty_type||'assistant'}</span></td>
                <td>${i.is_confirmed?'<span class="pill approved"><i class="fas fa-check"></i> Confirmed</span>':'<span class="pill inactive">Pending</span>'}</td>
                <td style="font-size:.75rem">${saIcon}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        invigWrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading invigilators:', error);
        invigWrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load invigilators. Please try again.</div>';
    }
}

// ═══ ASSIGNED SEATING VIEW ════════════════════════════════════════════
async function loadAssignedSeating() {
    const wrap = document.getElementById('seatingWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading seating…</p></div>';
    try {
        const schedId = document.getElementById('seatSchedSel')?.value || '';
        const data = await api('assigned_seating', schedId ? `schedule_id=${schedId}` : '');
        if (!data?.seats?.length) {
            wrap.innerHTML='<div class="empty-state"><i class="fas fa-chair"></i>No seating assigned yet by superadmin for your exams.</div>';
            return;
        }
        // Group by hall
        const byHall = {};
        data.seats.forEach(s => { const k=s.hall_name||'Unknown Hall'; if(!byHall[k]) byHall[k]=[]; byHall[k].push(s); });
        let html = '';
        Object.entries(byHall).forEach(([hall, seats]) => {
            html += `<div style="margin-bottom:20px">
                <div style="font-weight:700;color:var(--teal);margin-bottom:8px;font-size:.85rem"><i class="fas fa-door-open"></i> ${hall}</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px">`;
            seats.forEach(s => {
                html += `<div title="${s.full_name} (${s.roll_number})" style="width:52px;height:52px;background:var(--teal3);border:1px solid rgba(0,212,187,.2);border-radius:8px;display:grid;place-items:center;cursor:default;font-size:.65rem;text-align:center;color:var(--teal);padding:3px">
                    <div style="font-weight:700">${s.seat_number||'—'}</div>
                    <div style="color:var(--muted);font-size:.58rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:46px">${s.roll_number||s.full_name.split(' ')[0]}</div>
                </div>`;
            });
            html += `</div></div>`;
        });
        wrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading seating:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load seating arrangements. Please try again.</div>';
    }
}

// ═══ ASSIGNED ADMIT CARDS VIEW ════════════════════════════════════════
async function loadAssignedAdmits() {
    const wrap = document.getElementById('admitsWrap');
    if (!wrap) return;
    wrap.innerHTML='<div class="loading-wrap"><div class="spinner"></div><p>Loading admit cards…</p></div>';
    try {
        const q = document.getElementById('admitSearch')?.value || '';
        const data = await api('assigned_admits', q ? `q=${encodeURIComponent(q)}` : '');
        if (!data || !data.length) {
            wrap.innerHTML='<div class="empty-state"><i class="fas fa-id-card"></i>No admit cards issued yet for your assigned exams.</div>';
            return;
        }
        let html = '<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>Student</th><th>Exam</th><th>Date</th><th>Hall / Seat</th><th>Admit No</th><th>Status</th><th>Issued By</th></tr></thead><tbody>';
        data.forEach(a => {
            html += `<tr>
                <td><strong>${a.student_name}</strong><br><span style="font-size:.7rem;color:var(--muted)">${a.roll_number||''}</span></td>
                <td>${a.exam_title}<br><span style="font-size:.7rem;color:var(--muted)">${a.course_name} (${a.course_code})</span></td>
                <td style="font-size:.78rem">${a.exam_date}<br><span style="color:var(--muted)">${a.start_time?.slice(0,5)}–${a.end_time?.slice(0,5)}</span></td>
                <td style="font-size:.78rem">${a.hall_name||'—'}<br><span style="color:var(--muted)">Seat: ${a.seat_number||'—'}</span></td>
                <td style="font-family:var(--mono);font-size:.75rem;color:var(--teal)">${a.admit_card_no}</td>
                <td>${a.is_valid?'<span class="pill approved"><i class="fas fa-check"></i> Valid</span>':'<span class="pill inactive"><i class="fas fa-ban"></i> Revoked</span>'}</td>
                <td style="font-size:.75rem">${a.issued_by_name?'<span style="color:var(--amber)"><i class="fas fa-crown"></i></span> '+a.issued_by_name:'System'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    } catch (error) {
        console.error('Error loading admit cards:', error);
        wrap.innerHTML = '<div class="empty-state" style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i>Failed to load admit cards. Please try again.</div>';
    }
}

// ═══ EXAM VIEW — core loader ══════════════════════════════════════
async function loadExamView() {
    const wrap = document.getElementById('examCardsWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading-wrap"><div class="spinner"></div><p>Loading exams…</p></div>';
    try {
        const q      = document.getElementById('examSearch')?.value       || '';
        const type   = document.getElementById('examTypeFilter')?.value   || '';
        const status = document.getElementById('examStatusFilter')?.value || '';
        const data   = await api('superadmin_exams',
            `&q=${encodeURIComponent(q)}&type=${encodeURIComponent(type)}&status=${encodeURIComponent(status)}`);

        if (!data || !data.length) {
            // Faculty with no assigned courses sees a clear HOD-access notice
            const noAccessMsg = (USER_ROLE === 'faculty' && ASSIGNED_COURSES.length === 0)
                ? `<i class="fas fa-lock" style="color:var(--amber-acc)"></i><br>No course access granted yet.<br>
                   <small style="color:var(--muted)">Ask your HOD to assign you to a course via the <strong>Assign Faculty</strong> panel.</small>`
                : `<i class="fas fa-file-invoice"></i><br>No exams found for your assigned courses.<br>
                   <small style="color:var(--muted)">Exams will appear here once created for your courses.</small>`;
            wrap.innerHTML = `<div class="empty-state">${noAccessMsg}</div>`;
            return;
        }

        // Fetch real seating data for all exams (keyed by schedule_id)
        // We do it once globally then match per card
        const seatingAll = await api('assigned_seating', '');
        const seatsBySchedule = {};
        if (seatingAll?.seats) {
            seatingAll.seats.forEach(s => {
                const k = s.schedule_id;
                if (!seatsBySchedule[k]) seatsBySchedule[k] = [];
                seatsBySchedule[k].push(s);
            });
        }

        // Fetch invigilators keyed by schedule_id
        const invigAll = await api('assigned_invigilators');
        const invigsBySchedule = {};
        if (invigAll && Array.isArray(invigAll)) {
            invigAll.forEach(iv => {
                const k = iv.schedule_id;
                if (!invigsBySchedule[k]) invigsBySchedule[k] = [];
                invigsBySchedule[k].push(iv);
            });
        }

        const typeLabels = {unit_test:'Unit Test',mid_term:'Mid Term',final:'Final',
            practical:'Practical',assignment:'Assignment',project:'Project',viva:'Viva'};
        const typeIcons  = {unit_test:'fa-pen-to-square',mid_term:'fa-calendar-check',
            final:'fa-graduation-cap',practical:'fa-flask',assignment:'fa-paperclip',
            project:'fa-diagram-project',viva:'fa-comments'};
        const statusPill = {draft:'draft',upcoming:'upcoming',ongoing:'ongoing',
            completed:'completed',published:'published',cancelled:'cancelled'};

        let html = '<div style="display:flex;flex-direction:column;gap:18px">';

        data.forEach(e => {
            const schedDate  = e.sched_date || e.exam_date || null;
            const timeStr    = e.start_time
                ? `${fmtTime(e.start_time)} – ${fmtTime(e.end_time)}` : null;
            const ico        = typeIcons[e.type] || 'fa-file-invoice';
            const capacity   = parseInt(e.hall_capacity || 0);
            const admitCount = parseInt(e.admit_count   || 0);
            const invigCount = parseInt(e.invig_count   || 0);
            const saIcon     = (e.created_by_role === 'super_admin' || e.created_by_role === 'college_admin')
                ? `<span style="color:var(--amber);font-size:.6rem;margin-left:6px;vertical-align:middle"><i class="fas fa-crown"></i> SA</span>` : '';

            // ── 2D Seating grid ───────────────────────────────────
            const realSeats  = seatsBySchedule[e.schedule_id] || [];
            const invigList  = invigsBySchedule[e.schedule_id] || [];
            const totalSeats = capacity || Math.max(admitCount * 2, 30, realSeats.length);
            const cols       = Math.min(Math.ceil(Math.sqrt(totalSeats * 1.2)), 8);
            const rows       = Math.ceil(totalSeats / cols);

            // Build a set of seat positions that are occupied (from real data or distribute evenly)
            let occupiedPositions = new Set();
            let seatLabels = {}; // pos -> {name, roll, seat_no}
            if (realSeats.length) {
                realSeats.forEach((s, i) => {
                    const r = parseInt(s.row_no) || Math.floor(i / cols) + 1;
                    const c = parseInt(s.col_no) || (i % cols) + 1;
                    const pos = `${r}_${c}`;
                    occupiedPositions.add(pos);
                    seatLabels[pos] = {
                        name:    s.full_name    || '—',
                        roll:    s.roll_number  || '—',
                        dept:    s.dept_name    || s.dept_code || '',
                        deptCode:s.dept_code    || '',
                        seatNo:  s.seat_number  || `R${r}C${c}`,
                        present: s.is_present,
                    };
                });
            } else {
                // No real seating yet — shade admit_count slots from top-left
                let filled = 0;
                for (let r = 1; r <= rows && filled < admitCount; r++) {
                    for (let c = 1; c <= cols && filled < admitCount; c++) {
                        occupiedPositions.add(`${r}_${c}`);
                        filled++;
                    }
                }
            }

            // Render seat squares
            let gridHtml = '';
            for (let r = 1; r <= rows; r++) {
                for (let c = 1; c <= cols; c++) {
                    const pos     = `${r}_${c}`;
                    const seatIdx = (r - 1) * cols + (c - 1);
                    if (seatIdx >= totalSeats) {
                        gridHtml += `<div class="ev-seat ev-off"></div>`;
                        continue;
                    }
                    if (occupiedPositions.has(pos)) {
                        const info    = seatLabels[pos];
                        const present = info?.present;
                        const cls     = present === null || present === undefined ? 'ev-occ'
                            : present == 1 ? 'ev-present' : 'ev-absent';
                        const seatNo   = info ? info.seatNo : `S${seatIdx + 1}`;
                        const fullName = info ? (info.name || '—') : '—';
                        const roll     = info ? (info.roll !== '—' ? info.roll : '—') : '—';
                        const tip      = info
                            ? `${info.seatNo}&#10;${info.name}&#10;${info.roll}${info.dept ? '&#10;' + info.dept : ''}`
                            : `Seat ${seatIdx + 1} · Assigned`;
                        const deptTag  = info?.deptCode ? `<span class="es-dept">${info.deptCode}</span>` : '';
                        gridHtml += `<div class="ev-seat ${cls}" data-tip="${tip.replace(/"/g,'&quot;')}"><span class="es-no">${seatNo}</span><span class="es-name">${fullName}</span><span class="es-roll">${roll}</span>${deptTag}</div>`;
                    } else {
                        gridHtml += `<div class="ev-seat ev-empty" data-tip="Seat ${seatIdx + 1}&#10;Empty"><span class="es-no">${seatIdx + 1}</span></div>`;
                    }
                }
            }

            const fillPct   = capacity > 0 ? Math.min(Math.round((admitCount / capacity) * 100), 100) : 0;
            const fillColor = fillPct >= 90 ? 'var(--red)' : fillPct >= 60 ? 'var(--amber)' : 'var(--teal)';

            html += `
            <div style="background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(15,118,110,.08)">

              <!-- ─── Card header ─── -->
              <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
                <div style="width:44px;height:44px;border-radius:11px;background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.2);display:grid;place-items:center;color:var(--teal);flex-shrink:0;font-size:1.05rem">
                  <i class="fas ${ico}"></i>
                </div>
                <div style="flex:1;min-width:180px">
                  <div style="font-size:.95rem;font-weight:700;color:var(--text);line-height:1.3">${e.title}${saIcon}</div>
                  <div style="font-size:.76rem;color:var(--muted);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <span><i class="fas fa-book-open" style="color:var(--teal);margin-right:4px"></i><strong style="color:var(--text)">${e.course_name}</strong> <span style="font-family:var(--mono)">(${e.course_code})</span></span>
                    <span>Sem ${e.semester || '—'}</span>
                    <span>${e.academic_year || ''}</span>
                  </div>
                  <div style="margin-top:8px;display:flex;gap:7px;flex-wrap:wrap;align-items:center">
                    <span class="pill ${statusPill[e.status] || ''}" style="font-size:.65rem">${e.status || '—'}</span>
                    <span class="pill" style="font-size:.65rem">${typeLabels[e.type] || e.type || '—'}</span>
                    ${schedDate ? `<span style="font-size:.72rem;color:var(--muted)"><i class="fas fa-calendar" style="color:var(--teal)"></i> ${fmtDate(schedDate)}</span>` : ''}
                    ${timeStr   ? `<span style="font-size:.72rem;color:var(--muted)"><i class="fas fa-clock" style="color:var(--teal)"></i> ${timeStr}</span>` : ''}
                    <span style="font-size:.72rem;color:var(--muted)"><i class="fas fa-chart-bar" style="color:var(--muted)"></i> ${e.max_marks} marks · pass ${e.pass_marks}</span>
                  </div>
                </div>
                <!-- ─ Create Q.Paper button ─ -->
                <div style="flex-shrink:0;align-self:center">
                  <button class="btn btn-teal" style="font-size:.78rem;padding:9px 16px;display:flex;align-items:center;gap:7px;border-radius:9px"
                    onclick="openQPaperModal(${e.id}, '${(e.title||'').replace(/'/g,"\\'")}', '${(e.course_name||'').replace(/'/g,"\\'")}')">
                    <i class="fas fa-plus"></i> Create Question Paper
                  </button>
                </div>
              </div>

              <!-- ─── Card body: left info + right 2D grid ─── -->
              <div style="display:flex;flex-wrap:wrap">

                <!-- Left: venue info -->
                <div style="padding:16px 20px;min-width:170px;border-right:1px solid var(--border);background:#F8FAFC;flex-shrink:0">
                  <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);margin-bottom:10px">Venue</div>

                  ${e.hall_name
                    ? `<div style="display:flex;align-items:center;gap:7px;margin-bottom:8px">
                         <i class="fas fa-building" style="color:var(--teal);font-size:.8rem;width:14px"></i>
                         <span style="font-weight:600;color:var(--text);font-size:.82rem">${e.hall_name}</span>
                       </div>`
                    : `<div style="color:var(--amber);font-size:.76rem;margin-bottom:8px">
                         <i class="fas fa-exclamation-circle"></i> No hall assigned
                       </div>`}

                  ${capacity ? `<div style="font-size:.72rem;color:var(--muted);margin-bottom:5px"><i class="fas fa-chair" style="width:14px;color:var(--muted)"></i> ${capacity} total seats</div>` : ''}
                  <div style="font-size:.72rem;margin-bottom:5px;color:${admitCount > 0 ? 'var(--green)' : 'var(--muted)'}"><i class="fas fa-id-card" style="width:14px"></i> ${admitCount} admit cards</div>
                  <div style="font-size:.72rem;margin-bottom:4px;color:${invigCount > 0 ? 'var(--purple)' : 'var(--muted)'}"><i class="fas fa-user-tie" style="width:14px"></i> ${invigCount} invigilator${invigCount !== 1 ? 's' : ''}</div>
                  ${invigList.length ? `<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:10px;padding:6px 8px;background:rgba(168,85,247,.06);border:1px solid rgba(168,85,247,.15);border-radius:7px">
                    ${invigList.map(iv => `<div style="display:flex;flex-direction:column;gap:1px">
                      <span style="font-size:.68rem;font-weight:600;color:var(--text)">${iv.faculty_name}</span>
                      <span style="font-size:.6rem;color:rgba(168,85,247,.8)">${iv.dept_name || iv.dept_code || ''}${iv.duty_type ? ' · ' + iv.duty_type.replace('_',' ') : ''}</span>
                    </div>`).join('')}
                  </div>` : ''}

                  ${capacity > 0 ? `
                  <div style="font-size:.65rem;color:var(--muted);margin-bottom:4px">Occupancy ${fillPct}%</div>
                  <div style="height:5px;background:rgba(15,118,110,.12);border-radius:4px;overflow:hidden">
                    <div style="height:100%;width:${fillPct}%;background:${fillColor};border-radius:4px;transition:width .6s"></div>
                  </div>` : ''}

                  <div style="margin-top:14px;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);margin-bottom:7px">Legend</div>
                  <div style="display:flex;flex-direction:column;gap:5px">
                    <div style="display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--muted)"><div style="width:11px;height:11px;border-radius:2px;background:rgba(20,184,166,.18);border:1.5px solid rgba(15,118,110,.45);flex-shrink:0"></div>Assigned</div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--muted)"><div style="width:11px;height:11px;border-radius:2px;background:rgba(22,163,74,.18);border:1.5px solid rgba(22,163,74,.50);flex-shrink:0"></div>Present</div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--muted)"><div style="width:11px;height:11px;border-radius:2px;background:rgba(220,38,38,.12);border:1.5px solid rgba(220,38,38,.40);flex-shrink:0"></div>Absent</div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--muted)"><div style="width:11px;height:11px;border-radius:2px;background:#F1F5F9;border:1.5px dashed #CBD5E1;flex-shrink:0"></div>Empty</div>
                  </div>
                </div>

                <!-- Right: 2D classroom grid -->
                <div style="padding:16px 20px;flex:1;min-width:260px;overflow-x:auto">
                  <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);margin-bottom:10px">
                    Classroom Layout${e.hall_name ? ' — ' + e.hall_name : ''} &nbsp;
                    ${!realSeats.length && admitCount > 0 ? '<span style="color:var(--amber);font-weight:400;text-transform:none;letter-spacing:0">(estimated from admit count)</span>' : ''}
                    ${!admitCount && !realSeats.length ? '<span style="color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0">(no seating assigned yet)</span>' : ''}
                  </div>

                  <!-- Blackboard -->
                  <div style="background:rgba(15,118,110,.10);border:2px dashed rgba(15,118,110,.40);border-radius:6px;text-align:center;font-size:.58rem;color:#0F766E;padding:6px 0;margin-bottom:10px;letter-spacing:.08em;font-weight:700;text-transform:uppercase;width:fit-content;min-width:${cols * 85}px">
                    ▲ &nbsp; Blackboard / Front &nbsp; ▲
                  </div>

                  <!-- Seat grid -->
                  <div style="display:grid;grid-template-columns:repeat(${cols},80px);gap:5px">
                    ${gridHtml}
                  </div>

                  <div style="margin-top:8px;font-size:.62rem;color:#94A3B8">
                    ${rows} rows × ${cols} cols &nbsp;·&nbsp; ${totalSeats} seats total
                    ${realSeats.length ? `&nbsp;·&nbsp; <span style="color:#0F766E">${realSeats.length} actually assigned</span>` : ''}
                  </div>
                </div>

              </div><!-- /body -->
            </div>`;
        });

        html += '</div>';
        wrap.innerHTML = html;

    } catch (err) {
        console.error('loadExamView error:', err);
        wrap.innerHTML = `<div class="empty-state" style="color:var(--red)">
            <i class="fas fa-exclamation-triangle"></i> Failed to load exams.<br>
            <small style="color:var(--muted)">${err.message}</small>
        </div>`;
    }
}

// ── Open the Q.Paper create modal pre-filled for an exam ─────────
async function openQPaperModal(examId, examTitle, courseName) {
    // Populate exam dropdown if needed
    const sel = document.getElementById('qpExam');
    if (sel) {
        if (sel.options.length <= 1) {
            const exams = await api('exams_dropdown');
            sel.innerHTML = '<option value="">Select exam…</option>' +
                (exams || []).map(ex => `<option value="${ex.id}">${ex.label}</option>`).join('');
        }
        sel.value = examId;
        if (sel.value != examId) {
            const opt = document.createElement('option');
            opt.value = examId;
            opt.textContent = `${examTitle} (${courseName})`;
            opt.selected = true;
            sel.appendChild(opt);
        }
    }
    const titleInput = document.getElementById('qpTitle');
    if (titleInput && !titleInput.value) titleInput.value = `${examTitle} — Question Paper`;
    openModal('qpaperModal');
}

// ── Seat CSS (injected once) ──────────────────────────────────────
(function injectSeatCSS() {
    if (document.getElementById('ev-seat-css')) return;
    const s = document.createElement('style');
    s.id = 'ev-seat-css';
    s.textContent = `
        .ev-seat{width:80px;height:74px;border-radius:7px;cursor:default;transition:transform .15s,box-shadow .15s,border-color .15s;position:relative;
                 display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;overflow:hidden;padding:2px;}
        .ev-seat:hover{transform:scale(1.12);z-index:10;box-shadow:0 4px 14px rgba(15,118,110,.20);}
        .ev-seat .es-no{font-family:'JetBrains Mono',monospace;font-size:.6rem;font-weight:700;color:#0F172A;line-height:1;text-align:center;}
        .ev-seat .es-name{font-size:.54rem;color:#1E293B;line-height:1.2;text-align:center;
                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:76px;font-weight:600;}
        .ev-seat .es-roll{font-size:.48rem;color:#64748B;line-height:1;text-align:center;
                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:76px;}
        .ev-seat .es-dept{font-size:.44rem;color:#0F766E;line-height:1;text-align:center;
                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:76px;font-weight:600;letter-spacing:.04em;}
        .ev-occ    {background:rgba(20,184,166,.12);border:1.5px solid rgba(15,118,110,.40);}
        .ev-occ .es-no{color:#0F766E;}
        .ev-occ .es-roll{color:#14B8A6;}
        .ev-present{background:rgba(22,163,74,.12);border:1.5px solid rgba(22,163,74,.40);}
        .ev-present .es-no{color:#16A34A;}
        .ev-present .es-roll{color:rgba(22,163,74,.8);}
        .ev-absent {background:rgba(220,38,38,.10);border:1.5px solid rgba(220,38,38,.35);}
        .ev-absent .es-no{color:#DC2626;}
        .ev-absent .es-roll{color:rgba(220,38,38,.75);}
        .ev-empty  {background:#F1F5F9;border:1.5px dashed #CBD5E1;}
        .ev-empty .es-no{color:#94A3B8;}
        .ev-off    {background:transparent;border:none;pointer-events:none;}
        .ev-seat[data-tip]{position:relative;}
        .ev-seat[data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 7px);left:50%;
            transform:translateX(-50%);background:#fff;border:1px solid rgba(15,118,110,.15);color:#0F172A;
            font-size:.65rem;padding:6px 10px;border-radius:8px;white-space:pre;z-index:30;pointer-events:none;
            box-shadow:0 6px 18px rgba(15,118,110,.14);font-family:'Sora',sans-serif;line-height:1.6;min-width:120px;text-align:center;}
        .ev-seat[data-tip]:hover::before{content:'';position:absolute;bottom:calc(100% + 3px);left:50%;
            transform:translateX(-50%);border:5px solid transparent;border-top-color:rgba(15,118,110,.15);z-index:31;}
    `;
    document.head.appendChild(s);
})();

// ── loadStats (no-op stub — stats not shown in this view) ─────────
function loadStats() { /* stats panel not present in this view */ }

// ── Init ──────────────────────────────────────────────────────────
(async () => {
    // Pre-populate the Q.Paper exam dropdown for when user clicks the modal
    const exams = await api('exams_dropdown');
    const sel = document.getElementById('qpExam');
    if (sel && exams) {
        sel.innerHTML = '<option value="">Select exam…</option>' +
            exams.map(ex => `<option value="${ex.id}">${ex.label}</option>`).join('');
    }
    // Start on Exams tab and load it
    switchTab('exams');
})();

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
</body>
</html>