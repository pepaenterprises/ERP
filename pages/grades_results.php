<?php
// ─────────────────────────────────────────────────────────────────────────────
// grades_results.php  — Marks Entry & Grade Maintenance
// Scoped to logged-in faculty's college_id + department_id.
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']               ?? 'faculty';
$userId    = (int)($user['id']           ?? 0);
$collegeId = (int)($user['college_id']   ?? 0);
$deptId    = (int)($user['department_id']?? 0);

if (!in_array($role, ['faculty','college_admin','super_admin'])) {
    header('Location: students.php'); exit;
}

function h($v)  { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function initials($name) {
    $p = explode(' ', trim($name));
    $i = strtoupper(substr($p[0],0,1));
    if (count($p)>1) $i .= strtoupper(substr(end($p),0,1));
    return $i;
}
function gradeFromPct($pct) {
    if ($pct >= 90) return ['label'=>'A+','gpa'=>10.0,'color'=>'#00d4bb'];
    if ($pct >= 80) return ['label'=>'A', 'gpa'=>9.0, 'color'=>'#10b981'];
    if ($pct >= 70) return ['label'=>'B', 'gpa'=>8.0, 'color'=>'#3b82f6'];
    if ($pct >= 60) return ['label'=>'B-','gpa'=>7.0, 'color'=>'#6eb5f5'];
    if ($pct >= 50) return ['label'=>'C', 'gpa'=>6.0, 'color'=>'#f59e0b'];
    if ($pct >= 40) return ['label'=>'D', 'gpa'=>5.0, 'color'=>'#ef4444'];
    return ['label'=>'F','gpa'=>0.0,'color'=>'#ef4444'];
}

$db = getDB();

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

// ── Scope meta ───────────────────────────────────────────────────────────────
$collegeName = ''; $deptName = ''; $deptCode = ''; $hodName = '';
if ($db && $collegeId) {
    $st = $db->prepare('SELECT name FROM colleges WHERE id=? LIMIT 1');
    $st->execute([$collegeId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $collegeName = $r['name'] ?? '';
    if ($deptId) {
        $st = $db->prepare('SELECT name,code,hod_name FROM departments WHERE id=? AND college_id=? LIMIT 1');
        $st->execute([$deptId,$collegeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $deptName = $r['name']??''; $deptCode = $r['code']??''; $hodName = $r['hod_name']??'';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: load_qpaper_sections
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_load_sections']) && $db) {
    header('Content-Type: application/json');
    $examId       = (int)($_POST['exam_id']  ?? 0);
    $specificPId  = (int)($_POST['paper_id'] ?? 0);

    $st = $db->prepare('SELECT id,title,max_marks,pass_marks FROM exams WHERE id=? AND college_id=? LIMIT 1');
    $st->execute([$examId,$collegeId]);
    $examRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$examRow) { echo json_encode(['ok'=>false,'msg'=>'Exam not found']); exit; }

    if ($specificPId) {
        $st = $db->prepare('SELECT id,title,version,total_marks,total_questions,status FROM question_papers WHERE id=? AND exam_id=? AND college_id=? LIMIT 1');
        $st->execute([$specificPId,$examId,$collegeId]);
    } else {
        $st = $db->prepare("SELECT id,title,version,total_marks,total_questions,status FROM question_papers WHERE exam_id=? AND college_id=? ORDER BY FIELD(status,'released','approved') ASC, created_at DESC LIMIT 1");
        $st->execute([$examId,$collegeId]);
    }
    $paper = $st->fetch(PDO::FETCH_ASSOC);
    if (!$paper) { echo json_encode(['ok'=>false,'msg'=>'No question paper found for this exam.']); exit; }
    $paperId = (int)$paper['id'];

    // Sections with questions
    $st = $db->prepare("
        SELECT eb.id AS blueprint_id, eb.section_name, eb.question_type, eb.is_compulsory,
               eb.display_order, eb.marks_per_q,
               COUNT(qpq.id) AS actual_question_count,
               SUM(COALESCE(qpq.marks_override, eb.marks_per_q)) AS section_max
        FROM question_paper_questions qpq
        JOIN exam_blueprint eb ON eb.id=qpq.blueprint_id
        WHERE qpq.paper_id=? AND eb.exam_id=? AND eb.college_id=?
        GROUP BY eb.id ORDER BY eb.display_order ASC, eb.id ASC
    ");
    $st->execute([$paperId,$examId,$collegeId]);
    $sections = $st->fetchAll(PDO::FETCH_ASSOC);

    // Per-section individual questions
    $questionsMap = [];
    if ($sections) {
        $qSt = $db->prepare("
            SELECT qpq.id AS pq_id, qpq.blueprint_id, qpq.display_order,
                   COALESCE(qpq.marks_override, eb.marks_per_q) AS q_marks,
                   qb.id AS question_id, qb.question_text, qb.question_type AS qb_type
            FROM question_paper_questions qpq
            JOIN exam_blueprint eb ON eb.id=qpq.blueprint_id
            JOIN question_bank qb  ON qb.id=qpq.question_id
            WHERE qpq.paper_id=? AND eb.exam_id=? AND eb.college_id=?
            ORDER BY eb.display_order ASC, qpq.display_order ASC
        ");
        $qSt->execute([$paperId,$examId,$collegeId]);
        foreach ($qSt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $bid = (int)$q['blueprint_id'];
            $questionsMap[$bid][] = [
                'pq_id'         => (int)$q['pq_id'],
                'question_id'   => (int)$q['question_id'],
                'display_order' => (int)$q['display_order'],
                'q_marks'       => (float)$q['q_marks'],
                'question_text' => $q['question_text'],
                'qb_type'       => $q['qb_type'],
            ];
        }
        foreach ($sections as &$sec) {
            $sec['questions'] = $questionsMap[(int)$sec['blueprint_id']] ?? [];
        }
        unset($sec);
    }

    // Fallback 1: Blueprint-only (paper exists but questions not yet linked in question_paper_questions)
    if (!$sections) {
        $st = $db->prepare("SELECT id AS blueprint_id, section_name, question_type, is_compulsory,
                                   display_order, marks_per_q,
                                   num_questions AS actual_question_count,
                                   (num_questions * marks_per_q) AS section_max
                            FROM exam_blueprint
                            WHERE exam_id=? AND college_id=?
                            ORDER BY display_order ASC, id ASC");
        $st->execute([$examId, $collegeId]);
        $sections = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sections as &$sec) { $sec['questions'] = []; $sec['blueprint_only'] = true; }
        unset($sec);
    }

    // Fallback 2: No blueprint either — create a single virtual section from paper total_marks
    // This handles papers created directly without blueprint (common for simple/attached papers)
    if (!$sections) {
        $virtualMax = $paperMax > 0 ? $paperMax : (float)$examRow['max_marks'];
        $sections = [[
            'blueprint_id'          => 0,
            'section_name'          => 'Total Marks',
            'question_type'         => 'general',
            'is_compulsory'         => 1,
            'display_order'         => 1,
            'marks_per_q'           => $virtualMax,
            'actual_question_count' => 1,
            'section_max'           => $virtualMax,
            'questions'             => [],
            'blueprint_only'        => true,
            'virtual'               => true,   // signal to JS: render as single total-marks input
        ]];
    }

    foreach ($sections as &$sec) {
        $sec['section_max']           = (float)$sec['section_max'];
        $sec['actual_question_count'] = (int)$sec['actual_question_count'];
    }
    unset($sec);

    $paperMax    = (float)$paper['total_marks'];
    $examMax     = (float)$examRow['max_marks'];
    $examPass    = (float)$examRow['pass_marks'];
    $secTotal    = array_sum(array_column($sections,'section_max'));
    $effectiveMax= $paperMax > 0 ? $paperMax : $secTotal;
    $effectivePass=(abs($effectiveMax-$examMax)<0.01||$examMax==0)?$examPass:round(($examPass/$examMax)*$effectiveMax,2);

    // ── Auto-provision marks rows ─────────────────────────────────────────────
    // Source of truth for "who sits this exam" is admit_cards (via exam_schedule).
    // Fall back 1: all dept students enrolled in the exam's course (enrollments table).
    // Fall back 2: all active dept students — so marks entry is NEVER blocked.
    // After resolving the student list, INSERT IGNORE any missing marks rows.

    // Get exam's course_id and department_id for scoping fallbacks
    $exMeta = $db->prepare('SELECT course_id, department_id FROM exams WHERE id=? AND college_id=? LIMIT 1');
    $exMeta->execute([$examId, $collegeId]);
    $exMetaRow = $exMeta->fetch(PDO::FETCH_ASSOC);
    $examCourseId = (int)($exMetaRow['course_id'] ?? 0);
    $examDeptId   = (int)($exMetaRow['department_id'] ?? 0);

    // Step 1: Students from admit cards (primary source)
    $acSt = $db->prepare("
        SELECT DISTINCT u.id AS student_id, u.full_name, u.roll_number,
               UPPER(TRIM(COALESCE(u.section,''))) AS student_section
        FROM admit_cards ac
        JOIN exam_schedule es ON es.id = ac.schedule_id
        JOIN users u ON u.id = ac.student_id
        WHERE es.exam_id = ? AND ac.is_valid = 1 AND u.role = 'student'
        ORDER BY u.section, u.roll_number, u.full_name
    ");
    $acSt->execute([$examId]);
    $enrolledStudents = $acSt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Fallback — students enrolled in the course (enrollments table)
    if (!$enrolledStudents && $examCourseId) {
        $enSt = $db->prepare("
            SELECT DISTINCT u.id AS student_id, u.full_name, u.roll_number,
                   UPPER(TRIM(COALESCE(u.section,''))) AS student_section
            FROM enrollments en
            JOIN users u ON u.id = en.student_id
            WHERE en.course_id = ? AND en.college_id = ? AND u.role = 'student' AND u.status = 'active'
            ORDER BY u.section, u.roll_number, u.full_name
        ");
        $enSt->execute([$examCourseId, $collegeId]);
        $enrolledStudents = $enSt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Step 3: Fallback — all active students in the exam's department
    if (!$enrolledStudents && $examDeptId) {
        $depSt = $db->prepare("
            SELECT id AS student_id, full_name, roll_number,
                   UPPER(TRIM(COALESCE(section,''))) AS student_section
            FROM users
            WHERE college_id = ? AND department_id = ? AND role = 'student' AND status = 'active'
            ORDER BY section, roll_number, full_name
        ");
        $depSt->execute([$collegeId, $examDeptId]);
        $enrolledStudents = $depSt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$enrolledStudents) {
        echo json_encode(['ok'=>false,'msg'=>'No students found for this exam. Please assign admit cards or check department enrollment.']);
        exit;
    }

    // Auto-insert missing marks rows (INSERT IGNORE so existing rows are untouched)
    $insMarks = $db->prepare("
        INSERT IGNORE INTO marks (exam_id, student_id, college_id, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    foreach ($enrolledStudents as $es) {
        try { $insMarks->execute([$examId, (int)$es['student_id'], $collegeId]); } catch (Exception $e) {}
    }

    // Now fetch the full marks rows (with mark_id) for all enrolled students
    $studentIds = array_column($enrolledStudents, 'student_id');
    $inClause   = implode(',', array_fill(0, count($studentIds), '?'));
    $st = $db->prepare("
        SELECT m.id AS mark_id, m.student_id, m.obtained_marks, m.is_absent, m.grade, m.percentage,
               u.full_name, u.roll_number,
               UPPER(TRIM(COALESCE(u.section,''))) AS student_section
        FROM marks m
        JOIN users u ON u.id = m.student_id
        WHERE m.exam_id = ? AND m.college_id = ? AND m.student_id IN ($inClause)
        ORDER BY u.section, u.roll_number, u.full_name
    ");
    $st->execute(array_merge([$examId, $collegeId], $studentIds));
    $students = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$students) { echo json_encode(['ok'=>false,'msg'=>'Failed to load student marks rows. Please try again.']); exit; }

    // Ensure question_id column exists in section_marks
    try {
        $db->exec("ALTER TABLE section_marks ADD COLUMN IF NOT EXISTS question_id INT DEFAULT NULL");
        $db->exec("ALTER TABLE section_marks DROP INDEX IF EXISTS uq_sec_mark");
        $db->exec("ALTER TABLE section_marks ADD UNIQUE KEY IF NOT EXISTS uq_q_mark (exam_id,paper_id,student_id,blueprint_id,question_id)");
    } catch (Exception $e) {}

    // Existing per-question marks
    $existingQM = []; $existingSM = [];
    try {
        $st2 = $db->prepare('SELECT student_id,blueprint_id,question_id,obtained_marks,is_absent,remarks FROM section_marks WHERE exam_id=? AND paper_id=? AND college_id=?');
        $st2->execute([$examId,$paperId,$collegeId]);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['question_id']) {
                $existingQM[$row['student_id']][$row['question_id']] = (float)$row['obtained_marks'];
            } else {
                $existingSM[$row['student_id']][$row['blueprint_id']] = $row;
            }
        }
    } catch (Exception $e) {}

    echo json_encode(['ok'=>true,'paper'=>$paper,'sections'=>$sections,'students'=>$students,'existing'=>$existingSM,'existingQ'=>$existingQM,'exam_max'=>$effectiveMax,'exam_pass'=>$effectivePass,'paper_max'=>$paperMax,'section_total'=>$secTotal]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: save_section_mark
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_save_section_mark']) && $db) {
    header('Content-Type: application/json');
    $examId      = (int)($_POST['exam_id']      ?? 0);
    $paperId     = (int)($_POST['paper_id']      ?? 0);
    $studentId   = (int)($_POST['student_id']    ?? 0);
    $blueprintId = (int)($_POST['blueprint_id']  ?? 0);
    $questionId  = (int)($_POST['question_id']   ?? 0);
    $markId      = (int)($_POST['mark_id']       ?? 0);
    $isAbsent    = (int)($_POST['is_absent']      ?? 0);
    $remarks     = trim($_POST['remarks'] ?? '');

    // ── Validate obtained_marks ──────────────────────────────────────
    $obtained = null;
    if (!$isAbsent && isset($_POST['obtained_marks']) && $_POST['obtained_marks'] !== '') {
        $rawMark = $_POST['obtained_marks'];
        if (!is_numeric($rawMark)) {
            echo json_encode(['ok'=>false,'msg'=>'Marks must be a number.']); exit;
        }
        $obtained = round((float)$rawMark, 2);
        if ($obtained < 0) {
            echo json_encode(['ok'=>false,'msg'=>'Marks cannot be negative.']); exit;
        }
    }

    // ── Auth: verify exam belongs to this college ────────────────────
    $st = $db->prepare('SELECT id,max_marks,pass_marks FROM exams WHERE id=? AND college_id=? LIMIT 1');
    $st->execute([$examId,$collegeId]);
    $ex = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ex) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

    // ── Verify student belongs to this college ───────────────────────
    $stuChk = $db->prepare('SELECT id FROM users WHERE id=? AND college_id=? AND role=\'student\' LIMIT 1');
    $stuChk->execute([$studentId, $collegeId]);
    if (!$stuChk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Student not found.']); exit; }

    $pmSt = $db->prepare('SELECT total_marks FROM question_papers WHERE id=? AND college_id=? LIMIT 1');
    $pmSt->execute([$paperId,$collegeId]);
    $pmRow    = $pmSt->fetch(PDO::FETCH_ASSOC);
    $paperMax = $pmRow ? (float)$pmRow['total_marks'] : (float)$ex['max_marks'];
    $examMax  = (float)$ex['max_marks'];
    $examPass = (float)$ex['pass_marks'];
    $effectivePass = ($examMax>0 && abs($paperMax-$examMax)>0.01) ? round(($examPass/$examMax)*$paperMax,2) : $examPass;

    // ── Validate section max from DB (don't trust client sectionMax) ─
    $sectionMax = 0;
    if ($questionId && $blueprintId) {
        // Per-question: get marks from question_paper_questions / blueprint
        $qmSt = $db->prepare('SELECT COALESCE(qpq.marks_override, eb.marks_per_q) AS q_marks FROM question_paper_questions qpq JOIN exam_blueprint eb ON eb.id=qpq.blueprint_id WHERE qpq.paper_id=? AND qpq.question_id=? AND eb.exam_id=? AND eb.college_id=? LIMIT 1');
        $qmSt->execute([$paperId, $questionId, $examId, $collegeId]);
        $qmRow = $qmSt->fetch(PDO::FETCH_ASSOC);
        $sectionMax = $qmRow ? (float)$qmRow['q_marks'] : 0;
    } elseif ($blueprintId > 0) {
        // Section-level: get from blueprint
        $bpSt = $db->prepare('SELECT (num_questions * marks_per_q) AS section_max FROM exam_blueprint WHERE id=? AND exam_id=? AND college_id=? LIMIT 1');
        $bpSt->execute([$blueprintId, $examId, $collegeId]);
        $bpRow = $bpSt->fetch(PDO::FETCH_ASSOC);
        $sectionMax = $bpRow ? (float)$bpRow['section_max'] : 0;
        // Also try actual paper questions sum for this section
        $bpActSt = $db->prepare('SELECT SUM(COALESCE(qpq.marks_override, eb.marks_per_q)) AS section_max FROM question_paper_questions qpq JOIN exam_blueprint eb ON eb.id=qpq.blueprint_id WHERE qpq.paper_id=? AND eb.id=? AND eb.exam_id=? LIMIT 1');
        $bpActSt->execute([$paperId, $blueprintId, $examId]);
        $bpActRow = $bpActSt->fetch(PDO::FETCH_ASSOC);
        if ($bpActRow && $bpActRow['section_max'] !== null) $sectionMax = (float)$bpActRow['section_max'];
    } else {
        // Virtual section: use effective paper max
        $sectionMax = $paperMax > 0 ? $paperMax : $examMax;
    }

    if (!$isAbsent && $obtained !== null) {
        if ($sectionMax > 0 && $obtained > $sectionMax) {
            echo json_encode(['ok'=>false,'msg'=>"Marks entered ({$obtained}) exceed the maximum allowed ({$sectionMax})."]); exit;
        }
        // Guard: total of all sections should not exceed paper max
        if ($paperMax > 0 && $blueprintId > 0) {
            // Check sum of other sections + this new value won't exceed paperMax
            $otherSt = $db->prepare('SELECT SUM(sm.obtained_marks) AS others FROM section_marks sm WHERE sm.exam_id=? AND sm.paper_id=? AND sm.student_id=? AND sm.college_id=? AND sm.blueprint_id!=? AND sm.question_id IS NULL');
            $otherSt->execute([$examId, $paperId, $studentId, $collegeId, $blueprintId]);
            $otherRow = $otherSt->fetch(PDO::FETCH_ASSOC);
            $othersTotal = (float)($otherRow['others'] ?? 0);
            if (($othersTotal + $obtained) > $paperMax + 0.01) {
                echo json_encode(['ok'=>false,'msg'=>"Total marks ({$obtained} + existing ".round($othersTotal,2).") would exceed exam maximum ({$paperMax})."]); exit;
            }
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `section_marks` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `exam_id` INT NOT NULL, `paper_id` INT NOT NULL, `college_id` INT NOT NULL,
      `student_id` INT NOT NULL, `blueprint_id` INT NOT NULL,
      `question_id` INT DEFAULT NULL,
      `obtained_marks` DECIMAL(6,2) DEFAULT NULL, `is_absent` TINYINT(1) NOT NULL DEFAULT 0,
      `remarks` VARCHAR(255) DEFAULT NULL, `entered_by` INT DEFAULT NULL,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uq_q_mark` (`exam_id`,`paper_id`,`student_id`,`blueprint_id`,`question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE section_marks ADD COLUMN IF NOT EXISTS question_id INT DEFAULT NULL"); } catch(Exception $e){}

    $isVirtual = ($blueprintId === 0);

    if ($questionId) {
        $db->prepare('INSERT INTO section_marks (exam_id,paper_id,college_id,student_id,blueprint_id,question_id,obtained_marks,is_absent,remarks,entered_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE obtained_marks=VALUES(obtained_marks),is_absent=VALUES(is_absent),remarks=VALUES(remarks),entered_by=VALUES(entered_by),updated_at=NOW()')
           ->execute([$examId,$paperId,$collegeId,$studentId,$blueprintId,$questionId,$isAbsent?null:$obtained,$isAbsent,$remarks,$userId]);
    } else {
        $db->prepare('INSERT INTO section_marks (exam_id,paper_id,college_id,student_id,blueprint_id,question_id,obtained_marks,is_absent,remarks,entered_by) VALUES(?,?,?,?,?,NULL,?,?,?,?) ON DUPLICATE KEY UPDATE obtained_marks=VALUES(obtained_marks),is_absent=VALUES(is_absent),remarks=VALUES(remarks),entered_by=VALUES(entered_by),updated_at=NOW()')
           ->execute([$examId,$paperId,$collegeId,$studentId,$blueprintId,$isAbsent?null:$obtained,$isAbsent,$remarks,$userId]);
    }

    // Recompute total
    $stQ = $db->prepare('SELECT SUM(obtained_marks) AS total FROM section_marks WHERE exam_id=? AND paper_id=? AND student_id=? AND college_id=? AND question_id IS NOT NULL');
    $stQ->execute([$examId,$paperId,$studentId,$collegeId]);
    $totQ = $stQ->fetch(PDO::FETCH_ASSOC);

    $stS = $db->prepare('SELECT SUM(obtained_marks) AS total FROM section_marks WHERE exam_id=? AND paper_id=? AND student_id=? AND college_id=? AND question_id IS NULL');
    $stS->execute([$examId,$paperId,$studentId,$collegeId]);
    $totS = $stS->fetch(PDO::FETCH_ASSOC);

    if ($totQ['total'] !== null) {
        $totalObtained = round((float)$totQ['total'], 2);
    } elseif ($totS['total'] !== null) {
        $totalObtained = round((float)$totS['total'], 2);
    } else {
        $totalObtained = null;
    }

    // ── If markId missing, look it up (defensive) ────────────────────
    if (!$markId) {
        $mkSt = $db->prepare('SELECT id FROM marks WHERE exam_id=? AND student_id=? AND college_id=? LIMIT 1');
        $mkSt->execute([$examId, $studentId, $collegeId]);
        $mkRow = $mkSt->fetch(PDO::FETCH_ASSOC);
        $markId = $mkRow ? (int)$mkRow['id'] : 0;
    }

    if ($markId) {
        if ($totalObtained !== null && $paperMax > 0) {
            $pct = round(($totalObtained / $paperMax) * 100, 2);
            // Cap pct at 100 defensively
            $pct = min($pct, 100.0);
            $gi  = gradeFromPct($pct);
            $isPass = ($totalObtained >= $effectivePass) ? 1 : 0;
            $db->prepare('UPDATE marks SET obtained_marks=?,grade=?,grade_points=?,percentage=?,is_pass=?,entered_by=?,updated_at=NOW() WHERE id=? AND college_id=?')
               ->execute([$totalObtained,$gi['label'],$gi['gpa'],$pct,$isPass,$userId,$markId,$collegeId]);
        } else {
            $db->prepare('UPDATE marks SET obtained_marks=NULL,grade=NULL,grade_points=NULL,percentage=NULL,is_pass=NULL,entered_by=?,updated_at=NOW() WHERE id=? AND college_id=?')
               ->execute([$userId,$markId,$collegeId]);
        }
    }
    echo json_encode(['ok'=>true,'total'=>$totalObtained,'paper_max'=>$paperMax,'effective_pass'=>$effectivePass]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: get_marks (fresh from DB) — used by Total Marks tab
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_get_marks']) && $db) {
    header('Content-Type: application/json');
    $gm_examId = (int)($_POST['exam_id'] ?? 0);
    if (!$gm_examId) { echo json_encode(['ok'=>false]); exit; }

    // Resolve enrolled students (admit cards → enrollments → dept) and auto-create marks rows
    $exMeta2 = $db->prepare('SELECT course_id, department_id FROM exams WHERE id=? AND college_id=? LIMIT 1');
    $exMeta2->execute([$gm_examId, $collegeId]);
    $exMeta2Row = $exMeta2->fetch(PDO::FETCH_ASSOC);
    $gmCourseId = (int)($exMeta2Row['course_id'] ?? 0);
    $gmDeptId   = (int)($exMeta2Row['department_id'] ?? 0);

    $acSt2 = $db->prepare("SELECT DISTINCT u.id AS student_id FROM admit_cards ac JOIN exam_schedule es ON es.id=ac.schedule_id JOIN users u ON u.id=ac.student_id WHERE es.exam_id=? AND ac.is_valid=1 AND u.role='student'");
    $acSt2->execute([$gm_examId]);
    $gmStudents = $acSt2->fetchAll(PDO::FETCH_ASSOC);

    if (!$gmStudents && $gmCourseId) {
        $enSt2 = $db->prepare("SELECT DISTINCT u.id AS student_id FROM enrollments en JOIN users u ON u.id=en.student_id WHERE en.course_id=? AND en.college_id=? AND u.role='student' AND u.status='active'");
        $enSt2->execute([$gmCourseId, $collegeId]);
        $gmStudents = $enSt2->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$gmStudents && $gmDeptId) {
        $depSt2 = $db->prepare("SELECT id AS student_id FROM users WHERE college_id=? AND department_id=? AND role='student' AND status='active'");
        $depSt2->execute([$collegeId, $gmDeptId]);
        $gmStudents = $depSt2->fetchAll(PDO::FETCH_ASSOC);
    }

    // Auto-insert missing marks rows
    if ($gmStudents) {
        $ins2 = $db->prepare("INSERT IGNORE INTO marks (exam_id,student_id,college_id,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())");
        foreach ($gmStudents as $gs) {
            try { $ins2->execute([$gm_examId, (int)$gs['student_id'], $collegeId]); } catch (Exception $e) {}
        }
    }

    $st = $db->prepare('SELECT m.id,m.exam_id,m.student_id,u.full_name,u.roll_number,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.is_pass,m.remarks FROM marks m JOIN users u ON u.id=m.student_id WHERE m.exam_id=? AND m.college_id=? ORDER BY u.roll_number,u.full_name');
    $st->execute([$gm_examId,$collegeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $m) {
        $out[] = ['id'=>(int)$m['id'],'exam_id'=>(int)$m['exam_id'],'student_id'=>(int)$m['student_id'],'full_name'=>$m['full_name'],'roll_number'=>$m['roll_number']??'','obtained'=>$m['obtained_marks']!==null?(float)$m['obtained_marks']:null,'is_absent'=>(bool)((int)$m['is_absent']),'grade'=>$m['grade']??'','pct'=>$m['percentage']!==null?(float)$m['percentage']:null,'is_pass'=>$m['is_pass']!==null?(bool)((int)$m['is_pass']):null,'remarks'=>$m['remarks']??''];
    }
    echo json_encode(['ok'=>true,'marks'=>$out]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: ajax_course_marks
// Returns ALL exams + marks for the faculty's assigned course (scoped to the
// exam selected in the UI), grouped by exam, with per-student section info.
// Used by the "View Entered Marks & Results" panel.
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_course_marks']) && $db) {
    header('Content-Type: application/json');
    $anchorExamId = (int)($_POST['exam_id'] ?? 0);
    if (!$anchorExamId) { echo json_encode(['ok'=>false,'msg'=>'No exam id']); exit; }

    // Get the course_id for the selected exam (so we can pull ALL exams of that course)
    $st = $db->prepare('SELECT course_id, department_id FROM exams WHERE id=? AND college_id=? LIMIT 1');
    $st->execute([$anchorExamId, $collegeId]);
    $anchor = $st->fetch(PDO::FETCH_ASSOC);
    if (!$anchor) { echo json_encode(['ok'=>false,'msg'=>'Exam not found']); exit; }
    $courseId   = (int)$anchor['course_id'];
    $courseDept = (int)$anchor['department_id'];

    // All exams for this course (scoped to college + optionally dept for faculty)
    $eSql = 'SELECT e.id, e.title, e.type, e.max_marks, e.pass_marks, e.exam_date, e.status,
                    c.name AS course_name, c.code AS course_code
             FROM exams e
             JOIN courses c ON c.id = e.course_id
             WHERE e.college_id=? AND e.course_id=?';
    $eParams = [$collegeId, $courseId];
    if ($role === 'faculty' && $courseDept) { $eSql .= ' AND e.department_id=?'; $eParams[] = $courseDept; }
    $eSql .= ' ORDER BY e.exam_date ASC, e.id ASC';
    $st = $db->prepare($eSql); $st->execute($eParams);
    $allExams = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$allExams) { echo json_encode(['ok'=>false,'msg'=>'No exams found for this course']); exit; }

    $examIds   = array_column($allExams, 'id');
    $inClause  = implode(',', array_fill(0, count($examIds), '?'));

    // All marks rows for all exams of this course
    $mSql = "SELECT m.id, m.exam_id, m.student_id, m.obtained_marks, m.is_absent,
                    m.grade, m.percentage, m.is_pass, m.remarks,
                    u.full_name, u.roll_number,
                    UPPER(TRIM(COALESCE(u.section,''))) AS student_section
             FROM marks m
             JOIN users u ON u.id = m.student_id
             WHERE m.college_id=? AND m.exam_id IN ($inClause)
             ORDER BY m.exam_id ASC, u.section ASC, u.roll_number ASC, u.full_name ASC";
    $st = $db->prepare($mSql);
    $st->execute(array_merge([$collegeId], $examIds));
    $allMarks = $st->fetchAll(PDO::FETCH_ASSOC);

    // Group marks by exam_id
    $marksByExam = [];
    foreach ($allMarks as $m) {
        $eid = (int)$m['exam_id'];
        $marksByExam[$eid][] = [
            'id'         => (int)$m['id'],
            'student_id' => (int)$m['student_id'],
            'full_name'  => $m['full_name'],
            'roll_number'=> $m['roll_number'] ?? '',
            'section'    => $m['student_section'],
            'obtained'   => $m['obtained_marks'] !== null ? (float)$m['obtained_marks'] : null,
            'is_absent'  => (bool)((int)$m['is_absent']),
            'grade'      => $m['grade'] ?? '',
            'pct'        => $m['percentage'] !== null ? (float)$m['percentage'] : null,
            'is_pass'    => $m['is_pass'] !== null ? (bool)((int)$m['is_pass']) : null,
            'remarks'    => $m['remarks'] ?? '',
        ];
    }

    // Attach marks to each exam + compute per-exam stats
    $result = [];
    foreach ($allExams as $ex) {
        $eid    = (int)$ex['id'];
        $marks  = $marksByExam[$eid] ?? [];
        $maxM   = (float)$ex['max_marks'];
        $passM  = (float)$ex['pass_marks'];
        $present = array_filter($marks, fn($m) => !$m['is_absent'] && $m['obtained'] !== null);
        $avgObt  = count($present) ? round(array_sum(array_column(array_values($present),'obtained'))/count($present),1) : null;
        $avgPct  = count($present) ? round(array_sum(array_column(array_values($present),'pct'))/count($present),1) : null;
        $result[] = [
            'exam_id'     => $eid,
            'exam_title'  => $ex['title'],
            'exam_type'   => $ex['type'],
            'exam_date'   => $ex['exam_date'],
            'exam_status' => $ex['status'],
            'course_name' => $ex['course_name'],
            'course_code' => $ex['course_code'],
            'max_marks'   => $maxM,
            'pass_marks'  => $passM,
            'is_anchor'   => ($eid === $anchorExamId),
            'marks'       => $marks,
            'stats'       => [
                'total'   => count($marks),
                'pass'    => count(array_filter($marks, fn($m) => $m['is_pass'] === true)),
                'fail'    => count(array_filter($marks, fn($m) => $m['is_pass'] === false)),
                'absent'  => count(array_filter($marks, fn($m) => $m['is_absent'])),
                'pending' => count(array_filter($marks, fn($m) => !$m['is_absent'] && $m['is_pass'] === null)),
                'avg_obt' => $avgObt,
                'avg_pct' => $avgPct,
                'high'    => count($present) ? max(array_column(array_values($present),'obtained')) : null,
            ],
        ];
    }

    echo json_encode(['ok'=>true,'course_id'=>$courseId,'exams'=>$result]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: save_mark (flat total)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_save_mark']) && $db) {
    header('Content-Type: application/json');
    $markId   = (int)($_POST['mark_id'] ?? 0);
    $isAbsent = (int)($_POST['is_absent'] ?? 0);
    $remarks  = trim($_POST['remarks'] ?? '');

    if (!$markId) { echo json_encode(['ok'=>false,'msg'=>'Invalid request.']); exit; }

    // Validate obtained_marks before touching DB
    $obtained = null;
    if (!$isAbsent && isset($_POST['obtained_marks']) && $_POST['obtained_marks'] !== '') {
        $rawMark = $_POST['obtained_marks'];
        if (!is_numeric($rawMark)) {
            echo json_encode(['ok'=>false,'msg'=>'Marks must be a number.']); exit;
        }
        $obtained = round((float)$rawMark, 2);
        if ($obtained < 0) {
            echo json_encode(['ok'=>false,'msg'=>'Marks cannot be negative.']); exit;
        }
    }

    // Fetch exam limits from DB — never trust client-supplied max
    $st = $db->prepare('SELECT m.id, ex.max_marks, ex.pass_marks FROM marks m JOIN exams ex ON ex.id=m.exam_id WHERE m.id=? AND m.college_id=?');
    $st->execute([$markId,$collegeId]);
    $mRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mRow) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

    $maxMarks  = (float)$mRow['max_marks'];
    $passMarks = (float)$mRow['pass_marks'];

    if ($maxMarks <= 0) { echo json_encode(['ok'=>false,'msg'=>'Exam maximum marks not configured correctly.']); exit; }

    if ($obtained !== null && $obtained > $maxMarks) {
        echo json_encode(['ok'=>false,'msg'=>"Marks entered ({$obtained}) exceed the maximum allowed ({$maxMarks})."]); exit;
    }

    // Validate pass_marks configuration
    if ($passMarks > $maxMarks) {
        $passMarks = $maxMarks; // defensive fallback
    }

    $pct=null; $grade=null; $gp=null; $isPass=null;
    if ($isAbsent) {
        $grade='F'; $gp=0; $pct=0; $isPass=0; $obtained=null;
    } elseif ($obtained !== null) {
        $pct   = round(($obtained / $maxMarks) * 100, 2);
        $pct   = min($pct, 100.0); // defensive cap
        $gi    = gradeFromPct($pct);
        $grade = $gi['label']; $gp = $gi['gpa'];
        $isPass = ($obtained >= $passMarks) ? 1 : 0;
    }

    $db->prepare('UPDATE marks SET obtained_marks=?,is_absent=?,grade=?,grade_points=?,percentage=?,is_pass=?,remarks=?,entered_by=?,updated_at=NOW() WHERE id=? AND college_id=?')
       ->execute([$obtained,$isAbsent,$grade,$gp,$pct,$isPass,$remarks,$userId,$markId,$collegeId]);
    echo json_encode(['ok'=>true,'grade'=>$grade,'pct'=>$pct,'is_pass'=>$isPass,'max'=>$maxMarks,'pass'=>$passMarks]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: get_grade_summary — for Grade Maintenance tab
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_grade_summary']) && $db) {
    header('Content-Type: application/json');
    $filterExam   = (int)($_POST['filter_exam']   ?? 0);
    $filterCourse = (int)($_POST['filter_course'] ?? 0);

    $sql = "SELECT m.id, m.student_id, u.full_name, u.roll_number,
                   m.obtained_marks, m.is_absent, m.grade, m.grade_points, m.percentage, m.is_pass,
                   ex.id AS exam_id, ex.title AS exam_title, ex.type AS exam_type,
                   ex.max_marks, ex.pass_marks, ex.exam_date,
                   c.name AS course_name, c.code AS course_code, c.credits
            FROM marks m
            JOIN users u   ON u.id   = m.student_id
            JOIN exams ex  ON ex.id  = m.exam_id
            JOIN courses c ON c.id   = ex.course_id
            WHERE m.college_id=?";
    $params = [$collegeId];
    if ($deptId && $role==='faculty') { $sql .= ' AND ex.department_id=?'; $params[]=$deptId; }
    if ($filterExam)   { $sql .= ' AND m.exam_id=?';    $params[]=$filterExam; }
    if ($filterCourse) { $sql .= ' AND ex.course_id=?'; $params[]=$filterCourse; }
    $sql .= ' ORDER BY u.full_name, ex.exam_date DESC';

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Per-student aggregation for CGPA view
    $students = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($students[$sid])) {
            $students[$sid] = ['student_id'=>$sid,'full_name'=>$r['full_name'],'roll_number'=>$r['roll_number']??'','exams'=>[],'total_credits'=>0,'total_gpa'=>0.0];
        }
        $credits = (float)($r['credits']??3);
        $gp      = $r['grade_points']!==null?(float)$r['grade_points']:null;
        $students[$sid]['exams'][] = [
            'exam_id'     => (int)$r['exam_id'],
            'exam_title'  => $r['exam_title'],
            'exam_type'   => $r['exam_type'],
            'course_name' => $r['course_name'],
            'course_code' => $r['course_code'],
            'credits'     => $credits,
            'obtained'    => $r['obtained_marks']!==null?(float)$r['obtained_marks']:null,
            'max_marks'   => (float)$r['max_marks'],
            'pass_marks'  => (float)$r['pass_marks'],
            'grade'       => $r['grade']??'',
            'grade_points'=> $gp,
            'percentage'  => $r['percentage']!==null?(float)$r['percentage']:null,
            'is_pass'     => $r['is_pass']!==null?(bool)((int)$r['is_pass']):null,
            'is_absent'   => (bool)((int)$r['is_absent']),
            'exam_date'   => $r['exam_date'],
        ];
        if ($gp!==null) {
            $students[$sid]['total_gpa']     += $gp * $credits;
            $students[$sid]['total_credits'] += $credits;
        }
    }

    // Compute CGPA
    foreach ($students as &$s) {
        $s['cgpa'] = $s['total_credits']>0 ? round($s['total_gpa']/$s['total_credits'],2) : null;
        $total     = count($s['exams']);
        $s['pass_count'] = count(array_filter($s['exams'], fn($e)=>$e['is_pass']===true));
        $s['fail_count'] = count(array_filter($s['exams'], fn($e)=>$e['is_pass']===false));
        $pctExams = array_filter($s['exams'], fn($e)=>$e['percentage']!==null);
        $pctVals  = array_map(fn($e)=>min(100.0,(float)$e['percentage']), $pctExams);
        $s['pct_avg'] = count($pctVals) ? round(array_sum($pctVals)/count($pctVals),1) : null;
    }
    unset($s);

    echo json_encode(['ok'=>true,'students'=>array_values($students),'total_rows'=>count($rows)]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// Page data: approved Q.Papers for dropdown + exams + courses list
// ══════════════════════════════════════════════════════════════════════════════
$approvedPapers = [];
$courses = [];
$exams   = [];
$marks   = [];

if ($db && $collegeId) {
    // Approved/released papers
    $apSql = "SELECT qp.id AS paper_id, qp.title AS paper_title, qp.version, qp.total_marks AS paper_max,
                     qp.total_questions, qp.status AS paper_status,
                     ex.id AS exam_id, ex.title AS exam_title, ex.type AS exam_type,
                     ex.max_marks, ex.pass_marks, ex.exam_date, ex.status AS exam_status,
                     c.name AS course_name, c.code AS course_code
              FROM question_papers qp
              JOIN exams ex  ON ex.id  = qp.exam_id
              JOIN courses c ON c.id   = ex.course_id
              WHERE qp.college_id=? AND qp.status IN ('approved','released')";
    $apParams = [$collegeId];
    if ($deptId && $role==='faculty') { $apSql .= ' AND ex.department_id=?'; $apParams[]=$deptId; }
    $apSql .= " ORDER BY FIELD(qp.status,'released','approved') ASC, ex.exam_date DESC, qp.version ASC";
    $st = $db->prepare($apSql); $st->execute($apParams);
    $approvedPapers = $st->fetchAll(PDO::FETCH_ASSOC);

    // Courses for filter
    $cSql = 'SELECT id,name,code FROM courses WHERE college_id=? AND status="active"';
    $cP   = [$collegeId];
    if ($deptId && $role==='faculty') { $cSql .= ' AND department_id=?'; $cP[]=$deptId; }
    $cSql .= ' ORDER BY name';
    $st = $db->prepare($cSql); $st->execute($cP);
    $courses = $st->fetchAll(PDO::FETCH_ASSOC);

    // Exams for filter
    $eSql = 'SELECT e.id,e.title,e.type,e.max_marks,e.pass_marks,e.exam_date,c.code AS course_code FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.college_id=?';
    $eP   = [$collegeId];
    if ($deptId && $role==='faculty') { $eSql .= ' AND e.department_id=?'; $eP[]=$deptId; }
    $eSql .= ' ORDER BY e.exam_date DESC';
    $st = $db->prepare($eSql); $st->execute($eP);
    $exams = $st->fetchAll(PDO::FETCH_ASSOC);

    // All marks (for JS init)
    $mSql = 'SELECT m.id,m.exam_id,m.student_id,u.full_name,u.roll_number,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.is_pass,m.remarks FROM marks m JOIN users u ON u.id=m.student_id WHERE m.college_id=?';
    $mP   = [$collegeId];
    if ($deptId && $role==='faculty') { $mSql .= ' AND ex.department_id=?'; /* skip – filtered in JS */ }
    // Use exam-scoped subquery instead
    $mSql = "SELECT m.id,m.exam_id,m.student_id,u.full_name,u.roll_number,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.is_pass,m.remarks FROM marks m JOIN users u ON u.id=m.student_id JOIN exams ex ON ex.id=m.exam_id WHERE m.college_id=?";
    $mP   = [$collegeId];
    if ($deptId && $role==='faculty') { $mSql .= ' AND ex.department_id=?'; $mP[]=$deptId; }
    $mSql .= ' ORDER BY u.full_name';
    $st = $db->prepare($mSql); $st->execute($mP);
    $marks = $st->fetchAll(PDO::FETCH_ASSOC);
}

$activeTab = $_GET['tab'] ?? 'marks-entry';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Marks & Grades — <?= h($collegeName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   GRADES & RESULTS — Design system from staff_noticeboard.php
   Teal #0F766E · Amber #F59E0B · Light bg #F8FAFC
   Glassmorphic deep-teal sidebar
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

  /* ── backward-compat aliases used in inline PHP styles ── */
  --navy:#0D5C56;--navy2:#F8FAFC;
  --teal3:rgba(20,184,166,.10);--teal4:rgba(20,184,166,.18);
  --teal2:#0D5C56;--white:#FFFFFF;

  --sidebar-w:264px;--top-h:64px;--radius:14px;
  --font:'Sora',sans-serif;--mono:'JetBrains Mono',monospace;
}

html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* ── Subtle page grid ── */
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
   SIDEBAR — Glassmorphic deep-teal (mirrors staff_noticeboard exactly)
══════════════════════════════════════════════════════════════════════════ */
.sidebar{
  width:var(--sidebar-w);
  font-family:'Plus Jakarta Sans',sans-serif;
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
    2px 0 50px rgba(15,118,110,.45),
    8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease;overflow:hidden;
}
.sidebar::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;pointer-events:none;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);
}
.sidebar::after{
  content:'';position:absolute;top:0;right:0;width:1px;bottom:0;pointer-events:none;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);
}
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0;position:relative;z-index:1}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(245,158,11,.25);border-radius:3px}

.sidebar-logo{
  padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);
  display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12);
}
.logo-mark{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;
  box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15);
}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}

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
.sc-label i{font-size:.54rem}
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
  box-shadow:inset 0 1px 0 rgba(245,158,11,.12);
}
.nav-item.active::before{
  content:'';position:absolute;left:0;top:18%;height:64%;width:3px;
  background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0;
}
.nav-badge{margin-left:auto;background:var(--amber-acc);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.nav-badge.amber{background:var(--amber);color:#fff}

/* ── Sidebar collapse button ── */
.sb-collapse-btn{
  margin-left:auto;flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);
  cursor:pointer;display:grid;place-items:center;
  font-size:.68rem;transition:all .2s;
}
.sb-collapse-btn:hover{
  background:rgba(245,158,11,.22);
  border-color:rgba(245,158,11,.50);
  color:var(--amber-acc);
}

/* ── Collapsed sidebar ── */
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

/* ══════════════════════════════════════════════════════════════════════════
   MAIN — Light clean content area
══════════════════════════════════════════════════════════════════════════ */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease}
.sidebar.collapsed~.main,.shell:has(.sidebar.collapsed) .main{margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w))}

.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{
  display:none;width:36px;height:36px;border-radius:9px;
  border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);
  color:rgba(255,255,255,.75);place-items:center;cursor:pointer;font-size:.9rem;
}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:rgba(255,255,255,.52);font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);color:#ffffff;display:grid;place-items:center;cursor:pointer;transition:all .18s;position:relative;font-size:.82rem;}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{
  font-size:.72rem;color:rgba(255,255,255,.72);
  background:rgba(255,255,255,.10);border:1px solid rgba(245,158,11,.20);
  padding:5px 11px;border-radius:7px;font-family:var(--mono);
}

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
.content{padding:20px 24px;flex:1}

@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Page header ── */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;animation:slideUp .4s ease both}
.page-header-left h2{font-size:1.15rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px}
.page-header-left h2 i{color:var(--teal);font-size:1rem}
.page-header-left p{font-size:.76rem;color:var(--muted);margin-top:4px}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:9px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .18s;text-decoration:none;font-family:var(--font)}
.btn-primary{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,118,110,.28)}
.btn-outline{background:#F8FAFC;color:var(--text);border:1px solid var(--border)}
.btn-outline:hover{border-color:var(--border-accent);color:var(--teal);background:var(--teal-soft)}
.btn-amber{background:var(--amber-soft);color:var(--amber-acc);border:1px solid rgba(245,158,11,.22)}
.btn-amber:hover{background:var(--amber-soft2)}
.btn-sm{padding:5px 11px;font-size:.75rem}
.btn-danger{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.22)}

/* ── Module tabs ── */
.module-tabs{
  display:flex;gap:3px;
  background:var(--card);border:1px solid var(--border);
  border-radius:11px;padding:4px;margin-bottom:20px;
  animation:slideUp .4s .05s ease both;
  box-shadow:0 1px 6px rgba(15,118,110,.06);
}
.tab-btn{display:flex;align-items:center;gap:7px;padding:8px 20px;border-radius:8px;border:none;font-size:.82rem;font-weight:600;color:var(--muted);cursor:pointer;background:transparent;transition:all .18s;white-space:nowrap;font-family:var(--font)}
.tab-btn i{font-size:.82rem}
.tab-btn:hover{color:var(--teal);background:var(--teal-soft)}
.tab-btn.active{background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* ── Cards ── */
.section-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:slideUp .45s .1s ease both;margin-bottom:18px;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.section-card-body{padding:20px}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);background:#F0FDFA}
.card-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal);font-size:.8rem}

/* ── Filter row ── */
.filter-row{display:flex;gap:8px;flex-wrap:wrap;padding:12px 20px;align-items:center;border-bottom:1px solid var(--border)}
.filter-select{background:#fff;border:1px solid var(--border);color:var(--text);font-size:.78rem;padding:7px 10px;border-radius:8px;cursor:pointer;outline:none;transition:border-color .18s;font-family:var(--font)}
.filter-select:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.08)}
.filter-select option{background:#fff;color:var(--text)}

/* ── Info banners ── */
.info-banner{display:flex;align-items:flex-start;gap:10px;padding:11px 16px;border-radius:9px;background:var(--teal-soft);border:1px solid var(--border-accent);margin-bottom:16px;font-size:.79rem;color:var(--text)}
.info-banner i{color:var(--teal);margin-top:2px;flex-shrink:0;font-size:.8rem}
.warn-banner{background:var(--amber-soft);border-color:rgba(245,158,11,.22)}
.warn-banner i{color:var(--amber-acc)}
.success-banner{background:var(--green2);border-color:rgba(22,163,74,.22)}
.success-banner i{color:var(--green)}

/* ── Empty state ── */
.empty-state{text-align:center;padding:32px 20px;color:var(--muted);font-size:.8rem}
.empty-state i{font-size:1.4rem;display:block;margin-bottom:8px;opacity:.22}
.empty-state p{font-size:.8rem}
.empty-state strong{display:block;font-size:.9rem;color:var(--text);margin-bottom:4px}

/* ── Scope banner ── */
.scope-banner{
  display:flex;align-items:center;flex-wrap:wrap;gap:6px;
  background:var(--teal-soft);border:1px solid var(--border-accent);
  border-radius:10px;padding:8px 14px;margin-bottom:14px;font-size:.76rem;
  animation:slideUp .4s ease both;
}
.scope-banner i{color:var(--teal);flex-shrink:0}
.scope-banner strong{color:var(--text)}

/* ── Marks-entry tabs ── */
.entry-mode-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:16px}
.entry-mode-btn{padding:8px 18px;border:none;background:transparent;color:var(--muted);font-size:.8rem;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;transition:all .18s;font-family:var(--font)}
.entry-mode-btn.active{color:var(--teal);border-bottom-color:var(--teal)}
.entry-mode-btn:hover{color:var(--text)}

/* ── Marks table ── */
.marks-table{width:100%;border-collapse:collapse}
.marks-table th{font-size:.64rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;padding:8px 12px;border-bottom:1px solid var(--border);text-align:left;font-weight:700}
.marks-table td{padding:9px 12px;border-bottom:1px solid rgba(15,118,110,.07);font-size:.8rem;color:var(--text);vertical-align:middle}
.marks-table tr:last-child td{border-bottom:none}
.marks-table tr:hover td{background:#F0FDFA}
.marks-input{
  width:78px;background:#fff;border:1px solid var(--border);
  color:var(--text);font-size:.82rem;font-weight:600;
  padding:6px 7px;border-radius:7px;outline:none;text-align:center;
  font-family:var(--mono);transition:border-color .18s,box-shadow .18s;
}
.marks-input:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.10);background:var(--card-hover)}
.marks-input.error{border-color:var(--red)!important;background:var(--red2)}
.marks-input.saved{border-color:var(--green)!important;background:var(--green2)}
.absent-toggle{
  width:30px;height:30px;border-radius:7px;border:1px solid var(--border);
  background:#F8FAFC;color:var(--muted);cursor:pointer;font-size:.72rem;
  transition:all .18s;display:flex;align-items:center;justify-content:center;
}
.absent-toggle.marked{background:var(--red2);border-color:rgba(220,38,38,.25);color:var(--red)}
.auto-grade{padding:3px 9px;border-radius:6px;font-size:.78rem;font-weight:800;display:inline-block}

/* ── Badges & pills ── */
.status-pill{display:inline-flex;align-items:center;gap:5px;font-size:.64rem;font-weight:700;padding:3px 8px;border-radius:50px}
.pill-pass{background:var(--green2);color:var(--green)}
.pill-fail{background:var(--red2);color:var(--red)}
.pill-pending{background:var(--amber2);color:var(--amber)}
.pill-pub{background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent)}
.type-chip{font-size:.64rem;font-weight:700;padding:2px 7px;border-radius:5px;white-space:nowrap;font-family:var(--mono)}
.chip-unit {background:var(--blue2);  color:var(--blue)}
.chip-mid  {background:var(--teal-soft);color:var(--teal)}
.chip-final{background:var(--amber2); color:var(--amber)}
.chip-prac {background:var(--green2); color:var(--green)}
.chip-paper{background:var(--purple2);color:var(--purple)}

/* ── Progress ── */
.progress-wrap{display:flex;align-items:center;gap:8px}
.progress-bar{flex:1;height:5px;border-radius:3px;background:rgba(15,118,110,.10);overflow:hidden}
.progress-fill{height:100%;border-radius:3px;transition:width .6s ease}

/* ── Grade maintenance table ── */
.grade-table{width:100%;border-collapse:collapse}
.grade-table th{font-size:.64rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;padding:8px 14px;border-bottom:1px solid var(--border);text-align:left;font-weight:700}
.grade-table td{padding:10px 14px;border-bottom:1px solid rgba(15,118,110,.07);font-size:.8rem;color:var(--text);vertical-align:middle}
.grade-table tr:last-child td{border-bottom:none}
.grade-table tr:hover td{background:#F0FDFA}
.grade-table .expand-row td{background:var(--teal-soft);border-top:1px solid var(--border-accent)}
.grade-badge{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:30px;padding:0 7px;border-radius:7px;font-weight:800;font-size:.82rem;font-family:var(--mono)}
.g-Ap{background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent)}
.g-A {background:var(--green2);color:var(--green)}
.g-B {background:var(--blue2); color:var(--blue)}
.g-Bm{background:rgba(29,78,216,.08);color:#3B82F6}
.g-C {background:var(--amber2);color:var(--amber)}
.g-D {background:var(--red2);  color:var(--red)}
.g-F {background:var(--red2);  color:var(--red)}
.g-none{background:#F1F5F9;color:var(--muted)}
.student-expand-btn{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.76rem;padding:4px 7px;border-radius:6px;transition:all .18s;font-family:var(--font)}
.student-expand-btn:hover{color:var(--teal);background:var(--teal-soft)}
.student-detail-row{display:none}
.student-detail-row.open{display:table-row}

/* ── Student detail expand panel ── */
.student-detail-inner{
  background:linear-gradient(135deg,#F0FDFA 0%,#E6FAF8 100%);
  border-top:2px solid rgba(20,184,166,.22);
  border-bottom:1px solid rgba(20,184,166,.12);
  padding:0 0 4px;
}
.student-detail-header{
  display:flex;align-items:center;gap:7px;
  font-size:.76rem;color:var(--teal-dark);font-weight:600;
  padding:10px 16px 8px;
  border-bottom:1px solid rgba(20,184,166,.14);
  background:rgba(20,184,166,.06);
  letter-spacing:.01em;
}
.detail-thead-row{background:rgba(15,118,110,.06)}
.detail-th{
  text-align:left;font-size:.63rem;font-weight:700;
  color:var(--text-muted);text-transform:uppercase;
  letter-spacing:.09em;padding:7px 12px;
  border-bottom:1px solid rgba(20,184,166,.14);
}
.detail-inner-row td{
  border-bottom:1px solid rgba(20,184,166,.08);
}
.detail-inner-row:last-child td{border-bottom:none}
.detail-inner-row:hover td{background:rgba(20,184,166,.06)}

/* ── Stat strip ── */
.stat-strip{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.stat-pill{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 6px rgba(15,118,110,.05);transition:transform .2s,box-shadow .2s}
.stat-pill:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(15,118,110,.10)}
.stat-pill-icon{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;font-size:.88rem;flex-shrink:0}
.stat-pill-icon.teal  {background:var(--teal-soft); color:var(--teal)}
.stat-pill-icon.green {background:var(--green2);    color:var(--green)}
.stat-pill-icon.red   {background:var(--red2);      color:var(--red)}
.stat-pill-icon.amber {background:var(--amber-soft);color:var(--amber-acc)}
.stat-pill-icon.blue  {background:var(--blue2);     color:var(--blue)}
.stat-pill-val{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-pill-label{font-size:.68rem;color:var(--muted);margin-top:2px}

/* ── Grade Distribution ── */
.dist-label-badge{
  font-size:.72rem;font-weight:700;
  background:var(--teal-soft);color:var(--teal);
  border:1px solid var(--border-accent);
  padding:3px 10px;border-radius:50px;
}
.dist-body{
  display:grid;grid-template-columns:1fr 1fr;gap:0;
  border-top:1px solid var(--border);
}
@media(max-width:700px){.dist-body{grid-template-columns:1fr}}
.dist-chart-wrap{
  padding:20px 24px 16px;border-right:1px solid var(--border);
}
.dist-chart{
  display:flex;align-items:flex-end;justify-content:space-between;
  gap:10px;height:140px;
}
.dist-bar-col{
  flex:1;display:flex;flex-direction:column;align-items:center;
  gap:6px;height:100%;justify-content:flex-end;
}
.dist-bar-val{
  font-size:.72rem;font-weight:800;color:var(--text-muted);
  font-family:var(--mono);line-height:1;min-height:16px;
}
.dist-bar-track{
  width:100%;flex:1;position:relative;
  background:rgba(15,118,110,.06);border-radius:8px 8px 0 0;
  display:flex;align-items:flex-end;overflow:hidden;min-height:8px;
}
.dist-bar-fill{
  width:100%;border-radius:8px 8px 0 0;
  transition:height .65s cubic-bezier(.22,.61,.36,1);
  position:relative;
}
.dist-bar-fill::after{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:rgba(255,255,255,.35);border-radius:8px 8px 0 0;
}
.dist-bar-grade{
  font-size:.76rem;font-weight:800;font-family:var(--mono);
  padding:3px 0;text-align:center;width:100%;
}
.dist-legend{
  padding:20px 24px;
  display:grid;grid-template-columns:1fr 1fr;gap:10px;
  align-content:start;
}
.dist-legend-tile{
  display:flex;align-items:center;gap:10px;
  background:var(--page-bg);border:1px solid var(--border);
  border-radius:10px;padding:10px 12px;
  transition:border-color .18s,background .18s;
}
.dist-legend-tile:hover{border-color:var(--border-accent);background:var(--card-hover)}
.dist-legend-swatch{
  width:10px;height:10px;border-radius:3px;flex-shrink:0;
}
.dist-legend-grade{
  font-size:.88rem;font-weight:800;font-family:var(--mono);
  min-width:26px;
}
.dist-legend-count{
  font-size:1rem;font-weight:800;font-family:var(--mono);
  color:var(--text);
}
.dist-legend-pct{
  font-size:.68rem;color:var(--text-muted);margin-top:1px;
}

/* ── Section-wise wide table ── */
.sec-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.sec-table{border-collapse:collapse;width:100%}
.sec-table th{font-size:.64rem;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;padding:8px 10px;border-bottom:1px solid var(--border);text-align:center;font-weight:700;white-space:nowrap}
.sec-table th.name-th{text-align:left}
.sec-table td{padding:7px 6px;border-bottom:1px solid rgba(15,118,110,.07);font-size:.79rem;color:var(--text);vertical-align:middle;text-align:center}
.sec-table td.name-td{text-align:left;padding-left:12px;white-space:nowrap}
.sec-table tr:last-child td{border-bottom:none}
.sec-table tr:hover td{background:#F0FDFA}
.sec-header-row th{background:var(--teal-soft)}
.q-input{
  width:56px;background:#fff;border:1px solid var(--border);
  color:var(--text);font-size:.78rem;font-weight:600;
  padding:5px 4px;border-radius:6px;outline:none;text-align:center;
  font-family:var(--mono);transition:border-color .18s;
}
.q-input:focus{border-color:var(--teal-light);background:var(--card-hover);box-shadow:0 0 0 2px rgba(20,184,166,.10)}
.q-input.error{border-color:var(--red)!important}
.q-input.saved{border-color:var(--green)!important}
.save-row-btn{
  background:linear-gradient(135deg,var(--teal),var(--teal-light));
  color:#fff;border:none;border-radius:7px;padding:5px 13px;
  font-size:.72rem;font-weight:700;cursor:pointer;font-family:var(--font);
  transition:all .18s;white-space:nowrap;
}
.save-row-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,118,110,.25)}
.save-row-btn:disabled{opacity:.5;cursor:not-allowed}

/* ── Toast ── */
#toast{
  position:fixed;bottom:24px;right:24px;z-index:999;
  background:#fff;border:1px solid var(--border-accent);
  color:var(--teal);font-size:.82rem;font-weight:600;
  padding:10px 18px;border-radius:11px;
  box-shadow:0 8px 24px rgba(15,118,110,.14);
  display:flex;align-items:center;gap:8px;
  transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none;
}

/* ── Summary bar ── */
#marksSummaryBar{padding:12px 20px 0;border-top:1px solid var(--border);display:flex;gap:20px;flex-wrap:wrap}
#marksSummaryBar span{font-size:.78rem;color:var(--muted)}
#marksSummaryBar strong{color:var(--text)}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Sidebar overlay (mobile) ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99;backdrop-filter:blur(2px)}
.sidebar-overlay.active{display:block}

@media(max-width:900px){.stat-strip{flex-direction:column}.module-tabs{overflow-x:auto}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%}
  .content{padding:14px}
  .hamburger{display:grid}
}

/* ── Grade distribution chart ── */
#gmDistChart{height:120px}
#gmDistChart>div{transition:opacity .3s}
#gmDistChart>div:hover{opacity:.85!important}

/* ── GM search ── */
#gmSearch{transition:border-color .2s,box-shadow .2s;background:#fff;border:1px solid var(--border);color:var(--text)}
#gmSearch:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.08);outline:none}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="shell">

<!-- ── SIDEBAR ─────────────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">PE</div>
    <div class="logo-text">PEPA <span>ERP Platform</span></div>
    <button class="sb-collapse-btn" id="sidebarCollapseBtn" onclick="collapseSidebar()" title="Collapse sidebar">
      <i class="fas fa-angles-left" id="collapseIcon"></i>
    </button>
  </div>

  <div class="sidebar-scroll">
    <!-- Scope chip -->
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= h($collegeName) ?></div>
      <div class="sc-depts">
        <?php if ($deptName): ?>
          <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= h($deptName) ?></span>
        <?php else: ?>
          <span class="sc-dept-tag"><i class="fas fa-building-columns"></i><?= h($collegeName) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard"><i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span></a>
      <a href="students.php" class="nav-item" data-tip="Students"><i class="fas fa-users"></i><span class="nav-text"> Students</span></a>
      <div class="nav-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance"><i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span></a>
      <a href="grades_results.php" class="nav-item active" data-tip="Grades &amp; Results"><i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span></a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations"><i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span></a>
      <a href="timetable.php" class="nav-item" data-tip="Timetable"><i class="fas fa-calendar-days"></i><span class="nav-text"> Timetable</span></a>
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

  <!-- Sidebar user (pinned bottom) -->
  <div class="sidebar-user">
    <?php $nm=$user['full_name']??'Faculty'; ?>
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . h($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= h($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= h(initials($nm)) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= h($nm) ?></div>
      <div class="user-role-tag"><?= ucfirst(str_replace('_',' ',$role)) ?><?= $deptCode ? ' · '.$deptCode : '' ?></div>
    </div>
    <button class="logout-btn" onclick="doLogout()" title="Logout"><i class="fas fa-right-from-bracket"></i></button>
  </div>
</aside>

<!-- ── MAIN ─────────────────────────────────────────────────────── -->
<div class="main">
  <div class="topbar">
    <button class="hamburger" id="menuToggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="topbar-title">Grades &amp; Results</div>
    <div class="topbar-actions">
      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
      </div>
      <!-- Avatar -->
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= h($nm) ?>"
             style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . h($avatarColor) ?>">
          <?php if ($avatarPath): ?>
            <img src="<?= h($avatarPath) ?>" alt="<?= h(initials($nm)) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
          <?php else: ?>
            <?= h(initials($nm)) ?>
          <?php endif; ?>
        </div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= h($nm) ?></div>
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= ucfirst(str_replace('_',' ',$role)) ?></div>
          </div>
          <a href="profile.php" class="ad-item"><i class="fas fa-circle-user"></i> My Profile</a>
          <a href="settings.php" class="ad-item"><i class="fas fa-gear"></i> Settings</a>
          <div class="ad-sep"></div>
          <button class="ad-item danger" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </div>

    <div class="content">

      <!-- Scope banner -->

      <!-- Page header -->
      <div class="page-header">
        <div class="page-header-left">
          <h2><i class="fas fa-chart-bar"></i> Marks Entry &amp; Grade Maintenance</h2>
          <p>Enter marks via approved question papers and manage student grade records</p>
        </div>
      </div>


      <!-- ─── Module Tabs ──────────────────────────────────────── -->
      <div class="module-tabs">
        <button class="tab-btn <?= $activeTab==='marks-entry'?'active':'' ?>"   onclick="switchTab('marks-entry',this)">
          <i class="fas fa-keyboard"></i> Marks Entry
        </button>
        <button class="tab-btn <?= $activeTab==='grade-maintenance'?'active':'' ?>" onclick="switchTab('grade-maintenance',this)">
          <i class="fas fa-sliders"></i> Grade Maintenance
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 1 — MARKS ENTRY
      ══════════════════════════════════════════════════════ -->
      <div class="tab-panel <?= $activeTab==='marks-entry'?'active':'' ?>" id="tab-marks-entry">

        <?php if (empty($approvedPapers)): ?>
        <div class="section-card">
          <div class="empty-state">
            <i class="fas fa-file-lines"></i>
            <strong>No Approved Question Papers</strong>
            <p>Go to <a href="examinations.php" style="color:var(--teal)">Examinations</a> and approve or release a question paper before entering marks.</p>
          </div>
        </div>
        <?php else: ?>

        <div class="info-banner">
          <i class="fas fa-circle-info"></i>
          <span>Only <strong>approved</strong> and <strong>released</strong> question papers appear in the dropdown.
          Select a paper to enter marks section-wise (per question) or as a flat total.</span>
        </div>

        <!-- Q-Paper dropdown -->
        <div class="section-card">
        <div class="filter-row">
          <select class="filter-select" id="examSelect" onchange="onExamChange()" style="min-width:340px;max-width:520px">
            <option value="">— Select Question Paper —</option>
            <?php
            // Group by exam for cleaner UX
            $papersByExam = [];
            foreach ($approvedPapers as $ap) {
                $papersByExam[$ap['exam_id']][] = $ap;
            }
            foreach ($papersByExam as $examId => $papers):
                $first = $papers[0];
            ?>
            <optgroup label="<?= h($first['exam_title']) ?> [<?= h($first['course_code']) ?>] — <?= $first['exam_date'] ? date('d M Y', strtotime($first['exam_date'])) : 'TBD' ?>">
              <?php foreach ($papers as $ap): ?>
              <option value="<?= h($ap['exam_id']) ?>"
                data-paperid="<?= h($ap['paper_id']) ?>"
                data-max="<?= h($ap['max_marks']) ?>"
                data-pass="<?= h($ap['pass_marks']) ?>"
                data-papermax="<?= h($ap['paper_max']) ?>"
                data-title="<?= h($ap['exam_title']) ?> – Set <?= h($ap['version']) ?>"
                data-type="<?= h($ap['exam_type']) ?>"
                data-status="<?= h($ap['paper_status']) ?>">
                ✦ Set <?= h($ap['version']) ?> — <?= h($ap['paper_title']) ?>
                [Max: <?= h($ap['max_marks']) ?>, <?= ucfirst($ap['paper_status']) ?>]
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline btn-sm" onclick="resetMarks()"><i class="fas fa-rotate-left"></i> Reset</button>
          <span id="paperStatusBadge"></span>
        </div>
        </div><!-- /filter section-card -->

        <!-- Entry mode toggle -->
        <div id="entryModeTabs" style="display:none" class="entry-mode-tabs" style="padding:0 20px">
          <button id="tabFlatBtn"  class="entry-mode-btn active" onclick="switchEntryMode('flat')"><i class="fas fa-table"></i> Total Marks</button>
          <button id="tabSecBtn"   class="entry-mode-btn" onclick="switchEntryMode('section')"><i class="fas fa-layer-group"></i> Section-wise Entry</button>
        </div>

        <!-- Main card -->
        <div class="section-card" id="marksCard">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-keyboard"></i> <span id="marksTitle">Select a question paper above</span></div>
            <div style="display:flex;gap:14px;align-items:center;font-size:.78rem;color:var(--muted)">
              Max: <strong style="color:var(--white);font-family:var(--mono)" id="maxDisplay">—</strong>
              &nbsp;Pass: <strong style="color:var(--green);font-family:var(--mono)" id="passDisplay">—</strong>
            </div>
          </div>

          <!-- Flat (total marks) panel -->
          <div id="marksContent">
            <div class="empty-state">
              <i class="fas fa-arrow-up" style="font-size:1.4rem"></i>
              <strong>Select a Question Paper</strong>
              <p>Choose an approved paper from the dropdown to begin entering marks.</p>
            </div>
          </div>

          <!-- Section-wise panel -->
          <div id="sectionMarksContent" style="display:none">
            <div class="empty-state">
              <i class="fas fa-layer-group" style="font-size:1.4rem"></i>
              <strong>Section-wise Entry</strong>
              <p>Select a paper with sections to enter marks question by question.</p>
            </div>
          </div>

          <div id="marksSummaryBar" style="display:none"></div>
        </div>

        <!-- ── View Entered Marks section ───────────────────────── -->
        <div class="section-card" id="viewMarksCard" style="display:none">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-eye"></i> View Entered Marks &amp; Results</div>
            <div style="display:flex;gap:10px">
              <button class="btn btn-outline btn-sm" onclick="refreshViewMarks()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
          </div>

          <div id="viewMarksContent">
            <!-- populated by JS -->
          </div>
        </div>

        <?php endif; ?>
      </div><!-- /tab-marks-entry -->

      <!-- ══════════════════════════════════════════════════════
           TAB 2 — GRADE MAINTENANCE
      ══════════════════════════════════════════════════════ -->
      <div class="tab-panel <?= $activeTab==='grade-maintenance'?'active':'' ?>" id="tab-grade-maintenance">

        <!-- Filters -->
        <div class="section-card">
        <div class="filter-row" style="border-bottom:none;padding:14px 16px">
          <select class="filter-select" id="gmExamFilter" onchange="loadGradeSummary()" style="min-width:240px">
            <option value="">All Exams</option>
            <?php foreach ($exams as $ex): ?>
            <option value="<?= $ex['id'] ?>"><?= h($ex['title']) ?> [<?= h($ex['course_code']) ?>]</option>
            <?php endforeach; ?>
          </select>
          <select class="filter-select" id="gmCourseFilter" onchange="loadGradeSummary()" style="min-width:200px">
            <option value="">All Courses</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <input type="text" class="filter-select" id="gmSearch" placeholder="🔍 Search student / roll no…"
            oninput="filterGradeTable()" style="min-width:210px">
          <button class="btn btn-outline btn-sm" onclick="loadGradeSummary()"><i class="fas fa-sync-alt"></i> Refresh</button>
          <button class="btn btn-outline btn-sm" onclick="exportGradeCSV()" title="Export to CSV"><i class="fas fa-file-csv"></i> Export CSV</button>
          <button class="btn btn-outline btn-sm" onclick="printGradeReport()" title="Print Report"><i class="fas fa-print"></i> Print</button>
        </div>
        </div><!-- /filter card -->

        <!-- Stats strip -->
        <div class="stat-strip" id="gmStatStrip" style="display:none">
          <div class="stat-pill">
            <div class="stat-pill-icon teal"><i class="fas fa-users"></i></div>
            <div><div class="stat-pill-val" id="gmStatStudents">0</div><div class="stat-pill-label">Students</div></div>
          </div>
          <div class="stat-pill">
            <div class="stat-pill-icon green"><i class="fas fa-circle-check"></i></div>
            <div><div class="stat-pill-val" id="gmStatPass">0</div><div class="stat-pill-label">Avg Pass Rate</div></div>
          </div>
          <div class="stat-pill">
            <div class="stat-pill-icon red"><i class="fas fa-circle-xmark"></i></div>
            <div><div class="stat-pill-val" id="gmStatFail">0</div><div class="stat-pill-label">Fail Count</div></div>
          </div>
          <div class="stat-pill">
            <div class="stat-pill-icon amber"><i class="fas fa-percent"></i></div>
            <div><div class="stat-pill-val" id="gmStatPct">—</div><div class="stat-pill-label">Class Avg %</div></div>
          </div>
          <div class="stat-pill">
            <div class="stat-pill-icon blue"><i class="fas fa-star"></i></div>
            <div><div class="stat-pill-val" id="gmStatCgpa">—</div><div class="stat-pill-label">Avg CGPA</div></div>
          </div>
        </div>

        <!-- Grade table card -->
        <div class="section-card">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-table-list"></i> Student Grade Records</div>
            <span id="gmRowCount" style="font-size:.78rem;color:var(--muted)"></span>
          </div>

          <div id="gmContent">
            <div class="empty-state">
              <i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i>
              <strong>Loading…</strong>
            </div>
          </div>
        </div>

        <!-- Grade Distribution card -->
        <div class="section-card" id="gmDistCard" style="display:none">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-chart-bar"></i> Grade Distribution</div>
            <span class="dist-label-badge" id="gmDistLabel"></span>
          </div>
          <div class="dist-body">
            <!-- Bar chart -->
            <div class="dist-chart-wrap">
              <div class="dist-chart" id="gmDistChart"></div>
            </div>
            <!-- Legend tiles -->
            <div class="dist-legend" id="gmDistLegend"></div>
          </div>
        </div>

        <!-- Grade scale reference card -->
        <div class="section-card" id="gmScaleCard">
          <div class="card-header">
            <div class="card-title"><i class="fas fa-info-circle"></i> Grade Scale Reference</div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;padding:16px 20px">
            <?php
            $scaleRows=[
              ['A+','#00d4bb','90–100','10.0 GPA'],
              ['A', '#10b981','80–89', '9.0 GPA'],
              ['B', '#3b82f6','70–79', '8.0 GPA'],
              ['B-','#6eb5f5','60–69', '7.0 GPA'],
              ['C', '#f59e0b','50–59', '6.0 GPA'],
              ['D', '#ef4444','40–49', '5.0 GPA'],
              ['F', '#ef4444','0–39',  '0.0 GPA'],
            ];
            foreach ($scaleRows as [$gl,$gc,$gr,$gg]):
            ?>
            <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px">
              <span style="font-size:1.2rem;font-weight:800;color:<?= $gc ?>;min-width:28px"><?= $gl ?></span>
              <div>
                <div style="font-size:.82rem;color:var(--text);font-weight:600"><?= $gr ?> marks</div>
                <div style="font-size:.72rem;color:var(--muted)"><?= $gg ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /tab-grade-maintenance -->

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /shell -->

<!-- ─── Toast ────────────────────────────────────────────────────── -->
<div id="toast">
  <i class="fas fa-circle-check" id="toastIcon" style="color:var(--teal)"></i>
  <span id="toastMsg">Done!</span>
</div>

<script>
/* ─── Marks data (scoped) ───────────────────────────────────────── */
const ALL_MARKS = <?php
  $jsMarks=[];
  foreach($marks as $m){
    $jsMarks[]=['id'=>(int)$m['id'],'exam_id'=>(int)$m['exam_id'],'student_id'=>(int)$m['student_id'],'full_name'=>$m['full_name'],'roll_number'=>$m['roll_number']??'','obtained'=>$m['obtained_marks']!==null?(float)$m['obtained_marks']:null,'is_absent'=>(bool)((int)$m['is_absent']),'grade'=>$m['grade']??'','pct'=>$m['percentage']!==null?(float)$m['percentage']:null,'is_pass'=>$m['is_pass']!==null?(bool)$m['is_pass']:null,'remarks'=>$m['remarks']??''];
  }
  echo json_encode($jsMarks);
?>;

/* ─── Helpers ───────────────────────────────────────────────────── */
function getGradeInfo(pct){
  if(pct>=90) return{g:'A+',color:'#00d4bb',cls:'g-Ap',gpa:10};
  if(pct>=80) return{g:'A', color:'#10b981',cls:'g-A', gpa:9};
  if(pct>=70) return{g:'B', color:'#3b82f6',cls:'g-B', gpa:8};
  if(pct>=60) return{g:'B-',color:'#6eb5f5',cls:'g-Bm',gpa:7};
  if(pct>=50) return{g:'C', color:'#f59e0b',cls:'g-C', gpa:6};
  if(pct>=40) return{g:'D', color:'#ef4444',cls:'g-D', gpa:5};
  return{g:'F',color:'#ef4444',cls:'g-F',gpa:0};
}
function gradeClass(g){
  return{'A+':'g-Ap','A':'g-A','B':'g-B','B-':'g-Bm','C':'g-C','D':'g-D','F':'g-F'}[g]||'g-none';
}
function showToast(msg,isErr=false){
  const t=document.getElementById('toast'),ic=document.getElementById('toastIcon');
  t.style.background=isErr?'rgba(231,111,81,.15)':'rgba(0,198,174,.15)';
  t.style.borderColor=isErr?'rgba(231,111,81,.3)':'rgba(0,198,174,.3)';
  ic.className='fas fa-'+(isErr?'triangle-exclamation':'circle-check');
  ic.style.color=isErr?'var(--red)':'var(--teal)';
  document.getElementById('toastMsg').textContent=msg;
  t.style.transform='translateY(0)';t.style.opacity='1';
  setTimeout(()=>{t.style.transform='translateY(80px)';t.style.opacity='0';},3000);
}

/* ─── Tab switch ────────────────────────────────────────────────── */
function switchTab(id,btn){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  if(btn) btn.classList.add('active');
  if(id==='grade-maintenance') loadGradeSummary();
}

/* ══════════════════════════════════════════════════════════════════
   MARKS ENTRY
══════════════════════════════════════════════════════════════════ */
let _entryMode='section';
let _currentExamId=0;
let _secData=null;
let _activeSectionFilter=null;

function onExamChange(){
  const sel=document.getElementById('examSelect');
  const examId=parseInt(sel.value)||0;
  _currentExamId=examId;
  const tabsEl=document.getElementById('entryModeTabs');
  const viewCard=document.getElementById('viewMarksCard');
  if(examId){
    tabsEl.style.display='flex';
    viewCard.style.display='';
  } else {
    tabsEl.style.display='none';
    viewCard.style.display='none';
  }
  // Badge
  const opt=sel.options[sel.selectedIndex];
  const status=opt?.dataset?.status||'';
  const statusColors={draft:'var(--muted)',approved:'var(--teal)',released:'var(--green)'};
  document.getElementById('paperStatusBadge').innerHTML=status
    ?`<span class="status-pill" style="background:rgba(0,198,174,.1);color:${statusColors[status]||'var(--muted)'};border:1px solid rgba(0,198,174,.2)">${status.charAt(0).toUpperCase()+status.slice(1)}</span>`:'';

  if(!examId) { resetPanels(); return; }
  _activeSectionFilter=null;
  // Always default to section-wise if paper exists
  switchEntryMode(_entryMode||'section',false);
  if(_entryMode==='section') loadSectionMarks();
  else loadExamMarks();
  refreshViewMarks();
}

function switchEntryMode(mode,reload=true){
  _entryMode=mode;
  const fp=document.getElementById('marksContent'),sp=document.getElementById('sectionMarksContent');
  const fb=document.getElementById('tabFlatBtn'),sb=document.getElementById('tabSecBtn');
  if(mode==='section'){
    fp.style.display='none';sp.style.display='';
    fb&&fb.classList.remove('active');sb&&sb.classList.add('active');
    if(reload&&_currentExamId) loadSectionMarks();
  } else {
    sp.style.display='none';fp.style.display='';
    sb&&sb.classList.remove('active');fb&&fb.classList.add('active');
    if(_currentExamId) loadExamMarks();
  }
}

function resetPanels(){
  document.getElementById('marksContent').innerHTML='<div class="empty-state"><i class="fas fa-arrow-up" style="font-size:1.5rem"></i><strong>Select a Question Paper</strong><p>Choose an approved paper from the dropdown.</p></div>';
  document.getElementById('sectionMarksContent').innerHTML='<div class="empty-state"><i class="fas fa-layer-group" style="font-size:1.5rem"></i><strong>Section-wise Entry</strong><p>Select a paper with sections.</p></div>';
  document.getElementById('marksTitle').textContent='Select a question paper above';
  document.getElementById('maxDisplay').textContent='—';
  document.getElementById('passDisplay').textContent='—';
  document.getElementById('marksSummaryBar').style.display='none';
}

/* ─── Load section-wise marks ───────────────────────────────────── */
async function loadSectionMarks(){
  const examId=_currentExamId; if(!examId) return;
  const sel=document.getElementById('examSelect');
  const opt=sel.options[sel.selectedIndex];
  const paperId=opt?.dataset?.paperid||'';
  const panel=document.getElementById('sectionMarksContent');
  panel.innerHTML='<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><strong>Loading sections…</strong></div>';
  document.getElementById('marksTitle').textContent=opt?.dataset?.title||'Loading…';
  document.getElementById('maxDisplay').textContent=opt?.dataset?.max||'—';
  document.getElementById('passDisplay').textContent=opt?.dataset?.pass||'—';

  const fd=new FormData();
  fd.append('ajax_load_sections','1');
  fd.append('exam_id',examId);
  if(paperId) fd.append('paper_id',paperId);
  try{
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(!data.ok){
      panel.innerHTML=`<div class="empty-state"><i class="fas fa-triangle-exclamation" style="color:var(--amber)"></i><strong>Cannot load sections</strong><p>${data.msg}</p></div>`;
      return;
    }
    _secData=data;
    renderSectionTable();
  }catch(e){
    panel.innerHTML='<div class="empty-state"><i class="fas fa-wifi" style="color:var(--red)"></i><strong>Network error</strong><p>Could not reach server.</p></div>';
  }
}

function _getStudentSections(students){
  const seen=new Set(), out=[];
  students.forEach(s=>{ const v=(s.student_section||'').trim().toUpperCase(); if(v&&!seen.has(v)){seen.add(v);out.push(v);} });
  return out.sort();
}

function setSectionFilter(sec){
  _activeSectionFilter=sec;
  renderSectionTable();
}

function _applySecFilter(students){
  if(!_activeSectionFilter) return students;
  return students.filter(s=>(s.student_section||'').trim().toUpperCase()===_activeSectionFilter);
}

function _renderSecPickerBar(students){
  const secs=_getStudentSections(students);
  if(secs.length<2) return '';
  const vis=_activeSectionFilter
    ?students.filter(s=>(s.student_section||'').trim().toUpperCase()===_activeSectionFilter).length
    :students.length;
  const allActive=(_activeSectionFilter===null);
  let btns=`<button onclick="setSectionFilter(null)" style="padding:5px 14px;border-radius:6px;cursor:pointer;font-size:.78rem;font-weight:700;border:1.5px solid ${allActive?'var(--teal)':'rgba(255,255,255,.18)'};background:${allActive?'rgba(0,198,174,.15)':'transparent'};color:${allActive?'var(--teal)':'var(--muted)'};transition:all .15s">All Sections</button>`;
  secs.forEach(sec=>{
    const active=(_activeSectionFilter===sec);
    const cnt=students.filter(s=>(s.student_section||'').trim().toUpperCase()===sec).length;
    btns+=`<button onclick="setSectionFilter('${sec}')" style="padding:5px 20px;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:700;letter-spacing:.04em;border:1.5px solid ${active?'var(--teal)':'rgba(255,255,255,.18)'};background:${active?'rgba(0,198,174,.18)':'transparent'};color:${active?'var(--teal)':'var(--text)'};transition:all .15s"><i class='fas fa-users' style='font-size:.68rem;margin-right:4px;opacity:.7'></i>Sec ${sec} <span style='font-size:.65rem;opacity:.6;margin-left:3px'>(${cnt})</span></button>`;
  });
  return `<div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.25);border-radius:10px;padding:12px 16px;margin-bottom:14px">`
    +`<i class="fas fa-layer-group" style="color:var(--blue);flex-shrink:0"></i>`
    +`<span style="font-size:.8rem;font-weight:600;color:var(--text)">Enter marks for class section:</span>`
    +btns
    +`<span style="margin-left:auto;font-size:.72rem;color:var(--muted)">Showing <strong style="color:var(--text)">${vis}</strong> student${vis!==1?'s':''}</span>`
    +`</div>`;
}

function renderSectionTable(){
  if(!_secData) return;
  const {paper,sections,students,existing,existingQ,exam_max,exam_pass}=_secData;
  const panel=document.getElementById('sectionMarksContent');
  const effMax=parseFloat(_secData.paper_max)||parseFloat(exam_max)||100;
  const effPass=parseFloat(exam_pass)||40;

  // Detect mode:
  // - "virtual"  : single section with virtual=true  → one total-marks input per student
  // - "blueprint": sections exist but no questions linked → one input per section
  // - "questions": sections have per-question rows    → one input per question
  const isVirtual   = sections.length===1 && sections[0].virtual;
  const hasQuestions= !isVirtual && sections.some(s=>(s.questions||[]).length>0);
  const modeLabel   = isVirtual?'Direct Entry':hasQuestions?'Per-Question Entry':'Section-wise Entry';

  // Flatten questions (only used in questions mode)
  let allQs=[],globalNo=1;
  if(hasQuestions){
    sections.forEach(sec=>{
      (sec.questions||[]).forEach((q,li)=>{
        allQs.push({bid:sec.blueprint_id,qid:q.question_id,q_marks:q.q_marks,q_no:globalNo++,section:sec.section_name,sec_max:sec.section_max});
      });
    });
  }

  // Paper info bar
  const paperDesc = isVirtual
    ? `Direct marks entry · Max: ${effMax}`
    : hasQuestions
      ? `${allQs.length} Questions · ${sections.length} Section(s)`
      : `${sections.length} Section(s) · blueprint entry`;

  let html=`<div style="background:rgba(0,198,174,.06);border:1px solid rgba(0,198,174,.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <i class="fas fa-file-lines" style="color:var(--teal)"></i>
      <strong style="color:var(--white)">${paper.title}</strong>
      <span class="type-chip chip-paper">Set ${paper.version}</span>
      <span style="font-size:.72rem;color:var(--muted)">${paperDesc}</span>
    </div>
    <div style="display:flex;gap:16px;font-size:.8rem;align-items:center">
      <span>Max: <strong style="color:var(--teal)">${effMax}</strong></span>
      <span>Pass: <strong style="color:var(--amber)">${effPass}</strong></span>
      <span style="font-size:.68rem;background:rgba(59,130,246,.12);color:var(--blue);padding:2px 8px;border-radius:4px">${modeLabel}</span>
    </div>
  </div>`;

  // ── MODE 1: Virtual (no blueprint, no questions) — one total input per student ──
  if(isVirtual){
    html+=_renderSecPickerBar(students);
    html+=`<div class="info-banner warn-banner" style="margin-bottom:14px">
      <i class="fas fa-info-circle"></i>
      <span>This paper has no linked questions or blueprint sections. Enter the <strong>total marks</strong> directly for each student. Marks are saved to the database and linked to this paper.</span>
    </div>`;
    html+=`<div style="overflow-x:auto"><table class="sec-table">
      <thead><tr>
        <th class="name-th" style="min-width:30px">#</th>
        <th class="name-th" style="min-width:80px">Roll No.</th>
        <th class="name-th" style="min-width:160px">Student</th>
        <th style="text-align:center;min-width:120px">Total Marks<br><span style="font-size:.6rem;font-weight:400;color:var(--muted)">max ${effMax}</span></th>
        <th style="text-align:center;min-width:60px">Absent</th>
        <th style="text-align:center;min-width:70px">%</th>
        <th style="text-align:center;min-width:60px">Grade</th>
        <th style="text-align:center;min-width:80px">Save</th>
      </tr></thead><tbody>`;

    _applySecFilter(students).forEach((stu,idx)=>{
      const sid=stu.student_id, markId=stu.mark_id;
      // Get existing value: from existingSM (section-level, bid=0) or from marks row
      const existRow=existing&&existing[sid]&&existing[sid][0]?existing[sid][0]:null;
      const savedVal=existRow?existRow.obtained_marks:(stu.obtained_marks!==null&&stu.obtained_marks!==undefined?stu.obtained_marks:'');
      const isAbs=existRow?parseInt(existRow.is_absent):parseInt(stu.is_absent||0);
      const filled=savedVal!==''&&savedVal!==null;
      const pct=filled&&effMax>0?Math.round(parseFloat(savedVal)/effMax*100):null;
      const gi=pct!==null?getGradeInfo(pct):null;
      const totalCol=filled?(parseFloat(savedVal)>=effPass?'var(--teal)':'var(--red)'):'var(--muted)';

      html+=`<tr id="srow-${sid}">
        <td class="name-td" style="color:var(--muted)">${idx+1}</td>
        <td class="name-td" style="color:var(--muted);font-size:.76rem">${stu.roll_number||'—'}</td>
        <td class="name-td"><strong style="color:var(--white)">${stu.full_name}</strong></td>
        <td style="text-align:center">
          <input class="q-input" type="number" id="sv_${sid}"
            value="${filled&&!isAbs?parseFloat(savedVal):''}"
            min="0" max="${effMax}" step="0.5" placeholder="/${effMax}"
            ${isAbs?'disabled style="opacity:.4"':''}
            oninput="updateVirtualRow(${sid},${effMax},${effPass})">
        </td>
        <td style="text-align:center">
          <button class="absent-toggle${isAbs?' marked':''}" id="absv-${sid}"
            onclick="toggleVirtualAbsent(${sid},${markId},'${paper.id}',${effMax},${effPass})"
            title="${isAbs?'Mark Present':'Mark Absent'}">
            <i class="fas fa-${isAbs?'xmark':'check'}"></i>
          </button>
        </td>
        <td style="text-align:center" id="svpct-${sid}">
          ${pct!==null?`<span style="font-weight:700;color:${pct>=40?'var(--text)':'var(--red)'}">${pct}%</span>`:'—'}
        </td>
        <td style="text-align:center" id="svgrade-${sid}">
          ${gi?`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`:'—'}
        </td>
        <td style="text-align:center">
          <button class="save-row-btn" id="savebtn-${sid}"
            onclick="saveVirtualMark(${sid},${markId},'${paper.id}',${effMax},${effPass})">
            <i class="fas fa-check"></i> Save
          </button>
        </td>
      </tr>`;
    });
    html+='</tbody></table></div>';
    panel.innerHTML=html;
    return;
  }

  // ── MODE 2: Blueprint-only (sections exist, no per-question links) ──
  if(!hasQuestions){
    html+=_renderSecPickerBar(students);
    html+=`<div class="info-banner" style="margin-bottom:14px">
      <i class="fas fa-layer-group"></i>
      <span>Enter marks section by section. Each section's total is saved separately and summed automatically.</span>
    </div>`;
    html+=`<div style="overflow-x:auto"><table class="sec-table">
      <thead><tr>
        <th class="name-th" style="min-width:30px">#</th>
        <th class="name-th" style="min-width:80px">Roll No.</th>
        <th class="name-th" style="min-width:150px">Student</th>`;
    sections.forEach(sec=>{
      html+=`<th style="text-align:center;min-width:80px;background:rgba(0,198,174,.06)">
        ${sec.section_name}<br><span style="font-size:.6rem;font-weight:400;color:var(--muted)">max ${sec.section_max}</span>
      </th>`;
    });
    html+=`<th style="text-align:center;min-width:70px">Total<br><span style="font-size:.6rem;font-weight:400;color:var(--muted)">/${effMax}</span></th>
           <th style="text-align:center;min-width:60px">Grade</th>
           <th style="text-align:center;min-width:80px">Save</th>
      </tr></thead><tbody>`;

    _applySecFilter(students).forEach((stu,idx)=>{
      const sid=stu.student_id, markId=stu.mark_id;
      let rowTotal=0,anyFilled=false;
      const secCells=sections.map(sec=>{
        const bid=sec.blueprint_id;
        const existRow=existing&&existing[sid]&&existing[sid][bid]?existing[sid][bid]:null;
        const savedVal=existRow?existRow.obtained_marks:'';
        if(savedVal!==''&&savedVal!==null){rowTotal+=parseFloat(savedVal)||0;anyFilled=true;}
        return `<td style="text-align:center">
          <input class="q-input" type="number" id="sb_${sid}_${bid}"
            value="${savedVal!==''&&savedVal!==null?parseFloat(savedVal):''}"
            min="0" max="${sec.section_max}" step="0.5" placeholder="—"
            oninput="updateBlueprintRow(${sid},${effMax},${effPass})">
        </td>`;
      }).join('');

      const pct=anyFilled&&effMax>0?Math.round(rowTotal/effMax*100):null;
      const gi=pct!==null?getGradeInfo(pct):null;
      const totalCol=anyFilled?(rowTotal>=effPass?'var(--teal)':'var(--red)'):'var(--muted)';

      html+=`<tr>
        <td class="name-td" style="color:var(--muted)">${idx+1}</td>
        <td class="name-td" style="color:var(--muted);font-size:.76rem">${stu.roll_number||'—'}</td>
        <td class="name-td"><strong style="color:var(--white)">${stu.full_name}</strong></td>
        ${secCells}
        <td id="stotal-${sid}" style="text-align:center">
          <strong style="color:${totalCol}">${anyFilled?rowTotal.toFixed(1):'—'}</strong>
        </td>
        <td id="sgrade-${sid}" style="text-align:center">
          ${gi?`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`:'<span style="color:var(--muted);font-size:.75rem">—</span>'}
        </td>
        <td style="text-align:center">
          <button class="save-row-btn" id="savebtn-${sid}"
            onclick="saveBlueprintRow(${sid},${markId},'${paper.id}',${effMax},${effPass})">
            <i class="fas fa-check"></i> Save
          </button>
        </td>
      </tr>`;
    });
    html+='</tbody></table></div>';
    panel.innerHTML=html;
    return;
  }

  // ── MODE 3: Per-question entry (allQs has items) ──
  let secHeaderCells='';
  sections.forEach(sec=>{
    const qc=(sec.questions||[]).length;
    if(!qc) return;
    secHeaderCells+=`<th colspan="${qc}" style="text-align:center;background:rgba(0,198,174,.06);border-left:1px solid rgba(0,198,174,.15)">
      <span style="color:var(--teal);font-size:.72rem;font-weight:700">${sec.section_name}</span>
      <span style="color:var(--muted);font-weight:400;font-size:.65rem;margin-left:6px">max ${sec.section_max}</span>
    </th>`;
  });
  let qHeaderCells='';
  allQs.forEach(q=>{
    qHeaderCells+=`<th style="text-align:center;white-space:nowrap;min-width:60px;border-left:1px solid rgba(255,255,255,.04)">
      Q${q.q_no}<br><span style="font-weight:400;color:var(--muted);font-size:.6rem">/${q.q_marks}</span>
    </th>`;
  });

  html+=`<div class="sec-table-wrap"><table class="sec-table">
    <thead>
      <tr class="sec-header-row">
        <th class="name-th" rowspan="2" style="min-width:30px">#</th>
        <th class="name-th" rowspan="2" style="min-width:75px">Roll No.</th>
        <th class="name-th" rowspan="2" style="min-width:150px">Student</th>
        ${secHeaderCells}
        <th rowspan="2" style="min-width:70px;text-align:center">Total<br><span style="font-size:.6rem;font-weight:400;color:var(--muted)">/${effMax}</span></th>
        <th rowspan="2" style="min-width:60px;text-align:center">Grade</th>
        <th rowspan="2" style="min-width:70px;text-align:center">Action</th>
      </tr>
      <tr>${qHeaderCells}</tr>
    </thead><tbody>`;

  // Section picker for mode 3 (prepend above table)
  const _m3picker=_renderSecPickerBar(students);
  if(_m3picker) html=_m3picker+html;

  _applySecFilter(students).forEach((stu,idx)=>{
    const sid=stu.student_id,markId=stu.mark_id;
    let rowTotal=0,anyFilled=false;
    const cells=allQs.map(q=>{
      const savedVal=(existingQ&&existingQ[sid]&&existingQ[sid][q.qid]!==undefined)?existingQ[sid][q.qid]:'';
      if(savedVal!==''&&savedVal!==null){rowTotal+=parseFloat(savedVal)||0;anyFilled=true;}
      return `<td style="border-left:1px solid rgba(255,255,255,.04)">
        <input class="q-input" type="number" id="qi_${sid}_${q.qid}"
          value="${savedVal!==''&&savedVal!==null?savedVal:''}"
          min="0" max="${q.q_marks}" step="0.5" placeholder="—"
          data-sid="${sid}" data-bid="${q.bid}" data-qid="${q.qid}"
          data-smax="${q.q_marks}" data-markid="${markId}"
          data-examid="${_currentExamId}" data-paperid="${paper.id}"
          data-effmax="${effMax}" data-effpass="${effPass}"
          oninput="updateQRowTotal(${sid})">
      </td>`;
    }).join('');
    const pct=anyFilled&&effMax>0?Math.round(rowTotal/effMax*100):null;
    const gi=pct!==null?getGradeInfo(pct):null;
    const totalCol=anyFilled?(rowTotal>=effPass?'var(--teal)':'var(--red)'):'var(--muted)';
    html+=`<tr>
      <td class="name-td" style="color:var(--muted)">${idx+1}</td>
      <td class="name-td" style="color:var(--muted);font-size:.76rem">${stu.roll_number||'—'}</td>
      <td class="name-td"><strong style="color:var(--white)">${stu.full_name}</strong></td>
      ${cells}
      <td id="stotal-${sid}" style="text-align:center">
        <strong style="color:${totalCol}">${anyFilled?rowTotal.toFixed(1):'—'}</strong>
      </td>
      <td id="sgrade-${sid}" style="text-align:center">
        ${gi?`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`:'<span style="color:var(--muted);font-size:.75rem">—</span>'}
      </td>
      <td style="text-align:center">
        <button class="save-row-btn" id="savebtn-${sid}"
          onclick="saveRowMarks(${sid},${markId},'${paper.id}',${effMax},${effPass})">
          <i class="fas fa-check"></i> Save
        </button>
      </td>
    </tr>`;
  });
  html+='</tbody></table></div>';
  panel.innerHTML=html;
}

/* ── Virtual mode helpers ───────────────────────────────────────── */
function updateVirtualRow(sid,effMax,effPass){
  const inp=document.getElementById(`sv_${sid}`);
  if(!inp||inp.value===''){
    // Clear error state when field emptied
    inp && inp.classList.remove('error');
    return;
  }
  const val=parseFloat(inp.value);
  if(isNaN(val)||val<0||(effMax>0&&val>effMax)){
    inp.classList.add('error');
    return; // Don't update preview on invalid input
  }
  inp.classList.remove('error');
  const pct=effMax>0?Math.round(val/effMax*100):null;
  const gi=pct!==null?getGradeInfo(pct):null;
  const pctEl=document.getElementById(`svpct-${sid}`);
  const gEl=document.getElementById(`svgrade-${sid}`);
  if(pctEl) pctEl.innerHTML=pct!==null?`<span style="font-weight:700;color:${pct>=40?'var(--text)':'var(--red)'}">${pct}%</span>`:'—';
  if(gEl&&gi) gEl.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`;
}

function toggleVirtualAbsent(sid,markId,paperId,effMax,effPass){
  const btn=document.getElementById(`absv-${sid}`);
  btn.classList.toggle('marked');
  const isAbs=btn.classList.contains('marked');
  btn.querySelector('i').className='fas fa-'+(isAbs?'xmark':'check');
  btn.title=isAbs?'Mark Present':'Mark Absent';
  const inp=document.getElementById(`sv_${sid}`);
  if(inp){
    inp.disabled=isAbs;
    inp.classList.remove('error');
    if(isAbs){inp.value='';inp.style.opacity='.4';}
    else{inp.style.opacity='1';}
  }
  if(isAbs) saveVirtualMark(sid,markId,paperId,effMax,effPass);
}

async function saveVirtualMark(sid,markId,paperId,effMax,effPass){
  const btn=document.getElementById(`savebtn-${sid}`);
  const absBtn=document.getElementById(`absv-${sid}`);
  const inp=document.getElementById(`sv_${sid}`);
  const isAbsent=absBtn&&absBtn.classList.contains('marked')?1:0;

  if(!isAbsent && inp && inp.value!==''){
    const v = validateMarkInput(inp, effMax, 'Marks');
    if(!v.ok){if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Save';}return;}
  } else if(inp){inp.classList.remove('error');}

  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';}
  const val=isAbsent?null:(inp&&inp.value!==''?parseFloat(inp.value):null);

  const fd=new FormData();
  fd.append('ajax_save_section_mark','1');
  fd.append('exam_id',_currentExamId);fd.append('paper_id',paperId);
  fd.append('student_id',sid);fd.append('blueprint_id','0');
  fd.append('question_id','');fd.append('mark_id',markId);
  fd.append('section_max',effMax);fd.append('is_absent',isAbsent);
  if(val!==null) fd.append('obtained_marks',val);
  try{
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(data.ok){
      if(inp){inp.classList.add('saved');setTimeout(()=>inp.classList.remove('saved'),1500);}
      const t=data.total!==null?parseFloat(data.total):val;
      const pct=t!==null&&effMax>0?Math.round(t/effMax*100):null;
      const gi=pct!==null?getGradeInfo(pct):null;
      const pctEl=document.getElementById(`svpct-${sid}`);
      const gEl=document.getElementById(`svgrade-${sid}`);
      if(pctEl) pctEl.innerHTML=isAbsent?'—':(pct!==null?`<span style="font-weight:700;color:${pct>=40?'var(--text)':'var(--red)'}">${pct}%</span>`:'—');
      if(gEl) gEl.innerHTML=isAbsent?'<span style="color:var(--muted);font-size:.75rem">AB</span>':(gi?`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`:'—');
      const mRow=ALL_MARKS.find(x=>x.id===parseInt(markId));
      if(mRow){mRow.obtained=t;mRow.pct=pct;mRow.grade=gi?gi.g:'';mRow.is_pass=t!==null?(t>=effPass):null;mRow.is_absent=!!isAbsent;}
      showToast('Marks saved!');
      setTimeout(refreshViewMarks,400);
    }else showToast(data.msg||'Save failed',true);
  }catch(e){showToast('Network error',true);}
  if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check-double" style="color:var(--teal)"></i> Saved';setTimeout(()=>{btn.innerHTML='<i class="fas fa-check"></i> Save';},2500);}
}

/* ── Blueprint-only mode helpers ────────────────────────────────── */
function updateBlueprintRow(sid,effMax,effPass){
  if(!_secData) return;
  let total=0,anyFilled=false;
  _secData.sections.forEach(sec=>{
    const inp=document.getElementById(`sb_${sid}_${sec.blueprint_id}`);
    if(inp&&inp.value!==''){total+=parseFloat(inp.value)||0;anyFilled=true;}
  });
  const te=document.getElementById(`stotal-${sid}`),ge=document.getElementById(`sgrade-${sid}`);
  const col=anyFilled?(total>=effPass?'var(--teal)':'var(--red)'):'var(--muted)';
  if(te) te.innerHTML=`<strong style="color:${col}">${anyFilled?total.toFixed(1):'—'}</strong>`;
  if(ge&&anyFilled&&effMax>0){
    const gi=getGradeInfo(Math.round(total/effMax*100));
    ge.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`;
  }
}

async function saveBlueprintRow(sid,markId,paperId,effMax,effPass){
  const btn=document.getElementById(`savebtn-${sid}`);
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';}
  if(!_secData){if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Save';}return;}
  let lastTotal=null,valid=true;
  for(const sec of _secData.sections){
    const inp=document.getElementById(`sb_${sid}_${sec.blueprint_id}`);
    if(!inp) continue;
    const val=inp.value!==''?parseFloat(inp.value):null;
    if(val!==null&&sec.section_max>0&&val>sec.section_max){inp.classList.add('error');showToast(`${sec.section_name}: max is ${sec.section_max}`,true);valid=false;break;}
    inp.classList.remove('error');
    const fd=new FormData();
    fd.append('ajax_save_section_mark','1');
    fd.append('exam_id',_currentExamId);fd.append('paper_id',paperId);
    fd.append('student_id',sid);fd.append('blueprint_id',sec.blueprint_id);
    fd.append('question_id','');fd.append('mark_id',markId);
    fd.append('section_max',sec.section_max);fd.append('is_absent','0');
    if(val!==null) fd.append('obtained_marks',val);
    try{
      const res=await fetch(window.location.pathname,{method:'POST',body:fd});
      const d=await res.json();
      if(d.ok){inp.classList.add('saved');setTimeout(()=>inp.classList.remove('saved'),1500);lastTotal=d.total;}
      else{showToast(d.msg||'Save failed',true);}
    }catch(e){showToast('Network error',true);}
  }
  if(lastTotal!==null){
    const t=parseFloat(lastTotal);
    const pct=effMax>0?Math.round(t/effMax*100):null;
    const gi=pct!==null?getGradeInfo(pct):null;
    const col=t>=effPass?'var(--teal)':'var(--red)';
    const te=document.getElementById(`stotal-${sid}`);
    const ge=document.getElementById(`sgrade-${sid}`);
    if(te) te.innerHTML=`<strong style="color:${col}">${t.toFixed(1)}</strong>`;
    if(ge&&gi) ge.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`;
    const mRow=ALL_MARKS.find(x=>x.id===parseInt(markId));
    if(mRow){mRow.obtained=t;mRow.pct=pct;mRow.grade=gi?gi.g:'';mRow.is_pass=pct!==null?t>=effPass:null;}
  }
  if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check-double" style="color:var(--teal)"></i> Saved';setTimeout(()=>{btn.innerHTML='<i class="fas fa-check"></i> Save';},2500);}
  showToast('Section marks saved!');
  setTimeout(refreshViewMarks,400);
}

/* ── Per-question mode: update row total ───────────────────────── */
function updateQRowTotal(sid){
  if(!_secData) return;
  const effMax=parseFloat(_secData.paper_max)||parseFloat(_secData.exam_max)||100;
  const effPass=parseFloat(_secData.exam_pass)||40;
  let total=0,anyFilled=false;
  _secData.sections.forEach(sec=>{
    (sec.questions||[]).forEach(q=>{
      const inp=document.getElementById(`qi_${sid}_${q.question_id}`);
      if(inp&&inp.value!==''){total+=parseFloat(inp.value)||0;anyFilled=true;}
    });
  });
  const te=document.getElementById(`stotal-${sid}`),ge=document.getElementById(`sgrade-${sid}`);
  const col=anyFilled?(total>=effPass?'var(--teal)':'var(--red)'):'var(--muted)';
  if(te) te.innerHTML=`<strong style="color:${col}">${anyFilled?total.toFixed(1):'—'}</strong>`;
  if(ge&&anyFilled&&effMax>0){
    const gi=getGradeInfo(Math.round(total/effMax*100));
    ge.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`;
  }
}

async function saveRowMarks(sid,markId,paperId,effMax,effPass){
  const btn=document.getElementById(`savebtn-${sid}`);
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';}
  if(!_secData){if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Save';}return;}

  const saves=[];let valid=true;
  for(const sec of _secData.sections){
    for(const q of (sec.questions||[])){
      const inp=document.getElementById(`qi_${sid}_${q.question_id}`);
      if(!inp) continue;
      const val=inp.value!==''?parseFloat(inp.value):null;
      if(val!==null&&q.q_marks>0&&val>q.q_marks){inp.classList.add('error');showToast(`${sec.section_name}: value ${val} exceeds max ${q.q_marks}`,true);valid=false;break;}
      inp.classList.remove('error');
      saves.push({inp,bid:sec.blueprint_id,qid:q.question_id,val,smax:q.q_marks});
    }
    if(!valid) break;
  }
  if(!valid){if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Save';}return;}

  let lastTotal=null;
  for(const s of saves){
    const fd=new FormData();
    fd.append('ajax_save_section_mark','1');
    fd.append('exam_id',_currentExamId);fd.append('paper_id',paperId);
    fd.append('student_id',sid);fd.append('blueprint_id',s.bid);
    fd.append('question_id',s.qid);fd.append('mark_id',markId);
    fd.append('section_max',s.smax);fd.append('is_absent','0');
    if(s.val!==null) fd.append('obtained_marks',s.val);
    try{
      const res=await fetch(window.location.pathname,{method:'POST',body:fd});
      const data=await res.json();
      if(data.ok){
        s.inp.classList.add('saved');setTimeout(()=>s.inp.classList.remove('saved'),1500);
        if(_secData){if(!_secData.existingQ)_secData.existingQ={};if(!_secData.existingQ[sid])_secData.existingQ[sid]={};_secData.existingQ[sid][s.qid]=s.val;}
        lastTotal=data.total;
      }else showToast(data.msg||'Save failed',true);
    }catch(e){showToast('Network error',true);}
  }

  // Update totals in row
  if(lastTotal!==null){
    const t=parseFloat(lastTotal);
    const pct=effMax>0?Math.round(t/effMax*100):null;
    const gi=pct!==null?getGradeInfo(pct):null;
    const col=t>=effPass?'var(--teal)':'var(--red)';
    const te=document.getElementById(`stotal-${sid}`);
    const ge=document.getElementById(`sgrade-${sid}`);
    if(te) te.innerHTML=`<strong style="color:${col}">${t.toFixed(1)}</strong>`;
    if(ge&&gi) ge.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`;
    // Sync ALL_MARKS
    const mRow=ALL_MARKS.find(x=>x.id===parseInt(markId));
    if(mRow){mRow.obtained=t;mRow.pct=pct;mRow.grade=gi?gi.g:'';mRow.is_pass=pct!==null?t>=effPass:null;}
  }
  if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-check-double" style="color:var(--teal)"></i> Saved';setTimeout(()=>{btn.innerHTML='<i class="fas fa-check"></i> Save';},2500);}
  showToast('Marks saved for '+saves.length+' questions!');
  // Refresh view section
  setTimeout(refreshViewMarks,400);
}

/* ─── Flat (total) marks load ───────────────────────────────────── */
async function loadExamMarks(){
  const sel=document.getElementById('examSelect');
  const opt=sel.options[sel.selectedIndex];
  const examId=parseInt(sel.value);
  const mc=document.getElementById('marksContent');
  if(!examId){resetPanels();return;}
  document.getElementById('marksTitle').textContent=opt?.dataset?.title||'Loading…';
  document.getElementById('maxDisplay').textContent=opt?.dataset?.max||'—';
  document.getElementById('passDisplay').textContent=opt?.dataset?.pass||'—';

  // Refresh from DB
  try{
    const fd2=new FormData();fd2.append('ajax_get_marks','1');fd2.append('exam_id',examId);
    const r2=await fetch(window.location.pathname,{method:'POST',body:fd2});
    const d2=await r2.json();
    if(d2.ok&&Array.isArray(d2.marks)){
      d2.marks.forEach(fresh=>{const i=ALL_MARKS.findIndex(x=>x.id===fresh.id);if(i>=0)ALL_MARKS[i]=fresh;else ALL_MARKS.push(fresh);});
    }
  }catch(e){}

  const maxM=parseFloat(opt?.dataset?.max)||100;
  const passM=parseFloat(opt?.dataset?.pass)||40;
  const rows=ALL_MARKS.filter(m=>m.exam_id===examId);

  if(!rows.length){mc.innerHTML='<div class="empty-state"><i class="fas fa-users"></i><strong>No students enrolled for this exam</strong><p>Enroll students and add exam rows first.</p></div>';return;}

  let html=`<div style="overflow-x:auto"><table class="marks-table">
    <thead><tr>
      <th>#</th><th>Roll No.</th><th>Student</th>
      <th style="text-align:center">Absent</th>
      <th style="text-align:center">Marks /${maxM}</th>
      <th style="text-align:center">%</th>
      <th style="text-align:center">Grade</th>
      <th>Remarks</th>
    </tr></thead><tbody>`;

  rows.forEach((m,i)=>{
    const pct=m.pct!==null?m.pct:(m.obtained!==null?Math.round(m.obtained/maxM*100):null);
    const gi=pct!==null?getGradeInfo(pct):{g:'—',color:'var(--muted)',cls:'g-none'};
    const pctHtml=m.is_absent?'—':(pct!==null?`<span style="font-weight:700;color:${pct>=40?'var(--text)':'var(--red)'}">${pct}%</span>`:'—');
    const gradeHtml=m.is_absent?`<span style="color:var(--muted);font-size:.75rem">AB</span>`:(pct!==null?`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${gi.g}</span>`:`<span style="color:var(--muted);font-size:.75rem">—</span>`);
    html+=`<tr id="mrow-${m.id}">
      <td style="color:var(--muted)">${i+1}</td>
      <td style="color:var(--muted);font-size:.76rem">${m.roll_number||'—'}</td>
      <td><strong style="color:var(--white)">${m.full_name}</strong></td>
      <td style="text-align:center">
        <button class="absent-toggle${m.is_absent?' marked':''}" onclick="toggleAbsent(this,${m.id},${maxM},${passM})" title="${m.is_absent?'Mark Present':'Mark Absent'}">
          <i class="fas fa-${m.is_absent?'xmark':'check'}"></i>
        </button>
      </td>
      <td style="text-align:center">
        <input class="marks-input" id="inp-${m.id}" type="number"
          value="${m.obtained!==null&&!m.is_absent?m.obtained:''}"
          min="0" max="${maxM}" step="0.5"
          ${m.is_absent?'disabled placeholder="AB" style="color:var(--red)"':''}
          onchange="saveMark(${m.id},${maxM},${passM})">
      </td>
      <td style="text-align:center" id="pct-${m.id}">${pctHtml}</td>
      <td style="text-align:center" id="grade-${m.id}">${gradeHtml}</td>
      <td><input class="marks-input" id="rmk-${m.id}" type="text" value="${m.remarks||''}" placeholder="Remark…" style="width:110px;text-align:left;font-size:.76rem" onchange="saveMark(${m.id},${maxM},${passM})"></td>
    </tr>`;
  });
  html+='</tbody></table></div>';
  mc.innerHTML=html;

  // Summary bar
  const bar=document.getElementById('marksSummaryBar');
  const present=rows.filter(m=>!m.is_absent&&m.obtained!==null);
  const avg=present.length?Math.round(present.reduce((a,m)=>a+(m.obtained||0),0)/present.length*10)/10:0;
  const high=present.length?Math.max(...present.map(m=>m.obtained||0)):0;
  const absent=rows.filter(m=>m.is_absent).length;
  const pass=rows.filter(m=>!m.is_absent&&m.is_pass).length;
  bar.innerHTML=`<span>Avg: <strong>${avg}</strong></span><span>High: <strong style="color:var(--green)">${high}</strong></span><span>Absent: <strong style="color:var(--red)">${absent}</strong></span><span>Pass: <strong style="color:var(--teal)">${pass}</strong></span>`;
  bar.style.display='flex';
}

/* ─── Save flat mark ────────────────────────────────────────────── */
function validateMarkInput(inp, maxM, label) {
  if (!inp || inp.disabled) return {ok:true, val:null};
  const raw = inp.value.trim();
  if (raw === '') return {ok:true, val:null};
  const val = parseFloat(raw);
  if (isNaN(val)) {
    inp.classList.add('error');
    showToast(`${label||'Marks'}: must be a number.`, true); return {ok:false};
  }
  if (val < 0) {
    inp.classList.add('error');
    showToast(`${label||'Marks'}: cannot be negative.`, true); return {ok:false};
  }
  if (maxM > 0 && val > maxM) {
    inp.classList.add('error');
    showToast(`${label||'Marks'}: ${val} exceeds maximum ${maxM}.`, true); return {ok:false};
  }
  inp.classList.remove('error');
  return {ok:true, val};
}

function saveMark(markId,maxM,passM){
  const inp=document.getElementById('inp-'+markId);
  const rmk=document.getElementById('rmk-'+markId);
  const btn=document.querySelector(`#mrow-${markId} .absent-toggle`);
  const isAbsent=btn&&btn.classList.contains('marked')?1:0;

  if (!isAbsent) {
    const v = validateMarkInput(inp, maxM, 'Marks');
    if (!v.ok) return;
  } else {
    if (inp) inp.classList.remove('error');
  }

  const obtained=isAbsent?null:(inp&&inp.value!==''?parseFloat(inp.value):null);
  const fd=new FormData();
  fd.append('ajax_save_mark','1');fd.append('mark_id',markId);fd.append('is_absent',isAbsent);
  if(obtained!==null) fd.append('obtained_marks',obtained);
  if(rmk) fd.append('remarks',rmk.value);
  fetch(window.location.pathname,{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
      if(data.ok){
        if(inp){inp.classList.add('saved');setTimeout(()=>inp.classList.remove('saved'),1500);}
        // Update max/pass from server response (authoritative)
        const serverMax = data.max || maxM;
        const pctEl=document.getElementById('pct-'+markId),gradeEl=document.getElementById('grade-'+markId);
        if(isAbsent){
          if(pctEl) pctEl.innerHTML='—';
          if(gradeEl) gradeEl.innerHTML='<span style="color:var(--muted);font-size:.75rem">AB</span>';
        } else if(data.pct!==null){
          const gi=getGradeInfo(parseFloat(data.pct));
          if(pctEl) pctEl.innerHTML=`<span style="font-weight:700;color:${data.pct>=40?'var(--text)':'var(--red)'}">${data.pct}%</span>`;
          if(gradeEl) gradeEl.innerHTML=`<span class="auto-grade ${gi.cls}" style="color:${gi.color};background:rgba(0,198,174,.08)">${data.grade}</span>`;
        }
        const m=ALL_MARKS.find(x=>x.id===markId);
        if(m){m.obtained=obtained;m.is_absent=!!isAbsent;m.grade=data.grade;m.pct=data.pct?parseFloat(data.pct):null;m.is_pass=data.is_pass;}
        showToast('Marks saved!');
        setTimeout(refreshViewMarks,400);
      } else showToast(data.msg||'Save failed',true);
    })
    .catch(()=>showToast('Network error',true));
}

function toggleAbsent(btn,markId,maxM,passM){
  btn.classList.toggle('marked');
  const isAbsent=btn.classList.contains('marked');
  btn.querySelector('i').className='fas fa-'+(isAbsent?'xmark':'check');
  btn.title=isAbsent?'Mark Present':'Mark Absent';
  const inp=document.getElementById('inp-'+markId);
  if(inp){
    if(isAbsent){inp.disabled=true;inp.placeholder='AB';inp.style.color='var(--red)';}
    else{inp.disabled=false;inp.placeholder='';inp.style.color='';}
  }
  saveMark(markId,maxM,passM);
}

function resetMarks(){
  document.getElementById('examSelect').value='';
  document.getElementById('entryModeTabs').style.display='none';
  document.getElementById('viewMarksCard').style.display='none';
  document.getElementById('paperStatusBadge').innerHTML='';
  _currentExamId=0;_secData=null;
  resetPanels();
}

/* ── View Entered Marks — Course-wide, all exams, section-aware ──── */
let _vmActiveExamTab = null;   // track which exam tab is open
let _vmCourseData    = null;   // cache last fetch

async function refreshViewMarks(){
  const examId=_currentExamId; if(!examId) return;
  const vc=document.getElementById('viewMarksContent');
  vc.innerHTML=`<div class="empty-state" style="padding:28px"><i class="fas fa-spinner fa-spin" style="color:var(--teal)"></i><p style="margin-top:8px;color:var(--muted);font-size:.82rem">Loading course marks…</p></div>`;
  try{
    const fd=new FormData();
    fd.append('ajax_course_marks','1');
    fd.append('exam_id',examId);
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(!data.ok){
      vc.innerHTML=`<div class="empty-state" style="padding:20px"><i class="fas fa-inbox"></i><strong>${data.msg||'No marks data found'}</strong></div>`;
      return;
    }
    _vmCourseData=data;
    // default open tab = anchor exam (the one selected in dropdown)
    const anchor=data.exams.find(e=>e.is_anchor)||data.exams[0];
    _vmActiveExamTab=anchor?.exam_id||null;
    _renderViewMarks();
  }catch(e){
    vc.innerHTML='<div class="empty-state" style="padding:20px"><i class="fas fa-wifi" style="color:var(--red)"></i><strong>Network error</strong></div>';
  }
}

function _vmSwitchTab(examId){
  _vmActiveExamTab=examId;
  _renderViewMarks();
}

function _vmSwitchSection(examId, section){
  if(!_vmCourseData) return;
  const ex=_vmCourseData.exams.find(e=>e.exam_id===examId);
  if(!ex) return;
  ex._activeSection=section;
  _renderExamTable(examId);
}

function _renderViewMarks(){
  if(!_vmCourseData) return;
  const vc=document.getElementById('viewMarksContent');
  const {exams}=_vmCourseData;

  /* ── type label → color map (works on light bg) ── */
  const typeColors={
    mid_term :'#1D4ED8', final     :'#0F766E', assignment:'#16A34A',
    quiz     :'#7C3AED', practical :'#D97706', internal  :'#0891B2',
    unit_test:'#0369A1'
  };
  const typeBg={
    mid_term :'rgba(29,78,216,.08)',  final    :'rgba(15,118,110,.08)',
    assignment:'rgba(22,163,74,.08)', quiz     :'rgba(124,58,237,.08)',
    practical:'rgba(217,119,6,.09)', internal :'rgba(8,145,178,.08)',
    unit_test:'rgba(3,105,161,.08)'
  };

  /* ── Exam tab bar ── */
  let tabsHtml=`<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid var(--border)">`;
  exams.forEach(ex=>{
    const isActive=(ex.exam_id===_vmActiveExamTab);
    const tc=typeColors[ex.exam_type]||'#475569';
    const tb=typeBg[ex.exam_type]||'rgba(71,85,105,.07)';
    const dateStr=ex.exam_date?new Date(ex.exam_date).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'TBD';
    tabsHtml+=`
      <button onclick="_vmSwitchTab(${ex.exam_id})" style="
        display:flex;flex-direction:column;align-items:flex-start;
        padding:10px 14px;border-radius:10px;cursor:pointer;text-align:left;
        min-width:130px;max-width:200px;position:relative;
        border:2px solid ${isActive?tc:'#E2E8F0'};
        background:${isActive?tb:'#F8FAFC'};
        box-shadow:${isActive?`0 2px 12px ${tc}22`:'0 1px 3px rgba(0,0,0,.04)'};
        transition:all .17s">
        ${isActive?`<span style="position:absolute;top:-2px;left:10px;right:10px;height:3px;background:${tc};border-radius:0 0 4px 4px"></span>`:''}
        <span style="font-size:.62rem;font-weight:800;color:${tc};text-transform:uppercase;letter-spacing:.1em;padding:2px 7px;border-radius:4px;background:${tb};border:1px solid ${tc}33;margin-bottom:5px">
          ${(ex.exam_type||'exam').replace(/_/g,' ')}
        </span>
        <span style="font-size:.82rem;font-weight:700;color:${isActive?tc:'#0F172A'};line-height:1.3;word-break:break-word">${ex.exam_title}</span>
        <span style="font-size:.65rem;color:#64748B;margin-top:3px">${dateStr}</span>
        <div style="display:flex;gap:6px;margin-top:7px;flex-wrap:wrap">
          <span style="font-size:.64rem;padding:2px 7px;border-radius:4px;background:rgba(22,163,74,.1);color:#15803D;font-weight:700;border:1px solid rgba(22,163,74,.2)">${ex.stats.pass} Pass</span>
          <span style="font-size:.64rem;padding:2px 7px;border-radius:4px;background:rgba(220,38,38,.09);color:#DC2626;font-weight:700;border:1px solid rgba(220,38,38,.18)">${ex.stats.fail} Fail</span>
          ${ex.stats.absent>0?`<span style="font-size:.64rem;padding:2px 7px;border-radius:4px;background:rgba(217,119,6,.09);color:#B45309;font-weight:700;border:1px solid rgba(217,119,6,.2)">${ex.stats.absent} AB</span>`:''}
        </div>
      </button>`;
  });
  tabsHtml+=`</div>`;

  vc.innerHTML=tabsHtml+`<div id="vmExamContent"></div>`;
  _renderExamTable(_vmActiveExamTab);
}

function _renderExamTable(examId){
  const cont=document.getElementById('vmExamContent');
  if(!cont||!_vmCourseData) return;
  const ex=_vmCourseData.exams.find(e=>e.exam_id===examId);
  if(!ex){ cont.innerHTML=''; return; }

  const maxM=ex.max_marks, passM=ex.pass_marks;
  const typeColors={
    mid_term:'#1D4ED8',final:'#0F766E',assignment:'#16A34A',
    quiz:'#7C3AED',practical:'#D97706',internal:'#0891B2',unit_test:'#0369A1'
  };
  const typeBg={
    mid_term:'rgba(29,78,216,.08)',final:'rgba(15,118,110,.08)',
    assignment:'rgba(22,163,74,.08)',quiz:'rgba(124,58,237,.08)',
    practical:'rgba(217,119,6,.09)',internal:'rgba(8,145,178,.08)',unit_test:'rgba(3,105,161,.08)'
  };
  const tc=typeColors[ex.exam_type]||'#475569';
  const tb=typeBg[ex.exam_type]||'rgba(71,85,105,.07)';

  /* ── Stats strip (light bg) ── */
  const s=ex.stats;
  const statCell=(val,label,color)=>`
    <div style="text-align:center;padding:12px 16px;border-right:1px solid #E2E8F0;flex:1;min-width:70px">
      <div style="font-size:1.05rem;font-weight:800;color:${color};font-family:var(--mono)">${val!==null?val:'—'}</div>
      <div style="font-size:.58rem;color:#64748B;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;font-weight:600">${label}</div>
    </div>`;

  let html=`
  <div style="display:flex;flex-wrap:wrap;margin-bottom:18px;background:#F0FDFA;border-radius:10px;border:1px solid rgba(15,118,110,.18);overflow:hidden">
    ${statCell(s.total,'Total','#0F172A')}
    ${statCell(s.pass,'Pass','#16A34A')}
    ${statCell(s.fail,'Fail','#DC2626')}
    ${statCell(s.absent,'Absent','#D97706')}
    ${statCell(s.avg_obt,'Avg Marks','#0F766E')}
    ${statCell(s.avg_pct!==null?s.avg_pct+'%':null,'Avg %','#0F766E')}
    <div style="text-align:center;padding:12px 16px;flex:1;min-width:70px">
      <div style="font-size:1.05rem;font-weight:800;color:#16A34A;font-family:var(--mono)">${s.high!==null?s.high:'—'}</div>
      <div style="font-size:.58rem;color:#64748B;text-transform:uppercase;letter-spacing:.08em;margin-top:3px;font-weight:600">Highest</div>
    </div>
  </div>`;

  /* ── Exam meta pill row ── */
  const dateStr=ex.exam_date?new Date(ex.exam_date).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'TBD';
  html+=`<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:${tb};border-radius:8px;border:1px solid ${tc}22">
    <span style="font-size:.68rem;font-weight:800;color:${tc};text-transform:uppercase;letter-spacing:.1em;padding:3px 10px;border-radius:5px;background:#fff;border:1px solid ${tc}33">${(ex.exam_type||'').replace(/_/g,' ')}</span>
    <span style="font-size:.84rem;font-weight:700;color:#0F172A">${ex.exam_title}</span>
    <span style="font-size:.75rem;color:#64748B">${ex.course_name} (${ex.course_code})</span>
    <span style="font-size:.75rem;color:#94A3B8">·</span>
    <span style="font-size:.75rem;color:#475569">Max: <strong style="color:#0F172A">${maxM}</strong></span>
    <span style="font-size:.75rem;color:#475569">Pass: <strong style="color:#D97706">${passM}</strong></span>
    <span style="font-size:.75rem;color:#94A3B8">·</span>
    <span style="font-size:.75rem;color:#475569">${dateStr}</span>
  </div>`;

  /* ── Section filter pills (light bg) ── */
  const sections=[...new Set(ex.marks.map(m=>(m.section||'').trim()).filter(Boolean))].sort();
  const activeSec=ex._activeSection||null;
  if(sections.length>1){
    const visCount=activeSec?ex.marks.filter(m=>(m.section||'').trim()===activeSec).length:ex.marks.length;
    html+=`<div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;background:rgba(29,78,216,.05);border:1px solid rgba(29,78,216,.18);border-radius:9px;padding:10px 14px;margin-bottom:14px">
      <i class="fas fa-layer-group" style="color:#1D4ED8;flex-shrink:0;font-size:.8rem"></i>
      <span style="font-size:.77rem;font-weight:600;color:#0F172A">Section:</span>
      <button onclick="_vmSwitchSection(${examId},null)" style="padding:4px 12px;border-radius:6px;cursor:pointer;font-size:.76rem;font-weight:700;border:1.5px solid ${!activeSec?'#0F766E':'#CBD5E1'};background:${!activeSec?'rgba(15,118,110,.1)':'#fff'};color:${!activeSec?'#0F766E':'#475569'}">All</button>`;
    sections.forEach(sec=>{
      const isA=(activeSec===sec);
      const cnt=ex.marks.filter(m=>(m.section||'').trim()===sec).length;
      html+=`<button onclick="_vmSwitchSection(${examId},'${sec}')" style="padding:4px 14px;border-radius:6px;cursor:pointer;font-size:.78rem;font-weight:700;border:1.5px solid ${isA?'#0F766E':'#CBD5E1'};background:${isA?'rgba(15,118,110,.1)':'#fff'};color:${isA?'#0F766E':'#0F172A'}"><i class='fas fa-users' style='font-size:.64rem;margin-right:3px;color:#1D4ED8'></i>Sec ${sec} <span style='font-size:.62rem;color:#64748B;margin-left:2px'>(${cnt})</span></button>`;
    });
    html+=`<span style="margin-left:auto;font-size:.7rem;color:#64748B">Showing <strong style="color:#0F172A">${visCount}</strong> student${visCount!==1?'s':''}</span></div>`;
  }

  /* ── Table ── */
  let rows=ex.marks;
  if(activeSec) rows=rows.filter(m=>(m.section||'').trim()===activeSec);

  if(!rows.length){
    html+=`<div class="empty-state" style="padding:28px"><i class="fas fa-inbox"></i><strong>No marks entered yet for this exam</strong></div>`;
    cont.innerHTML=html; return;
  }

  html+=`<div style="overflow-x:auto"><table class="marks-table"><thead><tr>
    <th>#</th>
    ${sections.length>1?'<th>Section</th>':''}
    <th>Roll No.</th>
    <th>Student</th>
    <th style="text-align:center">Marks /${maxM}</th>
    <th style="text-align:center">Percentage</th>
    <th style="text-align:center">Grade</th>
    <th style="text-align:center">Result</th>
  </tr></thead><tbody>`;

  rows.forEach((m,i)=>{
    const pct=m.pct!==null?m.pct:(m.obtained!==null&&maxM>0?Math.round(m.obtained/maxM*100):null);
    const gi=pct!==null?getGradeInfo(pct):null;
    /* result pill — light-bg friendly */
    const resultHtml=m.is_absent
      ?'<span class="status-pill" style="background:#F1F5F9;color:#64748B;border:1px solid #E2E8F0">Absent</span>'
      :(m.is_pass===true
        ?'<span class="status-pill pill-pass"><i class="fas fa-circle" style="font-size:.45rem"></i> Pass</span>'
        :(m.is_pass===false
          ?'<span class="status-pill pill-fail"><i class="fas fa-circle" style="font-size:.45rem"></i> Fail</span>'
          :'<span class="status-pill pill-pending">Pending</span>'));
    const pctBar=pct!==null
      ?`<div style="display:flex;align-items:center;gap:8px">
          <div class="progress-bar" style="width:80px;background:#E2E8F0"><div class="progress-fill" style="width:${Math.min(pct,100)}%;background:${pct>=40?'#0F766E':'#DC2626'}"></div></div>
          <span style="font-weight:700;font-size:.8rem;color:${pct>=40?'#0F172A':'#DC2626'}">${pct}%</span>
        </div>`:'<span style="color:#94A3B8">—</span>';
    const marksVal=m.is_absent
      ?'<span style="color:#DC2626;font-weight:700">AB</span>'
      :(m.obtained!==null?`<strong style="color:#0F172A">${m.obtained}</strong>`:'<span style="color:#94A3B8">—</span>');
    html+=`<tr>
      <td style="color:#94A3B8;font-size:.75rem">${i+1}</td>
      ${sections.length>1?`<td style="text-align:center"><span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:4px;background:rgba(29,78,216,.09);color:#1D4ED8;border:1px solid rgba(29,78,216,.15)">${m.section||'—'}</span></td>`:''}
      <td style="color:#64748B;font-size:.76rem;font-family:var(--mono)">${m.roll_number||'—'}</td>
      <td><strong style="color:#0F172A">${m.full_name}</strong></td>
      <td style="text-align:center">${marksVal}</td>
      <td style="text-align:center">${pctBar}</td>
      <td style="text-align:center">${gi?`<span class="grade-badge ${gi.cls}" style="color:${gi.color}">${gi.g}</span>`:'<span class="grade-badge g-none">—</span>'}</td>
      <td style="text-align:center">${resultHtml}</td>
    </tr>`;
  });
  html+=`</tbody></table></div>`;
  cont.innerHTML=html;
}

/* ══════════════════════════════════════════════════════════════════
   GRADE MAINTENANCE
══════════════════════════════════════════════════════════════════ */
async function loadGradeSummary(){
  const examId=document.getElementById('gmExamFilter').value||'';
  const courseId=document.getElementById('gmCourseFilter').value||'';
  const gc=document.getElementById('gmContent');
  gc.innerHTML='<div class="empty-state"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i><strong>Loading…</strong></div>';
  document.getElementById('gmStatStrip').style.display='none';

  const fd=new FormData();
  fd.append('ajax_grade_summary','1');
  if(examId)  fd.append('filter_exam',examId);
  if(courseId) fd.append('filter_course',courseId);

  try{
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(!data.ok||!data.students.length){
      gc.innerHTML='<div class="empty-state"><i class="fas fa-inbox"></i><strong>No grade records found</strong><p>Enter marks first using the Marks Entry tab.</p></div>';
      return;
    }
    renderGradeTable(data.students, true);
    _gradeData=data.students; // full set for search/export
    // Stats
    const s=data.students;
    // Cap each student's pct_avg at 100 defensively
    const validPcts=s.filter(x=>x.pct_avg!==null).map(x=>Math.min(100,x.pct_avg||0));
    const avgPct=validPcts.length?Math.round(validPcts.reduce((a,v)=>a+v,0)/validPcts.length):0;
    const cgpaStudents=s.filter(x=>x.cgpa!==null);
    const avgCgpa=cgpaStudents.length?Math.round(cgpaStudents.reduce((a,x)=>a+(x.cgpa||0),0)/cgpaStudents.length*100)/100:null;
    // Pass rate: students with at least one exam, all their exams pass
    const studentsWithMarks=s.filter(x=>x.exams.length>0);
    const totalPass=s.reduce((a,x)=>a+x.pass_count,0);
    const totalExamCount=s.reduce((a,x)=>a+x.exams.length,0);
    const passRate=totalExamCount?Math.round(totalPass/totalExamCount*100):0;
    const totalFail=s.reduce((a,x)=>a+x.fail_count,0);
    document.getElementById('gmStatStudents').textContent=s.length;
    document.getElementById('gmStatPass').textContent=passRate+'%';
    document.getElementById('gmStatFail').textContent=totalFail;
    document.getElementById('gmStatPct').textContent=avgPct?avgPct+'%':'—';
    document.getElementById('gmStatCgpa').textContent=avgCgpa!==null?avgCgpa:'—';
    document.getElementById('gmStatStrip').style.display='flex';
    document.getElementById('gmRowCount').textContent=s.length+' student'+(s.length!==1?'s':'');
    renderGradeDistribution(s);
  }catch(e){
    gc.innerHTML='<div class="empty-state"><i class="fas fa-wifi" style="color:var(--red)"></i><strong>Network error</strong></div>';
  }
}

function renderGradeTable(students, updateCache){
  if(updateCache) _gradeData=students;
  const gc=document.getElementById('gmContent');
  let html=`<div style="overflow-x:auto"><table class="grade-table">
    <thead><tr>
      <th>#</th><th>Roll No.</th><th>Student</th>
      <th style="text-align:center">Exams</th>
      <th style="text-align:center">Pass / Fail</th>
      <th style="text-align:center">Avg %</th>
      <th style="text-align:center">CGPA</th>
      <th style="text-align:center">Details</th>
    </tr></thead><tbody>`;

  students.forEach((s,idx)=>{
    const avgPct=s.pct_avg;
    const pctBar=avgPct!==null?`<div style="display:flex;align-items:center;gap:8px"><div class="progress-bar" style="width:70px"><div class="progress-fill" style="width:${avgPct}%;background:${avgPct>=40?'var(--teal)':'var(--red)'}"></div></div><span style="font-weight:700;font-size:.82rem;color:${avgPct>=40?'var(--text)':'var(--red)'}">${avgPct}%</span></div>`:'—';
    const cgpaColor=s.cgpa!==null?(s.cgpa>=7?'var(--teal)':(s.cgpa>=5?'var(--amber)':'var(--red)')):'var(--muted)';

    html+=`<tr>
      <td style="color:var(--muted)">${idx+1}</td>
      <td style="color:var(--muted);font-size:.76rem">${s.roll_number||'—'}</td>
      <td><strong style="color:var(--white)">${s.full_name}</strong></td>
      <td style="text-align:center"><strong style="color:var(--white)">${s.exams.length}</strong></td>
      <td style="text-align:center">
        <span style="color:var(--green);font-weight:700">${s.pass_count}</span>
        <span style="color:var(--muted)"> / </span>
        <span style="color:var(--red);font-weight:700">${s.fail_count}</span>
      </td>
      <td>${pctBar}</td>
      <td style="text-align:center"><strong style="color:${cgpaColor};font-size:.95rem">${s.cgpa!==null?s.cgpa:'—'}</strong></td>
      <td style="text-align:center">
        <button class="student-expand-btn" onclick="toggleStudentDetail(${s.student_id})" id="expbtn-${s.student_id}">
          <i class="fas fa-chevron-down"></i> View
        </button>
      </td>
    </tr>
    <tr class="student-detail-row" id="detail-${s.student_id}">
      <td colspan="8" style="padding:0">
        ${buildStudentDetail(s)}
      </td>
    </tr>`;
  });
  html+='</tbody></table></div>';
  gc.innerHTML=html;
}

function buildStudentDetail(s){
  if(!s.exams.length) return '<div style="padding:16px;color:var(--text-muted);text-align:center;font-size:.82rem">No exam records.</div>';
  const typeMap={unit_test:'chip-unit',mid_term:'chip-mid',final:'chip-final',practical:'chip-prac',assignment:'chip-unit',project:'chip-unit',viva:'chip-unit'};
  let rows='';
  s.exams.forEach(ex=>{
    const tc=typeMap[ex.exam_type]||'chip-unit';
    const pct=ex.percentage!==null?ex.percentage:(ex.obtained!==null&&ex.max_marks>0?Math.round(ex.obtained/ex.max_marks*100):null);
    const cappedPct=pct!==null?Math.min(pct,100):null;
    const gi=pct!==null?getGradeInfo(pct):null;
    const pctBar=pct!==null?`<div style="display:flex;align-items:center;gap:6px"><div class="progress-bar" style="width:60px"><div class="progress-fill" style="width:${cappedPct}%;background:${pct>=40?'var(--teal)':'var(--red)'}"></div></div><span style="font-size:.78rem;font-weight:700;color:${pct>=40?'var(--teal-dark)':'var(--red)'}">${pct}%</span></div>`:'—';
    const res=ex.is_absent
      ?'<span class="status-pill" style="background:#F1F5F9;color:var(--text-muted)">Absent</span>'
      :(ex.is_pass===true?'<span class="status-pill pill-pass">Pass</span>'
      :(ex.is_pass===false?'<span class="status-pill pill-fail">Fail</span>'
      :(ex.obtained!==null?'<span class="status-pill pill-pending">—</span>':'<span class="status-pill pill-pending">Not Entered</span>')));
    rows+=`<tr class="detail-inner-row">
      <td style="color:var(--text-muted);font-size:.76rem;padding:9px 12px;white-space:nowrap">${ex.exam_date?new Date(ex.exam_date).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'—'}</td>
      <td style="padding:9px 12px">
        <div style="font-size:.84rem;font-weight:700;color:var(--text)">${ex.exam_title}</div>
        <div style="font-size:.71rem;color:var(--text-muted);margin-top:2px">${ex.course_name} · ${ex.course_code}</div>
      </td>
      <td style="padding:9px 12px"><span class="type-chip ${tc}">${ex.exam_type.replace('_',' ')}</span></td>
      <td style="text-align:center;padding:9px 12px">
        <span style="font-weight:700;color:var(--text)">${ex.is_absent?'—':(ex.obtained!==null?ex.obtained:'—')}</span>
        <span style="color:var(--text-muted);font-size:.71rem"> /${ex.max_marks}</span>
      </td>
      <td style="padding:9px 12px">${pctBar}</td>
      <td style="text-align:center;padding:9px 12px">${gi?`<span class="grade-badge ${gi.cls}" style="color:${gi.color}">${gi.g}</span>`:'<span class="grade-badge g-none">—</span>'}</td>
      <td style="text-align:center;padding:9px 12px">${res}</td>
    </tr>`;
  });
  return `<div class="student-detail-inner">
    <div class="student-detail-header">
      <i class="fas fa-graduation-cap" style="color:var(--teal);font-size:.8rem"></i>
      Exam Details — <strong>${s.full_name}</strong>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr class="detail-thead-row">
        <th class="detail-th">Date</th>
        <th class="detail-th">Exam</th>
        <th class="detail-th">Type</th>
        <th class="detail-th" style="text-align:center">Marks</th>
        <th class="detail-th">%</th>
        <th class="detail-th" style="text-align:center">Grade</th>
        <th class="detail-th" style="text-align:center">Result</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>
    </div>
  </div>`;
}

function toggleStudentDetail(sid){
  const row=document.getElementById('detail-'+sid);
  const btn=document.getElementById('expbtn-'+sid);
  const isOpen=row.classList.contains('open');
  row.classList.toggle('open',!isOpen);
  if(btn) btn.innerHTML=isOpen?'<i class="fas fa-chevron-down"></i> View':'<i class="fas fa-chevron-up"></i> Hide';
}

/* ─── Clock ─────────────────────────────────────────────────────── */
// topbarDate removed

/* ── Sidebar toggle (mobile) ────────────────────────────────────── */
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=800 && sb.classList.contains('open') &&
     !sb.contains(e.target) && !document.getElementById('menuToggle').contains(e.target)){
    sb.classList.remove('open');
  }
});

/* ── Logout ─────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res=await fetch('../auth/auth_handler.php',{
      method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'
    });
    const data=await res.json();
    if(data.redirect) window.location.href=data.redirect;
  } catch{ window.location.href='../login.php'; }
}

/* ─── Grade Maintenance: search/filter ──────────────────────────── */
let _gradeData=[];

function filterGradeTable(){
  const q=(document.getElementById('gmSearch').value||'').toLowerCase().trim();
  if(!_gradeData.length) return;
  const filtered=q?_gradeData.filter(s=>
    (s.full_name||'').toLowerCase().includes(q)||
    (s.roll_number||'').toLowerCase().includes(q)
  ):_gradeData;
  renderGradeTable(filtered, false);
  document.getElementById('gmRowCount').textContent=filtered.length+' student'+(filtered.length!==1?'s':'');
}

/* ─── Grade Distribution chart ───────────────────────────────────── */
function renderGradeDistribution(students){
  const dist={};
  const gradeOrder=['A+','A','B','B-','C','D','F'];
  const gradeColors={
    'A+':'#00d4bb','A':'#10b981','B':'#3b82f6',
    'B-':'#6eb5f5','C':'#f59e0b','D':'#ef4444','F':'#dc2626'
  };
  const gradeBg={
    'A+':'rgba(0,212,187,.12)','A':'rgba(16,185,129,.12)',
    'B':'rgba(59,130,246,.12)','B-':'rgba(110,181,245,.12)',
    'C':'rgba(245,158,11,.12)','D':'rgba(239,68,68,.12)','F':'rgba(220,38,38,.12)'
  };
  gradeOrder.forEach(g=>dist[g]=0);
  students.forEach(s=>{
    s.exams.forEach(ex=>{
      const g=ex.grade;
      if(g && dist[g]!==undefined) dist[g]++;
    });
  });
  const total=Object.values(dist).reduce((a,b)=>a+b,0);
  if(!total){document.getElementById('gmDistCard').style.display='none';return;}
  document.getElementById('gmDistCard').style.display='block';
  const maxVal=Math.max(...Object.values(dist),1);

  // Build bar columns
  let barsHtml='';
  gradeOrder.forEach(g=>{
    const v=dist[g];
    const heightPct=Math.round(v/maxVal*100);
    const pctOfTotal=total?Math.round(v/total*100):0;
    const c=gradeColors[g];
    const hasData=v>0;
    barsHtml+=`
      <div class="dist-bar-col">
        <div class="dist-bar-val">${hasData?v:''}</div>
        <div class="dist-bar-track">
          <div class="dist-bar-fill"
            style="height:${hasData?Math.max(heightPct,4):0}%;background:${c};opacity:${hasData?1:.25}">
          </div>
        </div>
        <div class="dist-bar-grade" style="color:${c}">${g}</div>
      </div>`;
  });

  // Build legend tiles
  let legendHtml='';
  gradeOrder.forEach(g=>{
    const v=dist[g];
    const pctOfTotal=total?Math.round(v/total*100):0;
    const c=gradeColors[g];
    const bg=gradeBg[g];
    legendHtml+=`
      <div class="dist-legend-tile" style="${v>0?'border-color:'+c+'33;background:'+bg:''}">
        <div class="dist-legend-swatch" style="background:${c};${v>0?'box-shadow:0 0 6px '+c+'66':'opacity:.4'}"></div>
        <div class="dist-legend-grade" style="color:${v>0?c:'var(--text-muted)'}">${g}</div>
        <div style="flex:1">
          <div class="dist-legend-count" style="color:${v>0?'var(--text)':'var(--text-muted)'}">${v}</div>
          <div class="dist-legend-pct">${pctOfTotal}% of total</div>
        </div>
      </div>`;
  });

  document.getElementById('gmDistChart').innerHTML=barsHtml;
  document.getElementById('gmDistLegend').innerHTML=legendHtml;
  document.getElementById('gmDistLabel').textContent=total+' exam record'+(total!==1?'s':'');
}

/* ─── Export to CSV ──────────────────────────────────────────────── */
function exportGradeCSV(){
  if(!_gradeData.length){showToast('No data to export',true);return;}
  const rows=[['Roll No.','Student Name','Exams','Pass','Fail','Avg %','CGPA']];
  _gradeData.forEach(s=>{
    rows.push([
      s.roll_number||'',
      s.full_name,
      s.exams.length,
      s.pass_count,
      s.fail_count,
      s.pct_avg!==null?s.pct_avg+'%':'—',
      s.cgpa!==null?s.cgpa:'—',
    ]);
  });
  // Per-exam detail rows
  rows.push([]);
  rows.push(['','--- Exam Details ---']);
  rows.push(['Roll No.','Student','Exam','Type','Course','Date','Marks','Max','%','Grade','Result']);
  _gradeData.forEach(s=>{
    s.exams.forEach(ex=>{
      rows.push([
        s.roll_number||'',s.full_name,
        ex.exam_title,ex.exam_type.replace('_',' '),
        ex.course_name+' ('+ex.course_code+')',
        ex.exam_date?new Date(ex.exam_date).toLocaleDateString('en-IN'):'—',
        ex.is_absent?'Absent':(ex.obtained!==null?ex.obtained:'—'),
        ex.max_marks,
        ex.percentage!==null?ex.percentage+'%':'—',
        ex.grade||'—',
        ex.is_absent?'Absent':(ex.is_pass===true?'Pass':(ex.is_pass===false?'Fail':'Pending'))
      ]);
    });
  });
  const csv=rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob=new Blob([csv],{type:'text/csv'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;
  a.download='grade_report_'+new Date().toISOString().slice(0,10)+'.csv';
  document.body.appendChild(a);a.click();
  setTimeout(()=>{URL.revokeObjectURL(url);a.remove();},1000);
  showToast('CSV exported!');
}

/* ─── Print Report ───────────────────────────────────────────────── */
function printGradeReport(){
  if(!_gradeData.length){showToast('No data to print',true);return;}
  const date=new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  let rows='';
  _gradeData.forEach((s,i)=>{
    const cgpaColor=s.cgpa!==null?(s.cgpa>=7?'#007a6d':(s.cgpa>=5?'#c47a1e':'#b5340e')):'#666';
    rows+=`<tr>
      <td>${i+1}</td>
      <td>${s.roll_number||'—'}</td>
      <td><strong>${s.full_name}</strong></td>
      <td style="text-align:center">${s.exams.length}</td>
      <td style="text-align:center;color:#2a8c2a">${s.pass_count}</td>
      <td style="text-align:center;color:#b5340e">${s.fail_count}</td>
      <td style="text-align:center">${s.pct_avg!==null?s.pct_avg+'%':'—'}</td>
      <td style="text-align:center;font-weight:700;color:${cgpaColor}">${s.cgpa!==null?s.cgpa:'—'}</td>
    </tr>`;
  });
  const win=window.open('','_blank','width=900,height=700');
  win.document.write(`<!DOCTYPE html><html><head><title>Grade Report</title>
  <style>body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:20px}
  h2{margin:0 0 4px;font-size:16px}p{color:#555;margin:0 0 14px;font-size:11px}
  table{width:100%;border-collapse:collapse}
  th{background:#0a5c54;color:#fff;padding:7px 10px;text-align:left;font-size:11px}
  td{padding:6px 10px;border-bottom:1px solid #ddd}
  tr:nth-child(even) td{background:#f5f5f5}
  .footer{margin-top:20px;font-size:10px;color:#999;text-align:right}
  @media print{.no-print{display:none}}
  </style></head><body>
  <h2>Grade & Results Report</h2>
  <p>Generated: ${date} &nbsp;·&nbsp; Total Students: ${_gradeData.length}</p>
  <button class="no-print" onclick="window.print()" style="margin-bottom:14px;padding:6px 14px;background:#0a5c54;color:#fff;border:none;border-radius:4px;cursor:pointer">🖨 Print</button>
  <table><thead><tr>
    <th>#</th><th>Roll No.</th><th>Student</th>
    <th style="text-align:center">Exams</th>
    <th style="text-align:center">Pass</th>
    <th style="text-align:center">Fail</th>
    <th style="text-align:center">Avg %</th>
    <th style="text-align:center">CGPA</th>
  </tr></thead><tbody>${rows}</tbody></table>
  <div class="footer">EduNexus ERP · Confidential · Printed on ${date}</div>
  </body></html>`);
  win.document.close();
}

/* ─── Init ──────────────────────────────────────────────────────── */
(function(){
  const t='<?= h($activeTab) ?>';
  const btn=document.querySelector(`.tab-btn[onclick*="'${t}'"]`);
  if(btn) switchTab(t,btn);
  if(t==='grade-maintenance') loadGradeSummary();
})();

/* ── Sidebar collapse (desktop) ── */
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

/* ── Sidebar toggle (mobile) ── */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sidebarOverlay');
  const open=sb.classList.toggle('open');
  if(ov) ov.classList.toggle('active',open);
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  const menuToggle=document.getElementById('menuToggle');
  if(window.innerWidth<=800&&sb.classList.contains('open')&&
     !sb.contains(e.target)&&menuToggle&&!menuToggle.contains(e.target)){
    sb.classList.remove('open');
    const ov=document.getElementById('sidebarOverlay');
    if(ov) ov.classList.remove('active');
  }
});
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