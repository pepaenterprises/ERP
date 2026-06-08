<?php
// ─── Super Admin Dashboard ────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../includes/config.php';

// Auth guard — only super_admin allowed
$user = $_SESSION['user'] ?? null;
if (!$user || $user['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit;
}

$adminName = htmlspecialchars($user['full_name'] ?? 'Super Administrator');
$adminEmail = htmlspecialchars($user['email'] ?? 'super@erp.edu');

// ── College & Role session management ────────────────────────────────────────
// Handle AJAX: set selected college/role in session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])
    && strpos($_POST['ajax_action'], 'expense_')   !== 0   // expense_ actions handled separately below
    && strpos($_POST['ajax_action'], 'leave_')     !== 0   // leave_ actions handled separately below
    && strpos($_POST['ajax_action'], 'admission_') !== 0) { // admission_ actions handled separately below
    // ── Ensure hod_access_codes table exists ────────────────────────────
    // Called once lazily so no manual migration needed.
    function ensureHodCodesTable($db) {
        $db->exec("CREATE TABLE IF NOT EXISTS `hod_access_codes` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `college_id`  INT NOT NULL,
            `dept_id`     INT NOT NULL,
            `code`        VARCHAR(20) NOT NULL,
            `created_by`  VARCHAR(50) DEFAULT 'principal',
            `used`        TINYINT(1) DEFAULT 0,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
            `used_at`     DATETIME DEFAULT NULL,
            UNIQUE KEY `uniq_college_dept` (`college_id`,`dept_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ── Ensure departments.hod_user_id column exists (lazy migration) ───
    function ensureHodUserIdColumn($db) {
        try {
            $db->query("SELECT hod_user_id FROM departments LIMIT 1");
        } catch (Exception $e) {
            $db->exec("ALTER TABLE departments
                ADD COLUMN `hod_user_id` INT DEFAULT NULL COMMENT 'FK to users.id (faculty who is HOD)',
                ADD CONSTRAINT `fk_dept_hod` FOREIGN KEY (`hod_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL");
        }
    }

    // ── Ensure courses table has academic_year + created_by columns (lazy migration) ─
    function ensureCourseColumns($db) {
        try { $db->query("SELECT academic_year FROM courses LIMIT 1"); }
        catch (Exception $e) {
            $db->exec("ALTER TABLE `courses`
                ADD COLUMN `academic_year` varchar(10) DEFAULT '2025-26'
                    COMMENT 'e.g. 2025-26 — the academic year this course offering belongs to'
                    AFTER `semester`");
        }
        try { $db->query("SELECT created_by FROM courses LIMIT 1"); }
        catch (Exception $e) {
            $db->exec("ALTER TABLE `courses`
                ADD COLUMN `created_by` int(11) DEFAULT NULL
                    COMMENT 'FK → users.id (who created this course — usually HOD)'
                    AFTER `academic_year`");
        }
        // Backfill NULL academic_year rows
        $db->exec("UPDATE `courses` SET `academic_year`='2025-26' WHERE `academic_year` IS NULL OR `academic_year`=''");
    }

    // ── Ensure users.current_semester column exists (lazy migration) ─────
    function ensureStudentSemesterColumn($db) {
        try { $db->query("SELECT current_semester FROM users LIMIT 1"); }
        catch (Exception $e) {
            $db->exec("ALTER TABLE `users`
                ADD COLUMN `current_semester` TINYINT DEFAULT NULL
                    COMMENT 'Active semester for student (1-8); NULL = not set'
                AFTER `roll_number`");
        }
    }

    // ── Principal: generate/set HOD access code for a department ─────────
    if ($_POST['ajax_action'] === 'set_hod_code') {
        header('Content-Type: application/json');
        $college_id = (int)($_POST['college_id'] ?? 0);
        $dept_id    = (int)($_POST['dept_id']    ?? 0);
        $code       = strtoupper(trim($_POST['code'] ?? ''));
        // Only principal of that college may call this
        if (!$college_id || !$dept_id || strlen($code) < 4) {
            echo json_encode(['ok'=>false,'msg'=>'College, department and a code of at least 4 characters are required.']);
            exit;
        }
        $dbH = getDB();
        if (!$dbH) { echo json_encode(['ok'=>false,'msg'=>'DB error']); exit; }
        ensureHodCodesTable($dbH);
        // Upsert: insert or update
        $st = $dbH->prepare("INSERT INTO hod_access_codes (college_id,dept_id,code,used,used_at)
                              VALUES (?,?,?,0,NULL)
                              ON DUPLICATE KEY UPDATE code=VALUES(code),used=0,used_at=NULL,created_at=NOW()");
        $st->execute([$college_id,$dept_id,$code]);
        echo json_encode(['ok'=>true,'msg'=>'HOD access code saved successfully.']);
        exit;
    }

    // ── Principal: get all HOD codes for a college (for the management UI) ─
    if ($_POST['ajax_action'] === 'get_hod_codes') {
        header('Content-Type: application/json');
        $college_id = (int)($_POST['college_id'] ?? 0);
        if (!$college_id) { echo json_encode(['ok'=>false]); exit; }
        $dbH = getDB();
        if (!$dbH) { echo json_encode(['ok'=>false]); exit; }
        ensureHodCodesTable($dbH);
        $st = $dbH->prepare("SELECT h.dept_id, d.name AS dept_name, d.code AS dept_code,
                                     h.code, h.used, h.created_at, h.used_at
                              FROM hod_access_codes h
                              JOIN departments d ON h.dept_id=d.id
                              WHERE h.college_id=?
                              ORDER BY d.name");
        $st->execute([$college_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'codes'=>$rows]);
        exit;
    }

    // ── Principal: assign a faculty member as HOD for a department ───────
    if ($_POST['ajax_action'] === 'set_dept_hod') {
        header('Content-Type: application/json');
        $college_id  = (int)($_POST['college_id']  ?? 0);
        $dept_id     = (int)($_POST['dept_id']     ?? 0);
        $hod_user_id = (int)($_POST['hod_user_id'] ?? 0) ?: null;
        if (!$college_id || !$dept_id) {
            echo json_encode(['ok'=>false,'msg'=>'College and department are required.']);
            exit;
        }
        $dbH = getDB();
        if (!$dbH) { echo json_encode(['ok'=>false,'msg'=>'DB error']); exit; }
        ensureHodUserIdColumn($dbH);
        $hodName = '';
        if ($hod_user_id) {
            // Verify the selected user is an active faculty in this college
            $chk = $dbH->prepare('SELECT id, full_name FROM users WHERE id=? AND role="faculty" AND college_id=? AND status="active"');
            $chk->execute([$hod_user_id, $college_id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['ok'=>false,'msg'=>'Selected faculty not found or not active in this college.']);
                exit;
            }
            $hodName = $row['full_name'];
        }
        $st = $dbH->prepare('UPDATE departments SET hod_user_id=?, hod_name=? WHERE id=? AND college_id=?');
        $st->execute([$hod_user_id, $hodName, $dept_id, $college_id]);
        echo json_encode(['ok'=>true,'msg'=>'HOD updated successfully.','hod_name'=>$hodName]);
        exit;
    }

    // ── Principal: get current HOD user for all depts in a college ────────
    if ($_POST['ajax_action'] === 'get_dept_hods') {
        header('Content-Type: application/json');
        $college_id = (int)($_POST['college_id'] ?? 0);
        if (!$college_id) { echo json_encode(['ok'=>false]); exit; }
        $dbH = getDB();
        if (!$dbH) { echo json_encode(['ok'=>false]); exit; }
        ensureHodUserIdColumn($dbH);
        $st = $dbH->prepare("SELECT d.id AS dept_id, d.hod_user_id, d.hod_name,
                                     u.full_name AS hod_full_name, u.email AS hod_email, u.designation AS hod_designation
                              FROM departments d
                              LEFT JOIN users u ON u.id = d.hod_user_id
                              WHERE d.college_id=?
                              ORDER BY d.name");
        $st->execute([$college_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'hods'=>$rows]);
        exit;
    }

    // ── verify_access_code ────────────────────────────────────────────────
    // step='college'  → principal verifies with college access_code (from colleges table)
    // step='role'     → for principal: same college code again
    //                   for HOD: checks hod_access_codes table (principal-set code)
    if ($_POST['ajax_action'] === 'verify_access_code') {
        header('Content-Type: application/json');
        $college_id = (int)($_POST['college_id'] ?? 0);
        $step       = $_POST['step'] ?? 'college';
        $code       = strtoupper(trim($_POST['code'] ?? ''));
        $role       = $_POST['role'] ?? '';
        $dept_id    = (int)($_POST['dept_id'] ?? 0);

        if (!$college_id || !$code) {
            echo json_encode(['ok'=>false,'msg'=>'College and code are required']);
            exit;
        }

        $dbV = getDB();
        $verified = false;

        if ($step === 'college') {
            // Both principal and HOD must first verify with the college-level code
            if ($dbV) {
                try {
                    $cs = $dbV->prepare('SELECT access_code FROM colleges WHERE id=?');
                    $cs->execute([$college_id]);
                    $crow = $cs->fetch(PDO::FETCH_ASSOC);
                    $storedCode = ($crow && !empty($crow['access_code'])) ? strtoupper($crow['access_code']) : 'ADMIN';
                    $verified = ($code === $storedCode);
                } catch (Exception $e) {
                    $verified = ($code === 'ADMIN');
                }
            }
            if ($verified) {
                $_SESSION['sa_verified_college_' . $college_id] = true;
                echo json_encode(['ok'=>true]);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'Incorrect college access code. Please try again.']);
            }

        } elseif ($step === 'role') {
            if ($role === 'principal') {
                // Principal verifies with college code again
                if ($dbV) {
                    try {
                        $cs = $dbV->prepare('SELECT access_code FROM colleges WHERE id=?');
                        $cs->execute([$college_id]);
                        $crow = $cs->fetch(PDO::FETCH_ASSOC);
                        $storedCode = ($crow && !empty($crow['access_code'])) ? strtoupper($crow['access_code']) : 'ADMIN';
                        $verified = ($code === $storedCode);
                    } catch (Exception $e) {
                        $verified = ($code === 'ADMIN');
                    }
                }
                if ($verified) {
                    $_SESSION['sa_verified_role_' . $college_id . '_principal'] = true;
                    echo json_encode(['ok'=>true]);
                } else {
                    echo json_encode(['ok'=>false,'msg'=>'Incorrect access code for Principal. Please try again.']);
                }

            } elseif ($role === 'hod') {
                // HOD verifies with the code that PRINCIPAL created for their dept
                if (!$dept_id) {
                    echo json_encode(['ok'=>false,'msg'=>'Department is required for HOD verification.']);
                    exit;
                }
                if ($dbV) {
                    ensureHodCodesTable($dbV);
                    $hs = $dbV->prepare('SELECT id,code,used FROM hod_access_codes WHERE college_id=? AND dept_id=?');
                    $hs->execute([$college_id,$dept_id]);
                    $hrow = $hs->fetch(PDO::FETCH_ASSOC);
                    if (!$hrow) {
                        echo json_encode(['ok'=>false,'msg'=>'No access code has been set for this department yet. Please contact your Principal.']);
                        exit;
                    }
                    $verified = (strtoupper($code) === strtoupper($hrow['code']));
                    if ($verified) {
                        $_SESSION['sa_verified_role_' . $college_id . '_hod'] = true;
                        echo json_encode(['ok'=>true]);
                    } else {
                        echo json_encode(['ok'=>false,'msg'=>'Incorrect HOD access code. Please check with your Principal.']);
                    }
                } else {
                    echo json_encode(['ok'=>false,'msg'=>'Database error.']);
                }
            }
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'set_college_role') {
        $selCollegeId   = (int)($_POST['college_id'] ?? 0);
        $selCollegeRole = in_array($_POST['role'] ?? '', ['principal','hod']) ? $_POST['role'] : '';
        $selDeptId      = (int)($_POST['dept_id'] ?? 0);
        if ($selCollegeId && $selCollegeRole) {
            $keyCollege = 'sa_verified_college_' . $selCollegeId;
            $keyRole    = 'sa_verified_role_' . $selCollegeId . '_' . $selCollegeRole;
            if (!isset($_SESSION[$keyCollege]) || !isset($_SESSION[$keyRole])) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>false,'msg'=>'Access code verification required.']);
                exit;
            }
            $_SESSION['sa_college_id']   = $selCollegeId;
            $_SESSION['sa_college_role'] = $selCollegeRole;
            $_SESSION['sa_dept_id']      = $selDeptId;
            unset($_SESSION[$keyCollege], $_SESSION[$keyRole]);
        }
    }
    if ($_POST['ajax_action'] === 'clear_college_role') {
        unset($_SESSION['sa_college_id'], $_SESSION['sa_college_role'], $_SESSION['sa_dept_id']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

$saCollegeId   = (int)($_SESSION['sa_college_id']   ?? 0);
$saCollegeRole = $_SESSION['sa_college_role'] ?? ''; // 'principal' or 'hod' or ''
$saDeptId      = (int)($_SESSION['sa_dept_id']      ?? 0);

// Load selected college name for display
$saCollegeName = '';
$saCollegeLogo = '';
$saDeptName    = '';
if ($saCollegeId) {
    $dbCheck = getDB();
    if ($dbCheck) {
        $cStmt = $dbCheck->prepare('SELECT name, logo_path FROM colleges WHERE id=?');
        $cStmt->execute([$saCollegeId]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        $saCollegeName = $cRow ? $cRow['name'] : '';
        $saCollegeLogo = $cRow ? ($cRow['logo_path'] ?? '') : '';
        // Resolve HOD department name
        if ($saDeptId) {
            $dStmt = $dbCheck->prepare('SELECT name FROM departments WHERE id=?');
            $dStmt->execute([$saDeptId]);
            $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
            $saDeptName = $dRow ? $dRow['name'] : '';
        }
    }
}

// ── AJAX handler ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])
    && strpos($_POST['ajax_action'], 'expense_')   !== 0   // expense_ actions handled separately below
    && strpos($_POST['ajax_action'], 'leave_')     !== 0   // leave_ actions handled separately below
    && strpos($_POST['ajax_action'], 'admission_') !== 0) { // admission_ actions handled separately below
    header('Content-Type: application/json');
    $db2 = getDB();
    $resp = ['ok' => false, 'msg' => 'Unknown error'];

    $action = $_POST['ajax_action'];

    try {
        // ── ADD COLLEGE ─────────────────────────────────────────────────
        if ($action === 'add_college') {
            $name    = trim($_POST['name'] ?? '');
            $code    = strtoupper(trim($_POST['code'] ?? ''));
            $phone   = trim($_POST['phone'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $est     = trim($_POST['established'] ?? '');
            $status  = $_POST['status'] ?? 'active';
            if (!$name || !$code) { echo json_encode(['ok'=>false,'msg'=>'Name and Code are required']); exit; }
            // check duplicate code
            $chk = $db2->prepare('SELECT id FROM colleges WHERE code=?');
            $chk->execute([$code]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'College code already exists']); exit; }
            $st = $db2->prepare('INSERT INTO colleges (name,code,phone,email,website,address,established,status) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$name,$code,$phone,$email,$website,$address,$est?$est:null,$status]);
            $resp = ['ok'=>true,'msg'=>'College added successfully','id'=>$db2->lastInsertId()];
        }

        // ── UPDATE COLLEGE STATUS ────────────────────────────────────────
        elseif ($action === 'update_college_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            if (!in_array($status,['active','inactive'])) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }
            $st = $db2->prepare('UPDATE colleges SET status=? WHERE id=?');
            $st->execute([$status,$id]);
            $resp = ['ok'=>true,'msg'=>'Status updated'];
        }

        // ── ADD USER ─────────────────────────────────────────────────────
        elseif ($action === 'add_user') {
            $full_name   = trim($_POST['full_name'] ?? '');
            $username    = trim($_POST['username'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $password    = trim($_POST['password'] ?? '');
            $role        = $_POST['role'] ?? 'student';
            $college_id  = (int)($_POST['college_id'] ?? 0) ?: null;
            $dept_id     = (int)($_POST['department_id'] ?? 0) ?: null;
            $phone       = trim($_POST['phone'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $status      = $_POST['status'] ?? 'active';
            if (!$full_name || !$username || !$email || !$password) { echo json_encode(['ok'=>false,'msg'=>'Name, username, email and password are required']); exit; }
            // check duplicate username/email
            $chk = $db2->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $chk->execute([$username,$email]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Username or email already exists']); exit; }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $st = $db2->prepare('INSERT INTO users (college_id,department_id,username,email,password,full_name,role,phone,designation,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id,$dept_id,$username,$email,$hashed,$full_name,$role,$phone,$designation,$status]);
            $resp = ['ok'=>true,'msg'=>'User added successfully','id'=>$db2->lastInsertId()];
        }

        // ── UPDATE USER STATUS ───────────────────────────────────────────
        elseif ($action === 'update_user_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            if (!in_array($status,['active','inactive','pending'])) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }
            $st = $db2->prepare('UPDATE users SET status=? WHERE id=?');
            $st->execute([$status,$id]);
            $resp = ['ok'=>true,'msg'=>'User status updated'];
        }

        // ── ADD DEPARTMENT ───────────────────────────────────────────────
        elseif ($action === 'add_department') {
            $name       = trim($_POST['name'] ?? '');
            $code       = strtoupper(trim($_POST['code'] ?? ''));
            $college_id = (int)($_POST['college_id'] ?? 0);
            $hod_name   = trim($_POST['hod_name'] ?? '');
            $status     = $_POST['status'] ?? 'active';
            if (!$name || !$code || !$college_id) { echo json_encode(['ok'=>false,'msg'=>'Name, code and college are required']); exit; }
            $chk = $db2->prepare('SELECT id FROM departments WHERE code=? AND college_id=?');
            $chk->execute([$code,$college_id]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Department code already exists in this college']); exit; }
            $st = $db2->prepare('INSERT INTO departments (college_id,name,code,hod_name,status) VALUES (?,?,?,?,?)');
            $st->execute([$college_id,$name,$code,$hod_name,$status]);
            $resp = ['ok'=>true,'msg'=>'Department added successfully','id'=>$db2->lastInsertId()];
        }

        // ── UPDATE DEPARTMENT STATUS ─────────────────────────────────────
        elseif ($action === 'update_dept_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $st = $db2->prepare('UPDATE departments SET status=? WHERE id=?');
            $st->execute([$status,$id]);
            $resp = ['ok'=>true,'msg'=>'Status updated'];
        }

        // ── ADD COURSE ───────────────────────────────────────────────────
        elseif ($action === 'add_course') {
            $name       = trim($_POST['name'] ?? '');
            $code       = strtoupper(trim($_POST['code'] ?? ''));
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['department_id'] ?? 0);
            $credits    = (float)($_POST['credits'] ?? 3);
            $semester   = (int)($_POST['semester'] ?? 0) ?: null;
            $acad_year  = trim($_POST['academic_year'] ?? '2025-26') ?: '2025-26';
            $desc       = trim($_POST['description'] ?? '');
            $status     = $_POST['status'] ?? 'active';
            ensureCourseColumns($db2);
            if (!$name || !$code || !$college_id || !$dept_id) { echo json_encode(['ok'=>false,'msg'=>'Name, code, college and department are required']); exit; }
            $chk = $db2->prepare('SELECT id FROM courses WHERE code=? AND college_id=?');
            $chk->execute([$code,$college_id]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Course code already exists in this college']); exit; }
            $st = $db2->prepare('INSERT INTO courses (college_id,department_id,name,code,credits,semester,academic_year,description,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id,$dept_id,$name,$code,$credits,$semester,$acad_year,$desc,$status,$user['id']]);
            $resp = ['ok'=>true,'msg'=>'Course added successfully','id'=>$db2->lastInsertId()];
        }

        // ── HOD: CREATE COURSE / SUBJECT for own department ─────────────
        elseif ($action === 'create_course_hod') {
            // Only HOD (or superadmin acting in HOD context) may call this
            if ($saCollegeRole !== 'hod' && $user['role'] !== 'super_admin') {
                echo json_encode(['ok'=>false,'msg'=>'Access denied. Only HOD can create courses.']); exit;
            }
            $name        = trim($_POST['name'] ?? '');
            $code        = strtoupper(trim($_POST['code'] ?? ''));
            $credits     = (float)($_POST['credits'] ?? 3);
            $semester    = (int)($_POST['semester'] ?? 0);
            $acad_year   = trim($_POST['academic_year'] ?? '2025-26') ?: '2025-26';
            $desc        = trim($_POST['description'] ?? '');
            // HOD is always locked to their own college + dept
            $college_id  = (int)$saCollegeId;
            $dept_id     = (int)$saDeptId;

            ensureCourseColumns($db2);

            if (!$name || !$code) {
                echo json_encode(['ok'=>false,'msg'=>'Course name and code are required.']); exit;
            }
            if (!$college_id || !$dept_id) {
                echo json_encode(['ok'=>false,'msg'=>'Session scope missing — please re-login as HOD.']); exit;
            }
            if ($semester < 1 || $semester > 12) {
                echo json_encode(['ok'=>false,'msg'=>'A valid semester (1–12) is required.']); exit;
            }
            if (!preg_match('/^\d{4}-\d{2,4}$/', $acad_year)) {
                echo json_encode(['ok'=>false,'msg'=>'Academic year must be in format like 2025-26.']); exit;
            }
            // Duplicate: same code in same college + dept + academic_year + semester
            $chk = $db2->prepare('SELECT id FROM courses WHERE code=? AND college_id=? AND department_id=? AND academic_year=? AND semester=?');
            $chk->execute([$code, $college_id, $dept_id, $acad_year, $semester]);
            if ($chk->fetch()) {
                echo json_encode(['ok'=>false,'msg'=>"Course code '$code' already exists for Sem $semester / $acad_year in your department."]);
                exit;
            }
            $st = $db2->prepare('INSERT INTO courses (college_id,department_id,name,code,credits,semester,academic_year,description,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id, $dept_id, $name, $code, $credits, $semester, $acad_year, $desc, 'active', $user['id']]);
            $new_id = (int)$db2->lastInsertId();
            echo json_encode([
                'ok'  => true,
                'msg' => "Course '$name ($code)' created for Sem $semester / $acad_year.",
                'id'  => $new_id,
                'course' => ['id'=>$new_id,'name'=>$name,'code'=>$code,'semester'=>$semester,'academic_year'=>$acad_year,'credits'=>$credits]
            ]);
            exit;
        }

        // ── UPDATE COURSE STATUS ─────────────────────────────────────────
        elseif ($action === 'update_course_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $st = $db2->prepare('UPDATE courses SET status=? WHERE id=?');
            $st->execute([$status,$id]);
            $resp = ['ok'=>true,'msg'=>'Status updated'];
        }

        // ── ADD STUDENT ──────────────────────────────────────────────────
        elseif ($action === 'add_student') {
            $full_name   = trim($_POST['full_name'] ?? '');
            $username    = trim($_POST['username'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $password    = trim($_POST['password'] ?? '');
            $college_id  = (int)($_POST['college_id'] ?? 0) ?: null;
            $dept_id     = (int)($_POST['department_id'] ?? 0) ?: null;
            $phone       = trim($_POST['phone'] ?? '');
            $roll        = trim($_POST['roll_number'] ?? '');
            $status      = $_POST['status'] ?? 'active';
            if (!$full_name || !$username || !$email || !$password) { echo json_encode(['ok'=>false,'msg'=>'Name, username, email and password are required']); exit; }
            $chk = $db2->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $chk->execute([$username,$email]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Username or email already exists']); exit; }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $st = $db2->prepare('INSERT INTO users (college_id,department_id,username,email,password,full_name,roll_number,role,phone,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id,$dept_id,$username,$email,$hashed,$full_name,$roll,'student',$phone,$status]);
            $resp = ['ok'=>true,'msg'=>'Student added successfully','id'=>$db2->lastInsertId()];
        }

        // ── ADD FACULTY ──────────────────────────────────────────────────
        elseif ($action === 'add_faculty') {
            $full_name   = trim($_POST['full_name'] ?? '');
            $username    = trim($_POST['username'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $password    = trim($_POST['password'] ?? '');
            $college_id  = (int)($_POST['college_id'] ?? 0) ?: null;
            $dept_id     = (int)($_POST['department_id'] ?? 0) ?: null;
            $phone       = trim($_POST['phone'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $status      = $_POST['status'] ?? 'active';
            if (!$full_name || !$username || !$email || !$password) { echo json_encode(['ok'=>false,'msg'=>'Name, username, email and password are required']); exit; }
            $chk = $db2->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $chk->execute([$username,$email]);
            if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Username or email already exists']); exit; }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $st = $db2->prepare('INSERT INTO users (college_id,department_id,username,email,password,full_name,role,phone,designation,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id,$dept_id,$username,$email,$hashed,$full_name,'faculty',$phone,$designation,$status]);
            $resp = ['ok'=>true,'msg'=>'Faculty added successfully','id'=>$db2->lastInsertId()];
        }

        // ── GET DEPARTMENTS BY COLLEGE (for dynamic selects) ─────────────
        elseif ($action === 'get_departments') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $st = $db2->prepare('SELECT id,name,code,hod_name FROM departments WHERE college_id=? AND status="active" ORDER BY name');
            $st->execute([$college_id]);
            $depts = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'departments'=>$depts]);
            exit;
        }

        // ── GET COURSES BY DEPARTMENT ───────────────────────────────────
        elseif ($action === 'get_courses') {
            $dept_id    = (int)($_POST['department_id'] ?? 0);
            $acad_year  = trim($_POST['academic_year'] ?? '');
            $semester   = (int)($_POST['semester'] ?? 0);
            $params     = [$dept_id];
            $clauses    = '';
            if ($acad_year) { $clauses .= ' AND academic_year=?'; $params[] = $acad_year; }
            if ($semester)  { $clauses .= ' AND semester=?';      $params[] = $semester; }
            $st = $db2->prepare("SELECT id,name,code,semester,credits,
                                        COALESCE(academic_year,'2025-26') AS academic_year
                                 FROM courses
                                 WHERE department_id=? AND status='active'$clauses
                                 ORDER BY semester,name");
            $st->execute($params);
            $courses = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'courses'=>$courses]);
            exit;
        }

        // ── GET DISTINCT SEMESTERS FOR A DEPARTMENT ────────────────────
        elseif ($action === 'get_semesters_for_dept') {
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $college_id = (int)($_POST['college_id'] ?? 0);
            // If HOD role: enforce dept restriction server-side
            if ($saCollegeRole === 'hod' && $saDeptId) { $dept_id = $saDeptId; }
            $params = [];
            $where  = 'status=\'active\'';
            if ($dept_id)    { $where .= ' AND department_id=?'; $params[] = $dept_id; }
            if ($college_id) { $where .= ' AND college_id=?';    $params[] = $college_id; }
            $st = $db2->prepare("SELECT DISTINCT semester FROM courses WHERE $where AND semester IS NOT NULL ORDER BY semester+0 ASC");
            $st->execute($params);
            $sems = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'semester');
            echo json_encode(['ok'=>true,'semesters'=>$sems]);
            exit;
        }

        // ── GET FACULTY BY COLLEGE ──────────────────────────────────────
        elseif ($action === 'get_faculty') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            if ($college_id) {
                $st = $db2->prepare('SELECT id,full_name,email,department_id,college_id,designation FROM users WHERE role="faculty" AND status="active" AND college_id=? ORDER BY full_name');
                $st->execute([$college_id]);
            } else {
                $st = $db2->prepare('SELECT id,full_name,email,department_id,college_id,designation FROM users WHERE role="faculty" AND status="active" ORDER BY full_name');
                $st->execute();
            }
            $faculty = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'faculty'=>$faculty]);
            exit;
        }

        // ── ADD FACULTY ASSIGNMENT ──────────────────────────────────────
        elseif ($action === 'add_faculty_assignment') {
            $faculty_id  = (int)($_POST['faculty_id'] ?? 0);
            $college_id  = (int)($_POST['college_id'] ?? 0);
            $dept_id     = (int)($_POST['department_id'] ?? 0);
            $course_id   = (int)($_POST['course_id'] ?? 0);
            $semester    = (int)($_POST['semester'] ?? 0) ?: null;
            $acad_year   = trim($_POST['academic_year'] ?? '');
            $period      = trim($_POST['period'] ?? '');
            $is_primary  = (int)($_POST['is_primary'] ?? 1);
            $remarks     = trim($_POST['remarks'] ?? '');

            // HOD: lock assignment to their own college + department
            if ($saCollegeRole === 'hod' && $saDeptId) {
                $college_id = (int)$saCollegeId;
                $dept_id    = (int)$saDeptId;
            }
            
            if (!$faculty_id || !$college_id || !$dept_id || !$course_id) {
                echo json_encode(['ok'=>false,'msg'=>'Faculty, College, Department and Course are required']);
                exit;
            }
            
            // Check if exact same assignment already exists (same faculty+course+year+period+college+dept)
            $chk = $db2->prepare('SELECT id FROM faculty_assignments WHERE faculty_id=? AND course_id=? AND academic_year=? AND period=? AND college_id=? AND department_id=? AND status="active"');
            $chk->execute([$faculty_id, $course_id, $acad_year, $period, $college_id, $dept_id]);
            if ($chk->fetch()) {
                echo json_encode(['ok'=>false,'msg'=>'This faculty is already assigned to this course for the selected college, department, year and period.']);
                exit;
            }
            
            $st = $db2->prepare('INSERT INTO faculty_assignments (faculty_id,college_id,department_id,course_id,semester,academic_year,period,is_primary,assigned_by,remarks) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$faculty_id,$college_id,$dept_id,$course_id,$semester,$acad_year,$period,$is_primary,$user['id'],$remarks]);
            $assignment_id = $db2->lastInsertId();

            // ── Multi-assignment: only set users.college_id if this is the first assignment.
            // The UI reads from faculty_assignments for the full picture.
            $chkUser = $db2->prepare('SELECT college_id FROM users WHERE id=?');
            $chkUser->execute([$faculty_id]);
            $currentUser = $chkUser->fetch(PDO::FETCH_ASSOC);
            if (!$currentUser['college_id']) {
                $upd = $db2->prepare('UPDATE users SET college_id=?, department_id=? WHERE id=? AND role="faculty"');
                $upd->execute([$college_id, $dept_id, $faculty_id]);
            }

            $resp = ['ok'=>true,'msg'=>'Faculty assignment created successfully','id'=>$assignment_id];
        }

        // ── UPDATE FACULTY ASSIGNMENT ───────────────────────────────────
        elseif ($action === 'update_faculty_assignment') {
            $id          = (int)($_POST['id'] ?? 0);
            $faculty_id  = (int)($_POST['faculty_id'] ?? 0);
            $college_id  = (int)($_POST['college_id'] ?? 0);
            $dept_id     = (int)($_POST['department_id'] ?? 0);
            $course_id   = (int)($_POST['course_id'] ?? 0);
            $semester    = (int)($_POST['semester'] ?? 0) ?: null;
            $acad_year   = trim($_POST['academic_year'] ?? '');
            $period      = trim($_POST['period'] ?? '');
            $is_primary  = (int)($_POST['is_primary'] ?? 1);
            $status      = $_POST['status'] ?? 'active';
            $remarks     = trim($_POST['remarks'] ?? '');
            
            if (!$id) {
                echo json_encode(['ok'=>false,'msg'=>'Assignment ID is required']);
                exit;
            }
            if (!$faculty_id || !$college_id || !$dept_id || !$course_id) {
                echo json_encode(['ok'=>false,'msg'=>'Faculty, College, Department and Course are required']);
                exit;
            }

            // Check duplicate (excluding this record)
            $chk = $db2->prepare('SELECT id FROM faculty_assignments WHERE faculty_id=? AND course_id=? AND academic_year=? AND period=? AND college_id=? AND department_id=? AND status="active" AND id!=?');
            $chk->execute([$faculty_id, $course_id, $acad_year, $period, $college_id, $dept_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['ok'=>false,'msg'=>'This assignment already exists for the selected faculty/course/year/period.']);
                exit;
            }
            
            $st = $db2->prepare('UPDATE faculty_assignments SET faculty_id=?,college_id=?,department_id=?,course_id=?,semester=?,academic_year=?,period=?,is_primary=?,status=?,remarks=? WHERE id=?');
            $st->execute([$faculty_id,$college_id,$dept_id,$course_id,$semester,$acad_year,$period,$is_primary,$status,$remarks,$id]);

            // ── Sync users table: recalculate from the most recent active primary assignment
            // (falls back to most recent active any assignment, then NULL if none remain)
            $latest = $db2->prepare('
                SELECT college_id, department_id
                FROM faculty_assignments
                WHERE faculty_id=? AND status="active"
                ORDER BY is_primary DESC, id DESC
                LIMIT 1
            ');
            $latest->execute([$faculty_id]);
            $lat = $latest->fetch(PDO::FETCH_ASSOC);
            $upd = $db2->prepare('UPDATE users SET college_id=?, department_id=? WHERE id=? AND role="faculty"');
            $upd->execute([$lat ? $lat['college_id'] : null, $lat ? $lat['department_id'] : null, $faculty_id]);

            $resp = ['ok'=>true,'msg'=>'Assignment updated successfully'];
        }

        // ── DELETE FACULTY ASSIGNMENT ───────────────────────────────────
        elseif ($action === 'delete_faculty_assignment') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok'=>false,'msg'=>'Assignment ID is required']);
                exit;
            }
            // Fetch assignment details before deleting so we can sync users table
            $fetch = $db2->prepare('SELECT faculty_id, college_id, department_id FROM faculty_assignments WHERE id=?');
            $fetch->execute([$id]);
            $asgn = $fetch->fetch(PDO::FETCH_ASSOC);

            $st = $db2->prepare('DELETE FROM faculty_assignments WHERE id=?');
            $st->execute([$id]);

            // Sync users table: set college/dept to most recent remaining active assignment,
            // or NULL if no active assignments remain
            if ($asgn) {
                $latest = $db2->prepare('SELECT college_id, department_id FROM faculty_assignments WHERE faculty_id=? AND status="active" ORDER BY id DESC LIMIT 1');
                $latest->execute([$asgn['faculty_id']]);
                $lat = $latest->fetch(PDO::FETCH_ASSOC);
                $upd = $db2->prepare('UPDATE users SET college_id=?, department_id=? WHERE id=? AND role="faculty"');
                $upd->execute([$lat ? $lat['college_id'] : null, $lat ? $lat['department_id'] : null, $asgn['faculty_id']]);
            }

            $resp = ['ok'=>true,'msg'=>'Assignment deleted successfully'];
        }

        // ── ANALYTICS DATA ──────────────────────────────────────────────
        elseif ($action === 'analytics_data') {
            $cid = (int)($_POST['college_id'] ?? $saCollegeId ?? 0);
            if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'No college selected']); exit; }

            // ── KPI totals ──────────────────────────────────────────────
            $kpiQ = [
                'students'    => "SELECT COUNT(*) FROM users WHERE role='student' AND college_id=? AND status='active'",
                'faculty'     => "SELECT COUNT(*) FROM users WHERE role='faculty' AND college_id=? AND status='active'",
                'departments' => "SELECT COUNT(*) FROM departments WHERE college_id=? AND status='active'",
                'courses'     => "SELECT COUNT(*) FROM courses c JOIN departments d ON c.department_id=d.id WHERE d.college_id=? AND c.status='active'",
                'exams'       => "SELECT COUNT(*) FROM exams WHERE college_id=?",
                'applications'=> "SELECT COUNT(*) FROM applications WHERE college_id=?",
                'fee_collected'=> "SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE college_id=? AND payment_status='Success'",
                'pending_leaves'=> "SELECT COUNT(*) FROM leave_applications WHERE college_id=? AND status='pending'",
            ];
            $totals = [];
            foreach ($kpiQ as $k => $q) { $s=$db2->prepare($q);$s->execute([$cid]);$totals[$k]=$s->fetchColumn(); }
            $totals['fee_collected'] = (float)$totals['fee_collected'];

            // ── Students per department ──────────────────────────────────
            $s=$db2->prepare("SELECT d.name, COUNT(u.id) AS cnt
                FROM departments d LEFT JOIN users u ON u.college_id=d.college_id AND u.department_id=d.id AND u.role='student' AND u.status='active'
                WHERE d.college_id=? AND d.status='active' GROUP BY d.id,d.name ORDER BY cnt DESC LIMIT 10");
            $s->execute([$cid]); $deptStudents=$s->fetchAll(PDO::FETCH_ASSOC);

            // ── Faculty per department ───────────────────────────────────
            $s=$db2->prepare("SELECT d.name, COUNT(u.id) AS cnt
                FROM departments d LEFT JOIN users u ON u.college_id=d.college_id AND u.department_id=d.id AND u.role='faculty' AND u.status='active'
                WHERE d.college_id=? AND d.status='active' GROUP BY d.id,d.name ORDER BY cnt DESC LIMIT 10");
            $s->execute([$cid]); $deptFaculty=$s->fetchAll(PDO::FETCH_ASSOC);

            // ── Courses per semester ─────────────────────────────────────
            $s=$db2->prepare("SELECT c.semester, COUNT(c.id) AS cnt
                FROM courses c JOIN departments d ON c.department_id=d.id
                WHERE d.college_id=? AND c.status='active' AND c.semester IS NOT NULL
                GROUP BY c.semester ORDER BY c.semester+0 ASC");
            $s->execute([$cid]); $semCourses=$s->fetchAll(PDO::FETCH_ASSOC);

            // ── Attendance last 30 days (status: P=present, A=absent, L=late) ──
            $attRow=['present'=>0,'absent'=>0,'late'=>0,'total'=>0]; $attPct=null;
            try {
                $s=$db2->prepare("SELECT
                    SUM(CASE WHEN status='P' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN status='A' THEN 1 ELSE 0 END) AS absent,
                    SUM(CASE WHEN status='L' THEN 1 ELSE 0 END) AS late,
                    COUNT(*) AS total
                    FROM attendance WHERE college_id=? AND date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
                $s->execute([$cid]); $r2=$s->fetch(PDO::FETCH_ASSOC);
                if ($r2) { $attRow=$r2; $attPct=$r2['total']>0?round(100*$r2['present']/$r2['total'],1):null; }
            } catch(Exception $e){}

            // ── Attendance trend last 6 months (monthly %) ───────────────
            $attTrend=[];
            try {
                $s=$db2->prepare("SELECT DATE_FORMAT(date,'%b %Y') AS mon, DATE_FORMAT(date,'%Y-%m') AS ym,
                    ROUND(SUM(status='P')*100.0/NULLIF(COUNT(*),0),1) AS pct, COUNT(*) AS total
                    FROM attendance WHERE college_id=? AND date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
                    GROUP BY ym ORDER BY ym ASC");
                $s->execute([$cid]); $attTrend=$s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            // ── Exam summary ─────────────────────────────────────────────
            $examStats=['upcoming'=>0,'draft'=>0,'published'=>0,'completed'=>0];
            try {
                $s=$db2->prepare("SELECT status, COUNT(*) AS cnt FROM exams WHERE college_id=? GROUP BY status");
                $s->execute([$cid]);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row) $examStats[$row['status']]=(int)$row['cnt'];
            } catch(Exception $e){}

            // ── Exam type breakdown ──────────────────────────────────────
            $examTypes=[];
            try {
                $s=$db2->prepare("SELECT type, COUNT(*) AS cnt FROM exams WHERE college_id=? GROUP BY type ORDER BY cnt DESC");
                $s->execute([$cid]); $examTypes=$s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            // ── Marks/results: avg % per course ─────────────────────────
            $courseMarks=[];
            try {
                $s=$db2->prepare("SELECT c.name AS course, ROUND(AVG(m.percentage),1) AS avg_pct,
                    SUM(m.is_pass) AS passed, COUNT(m.id) AS total
                    FROM marks m JOIN exams e ON m.exam_id=e.id JOIN courses c ON e.course_id=c.id
                    WHERE m.college_id=? GROUP BY c.id,c.name ORDER BY avg_pct DESC LIMIT 8");
                $s->execute([$cid]); $courseMarks=$s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            // ── Fee collection summary ───────────────────────────────────
            $feeStats=['total_fee'=>0,'paid'=>0,'balance'=>0,'paid_count'=>0,'pending_count'=>0];
            try {
                $s=$db2->prepare("SELECT
                    COALESCE(SUM(total_fee),0) AS total_fee,
                    COALESCE(SUM(paid_amount),0) AS paid,
                    COALESCE(SUM(balance_amount),0) AS balance,
                    SUM(fee_status='Paid') AS paid_count,
                    SUM(fee_status='Pending' OR fee_status='Partial') AS pending_count
                    FROM student_fees WHERE college_id=?");
                $s->execute([$cid]); $r2=$s->fetch(PDO::FETCH_ASSOC);
                if ($r2) $feeStats=$r2;
            } catch(Exception $e){}

            // ── Applications by status ───────────────────────────────────
            $appStats=[];
            try {
                $s=$db2->prepare("SELECT application_status AS status, COUNT(*) AS cnt FROM applications WHERE college_id=? GROUP BY application_status ORDER BY cnt DESC");
                $s->execute([$cid]); $appStats=$s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            // ── Leave applications summary ───────────────────────────────
            $leaveStats=['pending'=>0,'approved'=>0,'rejected'=>0];
            try {
                $s=$db2->prepare("SELECT status, COUNT(*) AS cnt FROM leave_applications WHERE college_id=? GROUP BY status");
                $s->execute([$cid]);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row) $leaveStats[$row['status']]=(int)$row['cnt'];
            } catch(Exception $e){}

            // ── Student registrations trend (last 6 months) ──────────────
            $regTrend=[];
            try {
                $s=$db2->prepare("SELECT DATE_FORMAT(created_at,'%b %Y') AS mon, DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
                    FROM users WHERE role='student' AND college_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH)
                    GROUP BY ym ORDER BY ym ASC");
                $s->execute([$cid]); $regTrend=$s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            echo json_encode([
                'ok'          => true,
                'totals'      => $totals,
                'deptStudents'=> $deptStudents,
                'deptFaculty' => $deptFaculty,
                'semCourses'  => $semCourses,
                'attendance'  => ['pct'=>$attPct,'present'=>(int)($attRow['present']??0),'absent'=>(int)($attRow['absent']??0),'late'=>(int)($attRow['late']??0),'total'=>(int)($attRow['total']??0)],
                'attTrend'    => $attTrend,
                'examStats'   => $examStats,
                'examTypes'   => $examTypes,
                'courseMarks' => $courseMarks,
                'feeStats'    => $feeStats,
                'appStats'    => $appStats,
                'leaveStats'  => $leaveStats,
                'regTrend'    => $regTrend,
            ]);
            exit;
        }

        // ── GET ATTENDANCE (super-admin cross-college view) ─────────────
        elseif ($action === 'get_attendance') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $course_id  = (int)($_POST['course_id']  ?? 0);
            $from       = $_POST['from'] ?? date('Y-m-01');
            $to         = $_POST['to']   ?? date('Y-m-d');
            $search     = trim($_POST['search'] ?? '');

            // Cap range to 90 days
            if ((strtotime($to) - strtotime($from)) > 90*86400)
                $from = date('Y-m-d', strtotime($to) - 90*86400);

            // ── Per-student summary ──────────────────────────────────────
            $sql = "
                SELECT
                    u.id            AS student_id,
                    u.full_name,
                    u.roll_number,
                    u.avatar_color,
                    u.status        AS user_status,
                    col.name        AS college_name,
                    col.code        AS college_code,
                    d.name          AS dept_name,
                    d.code          AS dept_code,
                    COALESCE(co.name,'—')  AS course_name,
                    COALESCE(co.code,'—')  AS course_code,
                    COALESCE(a.course_id,0) AS course_id,
                    COUNT(a.id)                               AS total,
                    SUM(a.status = 'P')                       AS present,
                    SUM(a.status = 'A')                       AS absent,
                    SUM(a.status = 'L')                       AS late,
                    SUM(a.status = 'H')                       AS half_day,
                    SUM(a.status IN ('LV','ML'))               AS leave_cnt,
                    SUM(a.status = 'HOL')                     AS holiday,
                    ROUND(SUM(a.status='P')*100.0/NULLIF(COUNT(a.id),0),1) AS pct
                FROM users u
                INNER JOIN attendance a   ON a.student_id    = u.id
                                         AND a.date BETWEEN ? AND ?
                INNER JOIN colleges  col  ON col.id          = u.college_id
                INNER JOIN departments d  ON d.id            = u.department_id
                LEFT  JOIN courses   co   ON co.id           = a.course_id
                WHERE u.role = 'student' AND u.status = 'active'
            ";
            $params = [$from, $to];

            if ($college_id) { $sql .= " AND u.college_id    = ?"; $params[] = $college_id; }
            if ($dept_id)    { $sql .= " AND u.department_id = ?"; $params[] = $dept_id;    }
            if ($course_id)  { $sql .= " AND a.course_id     = ?"; $params[] = $course_id;  }
            if ($search)     { $sql .= " AND (u.full_name LIKE ? OR u.roll_number LIKE ?)";
                               $params[] = "%$search%"; $params[] = "%$search%"; }

            $sql .= " GROUP BY u.id, u.full_name, u.roll_number, u.avatar_color,
                               u.status, col.name, col.code, d.name, d.code,
                               co.name, co.code, a.course_id
                      ORDER BY co.name, u.full_name";

            $st = $db2->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // ── Daily calendar map (for date heatmap) ───────────────────
            $calSql = "
                SELECT a.student_id, COALESCE(a.course_id,0) AS course_id, a.date, a.status
                FROM attendance a
                INNER JOIN users u ON u.id = a.student_id
                WHERE u.role='student' AND u.status='active'
                  AND a.date BETWEEN ? AND ?
            ";
            $calP = [$from, $to];
            if ($college_id) { $calSql .= " AND u.college_id    = ?"; $calP[] = $college_id; }
            if ($dept_id)    { $calSql .= " AND u.department_id = ?"; $calP[] = $dept_id;    }
            if ($course_id)  { $calSql .= " AND a.course_id     = ?"; $calP[] = $course_id;  }
            if ($search)     { $calSql .= " AND (u.full_name LIKE ? OR u.roll_number LIKE ?)";
                               $calP[] = "%$search%"; $calP[] = "%$search%"; }
            $calSt = $db2->prepare($calSql);
            $calSt->execute($calP);
            $calMap = [];
            foreach ($calSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // key: student_id|course_id → date → status
                $mapKey = $r['student_id'] . '|' . $r['course_id'];
                $calMap[$mapKey][$r['date']] = $r['status'];
            }

            // ── All unique dates (for column headers) ────────────────────
            $dateSql = "
                SELECT DISTINCT a.date
                FROM attendance a
                INNER JOIN users u ON u.id = a.student_id
                WHERE u.role='student' AND u.status='active'
                  AND a.date BETWEEN ? AND ?
            ";
            $dateP = [$from, $to];
            if ($college_id) { $dateSql .= " AND u.college_id    = ?"; $dateP[] = $college_id; }
            if ($dept_id)    { $dateSql .= " AND u.department_id = ?"; $dateP[] = $dept_id;    }
            if ($course_id)  { $dateSql .= " AND a.course_id     = ?"; $dateP[] = $course_id;  }
            $dateSt = $db2->prepare($dateSql);
            $dateSt->execute($dateP);
            $dates = array_column($dateSt->fetchAll(PDO::FETCH_ASSOC), 'date');
            rsort($dates); // newest first

            // ── Aggregate totals ─────────────────────────────────────────
            $totals = ['total'=>0,'present'=>0,'absent'=>0,'late'=>0,'leave_cnt'=>0,'holiday'=>0];
            foreach ($rows as $r) {
                foreach ($totals as $k => $_) $totals[$k] += (int)$r[$k];
            }
            $totals['pct'] = $totals['total']
                ? round($totals['present'] * 100 / $totals['total'], 1) : 0;

            echo json_encode([
                'ok'      => true,
                'rows'    => $rows,
                'dates'   => $dates,
                'cal_map' => $calMap,
                'totals'  => $totals,
                'from'    => $from,
                'to'      => $to,
            ]);
            exit;
        }

        // ── GET FACULTY ASSIGNMENTS (with filters) ──────────────────────
        elseif ($action === 'get_faculty_assignments') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['department_id'] ?? 0);
            $faculty_id = (int)($_POST['faculty_id'] ?? 0);

            // Direct JOIN — does NOT depend on v_faculty_assignments view existing
            $sql = '
                SELECT
                    fa.id                   AS assignment_id,
                    fa.id                   AS id,
                    fa.faculty_id,
                    fa.college_id,
                    fa.department_id,
                    fa.course_id,
                    fa.semester,
                    fa.academic_year,
                    fa.period,
                    fa.is_primary,
                    fa.status,
                    fa.remarks,
                    u.full_name             AS faculty_name,
                    u.email                 AS faculty_email,
                    u.designation           AS faculty_designation,
                    c.name                  AS college_name,
                    c.code                  AS college_code,
                    d.name                  AS department_name,
                    d.code                  AS department_code,
                    co.name                 AS course_name,
                    co.code                 AS course_code
                FROM faculty_assignments fa
                LEFT JOIN users       u  ON u.id  = fa.faculty_id
                LEFT JOIN colleges    c  ON c.id  = fa.college_id
                LEFT JOIN departments d  ON d.id  = fa.department_id
                LEFT JOIN courses     co ON co.id = fa.course_id
                WHERE 1=1
            ';
            $params = [];

            if ($college_id) {
                $sql .= ' AND fa.college_id=?';
                $params[] = $college_id;
            }
            if ($dept_id) {
                $sql .= ' AND fa.department_id=?';
                $params[] = $dept_id;
            }
            if ($faculty_id) {
                $sql .= ' AND fa.faculty_id=?';
                $params[] = $faculty_id;
            }

            $sql .= ' ORDER BY faculty_name, course_name';

            try {
                $st = $db2->prepare($sql);
                $st->execute($params);
                $assignments = $st->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok'=>true,'assignments'=>$assignments]);
            } catch (Exception $e) {
                echo json_encode(['ok'=>false,'msg'=>'DB error: '.$e->getMessage(),'assignments'=>[]]);
            }
            exit;
        }


        // ── GET ACADEMIC DETAILS (HOD: dept courses grouped by sem + assigned faculty) ─
        elseif ($action === 'get_academic_details') {
            $college_id = (int)($_POST['college_id'] ?? $saCollegeId ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? $saDeptId    ?? 0);
            $acad_year  = trim($_POST['academic_year'] ?? '');

            if (!$college_id || !$dept_id) {
                echo json_encode(['ok'=>false,'msg'=>'College and department scope required.']); exit;
            }

            // Build year filter
            $yearClause  = $acad_year ? ' AND co.academic_year=?' : '';
            $yearParams  = $acad_year ? [$acad_year] : [];

            // Fetch all courses for this dept
            $stCourses = $db2->prepare("
                SELECT co.id, co.name, co.code, co.credits,
                       COALESCE(co.semester,0)      AS semester,
                       COALESCE(co.academic_year,'2025-26') AS academic_year,
                       co.status
                FROM courses co
                WHERE co.college_id=? AND co.department_id=? AND co.status='active'
                $yearClause
                ORDER BY co.academic_year DESC, co.semester ASC, co.name ASC
            ");
            $stCourses->execute(array_merge([$college_id, $dept_id], $yearParams));
            $courses = $stCourses->fetchAll(PDO::FETCH_ASSOC);

            // For each course fetch assigned faculty
            $stFac = $db2->prepare("
                SELECT u.id, u.full_name, u.email,
                       COALESCE(u.designation,'—') AS designation,
                       fa.is_primary, fa.period, fa.academic_year AS assign_year, fa.status AS fa_status
                FROM faculty_assignments fa
                JOIN users u ON u.id = fa.faculty_id
                WHERE fa.course_id=? AND fa.college_id=? AND fa.department_id=? AND fa.status='active'
                ORDER BY fa.is_primary DESC, u.full_name ASC
            ");

            $grouped = []; // keyed by "academic_year|semester"
            foreach ($courses as $c) {
                $stFac->execute([$c['id'], $college_id, $dept_id]);
                $c['faculty'] = $stFac->fetchAll(PDO::FETCH_ASSOC);
                $key = $c['academic_year'] . '|' . $c['semester'];
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'academic_year' => $c['academic_year'],
                        'semester'      => (int)$c['semester'],
                        'courses'       => []
                    ];
                }
                $grouped[$key]['courses'][] = $c;
            }

            // Sort groups: year desc, semester asc
            usort($grouped, fn($a,$b) =>
                $b['academic_year'] <=> $a['academic_year'] ?: $a['semester'] <=> $b['semester']
            );

            // Fetch all distinct academic years for this dept (for filter dropdown)
            try {
                $stYears = $db2->prepare("
                    SELECT DISTINCT COALESCE(academic_year,'2025-26') AS academic_year
                    FROM courses WHERE college_id=? AND department_id=? AND status='active'
                    ORDER BY academic_year DESC
                ");
                $stYears->execute([$college_id, $dept_id]);
                $years = array_column($stYears->fetchAll(PDO::FETCH_ASSOC), 'academic_year');
            } catch (Exception $e) { $years = ['2025-26']; }

            echo json_encode([
                'ok'      => true,
                'groups'  => array_values($grouped),
                'years'   => $years,
                'total_courses'  => count($courses),
            ]);
            exit;
        }


        
        // ── CREATE EXAM (superadmin creates exam list visible in examinations.php) ──
        elseif ($action === 'sa_create_exam') {
            // ── Role guard: Principal cannot create/edit/delete exams (HOD can) ──
            if ($saCollegeRole === 'principal') {
                echo json_encode(['ok'=>false,'msg'=>'Principals have view-only access to exams.']);
                exit;
            }
            $college_id  = (int)($_POST['college_id'] ?? 0);
            $dept_id     = (int)($_POST['department_id'] ?? 0);
            $course_id   = (int)($_POST['course_id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $type        = trim($_POST['type'] ?? 'mid_term');
            $max_marks   = (float)($_POST['max_marks'] ?? 100);
            $pass_marks  = (float)($_POST['pass_marks'] ?? 40);
            $exam_date   = trim($_POST['exam_date'] ?? '');
            $semester    = (int)($_POST['semester'] ?? 1);
            $academic_year = trim($_POST['academic_year'] ?? '2025-26');
            if (!$college_id || !$dept_id || !$course_id || !$title || !$exam_date) {
                echo json_encode(['ok'=>false,'msg'=>'College, department, course, title and exam date are required']); exit;
            }
            $st = $db2->prepare('INSERT INTO exams (college_id,department_id,course_id,created_by,title,type,max_marks,pass_marks,exam_date,semester,academic_year,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$college_id,$dept_id,$course_id,$user['id'],$title,$type,$max_marks,$pass_marks,$exam_date,$semester,$academic_year,'upcoming']);
            $exam_id = $db2->lastInsertId();
            echo json_encode(['ok'=>true,'msg'=>'Exam created successfully','exam_id'=>$exam_id]);
            exit;
        }

        // ── ASSIGN SCHEDULE+HALL (superadmin assigns hall & schedule) ──
        elseif ($action === 'sa_assign_schedule') {
            $exam_id    = (int)($_POST['exam_id'] ?? 0);
            $hall_id    = (int)($_POST['hall_id'] ?? 0);
            $exam_date  = trim($_POST['exam_date'] ?? '');
            $start_time = trim($_POST['start_time'] ?? '');
            $end_time   = trim($_POST['end_time'] ?? '');
            $semester   = (int)($_POST['semester'] ?? 1);
            $academic_year = trim($_POST['academic_year'] ?? '2025-26');
            if (!$exam_id || !$hall_id || !$exam_date || !$start_time || !$end_time) {
                echo json_encode(['ok'=>false,'msg'=>'All schedule fields required']); exit;
            }
            $chk = $db2->prepare('SELECT id FROM exam_schedule WHERE exam_id=? AND status="scheduled"');
            $chk->execute([$exam_id]);
            if ($chk->fetch()) {
                // Update existing
                $st = $db2->prepare('UPDATE exam_schedule SET hall_id=?,exam_date=?,start_time=?,end_time=?,semester=?,academic_year=? WHERE exam_id=? AND status="scheduled"');
                $st->execute([$hall_id,$exam_date,$start_time,$end_time,$semester,$academic_year,$exam_id]);
            } else {
                $st = $db2->prepare('INSERT INTO exam_schedule (exam_id,hall_id,exam_date,start_time,end_time,semester,academic_year,created_by) VALUES(?,?,?,?,?,?,?,?)');
                $st->execute([$exam_id,$hall_id,$exam_date,$start_time,$end_time,$semester,$academic_year,$user['id']]);
            }
            echo json_encode(['ok'=>true,'msg'=>'Schedule assigned']);
            exit;
        }

        // ── ASSIGN INVIGILATOR (superadmin assigns faculty as invigilator) ──
        elseif ($action === 'sa_assign_invig') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $faculty_id  = (int)($_POST['faculty_id'] ?? 0);
            $hall_id     = (int)($_POST['hall_id'] ?? 0);
            $duty_type   = trim($_POST['duty_type'] ?? 'assistant');
            if (!$schedule_id || !$faculty_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule and faculty required']); exit; }
            $st = $db2->prepare('INSERT INTO exam_invigilators (schedule_id,faculty_id,hall_id,duty_type,is_confirmed,created_by) VALUES(?,?,?,?,0,?) ON DUPLICATE KEY UPDATE duty_type=VALUES(duty_type)');
            $st->execute([$schedule_id,$faculty_id,$hall_id,$duty_type,$user['id']]);
            echo json_encode(['ok'=>true,'msg'=>'Invigilator assigned']);
            exit;
        }

        // ── MARK EXAM COMPLETE (after full wizard setup) ──
        elseif ($action === 'sa_complete_exam') {
            $exam_id = (int)($_POST['exam_id'] ?? 0);
            if (!$exam_id) { echo json_encode(['ok'=>false,'msg'=>'Exam ID required']); exit; }
            $st = $db2->prepare('UPDATE exams SET status=? WHERE id=?');
            $st->execute(['scheduled', $exam_id]);
            echo json_encode(['ok'=>true,'msg'=>'Exam marked as scheduled/complete']);
            exit;
        }


        elseif ($action === 'sa_assign_seating') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $hall_id     = (int)($_POST['hall_id'] ?? 0);
            if (!$schedule_id || !$hall_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule and hall required']); exit; }
            // Get enrolled students for this exam
            $es = $db2->prepare('SELECT e.college_id,e.department_id,e.course_id,e.semester FROM exam_schedule esc JOIN exams e ON e.id=esc.exam_id WHERE esc.id=?');
            $es->execute([$schedule_id]);
            $exam = $es->fetch(PDO::FETCH_ASSOC);
            if (!$exam) { echo json_encode(['ok'=>false,'msg'=>'Schedule not found']); exit; }
            // Filter students by semester using current_semester on users
            ensureStudentSemesterColumn($db2);
            $semFilter  = $exam['semester'] ? ' AND current_semester=?' : '';
            $studParams = [$exam['college_id'], $exam['department_id']];
            if ($exam['semester']) $studParams[] = (int)$exam['semester'];
            $stud = $db2->prepare("SELECT id FROM users WHERE role='student' AND college_id=? AND department_id=? AND status='active'$semFilter ORDER BY roll_number LIMIT 200");
            $stud->execute($studParams);
            $students = $stud->fetchAll(PDO::FETCH_COLUMN);
            $seat = $db2->prepare('INSERT IGNORE INTO seating_arrangement (schedule_id,student_id,hall_id,seat_number,row_no,col_no) VALUES(?,?,?,?,?,?)');
            $count = 0;
            foreach ($students as $i => $sid) {
                $row = intdiv($i, 10) + 1; $col = ($i % 10) + 1;
                $seatNo = 'R'.$row.'C'.$col;
                $seat->execute([$schedule_id,$sid,$hall_id,$seatNo,$row,$col]);
                $count++;
            }
            echo json_encode(['ok'=>true,'msg'=>"$count students seated"]);
            exit;
        }

        // ── ISSUE ADMIT CARDS (superadmin bulk-issues admit cards) ──
        elseif ($action === 'sa_issue_admits') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            if (!$schedule_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule required']); exit; }
            $sa = $db2->prepare('SELECT student_id FROM seating_arrangement WHERE schedule_id=?');
            $sa->execute([$schedule_id]);
            $students = $sa->fetchAll(PDO::FETCH_COLUMN);
            if (empty($students)) { echo json_encode(['ok'=>false,'msg'=>'No students seated. Assign seating first.']); exit; }
            $ins = $db2->prepare('INSERT INTO admit_cards (student_id,schedule_id,admit_card_no,is_valid,created_by) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE is_valid=1');
            $count = 0;
            foreach ($students as $sid) {
                $no = 'AC-'.date('Y').'-'.str_pad(mt_rand(1,99999),5,'0',STR_PAD_LEFT);
                $ins->execute([$sid,$schedule_id,$no,$user['id']]);
                $count++;
            }
            echo json_encode(['ok'=>true,'msg'=>"$count admit cards issued"]);
            exit;
        }

        // ── GET SEATING GRID (superadmin views/edits seat assignments) ──
        elseif ($action === 'sa_get_seating_grid') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            if (!$schedule_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule required']); exit; }
            // Get exam info with hall capacity — compute rows/cols consistently
            $ei = $db2->prepare('SELECT e.college_id,e.department_id,e.course_id,e.semester,h.capacity,h.name AS hall_name,es.hall_id
                FROM exam_schedule es
                JOIN exams e ON e.id=es.exam_id
                LEFT JOIN exam_halls h ON h.id=es.hall_id
                WHERE es.id=?');
            $ei->execute([$schedule_id]);
            $exam = $ei->fetch(PDO::FETCH_ASSOC);
            if (!$exam) { echo json_encode(['ok'=>false,'msg'=>'Schedule not found']); exit; }
            // Same cols formula as get_exam_halls
            $cap = (int)($exam['capacity'] ?? 60);
            if      ($cap <= 20)  { $cols = 5; }
            elseif  ($cap <= 30)  { $cols = 6; }
            elseif  ($cap <= 48)  { $cols = 8; }
            elseif  ($cap <= 60)  { $cols = 10; }
            elseif  ($cap <= 84)  { $cols = 12; }
            else                  { $cols = 12; }
            $rows = (int)ceil($cap / $cols);
            $exam['rows'] = $rows;
            $exam['cols'] = $cols;
            // Get seated students
            $sa2 = $db2->prepare('SELECT sa.*,u.full_name,u.roll_number,d.name AS dept_name FROM seating_arrangement sa JOIN users u ON u.id=sa.student_id LEFT JOIN departments d ON d.id=u.department_id WHERE sa.schedule_id=?');
            $sa2->execute([$schedule_id]);
            $seats = $sa2->fetchAll(PDO::FETCH_ASSOC);
            // Get students for this dept scoped to the exam's semester
            ensureStudentSemesterColumn($db2);
            $semFilter2  = $exam['semester'] ? ' AND current_semester=?' : '';
            $st2Params   = [$exam['college_id'], $exam['department_id']];
            if ($exam['semester']) $st2Params[] = (int)$exam['semester'];
            $st2 = $db2->prepare("SELECT id,full_name,roll_number FROM users WHERE role='student' AND college_id=? AND department_id=? AND status='active'$semFilter2 ORDER BY roll_number,full_name");
            $st2->execute($st2Params);
            $students = $st2->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'exam'=>$exam,'seats'=>$seats,'students'=>$students]);
            exit;
        }

        // ── SAVE SINGLE SEAT (assign a student to a specific seat) ──
        elseif ($action === 'sa_save_seat') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $hall_id     = (int)($_POST['hall_id'] ?? 0);
            $student_id  = (int)($_POST['student_id'] ?? 0);
            $seat_number = trim($_POST['seat_number'] ?? '');
            $row_no      = (int)($_POST['row_no'] ?? 0);
            $col_no      = (int)($_POST['col_no'] ?? 0);
            if (!$schedule_id || !$student_id || !$seat_number) { echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit; }
            // Remove student from other seat first
            $db2->prepare('DELETE FROM seating_arrangement WHERE schedule_id=? AND student_id=?')->execute([$schedule_id,$student_id]);
            // Insert
            $ins = $db2->prepare('INSERT INTO seating_arrangement (schedule_id,student_id,hall_id,seat_number,row_no,col_no) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id)');
            $ins->execute([$schedule_id,$student_id,$hall_id,$seat_number,$row_no,$col_no]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── REMOVE SEAT ──
        elseif ($action === 'sa_remove_seat') {
            $schedule_id  = (int)($_POST['schedule_id'] ?? 0);
            $seat_number  = trim($_POST['seat_number'] ?? '');
            $db2->prepare('DELETE FROM seating_arrangement WHERE schedule_id=? AND seat_number=?')->execute([$schedule_id,$seat_number]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── MARK SEAT STATUS ──
        elseif ($action === 'sa_mark_seat_status') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $student_id  = (int)($_POST['student_id'] ?? 0);
            $is_present  = $_POST['is_present'] === '' ? null : (int)$_POST['is_present'];
            $db2->prepare('UPDATE seating_arrangement SET is_present=? WHERE schedule_id=? AND student_id=?')->execute([$is_present,$schedule_id,$student_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── GET STUDENTS FOR DEPT (for seating wizard) ──
        elseif ($action === 'sa_get_students_for_dept') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $semester   = (int)($_POST['semester']   ?? 0);
            // HOD: enforce dept restriction server-side
            if ($saCollegeRole === 'hod' && $saDeptId) { $dept_id = $saDeptId; }
            // Ensure current_semester column exists (lazy migration)
            ensureStudentSemesterColumn($db2);
            // Filter by current_semester if provided — only show students sitting this semester's exam
            $semClause = ($semester > 0) ? ' AND u.current_semester=?' : '';
            $params = [$college_id, $dept_id];
            if ($semester > 0) $params[] = $semester;
            $st = $db2->prepare("
                SELECT u.id, u.full_name, u.roll_number, d.name AS dept_name, u.current_semester
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.role='student' AND u.college_id=? AND u.department_id=? AND u.status='active'
                $semClause
                ORDER BY u.roll_number, u.full_name
            ");
            $st->execute($params);
            echo json_encode(['ok'=>true,'students'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── HOD: ALLOT YEAR / SEMESTER / SECTION TO A STUDENT ──────────
        elseif ($action === 'hod_allot_student') {
            header('Content-Type: application/json');
            // Only HOD (or super_admin acting as HOD) may call this
            if ($saCollegeRole !== 'hod' && $user['role'] !== 'super_admin') {
                echo json_encode(['ok'=>false,'msg'=>'Access denied. Only HOD can allot students.']); exit;
            }
            $student_id      = (int)($_POST['student_id']      ?? 0);
            $study_year      = (int)($_POST['study_year']      ?? 0);
            $current_semester= (int)($_POST['current_semester'] ?? 0);
            $section         = strtoupper(trim($_POST['section'] ?? ''));
            if (!$student_id) { echo json_encode(['ok'=>false,'msg'=>'Invalid student.']); exit; }
            if ($study_year < 1 || $study_year > 6) { echo json_encode(['ok'=>false,'msg'=>'Select a valid year (1–6).']); exit; }
            if ($current_semester < 1 || $current_semester > 12) { echo json_encode(['ok'=>false,'msg'=>'Select a valid semester (1–12).']); exit; }
            if ($section === '') { echo json_encode(['ok'=>false,'msg'=>'Select a section.']); exit; }
            // Verify student belongs to HOD's dept + college
            $scopeWhere = 'u.id=? AND u.role="student"';
            $scopeVals  = [$student_id];
            if ($saCollegeId) { $scopeWhere .= ' AND u.college_id=?';    $scopeVals[] = $saCollegeId; }
            if ($saDeptId)    { $scopeWhere .= ' AND u.department_id=?'; $scopeVals[] = $saDeptId; }
            $chk = $db2->prepare("SELECT id,full_name FROM users u WHERE $scopeWhere LIMIT 1");
            $chk->execute($scopeVals);
            $stuRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$stuRow) { echo json_encode(['ok'=>false,'msg'=>'Student not found or access denied.']); exit; }
            // Ensure columns exist
            try {
                $db2->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `study_year` TINYINT(1) DEFAULT NULL");
                $db2->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `section` VARCHAR(10) DEFAULT NULL");
                $db2->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `current_semester` TINYINT(2) DEFAULT NULL");
            } catch (Exception $e) {}
            $upd = $db2->prepare('UPDATE users SET study_year=?, current_semester=?, section=?, updated_at=NOW() WHERE id=?');
            $upd->execute([$study_year, $current_semester, $section, $student_id]);
            echo json_encode(['ok'=>true,'msg'=>'Allotment saved for <strong>'.htmlspecialchars($stuRow['full_name']).'</strong>.',
                'study_year'=>$study_year,'current_semester'=>$current_semester,'section'=>$section]);
            exit;
        }

        // ── HOD: GET STUDENTS FOR ALLOTMENT (with year/sem/section info) ─
        elseif ($action === 'hod_get_students_allot') {
            header('Content-Type: application/json');
            if ($saCollegeRole !== 'hod' && $user['role'] !== 'super_admin') {
                echo json_encode(['ok'=>false,'msg'=>'Access denied.']); exit;
            }
            $college_id = (int)($saCollegeId ?: ($_POST['college_id'] ?? 0));
            $dept_id    = (int)($saDeptId    ?: ($_POST['dept_id']    ?? 0));
            if (!$college_id || !$dept_id) { echo json_encode(['ok'=>false,'students'=>[]]); exit; }
            try {
                $st = $db2->prepare("SELECT u.id, u.full_name, u.roll_number,
                    COALESCE(u.study_year,0) AS study_year,
                    COALESCE(u.current_semester,0) AS current_semester,
                    COALESCE(u.section,'') AS section
                    FROM users u
                    WHERE u.role='student' AND u.college_id=? AND u.department_id=? AND u.status='active'
                    ORDER BY u.roll_number, u.full_name");
                $st->execute([$college_id, $dept_id]);
                echo json_encode(['ok'=>true,'students'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            exit;
        }

        // ── GET EXAM SCHEDULES WITH HALL (for wizard step linking) ──
        elseif ($action === 'sa_get_schedules_for_exam') {
            $exam_id = (int)($_POST['exam_id'] ?? 0);
            $st = $db2->prepare('SELECT es.*,h.name hall_name,h.capacity,CEIL(h.capacity/10) AS `rows`,LEAST(10,h.capacity) AS `cols` FROM exam_schedule es LEFT JOIN exam_halls h ON h.id=es.hall_id WHERE es.exam_id=? AND es.status="scheduled"');
            $st->execute([$exam_id]);
            echo json_encode(['ok'=>true,'schedules'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── APPROVE Q.PAPER (superadmin approves from their dashboard) ──
        elseif ($action === 'sa_approve_qpaper') {
            $id       = (int)($_POST['id'] ?? 0);
            $decision = $_POST['decision'] ?? 'approve';
            $newStatus = ($decision === 'approve') ? 'approved' : 'draft';
            // HOD: verify the paper belongs to their college + dept before approving
            if ($saCollegeRole === 'hod' && $saCollegeId && $saDeptId) {
                $chk = $db2->prepare('SELECT qp.id FROM question_papers qp JOIN exams e ON e.id=qp.exam_id WHERE qp.id=? AND qp.college_id=? AND e.department_id=?');
                $chk->execute([$id,$saCollegeId,$saDeptId]);
                if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Access denied.']); exit; }
            }
            $st = $db2->prepare('UPDATE question_papers SET status=?,approved_by=?,updated_at=NOW() WHERE id=?');
            $st->execute([$newStatus,$user['id'],$id]);
            echo json_encode(['ok'=>true,'msg'=>$decision==='approve'?'Q.Paper approved':'Sent back to draft','new_status'=>$newStatus]);
            exit;
        }

        // ── GET QUESTIONS FOR A SPECIFIC QUESTION PAPER (HOD/SA view) ──
        elseif ($action === 'sa_get_qpaper_questions') {
            $paper_id = (int)($_POST['paper_id'] ?? 0);
            if (!$paper_id) { echo json_encode(['ok'=>false,'msg'=>'Paper ID required']); exit; }

            // Fetch paper meta + instructions
            $pSt = $db2->prepare("SELECT qp.id, qp.title, qp.version, qp.status, qp.total_marks,
                       qp.total_questions, qp.instructions, qp.file_path,
                       e.title exam_title, e.exam_date,
                       c.name course_name, c.code course_code,
                       d.name dept_name, d.code dept_code,
                       col.name college_name,
                       creator.full_name  created_by_name,
                       approver.full_name approved_by_name,
                       qp.created_at, qp.updated_at
                FROM question_papers qp
                JOIN exams e   ON e.id  = qp.exam_id
                JOIN courses c ON c.id  = e.course_id
                JOIN departments d ON d.id = e.department_id
                JOIN colleges col  ON col.id = e.college_id
                LEFT JOIN users creator  ON creator.id  = qp.created_by
                LEFT JOIN users approver ON approver.id = qp.approved_by
                WHERE qp.id = ? LIMIT 1");
            $pSt->execute([$paper_id]);
            $paper = $pSt->fetch(PDO::FETCH_ASSOC);
            if (!$paper) { echo json_encode(['ok'=>false,'msg'=>'Paper not found']); exit; }

            // Fetch questions linked to this paper
            $qSt = $db2->prepare("SELECT ppq.display_order, ppq.marks_override,
                       qb.id question_id, qb.question_text, qb.question_type,
                       qb.difficulty, qb.marks, qb.topic, qb.unit_no,
                       qb.bloom_level, qb.image_url
                FROM question_paper_questions ppq
                JOIN question_bank qb ON qb.id = ppq.question_id
                WHERE ppq.paper_id = ?
                ORDER BY ppq.display_order ASC");
            $qSt->execute([$paper_id]);
            $questions = $qSt->fetchAll(PDO::FETCH_ASSOC);

            // For MCQ questions fetch options
            $qIds = array_column($questions, 'question_id');
            $options = [];
            if (!empty($qIds)) {
                $inPlaceholders = implode(',', array_fill(0, count($qIds), '?'));
                $oSt = $db2->prepare("SELECT question_id, option_text, is_correct, display_order
                    FROM question_bank_options WHERE question_id IN ($inPlaceholders)
                    ORDER BY question_id, display_order");
                $oSt->execute($qIds);
                foreach ($oSt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                    $options[$opt['question_id']][] = $opt;
                }
            }
            // Attach options to each question
            foreach ($questions as &$q) {
                $q['options'] = $options[$q['question_id']] ?? [];
            }
            unset($q);

            echo json_encode(['ok'=>true,'paper'=>$paper,'questions'=>$questions]);
            exit;
        }

        // ── LIST EXAMS FOR A DEPT (superadmin exam management view) ──
        elseif ($action === 'sa_list_exams') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['department_id'] ?? 0);
            // ── Role-based scope enforcement ──────────────────────────────
            // HOD: always lock to their assigned college + department
            if ($saCollegeRole === 'hod' && $saCollegeId) {
                $college_id = (int)$saCollegeId;
                $dept_id    = (int)$saDeptId;
            }
            // Principal: always lock to their college (can see all depts)
            elseif ($saCollegeRole === 'principal' && $saCollegeId) {
                $college_id = (int)$saCollegeId;
            }
            $params = [];
            $where = 'WHERE 1=1';
            if ($college_id) { $where .= ' AND e.college_id=?'; $params[] = $college_id; }
            if ($dept_id)    { $where .= ' AND e.department_id=?'; $params[] = $dept_id; }
            $st = $db2->prepare("SELECT e.id,e.title,e.type,e.status,e.max_marks,e.pass_marks,e.exam_date,e.semester,e.academic_year,
                    c.name course_name,c.code course_code,d.name dept_name,col.name college_name,
                    creator.full_name created_by_name, creator.role created_by_role,
                    es.exam_date sched_date,es.start_time,es.end_time,h.name hall_name,
                    (SELECT COUNT(*) FROM admit_cards ac WHERE ac.schedule_id=es.id AND ac.is_valid=1) admit_cards_issued,
                    (SELECT COUNT(*) FROM exam_invigilators ei WHERE ei.schedule_id=es.id) invig_count
                FROM exams e
                JOIN courses c ON c.id=e.course_id
                JOIN departments d ON d.id=e.department_id
                JOIN colleges col ON col.id=e.college_id
                LEFT JOIN users creator ON creator.id=e.created_by
                LEFT JOIN exam_schedule es ON es.exam_id=e.id AND es.status='scheduled'
                LEFT JOIN exam_halls h ON h.id=es.hall_id
                $where ORDER BY e.exam_date DESC LIMIT 100");
            $st->execute($params);
            echo json_encode(['ok'=>true,'exams'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── LIST PENDING Q.PAPERS ACROSS COLLEGE (superadmin dashboard) ──
        elseif ($action === 'sa_pending_qpapers') {
            // HOD: always scope to their own college + dept; SA: use posted college_id/dept_id
            if ($saCollegeRole === 'hod' && $saCollegeId && $saDeptId) {
                $college_id = (int)$saCollegeId;
                $dept_id    = (int)$saDeptId;
            } else {
                $college_id = (int)($_POST['college_id'] ?? 0);
                $dept_id    = (int)($_POST['dept_id']    ?? 0);
            }
            $where  = '';
            $params = [];
            if ($college_id) { $where .= ' AND qp.college_id=?'; $params[] = $college_id; }
            if ($dept_id)    { $where .= ' AND e.department_id=?'; $params[] = $dept_id; }
            $st = $db2->prepare("SELECT qp.id,qp.title,qp.version,qp.status,qp.created_at,qp.total_marks,
                    e.title exam_title,c.name course_name,c.code course_code,d.name dept_name,col.name college_name,
                    creator.full_name created_by_name
                FROM question_papers qp
                JOIN exams e ON e.id=qp.exam_id
                JOIN courses c ON c.id=e.course_id
                JOIN departments d ON d.id=e.department_id
                JOIN colleges col ON col.id=e.college_id
                LEFT JOIN users creator ON creator.id=qp.created_by
                WHERE qp.status='pending_approval' $where
                ORDER BY qp.created_at DESC LIMIT 50");
            $st->execute($params);
            echo json_encode(['ok'=>true,'papers'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── HOD: MARKS VIEW — Department-wise student marks (superadmin) ──
        elseif ($action === 'sa_hod_marks') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $exam_id    = (int)($_POST['exam_id']    ?? 0);
            if (!$college_id) { echo json_encode(['ok'=>false,'msg'=>'College required']); exit; }

            // Load departments for dropdown
            $deptSt = $db2->prepare("SELECT id,name,code FROM departments WHERE college_id=? AND status='active' ORDER BY name");
            $deptSt->execute([$college_id]);
            $depts = $deptSt->fetchAll(PDO::FETCH_ASSOC);

            // Load exams for dropdown
            $sql = "SELECT e.id,e.title,e.type,e.exam_date,e.max_marks,e.pass_marks,e.academic_year,
                           d.name dept_name, c.name course_name, c.code course_code
                    FROM exams e
                    JOIN courses c ON c.id=e.course_id
                    JOIN departments d ON d.id=e.department_id
                    WHERE e.college_id=?";
            $pA = [$college_id];
            if ($dept_id) { $sql .= ' AND e.department_id=?'; $pA[] = $dept_id; }
            $sql .= ' ORDER BY e.exam_date DESC LIMIT 200';
            $examSt = $db2->prepare($sql);
            $examSt->execute($pA);
            $exams = $examSt->fetchAll(PDO::FETCH_ASSOC);

            // Load marks if exam selected
            $marks = [];
            $examInfo = null;
            if ($exam_id) {
                $eiSt = $db2->prepare("SELECT e.id,e.title,e.max_marks,e.pass_marks,e.exam_date,e.type,e.academic_year,
                    d.name dept_name,d.code dept_code,c.name course_name,c.code course_code,col.name college_name
                    FROM exams e JOIN departments d ON d.id=e.department_id JOIN courses c ON c.id=e.course_id
                    JOIN colleges col ON col.id=e.college_id
                    WHERE e.id=? AND e.college_id=? LIMIT 1");
                $eiSt->execute([$exam_id,$college_id]);
                $examInfo = $eiSt->fetch(PDO::FETCH_ASSOC);

                $mSt = $db2->prepare("SELECT m.id,m.student_id,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.remarks,
                    u.full_name,u.roll_number,u.email,d.name dept_name,d.code dept_code
                    FROM marks m
                    JOIN users u ON u.id=m.student_id
                    LEFT JOIN departments d ON d.id=u.department_id
                    WHERE m.exam_id=? AND m.college_id=?
                    ORDER BY d.name,u.full_name");
                $mSt->execute([$exam_id,$college_id]);
                $marks = $mSt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['ok'=>true,'depts'=>$depts,'exams'=>$exams,'marks'=>$marks,'exam_info'=>$examInfo]);
            exit;
        }

        // ── HOD SEMESTER MARKS — semester+dept wise, all subjects, per student ──
        elseif ($action === 'sa_hod_semester_marks') {
            $college_id  = (int)($_POST['college_id']  ?? 0);
            $dept_id     = (int)($_POST['dept_id']     ?? 0);
            $semester    = (int)($_POST['semester']    ?? 0);
            $academic_yr = trim($_POST['academic_year'] ?? '');

            if (!$college_id) { echo json_encode(['ok'=>false,'msg'=>'College required']); exit; }
            if (!$dept_id)    { echo json_encode(['ok'=>false,'msg'=>'Department required']); exit; }
            if (!$semester)   { echo json_encode(['ok'=>false,'msg'=>'Semester required']); exit; }

            // Dept info
            $dSt = $db2->prepare('SELECT id,name,code FROM departments WHERE id=? AND college_id=? LIMIT 1');
            $dSt->execute([$dept_id, $college_id]);
            $deptRow = $dSt->fetch(PDO::FETCH_ASSOC);
            if (!$deptRow) { echo json_encode(['ok'=>false,'msg'=>'Department not found']); exit; }

            // All courses for this dept+semester
            $cSQL = 'SELECT id,name,code,credits FROM courses WHERE college_id=? AND department_id=? AND semester=? AND status="active"';
            $cP   = [$college_id, $dept_id, $semester];
            if ($academic_yr) { $cSQL .= ' AND academic_year=?'; $cP[] = $academic_yr; }
            $cSQL .= ' ORDER BY name';
            $cSt = $db2->prepare($cSQL);
            $cSt->execute($cP);
            $courses = $cSt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($courses)) {
                echo json_encode(['ok'=>true,'courses'=>[],'rows'=>[],'dept'=>$deptRow]);
                exit;
            }

            $courseIds = array_column($courses, 'id');
            $coursesById = [];
            foreach ($courses as $c) $coursesById[$c['id']] = $c;

            // All exams for these courses
            $inQ = implode(',', array_fill(0, count($courseIds), '?'));
            $eSt = $db2->prepare("SELECT id,course_id,title,type,max_marks,pass_marks,exam_date,semester,academic_year
                                   FROM exams WHERE college_id=? AND department_id=? AND course_id IN ($inQ) AND status != 'cancelled'
                                   ORDER BY course_id,exam_date ASC");
            $eSt->execute(array_merge([$college_id, $dept_id], $courseIds));
            $exams = $eSt->fetchAll(PDO::FETCH_ASSOC);

            // Group all exams by course_id
            $examsByCourse = []; // course_id => [exam, ...]
            foreach ($exams as $ex) {
                $examsByCourse[$ex['course_id']][] = $ex;
            }

            // Collect ALL exam IDs across all courses
            $allExamIds = array_column($exams, 'id');

            // Students in this dept
            $stSQL = 'SELECT id,full_name,roll_number,email,current_semester FROM users
                      WHERE college_id=? AND department_id=? AND role="student" AND status="active"
                      ORDER BY full_name';
            $stSt = $db2->prepare($stSQL);
            $stSt->execute([$college_id, $dept_id]);
            $students = $stSt->fetchAll(PDO::FETCH_ASSOC);

            // Marks for ALL exams (not just latest) — [exam_id][student_id] => mark row
            $marksMap = [];
            if (!empty($allExamIds)) {
                $mInQ = implode(',', array_fill(0, count($allExamIds), '?'));
                $mSt  = $db2->prepare("SELECT m.exam_id,m.student_id,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.is_pass
                                        FROM marks m WHERE m.exam_id IN ($mInQ) AND m.college_id=?");
                $mSt->execute(array_merge($allExamIds, [$college_id]));
                foreach ($mSt->fetchAll(PDO::FETCH_ASSOC) as $mk) {
                    $marksMap[$mk['exam_id']][$mk['student_id']] = $mk;
                }
            }

            // Build result rows: one row per student, aggregating ALL exams per course
            $resultRows = [];
            foreach ($students as $stu) {
                $row = [
                    'student_id'  => $stu['id'],
                    'full_name'   => $stu['full_name'],
                    'roll_number' => $stu['roll_number'] ?? '',
                    'email'       => $stu['email'] ?? '',
                    'subjects'    => [],
                ];
                $grandObt  = 0.0;
                $grandMax  = 0.0;
                $pctSum    = 0.0;
                $pctCount  = 0;
                $anyFail   = false;
                $anyAbsent = false;

                foreach ($courses as $crs) {
                    $courseExams = $examsByCourse[$crs['id']] ?? [];

                    if (empty($courseExams)) {
                        $row['subjects'][$crs['id']] = [
                            'obt' => null, 'max' => null, 'pct' => null,
                            'grade' => null, 'is_pass' => null, 'is_absent' => false, 'exam_count' => 0,
                        ];
                        continue;
                    }

                    // Aggregate marks across ALL exams for this course
                    $courseObt      = 0.0;
                    $courseMax      = 0.0;
                    $coursePctSum   = 0.0;
                    $coursePctCount = 0;
                    $courseAbsent   = false;
                    $courseFail     = false;
                    $hasAnyMark     = false;

                    foreach ($courseExams as $ex) {
                        $mk  = $marksMap[$ex['id']][$stu['id']] ?? null;
                        $max = (float)$ex['max_marks'];
                        if (!$mk) continue;
                        $hasAnyMark = true;
                        if ($mk['is_absent']) {
                            $courseAbsent = true;
                            $anyAbsent    = true;
                        } else {
                            $obt = (float)$mk['obtained_marks'];
                            $courseObt += $obt;
                            $courseMax += $max;
                            $coursePctSum   += ($max > 0 ? ($obt / $max * 100) : 0);
                            $coursePctCount++;
                        }
                        if (!$mk['is_absent'] && $mk['is_pass'] == 0) {
                            $courseFail = true;
                            $anyFail    = true;
                        }
                    }

                    $totalCourseMax = (float)array_sum(array_column($courseExams, 'max_marks'));

                    if (!$hasAnyMark) {
                        $row['subjects'][$crs['id']] = [
                            'obt' => null, 'max' => $totalCourseMax, 'pct' => null,
                            'grade' => null, 'is_pass' => null, 'is_absent' => false,
                            'exam_count' => count($courseExams),
                        ];
                        continue;
                    }

                    $coursePct = ($coursePctCount > 0) ? round(min($coursePctSum / $coursePctCount, 100), 1) : null;
                    $courseGrade = null;
                    if ($coursePct !== null) {
                        if      ($coursePct >= 90) $courseGrade = 'A+';
                        elseif  ($coursePct >= 80) $courseGrade = 'A';
                        elseif  ($coursePct >= 70) $courseGrade = 'B';
                        elseif  ($coursePct >= 60) $courseGrade = 'B-';
                        elseif  ($coursePct >= 50) $courseGrade = 'C';
                        elseif  ($coursePct >= 40) $courseGrade = 'D';
                        else                       $courseGrade = 'F';
                    }

                    $row['subjects'][$crs['id']] = [
                        'obt'        => round($courseObt, 2),
                        'max'        => $totalCourseMax,
                        'pct'        => $coursePct,
                        'grade'      => $courseGrade,
                        'is_pass'    => ($coursePct !== null) ? ($coursePct >= 40 ? 1 : 0) : null,
                        'is_absent'  => $courseAbsent,
                        'exam_count' => count($courseExams),
                    ];

                    if ($coursePctCount > 0) {
                        $grandObt += $courseObt;
                        $grandMax += $totalCourseMax;
                        $pctSum   += $coursePctSum / $coursePctCount;
                        $pctCount++;
                    }
                }

                $row['total_obt']      = $pctCount > 0 ? round($grandObt, 2) : null;
                $row['total_max']      = $pctCount > 0 ? $grandMax : null;
                $row['total_pct']      = $pctCount > 0 ? round(min($pctSum / $pctCount, 100), 1) : null;
                $row['overall_result'] = $pctCount > 0 ? ($anyFail ? 'FAIL' : 'PASS') : null;
                $row['any_fail']       = $anyFail;
                $row['any_absent']     = $anyAbsent;
                $resultRows[] = $row;
            }

            echo json_encode([
                'ok'      => true,
                'dept'    => $deptRow,
                'courses' => $courses,
                'rows'    => $resultRows,
            ]);
            exit;
        }

        // ── SA HOD COURSE MARKS — all exams for a course, per-student per-exam ──
        elseif ($action === 'sa_hod_course_marks') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $course_id  = (int)($_POST['course_id']  ?? 0);

            if (!$college_id) { echo json_encode(['ok'=>false,'msg'=>'College required']); exit; }

            // Load departments for dropdown
            $deptSt = $db2->prepare("SELECT id,name,code FROM departments WHERE college_id=? AND status='active' ORDER BY name");
            $deptSt->execute([$college_id]);
            $depts = $deptSt->fetchAll(PDO::FETCH_ASSOC);

            // Load courses for dropdown (filter by dept if given)
            $cSQL  = "SELECT id,name,code,semester,academic_year FROM courses WHERE college_id=? AND status='active'";
            $cPrms = [$college_id];
            if ($dept_id) { $cSQL .= ' AND department_id=?'; $cPrms[] = $dept_id; }
            $cSQL .= ' ORDER BY semester,name';
            $cSt = $db2->prepare($cSQL);
            $cSt->execute($cPrms);
            $courses = $cSt->fetchAll(PDO::FETCH_ASSOC);

            if (!$course_id) {
                echo json_encode(['ok'=>true,'depts'=>$depts,'courses'=>$courses,'exams'=>[],'rows'=>[],'course_info'=>null]);
                exit;
            }

            // Course info
            $ciSt = $db2->prepare("SELECT c.id,c.name,c.code,c.semester,c.academic_year,d.name dept_name,d.code dept_code
                FROM courses c LEFT JOIN departments d ON d.id=c.department_id
                WHERE c.id=? AND c.college_id=? LIMIT 1");
            $ciSt->execute([$course_id, $college_id]);
            $courseInfo = $ciSt->fetch(PDO::FETCH_ASSOC);
            if (!$courseInfo) { echo json_encode(['ok'=>false,'msg'=>'Course not found']); exit; }

            // All exams for this course (excluding cancelled)
            $eSt = $db2->prepare("SELECT id,title,type,max_marks,pass_marks,exam_date,semester,academic_year,status
                FROM exams WHERE course_id=? AND college_id=? AND status != 'cancelled'
                ORDER BY exam_date ASC, id ASC");
            $eSt->execute([$course_id, $college_id]);
            $exams = $eSt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($exams)) {
                echo json_encode(['ok'=>true,'depts'=>$depts,'courses'=>$courses,'exams'=>[],'rows'=>[],'course_info'=>$courseInfo]);
                exit;
            }

            $examIds = array_column($exams, 'id');
            $inQ     = implode(',', array_fill(0, count($examIds), '?'));

            // All marks for these exams
            $mSt = $db2->prepare("SELECT m.exam_id,m.student_id,m.obtained_marks,m.is_absent,m.grade,m.percentage,m.is_pass
                FROM marks m WHERE m.exam_id IN ($inQ) AND m.college_id=?");
            $mSt->execute(array_merge($examIds, [$college_id]));
            $marksMap = []; // [exam_id][student_id]
            foreach ($mSt->fetchAll(PDO::FETCH_ASSOC) as $mk) {
                $marksMap[$mk['exam_id']][$mk['student_id']] = $mk;
            }

            // Students enrolled in the department of this course
            $courseRow = $db2->prepare("SELECT department_id FROM courses WHERE id=? LIMIT 1");
            $courseRow->execute([$course_id]);
            $courseDeptId = (int)($courseRow->fetchColumn() ?: 0);

            $stSt = $db2->prepare("SELECT id,full_name,roll_number,email FROM users
                WHERE college_id=? AND department_id=? AND role='student' AND status='active'
                ORDER BY full_name");
            $stSt->execute([$college_id, $courseDeptId]);
            $students = $stSt->fetchAll(PDO::FETCH_ASSOC);

            // Build rows: one per student, columns = each exam
            $rows = [];
            foreach ($students as $stu) {
                $row = [
                    'student_id'  => $stu['id'],
                    'full_name'   => $stu['full_name'],
                    'roll_number' => $stu['roll_number'] ?? '',
                    'email'       => $stu['email'] ?? '',
                    'exams'       => [], // exam_id => {obt, max, pct, grade, is_pass, is_absent}
                ];
                $totalObt = 0; $totalMax = 0; $pctSum = 0.0; $subCount = 0; $anyFail = false;
                foreach ($exams as $ex) {
                    $mk  = $marksMap[$ex['id']][$stu['id']] ?? null;
                    $max = (float)$ex['max_marks'];
                    if (!$mk) {
                        $row['exams'][$ex['id']] = ['obt'=>null,'max'=>$max,'pct'=>null,'grade'=>null,'is_pass'=>null,'is_absent'=>false];
                    } else {
                        $obt = $mk['is_absent'] ? null : (float)$mk['obtained_marks'];
                        // Per-exam percentage capped at 100 for display
                        $pct = ($obt !== null && $max > 0) ? round(min($obt / $max * 100, 100), 1) : null;
                        if (!$mk['is_absent'] && $mk['is_pass'] == 0) $anyFail = true;
                        if ($obt !== null) {
                            $totalObt += $obt;
                            $totalMax += $max;
                            $pctSum   += ($max > 0 ? ($obt / $max * 100) : 0);
                            $subCount++;
                        }
                        $row['exams'][$ex['id']] = [
                            'obt'       => $obt,
                            'max'       => $max,
                            'pct'       => $pct,
                            'grade'     => $mk['grade'],
                            'is_pass'   => $mk['is_pass'],
                            'is_absent' => (bool)$mk['is_absent'],
                        ];
                    }
                }
                $row['total_obt'] = $subCount > 0 ? round($totalObt, 2) : null;
                $row['total_max'] = $subCount > 0 ? $totalMax : null;
                // Average percentage across exams with marks (always 0-100)
                $row['total_pct'] = $subCount > 0 ? round(min($pctSum / $subCount, 100), 1) : null;
                $row['any_fail']  = $anyFail;
                $rows[] = $row;
            }

            echo json_encode([
                'ok'          => true,
                'depts'       => $depts,
                'courses'     => $courses,
                'exams'       => $exams,
                'rows'        => $rows,
                'course_info' => $courseInfo,
            ]);
            exit;
        }

        // ── SA ALL Q.PAPERS — view all question papers dept/college wise ──
        elseif ($action === 'sa_all_qpapers') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $status     = trim($_POST['status']      ?? '');

            $sql = "SELECT qp.id,qp.title,qp.version,qp.status,qp.created_at,qp.updated_at,
                           qp.total_marks,qp.total_questions,
                           e.title exam_title,e.exam_date,e.type exam_type,
                           c.name course_name,c.code course_code,
                           d.name dept_name,d.code dept_code,
                           col.name college_name,col.code college_code,
                           creator.full_name created_by_name,
                           approver.full_name approved_by_name
                    FROM question_papers qp
                    JOIN exams e ON e.id=qp.exam_id
                    JOIN courses c ON c.id=e.course_id
                    JOIN departments d ON d.id=e.department_id
                    JOIN colleges col ON col.id=e.college_id
                    LEFT JOIN users creator  ON creator.id=qp.created_by
                    LEFT JOIN users approver ON approver.id=qp.approved_by
                    WHERE 1=1";
            $params = [];
            if ($college_id) { $sql .= ' AND qp.college_id=?'; $params[] = $college_id; }
            if ($dept_id)    { $sql .= ' AND e.department_id=?'; $params[] = $dept_id; }
            if ($status)     { $sql .= ' AND qp.status=?'; $params[] = $status; }
            $sql .= ' ORDER BY qp.created_at DESC LIMIT 200';
            $st = $db2->prepare($sql);
            $st->execute($params);
            $papers = $st->fetchAll(PDO::FETCH_ASSOC);

            // Dept list for filter
            $dSQL = "SELECT id,name,code FROM departments WHERE status='active'";
            $dP   = [];
            if ($college_id) { $dSQL .= ' AND college_id=?'; $dP[] = $college_id; }
            $dSQL .= ' ORDER BY name';
            $dSt = $db2->prepare($dSQL);
            $dSt->execute($dP);
            $depts = $dSt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok'=>true,'papers'=>$papers,'depts'=>$depts]);
            exit;
        }

        // ── GET INVIGILATORS FOR SCHEDULE ──
        elseif ($action === 'sa_get_invigilators') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            if (!$schedule_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule required']); exit; }
            $st = $db2->prepare('SELECT ei.*,u.full_name,u.email,u.designation FROM exam_invigilators ei JOIN users u ON u.id=ei.faculty_id WHERE ei.schedule_id=?');
            $st->execute([$schedule_id]);
            echo json_encode(['ok'=>true,'invigilators'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── REMOVE INVIGILATOR ──
        elseif ($action === 'sa_remove_invig') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $faculty_id  = (int)($_POST['faculty_id']  ?? 0);
            $db2->prepare('DELETE FROM exam_invigilators WHERE schedule_id=? AND faculty_id=?')->execute([$schedule_id,$faculty_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── EXAM STATS DASHBOARD ──────────────────────────────────────────────────
        elseif ($action === 'sa_exam_stats') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            // ── Role-based scope enforcement ──────────────────────────────
            if (($saCollegeRole === 'hod' || $saCollegeRole === 'principal') && $saCollegeId) {
                $college_id = (int)$saCollegeId;
            }
            $deptScopeId = ($saCollegeRole === 'hod' && $saDeptId) ? (int)$saDeptId : 0;
            $wBase = 'WHERE 1=1' . ($college_id ? ' AND college_id=' . $college_id : '') . ($deptScopeId ? ' AND department_id=' . $deptScopeId : '');
            $total    = $db2->query("SELECT COUNT(*) FROM exams $wBase");
            $byStatus = $db2->query("SELECT status, COUNT(*) n FROM exams $wBase GROUP BY status");
            $upcoming = $db2->query("SELECT COUNT(*) FROM exams $wBase AND status='upcoming'");
            $conflict = $db2->query("SELECT COUNT(*) FROM schedule_conflicts WHERE resolved_at IS NULL")->fetchColumn();
            echo json_encode([
                'ok'        => true,
                'total'     => (int)$total->fetchColumn(),
                'upcoming'  => (int)$upcoming->fetchColumn(),
                'conflicts' => (int)$conflict,
                'by_status' => $byStatus->fetchAll(PDO::FETCH_KEY_PAIR),
            ]);
            exit;
        }

        // ── EDIT EXAM ─────────────────────────────────────────────────────────
        elseif ($action === 'sa_edit_exam') {
            // ── Role guard: Principal cannot create/edit/delete exams (HOD can) ──
            if ($saCollegeRole === 'principal') {
                echo json_encode(['ok'=>false,'msg'=>'Principals have view-only access to exams.']);
                exit;
            }
            $id         = (int)($_POST['exam_id'] ?? 0);
            $title      = trim($_POST['title'] ?? '');
            $type       = trim($_POST['type'] ?? 'mid_term');
            $max_marks  = (float)($_POST['max_marks'] ?? 100);
            $pass_marks = (float)($_POST['pass_marks'] ?? 40);
            $exam_date  = trim($_POST['exam_date'] ?? '');
            $status     = trim($_POST['status'] ?? '');
            $remarks    = trim($_POST['remarks'] ?? '');
            if (!$id || !$title || !$exam_date) { echo json_encode(['ok'=>false,'msg'=>'Missing required fields']); exit; }
            $validStatuses = ['draft','upcoming','ongoing','completed','published','cancelled'];
            if ($status && !in_array($status, $validStatuses)) $status = 'upcoming';
            $fields = 'title=?,type=?,max_marks=?,pass_marks=?,exam_date=?,remarks=?,updated_at=NOW()';
            $params = [$title,$type,$max_marks,$pass_marks,$exam_date,$remarks];
            if ($status) { $fields .= ',status=?'; $params[] = $status; }
            $params[] = $id;
            $db2->prepare("UPDATE exams SET $fields WHERE id=?")->execute($params);
            echo json_encode(['ok'=>true,'msg'=>'Exam updated']);
            exit;
        }

        // ── DELETE EXAM ───────────────────────────────────────────────────────
        elseif ($action === 'sa_delete_exam') {
            // ── Role guard: Principal cannot create/edit/delete exams (HOD can) ──
            if ($saCollegeRole === 'principal') {
                echo json_encode(['ok'=>false,'msg'=>'Principals have view-only access to exams.']);
                exit;
            }
            $id = (int)($_POST['exam_id'] ?? 0);
            if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Exam ID required']); exit; }
            // Only allow delete if not published/completed
            $chk = $db2->prepare("SELECT status FROM exams WHERE id=?");
            $chk->execute([$id]);
            $ex = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$ex) { echo json_encode(['ok'=>false,'msg'=>'Exam not found']); exit; }
            if (in_array($ex['status'], ['published','completed'])) {
                echo json_encode(['ok'=>false,'msg'=>'Cannot delete a published or completed exam']); exit;
            }
            // Cascade clean
            $db2->prepare("DELETE FROM seating_arrangement WHERE schedule_id IN (SELECT id FROM exam_schedule WHERE exam_id=?)")->execute([$id]);
            $db2->prepare("DELETE FROM exam_invigilators WHERE schedule_id IN (SELECT id FROM exam_schedule WHERE exam_id=?)")->execute([$id]);
            $db2->prepare("DELETE FROM admit_cards WHERE schedule_id IN (SELECT id FROM exam_schedule WHERE exam_id=?)")->execute([$id]);
            $db2->prepare("DELETE FROM exam_schedule WHERE exam_id=?")->execute([$id]);
            $db2->prepare("DELETE FROM exams WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true,'msg'=>'Exam deleted']);
            exit;
        }

        // ── EXPORT ATTENDANCE CSV ─────────────────────────────────────────────
        elseif ($action === 'sa_export_attendance') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            if (!$schedule_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule required']); exit; }
            $st = $db2->prepare("SELECT u.roll_number, u.full_name, d.name dept_name, sa.seat_number,
                CASE sa.is_present WHEN 1 THEN 'Present' WHEN 0 THEN 'Absent' ELSE 'Not Marked' END AS attendance
                FROM seating_arrangement sa
                JOIN users u ON u.id=sa.student_id
                LEFT JOIN departments d ON d.id=u.department_id
                WHERE sa.schedule_id=? ORDER BY sa.row_no,sa.col_no");
            $st->execute([$schedule_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows]);
            exit;
        }

        // ── BULK MARK ATTENDANCE ─────────────────────────────────────────────
        elseif ($action === 'sa_bulk_mark_attendance') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $mark        = $_POST['mark'] ?? 'present';  // present | absent | reset
            if (!$schedule_id) { echo json_encode(['ok'=>false,'msg'=>'Schedule required']); exit; }
            $val = $mark === 'present' ? 1 : ($mark === 'absent' ? 0 : null);
            $db2->prepare("UPDATE seating_arrangement SET is_present=? WHERE schedule_id=?")->execute([$val,$schedule_id]);
            echo json_encode(['ok'=>true,'msg'=>'Attendance updated']);
            exit;
        }

        // ── GET SCHEDULE CONFLICTS ────────────────────────────────────────────
        elseif ($action === 'sa_get_conflicts') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $st = $db2->prepare("SELECT sc.*,
                    e1.title exam1_title, e2.title exam2_title
                FROM schedule_conflicts sc
                LEFT JOIN exams e1 ON e1.id=sc.exam1_id
                LEFT JOIN exams e2 ON e2.id=sc.exam2_id
                WHERE sc.resolved_at IS NULL
                ORDER BY sc.created_at DESC LIMIT 20");
            $st->execute();
            echo json_encode(['ok'=>true,'conflicts'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

                // ── GET EXAM HALLS by college ─────────────────────────────────────────
        elseif ($action === 'get_exam_halls') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            if (!$college_id) {
                $st = $db2->query("SELECT id,college_id,name,code,capacity,building,floor,has_projector,has_ac FROM exam_halls WHERE status='active' ORDER BY college_id,name");
            } else {
                $st = $db2->prepare("SELECT id,college_id,name,code,capacity,building,floor,has_projector,has_ac FROM exam_halls WHERE college_id=? AND status='active' ORDER BY name");
                $st->execute([$college_id]);
            }
            $halls = $st->fetchAll(PDO::FETCH_ASSOC);
            // Compute rows/cols: use realistic grid that fits actual capacity
            foreach ($halls as &$h) {
                $cap  = (int)$h['capacity'];
                // Choose cols so rows are roughly square-ish and ≤ 12 cols
                if      ($cap <= 20)  { $cols = 5; }
                elseif  ($cap <= 30)  { $cols = 6; }
                elseif  ($cap <= 48)  { $cols = 8; }
                elseif  ($cap <= 60)  { $cols = 10; }
                elseif  ($cap <= 84)  { $cols = 12; }
                else                  { $cols = 12; }
                $h['cols'] = $cols;
                $h['rows'] = (int)ceil($cap / $cols);
            }
            unset($h);
            echo json_encode(['ok'=>true,'halls'=>$halls]);
            exit;
        }

        // ── CREATE EXAM HALL ─────────────────────────────────────────
        elseif ($action === 'sa_create_exam_hall') {
            if ($saCollegeRole === 'principal') {
                echo json_encode(['ok'=>false,'msg'=>'Principals cannot create exam halls.']); exit;
            }
            $college_id   = (int)($_POST['college_id']   ?? $saCollegeId ?? 0);
            $name         = trim($_POST['name']          ?? '');
            $code         = strtoupper(trim($_POST['code'] ?? ''));
            $capacity     = (int)($_POST['capacity']     ?? 0);
            $building     = trim($_POST['building']      ?? '');
            $floor        = trim($_POST['floor']         ?? '');
            $has_projector= (int)($_POST['has_projector'] ?? 0);
            $has_ac       = (int)($_POST['has_ac']       ?? 0);
            if (!$college_id || !$name || $capacity < 1) {
                echo json_encode(['ok'=>false,'msg'=>'College, hall name and capacity are required.']); exit;
            }
            // Lazy-create exam_halls table if needed
            $db2->exec("CREATE TABLE IF NOT EXISTS `exam_halls` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `college_id`    INT NOT NULL,
                `name`          VARCHAR(120) NOT NULL,
                `code`          VARCHAR(20)  DEFAULT NULL,
                `capacity`      INT          NOT NULL DEFAULT 60,
                `building`      VARCHAR(80)  DEFAULT NULL,
                `floor`         VARCHAR(20)  DEFAULT NULL,
                `has_projector` TINYINT(1)   DEFAULT 0,
                `has_ac`        TINYINT(1)   DEFAULT 0,
                `status`        ENUM('active','inactive') DEFAULT 'active',
                `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $st = $db2->prepare('INSERT INTO exam_halls (college_id,name,code,capacity,building,floor,has_projector,has_ac,status) VALUES (?,?,?,?,?,?,?,?,\'active\')');
            $st->execute([$college_id,$name,$code?:null,$capacity,$building?:null,$floor?:null,$has_projector,$has_ac]);
            echo json_encode(['ok'=>true,'msg'=>'Exam hall created.','id'=>$db2->lastInsertId()]);
            exit;
        }

        // ── DELETE EXAM HALL ─────────────────────────────────────────
        elseif ($action === 'sa_delete_exam_hall') {
            if ($saCollegeRole === 'principal') {
                echo json_encode(['ok'=>false,'msg'=>'Principals cannot delete exam halls.']); exit;
            }
            $hall_id = (int)($_POST['hall_id'] ?? 0);
            if (!$hall_id) { echo json_encode(['ok'=>false,'msg'=>'Hall ID required.']); exit; }
            // Soft-delete (set inactive) so existing schedule refs stay valid
            $st = $db2->prepare("UPDATE exam_halls SET status='inactive' WHERE id=?");
            $st->execute([$hall_id]);
            echo json_encode(['ok'=>true,'msg'=>'Exam hall removed.']);
            exit;
        }

        // ── LIST ALL EXAM HALLS FOR A COLLEGE (active + inactive) ───
        elseif ($action === 'sa_list_exam_halls') {
            $college_id = (int)($_POST['college_id'] ?? $saCollegeId ?? 0);
            if (!$college_id) { echo json_encode(['ok'=>false,'msg'=>'College required.']); exit; }
            $st = $db2->prepare("SELECT id,name,code,capacity,building,floor,has_projector,has_ac,status FROM exam_halls WHERE college_id=? ORDER BY name");
            $st->execute([$college_id]);
            echo json_encode(['ok'=>true,'halls'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: get semesters for a college ──────────────────
        // ── TIMETABLE: get semesters for a college ──────────────────
        // Returns ALL tt_semesters the HOD created for this college.
        // When dept_id is given we also annotate each semester with the
        // distinct user-derived sections that exist for that dept/sem,
        // so the front-end can skip semesters with zero real students.
        elseif ($action === 'tt_get_semesters') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);

            // Always fetch every tt_semester for this college (HOD created them)
            $st = $db2->prepare(
                'SELECT id, name, status FROM tt_semesters
                  WHERE college_id = ? ORDER BY id'
            );
            $st->execute([$college_id]);
            $semesters = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($dept_id && $semesters) {
                // For each semester resolve the semester NUMBER from the name
                // e.g. "Semester II 2025-26" → 2, then count matching students
                // in users WHERE department_id=dept_id AND current_semester=num
                // A semester with 0 matching students is still shown but flagged.
                $romanMap = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,
                             'VI'=>6,'VII'=>7,'VIII'=>8,'IX'=>9,'X'=>10,
                             'XI'=>11,'XII'=>12];
                foreach ($semesters as &$sem) {
                    $num = 0;
                    if (preg_match('/Semester\s+([IVXLCDM]+|\d+)/i', $sem['name'], $m)) {
                        $tok = strtoupper($m[1]);
                        $num = isset($romanMap[$tok]) ? $romanMap[$tok] : (int)$m[1];
                    }
                    $sem['sem_num'] = $num;   // the actual semester number (1-12)
                    // Count distinct sections that exist in users for this sem
                    if ($num > 0) {
                        $sc = $db2->prepare(
                            "SELECT COUNT(DISTINCT section) AS cnt
                               FROM users
                              WHERE college_id=? AND department_id=?
                                AND current_semester=? AND role='student'
                                AND status='active' AND section IS NOT NULL AND section != ''"
                        );
                        $sc->execute([$college_id, $dept_id, $num]);
                        $sem['student_sections'] = (int)$sc->fetchColumn();
                    } else {
                        $sem['student_sections'] = 0;
                    }
                }
                unset($sem);
            }

            echo json_encode(['ok'=>true,'semesters'=>$semesters]);
            exit;
        }

        // ── TIMETABLE: get sections for college+dept+sem ────────────
        // Primary source: DISTINCT section labels from the users table
        // (real enrolled students).  Falls back to tt_sections if no
        // student records exist (so manually-created sections still work).
        elseif ($action === 'tt_get_sections') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sem_id     = (int)($_POST['sem_id']     ?? 0);

            $sections = [];

            // Resolve semester number from tt_semesters name
            $sem_num = 0;
            if ($sem_id) {
                $sn = $db2->prepare('SELECT name FROM tt_semesters WHERE id=? AND college_id=?');
                $sn->execute([$sem_id, $college_id]);
                $snRow = $sn->fetch(PDO::FETCH_ASSOC);
                if ($snRow) {
                    $romanMap2 = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,
                                  'VI'=>6,'VII'=>7,'VIII'=>8,'IX'=>9,'X'=>10,
                                  'XI'=>11,'XII'=>12];
                    if (preg_match('/Semester\s+([IVXLCDM]+|\d+)/i', $snRow['name'], $m2)) {
                        $tok2 = strtoupper($m2[1]);
                        $sem_num = isset($romanMap2[$tok2]) ? $romanMap2[$tok2] : (int)$m2[1];
                    }
                }
            }

            // ① Try users table: distinct sections for this dept+semester
            if ($dept_id && $sem_num > 0) {
                $uSt = $db2->prepare(
                    "SELECT DISTINCT section AS label,
                            COUNT(*) AS strength
                       FROM users
                      WHERE college_id=? AND department_id=?
                        AND current_semester=? AND role='student'
                        AND status='active'
                        AND section IS NOT NULL AND section != ''
                      GROUP BY section
                      ORDER BY section"
                );
                $uSt->execute([$college_id, $dept_id, $sem_num]);
                $userRows = $uSt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($userRows as $ur) {
                    // Find or create the matching tt_sections row so timetable FK works
                    $chk = $db2->prepare(
                        'SELECT id FROM tt_sections
                          WHERE college_id=? AND department_id=? AND semester_id=? AND label=?'
                    );
                    $chk->execute([$college_id, $dept_id, $sem_id, $ur['label']]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $secId = $existing['id'];
                        // Keep strength in sync with actual enrolment
                        $db2->prepare('UPDATE tt_sections SET strength=? WHERE id=?')
                            ->execute([$ur['strength'], $secId]);
                    } else {
                        // Auto-create tt_sections row so timetable can be saved
                        $db2->prepare(
                            'INSERT INTO tt_sections (college_id,department_id,semester_id,label,strength) VALUES (?,?,?,?,?)'
                        )->execute([$college_id, $dept_id, $sem_id, $ur['label'], $ur['strength']]);
                        $secId = (int)$db2->lastInsertId();
                    }
                    $sections[] = [
                        'id'       => $secId,
                        'label'    => $ur['label'],
                        'strength' => (int)$ur['strength'],
                        'source'   => 'students',
                    ];
                }
            }

            // ② Fallback: if no student records found, use tt_sections directly
            if (empty($sections)) {
                $sql = 'SELECT id, label, strength FROM tt_sections WHERE college_id=? AND department_id=?';
                $params = [$college_id, $dept_id];
                if ($sem_id) { $sql .= ' AND semester_id=?'; $params[] = $sem_id; }
                $sql .= ' ORDER BY label';
                $fbSt = $db2->prepare($sql);
                $fbSt->execute($params);
                $sections = $fbSt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($sections as &$s) { $s['source'] = 'manual'; }
                unset($s);
            }

            echo json_encode(['ok'=>true,'sections'=>$sections]);
            exit;
        }

        // ── TIMETABLE: get periods for a college ───────────────────
        elseif ($action === 'tt_get_periods') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $st = $db2->prepare('SELECT id,label,type,start_time,end_time,sort_order FROM tt_periods WHERE college_id=? ORDER BY sort_order');
            $st->execute([$college_id]);
            echo json_encode(['ok'=>true,'periods'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: get rooms for a college ─────────────────────
        elseif ($action === 'tt_get_rooms') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $st = $db2->prepare("SELECT id,name,type,block FROM tt_rooms WHERE college_id=? AND status='active' ORDER BY type,name");
            $st->execute([$college_id]);
            echo json_encode(['ok'=>true,'rooms'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: get courses for dept ────────────────────────
        elseif ($action === 'tt_get_courses') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sem_id     = (int)($_POST['sem_id']     ?? 0);
            // When a tt_semester is selected, resolve its semester number from the
            // name (e.g. "Semester II 2025-26" → sem number 2) so we can filter
            // courses by their `semester` column. Fall back to all dept courses if
            // the name doesn't encode a recognisable ordinal.
            $semClause  = '';
            $semParams  = [$college_id, $dept_id];
            if ($sem_id) {
                $sn = $db2->prepare('SELECT name FROM tt_semesters WHERE id=? AND college_id=?');
                $sn->execute([$sem_id, $college_id]);
                $semRow = $sn->fetch(PDO::FETCH_ASSOC);
                if ($semRow) {
                    // Match Roman or Arabic numeral after "Semester "
                    $map = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6,'VII'=>7,'VIII'=>8];
                    if (preg_match('/Semester\s+([IVX]+|\d+)/i', $semRow['name'], $m)) {
                        $num = isset($map[strtoupper($m[1])]) ? $map[strtoupper($m[1])] : (int)$m[1];
                        if ($num > 0) {
                            $semClause  = ' AND semester=?';
                            $semParams[] = $num;
                        }
                    }
                }
            }
            $st = $db2->prepare("SELECT id,name,code,semester FROM courses WHERE college_id=? AND department_id=? AND status='active'{$semClause} ORDER BY semester,name");
            $st->execute($semParams);
            echo json_encode(['ok'=>true,'courses'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: get faculty for dept ────────────────────────
        elseif ($action === 'tt_get_faculty') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $st = $db2->prepare("SELECT id,full_name,designation FROM users WHERE college_id=? AND department_id=? AND role='faculty' AND status='active' ORDER BY full_name");
            $st->execute([$college_id, $dept_id]);
            echo json_encode(['ok'=>true,'faculty'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: find or load timetable ─────────────────────
        elseif ($action === 'tt_find') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sem_id     = (int)($_POST['sem_id']     ?? 0);
            $sec_id     = (int)($_POST['sec_id']     ?? 0);
            $st = $db2->prepare('SELECT id,status,academic_year FROM timetables WHERE college_id=? AND department_id=? AND semester_id=? AND section_id=? LIMIT 1');
            $st->execute([$college_id, $dept_id, $sem_id, $sec_id]);
            echo json_encode(['ok'=>true,'timetable'=>$st->fetch(PDO::FETCH_ASSOC) ?: null]);
            exit;
        }

        // ── TIMETABLE: load slots for a timetable ─────────────────
        elseif ($action === 'tt_load_slots') {
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $st = $db2->prepare('
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
            $st->execute([$tt_id]);
            echo json_encode(['ok'=>true,'slots'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: create timetable ────────────────────────────
        elseif ($action === 'tt_create') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sem_id     = (int)($_POST['sem_id']     ?? 0);
            $sec_id     = (int)($_POST['sec_id']     ?? 0);
            $acad_year  = trim($_POST['acad_year']   ?? date('Y').'-'.substr((date('Y')+1),2));
            if (!$college_id||!$dept_id||!$sem_id||!$sec_id) {
                echo json_encode(['ok'=>false,'msg'=>'College, Department, Semester and Section required']); exit;
            }
            $chk = $db2->prepare('SELECT id FROM timetables WHERE college_id=? AND department_id=? AND semester_id=? AND section_id=?');
            $chk->execute([$college_id,$dept_id,$sem_id,$sec_id]);
            if ($row=$chk->fetch()) {
                echo json_encode(['ok'=>true,'tt_id'=>$row['id'],'existing'=>true]); exit;
            }
            $db2->prepare("INSERT INTO timetables (college_id,department_id,semester_id,section_id,academic_year,status,created_by) VALUES (?,?,?,?,?,'draft',?)")
                ->execute([$college_id,$dept_id,$sem_id,$sec_id,$acad_year,$user['id']]);
            echo json_encode(['ok'=>true,'tt_id'=>$db2->lastInsertId(),'existing'=>false]);
            exit;
        }

        // ── TIMETABLE: save slot ───────────────────────────────────
        elseif ($action === 'tt_save_slot') {
            $tt_id     = (int)($_POST['tt_id']      ?? 0);
            $period_id = (int)($_POST['period_id']  ?? 0);
            $day       = trim($_POST['day']          ?? '');
            $course_id = (int)($_POST['course_id']  ?? 0) ?: null;
            $fac_id    = (int)($_POST['faculty_id'] ?? 0) ?: null;
            $room_id   = (int)($_POST['room_id']    ?? 0) ?: null;
            if (!$tt_id || !$period_id || !$day) {
                echo json_encode(['ok'=>false,'msg'=>'Missing required slot fields']); exit;
            }
            // Safe upsert: delete existing row first, then insert fresh.
            // This avoids relying on a DB UNIQUE constraint being present.
            $db2->prepare('DELETE FROM tt_slots WHERE timetable_id=? AND period_id=? AND day=?')
                ->execute([$tt_id, $period_id, $day]);
            $db2->prepare('INSERT INTO tt_slots (timetable_id,period_id,day,course_id,faculty_id,room_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tt_id, $period_id, $day, $course_id, $fac_id, $room_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── TIMETABLE: clear slot ──────────────────────────────────
        elseif ($action === 'tt_clear_slot') {
            $tt_id     = (int)($_POST['tt_id']     ?? 0);
            $period_id = (int)($_POST['period_id'] ?? 0);
            $day       = trim($_POST['day']         ?? '');
            $db2->prepare('DELETE FROM tt_slots WHERE timetable_id=? AND period_id=? AND day=?')->execute([$tt_id,$period_id,$day]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── TIMETABLE: clear all slots ─────────────────────────────
        elseif ($action === 'tt_clear_all') {
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $db2->prepare('DELETE FROM tt_slots WHERE timetable_id=?')->execute([$tt_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── TIMETABLE: activate ────────────────────────────────────
        elseif ($action === 'tt_activate') {
            $tt_id = (int)($_POST['tt_id'] ?? 0);
            $db2->prepare("UPDATE timetables SET status='active',updated_at=current_timestamp() WHERE id=?")->execute([$tt_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── TIMETABLE: list all timetables for a college ───────────
        elseif ($action === 'tt_list') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sql = 'SELECT t.id, t.status, t.academic_year,
                           t.department_id, t.semester_id, t.section_id,
                           d.name AS dept_name, d.code AS dept_code,
                           sm.name AS sem_name, sec.label AS sec_label, sec.strength,
                           (SELECT COUNT(*) FROM tt_slots sl WHERE sl.timetable_id=t.id) AS slot_count
                    FROM timetables t
                    JOIN departments d   ON d.id  = t.department_id
                    JOIN tt_semesters sm ON sm.id = t.semester_id
                    JOIN tt_sections  sec ON sec.id = t.section_id
                    WHERE t.college_id=?';
            $params = [$college_id];
            if ($dept_id) { $sql .= ' AND t.department_id=?'; $params[] = $dept_id; }
            $sql .= ' ORDER BY d.name, sm.name, sec.label';
            $st = $db2->prepare($sql);
            $st->execute($params);
            echo json_encode(['ok'=>true,'timetables'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── TIMETABLE: auto-generate ───────────────────────────────
        elseif ($action === 'tt_auto_generate') {
            $tt_id      = (int)($_POST['tt_id']      ?? 0);
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $db2->prepare('DELETE FROM tt_slots WHERE timetable_id=?')->execute([$tt_id]);
            $periods = $db2->prepare("SELECT id FROM tt_periods WHERE college_id=? AND type='Period' ORDER BY sort_order");
            $periods->execute([$college_id]); $periods = $periods->fetchAll(PDO::FETCH_ASSOC);
            $courses = $db2->prepare("SELECT id FROM courses WHERE college_id=? AND department_id=? AND status='active'");
            $courses->execute([$college_id,$dept_id]); $courses = $courses->fetchAll(PDO::FETCH_ASSOC);
            $faculty = $db2->prepare("SELECT id FROM users WHERE college_id=? AND department_id=? AND role='faculty' AND status='active'");
            $faculty->execute([$college_id,$dept_id]); $faculty = $faculty->fetchAll(PDO::FETCH_ASSOC);
            $rooms = $db2->prepare("SELECT id FROM tt_rooms WHERE college_id=? AND status='active'");
            $rooms->execute([$college_id]); $rooms = $rooms->fetchAll(PDO::FETCH_ASSOC);
            if (!$periods||!$courses||!$faculty||!$rooms) {
                echo json_encode(['ok'=>false,'msg'=>'Ensure courses, faculty and rooms exist first.']); exit;
            }
            $days=['Mon','Tue','Wed','Thu','Fri','Sat'];
            $si=0;$fi=0;$ri=0;
            $stmt=$db2->prepare('INSERT IGNORE INTO tt_slots (timetable_id,period_id,day,course_id,faculty_id,room_id) VALUES (?,?,?,?,?,?)');
            foreach ($periods as $p) {
                foreach ($days as $d) {
                    if ($d==='Sat'&&rand(0,1)) continue;
                    $stmt->execute([$tt_id,$p['id'],$d,$courses[$si%count($courses)]['id'],$faculty[$fi%count($faculty)]['id'],$rooms[$ri%count($rooms)]['id']]);
                    $si++;$fi++;$ri++;
                }
            }
            $db2->prepare("UPDATE timetables SET status='active' WHERE id=?")->execute([$tt_id]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── TIMETABLE: add semester ────────────────────────────────
        elseif ($action === 'tt_add_semester') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $name       = trim($_POST['name']        ?? '');
            $start      = $_POST['start_date']       ?? null;
            $end        = $_POST['end_date']          ?? null;
            $status     = $_POST['status']            ?? 'Upcoming';
            if (!$college_id||!$name) { echo json_encode(['ok'=>false,'msg'=>'College and name required']); exit; }
            $db2->prepare('INSERT INTO tt_semesters (college_id,name,start_date,end_date,status) VALUES (?,?,?,?,?)')
                ->execute([$college_id,$name,$start?:null,$end?:null,$status]);
            echo json_encode(['ok'=>true,'id'=>$db2->lastInsertId()]);
            exit;
        }

        // ── TIMETABLE: add section ─────────────────────────────────
        elseif ($action === 'tt_add_section') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $dept_id    = (int)($_POST['dept_id']    ?? 0);
            $sem_id     = (int)($_POST['sem_id']     ?? 0);
            $label      = strtoupper(trim($_POST['label']   ?? ''));
            $strength   = (int)($_POST['strength']   ?? 60);
            if (!$college_id||!$dept_id||!$sem_id||!$label) { echo json_encode(['ok'=>false,'msg'=>'All fields required']); exit; }
            $db2->prepare('INSERT INTO tt_sections (college_id,department_id,semester_id,label,strength) VALUES (?,?,?,?,?)')
                ->execute([$college_id,$dept_id,$sem_id,$label,$strength]);
            echo json_encode(['ok'=>true,'id'=>$db2->lastInsertId()]);
            exit;
        }

        // ── TIMETABLE: add period ──────────────────────────────────
        elseif ($action === 'tt_add_period') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $label      = trim($_POST['label']        ?? '');
            $type       = $_POST['type']               ?? 'Period';
            $start      = $_POST['start_time']         ?? '';
            $end        = $_POST['end_time']            ?? '';
            $sort       = (int)($_POST['sort_order']   ?? 1);
            if (!$college_id||!$label||!$start||!$end) { echo json_encode(['ok'=>false,'msg'=>'All fields required']); exit; }
            $db2->prepare('INSERT INTO tt_periods (college_id,label,type,start_time,end_time,sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$college_id,$label,$type,$start,$end,$sort]);
            echo json_encode(['ok'=>true,'id'=>$db2->lastInsertId()]);
            exit;
        }

        // ── TIMETABLE: add room ────────────────────────────────────
        elseif ($action === 'tt_add_room') {
            $college_id = (int)($_POST['college_id'] ?? 0);
            $name       = trim($_POST['name']         ?? '');
            $type       = $_POST['type']               ?? 'Classroom';
            $block      = trim($_POST['block']         ?? '');
            $capacity   = (int)($_POST['capacity']    ?? 60);
            if (!$college_id||!$name) { echo json_encode(['ok'=>false,'msg'=>'College and room name required']); exit; }
            $db2->prepare("INSERT INTO tt_rooms (college_id,name,type,block,capacity,status) VALUES (?,?,?,?,?,'active')")
                ->execute([$college_id,$name,$type,$block?:null,$capacity]);
            echo json_encode(['ok'=>true,'id'=>$db2->lastInsertId()]);
            exit;
        }

        // ══════════════════════════════════════════════════════════════════════
        // ACCOUNTANT CREDENTIAL MANAGEMENT
        // Created by principal (super_admin in college context) for their college
        // ══════════════════════════════════════════════════════════════════════

        // ── CREATE / RESET ACCOUNTANT CREDENTIAL ─────────────────────────────
        elseif ($action === 'create_accountant_credential') {
            $col_id  = (int)($_POST['college_id']  ?? 0);
            $uname   = trim($_POST['username']     ?? '');
            $pwd     = trim($_POST['password']     ?? '');
            $fullNm  = trim($_POST['full_name']    ?? 'Accountant');
            $acEmail = trim($_POST['email']        ?? '');
            $acPhone = trim($_POST['phone']        ?? '');

            if (!$col_id || !$uname || !$pwd) {
                echo json_encode(['ok' => false, 'msg' => 'College, username and password are required.']); exit;
            }
            if (strlen($uname) < 4) {
                echo json_encode(['ok' => false, 'msg' => 'Username must be at least 4 characters.']); exit;
            }
            if (strlen($pwd) < 8 || !preg_match('/[A-Z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
                echo json_encode(['ok' => false, 'msg' => 'Password must be ≥8 chars with at least 1 uppercase letter and 1 number.']); exit;
            }

            // Auto-create table if missing (handles case where migration hasn't run yet)
            try {
                $db2->exec('CREATE TABLE IF NOT EXISTS `account_credentials` (
                    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
                    `college_id`    INT(11)      NOT NULL,
                    `username`      VARCHAR(60)  NOT NULL,
                    `password_hash` VARCHAR(255) NOT NULL,
                    `full_name`     VARCHAR(150) NOT NULL DEFAULT "Accountant",
                    `email`         VARCHAR(100) DEFAULT NULL,
                    `phone`         VARCHAR(20)  DEFAULT NULL,
                    `created_by`    INT(11)      DEFAULT NULL,
                    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
                    `last_login`    DATETIME     DEFAULT NULL,
                    `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_acc_username` (`username`),
                    KEY `idx_acc_college` (`college_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            } catch (Exception $tblEx) { /* table already exists */ }

            // Verify college exists and belongs to principal's scope
            $cChk = $db2->prepare('SELECT id, name FROM colleges WHERE id = ? AND status = "active" LIMIT 1');
            $cChk->execute([$col_id]);
            if (!$cChk->fetch()) {
                echo json_encode(['ok' => false, 'msg' => 'College not found or inactive.']); exit;
            }

            // If principal/saCollegeId is set, ensure they can only create for their own college
            if ($saCollegeId && $col_id !== $saCollegeId) {
                echo json_encode(['ok' => false, 'msg' => 'You can only create credentials for your own college.']); exit;
            }

            // Check username uniqueness
            $uChk = $db2->prepare('SELECT id FROM account_credentials WHERE username = ? LIMIT 1');
            $uChk->execute([$uname]);
            if ($uChk->fetch()) {
                echo json_encode(['ok' => false, 'msg' => 'Username already exists. Choose a different one.']); exit;
            }

            // Deactivate existing active credentials for this college
            $db2->prepare('UPDATE account_credentials SET is_active = 0 WHERE college_id = ? AND is_active = 1')
                ->execute([$col_id]);

            // Insert new credential
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $ins  = $db2->prepare('INSERT INTO account_credentials (college_id, username, password_hash, full_name, email, phone, created_by, is_active) VALUES (?,?,?,?,?,?,?,1)');
            $ins->execute([$col_id, $uname, $hash, $fullNm ?: 'Accountant', $acEmail ?: null, $acPhone ?: null, $user['id']]);
            $resp = ['ok' => true, 'msg' => 'Accountant credential created successfully.', 'id' => (int)$db2->lastInsertId()];
        }

        // ── GET ACCOUNTANT CREDENTIALS FOR A COLLEGE ─────────────────────────
        elseif ($action === 'get_accountant_credentials') {
            $col_id = (int)($_POST['college_id'] ?? 0);
            if (!$col_id) { echo json_encode(['ok' => false, 'msg' => 'College ID required.']); exit; }

            // Security: principal can only query their own college
            if ($saCollegeId && $col_id !== $saCollegeId) {
                echo json_encode(['ok' => false, 'msg' => 'Access denied.']); exit;
            }

            // Gracefully handle table-not-found (migration not run yet)
            try {
                $st = $db2->prepare('SELECT id, username, full_name, email, phone, is_active, last_login, created_at FROM account_credentials WHERE college_id = ? ORDER BY created_at DESC');
                $st->execute([$col_id]);
                $resp = ['ok' => true, 'credentials' => $st->fetchAll(PDO::FETCH_ASSOC)];
            } catch (Exception $tblEx) {
                // Table doesn't exist yet — return empty with setup hint
                $resp = ['ok' => true, 'credentials' => [], 'setup_needed' => true,
                         'hint' => 'Run migration_account_credentials.sql first, or click "Create Credential" to auto-create the table.'];
            }
        }

        // ── TOGGLE ACCOUNTANT CREDENTIAL STATUS ──────────────────────────────
        elseif ($action === 'toggle_accountant_status') {
            $cred_id = (int)($_POST['cred_id']   ?? 0);
            $status  = (int)($_POST['is_active']  ?? 0);
            if (!$cred_id) { echo json_encode(['ok' => false, 'msg' => 'Credential ID required.']); exit; }

            // Security: verify credential belongs to principal's college
            if ($saCollegeId) {
                $ownerChk = $db2->prepare('SELECT college_id FROM account_credentials WHERE id = ?');
                $ownerChk->execute([$cred_id]);
                $owner = $ownerChk->fetch(PDO::FETCH_ASSOC);
                if (!$owner || (int)$owner['college_id'] !== $saCollegeId) {
                    echo json_encode(['ok' => false, 'msg' => 'Access denied.']); exit;
                }
            }

            $db2->prepare('UPDATE account_credentials SET is_active = ? WHERE id = ?')->execute([$status ? 1 : 0, $cred_id]);
            $resp = ['ok' => true, 'msg' => $status ? 'Credential activated.' : 'Credential deactivated.'];
        }

        // ── STAFF NOTICEBOARD: list notices (scoped to selected college/dept) ─
        elseif ($action === 'sn_list') {
            $filterCollegeId = (int)($_POST['college_id'] ?? 0) ?: $saCollegeId;
            $fType = trim($_POST['type']     ?? 'all');
            $fPrio = trim($_POST['priority'] ?? 'all');
            $fShow = trim($_POST['show']     ?? 'active');

            $sql = '
                SELECT n.*,
                       u.full_name  AS created_by_name,
                       d.name       AS department_name,
                       c.name       AS college_name
                FROM staff_notices n
                LEFT JOIN users       u ON u.id = n.created_by
                LEFT JOIN departments d ON d.id = n.department_id
                LEFT JOIN colleges    c ON c.id = n.college_id
                WHERE 1=1
            ';
            $params = [];

            if ($fShow === 'active') {
                $sql .= ' AND n.is_active = 1 AND (n.expiry_date IS NULL OR n.expiry_date >= CURDATE())';
            }
            if ($filterCollegeId) {
                $sql .= ' AND n.college_id = ?';
                $params[] = $filterCollegeId;
            }
            if ($saCollegeRole === 'hod' && $saDeptId) {
                $sql .= ' AND (n.target_audience IN ("all","faculty","college_specific") OR (n.target_audience="department_specific" AND n.department_id=?))';
                $params[] = $saDeptId;
            }
            if ($fType !== 'all') { $sql .= ' AND n.notice_type = ?'; $params[] = $fType; }
            if ($fPrio !== 'all') { $sql .= ' AND n.priority = ?';    $params[] = $fPrio; }
            $sql .= ' ORDER BY FIELD(n.priority,"critical","high","normal","low"), n.publish_date DESC LIMIT 120';

            $st = $db2->prepare($sql);
            $st->execute($params);
            $notices = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'notices' => $notices]);
            exit;
        }

        // ── STAFF NOTICEBOARD: create notice ───────────────────────────────
        elseif ($action === 'sn_create') {
            $targetCollegeId = (int)($_POST['college_id'] ?? 0) ?: $saCollegeId;
            $title   = trim($_POST['title']           ?? '');
            $content = trim($_POST['content']         ?? '');
            $type    = trim($_POST['notice_type']     ?? 'general');
            $prio    = trim($_POST['priority']        ?? 'normal');
            $tgt     = trim($_POST['target_audience'] ?? 'all');
            $expiry  = trim($_POST['expiry_date'] ?? '') ?: null;
            $dTarget = intval($_POST['department_id'] ?? 0) ?: null;

            if (!$targetCollegeId) { echo json_encode(['ok'=>false,'msg'=>'College is required.']); exit; }
            if (!$title || !$content) { echo json_encode(['ok'=>false,'msg'=>'Title and content are required.']); exit; }

            // HOD: force dept scope
            if ($saCollegeRole === 'hod') {
                $tgt     = 'department_specific';
                $dTarget = $saDeptId ?: $dTarget;
            }

            $st = $db2->prepare('
                INSERT INTO staff_notices
                    (title,content,notice_type,priority,target_audience,department_id,college_id,expiry_date,created_by,publish_date)
                VALUES (?,?,?,?,?,?,?,?,?,CURDATE())
            ');
            $st->execute([$title,$content,$type,$prio,$tgt,$dTarget,$targetCollegeId,$expiry,$user['id']]);
            echo json_encode(['ok'=>true,'msg'=>'Notice published successfully.','id'=>(int)$db2->lastInsertId()]);
            exit;
        }

        // ── STAFF NOTICEBOARD: update notice ───────────────────────────────
        elseif ($action === 'sn_update') {
            $nid     = (int)($_POST['notice_id'] ?? 0);
            $title   = trim($_POST['title']           ?? '');
            $content = trim($_POST['content']         ?? '');
            $type    = trim($_POST['notice_type']     ?? 'general');
            $prio    = trim($_POST['priority']        ?? 'normal');
            $tgt     = trim($_POST['target_audience'] ?? 'all');
            $expiry  = trim($_POST['expiry_date'] ?? '') ?: null;
            $dTarget = intval($_POST['department_id'] ?? 0) ?: null;
            $cTarget = (int)($_POST['college_id'] ?? 0) ?: $saCollegeId;

            if (!$nid || !$title || !$content) { echo json_encode(['ok'=>false,'msg'=>'Notice ID, title and content are required.']); exit; }

            if ($saCollegeRole === 'hod') {
                $tgt = 'department_specific'; $dTarget = $saDeptId;
                $db2->prepare('UPDATE staff_notices SET title=?,content=?,notice_type=?,priority=?,target_audience=?,department_id=?,expiry_date=? WHERE id=? AND college_id=?')
                    ->execute([$title,$content,$type,$prio,$tgt,$dTarget,$expiry,$nid,$saCollegeId]);
            } elseif ($saCollegeRole === 'principal') {
                $db2->prepare('UPDATE staff_notices SET title=?,content=?,notice_type=?,priority=?,target_audience=?,department_id=?,expiry_date=? WHERE id=? AND college_id=?')
                    ->execute([$title,$content,$type,$prio,$tgt,$dTarget,$expiry,$nid,$saCollegeId]);
            } else {
                $db2->prepare('UPDATE staff_notices SET title=?,content=?,notice_type=?,priority=?,target_audience=?,department_id=?,college_id=?,expiry_date=? WHERE id=?')
                    ->execute([$title,$content,$type,$prio,$tgt,$dTarget,$cTarget,$expiry,$nid]);
            }
            echo json_encode(['ok'=>true,'msg'=>'Notice updated successfully.']);
            exit;
        }

        // ── STAFF NOTICEBOARD: delete notice ───────────────────────────────
        elseif ($action === 'sn_delete') {
            $nid = (int)($_POST['notice_id'] ?? 0);
            if (!$nid) { echo json_encode(['ok'=>false,'msg'=>'Notice ID required.']); exit; }
            if ($saCollegeId) {
                $db2->prepare('DELETE FROM staff_notices WHERE id=? AND college_id=?')->execute([$nid, $saCollegeId]);
            } else {
                $db2->prepare('DELETE FROM staff_notices WHERE id=?')->execute([$nid]);
            }
            echo json_encode(['ok'=>true,'msg'=>'Notice deleted.']);
            exit;
        }

        // ── STAFF NOTICEBOARD: toggle active ───────────────────────────────
        elseif ($action === 'sn_toggle') {
            $nid = (int)($_POST['notice_id'] ?? 0);
            if (!$nid) { echo json_encode(['ok'=>false,'msg'=>'Notice ID required.']); exit; }
            if ($saCollegeId) {
                $db2->prepare('UPDATE staff_notices SET is_active = NOT is_active WHERE id=? AND college_id=?')->execute([$nid, $saCollegeId]);
            } else {
                $db2->prepare('UPDATE staff_notices SET is_active = NOT is_active WHERE id=?')->execute([$nid]);
            }
            $st2 = $db2->prepare('SELECT is_active FROM staff_notices WHERE id=?');
            $st2->execute([$nid]);
            $row2 = $st2->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'msg'=>'Status toggled.','is_active'=>(int)($row2['is_active']??0)]);
            exit;
        }

        // ── COLLEGE PROFILE: get ────────────────────────────────────────────
        elseif ($action === 'get_college_profile') {
            $cid = (int)($_POST['college_id'] ?? 0) ?: (int)$saCollegeId;
            if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'No college selected']); exit; }
            // Lazy-migrate: add extra columns if they don't exist yet
            try {
                $db2->exec("ALTER TABLE colleges
                    ADD COLUMN IF NOT EXISTS `banner_path`      VARCHAR(255) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `principal_name`   VARCHAR(150) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `principal_email`  VARCHAR(150) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `principal_phone`  VARCHAR(30)  DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `college_type`     VARCHAR(80)  DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `affiliation`      VARCHAR(200) DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `naac_grade`       VARCHAR(10)  DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `vision`           TEXT         DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `mission`          TEXT         DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `facilities`       TEXT         DEFAULT NULL,
                    ADD COLUMN IF NOT EXISTS `tagline`          VARCHAR(255) DEFAULT NULL
                ");
            } catch (Exception $me) { /* columns may already exist */ }
            $st = $db2->prepare('SELECT id,name,code,address,phone,email,website,logo_path,established,status,
                                        banner_path,principal_name,principal_email,principal_phone,
                                        college_type,affiliation,naac_grade,vision,mission,facilities,tagline
                                 FROM colleges WHERE id=? LIMIT 1');
            $st->execute([$cid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok'=>false,'msg'=>'College not found']); exit; }
            echo json_encode(['ok'=>true,'profile'=>$row]);
            exit;
        }

        // ── COLLEGE PROFILE: save ────────────────────────────────────────────
        elseif ($action === 'save_college_profile') {
            $cid = (int)($_POST['college_id'] ?? 0) ?: (int)$saCollegeId;
            if (!$cid) { echo json_encode(['ok'=>false,'msg'=>'No college selected']); exit; }
            // Superadmin or principal of this college allowed
            if ($saCollegeId && $saCollegeId !== $cid) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }

            $fields = [
                'name'            => trim($_POST['name']            ?? ''),
                'phone'           => trim($_POST['phone']           ?? ''),
                'email'           => trim($_POST['email']           ?? ''),
                'website'         => trim($_POST['website']         ?? ''),
                'address'         => trim($_POST['address']         ?? ''),
                'established'     => trim($_POST['established']     ?? '') ?: null,
                'principal_name'  => trim($_POST['principal_name']  ?? ''),
                'principal_email' => trim($_POST['principal_email'] ?? ''),
                'principal_phone' => trim($_POST['principal_phone'] ?? ''),
                'college_type'    => trim($_POST['college_type']    ?? ''),
                'affiliation'     => trim($_POST['affiliation']     ?? ''),
                'naac_grade'      => strtoupper(trim($_POST['naac_grade'] ?? '')),
                'vision'          => trim($_POST['vision']          ?? ''),
                'mission'         => trim($_POST['mission']         ?? ''),
                'facilities'      => trim($_POST['facilities']      ?? ''),
                'tagline'         => trim($_POST['tagline']         ?? ''),
            ];
            if (!$fields['name']) { echo json_encode(['ok'=>false,'msg'=>'College name is required']); exit; }

            // Handle logo upload
            $uploadDir = __DIR__ . '/../uploads/college_assets/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

            $logoPath  = null;
            $bannerPath = null;

            if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    echo json_encode(['ok'=>false,'msg'=>'Invalid logo format. Allowed: jpg,png,gif,webp,svg']); exit;
                }
                if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                    echo json_encode(['ok'=>false,'msg'=>'Logo file too large (max 2 MB)']); exit;
                }
                $logoFile = 'logo_' . $cid . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoFile)) {
                    $logoPath = $logoFile;
                }
            }

            if (!empty($_FILES['banner']['tmp_name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    echo json_encode(['ok'=>false,'msg'=>'Invalid banner format. Allowed: jpg,png,gif,webp']); exit;
                }
                if ($_FILES['banner']['size'] > 5 * 1024 * 1024) {
                    echo json_encode(['ok'=>false,'msg'=>'Banner file too large (max 5 MB)']); exit;
                }
                $bannerFile = 'banner_' . $cid . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['banner']['tmp_name'], $uploadDir . $bannerFile)) {
                    $bannerPath = $bannerFile;
                }
            }

            $sets   = [];
            $vals   = [];
            foreach ($fields as $col => $val) { $sets[] = "`$col`=?"; $vals[] = $val; }
            if ($logoPath  !== null) { $sets[] = '`logo_path`=?';   $vals[] = $logoPath; }
            if ($bannerPath !== null) { $sets[] = '`banner_path`=?'; $vals[] = $bannerPath; }
            $vals[] = $cid;
            $db2->prepare('UPDATE colleges SET ' . implode(',',$sets) . ' WHERE id=?')->execute($vals);

            // Fetch updated row to return
            $st2 = $db2->prepare('SELECT logo_path,banner_path FROM colleges WHERE id=? LIMIT 1');
            $st2->execute([$cid]);
            $updated = $st2->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'msg'=>'College profile saved successfully',
                'logo_path'=>$updated['logo_path'],'banner_path'=>$updated['banner_path']]);
            exit;
        }

        // ── STAFF NOTICEBOARD: get departments for notice form ─────────────
        elseif ($action === 'sn_get_depts') {
            $cid = (int)($_POST['college_id'] ?? 0) ?: $saCollegeId;
            if (!$cid) { echo json_encode(['ok'=>true,'depts'=>[]]); exit; }
            $st = $db2->prepare('SELECT id,name FROM departments WHERE college_id=? AND status="active" ORDER BY name');
            $st->execute([$cid]);
            echo json_encode(['ok'=>true,'depts'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>'DB error: '.$e->getMessage()]);
        exit;
    }

    echo json_encode($resp);
    exit;
}

// ── Expense Applications AJAX handlers ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && strpos($_POST['ajax_action'], 'expense_') === 0) {
    header('Content-Type: application/json');
    $dbE = getDB();
    if (!$dbE) { echo json_encode(['ok'=>false,'msg'=>'DB unavailable']); exit; }

    // Only principal, hod (scoped college), superadmin allowed
    $expAction = $_POST['ajax_action'];

    // ── Load expense list ────────────────────────────────────────────────
    if ($expAction === 'expense_list') {
        $filterCollege = (int)($_POST['college_id'] ?? 0);
        $filterDept    = (int)($_POST['dept_id']    ?? 0);
        $filterStatus  = trim($_POST['status']      ?? '');
        $filterPriority= trim($_POST['priority']    ?? '');

        $where = [];
        $params = [];

        if ($filterCollege) { $where[] = 'ea.college_id = ?';    $params[] = $filterCollege; }
        if ($filterDept)    { $where[] = 'ea.department_id = ?'; $params[] = $filterDept; }

        // ── Role-based status gate ────────────────────────────────────────
        // HOD  : sees only 'pending' (their queue to action)
        // Principal / SuperAdmin : sees everything EXCEPT 'pending'
        //        (only after HOD has forwarded = status 'review' or beyond)
        if ($saCollegeRole === 'hod') {
            // HOD can only see pending applications
            if ($filterStatus && $filterStatus !== 'all' && $filterStatus !== 'pending') {
                // If HOD tries to filter to a status they can't see, return empty
                echo json_encode(['ok'=>true,'expenses'=>[]]);
                exit;
            }
            $where[] = "ea.status = 'pending'";
        } elseif ($saCollegeRole === 'principal') {
            // Principal only sees HOD-forwarded (review) and beyond — never raw 'pending'
            if ($filterStatus && $filterStatus !== 'all' && $filterStatus === 'pending') {
                echo json_encode(['ok'=>true,'expenses'=>[]]);
                exit;
            }
            if (!$filterStatus || $filterStatus === 'all') {
                $where[] = "ea.status IN ('review','approved','rejected','disbursed')";
            } else {
                $where[] = 'ea.status = ?'; $params[] = $filterStatus;
            }
        } else {
            // SuperAdmin sees everything, respect manual filter
            if ($filterStatus && $filterStatus !== 'all') { $where[] = 'ea.status = ?'; $params[] = $filterStatus; }
        }

        if ($filterPriority && $filterPriority !== 'all'){ $where[] = 'ea.priority = ?'; $params[] = $filterPriority; }

        $wSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        try {
            $st = $dbE->prepare("
                SELECT ea.id, ea.expense_title, ea.category, ea.amount, ea.currency,
                       ea.expense_date, ea.priority, ea.status, ea.submitted_at,
                       ea.reason, ea.payment_mode, ea.vendor_name, ea.receipt_path,
                       ea.hod_remarks, ea.admin_remarks, ea.reviewed_at,
                       u.full_name AS faculty_name,
                       d.name AS dept_name, c.name AS college_name
                FROM   expense_applications ea
                LEFT JOIN users       u ON u.id = ea.faculty_id
                LEFT JOIN departments d ON d.id = ea.department_id
                LEFT JOIN colleges    c ON c.id = ea.college_id
                $wSql
                ORDER BY
                  FIELD(ea.status,'pending','review','approved','rejected','disbursed'),
                  FIELD(ea.priority,'urgent','high','normal','low'),
                  ea.submitted_at DESC
                LIMIT 200
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'expenses'=>$rows]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Update expense status (approve / reject / mark disbursed) ────────
    if ($expAction === 'expense_update_status') {
        $expId   = (int)($_POST['expense_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        $remarks   = trim($_POST['remarks']    ?? '');
        $remarksCol = ($_POST['remarker'] ?? '') === 'hod' ? 'hod_remarks' : 'admin_remarks';

        $allowed = ['pending','review','approved','rejected','disbursed'];
        if (!$expId || !in_array($newStatus, $allowed)) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid parameters']); exit;
        }

        try {
            $extraSet = $remarks ? ", $remarksCol = ?" : '';
            $extraParams = $remarks ? [$remarks] : [];

            $reviewerId   = (int)($user['id'] ?? 0);
            $reviewed_at  = in_array($newStatus, ['approved','rejected','review','disbursed'])
                ? ', reviewed_at = NOW()' . ($reviewerId ? ', reviewed_by = ' . $reviewerId : '')
                : '';
            $disbursed_at = $newStatus === 'disbursed' ? ', disbursed_at = NOW()' : '';

            $st = $dbE->prepare("UPDATE expense_applications
                SET status = ?, updated_at = NOW() $reviewed_at $disbursed_at $extraSet
                WHERE id = ?");
            $st->execute(array_merge([$newStatus], $extraParams, [$expId]));
            echo json_encode(['ok'=>true,'msg'=>'Status updated to '.ucfirst($newStatus)]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown expense action']);
    exit;
}

// ── Leave Applications AJAX handlers (HOD approve/reject; Principal view) ──
// ═══════════════════════════════════════════════════════════════════════════
// ── Admission Applications AJAX handlers (Principal accept / reject) ────────
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && strpos($_POST['ajax_action'], 'admission_') === 0) {
    header('Content-Type: application/json');
    $dbA = getDB();
    if (!$dbA) { echo json_encode(['ok'=>false,'msg'=>'DB unavailable']); exit; }

    $admAction = $_POST['ajax_action'];

    // ── List forwarded applications scoped to principal's college ────────
    if ($admAction === 'admission_list') {
        if (!$saCollegeId) { echo json_encode(['ok'=>false,'msg'=>'No college selected.']); exit; }
        $filterStatus = trim($_POST['status'] ?? 'all');

        $where  = ['a.college_id = ?', 'a.payment_status = "Paid"'];
        $params = [$saCollegeId];

        if ($saCollegeRole === 'principal') {
            // Principal sees Under Review + Approved + Rejected (all forwarded)
            if ($filterStatus === 'all') {
                $where[] = "a.application_status IN ('Under Review','Approved','Rejected')";
            } else {
                $where[] = 'a.application_status = ?'; $params[] = $filterStatus;
            }
        } else {
            // Super admin sees everything paid
            if ($filterStatus !== 'all') { $where[] = 'a.application_status = ?'; $params[] = $filterStatus; }
        }

        $wSql = 'WHERE ' . implode(' AND ', $where);
        try {
            $st = $dbA->prepare("
                SELECT a.id, a.application_no, a.full_name, a.email, a.mobile,
                       a.dob, a.gender, a.category, a.address, a.city, a.state,
                       a.admission_type, a.entrance_exam, a.entrance_score,
                       a.qual_10_percent, a.qual_12_percent, a.prev_cgpa,
                       a.father_name, a.mother_name, a.guardian_mobile,
                       a.application_status, a.payment_status,
                       a.submitted_at, a.updated_at,
                       cm.course_name, cm.course_code,
                       c.name AS college_name
                FROM applications a
                JOIN courses_master cm ON cm.id = a.course_id
                JOIN colleges      c  ON c.id  = a.college_id
                $wSql
                ORDER BY
                  FIELD(a.application_status,'Under Review','Approved','Rejected'),
                  a.updated_at DESC
                LIMIT 500
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Stats
            $sSt = $dbA->prepare("SELECT application_status, COUNT(*) AS cnt FROM applications WHERE college_id=? AND payment_status='Paid' GROUP BY application_status");
            $sSt->execute([$saCollegeId]);
            $statsRaw = $sSt->fetchAll(PDO::FETCH_ASSOC);
            $admStats = ['Under Review'=>0,'Approved'=>0,'Rejected'=>0,'Submitted'=>0];
            foreach ($statsRaw as $sr) { $admStats[$sr['application_status']] = (int)$sr['cnt']; }

            echo json_encode(['ok'=>true,'applications'=>$rows,'stats'=>$admStats]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Accept or Reject an application ──────────────────────────────────
    if ($admAction === 'admission_action') {
        // Only principal (or super admin with college context) may act
        if ($saCollegeRole !== 'principal' && $user['role'] !== 'super_admin') {
            echo json_encode(['ok'=>false,'msg'=>'Permission denied.']); exit;
        }
        $appId    = (int)($_POST['app_id']    ?? 0);
        $decision = trim($_POST['decision']   ?? ''); // 'Approved' or 'Rejected'
        $remarks  = trim($_POST['remarks']    ?? '');

        if (!$appId || !in_array($decision, ['Approved','Rejected'])) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid parameters.']); exit;
        }

        // Fetch the application
        $scope = $saCollegeId ? ' AND a.college_id = ' . (int)$saCollegeId : '';
        $fetch = $dbA->prepare("
            SELECT a.*, cm.course_name, cm.course_code, d.id AS dept_id
            FROM applications a
            JOIN courses_master cm ON cm.id = a.course_id
            LEFT JOIN departments d ON d.code = cm.course_code AND d.college_id = a.college_id
            WHERE a.id = ? AND a.application_status = 'Under Review' AND a.payment_status = 'Paid' $scope
            LIMIT 1
        ");
        $fetch->execute([$appId]);
        $app = $fetch->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(['ok'=>false,'msg'=>'Application not found or not in Under Review state.']); exit;
        }

        try {
            $dbA->beginTransaction();

            // Update application status
            $upd = $dbA->prepare("UPDATE applications SET application_status=?, updated_at=NOW() WHERE id=?");
            $upd->execute([$decision, $appId]);

            $newUserId = null;

            if ($decision === 'Approved') {
                // ── Create student user account ───────────────────────────
                // Build username from name + app_no suffix
                $baseUser = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['full_name']));
                $baseUser = substr($baseUser, 0, 15) ?: 'student';
                // Ensure unique username
                $uChk = $dbA->prepare("SELECT COUNT(*) FROM users WHERE username LIKE ?");
                $uChk->execute([$baseUser . '%']);
                $cnt  = (int)$uChk->fetchColumn();
                $username = $cnt ? $baseUser . ($cnt + 1) : $baseUser;

                // Roll number: COLLEGE_CODE-COURSE_CODE-YEAR-SEQ
                $year  = date('Y');
                $seqSt = $dbA->prepare("SELECT COUNT(*) FROM users WHERE college_id=? AND department_id=? AND role='student'");
                $seqSt->execute([$app['college_id'], $app['dept_id'] ?: 0]);
                $seq   = (int)$seqSt->fetchColumn() + 1;
                $rollNo = strtoupper($app['course_code'] ?? 'STU') . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                // Default password = DOB digits or mobile last 6
                $rawPass = $app['dob'] ? preg_replace('/[^0-9]/', '', $app['dob']) : substr($app['mobile'] ?? '000000', -6);
                $rawPass = $rawPass ?: 'Welcome@123';
                $hashed  = password_hash($rawPass, PASSWORD_BCRYPT);

                $ins = $dbA->prepare("
                    INSERT INTO users
                      (college_id, department_id, username, email, password,
                       full_name, roll_number, role, phone, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'student', ?, 'active', NOW())
                ");
                $ins->execute([
                    $app['college_id'],
                    $app['dept_id'] ?: null,
                    $username,
                    $app['email'],
                    $hashed,
                    $app['full_name'],
                    $rollNo,
                    $app['mobile'] ?? '',
                ]);
                $newUserId = $dbA->lastInsertId();
            }

            $dbA->commit();

            $msg = $decision === 'Approved'
                ? 'Application <strong>' . htmlspecialchars($app['application_no']) . '</strong> accepted — student account created.'
                : 'Application <strong>' . htmlspecialchars($app['application_no']) . '</strong> rejected.';

            echo json_encode([
                'ok'         => true,
                'msg'        => $msg,
                'decision'   => $decision,
                'new_user_id'=> $newUserId,
                'roll_number'=> $rollNo ?? null,
            ]);
        } catch (Exception $e) {
            $dbA->rollBack();
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown admission action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && strpos($_POST['ajax_action'], 'leave_') === 0) {
    header('Content-Type: application/json');
    $dbL = getDB();
    if (!$dbL) { echo json_encode(['ok'=>false,'msg'=>'DB unavailable']); exit; }

    $leaveAction = $_POST['ajax_action'];

    // ── List leave applications scoped to college (+ dept for HOD) ───────
    if ($leaveAction === 'leave_list') {
        $filterCollegeId = (int)($_POST['college_id'] ?? 0) ?: $saCollegeId;
        $filterDeptId    = (int)($_POST['dept_id']    ?? 0) ?: $saDeptId;
        $filterStatus    = trim($_POST['status']      ?? '');

        if (!$filterCollegeId) { echo json_encode(['ok'=>false,'msg'=>'No college selected.']); exit; }

        $where  = ['la.college_id = ?'];
        $params = [$filterCollegeId];

        if ($saCollegeRole === 'hod') {
            // HOD sees their dept's pending (direct) AND hod_pending (forwarded by admin)
            if ($filterDeptId) { $where[] = 'la.department_id = ?'; $params[] = $filterDeptId; }
            if (!$filterStatus || $filterStatus === 'all') {
                $where[] = "la.status IN ('pending','hod_pending','approved','rejected')";
            } else {
                $where[] = 'la.status = ?'; $params[] = $filterStatus;
            }
        } elseif ($saCollegeRole === 'principal') {
            // Principal sees everything
            if ($filterStatus && $filterStatus !== 'all') { $where[] = 'la.status = ?'; $params[] = $filterStatus; }
        } else {
            // Super admin
            if ($filterDeptId) { $where[] = 'la.department_id = ?'; $params[] = $filterDeptId; }
            if ($filterStatus && $filterStatus !== 'all') { $where[] = 'la.status = ?'; $params[] = $filterStatus; }
        }

        $wSql = 'WHERE ' . implode(' AND ', $where);

        try {
            $st = $dbL->prepare("
                SELECT la.id, la.leave_type, la.from_date, la.to_date, la.total_days,
                       la.half_day, la.reason, la.status, la.created_at,
                       la.review_remarks, la.reviewed_at,
                       la.hod_remarks, la.hod_reviewed_at,
                       u.full_name AS applicant_name, u.designation,
                       d.name AS dept_name,
                       rv.full_name AS reviewer_name,
                       hv.full_name AS hod_reviewer_name
                FROM leave_applications la
                JOIN  users       u  ON u.id  = la.applicant_id
                LEFT JOIN departments d  ON d.id  = la.department_id
                LEFT JOIN users       rv ON rv.id = la.reviewed_by
                LEFT JOIN users       hv ON hv.id = la.hod_reviewed_by
                $wSql
                ORDER BY
                  FIELD(la.status,'hod_pending','pending','approved','rejected','cancelled'),
                  la.created_at DESC
                LIMIT 300
            ");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'leaves'=>$rows]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    // ── HOD: approve or reject a hod_pending application ─────────────────
    if ($leaveAction === 'leave_hod_action') {
        if ($saCollegeRole !== 'hod' && $saCollegeRole !== 'principal') {
            echo json_encode(['ok'=>false,'msg'=>'Permission denied.']); exit;
        }
        $leaveId   = (int)($_POST['leave_id']  ?? 0);
        $newStatus = trim($_POST['new_status'] ?? ''); // 'approved' or 'rejected'
        $remarks   = trim($_POST['remarks']    ?? '');

        if (!$leaveId || !in_array($newStatus, ['approved','rejected'])) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid parameters.']); exit;
        }

        $reviewerId = (int)($user['id'] ?? 0);
        try {
            $extraDept = ($saCollegeRole === 'hod' && $saDeptId)
                ? ' AND la.department_id = ' . $saDeptId : '';
            $st = $dbL->prepare("
                UPDATE leave_applications la
                SET la.status = ?, la.hod_reviewed_by = ?, la.hod_reviewed_at = NOW(), la.hod_remarks = ?
                WHERE la.id = ? AND la.college_id = ?
                  AND la.status IN ('pending','hod_pending') $extraDept
            ");
            $st->execute([$newStatus, $reviewerId, $remarks, $leaveId, $saCollegeId]);
            if ($st->rowCount())
                echo json_encode(['ok'=>true,'msg'=>'Leave application '.ucfirst($newStatus).'.']);
            else
                echo json_encode(['ok'=>false,'msg'=>'Application not found or not awaiting HOD review.']);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown leave action']);
    exit;
}

// ── Fetch live stats from DB ────────────────────────────────────────────
$stats = [
    'total_colleges'    => 0,
    'total_users'       => 0,
    'total_students'    => 0,
    'total_faculty'     => 0,
    'pending_approvals' => 0,
    'total_courses'     => 0,
    'total_departments' => 0,
    'active_colleges'   => 0,
    'college_admins'    => 0,
];

$recent_colleges = [];
$recent_users = [];
$activity_log = [];
$all_colleges = [];
$all_departments = [];
$all_courses = [];
$all_students = [];
$all_faculty = [];

$db = getDB();
if ($db) {
    try {
        // ── When a college is selected (Principal/HOD mode), scope all counts ──
        $cWhere = $saCollegeId ? ' WHERE college_id=' . $saCollegeId : '';
        $uWhere = $saCollegeId ? ' WHERE college_id=' . $saCollegeId . ' AND role!="super_admin"' : ' WHERE role!="super_admin"';

        // Fetch statistics — scoped to selected college when in Principal/HOD mode
        if ($saCollegeId) {
            $stats['total_colleges']    = 1; // only their college
            $stats['active_colleges']   = 1;
            // HOD mode: scope stats to their department only
            $deptStatsWhere = ($saCollegeRole === 'hod' && $saDeptId) ? ' AND department_id=' . $saDeptId : '';
            $stats['total_users']       = (int)$db->query('SELECT COUNT(*) FROM users WHERE college_id='.$saCollegeId.$deptStatsWhere)->fetchColumn();
            $stats['total_students']    = (int)$db->query('SELECT COUNT(*) FROM users WHERE role="student" AND college_id='.$saCollegeId.$deptStatsWhere)->fetchColumn();
            $stats['total_faculty']     = (int)$db->query('SELECT COUNT(*) FROM users WHERE role="faculty" AND college_id='.$saCollegeId.$deptStatsWhere)->fetchColumn();
            $stats['college_admins']    = 0;
            $stats['pending_approvals'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE status="pending" AND college_id='.$saCollegeId.$deptStatsWhere)->fetchColumn();
            $stats['total_courses']     = (int)$db->query('SELECT COUNT(*) FROM courses WHERE status="active" AND college_id='.$saCollegeId.($saDeptId && $saCollegeRole==='hod' ? ' AND department_id='.$saDeptId : ''))->fetchColumn();
            $stats['total_departments'] = ($saCollegeRole === 'hod' && $saDeptId) ? 1 : (int)$db->query('SELECT COUNT(*) FROM departments WHERE status="active" AND college_id='.$saCollegeId)->fetchColumn();
        } else {
            $stats['total_colleges']    = (int)$db->query('SELECT COUNT(*) FROM colleges')->fetchColumn();
            $stats['active_colleges']   = (int)$db->query('SELECT COUNT(*) FROM colleges WHERE status="active"')->fetchColumn();
            $stats['total_users']       = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $stats['total_students']    = (int)$db->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn();
            $stats['total_faculty']     = (int)$db->query('SELECT COUNT(*) FROM users WHERE role="faculty"')->fetchColumn();
            $stats['college_admins']    = (int)$db->query('SELECT COUNT(*) FROM users WHERE role="college_admin"')->fetchColumn();
            $stats['pending_approvals'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE status="pending"')->fetchColumn();
            $stats['total_courses']     = (int)$db->query('SELECT COUNT(*) FROM courses WHERE status="active"')->fetchColumn();
            $stats['total_departments'] = (int)$db->query('SELECT COUNT(*) FROM departments WHERE status="active"')->fetchColumn();
        }

        // Fetch ALL colleges (always — needed for modals/selects in super-admin mode)
        $stmt = $db->query('
            SELECT c.*, 
                   (SELECT COUNT(*) FROM departments d WHERE d.college_id=c.id) AS dept_count,
                   (SELECT COUNT(*) FROM courses co WHERE co.college_id=c.id AND co.status="active") AS course_count
            FROM colleges c 
            ORDER BY c.created_at DESC
        ');
        $all_colleges = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For display in colleges view & dashboard panel — scoped in Principal/HOD mode
        $recent_colleges = $saCollegeId
            ? array_values(array_filter($all_colleges, fn($c) => (int)$c['id'] === $saCollegeId))
            : $all_colleges;

        // Scoped college filter fragments
        $collegeFilter       = $saCollegeId ? ' AND u.college_id = ' . $saCollegeId  : '';
        $collegeFilterDept   = $saCollegeId ? ' AND d.college_id = ' . $saCollegeId  : '';
        $collegeFilterCourse = $saCollegeId ? ' AND co.college_id = ' . $saCollegeId : '';
        $collegeFilterFacJoin= $saCollegeId ? ' AND fa.college_id = ' . $saCollegeId : '';

        // HOD mode: further restrict to only their department
        $isHodMode = ($saCollegeRole === 'hod' && $saDeptId);
        if ($isHodMode) {
            $collegeFilter       .= ' AND u.department_id = ' . $saDeptId;
            $collegeFilterDept   .= ' AND d.id = '           . $saDeptId;
            $collegeFilterCourse .= ' AND co.department_id = ' . $saDeptId;
            $collegeFilterFacJoin .= ' AND fa.department_id = ' . $saDeptId;
        }
        // Colleges for display-only dropdowns in scoped mode
        $all_colleges_display = $saCollegeId
            ? array_values(array_filter($all_colleges, fn($c) => (int)$c['id'] === $saCollegeId))
            : $all_colleges;

        // Fetch users scoped to selected college (or all in super-admin mode)
        $stmt = $db->query('
            SELECT u.*, c.name as college_name 
            FROM users u 
            LEFT JOIN colleges c ON u.college_id = c.id
            WHERE u.role != "super_admin"' . $collegeFilter . '
            ORDER BY u.created_at DESC
        ');
        $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch departments (scoped)
        $stmt = $db->query('
            SELECT d.*, c.name as college_name,
                   (SELECT COUNT(*) FROM courses co WHERE co.department_id=d.id AND co.status="active") AS course_count
            FROM departments d
            LEFT JOIN colleges c ON d.college_id=c.id
            WHERE 1=1' . $collegeFilterDept . '
            ORDER BY c.name, d.name
        ');
        $all_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch courses (scoped)
        $stmt = $db->query('
            SELECT co.*, c.name as college_name, d.name as dept_name
            FROM courses co
            LEFT JOIN colleges c ON co.college_id=c.id
            LEFT JOIN departments d ON co.department_id=d.id
            WHERE 1=1' . $collegeFilterCourse . '
            ORDER BY c.name, co.name
        ');
        $all_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch students (scoped)
        $stmt = $db->query('
            SELECT u.*, c.name as college_name, d.name as dept_name
            FROM users u
            LEFT JOIN colleges c ON u.college_id=c.id
            LEFT JOIN departments d ON u.department_id=d.id
            WHERE u.role="student"' . $collegeFilter . '
            ORDER BY u.full_name
        ');
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch faculty (scoped to college via faculty_assignments when a college is selected,
        // or all faculty in super-admin mode)
        if ($saCollegeId) {
            // Only faculty assigned to (or belonging to) the selected college
            $hodDeptClause = ($saCollegeRole === 'hod' && $saDeptId) ? ' AND (u.department_id='.$saDeptId.' OR fa.department_id='.$saDeptId.')' : '';
            $stmt = $db->prepare('
                SELECT u.*,
                       c_own.name as college_name,
                       GROUP_CONCAT(DISTINCT d2.name ORDER BY d2.name SEPARATOR " | ") as dept_name,
                       COUNT(DISTINCT fa.id) as assignment_count
                FROM users u
                LEFT JOIN colleges c_own ON u.college_id = c_own.id
                LEFT JOIN faculty_assignments fa ON u.id=fa.faculty_id AND fa.status="active" AND fa.college_id=?
                LEFT JOIN departments d2 ON fa.department_id=d2.id
                WHERE u.role="faculty"
                  AND (u.college_id=? OR fa.college_id=?)
                  ' . $hodDeptClause . '
                GROUP BY u.id
                ORDER BY u.full_name
            ');
            $stmt->execute([$saCollegeId, $saCollegeId, $saCollegeId]);
        } else {
            $stmt = $db->query('
                SELECT u.*,
                       GROUP_CONCAT(DISTINCT c2.name ORDER BY c2.name SEPARATOR " | ") as college_name,
                       GROUP_CONCAT(DISTINCT d2.name ORDER BY d2.name SEPARATOR " | ") as dept_name,
                       COUNT(DISTINCT fa.id) as assignment_count
                FROM users u
                LEFT JOIN faculty_assignments fa ON u.id=fa.faculty_id AND fa.status="active"
                LEFT JOIN colleges c2 ON fa.college_id=c2.id
                LEFT JOIN departments d2 ON fa.department_id=d2.id
                WHERE u.role="faculty"
                GROUP BY u.id
                ORDER BY u.full_name
            ');
        }
        $all_faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch activity log
        $stmt = $db->query('
            SELECT a.*, u.username, u.full_name 
            FROM activity_log a 
            LEFT JOIN users u ON a.user_id=u.id 
            ORDER BY a.created_at DESC 
            LIMIT 8
        ');
        $activity_log = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Dashboard query error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Dashboard — PEPA ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ─── Reset & Tokens ──────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* ── Core palette ── */
  --teal:      #0F766E;
  --teal2:     #0D5C56;
  --teal-light:#14B8A6;
  --teal-dim:  rgba(20,184,166,0.10);
  --teal-soft: rgba(20,184,166,0.06);
  --amber:     #F59E0B;
  --amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,0.12);
  --red:       #DC2626;
  --red-soft:  rgba(220,38,38,0.10);
  --green:     #16A34A;
  --green-soft:rgba(22,163,74,0.10);
  --blue:      #1D4ED8;
  --purple:    #7C3AED;

  /* ── Surface ── */
  --navy:      #0F172A;
  --navy2:     #1E293B;
  --page-bg:   #F0FAFA;
  --card-bg:   #FFFFFF;
  --white:     #FFFFFF;

  /* ── Text ── */
  --text:      #0F172A;
  --text-muted:#475569;
  --muted:     #64748B;

  /* ── Borders ── */
  --border:    rgba(15,118,110,0.12);
  --border2:   rgba(15,118,110,0.20);

  /* ── Misc ── */
  --sidebar-w: 260px;
  --font-head: 'Plus Jakarta Sans', sans-serif;
  --font-body: 'Plus Jakarta Sans', sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --radius-lg: 16px;
  --radius:    12px;
  --radius-sm: 8px;
}

html, body {
  height: 100%; font-family: var(--font-body);
  background: var(--page-bg); color: var(--text); overflow-x: hidden;
}

/* ─── Background ──────────────────────────────────────────────────────────── */
.bg-canvas {
  position: fixed; inset: 0; z-index: 0;
  background: linear-gradient(180deg, rgba(20,184,166,0.05) 0%, transparent 35%);
}
.bg-grid {
  position: fixed; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(15,118,110,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15,118,110,0.03) 1px, transparent 1px);
  background-size: 48px 48px;
}

/* ─── Layout ──────────────────────────────────────────────────────────────── */
.app-shell {
  position: relative; z-index: 1;
  display: flex; min-height: 100vh; height: 100vh; overflow: hidden;
}

/* ─── Sidebar ─────────────────────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w);
  background: linear-gradient(168deg,
    rgba(13,92,86,0.98) 0%,
    rgba(15,118,110,0.95) 40%,
    rgba(17,140,130,0.92) 72%,
    rgba(13,92,86,0.98) 100%
  );
  border-right: 1px solid rgba(255,255,255,0.10);
  backdrop-filter: blur(24px);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; bottom: 0;
  z-index: 100;
  transition: transform 0.3s ease;
}

.sidebar-logo {
  padding: 22px 20px 18px;
  border-bottom: 1px solid rgba(255,255,255,0.10);
  display: flex; align-items: center; gap: 12px;
}
.logo-icon {
  width: 40px; height: 40px; border-radius: 10px;
  background: linear-gradient(135deg, var(--amber), var(--amber-dark));
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; color: #fff; font-weight: 800;
  flex-shrink: 0; box-shadow: 0 4px 12px rgba(245,158,11,0.35);
}
.logo-text { font-family: var(--font-head); font-size: 1rem; font-weight: 700; color: #fff; line-height: 1.2; }
.logo-sub  { font-size: 0.62rem; color: rgba(255,255,255,0.55); letter-spacing: 0.12em; text-transform: uppercase; display: block; margin-top: 2px; }

.sidebar-admin {
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
  display: flex; align-items: center; gap: 10px;
}
.admin-avatar {
  width: 36px; height: 36px; border-radius: 9px;
  background: linear-gradient(135deg, var(--amber), var(--amber-dark));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-weight: 800; font-size: 0.85rem; color: #fff;
  flex-shrink: 0;
}
.admin-info .admin-name  { font-size: 0.82rem; font-weight: 600; color: #fff; }
.admin-info .admin-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(245,158,11,0.18); color: var(--amber);
  font-size: 0.6rem; font-weight: 700; letter-spacing: 0.1em;
  text-transform: uppercase; padding: 2px 8px; border-radius: 50px;
  border: 1px solid rgba(245,158,11,0.30); margin-top: 3px;
}
.admin-badge i { font-size: 0.55rem; }

.sidebar-nav {
  flex: 1; padding: 14px 10px; overflow-y: auto;
}
.nav-section { margin-bottom: 18px; }
.nav-section-label {
  font-size: 0.58rem; font-weight: 700; letter-spacing: 0.14em;
  text-transform: uppercase; color: rgba(255,255,255,0.44);
  padding: 0 10px 8px; display: flex; align-items: center; gap: 8px;
}
.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; margin-bottom: 1px;
  font-size: 0.82rem; font-weight: 500; color: rgba(255,255,255,0.78);
  text-decoration: none; border-radius: 9px;
  transition: all 0.18s ease; position: relative;
  border: 1px solid transparent;
}
.nav-link i { width: 16px; text-align: center; font-size: 0.82rem; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,0.07); color: #fff; }
.nav-link.active {
  background: rgba(245,158,11,0.16);
  color: var(--amber);
  border-color: rgba(245,158,11,0.38);
  font-weight: 600;
}
.nav-link.active i { opacity: 1; }
.nav-link.active::before {
  content: ''; position: absolute; left: 0; top: 20%;
  width: 2.5px; height: 60%; background: var(--amber); border-radius: 0 3px 3px 0;
}
.nav-badge {
  margin-left: auto;
  background: rgba(220,38,38,0.85);
  color: #fff;
  font-size: 0.6rem;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 50px;
  min-width: 18px;
  text-align: center;
  font-family: var(--font-mono);
}

.sidebar-footer {
  padding: 14px 16px;
  border-top: 1px solid rgba(255,255,255,0.08);
}
.btn-logout-side {
  width: 100%;
  padding: 10px;
  background: rgba(220,38,38,0.12);
  border: 1px solid rgba(220,38,38,0.25);
  border-radius: var(--radius-sm);
  color: #fca5a5;
  font-family: var(--font-body);
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-logout-side:hover {
  background: rgba(220,38,38,0.22);
  border-color: rgba(220,38,38,0.5);
}

/* ─── Main Content ────────────────────────────────────────────────────────── */
main {
  flex: 1;
  margin-left: var(--sidebar-w);
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  height: 100vh;
  overflow: hidden;
}

.header {
  background: linear-gradient(135deg, #0D5C56 0%, #0F766E 45%, #0F9488 75%, #0D5C56 100%);
  border-bottom: 1px solid rgba(255,255,255,0.08);
  backdrop-filter: none;
  padding: 0 32px;
  height: 64px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: sticky;
  top: 0;
  z-index: 50;
  box-shadow: 0 2px 16px rgba(13,92,86,0.35);
}
.header-left h1 {
  font-family: var(--font-head);
  font-size: 1rem;
  font-weight: 700;
  color: #fff;
  margin-bottom: 0;
  display: flex; align-items: center; gap: 6px;
}
.header-left .breadcrumb {
  font-size: 0.73rem;
  color: rgba(255,255,255,0.60);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.breadcrumb i { font-size: 0.6rem; }
.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.clock-display {
  font-family: var(--font-mono);
  font-size: 0.78rem;
  color: rgba(255,255,255,0.88);
  padding: 6px 14px;
  background: rgba(255,255,255,0.12);
  border: 1px solid rgba(255,255,255,0.18);
  border-radius: var(--radius-sm);
  font-weight: 600;
}

.content {
  flex: 1;
  padding: 24px 32px;
  overflow-y: auto;
}

/* ─── Dashboard Grid ──────────────────────────────────────────────────────── */
.dash-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 28px;
}

.stat-card {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  padding: 20px 22px;
  position: relative;
  overflow: hidden;
  transition: all 0.25s ease;
  box-shadow: 0 1px 6px rgba(15,118,110,0.07);
}
.stat-card::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -20%;
  width: 200px;
  height: 200px;
  border-radius: 50%;
  opacity: 0.04;
  transition: all 0.3s ease;
}
.stat-card:hover {
  transform: translateY(-3px);
  border-color: var(--border2);
  box-shadow: 0 8px 28px rgba(15,118,110,0.13);
}
.stat-card.teal::before { background: var(--teal); }
.stat-card.amber::before { background: var(--amber); }
.stat-card.green::before { background: var(--green); }
.stat-card.red::before { background: var(--red); }

.stat-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 14px;
}
.stat-label {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.stat-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
}
.stat-icon.teal { background: rgba(15,118,110,0.10); color: var(--teal); }
.stat-icon.amber { background: rgba(245,158,11,0.12); color: var(--amber-dark); }
.stat-icon.green { background: rgba(22,163,74,0.10); color: var(--green); }
.stat-icon.red { background: rgba(220,38,38,0.10); color: var(--red); }

.stat-value {
  font-family: var(--font-head);
  font-size: 2.2rem;
  font-weight: 800;
  color: var(--text);
  line-height: 1;
  margin-bottom: 8px;
}
.stat-change {
  font-size: 0.73rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
}
.stat-change.up { color: var(--green); }
.stat-change.down { color: var(--red); }
.stat-change i { font-size: 0.7rem; }

/* ─── Panels ──────────────────────────────────────────────────────────────── */
.panel {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  overflow: hidden;
  min-width: 0;
  box-shadow: 0 1px 6px rgba(15,118,110,0.06);
}
.panel-header {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(90deg, rgba(20,184,166,0.05), transparent);
}
.panel-title {
  font-family: var(--font-head);
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 10px;
}
.panel-title i { color: var(--teal); font-size: 0.95rem; }
.panel-body {
  padding: 24px;
  overflow-x: auto;
}
.panel-body.panel-body-table {
  padding: 0;
  overflow-x: auto;
}
.panel-body.panel-body-table .table-wrap {
  overflow-x: auto;
}

.dash-grid-1 {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-bottom: 28px;
}

.dash-grid-2 {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  margin-bottom: 28px;
  min-width: 0;
}
.dash-grid-2 > * {
  min-width: 0;
}

/* ─── Tables ──────────────────────────────────────────────────────────────── */
.table-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.data-table {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
  white-space: nowrap;
}
.data-table thead th {
  text-align: left;
  padding: 10px 14px;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
  background: rgba(20,184,166,0.03);
}
.data-table tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background 0.15s ease;
}
.data-table tbody tr:hover {
  background: rgba(20,184,166,0.04);
}
.data-table tbody td {
  padding: 11px 14px;
  font-size: 0.83rem;
  color: var(--text);
  white-space: nowrap;
}

.badge {
  display: inline-block;
  padding: 3px 9px;
  border-radius: 50px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.badge-active {
  background: rgba(22,163,74,0.12);
  color: var(--green);
  border: 1px solid rgba(22,163,74,0.25);
}
.badge-inactive {
  background: rgba(100,116,139,0.10);
  color: var(--muted);
  border: 1px solid rgba(100,116,139,0.20);
}
.badge-pending {
  background: rgba(245,158,11,0.12);
  color: var(--amber-dark);
  border: 1px solid rgba(245,158,11,0.25);
}

.role-pill {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 50px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.role-super_admin {
  background: rgba(15,118,110,0.10);
  color: var(--teal);
  border: 1px solid rgba(15,118,110,0.25);
}
.role-college_admin {
  background: rgba(245,158,11,0.12);
  color: var(--amber-dark);
  border: 1px solid rgba(245,158,11,0.28);
}
.role-faculty {
  background: rgba(22,163,74,0.10);
  color: var(--green);
  border: 1px solid rgba(22,163,74,0.25);
}
.role-student {
  background: rgba(100,116,139,0.10);
  color: var(--text-muted);
  border: 1px solid rgba(100,116,139,0.20);
}

/* ─── Activity Log ────────────────────────────────────────────────────────── */
.activity-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.activity-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 10px 12px;
  background: rgba(20,184,166,0.03);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  transition: all 0.18s ease;
}
.activity-item:hover {
  background: rgba(20,184,166,0.07);
  border-color: var(--border2);
}
.activity-icon-wrap {
  width: 34px; height: 34px; border-radius: 8px;
  background: rgba(15,118,110,0.10);
  border: 1px solid rgba(15,118,110,0.18);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.activity-icon-wrap i { color: var(--teal); font-size: 0.85rem; }
.activity-text { font-size: 0.82rem; color: var(--text); margin-bottom: 3px; }
.activity-user { font-weight: 600; color: var(--teal); }
.activity-time { font-size: 0.7rem; color: var(--muted); }

/* ─── Quick Actions ───────────────────────────────────────────────────────── */
.quick-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.qa-btn {
  padding: 13px 16px;
  background: rgba(20,184,166,0.05);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 0.83rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.18s ease;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
  text-align: left;
}
.qa-btn i { font-size: 1rem; color: var(--teal); }
.qa-btn:hover {
  background: rgba(20,184,166,0.10);
  border-color: var(--border2);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(15,118,110,0.10);
}

/* ─── Health Bars ─────────────────────────────────────────────────────────── */
.health-row {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 18px;
}
.health-row:last-child { margin-bottom: 0; }
.health-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-muted);
  min-width: 130px;
}
.health-bar-wrap {
  flex: 1;
  height: 7px;
  background: rgba(15,118,110,0.09);
  border-radius: 50px;
  overflow: hidden;
}
.health-bar {
  height: 100%;
  border-radius: 50px;
  transition: width 1s ease-out;
}
.health-val {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--text);
  min-width: 42px;
  text-align: right;
}

/* ─── Modal ───────────────────────────────────────────────────────────────── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;
}
.modal-overlay.open {
  opacity: 1;
  pointer-events: all;
}
.modal-box {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius-lg);
  padding: 36px;
  max-width: 440px;
  width: 90%;
  text-align: center;
  transform: scale(0.9);
  transition: transform 0.3s ease;
  box-shadow: 0 20px 60px rgba(15,118,110,0.15);
}
.modal-overlay.open .modal-box { transform: scale(1); }
.modal-icon {
  width: 60px; height: 60px; margin: 0 auto 18px;
  border-radius: 50%;
  background: rgba(220,38,38,0.10);
  border: 2px solid rgba(220,38,38,0.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem; color: var(--red);
}
.modal-box h3 {
  font-family: var(--font-head); font-size: 1.3rem; font-weight: 800;
  color: var(--text); margin-bottom: 10px;
}
.modal-box p {
  font-size: 0.88rem; color: var(--muted); line-height: 1.6; margin-bottom: 24px;
}
.btn-cancel {
  flex: 1; padding: 12px;
  border-radius: var(--radius-sm);
  font-family: var(--font-body); font-size: 0.88rem; font-weight: 600;
  cursor: pointer; transition: all 0.2s;
  background: rgba(100,116,139,0.10);
  color: var(--text-muted);
  border: 1px solid rgba(100,116,139,0.20);
}
.btn-cancel:hover { background: rgba(100,116,139,0.18); }
.btn-logout {
  flex: 1; padding: 12px;
  border-radius: var(--radius-sm);
  font-family: var(--font-body); font-size: 0.88rem; font-weight: 600;
  cursor: pointer; transition: all 0.2s; border: none;
  background: linear-gradient(135deg, var(--red), #b91c1c);
  color: #fff;
}
.btn-logout:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(220,38,38,0.3); }

/* ─── View Header ─────────────────────────────────────────────────────────── */
.view-header {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 24px;
  flex-wrap: wrap;
}
.view-title {
  font-family: var(--font-head);
  font-size: 1.2rem;
  font-weight: 800;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 10px;
}
.view-title i { color: var(--teal); }
.view-count {
  background: rgba(15,118,110,0.10);
  color: var(--teal);
  border: 1px solid rgba(15,118,110,0.20);
  font-size: 0.72rem; font-weight: 700;
  padding: 3px 10px; border-radius: 50px; letter-spacing: 0.05em;
}

/* ─── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 1200px) {
  .dash-grid-4 {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  main { margin-left: 0; }
  .dash-grid-4, .dash-grid-2 { grid-template-columns: 1fr; }
  .quick-actions { grid-template-columns: 1fr; }
}

/* ── Add/Edit Form Modal ─────────────────────────────────────────────────── */
.form-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.8);
  backdrop-filter: blur(8px);
  display: flex; align-items: center; justify-content: center;
  z-index: 2000;
  opacity: 0; pointer-events: none;
  transition: opacity 0.25s ease;
}
.form-modal-overlay.open { opacity: 1; pointer-events: all; }
.form-modal {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius-lg);
  padding: 28px 32px;
  width: 560px; max-width: 95vw;
  max-height: 90vh; overflow-y: auto;
  transform: scale(0.92);
  transition: transform 0.25s ease;
  box-shadow: 0 20px 60px rgba(15,118,110,0.15);
}
.form-modal-overlay.open .form-modal { transform: scale(1); }
.form-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 22px;
}
.form-modal-title {
  font-family: var(--font-head); font-size: 1.05rem; font-weight: 800;
  color: var(--text); display: flex; align-items: center; gap: 10px;
}
.form-modal-title i { color: var(--teal); }
.form-modal-close {
  background: none; border: none; color: var(--muted); cursor: pointer;
  font-size: 1.1rem; transition: color 0.2s;
}
.form-modal-close:hover { color: var(--text); }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid .full { grid-column: 1/-1; }
.form-group { display: flex; flex-direction: column; gap: 7px; }
.form-group label {
  font-size: 0.72rem; font-weight: 700; color: var(--muted);
  text-transform: uppercase; letter-spacing: 0.07em;
}
.form-group input,
.form-group select,
.form-group textarea {
  background: var(--white);
  border: 1.5px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body); font-size: 0.86rem;
  padding: 11px 14px;
  transition: border-color 0.18s, box-shadow 0.18s;
  outline: none;
  width: 100%;
  box-sizing: border-box;
  -webkit-appearance: none;
  appearance: none;
  line-height: 1.5;
  min-height: 42px;
}
.form-group input:hover:not(:disabled),
.form-group select:hover:not(:disabled),
.form-group textarea:hover:not(:disabled) { border-color: rgba(15,118,110,0.40); }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(15,118,110,0.12);
  background: #fff;
}
.form-group input::placeholder,
.form-group textarea::placeholder { color: #a0aec0; }
.form-group input:disabled,
.form-group select:disabled { background: rgba(15,118,110,0.04); color: var(--muted); cursor: not-allowed; opacity: 0.7; }
.form-group select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748B' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; cursor:pointer; }
.form-group select option { background: var(--white); color: var(--text); }
.form-group textarea { resize: vertical; min-height: 72px; line-height: 1.6; }

.form-actions {
  display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;
}
.btn-primary {
  padding: 10px 22px;
  background: linear-gradient(135deg, var(--teal-light), var(--teal));
  color: #fff; font-family: var(--font-body); font-size: 0.86rem;
  font-weight: 700; border: none; border-radius: var(--radius-sm);
  cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 14px rgba(15,118,110,0.25);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(15,118,110,0.38); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.btn-secondary {
  padding: 10px 18px;
  background: rgba(100,116,139,0.08); color: var(--text-muted);
  border: 1px solid rgba(100,116,139,0.20); border-radius: var(--radius-sm);
  font-family: var(--font-body); font-size: 0.86rem; font-weight: 600;
  cursor: pointer; transition: all 0.18s;
}
.btn-secondary:hover { background: rgba(100,116,139,0.15); }

.form-alert {
  padding: 10px 14px; border-radius: var(--radius-sm);
  font-size: 0.82rem; font-weight: 600; margin-bottom: 16px;
  display: none;
}
.form-alert.error { background: rgba(231,111,81,0.15); color: var(--red); border: 1px solid rgba(231,111,81,0.3); display: block; }
.form-alert.success { background: rgba(45,212,170,0.15); color: var(--green); border: 1px solid rgba(45,212,170,0.3); display: block; }

/* Add button in view headers */
.btn-add {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px;
  background: linear-gradient(135deg, var(--teal-light), var(--teal));
  color: #fff; font-family: var(--font-body); font-size: 0.8rem;
  font-weight: 700; border: none; border-radius: var(--radius-sm);
  cursor: pointer; transition: all 0.2s;
  text-decoration: none; white-space: nowrap;
  box-shadow: 0 3px 10px rgba(15,118,110,0.22);
}
.btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15,118,110,0.35); }

/* inline status toggle */
.status-toggle {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 50px; cursor: pointer;
  font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em;
  text-transform: uppercase; border: none; transition: all 0.2s;
}
.status-toggle.active  { background: rgba(45,212,170,0.15); color: var(--green); border: 1px solid rgba(45,212,170,0.3); }
.status-toggle.inactive { background: rgba(122,147,172,0.15); color: var(--muted); border: 1px solid rgba(122,147,172,0.3); }
.status-toggle.pending { background: rgba(244,162,97,0.15); color: var(--amber); border: 1px solid rgba(244,162,97,0.3); }
.status-toggle:hover { filter: brightness(1.25); }

/* Status badges */
.status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 50px;
  font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em;
  text-transform: uppercase;
}
.status-badge.active { background: rgba(22,163,74,0.10); color: var(--green); border: 1px solid rgba(22,163,74,0.22); }
.status-badge.inactive { background: rgba(100,116,139,0.10); color: var(--muted); border: 1px solid rgba(100,116,139,0.20); }
.status-badge.completed { background: rgba(29,78,216,0.10); color: var(--blue); border: 1px solid rgba(29,78,216,0.22); }

/* Icon buttons */
.btn-icon-delete {
  background: transparent; border: 1px solid rgba(231,111,81,0.3);
  color: var(--red); padding: 6px 10px; border-radius: var(--radius-sm);
  cursor: pointer; transition: all 0.2s; font-size: 0.85rem;
}
.btn-icon-delete:hover {
  background: rgba(231,111,81,0.15); border-color: var(--red);
  transform: translateY(-1px);
}


/* ─── Attendance View ─────────────────────────────────────────────────────── */
.att-filter-bar {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  padding: 18px 20px;
  margin-bottom: 20px;
  display: grid;
  /* college | dept | course | from | to | search | btn */
  grid-template-columns: 1.3fr 1.3fr 2fr 155px 155px 1.1fr auto;
  gap: 12px;
  align-items: end;
  box-shadow: 0 1px 6px rgba(15,118,110,0.06);
}
@media (max-width: 1280px) {
  .att-filter-bar { grid-template-columns: 1fr 1fr 1.8fr 145px 145px; }
  .att-filter-bar .fg:nth-child(6),
  .att-filter-bar .fg:nth-child(7) { grid-column: span 2; }
}
@media (max-width: 860px) {
  .att-filter-bar { grid-template-columns: 1fr 1fr; }
  .att-filter-bar .fg:last-child { grid-column: span 2; }
}
.att-filter-bar .fg { min-width: 0; }
.att-filter-bar label {
  display: block; margin-bottom: 5px;
  font-size: 0.68rem; font-weight: 700; color: var(--muted);
  text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;
}
.att-filter-bar input,
.att-filter-bar select {
  background: rgba(15,118,110,0.04);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body); font-size: 0.85rem;
  padding: 8px 10px; outline: none;
  width: 100%; box-sizing: border-box;
  transition: border-color 0.2s;
}
/* Extra right padding so native calendar icon never overlaps date text */
.att-filter-bar input[type="date"] {
  padding-right: 38px;
  min-width: 130px;
}
.att-filter-bar input:focus,
.att-filter-bar select:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(15,118,110,0.08); }
.att-filter-bar select option { background: var(--white); }
.att-filter-bar .fg-btn { display: flex; gap: 8px; align-items: stretch; }

.att-stats {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin-bottom: 20px;
}
.att-stat {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  padding: 14px 16px;
  display: flex; flex-direction: column; gap: 5px;
  box-shadow: 0 1px 4px rgba(15,118,110,0.06);
}
.att-stat-label { font-size: 0.68rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; }
.att-stat-val   { font-family: var(--font-head); font-size: 1.8rem; font-weight: 800; color: var(--text); line-height: 1; }
.att-stat-bar   { height: 4px; border-radius: 2px; background: rgba(15,118,110,0.08); overflow: hidden; }
.att-stat-fill  { height: 100%; border-radius: 2px; transition: width 0.9s ease; }

/* Progress bar in table */
.pct-wrap { display: flex; align-items: center; gap: 8px; min-width: 100px; }
.pct-bar  { flex: 1; height: 5px; border-radius: 3px; background: rgba(15,118,110,0.09); overflow: hidden; }
.pct-fill { height: 100%; border-radius: 3px; transition: width 0.9s ease; }
.pct-fill.hi { background: linear-gradient(90deg, #2dd4aa, #10b981); }
.pct-fill.md { background: linear-gradient(90deg, #f4a261, #fbbf24); }
.pct-fill.lo { background: linear-gradient(90deg, #e76f51, #f87171); }
.pct-num     { font-size: 0.76rem; font-weight: 700; white-space: nowrap; }
.pct-num.hi  { color: #2dd4aa; } .pct-num.md { color: #f4a261; } .pct-num.lo { color: #e76f51; }

/* Status mini-badge */
.st-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 22px; border-radius: 5px;
  font-size: 0.68rem; font-weight: 700; user-select: none;
}
.st-badge.P   { background: rgba(45,212,170,0.14); color: #2dd4aa; }
.st-badge.A   { background: rgba(231,111,81,0.14);  color: #e76f51; }
.st-badge.L   { background: rgba(244,162,97,0.14);  color: #f4a261; }
.st-badge.H   { background: rgba(59,130,246,0.14);  color: #60a5fa; }
.st-badge.LV  { background: rgba(139,92,246,0.14);  color: #a78bfa; }
.st-badge.ML  { background: rgba(6,182,212,0.14);   color: #22d3ee; }
.st-badge.HOL { background: rgba(100,116,139,0.14); color: #94a3b8; }
.st-badge.none { background: rgba(255,255,255,0.05); color: var(--muted); font-size: 0.55rem; }

/* Daily calendar strip */
.cal-strip { display: flex; gap: 2px; flex-wrap: nowrap; overflow-x: auto; max-width: 280px; }
.cal-dot {
  width: 18px; height: 18px; border-radius: 4px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 0.55rem; font-weight: 700; cursor: default;
}

/* Loading state */
.att-loading { padding: 60px; text-align: center; color: var(--muted); }
.att-loading i { font-size: 2rem; margin-bottom: 12px; display: block; opacity: 0.4; }

/* Attendance table min-width */
#att-data-table { min-width: 960px; table-layout: auto; }
#att-data-table th, #att-data-table td { white-space: nowrap; vertical-align: middle; }
#att-data-table td:first-child, #att-data-table th:first-child { width: 36px; text-align: center; }
/* Date badge columns — compact */
#att-data-table th.date-col { min-width: 34px; width: 34px; text-align: center; padding: 6px 3px; font-size: 0.6rem; }
#att-data-table td.date-col { text-align: center; padding: 6px 3px; }


/* ─── Timetable View ─────────────────────────────────────────────────────── */

/* Wizard step bar */
.tt-stepbar {
  display: flex; align-items: center; gap: 6px; margin-bottom: 20px;
  background: var(--card-bg); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 12px 18px; flex-wrap: wrap;
  box-shadow: 0 1px 4px rgba(15,118,110,0.06);
}
.tt-step {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.78rem; font-weight: 600; color: var(--muted);
  padding: 5px 12px; border-radius: 8px; border: 1px solid transparent;
  transition: all 0.18s;
}
.tt-step.active { color: var(--teal); background: rgba(15,118,110,0.08); border-color: rgba(15,118,110,0.20); }
.tt-step-num {
  width: 22px; height: 22px; border-radius: 50%; background: var(--border2);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.68rem; font-weight: 800; color: var(--muted);
}
.tt-step.active .tt-step-num { background: var(--teal); color: #fff; }
.tt-step-arrow { color: var(--muted); font-size: 0.65rem; opacity: 0.5; }

/* Wizard panels */
.tt-wizard-panel { display: none; }
.tt-wizard-panel.active { display: block; }

/* Form input style for wizard */
.form-input {
  background: rgba(15,118,110,0.04); border: 1px solid var(--border2);
  border-radius: var(--radius-sm); color: var(--text);
  font-family: var(--font-body); font-size: 0.85rem;
  padding: 10px 13px; outline: none; transition: border-color 0.2s;
  width: 100%;
}
.form-input:focus { border-color: var(--teal); }
.form-input option { background: var(--white); }

/* Grid */
.tt-grid-wrap { overflow-x: auto; padding: 16px 20px; }
.tt-grid {
  width: 100%; border-collapse: separate; border-spacing: 3px; min-width: 700px;
}
.tt-grid th {
  padding: 9px 10px; text-align: center; font-size: 0.69rem; font-weight: 700;
  letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted);
  background: rgba(15,118,110,0.05); border-radius: 8px;
}
.tt-grid th.day-h { color: var(--teal); background: rgba(20,184,166,0.07); border: 1px solid rgba(20,184,166,0.15); }
.tt-grid th.today-h { color: #10b981; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); }
.tt-grid td {
  height: 80px; vertical-align: top; border-radius: 10px; position: relative;
  background: rgba(15,118,110,0.02); border: 1px solid var(--border2); min-width: 105px;
}
.tt-grid td.break-td { background: rgba(245,158,11,0.04); border-color: rgba(245,158,11,0.12); }
.tt-grid td.lunch-td { background: rgba(16,185,129,0.04); border-color: rgba(16,185,129,0.12); }
.tt-grid td.empty-editable { cursor: pointer; transition: all 0.18s; }
.tt-grid td.empty-editable:hover { background: rgba(20,184,166,0.05); border-color: rgba(20,184,166,0.3); }
.tt-grid td.filled { cursor: pointer; transition: border-color 0.18s; }
.tt-grid td.filled:hover { border-color: rgba(20,184,166,0.4); }
.tt-time-col {
  font-size: 0.69rem; font-family: monospace; color: var(--muted);
  background: transparent !important; border: none !important;
  text-align: right; padding-right: 8px; min-width: 88px; cursor: default !important;
}
.tt-time-col:hover { background: transparent !important; }
.tt-slot-inner {
  padding: 7px 9px; height: 100%; display: flex;
  flex-direction: column; justify-content: space-between;
}
.tt-s-name { font-size: 0.76rem; font-weight: 700; color: var(--text); line-height: 1.2; }
.tt-s-code { font-size: 0.63rem; color: var(--muted); margin-top: 1px; font-family: monospace; }
.tt-s-room { font-size: 0.62rem; color: var(--teal); margin-top: 2px; }
.tt-s-fac  { font-size: 0.61rem; color: var(--muted); margin-top: 1px; }
.tt-add-hint {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  color: var(--teal); font-size: 1.2rem; opacity: 0; transition: opacity 0.18s; pointer-events: none;
}
.tt-grid td.empty-editable:hover .tt-add-hint { opacity: 1; }
.tt-del-btn {
  position: absolute; top: 3px; right: 3px; width: 20px; height: 20px;
  border-radius: 4px; border: none; background: rgba(239,68,68,0.14);
  color: #ef4444; font-size: 0.58rem; cursor: pointer; display: flex;
  align-items: center; justify-content: center; opacity: 0; transition: opacity 0.18s;
}
.tt-grid td.filled:hover .tt-del-btn { opacity: 1; }
.tt-break-lbl {
  height: 100%; display: flex; align-items: center; justify-content: center;
  gap: 6px; font-size: 0.72rem; font-weight: 700; color: #f59e0b;
}
.tt-lunch-lbl { height:100%;display:flex;align-items:center;justify-content:center;gap:6px;font-size:0.72rem;font-weight:700;color:#10b981; }

/* Color accents per course */
.tc1 .tt-slot-inner { border-left: 3px solid #0F766E; }
.tc2 .tt-slot-inner { border-left: 3px solid #f59e0b; }
.tc3 .tt-slot-inner { border-left: 3px solid #a78bfa; }
.tc4 .tt-slot-inner { border-left: 3px solid #ef4444; }
.tc5 .tt-slot-inner { border-left: 3px solid #60a5fa; }
.tc6 .tt-slot-inner { border-left: 3px solid #10b981; }
.tc7 .tt-slot-inner { border-left: 3px solid #f472b6; }
.tc8 .tt-slot-inner { border-left: 3px solid #fb923c; }

/* Overview table */
.tt-overview-table { min-width: 700px; }

/* Setup card */
.tt-setup-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; padding: 20px; }
.tt-setup-card {
  background: rgba(15,118,110,0.04); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 18px;
}
.tt-setup-card h4 { font-size: 0.85rem; font-weight: 700; color: var(--text); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.tt-setup-card h4 i { color: var(--teal); }
.tt-setup-mini-form { display: flex; flex-direction: column; gap: 10px; }
.tt-setup-mini-form input, .tt-setup-mini-form select {
  background: rgba(15,118,110,0.04); border: 1px solid var(--border2);
  border-radius: var(--radius-sm); color: var(--text);
  font-size: 0.82rem; padding: 8px 11px; outline: none; width: 100%;
  transition: border-color 0.2s; font-family: var(--font-body);
}
.tt-setup-mini-form input:focus, .tt-setup-mini-form select:focus { border-color: var(--teal); }
.tt-setup-mini-form select option { background: var(--white); }

/* Legend */
.tt-legend { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px 20px; border-top: 1px solid var(--border2); font-size: 0.71rem; }
.tt-legend-item { display: flex; align-items: center; gap: 5px; }

/* Status badge */
.tt-status { font-size: 0.68rem; font-weight: 700; padding: 3px 8px; border-radius: 5px; display: inline-block; }
.tt-status.active { background: rgba(16,185,129,0.12); color: #10b981; }
.tt-status.draft  { background: rgba(245,158,11,0.12);  color: #f59e0b; }

/* Empty state */
.tt-empty { padding: 50px 24px; text-align: center; color: var(--muted); }
.tt-empty i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.3; }
.tt-empty p { font-size: 0.84rem; }


/* ─── Exam Management — card/input/btn aliases ───────────────────────────── */
.card {
  background: var(--card-bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  overflow: hidden;
  margin-bottom: 18px;
}
.card-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.card-header h3 {
  font-family: var(--font-head);
  font-size: 1rem;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
}
.card-header h3 i { color: var(--teal); }
.card-header small { font-size: 0.76rem; color: var(--muted); display: block; margin-top: 2px; }
.input-field {
  background: var(--white);
  border: 1.5px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 0.85rem;
  padding: 11px 14px;
  outline: none;
  transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
  width: 100%;
  box-sizing: border-box;
  -webkit-appearance: none;
  appearance: none;
  line-height: 1.5;
  min-height: 42px;
}
.input-field::placeholder { color: #a0aec0; }
.input-field:hover:not(:disabled) { border-color: rgba(15,118,110,0.40); }
.input-field:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(15,118,110,0.12); background: #fff; }
.input-field:disabled { background: rgba(15,118,110,0.04); color: var(--muted); cursor: not-allowed; opacity: 0.7; }
.input-field option { background: var(--white); }
select.input-field {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748B' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: 36px;
  cursor: pointer;
}
textarea.input-field { min-height: 70px; resize: vertical; line-height: 1.6; }
.form-label {
  display: block;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  margin-bottom: 5px;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: var(--teal);
  color: #fff;
  font-family: var(--font-body);
  font-size: 0.82rem;
  font-weight: 700;
  border: none;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
  white-space: nowrap;
}
.btn:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-sm { padding: 5px 10px; font-size: 0.75rem; }
.notification-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  padding: 14px 20px;
  border-radius: var(--radius-sm);
  font-size: 0.86rem;
  font-weight: 600;
  min-width: 240px;
  display: flex;
  align-items: center;
  gap: 10px;
  opacity: 0;
  transform: translateY(12px);
  transition: all 0.3s ease;
  pointer-events: none;
}
.notification-toast.show { opacity: 1; transform: translateY(0); }
.notification-toast.success { background: rgba(22,163,74,0.10); color: var(--green); border: 1px solid rgba(22,163,74,0.22); }
.notification-toast.warning { background: rgba(245,158,11,0.12); color: var(--amber-dark); border: 1px solid rgba(245,158,11,0.25); }
.notification-toast.error   { background: rgba(220,38,38,0.10);  color: var(--red);   border: 1px solid rgba(220,38,38,0.22); }

/* ─── Scrollbar ─────────────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(15,118,110,.18); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(15,118,110,.35); }
</style>
</head>
<body>
<div class="bg-canvas"></div>
<div class="bg-grid"></div>

<!-- ══════════════════════════════════════════════════════════
     COLLEGE SELECTION OVERLAY (shown when no college chosen)
     ══════════════════════════════════════════════════════════ -->
<div id="collegeSelectOverlay" style="display:<?= $saCollegeId ? 'none' : 'flex' ?>;
  position:fixed;inset:0;z-index:9000;
  background:rgba(6,14,24,0.97);backdrop-filter:blur(12px);
  flex-direction:column;align-items:center;justify-content:center;
  padding:40px 20px;">
  <!-- Logo -->
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:36px">
    <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#14B8A6,#0F766E);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--text);font-weight:800">P</div>
    <div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.5rem;font-weight:800;color:#fff">PEPA ERP</div>
      <div style="font-size:.72rem;color:#0F766E;letter-spacing:.12em;text-transform:uppercase">Super Admin Portal</div>
    </div>
  </div>

  <!-- Step 1: Choose College -->
  <div id="csStep1" style="width:100%;max-width:600px">
    <div style="text-align:center;margin-bottom:28px">
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.8rem;font-weight:800;color:#fff;margin-bottom:8px">Choose a College</div>
      <div style="font-size:.9rem;color:#7a93ac">Select the college you want to manage in this session</div>
    </div>
    <div id="csCollegeList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:20px">
      <?php
      $dbCS = getDB();
      $csColleges = [];
      if ($dbCS) {
          $csStmt = $dbCS->query('SELECT id,name,code,established,status,
              (SELECT COUNT(*) FROM departments d WHERE d.college_id=colleges.id) AS dept_count,
              (SELECT COUNT(*) FROM users u WHERE u.college_id=colleges.id AND u.role="student") AS student_count
              FROM colleges ORDER BY name');
          $csColleges = $csStmt->fetchAll(PDO::FETCH_ASSOC);
      }
      foreach ($csColleges as $csC):
        $active = $csC['status'] === 'active';
      ?>
      <div class="cs-college-card"
        onclick="csSelectCollege(<?= $csC['id'] ?>,'<?= addslashes(htmlspecialchars($csC['name'])) ?>','<?= htmlspecialchars($csC['code']) ?>')"
        style="background:#ffffff;border:1px solid rgba(255,255,255,0.1);border-radius:14px;
          padding:20px;cursor:pointer;transition:all .22s">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px">
          <div>
            <div style="font-size:.68rem;color:#0F766E;font-family:monospace;font-weight:700;letter-spacing:.06em;margin-bottom:4px"><?= htmlspecialchars($csC['code']) ?></div>
            <div style="font-weight:700;font-size:1rem;color:var(--text);line-height:1.25"><?= htmlspecialchars($csC['name']) ?></div>
          </div>
          <span style="padding:3px 9px;border-radius:50px;font-size:.62rem;font-weight:700;
            background:<?= $active?'rgba(45,212,170,.15)':'rgba(122,147,172,.12)' ?>;
            color:<?= $active?'#2dd4aa':'#7a93ac' ?>;">
            <?= strtoupper($csC['status']) ?>
          </span>
        </div>
        <div style="display:flex;gap:12px;margin-top:8px">
          <span style="font-size:.75rem;color:#7a93ac"><i class="fas fa-sitemap" style="color:#0F766E;font-size:.68rem"></i> <?= (int)$csC['dept_count'] ?> Depts</span>
          <span style="font-size:.75rem;color:#7a93ac"><i class="fas fa-user-graduate" style="color:#0F766E;font-size:.68rem"></i> <?= (int)$csC['student_count'] ?> Students</span>
          <?php if ($csC['established']): ?><span style="font-size:.75rem;color:#7a93ac">Est. <?= htmlspecialchars($csC['established']) ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($csColleges)): ?>
      <div style="grid-column:1/-1;text-align:center;color:#7a93ac;padding:40px">No colleges found. Add a college from the full dashboard.</div>
      <?php endif; ?>
    </div>
    <div style="text-align:center">
      <button onclick="csSuperAdminMode()"
        style="background:none;border:1px solid rgba(122,147,172,.3);border-radius:8px;color:#7a93ac;padding:10px 22px;cursor:pointer;font-size:.82rem;font-family:'Plus Jakarta Sans',sans-serif;transition:all .2s"
        onmouseover="this.style.borderColor='rgba(20,184,166,.4)';this.style.color='#00c6ae'"
        onmouseout="this.style.borderColor='rgba(122,147,172,.3)';this.style.color='#7a93ac'">
        <i class="fas fa-shield-halved"></i> &nbsp;Continue as Super Admin (full access)
      </button>
    </div>
  </div>

  <!-- Step 1.5: Verify College Access Code -->
  <div id="csStep1b" style="display:none;width:100%;max-width:420px">
    <div style="text-align:center;margin-bottom:28px">
      <button onclick="csBackToStep1()" style="background:none;border:none;color:#7a93ac;cursor:pointer;font-size:.82rem;margin-bottom:16px"><i class="fas fa-arrow-left"></i> Back</button>
      <div style="width:60px;height:60px;border-radius:50%;background:rgba(20,184,166,.12);border:2px solid rgba(20,184,166,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.5rem;color:#0F766E">
        <i class="fas fa-lock"></i>
      </div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:6px">College Access Code</div>
      <div id="csStep1bCollegeName" style="font-size:.88rem;color:#0F766E;font-weight:600;margin-bottom:8px"></div>
      <div style="font-size:.82rem;color:#7a93ac">Enter the access code assigned to this college to continue</div>
    </div>
    <div style="background:#f8fafc;border:1px solid rgba(20,184,166,.2);border-radius:14px;padding:24px">
      <label style="font-size:.75rem;font-weight:700;color:#7a93ac;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px">Access Code *</label>
      <input id="csCollegeCodeInput" type="password"
        placeholder="Enter college access code"
        style="background:#fff;border:1px solid rgba(15,118,110,.25);border-radius:8px;color:#0F172A;padding:11px 14px;font-size:.92rem;width:100%;outline:none;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif"
        onkeydown="if(event.key==='Enter')csVerifyCollegeCode()"
        onfocus="this.style.borderColor='rgba(20,184,166,.5)'" onblur="this.style.borderColor='rgba(15,118,110,.25)'">
      <div id="csCollegeCodeErr" style="color:#ef4444;font-size:.75rem;margin-top:6px;display:none"></div>
      <button onclick="csVerifyCollegeCode()" id="csCollegeCodeBtn"
        style="margin-top:16px;width:100%;background:linear-gradient(135deg,#14B8A6,#0F766E);color:#fff;border:none;border-radius:8px;padding:12px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
        <i class="fas fa-unlock"></i> &nbsp;Verify &amp; Continue
      </button>
    </div>
  </div>

  <!-- Step 2: Choose Role (Principal or HOD) -->
  <div id="csStep2" style="display:none;width:100%;max-width:520px">
    <div style="text-align:center;margin-bottom:28px">
      <button onclick="csBackToStep1b()" style="background:none;border:none;color:#7a93ac;cursor:pointer;font-size:.82rem;margin-bottom:16px"><i class="fas fa-arrow-left"></i> Back</button>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.6rem;font-weight:800;color:#fff;margin-bottom:6px">Select Your Role</div>
      <div id="csSelectedCollegeName" style="font-size:.88rem;color:#0F766E;font-weight:600"></div>
      <div style="font-size:.82rem;color:#7a93ac;margin-top:4px">Are you the Principal or a Head of Department?</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
      <!-- Principal Card -->
      <div onclick="csSelectRole('principal')"
        style="background:#f8fafc;border:2px solid rgba(20,184,166,.2);border-radius:16px;padding:28px 20px;cursor:pointer;text-align:center;transition:all .22s"
        onmouseover="this.style.borderColor='#00c6ae';this.style.background='rgba(20,184,166,.06)'"
        onmouseout="this.style.borderColor='rgba(20,184,166,.2)';this.style.background='rgba(21,35,54,0.9)'">
        <div style="width:56px;height:56px;border-radius:50%;background:rgba(20,184,166,.15);border:2px solid rgba(20,184,166,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.4rem;color:#0F766E">
          <i class="fas fa-user-tie"></i>
        </div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:6px">Principal</div>
        <div style="font-size:.74rem;color:#7a93ac;line-height:1.5">Full college management,<br>exams, reports &amp; more</div>
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:4px;text-align:left">
          <?php foreach(['Manage departments','Add/Edit faculty & students','Create & schedule exams','Generate admit cards','Manage timetables','View analytics'] as $feat): ?>
          <div style="font-size:.68rem;color:#7a93ac"><i class="fas fa-check" style="color:#0F766E;font-size:.6rem;margin-right:4px"></i><?= $feat ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- HOD Card -->
      <div onclick="csSelectRole('hod')"
        style="background:#f8fafc;border:2px solid rgba(244,162,97,.2);border-radius:16px;padding:28px 20px;cursor:pointer;text-align:center;transition:all .22s"
        onmouseover="this.style.borderColor='#f4a261';this.style.background='rgba(244,162,97,.05)'"
        onmouseout="this.style.borderColor='rgba(244,162,97,.2)';this.style.background='rgba(21,35,54,0.9)'">
        <div style="width:56px;height:56px;border-radius:50%;background:rgba(244,162,97,.15);border:2px solid rgba(244,162,97,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.4rem;color:#D97706">
          <i class="fas fa-chalkboard-teacher"></i>
        </div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:6px">Head of Department</div>
        <div style="font-size:.74rem;color:#7a93ac;line-height:1.5">Department-level access,<br>faculty &amp; course oversight</div>
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:4px;text-align:left">
          <?php foreach(['View dept dashboard','Assign faculty to subjects','Upload question papers','Suggest timetable changes','View exam schedules','Generate dept reports'] as $feat): ?>
          <div style="font-size:.68rem;color:#7a93ac"><i class="fas fa-check" style="color:#D97706;font-size:.6rem;margin-right:4px"></i><?= $feat ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Step 2.5: Verify Role Access Code -->
  <!-- Step 2b — PRINCIPAL: verify with college code again -->
  <div id="csStep2b_principal" style="display:none;width:100%;max-width:420px">
    <div style="text-align:center;margin-bottom:28px">
      <button onclick="csBackToStep2()" style="background:none;border:none;color:#7a93ac;cursor:pointer;font-size:.82rem;margin-bottom:16px"><i class="fas fa-arrow-left"></i> Back</button>
      <div style="width:60px;height:60px;border-radius:50%;background:rgba(20,184,166,.12);border:2px solid rgba(20,184,166,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.5rem;color:#0F766E"><i class="fas fa-user-tie"></i></div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:6px">Confirm Principal Access</div>
      <div id="cs2bpCollegeName" style="font-size:.88rem;color:#0F766E;font-weight:600;margin-bottom:8px"></div>
      <div style="font-size:.82rem;color:#7a93ac">Re-enter the college access code to confirm</div>
    </div>
    <div style="background:#f8fafc;border:1px solid rgba(20,184,166,.2);border-radius:14px;padding:24px">
      <label style="font-size:.75rem;font-weight:700;color:#7a93ac;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px">College Access Code *</label>
      <input id="csPrincipalCodeInput" type="password" placeholder="Re-enter access code"
        style="background:#fff;border:1px solid rgba(15,118,110,.25);border-radius:8px;color:#0F172A;padding:11px 14px;font-size:.92rem;width:100%;outline:none;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif"
        onkeydown="if(event.key==='Enter')csVerifyPrincipalCode()"
        onfocus="this.style.borderColor='rgba(20,184,166,.5)'" onblur="this.style.borderColor='rgba(15,118,110,.25)'">
      <div id="csPrincipalCodeErr" style="color:#ef4444;font-size:.75rem;margin-top:6px;display:none"></div>
      <button onclick="csVerifyPrincipalCode()" id="csPrincipalCodeBtn"
        style="margin-top:16px;width:100%;background:linear-gradient(135deg,#14B8A6,#0F766E);color:#fff;border:none;border-radius:8px;padding:12px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
        <i class="fas fa-unlock"></i> &nbsp;Verify &amp; Enter Dashboard
      </button>
    </div>
  </div>

  <!-- Step 2b HOD: pick dept then enter principal-generated code -->
  <div id="csStep2b_hod" style="display:none;width:100%;max-width:460px">
    <div style="text-align:center;margin-bottom:24px">
      <button onclick="csBackToStep2()" style="background:none;border:none;color:#7a93ac;cursor:pointer;font-size:.82rem;margin-bottom:16px"><i class="fas fa-arrow-left"></i> Back</button>
      <div style="width:60px;height:60px;border-radius:50%;background:rgba(244,162,97,.12);border:2px solid rgba(244,162,97,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.5rem;color:#D97706"><i class="fas fa-chalkboard-teacher"></i></div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;margin-bottom:4px">HOD Access</div>
      <div id="cs2bHodCollegeName" style="font-size:.88rem;color:#0F766E;font-weight:600;margin-bottom:4px"></div>
      <div style="font-size:.8rem;color:#7a93ac">Select your department, then enter the code given by your Principal</div>
    </div>
    <div id="csHodStep_dept" style="background:#f8fafc;border:1px solid rgba(244,162,97,.25);border-radius:14px;padding:22px;margin-bottom:14px">
      <label style="font-size:.75rem;font-weight:700;color:#7a93ac;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:8px"><i class="fas fa-sitemap" style="color:#D97706;margin-right:4px"></i> Step 1 of 2 &mdash; Your Department *</label>
      <select id="csHodDeptSel" onchange="csHodDeptSelected()"
        style="background:#fff;border:1px solid rgba(15,118,110,.25);border-radius:8px;color:#0F172A;padding:11px 14px;font-size:.88rem;width:100%;outline:none;cursor:pointer">
        <option value="">— Select Your Department —</option>
      </select>
    </div>
    <div id="csHodStep_code" style="display:none;background:#f8fafc;border:1px solid rgba(244,162,97,.25);border-radius:14px;padding:22px">
      <label style="font-size:.75rem;font-weight:700;color:#7a93ac;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px"><i class="fas fa-lock" style="color:#D97706;margin-right:4px"></i> Step 2 of 2 &mdash; HOD Access Code *</label>
      <div style="font-size:.72rem;color:#7a93ac;margin-bottom:10px">This one-time code was generated by your Principal for your department</div>
      <input id="csHodCodeInput" type="password" placeholder="Enter the code from your Principal"
        style="background:#fff;border:1px solid rgba(15,118,110,.25);border-radius:8px;color:#0F172A;padding:11px 14px;font-size:.92rem;width:100%;outline:none;box-sizing:border-box;font-family:'Plus Jakarta Sans',sans-serif"
        onkeydown="if(event.key==='Enter')csVerifyHodCode()"
        onfocus="this.style.borderColor='rgba(244,162,97,.6)'" onblur="this.style.borderColor='rgba(15,118,110,.25)'">
      <div id="csHodCodeErr" style="color:#ef4444;font-size:.75rem;margin-top:6px;display:none"></div>
      <button onclick="csVerifyHodCode()" id="csHodCodeBtn"
        style="margin-top:14px;width:100%;background:linear-gradient(135deg,#f4a261,#e07a3a);color:var(--text);border:none;border-radius:8px;padding:12px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
        <i class="fas fa-unlock"></i> &nbsp;Verify &amp; Enter HOD Dashboard
      </button>
    </div>
  </div>
</div>
<!-- END OVERLAY -->

<!-- ── Inline style for college card hover ── -->
<style>
.cs-college-card:hover {
  border-color: rgba(20,184,166,.6) !important;
  background: rgba(20,184,166,.07) !important;
  transform: translateY(-3px);
  box-shadow: 0 10px 30px rgba(13,92,86,.3);
}
/* Role-based nav visibility */
.nav-principal-only  { display: none; }
.nav-finance-section { display: none; }   /* hidden by default, shown below */
.nav-hod-only        { display: none; }
.nav-sa-only         { display: none; }
/* nav-col-mgmt: shown to both principal and SA, but NOT hod-only items */
.nav-col-mgmt        { display: none; }

body.role-superadmin .nav-sa-only        { display: flex; }
body.role-superadmin .nav-col-mgmt       { display: flex; }
body.role-principal  .nav-principal-only { display: flex; }
body.role-principal  .nav-col-mgmt       { display: flex; }
/* Finance section: show for principal, superadmin, and HOD */
body.role-superadmin .nav-finance-section { display: block; }
body.role-principal  .nav-finance-section { display: block; }
body.role-hod        .nav-finance-section { display: block !important; }

/* Expense Applications styles */
.exp-pill { display:inline-block; font-size:.62rem; font-weight:700; padding:3px 9px; border-radius:6px; }
.exp-pill.pending   { background:rgba(245,158,11,.15);  color:#f59e0b; }
.exp-pill.review    { background:rgba(139,92,246,.15);  color:#8b5cf6; }
.exp-pill.approved  { background:rgba(16,185,129,.15);  color:#10b981; }
.exp-pill.rejected  { background:rgba(239,68,68,.15);   color:#ef4444; }
.exp-pill.disbursed { background:rgba(59,130,246,.15);  color:#3b82f6; }
.exp-pill.low       { background:rgba(16,185,129,.12);  color:#10b981; }
.exp-pill.normal    { background:rgba(20,184,166,.12);   color:#0F766E; }
.exp-pill.high      { background:rgba(245,158,11,.12);  color:#f59e0b; }
.exp-pill.urgent    { background:rgba(239,68,68,.12);   color:#ef4444; }
.exp-action-btn { display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:7px;border:none;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .2s; }
.exp-action-btn:hover { opacity:.8; }
.exp-action-btn.approve  { background:rgba(16,185,129,.2); color:#10b981; }
.exp-action-btn.reject   { background:rgba(239,68,68,.2);  color:#ef4444; }
.exp-action-btn.disburse { background:rgba(59,130,246,.2); color:#3b82f6; }
.exp-action-btn.review   { background:rgba(139,92,246,.2); color:#8b5cf6; }
.exp-filter-bar { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px; }
.exp-filter-bar select, .exp-filter-bar input {
  padding:7px 12px;background:rgba(15,118,110,.04);border:1px solid var(--border2);
  border-radius:8px;color:var(--text);font-family:inherit;font-size:.82rem;outline:none;
}
.exp-stat-bar { display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:18px; }
.exp-stat-card { background:var(--card-bg);border:1px solid var(--border2);border-radius:10px;padding:12px 16px;box-shadow:0 1px 4px rgba(15,118,110,.06); }
.exp-stat-num  { font-size:1.5rem;font-weight:800;line-height:1;margin-bottom:4px;color:var(--text); }
.exp-stat-lbl  { font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em; }
.exp-detail-row { background:rgba(15,118,110,.05);border-radius:8px;padding:12px 16px;margin-top:6px;font-size:.78rem;color:var(--text);line-height:1.7; }
/* HOD: show HOD-only sections, hide col-mgmt section entirely */
body.role-hod        .nav-hod-only                 { display: flex !important; }
body.role-hod        .nav-section.nav-hod-only     { display: block !important; }
body.role-hod        .nav-col-mgmt-section         { display: none !important; }
/* nav-section visibility */
.nav-section.nav-sa-only                 { display: block; }
body.role-superadmin .nav-section.nav-sa-only  { display: block; }
body.role-principal  .nav-section.nav-sa-only  { display: none !important; }
body.role-hod        .nav-section.nav-sa-only  { display: none !important; }
/* Role badge variants */
.role-badge-principal { background: rgba(20,184,166,.15); color: #0F766E; border: 1px solid rgba(20,184,166,.3); }
.role-badge-hod       { background: rgba(244,162,97,.15); color: #f4a261; border: 1px solid rgba(244,162,97,.3); }
.role-badge-sa        { background: rgba(20,184,166,.15); color: #0F766E; border: 1px solid rgba(20,184,166,.25); }
/* HOD-restricted access: dim & block restricted views when in HOD mode */
body.role-hod .hod-restricted {
  pointer-events: none;
  opacity: .35;
}
/* Principal-restricted */
body.role-principal .principal-restricted {
  pointer-events: none;
  opacity: .35;
}
</style>

<div class="app-shell">
  <!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon">P</div>
      <div>
        <div class="logo-text">PEPA</div>
        <div class="logo-sub">ERP System</div>
      </div>
    </div>

    <div class="sidebar-admin">
      <?php $logoUrl = $saCollegeLogo ? '../uploads/college_assets/' . rawurlencode($saCollegeLogo) : ''; ?>
      <div class="admin-avatar" id="sidebarAdminAvatar" style="position:relative;overflow:hidden">
        <img id="sidebarAdminAvatarImg" src="<?= htmlspecialchars($logoUrl) ?>" alt=""
             style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;<?= $logoUrl ? '' : 'display:none;' ?>border-radius:9px">
        <span id="sidebarAdminAvatarInitials"<?= $logoUrl ? ' style="display:none"' : '' ?>><?= strtoupper(substr($adminName, 0, 2)) ?></span>
      </div>
      <div class="admin-info">
        <div class="admin-name"><?= $adminName ?></div>
        <div class="admin-badge" id="sidebarRoleBadge">
          <i class="fas fa-shield-halved"></i>
          <span id="sidebarRoleLabel">Super Admin</span>
        </div>
      </div>
    </div>

    <!-- College context bar -->
    <div id="sidebarCollegeCtx" style="<?= $saCollegeId ? '' : 'display:none' ?>;margin:8px 12px 4px;padding:10px 14px 12px;border-radius:12px;border:1px solid rgba(20,184,166,.25);background:rgba(20,184,166,.08);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 4px 16px rgba(0,0,0,.18),inset 0 1px 0 rgba(255,255,255,.08)">
      <div style="font-size:.6rem;color:rgba(20,184,166,.85);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px;font-weight:600">Managing College</div>
      <div id="sidebarCollegeName" style="font-size:.84rem;font-weight:700;color:#f0fdfa;margin-bottom:4px;line-height:1.3"><?= htmlspecialchars($saCollegeName) ?></div>
      <?php if ($saCollegeRole === 'hod' && $saDeptId):
        $deptNameDisplay = '';
        try {
          $dnDb = getDB();
          $dnSt = $dnDb->prepare('SELECT name FROM departments WHERE id=?');
          $dnSt->execute([$saDeptId]);
          $dnRow = $dnSt->fetch(PDO::FETCH_ASSOC);
          $deptNameDisplay = $dnRow ? $dnRow['name'] : '';
        } catch(Exception $e) {}
      ?>
      <div id="sidebarHodDept" style="font-size:.72rem;color:#fbbf24;margin-bottom:8px;display:flex;align-items:center;gap:5px">
        <i class="fas fa-sitemap" style="font-size:.62rem;opacity:.9"></i>
        <span><?= htmlspecialchars($deptNameDisplay) ?></span>
      </div>
      <?php else: ?>
      <div id="sidebarHodDept" style="display:none;font-size:.72rem;color:#fbbf24;margin-bottom:8px;align-items:center;gap:5px">
        <i class="fas fa-sitemap" style="font-size:.62rem;opacity:.9"></i>
        <span></span>
      </div>
      <?php endif; ?>
      <button onclick="csSwitchCollege()" style="background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.35);border-radius:7px;color:#5eead4;padding:5px 10px;font-size:.7rem;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .2s;width:100%;backdrop-filter:blur(4px)" onmouseover="this.style.background='rgba(20,184,166,.22)';this.style.color='#ccfbf1'" onmouseout="this.style.background='rgba(20,184,166,.12)';this.style.color='#5eead4'">
        <i class="fas fa-arrows-rotate"></i> Switch College / Role
      </button>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section">
        <div class="nav-section-label">
          <i class="fas fa-chart-line"></i>
          Overview
        </div>
        <a href="#" class="nav-link active" data-view="dashboard">
          <i class="fas fa-home"></i>
          <span>Dashboard</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="analytics">
          <i class="fas fa-chart-bar"></i>
          <span>Analytics &amp; Reports</span>
        </a>
      </div>

      <!-- SA ONLY: Global Management — hidden from Principal/HOD -->
      <div class="nav-section nav-sa-only">
        <div class="nav-section-label">
          <i class="fas fa-globe"></i>
          Global Management
        </div>
        <a href="#" class="nav-link" data-view="colleges">
          <i class="fas fa-building"></i>
          <span>Colleges</span>
          <span class="nav-badge"><?= $stats['total_colleges'] ?></span>
        </a>
        <a href="#" class="nav-link" data-view="users">
          <i class="fas fa-users"></i>
          <span>All Users</span>
        </a>
      </div>

      <!-- Principal + SA: College Management -->
      <div class="nav-section nav-col-mgmt-section">
        <div class="nav-section-label">
          <i class="fas fa-cog"></i>
          College Management
        </div>
        <a href="#" class="nav-link nav-col-mgmt" data-view="departments">
          <i class="fas fa-sitemap"></i>
          <span>Departments</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="courses">
          <i class="fas fa-book"></i>
          <span>Courses</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="faculty">
          <i class="fas fa-chalkboard-teacher"></i>
          <span>Faculty</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="students">
          <i class="fas fa-user-graduate"></i>
          <span>Students</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="faculty-assignments">
          <i class="fas fa-user-check"></i>
          <span>Faculty Assignments</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="attendance">
          <i class="fas fa-clipboard-check"></i>
          <span>Attendance</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="timetable">
          <i class="fas fa-calendar-days"></i>
          <span>Timetable</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="exam-management">
          <i class="fas fa-file-invoice"></i>
          <span>Exam Management</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="staff-noticeboard">
          <i class="fas fa-clipboard-list"></i>
          <span>Staff Noticeboard</span>
          <span id="noticeNavBadge" style="display:none;margin-left:auto;background:rgba(20,184,166,.8);color:#080f1a;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:monospace"></span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="hod-marks-viewer">
          <i class="fas fa-chart-simple"></i>
          <span>Marks Viewer</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="hod-course-marks">
          <i class="fas fa-book-open"></i>
          <span>Course Marks</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="question-papers-viewer">
          <i class="fas fa-file-lines"></i>
          <span>Question Papers</span>
        </a>
        <a href="#" class="nav-link nav-col-mgmt" data-view="college-profile">
          <i class="fas fa-building-columns" style="color:#38bdf8"></i>
          <span>College Profile</span>
        </a>
        <!-- Principal only: manage HOD access codes -->
        <a href="#" class="nav-link nav-principal-only" data-view="hod-access-codes"
           style="border-left:2px solid rgba(244,162,97,.4)">
          <i class="fas fa-key" style="color:#D97706"></i>
          <span style="color:#D97706">HOD Access Codes</span>
        </a>
        <!-- Principal only: Admission Applications -->
        <a href="#" class="nav-link nav-principal-only" data-view="admission-applications"
           style="border-left:2px solid rgba(124,58,237,.4)">
          <i class="fas fa-user-graduate" style="color:#7c3aed"></i>
          <span style="color:#7c3aed">Admissions</span>
          <span id="admNavBadge" style="display:none;margin-left:auto;background:#7c3aed;color:#fff;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:monospace"></span>
        </a>
      </div><!-- /nav col-mgmt section -->

      <!-- HOD only: Department section (replaces College Management entirely) -->
      <div class="nav-section nav-hod-only" style="display:none">
        <div class="nav-section-label">
          <i class="fas fa-sitemap"></i>
          Department
        </div>
        <a href="#" class="nav-link" data-view="students">
          <i class="fas fa-user-graduate"></i>
          <span>Dept Students</span>
        </a>
        <a href="#" class="nav-link" data-view="faculty-assignments">
          <i class="fas fa-user-check"></i>
          <span>Faculty &amp; Assignments</span>
        </a>
        <a href="#" class="nav-link" data-view="academic-details">
          <i class="fas fa-layer-group"></i>
          <span>Academic Details</span>
        </a>
        <a href="#" class="nav-link" data-view="attendance">
          <i class="fas fa-clipboard-check"></i>
          <span>Attendance</span>
        </a>
        <a href="#" class="nav-link" data-view="timetable">
          <i class="fas fa-calendar-days"></i>
          <span>Timetable</span>
        </a>
        <a href="#" class="nav-link" data-view="exam-management">
          <i class="fas fa-file-invoice"></i>
          <span>Exam Schedules</span>
        </a>
        <a href="#" class="nav-link" data-view="staff-noticeboard">
          <i class="fas fa-clipboard-list"></i>
          <span>Staff Noticeboard</span>
        </a>
        <a href="#" class="nav-link" data-view="hod-marks-viewer">
          <i class="fas fa-chart-simple"></i>
          <span>Marks Viewer</span>
        </a>
        <a href="#" class="nav-link" data-view="hod-course-marks">
          <i class="fas fa-book-open"></i>
          <span>Course Marks</span>
        </a>
        <a href="#" class="nav-link" data-view="question-papers-viewer">
          <i class="fas fa-file-lines"></i>
          <span>Question Papers</span>
        </a>
        <a href="#" class="nav-link" data-view="college-profile">
          <i class="fas fa-building-columns" style="color:#38bdf8"></i>
          <span>College Profile</span>
        </a>
        <a href="#" class="nav-link" data-view="activity">
          <i class="fas fa-history"></i>
          <span>Dept Activity</span>
        </a>
      </div><!-- /nav hod dept section -->

      <!-- Accounts nav — visible to principal AND superadmin (not hod) -->
      <div class="nav-section nav-finance-section">
        <div class="nav-section-label">
          <i class="fas fa-coins"></i>
          Finance
        </div>
        <a href="#" class="nav-link nav-col-mgmt" data-view="accountant-credentials">
          <i class="fas fa-coins" style="color:#0F766E"></i>
          <span>Accountant Credentials</span>
        </a>
        <a href="#" class="nav-link" data-view="expense-applications">
          <i class="fas fa-file-invoice-dollar" style="color:#f59e0b"></i>
          <span>Expense Applications</span>
          <span id="expNavBadge" style="display:none;margin-left:auto;background:#f59e0b;color:#080f1a;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:monospace"></span>
        </a>
        <a href="#" class="nav-link" data-view="leave-applications">
          <i class="fas fa-calendar-minus" style="color:#a78bfa"></i>
          <span>Leave Applications</span>
          <span id="leaveNavBadge" style="display:none;margin-left:auto;background:#a78bfa;color:#080f1a;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:monospace"></span>
        </a>
      </div>

      <!-- SA + Principal: System -->
      <div class="nav-section nav-sa-only">
        <div class="nav-section-label">
          <i class="fas fa-tools"></i>
          System
        </div>
        <a href="#" class="nav-link" data-view="activity">
          <i class="fas fa-history"></i>
          <span>Activity Log</span>
        </a>
        <a href="#" class="nav-link" data-view="dashboard">
          <i class="fas fa-sliders-h"></i>
          <span>Settings</span>
        </a>
      </div>

    </nav>

    <div class="sidebar-footer">
      <button class="btn-logout-side" onclick="document.getElementById('logoutModal').classList.add('open')">
        <i class="fas fa-right-from-bracket"></i>
        Sign Out
      </button>
    </div>
  </aside>

  <!-- ── Main Content ─────────────────────────────────────────────────────── -->
  <main>
    <header class="header">
      <div class="header-left">
        <h1>Super Admin Dashboard</h1>
        <div class="breadcrumb">
          <i class="fas fa-home"></i>
          <span>Overview</span>
          <i class="fas fa-chevron-right"></i>
          <span>Dashboard</span>
        </div>
      </div>
      <div class="header-right">
        <div class="clock-display" id="liveClock">00:00:00</div>
      </div>
    </header>

    <div class="content">
      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: dashboard (default)                             -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="dashboard">

      <!-- ── Stats Cards ────────────────────────────────────────────────── -->
      <div class="dash-grid-4">
        <?php if ($saCollegeId): ?>
        <!-- Scoped stats for Principal/HOD -->
        <div class="stat-card teal">
          <div class="stat-header">
            <div class="stat-label">Departments</div>
            <div class="stat-icon teal"><i class="fas fa-sitemap"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_departments'] ?>">0</div>
          <div class="stat-change up"><span><?= htmlspecialchars($saCollegeName) ?></span></div>
        </div>
        <div class="stat-card amber">
          <div class="stat-header">
            <div class="stat-label">Total Users</div>
            <div class="stat-icon amber"><i class="fas fa-users"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_users'] ?>">0</div>
          <div class="stat-change"><span><?= $stats['pending_approvals'] ?> pending</span></div>
        </div>
        <div class="stat-card green">
          <div class="stat-header">
            <div class="stat-label">Students</div>
            <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_students'] ?>">0</div>
          <div class="stat-change up"><i class="fas fa-check-circle"></i><span>Enrolled</span></div>
        </div>
        <div class="stat-card red">
          <div class="stat-header">
            <div class="stat-label">Faculty</div>
            <div class="stat-icon red"><i class="fas fa-chalkboard-teacher"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_faculty'] ?>">0</div>
          <div class="stat-change up"><span>Active members</span></div>
        </div>
        <?php else: ?>
        <!-- Global stats for Super Admin -->
        <div class="stat-card teal">
          <div class="stat-header">
            <div class="stat-label">Total Colleges</div>
            <div class="stat-icon teal"><i class="fas fa-building"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_colleges'] ?>">0</div>
          <div class="stat-change up">
            <i class="fas fa-arrow-up"></i>
            <span><?= $stats['active_colleges'] ?> active</span>
          </div>
        </div>
        <div class="stat-card amber">
          <div class="stat-header">
            <div class="stat-label">Total Users</div>
            <div class="stat-icon amber"><i class="fas fa-users"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_users'] ?>">0</div>
          <div class="stat-change">
            <span><?= $stats['pending_approvals'] ?> pending approval</span>
          </div>
        </div>
        <div class="stat-card green">
          <div class="stat-header">
            <div class="stat-label">Students</div>
            <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_students'] ?>">0</div>
          <div class="stat-change up">
            <i class="fas fa-check-circle"></i>
            <span>All enrolled</span>
          </div>
        </div>
        <div class="stat-card red">
          <div class="stat-header">
            <div class="stat-label">Faculty</div>
            <div class="stat-icon red"><i class="fas fa-chalkboard-teacher"></i></div>
          </div>
          <div class="stat-value" data-target="<?= $stats['total_faculty'] ?>">0</div>
          <div class="stat-change up">
            <i class="fas fa-arrow-up"></i>
            <span>Active members</span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Summary Row ────────────────────────────────────────────────── -->
      <div class="dash-grid-1">
        <?php if ($saCollegeId): ?>
        <!-- Principal/HOD: show selected college info card instead of all-colleges table -->
        <?php $myCollege = $recent_colleges[0] ?? null; ?>
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-building"></i> Managing College</div>
          </div>
          <div class="panel-body" style="padding:20px 24px">
            <?php if ($myCollege): ?>
            <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
              <div style="flex:1;min-width:200px">
                <div style="font-size:0.7rem;color:var(--teal);font-family:monospace;font-weight:700;letter-spacing:.08em;margin-bottom:4px"><?= htmlspecialchars($myCollege['code']) ?></div>
                <div style="font-size:1.2rem;font-weight:700;color:var(--text);margin-bottom:6px"><?= htmlspecialchars($myCollege['name']) ?></div>
                <div style="font-size:0.82rem;color:var(--muted)"><?= htmlspecialchars($myCollege['email'] ?? '') ?></div>
                <div style="font-size:0.82rem;color:var(--muted)"><?= htmlspecialchars($myCollege['phone'] ?? '') ?></div>
              </div>
              <div style="display:flex;gap:20px;flex-wrap:wrap">
                <div style="text-align:center;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.2);border-radius:12px;padding:14px 22px">
                  <div style="font-size:1.6rem;font-weight:800;color:var(--teal)"><?= (int)$myCollege['dept_count'] ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Departments</div>
                </div>
                <div style="text-align:center;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.2);border-radius:12px;padding:14px 22px">
                  <div style="font-size:1.6rem;font-weight:800;color:var(--teal)"><?= (int)$myCollege['course_count'] ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Courses</div>
                </div>
                <div style="text-align:center;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.2);border-radius:12px;padding:14px 22px">
                  <div style="font-size:1.6rem;font-weight:800;color:var(--teal)"><?= $stats['total_students'] ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Students</div>
                </div>
                <div style="text-align:center;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.2);border-radius:12px;padding:14px 22px">
                  <div style="font-size:1.6rem;font-weight:800;color:var(--teal)"><?= $stats['total_faculty'] ?></div>
                  <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Faculty</div>
                </div>
              </div>
            </div>
            <?php else: ?>
            <div style="color:var(--muted);text-align:center;padding:20px">College info not available.</div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
        <!-- HOD Mode: show Department Faculty panel -->
        <?php
          $hod_dept_faculty = array_values(array_filter($all_faculty, fn($f) => (int)$f['department_id'] === $saDeptId));
        ?>
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-chalkboard-teacher"></i> My Department Faculty</div>
            <a href="#" class="nav-link-inline" data-view="faculty" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr><th>Name</th><th>Username</th><th>Designation</th><th>Last Login</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (empty($hod_dept_faculty)): ?>
                  <tr><td colspan="5" style="text-align:center;color:var(--muted)">No faculty found in your department</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($hod_dept_faculty, 0, 5) as $f): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($f['full_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars($f['email']) ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($f['username']) ?></td>
                      <td style="font-size:0.82rem">
                        <?php if (!empty($f['designation'])): ?>
                          <span style="color:var(--text);font-weight:500"><?= htmlspecialchars($f['designation']) ?></span>
                        <?php else: ?>
                          <span style="font-size:0.74rem;padding:2px 8px;border-radius:10px;background:rgba(100,116,139,.1);color:var(--muted);font-style:italic">Not set</span>
                        <?php endif; ?>
                      </td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= $f['last_login'] ? date('M j, H:i', strtotime($f['last_login'])) : 'Never' ?></td>
                      <td><span class="badge badge-<?= $f['status'] ?>"><?= strtoupper($f['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- HOD Mode: show Department Students panel -->
        <?php
          $hod_dept_students = array_values(array_filter($all_students, fn($s) => (int)$s['department_id'] === $saDeptId));
        ?>
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-user-graduate"></i> My Department Students</div>
            <div style="display:flex;align-items:center;gap:10px">
              <button onclick="openHodAllotModal()" style="background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);color:#7c3aed;padding:5px 13px;border-radius:8px;font-size:0.75rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:background .15s" onmouseover="this.style.background='rgba(124,58,237,.2)'" onmouseout="this.style.background='rgba(124,58,237,.1)'">
                <i class="fas fa-layer-group"></i> Allot Year &amp; Section
              </button>
              <a href="#" class="nav-link-inline" data-view="students" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
            </div>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table" id="hodStudentsTable">
              <thead>
                <tr><th>#</th><th>Name</th><th>Roll No.</th><th>Username</th><th>Year / Sem / Section</th><th>Last Login</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (empty($hod_dept_students)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted)">No students found in your department</td></tr>
                <?php else: ?>
                  <?php foreach ($hod_dept_students as $idx => $s): ?>
                    <?php
                      $sy   = (int)($s['study_year']        ?? 0);
                      $csem = (int)($s['current_semester']  ?? 0);
                      $sec  = $s['section'] ?? '';
                      $yearL = ['','1st','2nd','3rd','4th','5th','6th'];
                    ?>
                    <tr data-sid="<?= (int)$s['id'] ?>" data-year="<?= $sy ?>" data-sem="<?= $csem ?>" data-sec="<?= htmlspecialchars($sec) ?>">
                      <td style="color:var(--muted);font-size:0.75rem"><?= $idx+1 ?></td>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($s['full_name']) ?></div>
                        <div style="font-size:0.72rem;color:var(--muted)"><?= htmlspecialchars($s['email']) ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($s['roll_number'] ?? '—') ?></td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--muted)"><?= htmlspecialchars($s['username']) ?></td>
                      <td class="hod-yss-cell">
                        <?php if ($sy || $csem || $sec): ?>
                          <div style="display:flex;flex-direction:column;gap:3px">
                            <?php if($sy): ?><span style="display:inline-flex;align-items:center;gap:4px;background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.22);color:#7c3aed;padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-layer-group" style="font-size:0.6rem"></i><?= htmlspecialchars($yearL[$sy]??$sy) ?> Year</span><?php endif; ?>
                            <?php if($csem): ?><span style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.28);color:#d97706;padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-graduation-cap" style="font-size:0.6rem"></i>Sem <?= $csem ?></span><?php endif; ?>
                            <?php if($sec): ?><span style="display:inline-flex;align-items:center;gap:4px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.22);color:var(--teal);padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-users" style="font-size:0.6rem"></i>Sec <?= htmlspecialchars($sec) ?></span><?php endif; ?>
                          </div>
                        <?php else: ?>
                          <span style="color:var(--muted);font-size:0.74rem">Not allotted</span>
                        <?php endif; ?>
                      </td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= $s['last_login'] ? date('M j, H:i', strtotime($s['last_login'])) : 'Never' ?></td>
                      <td><span class="badge badge-<?= $s['status'] ?>"><?= strtoupper($s['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>

        <script>
        /* ── HOD Allot Modal ─────────────────────────────────────────────── */
        const yearLabelsHod = {1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th',6:'6th'};

        function openHodAllotModal() {
          const modal = document.getElementById('hodAllotModal');
          modal.style.display = 'flex';
          document.getElementById('hodAllotMsg').style.display = 'none';
          document.getElementById('hodAllotLoading').style.display = 'block';
          document.getElementById('hodAllotList').style.display = 'none';

          // Fetch students with current allotment info
          postAction({ajax_action:'hod_get_students_allot'}).then(d => {
            document.getElementById('hodAllotLoading').style.display = 'none';
            if (!d.ok || !d.students || !d.students.length) {
              document.getElementById('hodAllotList').style.display = 'block';
              document.getElementById('hodAllotList').innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted)"><i class="fas fa-users-slash"></i> No active students found in your department.</div>';
              return;
            }
            buildHodAllotRows(d.students);
          }).catch(() => {
            document.getElementById('hodAllotLoading').innerHTML = '<span style="color:#f87171">Failed to load students. Try again.</span>';
          });
        }

        function closeHodAllotModal() {
          document.getElementById('hodAllotModal').style.display = 'none';
        }

        function buildHodAllotRows(students) {
          const list = document.getElementById('hodAllotList');
          list.innerHTML = '';
          students.forEach((s, i) => {
            const initials = s.full_name.trim().split(/\s+/).map(w=>w[0]||'').join('').substring(0,2).toUpperCase();
            const colors = ['#00d4bb','#f59e0b','#8b5cf6','#3b82f6','#10b981'];
            const col = colors[s.full_name.charCodeAt(0) % colors.length];
            const yearOpts = [1,2,3,4,5,6].map(v=>`<option value="${v}"${s.study_year==v?' selected':''}>${v}${['st','nd','rd','th','th','th'][v-1]} Year</option>`).join('');
            const semOpts  = [1,2,3,4,5,6,7,8,9,10].map(v=>`<option value="${v}"${s.current_semester==v?' selected':''}>${'Semester '+v}</option>`).join('');
            const secOpts  = ['A','B','C','D','E','F'].map(v=>`<option value="${v}"${s.section===v?' selected':''}>${'Section '+v}</option>`).join('');

            const row = document.createElement('div');
            row.dataset.sid = s.id;
            row.style.cssText = `display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border,rgba(0,0,0,.07));transition:background .12s`;
            row.onmouseenter = ()=>row.style.background='rgba(124,58,237,.03)';
            row.onmouseleave = ()=>row.style.background='';
            row.innerHTML = `
              <div style="width:30px;height:30px;border-radius:50%;background:${col};display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:#fff;flex-shrink:0">${initials}</div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:0.82rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escH(s.full_name)}</div>
                ${s.roll_number ? `<div style="font-size:0.7rem;color:var(--muted);font-family:monospace">${escH(s.roll_number)}</div>` : ''}
              </div>
              <select class="hod-allot-year" style="width:105px;padding:5px 8px;font-size:0.76rem;border:1px solid var(--border,rgba(0,0,0,.15));border-radius:7px;background:var(--surface,#fff);color:var(--text,#1e293b);font-family:inherit">
                <option value="">— Year —</option>${yearOpts}
              </select>
              <select class="hod-allot-sem" style="width:115px;padding:5px 8px;font-size:0.76rem;border:1px solid var(--border,rgba(0,0,0,.15));border-radius:7px;background:var(--surface,#fff);color:var(--text,#1e293b);font-family:inherit">
                <option value="">— Sem —</option>${semOpts}
              </select>
              <select class="hod-allot-sec" style="width:105px;padding:5px 8px;font-size:0.76rem;border:1px solid var(--border,rgba(0,0,0,.15));border-radius:7px;background:var(--surface,#fff);color:var(--text,#1e293b);font-family:inherit">
                <option value="">— Section —</option>${secOpts}
              </select>
              <div class="hod-allot-status" style="width:20px;text-align:center"></div>`;
            list.appendChild(row);
          });
          // Remove border from last row
          if (list.lastChild) list.lastChild.style.borderBottom = 'none';
          list.style.display = 'block';
        }

        async function saveHodAllotment() {
          const btn = document.getElementById('hodAllotSaveBtn');
          const msgEl = document.getElementById('hodAllotMsg');
          const rows  = document.querySelectorAll('#hodAllotList [data-sid]');
          btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
          let saved = 0, skipped = 0, failed = 0;
          for (const row of rows) {
            const sid = row.dataset.sid;
            const yr  = row.querySelector('.hod-allot-year').value;
            const sm  = row.querySelector('.hod-allot-sem').value;
            const sc  = row.querySelector('.hod-allot-sec').value;
            const st  = row.querySelector('.hod-allot-status');
            if (!yr || !sm || !sc) { skipped++; continue; }
            st.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--muted);font-size:0.7rem"></i>';
            try {
              const d = await postAction({ajax_action:'hod_allot_student',student_id:sid,study_year:yr,current_semester:sm,section:sc});
              if (d.ok) {
                saved++;
                st.innerHTML = '<i class="fas fa-circle-check" style="color:#10b981;font-size:0.75rem"></i>';
                // Update main table row if visible
                const tableRow = document.querySelector(`#hodStudentsTable tr[data-sid="${sid}"]`);
                if (tableRow) {
                  tableRow.dataset.year = yr; tableRow.dataset.sem = sm; tableRow.dataset.sec = sc;
                  const cell = tableRow.querySelector('.hod-yss-cell');
                  if (cell) cell.innerHTML = buildYSSBadges(yr, sm, sc);
                }
              } else { failed++; st.innerHTML = '<i class="fas fa-circle-xmark" style="color:#f87171;font-size:0.75rem"></i>'; }
            } catch { failed++; st.innerHTML = '<i class="fas fa-circle-xmark" style="color:#f87171;font-size:0.75rem"></i>'; }
          }
          msgEl.style.cssText = 'display:block;padding:9px 13px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.22);border-radius:8px;font-size:0.78rem;color:#10b981;margin-bottom:10px';
          msgEl.innerHTML = `<i class="fas fa-circle-check"></i> ${saved} allotted${skipped?' · '+skipped+' skipped (incomplete)':''}${failed?' · '+failed+' failed':''}`;
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save All Allotments';
        }

        function buildYSSBadges(yr, sem, sec) {
          const yl = {1:'1st',2:'2nd',3:'3rd',4:'4th',5:'5th',6:'6th'};
          let h = '<div style="display:flex;flex-direction:column;gap:3px">';
          if(yr)  h += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.22);color:#7c3aed;padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-layer-group" style="font-size:0.6rem"></i>${yl[yr]||yr} Year</span>`;
          if(sem) h += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.28);color:#d97706;padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-graduation-cap" style="font-size:0.6rem"></i>Sem ${sem}</span>`;
          if(sec) h += `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.22);color:var(--teal);padding:2px 8px;border-radius:12px;font-size:0.68rem;font-weight:700;width:fit-content"><i class="fas fa-users" style="font-size:0.6rem"></i>Sec ${sec}</span>`;
          return h+'</div>';
        }
        </script>

        <?php else: ?>
        <!-- Principal Mode: Departments with faculty count -->
        <?php
          // Group faculty by department for principal view
          $principalFacultyByDept = [];
          foreach ($all_faculty as $f) {
            $deptId = (int)($f['department_id'] ?? 0);
            if ($deptId) $principalFacultyByDept[$deptId][] = $f;
          }
        ?>

        <!-- Departments Overview Panel -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-sitemap"></i> Departments — <?= htmlspecialchars($saCollegeName) ?></div>
            <a href="#" class="nav-link-inline" data-view="departments" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr><th>Department</th><th>Code</th><th>HOD</th><th>Courses</th><th>Faculty</th><th>Students</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (empty($all_departments)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">No departments found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_departments as $dept): ?>
                    <?php
                      $dId = (int)$dept['id'];
                      $dFacCount = count($principalFacultyByDept[$dId] ?? []);
                      $dStudCount = count(array_filter($all_students, fn($s) => (int)$s['department_id'] === $dId));
                    ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($dept['name']) ?></div>
                        <div style="font-size:0.72rem;color:var(--muted)"><?= htmlspecialchars($dept['college_name'] ?? '') ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($dept['code'] ?? '—') ?></td>
                      <td style="font-size:0.82rem;color:var(--muted)"><?= htmlspecialchars($dept['hod_name'] ?? '—') ?></td>
                      <td style="text-align:center"><span class="badge badge-active"><?= (int)($dept['course_count'] ?? 0) ?></span></td>
                      <td style="text-align:center"><span class="badge badge-active"><?= $dFacCount ?></span></td>
                      <td style="text-align:center"><span class="badge badge-active"><?= $dStudCount ?></span></td>
                      <td><span class="badge badge-<?= $dept['status'] ?? 'active' ?>"><?= strtoupper($dept['status'] ?? 'active') ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- Faculty List with Login Details -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-chalkboard-teacher"></i> Faculty — Login Details</div>
            <a href="#" class="nav-link-inline" data-view="faculty" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table" style="min-width:720px">
              <thead>
                <tr><th>Name</th><th>Username</th><th>Department</th><th>Designation</th><th>Last Login</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (empty($all_faculty)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No faculty found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_faculty as $f): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($f['full_name']) ?></div>
                        <div style="font-size:0.72rem;color:var(--muted)"><?= htmlspecialchars($f['email'] ?? '') ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($f['username'] ?? '—') ?></td>
                      <td style="font-size:0.8rem;color:var(--muted)"><?= htmlspecialchars($f['dept_name'] ?? '—') ?></td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= htmlspecialchars($f['designation'] ?? '—') ?></td>
                      <td style="font-size:0.78rem;color:var(--muted)">
                        <?php if ($f['last_login']): ?>
                          <span><?= date('M j, Y', strtotime($f['last_login'])) ?></span>
                          <div style="font-size:0.68rem;color:var(--muted)"><?= date('H:i', strtotime($f['last_login'])) ?></div>
                        <?php else: ?>
                          <span style="color:#e76f51;font-size:.75rem">Never</span>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge badge-<?= $f['status'] ?>"><?= strtoupper($f['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- Recent Login Activity — all users of this college -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Recent User Logins — <?= htmlspecialchars($saCollegeName) ?></div>
            <a href="#" class="nav-link-inline" data-view="users" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table" style="min-width:720px">
              <thead>
                <tr><th>Name</th><th>Username</th><th>Department</th><th>Role</th><th>Last Login</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php
                  // Sort all users by last_login desc
                  $loginSorted = $recent_users;
                  usort($loginSorted, fn($a,$b) => strcmp($b['last_login']??'',$a['last_login']??''));
                ?>
                <?php if (empty($loginSorted)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No users found</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($loginSorted, 0, 10) as $u): ?>
                    <?php
                      // Find dept name from all_departments
                      $uDept = '—';
                      foreach ($all_departments as $dd) {
                        if ((int)$dd['id'] === (int)($u['department_id']??0)) { $uDept = $dd['name']; break; }
                      }
                    ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['full_name']) ?></div>
                        <div style="font-size:0.72rem;color:var(--muted)"><?= htmlspecialchars($u['email'] ?? '') ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($u['username'] ?? '—') ?></td>
                      <td style="font-size:0.8rem;color:var(--muted)"><?= htmlspecialchars($uDept) ?></td>
                      <td><span class="role-pill role-<?= $u['role'] ?>"><?= str_replace('_', ' ', strtoupper($u['role'])) ?></span></td>
                      <td style="font-size:0.78rem;color:var(--muted)">
                        <?php if ($u['last_login']): ?>
                          <span><?= date('M j, Y', strtotime($u['last_login'])) ?></span>
                          <div style="font-size:0.68rem;color:var(--muted)"><?= date('H:i', strtotime($u['last_login'])) ?></div>
                        <?php else: ?>
                          <span style="color:#e76f51;font-size:.75rem">Never logged in</span>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge badge-<?= $u['status'] ?>"><?= strtoupper($u['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Super Admin: show All Colleges preview + All Users preview -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-building"></i> All Colleges</div>
            <a href="#" class="nav-link-inline" data-view="colleges" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>College Name</th><th>Code</th><th>Contact</th><th>Est.</th><th>Dept</th><th>Courses</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recent_colleges)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted)">No colleges found</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($recent_colleges, 0, 3) as $college): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($college['name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars($college['email'] ?? '') ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($college['code']) ?></td>
                      <td style="font-size:0.8rem"><?= htmlspecialchars($college['phone'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($college['established'] ?? '—') ?></td>
                      <td><span class="badge badge-active"><?= (int)$college['dept_count'] ?></span></td>
                      <td><span class="badge badge-active"><?= (int)$college['course_count'] ?></span></td>
                      <td><span class="badge badge-<?= $college['status'] ?>"><?= strtoupper($college['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-users"></i> All Users</div>
            <a href="#" class="nav-link-inline" data-view="users" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All →</a>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Name</th><th>Username</th><th>College</th><th>Role</th><th>Last Login</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recent_users)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted)">No users found</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($recent_users, 0, 3) as $u): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['full_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($u['username']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($u['college_name'] ?? '—') ?></td>
                      <td><span class="role-pill role-<?= $u['role'] ?>"><?= str_replace('_', ' ', strtoupper($u['role'])) ?></span></td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= $u['last_login'] ? date('M j, H:i', strtotime($u['last_login'])) : 'Never' ?></td>
                      <td><span class="badge badge-<?= $u['status'] ?>"><?= strtoupper($u['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      </div><!-- /view:dashboard -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: analytics                                       -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="analytics" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-chart-bar"></i> Analytics &amp; Reports</h2>
          <span id="anlCollegeBadge" style="font-size:.72rem;background:rgba(20,184,166,.12);color:var(--teal);border:1px solid rgba(20,184,166,.25);padding:4px 12px;border-radius:20px;font-weight:600"></span>
          <button onclick="loadAnalytics()" style="margin-left:auto;background:none;border:1px solid var(--border2);color:var(--muted);padding:7px 14px;border-radius:8px;cursor:pointer;font-size:.78rem;font-family:inherit;display:inline-flex;align-items:center;gap:6px;transition:.18s" onmouseover="this.style.borderColor='var(--teal)';this.style.color='var(--teal)'" onmouseout="this.style.borderColor='var(--border2)';this.style.color='var(--muted)'">
            <i class="fas fa-rotate-right"></i> Refresh
          </button>
        </div>

        <!-- Loading -->
        <div id="anlLoading" style="padding:60px 24px;text-align:center;color:var(--muted)">
          <i class="fas fa-spinner fa-spin" style="font-size:1.6rem;margin-bottom:12px;display:block;color:var(--teal)"></i>
          <div style="font-size:.85rem">Loading analytics…</div>
        </div>

        <!-- No college -->
        <div id="anlNoCollege" style="display:none;padding:60px 24px;text-align:center;color:var(--muted)">
          <i class="fas fa-building-columns" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:14px"></i>
          <div style="font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px">No College Selected</div>
          <div style="font-size:.83rem">Select a college from the sidebar to view analytics.</div>
        </div>

        <!-- Content -->
        <div id="anlContent" style="display:none">

          <!-- ── ROW 1: 8 KPI cards ── -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px" id="anlKpiRow"></div>

          <!-- ── ROW 2: dept students + dept faculty ── -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-users" style="color:#38bdf8"></i> Students per Department</div></div>
              <div style="padding:16px 20px"><div id="anlDeptStudentsChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-chalkboard-teacher" style="color:#a78bfa"></i> Faculty per Department</div></div>
              <div style="padding:16px 20px"><div id="anlDeptFacultyChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>
          </div>

          <!-- ── ROW 3: attendance ring + att trend + exam status ── -->
          <div style="display:grid;grid-template-columns:1fr 1.6fr 1fr;gap:16px;margin-bottom:16px">

            <!-- Attendance ring -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-clipboard-check" style="color:#f59e0b"></i> Attendance · 30 Days</div></div>
              <div style="padding:16px 20px;display:flex;flex-direction:column;align-items:center;gap:12px">
                <div style="position:relative;width:106px;height:106px">
                  <svg viewBox="0 0 36 36" style="width:106px;height:106px;transform:rotate(-90deg)">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--border2)" stroke-width="3"/>
                    <circle id="anlAttRing" cx="18" cy="18" r="15.9" fill="none" stroke="#34d399" stroke-width="3"
                      stroke-dasharray="0 100" stroke-linecap="round" style="transition:stroke-dasharray .9s ease"/>
                  </svg>
                  <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <span id="anlAttPct" style="font-size:1.25rem;font-weight:800;color:var(--text)">—</span>
                    <span style="font-size:.58rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em">Present</span>
                  </div>
                </div>
                <div id="anlAttStats" style="width:100%;display:flex;flex-direction:column;gap:6px;font-size:.77rem"></div>
              </div>
            </div>

            <!-- Attendance monthly trend -->
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-chart-line" style="color:#34d399"></i> Attendance Trend</div>
                <span style="font-size:.68rem;color:var(--muted)">Last 6 months</span>
              </div>
              <div style="padding:16px 20px"><div id="anlAttTrendChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>

            <!-- Exam status breakdown -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-file-pen" style="color:#f472b6"></i> Exam Status</div></div>
              <div style="padding:16px 20px"><div id="anlExamStatusChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>

          </div>

          <!-- ── ROW 4: courses by semester + marks/results + fee + leave ── -->
          <div style="display:grid;grid-template-columns:1fr 1.4fr 1fr 1fr;gap:16px;margin-bottom:16px">

            <!-- Courses by semester -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-book-open" style="color:#34d399"></i> Courses / Semester</div></div>
              <div style="padding:16px 20px"><div id="anlSemCoursesChart" style="display:flex;flex-direction:column;gap:8px"></div></div>
            </div>

            <!-- Average marks per course -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-graduation-cap" style="color:#38bdf8"></i> Avg Marks per Course</div></div>
              <div style="padding:16px 20px"><div id="anlCourseMarksChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>

            <!-- Fee collection donut -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-indian-rupee-sign" style="color:#34d399"></i> Fee Collection</div></div>
              <div style="padding:16px 20px;display:flex;flex-direction:column;align-items:center;gap:10px">
                <div style="position:relative;width:90px;height:90px">
                  <svg viewBox="0 0 36 36" style="width:90px;height:90px;transform:rotate(-90deg)">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--border2)" stroke-width="3.5"/>
                    <circle id="anlFeeRing" cx="18" cy="18" r="15.9" fill="none" stroke="#34d399" stroke-width="3.5"
                      stroke-dasharray="0 100" stroke-linecap="round" style="transition:stroke-dasharray .9s ease"/>
                  </svg>
                  <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <span id="anlFeePct" style="font-size:1rem;font-weight:800;color:var(--text)">—</span>
                    <span style="font-size:.55rem;color:var(--muted);font-weight:700;letter-spacing:.04em">Collected</span>
                  </div>
                </div>
                <div id="anlFeeStats" style="width:100%;font-size:.76rem;display:flex;flex-direction:column;gap:6px"></div>
              </div>
            </div>

            <!-- Leave applications -->
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-calendar-xmark" style="color:#f59e0b"></i> Leave Applications</div></div>
              <div style="padding:16px 20px"><div id="anlLeaveChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>

          </div>

          <!-- ── ROW 5: applications by status + student registrations ── -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:4px">
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-inbox" style="color:#a78bfa"></i> Applications by Status</div></div>
              <div style="padding:16px 20px"><div id="anlAppStatusChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-user-plus" style="color:#f472b6"></i> Student Registrations</div>
                <span style="font-size:.68rem;color:var(--muted)">Last 6 months</span>
              </div>
              <div style="padding:16px 20px"><div id="anlRegTrendChart" style="display:flex;flex-direction:column;gap:9px"></div></div>
            </div>
          </div>

        </div><!-- /anlContent -->
      </div><!-- /view:analytics -->


      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: colleges                                        -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="colleges" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-building"></i> All Colleges</h2>
          <span class="view-count"><?= count($recent_colleges) ?> total</span>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalCollege')"><i class="fas fa-plus"></i> Add College</button>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>College Name</th><th>Code</th><th>Contact</th><th>Est.</th><th>Dept</th><th>Courses</th><th>Status</th>
                </tr>
              </thead>
              <tbody id="college-tbody">
                <?php if (empty($recent_colleges)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted)">No colleges found</td></tr>
                <?php else: ?>
                  <?php foreach ($recent_colleges as $college): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($college['name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars($college['email'] ?? '') ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($college['code']) ?></td>
                      <td style="font-size:0.8rem"><?= htmlspecialchars($college['phone'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($college['established'] ?? '—') ?></td>
                      <td><span class="badge badge-active"><?= (int)$college['dept_count'] ?></span></td>
                      <td><span class="badge badge-active"><?= (int)$college['course_count'] ?></span></td>
                      <td>
                        <button class="status-toggle <?= $college['status'] ?>"
                          data-id="<?= $college['id'] ?>"
                          data-type="college"
                          data-status="<?= $college['status'] ?>"
                          onclick="toggleStatus(this,'college')">
                          <?= strtoupper($college['status']) ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:colleges -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: users                                           -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="users" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-users"></i> <?= $saCollegeId ? htmlspecialchars($saCollegeName).' — ' : '' ?>All Users</h2>
          <span class="view-count"><?= count($recent_users) ?> total</span>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalUser')"><i class="fas fa-plus"></i> Add User</button>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Name</th><th>Username</th><th>College</th><th>Role</th><th>Last Login</th><th>Status</th>
                </tr>
              </thead>
              <tbody id="user-tbody">
                <?php if (empty($recent_users)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted)">No users found</td></tr>
                <?php else: ?>
                  <?php foreach ($recent_users as $u): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['full_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars($u['email']) ?></div>
                      </td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($u['username']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($u['college_name'] ?? '—') ?></td>
                      <td><span class="role-pill role-<?= $u['role'] ?>"><?= str_replace('_', ' ', strtoupper($u['role'])) ?></span></td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= $u['last_login'] ? date('M j, H:i', strtotime($u['last_login'])) : 'Never' ?></td>
                      <td>
                        <button class="status-toggle <?= $u['status'] ?>"
                          data-id="<?= $u['id'] ?>"
                          data-type="user"
                          data-status="<?= $u['status'] ?>"
                          onclick="toggleStatus(this,'user')">
                          <?= strtoupper($u['status']) ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:users -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: departments                                     -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="departments" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-sitemap"></i> <?= $saCollegeId ? htmlspecialchars($saCollegeName).' — ' : '' ?>Departments</h2>
          <span class="view-count"><?= count($all_departments) ?> total</span>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalDept')"><i class="fas fa-plus"></i> Add Department</button>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Department Name</th><th>Code</th><th>College</th><th>Head of Department</th><th>Active Courses</th><th>Status</th>
                </tr>
              </thead>
              <tbody id="dept-tbody">
                <?php if (empty($all_departments)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted)">No departments found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_departments as $dept): ?>
                    <tr>
                      <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($dept['name']) ?></td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($dept['code']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($dept['college_name'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($dept['hod_name'] ?? '—') ?></td>
                      <td><span class="badge badge-active"><?= (int)$dept['course_count'] ?></span></td>
                      <td>
                        <button class="status-toggle <?= $dept['status'] ?>"
                          data-id="<?= $dept['id'] ?>"
                          data-type="dept"
                          data-status="<?= $dept['status'] ?>"
                          onclick="toggleStatus(this,'dept')">
                          <?= strtoupper($dept['status']) ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:departments -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: courses                                         -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="courses" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-book"></i> <?= $saCollegeId ? htmlspecialchars($saCollegeName).' — ' : '' ?>Courses</h2>
          <span class="view-count"><?= count($all_courses) ?> total</span>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalCourse')"><i class="fas fa-plus"></i> Add Course</button>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Course Name</th><th>Code</th><th>College</th><th>Department</th><th>Credits</th><th>Semester</th><th>Status</th>
                </tr>
              </thead>
              <tbody id="course-tbody">
                <?php if (empty($all_courses)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted)">No courses found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_courses as $course): ?>
                    <tr>
                      <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($course['name']) ?></td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($course['code']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($course['college_name'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($course['dept_name'] ?? '—') ?></td>
                      <td style="text-align:center"><span class="badge badge-active"><?= number_format($course['credits'], 0) ?></span></td>
                      <td style="text-align:center;font-size:0.82rem"><?= $course['semester'] ? 'Sem ' . $course['semester'] : '—' ?></td>
                      <td>
                        <button class="status-toggle <?= $course['status'] ?>"
                          data-id="<?= $course['id'] ?>"
                          data-type="course"
                          data-status="<?= $course['status'] ?>"
                          onclick="toggleStatus(this,'course')">
                          <?= strtoupper($course['status']) ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:courses -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: students                                        -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="students" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-user-graduate"></i> <?= $saCollegeId ? htmlspecialchars($saCollegeName).' — ' : '' ?>Students</h2>
          <span class="view-count"><?= count($all_students) ?> total</span>
          <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
          <button onclick="openHodAllotModal()" style="margin-left:auto;background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);color:#7c3aed;padding:6px 14px;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:background .15s" onmouseover="this.style.background='rgba(124,58,237,.2)'" onmouseout="this.style.background='rgba(124,58,237,.1)'">
            <i class="fas fa-layer-group"></i> Allot Year &amp; Section
          </button>
          <button class="btn-add" style="margin-left:10px" onclick="openModal('modalStudent')"><i class="fas fa-plus"></i> Add Student</button>
          <?php else: ?>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalStudent')"><i class="fas fa-plus"></i> Add Student</button>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Student Name</th><th>Username</th><th>Email</th><th>College</th><th>Department</th><th>Last Login</th><th>Status</th>
                </tr>
              </thead>
              <tbody id="student-tbody">
                <?php if (empty($all_students)): ?>
                  <tr><td colspan="7" style="text-align:center;color:var(--muted)">No students found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_students as $s): ?>
                    <tr>
                      <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($s['full_name']) ?></td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($s['username']) ?></td>
                      <td style="font-size:0.8rem"><?= htmlspecialchars($s['email']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($s['college_name'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($s['dept_name'] ?? '—') ?></td>
                      <td style="font-size:0.78rem;color:var(--muted)"><?= $s['last_login'] ? date('M j, H:i', strtotime($s['last_login'])) : 'Never' ?></td>
                      <td>
                        <button class="status-toggle <?= $s['status'] ?>"
                          data-id="<?= $s['id'] ?>"
                          data-type="user"
                          data-status="<?= $s['status'] ?>"
                          onclick="toggleStatus(this,'user')">
                          <?= strtoupper($s['status']) ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:students -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: faculty                                         -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="faculty" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-chalkboard-teacher"></i> <?= $saCollegeId ? htmlspecialchars($saCollegeName).' — ' : '' ?>Faculty</h2>
          <span class="view-count"><?= count($all_faculty) ?> total</span>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalFaculty')"><i class="fas fa-plus"></i> Add Faculty</button>
        </div>
        <div class="panel">
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <thead>
                <tr>
                  <th>Faculty Name</th><th>Username</th><th>Email</th><th>College</th><th>Department</th><th>Phone</th><th>Status</th><th style="text-align:center">Assign</th>
                </tr>
              </thead>
              <tbody id="faculty-tbody">
                <?php if (empty($all_faculty)): ?>
                  <tr><td colspan="8" style="text-align:center;color:var(--muted)">No faculty found</td></tr>
                <?php else: ?>
                  <?php foreach ($all_faculty as $f): ?>
                    <tr>
                      <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($f['full_name']) ?></td>
                      <td style="font-family:monospace;font-size:0.8rem;color:var(--teal)"><?= htmlspecialchars($f['username']) ?></td>
                      <td style="font-size:0.8rem"><?= htmlspecialchars($f['email']) ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($f['college_name'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($f['dept_name'] ?? '—') ?></td>
                      <td style="font-size:0.82rem"><?= htmlspecialchars($f['phone'] ?? '—') ?></td>
                      <td>
                        <button class="status-toggle <?= $f['status'] ?>"
                          data-id="<?= $f['id'] ?>"
                          data-type="user"
                          data-status="<?= $f['status'] ?>"
                          onclick="toggleStatus(this,'user')">
                          <?= strtoupper($f['status']) ?>
                        </button>
                      </td>
                      <td style="text-align:center">
                        <button
                          class="btn"
                          style="background:var(--teal);padding:5px 14px;font-size:0.78rem;border-radius:6px;cursor:pointer"
                          title="Assign Department &amp; Course to this faculty"
                          onclick="quickAssignFaculty(<?= $f['id'] ?>,'<?= addslashes(htmlspecialchars($f['full_name'])) ?>',<?= (int)($f['college_id'] ?? 0) ?>,<?= (int)($f['department_id'] ?? 0) ?>)">
                          <i class="fas fa-user-check"></i> Assign
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table></div>
          </div>
        </div>
      </div><!-- /view:faculty -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: faculty-assignments                             -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="faculty-assignments" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-user-check"></i> Faculty Assignments</h2>
          <span class="view-count" id="assignments-count">0 assignments</span>
          <?php if ($saCollegeRole === 'hod'): ?>
          <button class="btn-add" style="margin-left:auto;margin-right:8px;background:#6366f1" onclick="openModal('modalCreateCourseHOD')">
            <i class="fas fa-book-open"></i> Create Course
          </button>
          <button class="btn-add" onclick="openModal('modalFacultyAssignment')">
            <i class="fas fa-plus"></i> Assign Faculty
          </button>
          <?php else: ?>
          <button class="btn-add" style="margin-left:auto" onclick="openModal('modalFacultyAssignment')">
            <i class="fas fa-plus"></i> Assign Faculty
          </button>
          <?php endif; ?>
        </div>

        <!-- ── Summary cards — only shown in Super Admin (full) mode ── -->
        <?php if (!$saCollegeId): ?>
        <div id="fa-college-summary" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-bottom:20px">
          <?php foreach ($all_colleges as $col): ?>
          <div class="panel" style="margin:0">
            <div class="panel-body" style="padding:16px 20px">
              <div style="font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px"><?= htmlspecialchars($col['code']) ?></div>
              <div style="font-weight:700;font-size:1rem;color:var(--text);margin-bottom:10px"><?= htmlspecialchars($col['name']) ?></div>
              <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php
                  $cFaculty = array_filter($all_faculty, fn($f) => (int)$f['college_id'] === (int)$col['id']);
                ?>
                <span style="font-size:0.8rem;background:rgba(20,184,166,.12);color:var(--teal);padding:3px 10px;border-radius:20px">
                  <i class="fas fa-users"></i> <?= count($cFaculty) ?> Faculty
                </span>
                <button
                  class="btn"
                  style="font-size:0.75rem;padding:3px 10px;background:var(--teal);border-radius:20px"
                  onclick="document.getElementById('filter-college').value='<?= $col['id'] ?>';loadFacultyAssignments()">
                  View Assignments
                </button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="panel" style="margin-bottom:20px">
          <div class="panel-body" style="padding:16px 24px">
            <div style="display:grid;grid-template-columns:<?= $saCollegeId ? 'repeat(3,1fr)' : 'repeat(4,1fr)' ?>;gap:16px;align-items:end">
              <?php if (!$saCollegeId): ?>
              <div>
                <label style="display:block;font-size:0.75rem;color:var(--muted);margin-bottom:6px">Filter by College</label>
                <select id="filter-college" class="input-field" onchange="loadFacultyAssignments()">
                  <option value="">All Colleges</option>
                  <?php foreach ($all_colleges as $col): ?>
                    <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php else: ?>
              <!-- Hidden locked college filter in scoped mode -->
              <input type="hidden" id="filter-college" value="<?= $saCollegeId ?>">
              <?php endif; ?>
              <div>
                <label style="display:block;font-size:0.75rem;color:var(--muted);margin-bottom:6px">Filter by Department</label>
                <select id="filter-department" class="input-field" onchange="loadFacultyAssignments()">
                  <option value="">All Departments</option>
                  <?php foreach ($all_departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?> <?= !$saCollegeId ? '('.$dept['college_name'].')' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label style="display:block;font-size:0.75rem;color:var(--muted);margin-bottom:6px">Filter by Faculty</label>
                <select id="filter-faculty" class="input-field" onchange="loadFacultyAssignments()">
                  <option value="">All Faculty</option>
                  <?php foreach ($all_faculty as $fac): ?>
                    <option value="<?= $fac['id'] ?>"><?= htmlspecialchars($fac['full_name']) ?><?= !$saCollegeId ? ' — '.htmlspecialchars($fac['college_name'] ?? '') : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <button class="btn" style="background:var(--red);width:100%" onclick="clearAssignmentFilters()">
                  <i class="fas fa-times"></i> Clear Filters
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- All-faculty quick-assign table (shows faculty WITHOUT assignments too) -->
        <div class="panel" style="margin-bottom:20px">
          <div class="panel-body" style="padding:14px 20px 8px">
            <div style="font-size:0.78rem;color:var(--muted);margin-bottom:12px">
              <i class="fas fa-info-circle"></i>
              <?php if ($saCollegeId): ?>
              The table below shows <strong style="color:var(--text)">every faculty member</strong> for <strong style="color:var(--teal)"><?= htmlspecialchars($saCollegeName) ?></strong>.
              <?php else: ?>
              The table below shows <strong style="color:var(--text)">every faculty member</strong> across all colleges.
              <?php endif; ?>
              Faculty with no assignments are highlighted — use the <em>Assign</em> button to add Department + Course.
            </div>
          </div>
          <div class="panel-body panel-body-table" style="padding-top:0">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Faculty Name</th>
                    <?php if (!$saCollegeId): ?><th>College</th><?php endif; ?>
                    <th>Department</th>
                    <th>Designation</th>
                    <th style="text-align:center">Assigned Courses</th>
                    <th style="text-align:center">Quick Assign</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($all_faculty)): ?>
                    <tr><td colspan="7" style="text-align:center;color:var(--muted)">No faculty found</td></tr>
                  <?php else: ?>
                    <?php foreach ($all_faculty as $idx => $f): ?>
                      <tr id="faculty-row-<?= $f['id'] ?>">
                        <td style="color:var(--muted);font-size:0.8rem"><?= $idx+1 ?></td>
                        <td style="font-weight:600;color:var(--text)">
                          <?= htmlspecialchars($f['full_name']) ?>
                          <div style="font-size:0.75rem;color:var(--muted);font-family:monospace"><?= htmlspecialchars($f['email']) ?></div>
                        </td>
                        <?php if (!$saCollegeId): ?>
                        <td style="font-size:0.82rem"><?= htmlspecialchars($f['college_name'] ?? '—') ?></td>
                        <?php endif; ?>
                        <td style="font-size:0.82rem"><?= htmlspecialchars($f['dept_name'] ?? '—') ?></td>
                        <td style="font-size:0.82rem;color:var(--muted)"><?= htmlspecialchars($f['designation'] ?? '—') ?></td>
                        <td style="text-align:center">
                          <span id="fa-badge-<?= $f['id'] ?>" style="font-size:0.78rem;padding:2px 10px;border-radius:20px;background:rgba(255,90,90,.15);color:#ff5a5a">
                            Loading...
                          </span>
                        </td>
                        <td style="text-align:center">
                          <button
                            class="btn"
                            style="background:var(--teal);padding:5px 14px;font-size:0.78rem;border-radius:6px"
                            onclick="quickAssignFaculty(<?= $f['id'] ?>,'<?= addslashes(htmlspecialchars($f['full_name'])) ?>',<?= (int)($f['college_id'] ?? 0) ?>,<?= (int)($f['department_id'] ?? 0) ?>)">
                            <i class="fas fa-plus"></i> Assign
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Existing Assignments Table -->
        <div class="panel">
          <div class="panel-body" style="padding:14px 20px 0">
            <h3 style="font-size:0.9rem;color:var(--muted);margin:0 0 14px"><i class="fas fa-list"></i> Existing Assignments</h3>
          </div>
          <div class="panel-body panel-body-table" style="padding-top:0">
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Faculty Name</th>
                    <?php if (!$saCollegeId): ?><th>College</th><?php endif; ?>
                    <th>Department</th>
                    <th>Course / Subject</th>
                    <th>Sem</th>
                    <th>Year</th>
                    <th>Period</th>
                    <th>Primary</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="assignments-tbody">
                  <tr>
                    <td colspan="<?= $saCollegeId ? 9 : 10 ?>" style="text-align:center;color:var(--muted);padding:40px">
                      <i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px"></i><br>
                      Loading assignments...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div><!-- /view:faculty-assignments -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: academic-details  (HOD only)                   -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="academic-details" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-layer-group" style="color:#6366f1"></i> Academic Details</h2>
          <span class="view-count" id="acad-count">—</span>
        </div>

        <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
        <!-- Department Identity Card -->
        <div class="panel" style="margin-bottom:20px;border-left:4px solid #6366f1">
          <div class="panel-body" style="padding:18px 24px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
            <div style="width:52px;height:52px;border-radius:14px;background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="fas fa-building-columns" style="font-size:1.4rem;color:#6366f1"></i>
            </div>
            <div style="flex:1;min-width:160px">
              <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:3px">Managing Department</div>
              <div style="font-size:1.15rem;font-weight:700;color:var(--text)"><?= htmlspecialchars($saDeptName) ?></div>
              <div style="font-size:0.78rem;color:var(--muted)"><?= htmlspecialchars($saCollegeName) ?></div>
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
              <div style="text-align:center;min-width:60px">
                <div style="font-size:1.4rem;font-weight:700;color:#6366f1" id="acad-stat-courses">—</div>
                <div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Courses</div>
              </div>
              <div style="text-align:center;min-width:60px">
                <div style="font-size:1.4rem;font-weight:700;color:var(--teal)" id="acad-stat-sems">—</div>
                <div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Semesters</div>
              </div>
              <div style="text-align:center;min-width:60px">
                <div style="font-size:1.4rem;font-weight:700;color:var(--amber,#f59e0b)" id="acad-stat-faculty">—</div>
                <div style="font-size:0.7rem;color:var(--muted);text-transform:uppercase">Assigned</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filter bar -->
        <div class="panel" style="margin-bottom:20px">
          <div class="panel-body" style="padding:14px 24px">
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
              <div style="flex:0 0 220px">
                <label style="font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px">Academic Year</label>
                <select id="acad-filter-year" class="input-field" onchange="loadAcademicDetails()" style="padding:7px 12px">
                  <option value="">All Years</option>
                </select>
              </div>
              <div style="flex:0 0 200px">
                <label style="font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px">Semester</label>
                <select id="acad-filter-sem" class="input-field" onchange="filterAcademicDetails()" style="padding:7px 12px">
                  <option value="">All Semesters</option>
                </select>
              </div>
              <div style="margin-top:18px">
                <button class="btn" style="background:var(--teal);padding:7px 18px;font-size:0.8rem" onclick="loadAcademicDetails()">
                  <i class="fas fa-rotate-right"></i> Refresh
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Semester-wise groups container -->
        <div id="acad-groups-container">
          <div style="text-align:center;padding:60px;color:var(--muted)">
            <i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px;display:block"></i>
            Loading academic details...
          </div>
        </div>

        <?php else: ?>
        <div class="panel">
          <div class="panel-body" style="padding:40px;text-align:center;color:var(--muted)">
            <i class="fas fa-lock" style="font-size:2rem;margin-bottom:12px;display:block"></i>
            Academic Details is only available in HOD mode.
          </div>
        </div>
        <?php endif; ?>
      </div><!-- /view:academic-details -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="hod-access-codes" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-key" style="color:#D97706"></i> HOD Access Codes</h2>
          <p style="font-size:.82rem;color:var(--muted);margin-top:4px">
            Generate or reset one-time access codes for each department's Head of Department.
            The HOD enters this code when logging in — it can only be used once.
          </p>
        </div>
        <div class="panel" style="width:100%">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-sitemap" style="color:#D97706"></i> Department HOD Codes</div>
            <button onclick="hodCodesRefresh()" style="background:none;border:1px solid rgba(20,184,166,.3);border-radius:6px;color:var(--teal);padding:5px 12px;font-size:.75rem;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
              <i class="fas fa-refresh"></i> Refresh
            </button>
          </div>
          <div class="panel-body" id="hodCodesPanelBody">
            <div style="text-align:center;color:var(--muted);padding:32px"><i class="fas fa-spinner fa-spin"></i> Loading departments…</div>
          </div>
        </div>
      </div><!-- /view:hod-access-codes -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: staff-noticeboard                              -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="staff-noticeboard" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-clipboard-list"></i>
            <?php if($saCollegeRole==='hod'): ?>
              <?= htmlspecialchars($saDeptName) ?> — Staff Noticeboard
            <?php elseif($saCollegeRole==='principal'): ?>
              <?= htmlspecialchars($saCollegeName) ?> — Staff Noticeboard
            <?php else: ?>
              Staff Noticeboard
            <?php endif; ?>
          </h2>
          <span class="view-count" id="sn-count">0 notices</span>
          <?php if($saCollegeId || true): // visible when scoped or super admin ?>
          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <select id="sn-filter-type" style="background:rgba(15,118,110,.04);border:1px solid rgba(15,118,110,.1);border-radius:8px;color:var(--text);font-family:inherit;font-size:.8rem;padding:6px 11px;outline:none">
              <option value="all">All Types</option>
              <option value="general">General</option><option value="urgent">Urgent</option>
              <option value="event">Event</option><option value="circular">Circular</option>
              <option value="holiday">Holiday</option><option value="academic">Academic</option>
              <option value="administrative">Administrative</option>
            </select>
            <select id="sn-filter-prio" style="background:rgba(15,118,110,.04);border:1px solid rgba(15,118,110,.1);border-radius:8px;color:var(--text);font-family:inherit;font-size:.8rem;padding:6px 11px;outline:none">
              <option value="all">All Priorities</option>
              <option value="critical">Critical</option><option value="high">High</option>
              <option value="normal">Normal</option><option value="low">Low</option>
            </select>
            <select id="sn-filter-show" style="background:rgba(15,118,110,.04);border:1px solid rgba(15,118,110,.1);border-radius:8px;color:var(--text);font-family:inherit;font-size:.8rem;padding:6px 11px;outline:none">
              <option value="active">Active Only</option>
              <option value="all">Show All</option>
            </select>
            <button class="btn-primary" style="padding:8px 14px;font-size:.8rem" onclick="snLoadNotices()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn-add" onclick="snOpenCreate()"><i class="fas fa-plus"></i>
              <?php if($saCollegeRole==='hod'): ?>New Dept Notice<?php elseif($saCollegeRole==='principal'): ?>New College Notice<?php else: ?>New Notice<?php endif; ?>
            </button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Notices list -->
        <div id="sn-list-wrap">
          <div class="panel"><div class="panel-body" style="text-align:center;color:var(--muted);padding:48px">
            <i class="fas fa-clipboard-list" style="font-size:2rem;opacity:.2;display:block;margin-bottom:12px"></i>
            Click <strong style="color:var(--teal)">Filter</strong> or navigate here to load notices.
          </div></div>
        </div>
      </div><!-- /view:staff-noticeboard -->

      <!-- Staff Noticeboard Create/Edit Modal -->
      <div id="snModal" class="form-modal-overlay">
        <div class="form-modal" style="max-width:660px">
          <div class="form-modal-header">
            <div class="form-modal-title" id="snModalTitle"><i class="fas fa-plus-circle"></i> Create Notice</div>
            <button class="form-modal-close" onclick="snCloseModal()">✕</button>
          </div>
          <div id="snModalAlert" class="form-alert"></div>
          <input type="hidden" id="snNoticeId">
          <input type="hidden" id="snNoticeCollegeId" value="<?= $saCollegeId ?>">
          <div class="form-grid">
            <div class="form-group full">
              <label>Title *</label>
              <input type="text" id="snTitle" class="form-group input" placeholder="Notice title…"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;transition:border-color .2s;width:100%">
            </div>
            <div class="form-group full">
              <label>Content *</label>
              <textarea id="snContent" rows="5" placeholder="Write the notice content…"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;transition:border-color .2s;width:100%;resize:vertical;min-height:110px"></textarea>
            </div>
            <div class="form-group">
              <label>Notice Type</label>
              <select id="snType" style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
                <option value="general">General</option><option value="urgent">Urgent</option>
                <option value="event">Event</option><option value="circular">Circular</option>
                <option value="holiday">Holiday</option><option value="academic">Academic</option>
                <option value="administrative">Administrative</option>
              </select>
            </div>
            <div class="form-group">
              <label>Priority</label>
              <select id="snPriority" style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
                <option value="low">Low</option><option value="normal" selected>Normal</option>
                <option value="high">High</option><option value="critical">Critical</option>
              </select>
            </div>
            <?php if(!$saCollegeId): ?>
            <div class="form-group">
              <label>College</label>
              <select id="snCollegeSel" onchange="snCollegeChanged()"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
                <option value="">— All / Global —</option>
                <?php foreach($all_colleges as $col): ?>
                <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <?php if($saCollegeRole !== 'hod'): ?>
            <div class="form-group">
              <label>Target Audience</label>
              <select id="snAudience" onchange="snToggleTargetFields()"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
                <option value="all">All Staff</option>
                <option value="faculty">Faculty Only</option>
                <option value="department_specific">Specific Department</option>
                <option value="college_specific">Whole College</option>
                <?php if(!$saCollegeId): ?><option value="admin">Administrators Only</option><?php endif; ?>
              </select>
            </div>
            <?php else: ?>
            <div class="form-group">
              <label>Target Audience</label>
              <input type="hidden" id="snAudience" value="department_specific">
              <input type="text" value="My Department Only" disabled
                style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.25);border-radius:var(--radius-sm);color:var(--teal);font-weight:600;font-family:var(--font-body);font-size:.88rem;padding:10px 14px;width:100%;cursor:not-allowed">
            </div>
            <?php endif; ?>
            <div class="form-group" id="snDeptField" style="display:none">
              <label>Department</label>
              <select id="snDept"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
                <option value="">— Select Department —</option>
                <?php foreach($all_departments as $d): ?>
                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Expiry Date <em style="font-weight:400;color:var(--muted)">(optional)</em></label>
              <input type="date" id="snExpiry" min="<?= date('Y-m-d') ?>"
                style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:.88rem;padding:10px 14px;outline:none;width:100%">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn-secondary" onclick="snCloseModal()">Cancel</button>
            <button class="btn-primary" onclick="snSubmitNotice()"><i class="fas fa-save"></i> Save Notice</button>
          </div>
        </div>
      </div>
      <div data-view="activity" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-history"></i> Activity Log</h2>
        </div>

      <!-- ── Activity & Quick Actions ───────────────────────────────────── -->
      <div class="dash-grid-2">
        <!-- Activity Log -->
        <div class="panel" id="activity-section">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-history"></i> Recent Activity</div>
            <a href="#" style="font-size:0.75rem;color:var(--teal);text-decoration:none">View All</a>
          </div>
          <div class="panel-body">
            <div class="activity-list">
              <?php if (empty($activity_log)): ?>
                <div style="text-align:center;color:var(--muted);padding:20px">No activity logs found</div>
              <?php else: ?>
                <?php foreach ($activity_log as $a):
                  $uname = htmlspecialchars($a['username'] ?? 'System');
                  $time = date('H:i, M j', strtotime($a['created_at']));
                ?>
                <div class="activity-item">
                  <div class="activity-icon-wrap">
                    <i class="fas fa-circle-dot"></i>
                  </div>
                  <div class="activity-content">
                    <div class="activity-text">
                      <span class="activity-user"><?= $uname ?></span>
                      — <?= htmlspecialchars($a['action']) ?>
                    </div>
                    <div class="activity-time"><?= $time ?></div>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-bolt"></i> Quick Actions</div>
          </div>
          <div class="panel-body">
            <div class="quick-actions">
              <button class="qa-btn" onclick="switchView('colleges');setTimeout(()=>openModal('modalCollege'),100)">
                <i class="fas fa-plus-circle" style="color:var(--teal)"></i>Add College
              </button>
              <button class="qa-btn" onclick="switchView('users');setTimeout(()=>openModal('modalUser'),100)">
                <i class="fas fa-user-plus" style="color:var(--amber)"></i>Add User
              </button>
              <button class="qa-btn" onclick="switchView('courses');setTimeout(()=>openModal('modalCourse'),100)">
                <i class="fas fa-book-medical" style="color:var(--green)"></i>New Course
              </button>
              <button class="qa-btn" onclick="switchView('departments');setTimeout(()=>openModal('modalDept'),100)">
                <i class="fas fa-sitemap" style="color:var(--muted)"></i>New Department
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Second Row: System Health & Platform Summary ────────────────── -->
      <div class="dash-grid-2">
        <!-- System Health -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-server"></i> System Health</div>
            <span style="font-size:0.75rem;color:var(--green)">
              <i class="fas fa-circle" style="font-size:0.55rem"></i> All systems operational
            </span>
          </div>
          <div class="panel-body">
            <?php
            $health = [
              ['label' => 'Database', 'pct' => $db ? 98 : 0, 'color' => '#00c6ae'],
              ['label' => 'API Response', 'pct' => 98, 'color' => '#2dd4aa'],
              ['label' => 'Storage Used', 'pct' => 34, 'color' => '#f4a261'],
              ['label' => 'Active Sessions', 'pct' => 20, 'color' => '#00c6ae'],
              ['label' => 'Uptime', 'pct' => 99, 'color' => '#2dd4aa'],
            ];
            foreach ($health as $h): ?>
            <div class="health-row">
              <div class="health-label"><?= $h['label'] ?></div>
              <div class="health-bar-wrap">
                <div class="health-bar" style="width:0%;background:<?= $h['color'] ?>" data-width="<?= $h['pct'] ?>%"></div>
              </div>
              <div class="health-val"><?= $h['pct'] ?>%</div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Platform Summary -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-chart-pie"></i> Platform Summary</div>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap"><table class="data-table">
              <tbody>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Total Colleges</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['total_colleges'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Total Departments</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['total_departments'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Active Courses</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['total_courses'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Faculty Members</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['total_faculty'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Students</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['total_students'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">College Admins</td>
                  <td style="text-align:right"><span class="badge badge-active"><?= $stats['college_admins'] ?></span></td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Pending Approvals</td>
                  <td style="text-align:right">
                    <span class="badge <?= $stats['pending_approvals'] > 0 ? 'badge-pending' : 'badge-active' ?>">
                      <?= $stats['pending_approvals'] > 0 ? $stats['pending_approvals'] : 'None' ?>
                    </span>
                  </td>
                </tr>
                <tr>
                  <td style="color:var(--muted);font-size:0.82rem">Admin Account</td>
                  <td style="text-align:right"><span class="role-pill role-super_admin">Super Admin</span></td>
                </tr>
              </tbody>
            </table></div>
          </div>
        </div>
      </div>

    </div><!-- /view:activity -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: attendance                                       -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="attendance" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-clipboard-check"></i> Student Attendance</h2>
          <span class="view-count" id="att-count-badge">All colleges</span>
          <div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <!-- View mode toggle -->
            <div style="display:flex;border:1px solid rgba(20,184,166,.3);border-radius:6px;overflow:hidden;font-size:0.78rem">
              <button id="att-view-student" onclick="setAttView('student')"
                style="padding:6px 14px;background:var(--teal);color:#0a0f1a;border:none;cursor:pointer;font-family:inherit;font-weight:700;transition:all .2s">
                <i class="fas fa-users"></i> Student-wise
              </button>
              <button id="att-view-course" onclick="setAttView('course')"
                style="padding:6px 14px;background:transparent;color:var(--teal);border:none;cursor:pointer;font-family:inherit;font-weight:600;transition:all .2s">
                <i class="fas fa-book"></i> Course-wise
              </button>
            </div>
            <button class="btn-add" onclick="exportAttendanceCSV()">
              <i class="fas fa-download"></i> Export CSV
            </button>
          </div>
        </div>

        <!-- ── Filters ── -->
        <div class="att-filter-bar">
          <div class="fg">
            <label>College</label>
            <?php if ($saCollegeId): ?>
            <input type="text" value="<?= htmlspecialchars($saCollegeName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:8px 12px;border-radius:var(--radius-sm,6px);font-size:.85rem;width:100%">
            <input type="hidden" id="att-college" value="<?= $saCollegeId ?>">
            <?php else: ?>
            <select id="att-college" onchange="attCollegeChange(); attMarkStale()">
              <option value="">All Colleges</option>
              <?php foreach ($all_colleges as $col): ?>
              <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>
          <div class="fg">
            <label>Department</label>
            <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
            <input type="text" value="<?= htmlspecialchars($saDeptName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:8px 12px;border-radius:var(--radius-sm,6px);font-size:.85rem;width:100%">
            <input type="hidden" id="att-dept" value="<?= $saDeptId ?>">
            <?php else: ?>
            <select id="att-dept" onchange="attDeptChange(); attMarkStale()">
              <option value="">All Departments</option>
              <?php foreach ($all_departments as $d): ?>
              <option value="<?= $d['id'] ?>" data-college="<?= $d['college_id'] ?>">
                <?= htmlspecialchars($d['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>
          <div class="fg">
            <label>Course / Subject</label>
            <select id="att-course" onchange="attDeptChange(); attMarkStale()">
              <option value="">All Courses</option>
              <?php foreach ($all_courses as $c): ?>
              <option value="<?= $c['id'] ?>" data-dept="<?= $c['department_id'] ?>" data-college="<?= $c['college_id'] ?>"><?= htmlspecialchars($c['code'].' — '.$c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>From Date</label>
            <input type="date" id="att-from" onchange="attMarkStale()">
          </div>
          <div class="fg">
            <label>To Date</label>
            <input type="date" id="att-to" onchange="attMarkStale()">
          </div>
          <div class="fg">
            <label>Search Student</label>
            <input type="text" id="att-search" placeholder="Name or roll no…" oninput="attMarkStale()">
          </div>
          <div class="fg" style="grid-column: span 1">
            <label>&nbsp;</label>
            <div class="fg-btn">
              <button id="att-load-btn" class="btn-primary" style="flex:1; padding:9px 14px" onclick="loadAttendance()">
                <i class="fas fa-search"></i> Load
              </button>
              <button class="btn-secondary" style="padding:9px 12px" onclick="resetAttFilters()" title="Reset filters">
                <i class="fas fa-rotate-left"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- ── Summary stats ── -->
        <div class="att-stats" id="att-stats" style="display:none">
          <div class="att-stat">
            <div class="att-stat-label">Students</div>
            <div class="att-stat-val" id="as-students">0</div>
            <div class="att-stat-bar"><div class="att-stat-fill" id="asb-students" style="width:0%;background:var(--teal)"></div></div>
          </div>
          <div class="att-stat">
            <div class="att-stat-label"><i class="fas fa-circle-check" style="color:#2dd4aa;font-size:.7rem"></i> Present</div>
            <div class="att-stat-val" style="color:#2dd4aa" id="as-present">0</div>
            <div class="att-stat-bar"><div class="att-stat-fill" id="asb-present" style="width:0%;background:#2dd4aa"></div></div>
          </div>
          <div class="att-stat">
            <div class="att-stat-label"><i class="fas fa-circle-xmark" style="color:#e76f51;font-size:.7rem"></i> Absent</div>
            <div class="att-stat-val" style="color:#e76f51" id="as-absent">0</div>
            <div class="att-stat-bar"><div class="att-stat-fill" id="asb-absent" style="width:0%;background:#e76f51"></div></div>
          </div>
          <div class="att-stat">
            <div class="att-stat-label"><i class="fas fa-plane-departure" style="color:#a78bfa;font-size:.7rem"></i> Leave</div>
            <div class="att-stat-val" style="color:#a78bfa" id="as-leave">0</div>
            <div class="att-stat-bar"><div class="att-stat-fill" id="asb-leave" style="width:0%;background:#a78bfa"></div></div>
          </div>
          <div class="att-stat">
            <div class="att-stat-label"><i class="fas fa-percent" style="color:var(--teal);font-size:.7rem"></i> Overall %</div>
            <div class="att-stat-val" style="color:var(--teal)" id="as-pct">0%</div>
            <div class="att-stat-bar"><div class="att-stat-fill" id="asb-pct" style="width:0%;background:var(--teal)"></div></div>
          </div>
        </div>

        <!-- ── Defaulters alert strip ── -->
        <div id="att-defaulter-strip" style="display:none;margin-bottom:16px;padding:12px 20px;
          background:rgba(231,111,81,0.08);border:1px solid rgba(231,111,81,0.25);
          border-radius:var(--radius);font-size:0.84rem;color:var(--red);display:none">
          <i class="fas fa-triangle-exclamation"></i>
          <span id="att-defaulter-text"></span>
        </div>

        <!-- ── Student-wise table panel ── -->
        <div class="panel" id="att-student-panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-table"></i> Attendance Records</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <span style="font-size:0.72rem;color:var(--muted)" id="att-range-label"></span>
              <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:0.7rem">
                <span style="background:rgba(45,212,170,.13);color:#2dd4aa;padding:2px 8px;border-radius:4px">P=Present</span>
                <span style="background:rgba(231,111,81,.13);color:#e76f51;padding:2px 8px;border-radius:4px">A=Absent</span>
                <span style="background:rgba(244,162,97,.13);color:#D97706;padding:2px 8px;border-radius:4px">L=Late</span>
                <span style="background:rgba(139,92,246,.13);color:#a78bfa;padding:2px 8px;border-radius:4px">LV/ML=Leave</span>
                <span style="background:rgba(100,116,139,.13);color:#94a3b8;padding:2px 8px;border-radius:4px">HOL=Holiday</span>
              </div>
            </div>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap">
              <table class="data-table" id="att-data-table">
                <thead id="att-thead">
                  <tr>
                    <th style="width:36px;text-align:center">#</th>
                    <th>Student</th>
                    <th style="width:110px">Roll No.</th>
                    <?php if (!$saCollegeId): ?><th>College</th><?php endif; ?>
                    <?php if (!($saDeptId && $saCollegeRole === 'hod')): ?><th>Department</th><?php endif; ?>
                    <th style="width:130px">Attendance %</th>
                    <th style="width:70px;text-align:center">Present</th>
                    <th style="width:70px;text-align:center">Absent</th>
                    <th style="width:70px;text-align:center">Leave</th>
                    <th style="width:80px;text-align:center">Total Days</th>
                  </tr>
                </thead>
                <tbody id="att-tbody">
                  <tr>
                    <td colspan="9">
                      <div class="att-loading">
                        <i class="fas fa-clipboard-check"></i>
                        Select filters above and click <strong style="color:var(--teal)">Load</strong> to view attendance
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ── Course-wise panel ── -->
        <div id="att-course-panel" style="display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px">
            <span style="font-size:0.82rem;color:var(--muted)" id="att-range-label-cw"></span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:0.7rem">
              <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:2px;background:#2dd4aa;display:inline-block"></span>75–100% Good</span>
              <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:2px;background:#f4a261;display:inline-block"></span>60–74% Warning</span>
              <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:2px;background:#e76f51;display:inline-block"></span>&lt;60% Low</span>
            </div>
          </div>
          <div id="att-course-cards">
            <div class="att-loading" style="padding:40px 0;text-align:center;color:var(--muted)">
              <i class="fas fa-book"></i>
              Select filters above and click <strong style="color:var(--teal)">Load</strong> to view course-wise attendance
            </div>
          </div>
        </div>

      </div><!-- /view:attendance -->

      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- VIEW: timetable                                            -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div data-view="timetable" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-calendar-days"></i> Timetable Management</h2>
          <span class="view-count" id="tt-scope-label">Select a college to begin</span>
        </div>

        <!-- ── STEP BAR ── -->
        <div class="tt-stepbar">
          <div class="tt-step active" id="ttstep-1"><span class="tt-step-num">1</span><span>College &amp; Dept</span></div>
          <div class="tt-step-arrow"><i class="fas fa-chevron-right"></i></div>
          <div class="tt-step" id="ttstep-2"><span class="tt-step-num">2</span><span>Section</span></div>
          <div class="tt-step-arrow"><i class="fas fa-chevron-right"></i></div>
          <div class="tt-step" id="ttstep-3"><span class="tt-step-num">3</span><span>Timetable Grid</span></div>
          <div class="tt-step-arrow"><i class="fas fa-chevron-right"></i></div>
          <div class="tt-step" id="ttstep-4"><span class="tt-step-num">4</span><span>Assign Slots</span></div>
        </div>

        <!-- ── STEP 1: Select College + Department ── -->
        <div class="tt-wizard-panel active" id="ttwp-1">
          <div class="panel">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-building"></i> Step 1 — Select College &amp; Department</div>
            </div>
            <div style="padding:24px;display:grid;grid-template-columns:1fr 1fr;gap:18px">
              <?php if ($saCollegeId): ?>
              <!-- Scoped: college locked as readonly -->
              <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;display:block">College</label>
                <input class="form-input" type="text" value="<?= htmlspecialchars($saCollegeName) ?>" readonly
                       style="background:rgba(20,184,166,.06);border-color:rgba(20,184,166,.3);color:var(--teal);cursor:not-allowed;font-weight:600">
                <input type="hidden" id="tt-college" value="<?= $saCollegeId ?>">
              </div>
              <?php else: ?>
              <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;display:block">College *</label>
                <select class="form-input" id="tt-college" onchange="ttCollegeChange()">
                  <option value="">— Select College —</option>
                  <?php foreach ($all_colleges as $col): ?>
                  <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;display:block">Department <?= ($saDeptId ? '' : '*') ?></label>
                <?php if ($saDeptId): ?>
                <!-- HOD mode: department locked -->
                <input class="form-input" type="text" value="<?= htmlspecialchars($saDeptName) ?>" readonly
                       style="background:rgba(20,184,166,.06);border-color:rgba(20,184,166,.3);color:var(--teal);cursor:not-allowed;font-weight:600">
                <input type="hidden" id="tt-dept" value="<?= $saDeptId ?>">
                <?php else: ?>
                <select class="form-input" id="tt-dept" onchange="ttDeptChange()">
                  <option value="">— Select Department —</option>
                  <?php foreach ($all_departments as $d): ?>
                  <option value="<?= $d['id'] ?>" data-college="<?= $d['college_id'] ?>"><?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['code']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </div>
            </div>
            <div style="padding:0 24px 24px;display:flex;justify-content:flex-end">
              <button class="btn-primary" style="padding:10px 24px" onclick="ttGoToStep2()">
                Next: Select Section <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          <!-- Overview list for this college -->
          <div class="panel" id="tt-overview-panel" style="display:none">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-table-list"></i> Existing Timetables</div>
              <button class="btn-add" onclick="ttRefreshOverview()"><i class="fas fa-refresh"></i> Refresh</button>
            </div>
            <div class="panel-body panel-body-table">
              <div class="table-wrap">
                <table class="data-table tt-overview-table">
                  <thead>
                    <tr>
                      <th>#</th><th>Department</th><th>Semester</th><th>Section</th>
                      <th>Academic Year</th><th style="text-align:center">Slots</th>
                      <th style="text-align:center">Status</th><th style="text-align:center">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="tt-overview-tbody">
                    <tr><td colspan="8"><div class="tt-empty"><i class="fas fa-building"></i><p>Select a college above.</p></div></td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- ── STEP 2: Select Semester + Section ── -->
        <div class="tt-wizard-panel" id="ttwp-2">
          <div class="panel">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-layer-group"></i> Step 2 — Select Semester &amp; Section</div>
              <button class="btn-secondary" style="padding:6px 12px;font-size:0.78rem" onclick="ttGoToStep1()"><i class="fas fa-arrow-left"></i> Back</button>
            </div>
            <div style="padding:20px 24px">
              <div style="font-size:0.82rem;color:var(--muted);background:rgba(20,184,166,0.06);border:1px solid rgba(20,184,166,0.18);border-radius:8px;padding:10px 14px;margin-bottom:18px">
                <i class="fas fa-circle-info" style="color:var(--teal)"></i>
                &nbsp;Department: <strong id="tt-step2-dept-label" style="color:var(--text)">—</strong>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
                <div class="form-group">
                  <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;display:block">Semester *</label>
                  <select class="form-input" id="tt-sem" onchange="ttSemChange()">
                    <option value="">— Select Semester —</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label" style="font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;display:block">Section *</label>
                  <select class="form-input" id="tt-sec">
                    <option value="">— Select Semester first —</option>
                  </select>
                  <div id="tt-sec-source-note" style="display:none;font-size:0.74rem;margin-top:6px;font-weight:600"></div>
                </div>
              </div>
              <div style="display:flex;gap:12px;justify-content:flex-end">
                <button class="btn-primary" style="padding:10px 24px" onclick="ttGoToStep3()">
                  Next: View/Edit Grid <i class="fas fa-arrow-right"></i>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- ── STEP 3: Timetable Grid (Edit) ── -->
        <div class="tt-wizard-panel" id="ttwp-3">
          <div class="panel" style="margin-bottom:14px">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-sliders"></i> Timetable Controls</div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <span class="tt-status draft" id="tt-edit-badge">No timetable loaded</span>
                <button class="btn-secondary" style="padding:6px 12px;font-size:0.76rem" onclick="ttGoToStep2()"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
            </div>
            <div style="padding:14px 20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;border-bottom:1px solid var(--border2)">
              <button class="btn-primary" style="padding:8px 16px;font-size:0.8rem" onclick="ttDoLoadOrCreate()">
                <i class="fas fa-calendar-plus"></i> Load / Create Timetable
              </button>
              <span id="tt-selection-info" style="font-size:0.75rem;color:var(--muted);padding:4px 10px;background:rgba(15,118,110,0.08);border-radius:6px;display:none"></span>
              <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn-secondary" style="padding:8px 14px;font-size:0.78rem;color:#a78bfa;border-color:rgba(167,139,250,0.3)" onclick="ttAutoGen()"><i class="fas fa-wand-magic-sparkles"></i> Auto-Gen</button>
                <button class="btn-secondary" style="padding:8px 14px;font-size:0.78rem;color:#10b981;border-color:rgba(16,185,129,0.3)" onclick="ttActivate()"><i class="fas fa-circle-check"></i> Activate</button>
                <button class="btn-secondary" style="padding:8px 14px;font-size:0.78rem" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn-secondary" style="padding:8px 14px;font-size:0.78rem;color:#ef4444;border-color:rgba(239,68,68,0.3)" onclick="openModal('tt-modal-clear')"><i class="fas fa-trash"></i> Clear All</button>
              </div>
            </div>
          </div>
          <div class="panel">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-pen-ruler"></i> Weekly Grid — click any cell to assign</div>
              <span id="tt-grid-status" style="font-size:0.75rem;color:var(--muted)"></span>
            </div>
            <div id="tt-edit-wrap">
              <div class="tt-empty"><i class="fas fa-calendar-plus"></i><p>Click <strong style="color:var(--teal)">Load / Create Timetable</strong> above to begin.</p></div>
            </div>
            <div class="tt-legend" id="tt-edit-legend" style="display:none"></div>
          </div>
          <!-- Setup sub-section -->
          <div class="panel" style="margin-top:14px">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-sliders"></i> Quick Setup — Add periods, rooms &amp; sections for this college</div>
            </div>
            <div class="tt-setup-grid">
              <!-- Add Semester -->
              <div class="tt-setup-card">
                <h4><i class="fas fa-calendar-alt"></i> Add Semester</h4>
                <div class="tt-setup-mini-form">
                  <input id="tts-sem-name" placeholder="e.g. Semester 1 — 2025-26">
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <input id="tts-sem-start" type="date">
                    <input id="tts-sem-end"   type="date">
                  </div>
                  <select id="tts-sem-status">
                    <option value="Upcoming">Upcoming</option>
                    <option value="Current" selected>Current</option>
                    <option value="Completed">Completed</option>
                  </select>
                  <button class="btn-primary" style="padding:8px;font-size:0.8rem" onclick="ttAddSemester()"><i class="fas fa-plus"></i> Add Semester</button>
                </div>
              </div>
              <!-- Add Section -->
              <div class="tt-setup-card">
                <h4><i class="fas fa-layer-group"></i> Add Section</h4>
                <div class="tt-setup-mini-form">
                  <select id="tts-sec-dept">
                    <option value="">— Select Department —</option>
                    <?php foreach ($all_departments as $d): ?>
                    <option value="<?= $d['id'] ?>" data-college="<?= $d['college_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select id="tts-sec-sem"><option value="">— Select Semester —</option></select>
                  <input id="tts-sec-label"    placeholder="Section label (e.g. A, B, C1)">
                  <input id="tts-sec-strength" type="number" placeholder="Student strength (e.g. 60)" min="1">
                  <button class="btn-primary" style="padding:8px;font-size:0.8rem" onclick="ttAddSection()"><i class="fas fa-plus"></i> Add Section</button>
                </div>
              </div>
              <!-- Add Period -->
              <div class="tt-setup-card">
                <h4><i class="fas fa-clock"></i> Add Period Slot</h4>
                <div class="tt-setup-mini-form">
                  <input id="tts-per-label" placeholder="Label (e.g. Period 1, Lunch Break)">
                  <select id="tts-per-type">
                    <option value="Period">Period</option>
                    <option value="Break">Break</option>
                    <option value="Lunch">Lunch</option>
                  </select>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <input id="tts-per-start" type="time">
                    <input id="tts-per-end"   type="time">
                  </div>
                  <input id="tts-per-sort" type="number" placeholder="Sort order (e.g. 1, 2, 3…)" min="1">
                  <button class="btn-primary" style="padding:8px;font-size:0.8rem" onclick="ttAddPeriod()"><i class="fas fa-plus"></i> Add Period</button>
                </div>
              </div>
              <!-- Add Room -->
              <div class="tt-setup-card">
                <h4><i class="fas fa-door-open"></i> Add Room / Lab</h4>
                <div class="tt-setup-mini-form">
                  <input id="tts-room-name"  placeholder="Room name (e.g. CS Lab 1, Room 201)">
                  <select id="tts-room-type">
                    <option value="Classroom">Classroom</option>
                    <option value="Lab">Lab</option>
                    <option value="Seminar Hall">Seminar Hall</option>
                    <option value="Auditorium">Auditorium</option>
                  </select>
                  <input id="tts-room-block"    placeholder="Block (e.g. Block A) — optional">
                  <input id="tts-room-capacity" type="number" placeholder="Capacity (e.g. 60)" min="1">
                  <button class="btn-primary" style="padding:8px;font-size:0.8rem" onclick="ttAddRoom()"><i class="fas fa-plus"></i> Add Room</button>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /view:timetable -->

      <!-- ═══════════════════════════════════════════════════════
           EXAM MANAGEMENT VIEW — Wizard-based (College→Dept→Subject→Room→Seating→Invigilator)
           ═══════════════════════════════════════════════════════ -->
      <div data-view="exam-management" style="display:none">

        <!-- ── Top bar: title + tabs ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
          <h2 class="view-title"><i class="fas fa-file-invoice"></i> Exam Management</h2>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if ($saCollegeRole !== 'principal'): ?>
            <button class="btn-secondary" id="emTab-wizard"    onclick="emSwitchTab('wizard')"                              style="border-color:rgba(20,184,166,.4);color:var(--teal)"><i class="fas fa-wand-magic-sparkles"></i> Seating Wizard</button>
            <?php endif; ?>
            <button class="btn-secondary" id="emTab-list"      onclick="emSwitchTab('list')" <?php if ($saCollegeRole === 'principal'): ?>style="border-color:rgba(20,184,166,.4);color:var(--teal)"<?php endif; ?>><i class="fas fa-list"></i> Exam List</button>
            <button class="btn-secondary" id="emTab-qpaper"    onclick="emSwitchTab('qpaper');saLoadPendingQPapers()"><i class="fas fa-clock"></i> Q.Paper Approvals</button>
            <?php if ($saCollegeRole !== 'principal'): ?>
            <button class="btn-secondary" id="emTab-halls"     onclick="emSwitchTab('halls');emLoadHallsManager()"><i class="fas fa-door-open"></i> Exam Halls</button>
            <button class="btn-secondary" id="emTab-conflicts" onclick="emSwitchTab('conflicts');saLoadConflicts()"><i class="fas fa-triangle-exclamation" style="color:#f59e0b"></i> Conflicts <span id="emConflictBadge" style="display:none;background:#ef4444;color:var(--text);border-radius:50px;padding:1px 6px;font-size:.65rem;margin-left:3px">0</span></button>
            <?php endif; ?>
            <?php if ($saCollegeRole !== 'principal'): ?>
            <button class="btn-add" style="margin-left:auto" onclick="openSACreateExamModal()"><i class="fas fa-plus"></i> Create Exam</button>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── Exam Management body: vertical stats sidebar + main content ── -->
        <div style="display:flex;gap:16px;align-items:flex-start">

          <!-- Quick Stats Sidebar -->
          <div id="emStatsBar" style="display:flex;flex-direction:column;gap:8px;width:160px;flex-shrink:0">
            <!-- TOTAL -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">TOTAL</div>
              <div style="width:28px;height:2px;background:var(--border2);border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:var(--text);line-height:1" id="emStat-total">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Exams</div>
            </div>
            <!-- UPCOMING -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">UPCOMING</div>
              <div style="width:28px;height:2px;background:var(--teal);border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:var(--teal);line-height:1" id="emStat-upcoming">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Exams</div>
            </div>
            <!-- SCHEDULED -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">SCHEDULED</div>
              <div style="width:28px;height:2px;background:#a78bfa;border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:#a78bfa;line-height:1" id="emStat-scheduled">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Exams</div>
            </div>
            <!-- COMPLETED -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">COMPLETED</div>
              <div style="width:28px;height:2px;background:#2dd4aa;border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:#2dd4aa;line-height:1" id="emStat-completed">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Exams</div>
            </div>
            <!-- CONFLICTS -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px" id="emStat-conflictCard">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">CONFLICTS</div>
              <div style="width:28px;height:2px;background:#ef4444;border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:#ef4444;line-height:1" id="emStat-conflicts">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Unresolved</div>
            </div>
            <!-- PUBLISHED -->
            <div style="padding:14px 16px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);display:flex;flex-direction:column;gap:2px">
              <div style="font-size:.6rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em">PUBLISHED</div>
              <div style="width:28px;height:2px;background:#f59e0b;border-radius:2px;margin:4px 0 6px"></div>
              <div style="font-size:1.5rem;font-weight:800;color:#f59e0b;line-height:1" id="emStat-published">—</div>
              <div style="font-size:.68rem;color:var(--muted);margin-top:2px">Exams</div>
            </div>
          </div>

          <!-- Main content area (panels go here) -->
          <div style="flex:1;min-width:0">

        <!-- ══════════════════════════════════
             TAB: SEATING WIZARD
             ══════════════════════════════════ -->
        <div id="emPanel-wizard"<?php if ($saCollegeRole === 'principal'): ?> style="display:none"<?php endif; ?>>

          <!-- Step indicators -->
          <div style="display:flex;align-items:center;gap:4px;margin-bottom:20px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 20px;flex-wrap:wrap" id="emStepBar">
            <?php
            $emSteps = [1=>'College',2=>'Department',3=>'Subject',4=>'Room',5=>'Seating Grid',6=>'Invigilators',7=>'Summary'];
            foreach ($emSteps as $n => $label): ?>
              <div class="tt-step <?= $n===1?'active':'' ?>" id="emStep-<?=$n?>">
                <span class="tt-step-num" id="emStepNum-<?=$n?>"><?=$n?></span>
                <span><?= htmlspecialchars($label) ?></span>
              </div>
              <?php if ($n < 7): ?><div class="tt-step-arrow"><i class="fas fa-chevron-right"></i></div><?php endif; ?>
            <?php endforeach; ?>
          </div>

          <!-- Selection breadcrumb bar -->
          <div id="emSelBar" style="display:none;margin-bottom:16px;padding:10px 16px;background:rgba(20,184,166,0.06);border:1px solid rgba(20,184,166,0.18);border-radius:var(--radius-sm);font-size:0.8rem;color:var(--text);gap:12px;flex-wrap:wrap;align-items:center"></div>

          <!-- ── WIZARD PANEL 1: College ── -->
          <div class="tt-wizard-panel active" id="emWP-1">
            <div class="panel">
              <div class="panel-header"><div class="panel-title"><i class="fas fa-building"></i> Step 1 — Select College &amp; Exam Details</div></div>
              <div style="padding:20px 20px 10px" id="em-college-cards">
                <label class="form-label">Select College *</label>
                <?php if ($saCollegeId): ?>
                <!-- Scoped mode: show readonly + hidden input with data attributes -->
                <input class="input-field" type="text"
                       value="<?= htmlspecialchars($saCollegeName) ?>"
                       readonly style="max-width:480px;background:rgba(20,184,166,.06);border-color:rgba(20,184,166,.3);color:var(--teal);cursor:not-allowed;font-weight:600">
                <input type="hidden" id="emCollegeSelect"
                       value="<?= $saCollegeId ?>"
                       data-name="<?= htmlspecialchars($saCollegeName) ?>"
                       data-code="<?= htmlspecialchars($recent_colleges[0]['code'] ?? '') ?>">
                <?php else: ?>
                <select id="emCollegeSelect" class="input-field" style="max-width:480px"
                        onchange="emCollegeDropdownChange(this)">
                  <?php foreach ($all_colleges as $col): ?>
                  <option value="<?=$col['id']?>" <?=($col===reset($all_colleges)?'selected':'')?>
                          data-name="<?=htmlspecialchars($col['name'])?>"
                          data-code="<?=htmlspecialchars($col['code'])?>">
                    <?=htmlspecialchars($col['code'])?> — <?=htmlspecialchars($col['name'])?>
                    (<?=$col['dept_count']?> depts · <?=$col['course_count']?> courses)
                  </option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </div>
              <!-- Exam details inline -->
              <div style="padding:0 20px 20px">
                <div style="padding:14px 16px;background:rgba(15,118,110,.05);border-radius:var(--radius-sm);border:1px solid var(--border2)">
                  <div style="font-size:.78rem;color:var(--muted);margin-bottom:12px"><i class="fas fa-circle-info" style="color:var(--teal)"></i> &nbsp;Fill exam details before continuing.</div>
                  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
                    <div style="grid-column:1/-1"><label class="form-label">Exam Title *</label>
                      <input type="text" id="emTitle" class="input-field" placeholder="e.g. Mid-Term Examination — May 2026" oninput="emClearStep1Error()"></div>
                    <div><label class="form-label">Exam Type</label>
                      <select id="emType" class="input-field">
                        <option value="mid_term">Mid Term</option>
                        <option value="unit_test">Unit Test</option>
                        <option value="final">Final / End-Sem</option>
                        <option value="practical">Practical</option>
                        <option value="assignment">Assignment</option>
                        <option value="viva">Viva</option>
                      </select>
                    </div>
                    <div><label class="form-label">Exam Date *</label>
                      <input type="date" id="emDate" class="input-field" onchange="emClearStep1Error()">
                    </div>
                    <div><label class="form-label">Max Marks</label>
                      <input type="number" id="emMaxMarks" class="input-field" value="100" min="1">
                    </div>
                    <div><label class="form-label">Pass Marks</label>
                      <input type="number" id="emPassMarks" class="input-field" value="40" min="1">
                    </div>
                    <div><label class="form-label">Semester *</label>
                      <select id="emSemester" class="input-field" onchange="emClearStep1Error()">
                        <option value="">— Loading semesters… —</option>
                      </select>
                    </div>
                    <div><label class="form-label">Academic Year</label>
                      <input type="text" id="emAcadYear" class="input-field" value="2025-26" placeholder="e.g. 2025-26">
                    </div>
                  </div>
                </div>
              </div>
              <!-- Inline error banner for Step 1 -->
              <div id="emStep1Error" style="display:none;margin:0 20px 12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                <i class="fas fa-triangle-exclamation"></i> <span id="emStep1ErrorMsg"></span>
              </div>
              <div style="padding:0 20px 20px;display:flex;justify-content:flex-end">
                <button class="btn-primary" onclick="emGoStep(2)">Next — Select Department <i class="fas fa-arrow-right"></i></button>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 2: Department ── -->
          <div class="tt-wizard-panel" id="emWP-2">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-sitemap"></i> Step 2 — Select Department</div>
                <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
              <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
              <!-- HOD: department is locked — show read-only badge -->
              <div style="padding:20px">
                <div style="display:inline-flex;align-items:center;gap:12px;padding:14px 20px;border-radius:var(--radius);border:2px solid rgba(20,184,166,.4);background:rgba(20,184,166,.06);max-width:360px">
                  <div style="width:36px;height:36px;border-radius:10px;background:rgba(20,184,166,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fas fa-lock" style="color:var(--teal);font-size:.85rem"></i>
                  </div>
                  <div>
                    <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars($saDeptName) ?></div>
                    <div style="font-size:.72rem;color:var(--muted);margin-top:2px">Department locked to your HOD role</div>
                  </div>
                </div>
                <p style="font-size:.78rem;color:var(--muted);margin-top:12px"><i class="fas fa-circle-info" style="color:var(--teal)"></i> &nbsp;Your department is pre-selected. Click <strong>Next</strong> to choose a subject.</p>
              </div>
              <?php else: ?>
              <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px" id="em-dept-cards">
                <div style="color:var(--muted);grid-column:1/-1;text-align:center;padding:30px">Select a college first</div>
              </div>
              <?php endif; ?>
              <div id="emStep2Error" style="display:none;margin:0 20px 12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                <i class="fas fa-triangle-exclamation"></i> <span id="emStep2ErrorMsg"></span>
              </div>
              <div style="padding:0 20px 20px;display:flex;justify-content:flex-end">
                <button class="btn-primary" onclick="emGoStep(3)">Next — Select Subject <i class="fas fa-arrow-right"></i></button>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 3: Subject ── -->
          <div class="tt-wizard-panel" id="emWP-3">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-book"></i> Step 3 — Select Subject / Course</div>
                <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px" id="em-course-cards">
                <div style="color:var(--muted);grid-column:1/-1;text-align:center;padding:30px">Select a department first</div>
              </div>
              <div id="emStep3Error" style="display:none;margin:0 20px 12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                <i class="fas fa-triangle-exclamation"></i> <span id="emStep3ErrorMsg"></span>
              </div>
              <div style="padding:0 20px 20px;margin-top:6px;display:flex;justify-content:flex-end">
                <button class="btn-primary" onclick="emGoStep(4)">Next — Assign Room <i class="fas fa-arrow-right"></i></button>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 4: Room / Hall ── -->
          <div class="tt-wizard-panel" id="emWP-4">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-map-pin"></i> Step 4 — Assign Exam Room &amp; Schedule</div>
                <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px" id="em-hall-cards">
                <div style="color:var(--muted);grid-column:1/-1;text-align:center;padding:30px">Select a college first</div>
              </div>
              <!-- Schedule time + exam linkage -->
              <div style="padding:0 20px 20px">
                <div id="emHallStudentNote" style="display:none;margin-bottom:10px;padding:8px 12px;border-radius:var(--radius-sm);font-size:.78rem"></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:8px;padding:14px;background:rgba(15,118,110,.05);border-radius:var(--radius-sm);border:1px solid var(--border2)">
                  <div style="grid-column:1/-1;font-size:.78rem;color:var(--muted);margin-bottom:4px">
                    <i class="fas fa-calendar-plus" style="color:var(--teal)"></i> &nbsp;Create exam &amp; schedule it in this hall
                  </div>
                  <div><label class="form-label">Start Time</label><input type="time" id="emSchedStart" class="input-field" value="09:00"></div>
                  <div><label class="form-label">End Time</label><input type="time" id="emSchedEnd" class="input-field" value="12:00"></div>
                </div>
                <div style="display:flex;gap:10px;margin-top:12px;justify-content:flex-end;flex-wrap:wrap">
                  <button class="btn-add" onclick="emCreateAndSchedule()"><i class="fas fa-calendar-check"></i> Create Exam &amp; Save Schedule</button>
                </div>
              </div>
              <div id="emStep4Error" style="display:none;margin:0 20px 12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                <i class="fas fa-triangle-exclamation"></i> <span id="emStep4ErrorMsg"></span>
              </div>
              <div style="padding:0 20px 20px;display:flex;justify-content:flex-end">
                <button class="btn-primary" onclick="emGoStep(5)">Next — Seating Grid <i class="fas fa-arrow-right"></i></button>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 5: Seating Grid ── -->
          <div class="tt-wizard-panel" id="emWP-5">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-th"></i> Step 5 — Seating Grid</div>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  <button class="btn-secondary" style="padding:5px 10px;font-size:.76rem;color:#a78bfa;border-color:rgba(167,139,250,.3)" onclick="emAutoAssignSeats()"><i class="fas fa-wand-magic-sparkles"></i> Auto-Assign</button>
                  <button class="btn-secondary" style="padding:5px 9px;font-size:.74rem;color:#2dd4aa;border-color:rgba(45,212,170,.3)" onclick="emBulkMark('present')" title="Mark all students present"><i class="fas fa-check-double"></i> All Present</button>
                  <button class="btn-secondary" style="padding:5px 9px;font-size:.74rem;color:#e76f51;border-color:rgba(231,111,81,.3)" onclick="emBulkMark('absent')" title="Mark all students absent"><i class="fas fa-times"></i> All Absent</button>
                  <button class="btn-secondary" style="padding:5px 9px;font-size:.74rem;color:var(--teal);border-color:rgba(20,184,166,.3)" onclick="emExportAttendance()" title="Export attendance as CSV"><i class="fas fa-file-csv"></i> Export CSV</button>
                  <button class="btn-secondary" style="padding:5px 9px;font-size:.74rem;color:var(--teal);border-color:rgba(20,184,166,.3)" onclick="emPrintSeating()" title="Print seating chart"><i class="fas fa-print"></i> Print</button>
                  <button class="btn-secondary" style="padding:5px 10px;font-size:.76rem;color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="emClearAllSeats()"><i class="fas fa-eraser"></i> Clear All</button>
                  <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(4)"><i class="fas fa-arrow-left"></i> Back</button>
                </div>
              </div>
              <!-- Seat legend -->
              <div style="padding:10px 20px 0;display:flex;gap:16px;flex-wrap:wrap;font-size:.74rem;align-items:center">
                <span style="display:flex;align-items:center;gap:5px"><span style="width:14px;height:14px;border-radius:3px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);display:inline-block"></span>Empty</span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:14px;height:14px;border-radius:3px;background:rgba(20,184,166,.14);border:1px solid rgba(20,184,166,.5);display:inline-block"></span><span style="color:var(--teal)">Assigned</span></span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:14px;height:14px;border-radius:3px;background:rgba(45,212,170,.22);border:1px solid #2dd4aa;display:inline-block"></span><span style="color:#2dd4aa">Present</span></span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:14px;height:14px;border-radius:3px;background:rgba(231,111,81,.18);border:1px solid #e76f51;display:inline-block"></span><span style="color:#e76f51">Absent</span></span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:14px;height:14px;border-radius:3px;background:rgba(167,139,250,.2);border:1px solid #a78bfa;display:inline-block"></span><span style="color:#a78bfa">Invigilator</span></span>
              </div>
              <!-- Stats bar -->
              <div style="padding:12px 20px;display:flex;gap:20px;flex-wrap:wrap;font-size:.8rem;border-bottom:1px solid var(--border2);align-items:center" id="emGridStats">
                <span><strong id="emStatTotal" style="color:var(--text)">—</strong> <span style="color:var(--muted)">students</span></span>
                <span><strong id="emStatAssigned" style="color:var(--teal)">0</strong> <span style="color:var(--muted)">assigned</span></span>
                <span><strong id="emStatPresent" style="color:#2dd4aa">0</strong> <span style="color:var(--muted)">present</span></span>
                <span><strong id="emStatAbsent" style="color:#e76f51">0</strong> <span style="color:var(--muted)">absent</span></span>
                <span style="margin-left:auto"><strong id="emStatUnassigned" style="color:var(--amber)">—</strong> <span style="color:var(--muted)">unassigned</span></span>
              </div>

              <div style="padding:10px 20px;font-size:.8rem;color:var(--muted)" id="emGridMeta">Select college, dept and room to load seating grid.</div>
              <div style="overflow-x:auto;padding:0 20px 20px" id="emGridWrap">
                <div style="padding:40px;text-align:center;color:var(--muted)"><i class="fas fa-th" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>Complete steps 1–4 first</div>
              </div>
              <!-- Seat action popup -->
              <div id="emSeatPopup" style="display:none;margin:0 20px 16px;padding:14px;background:rgba(15,118,110,.05);border-radius:var(--radius-sm);border:1px solid var(--border2)"></div>
              <div id="emStep5Error" style="display:none;margin:0 20px 12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                <i class="fas fa-triangle-exclamation"></i> <span id="emStep5ErrorMsg"></span>
              </div>
              <div style="padding:0 20px 20px;display:flex;justify-content:flex-end">
                <button class="btn-primary" onclick="emGoStep(6)">Next — Assign Invigilators <i class="fas fa-arrow-right"></i></button>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 6: Invigilators ── -->
          <div class="tt-wizard-panel" id="emWP-6">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-user-tie"></i> Step 6 — Assign Invigilators</div>
                <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(5)"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px">
                <div style="font-size:.78rem;color:var(--muted);margin-bottom:14px">
                  <i class="fas fa-circle-info" style="color:var(--teal)"></i>
                  &nbsp;Check a faculty member to assign them. Each gets a duty type (Chief / Assistant / Flying Squad).
                </div>
                <div id="em-invig-list" style="display:flex;flex-direction:column;gap:8px">
                  <div style="color:var(--muted);text-align:center;padding:30px">Select a college first</div>
                </div>
                <div id="emStep6Error" style="display:none;margin-top:12px;padding:10px 14px;background:rgba(231,111,81,.12);border:1px solid rgba(231,111,81,.4);border-radius:var(--radius-sm);font-size:.82rem;color:#e76f51;font-weight:600">
                  <i class="fas fa-triangle-exclamation"></i> <span id="emStep6ErrorMsg"></span>
                </div>
                <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
                  <button class="btn-primary" onclick="emGoStep(7)">Next — Review Summary <i class="fas fa-arrow-right"></i></button>
                </div>
              </div>
            </div>
          </div>

          <!-- ── WIZARD PANEL 7: Summary ── -->
          <div class="tt-wizard-panel" id="emWP-7">
            <div class="panel">
              <div class="panel-header">
                <div class="panel-title"><i class="fas fa-circle-check" style="color:#2dd4aa"></i> Step 7 — Review &amp; Finish</div>
                <button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="emGoStep(6)"><i class="fas fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px" id="emSummaryBody">
                <div style="color:var(--muted);text-align:center;padding:30px">Complete earlier steps to see the summary.</div>
              </div>
            </div>
            <!-- Final summary panel rendered after finish -->
            <div id="emSummaryPanel" style="display:none;margin-top:16px">
              <div class="panel">
                <div class="panel-header"><div class="panel-title"><i class="fas fa-circle-check" style="color:#2dd4aa"></i> Exam Setup Complete</div></div>
                <div style="padding:20px" id="emSummaryDone"></div>
              </div>
            </div>
          </div>

        </div><!-- /emPanel-wizard -->

        <!-- ══════════════════════════════════
             TAB: EXAM LIST
             ══════════════════════════════════ -->
        <div id="emPanel-list" style="<?php echo $saCollegeRole === 'principal' ? '' : 'display:none'; ?>">
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
            <?php if ($saCollegeRole === 'hod' || $saCollegeRole === 'principal'): ?>
            <!-- HOD/Principal: locked to their college (and dept for HOD) -->
            <input type="hidden" id="saExamCollege" value="<?= (int)$saCollegeId ?>">
            <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
            <input type="hidden" id="saExamDept" value="<?= (int)$saDeptId ?>">
            <div style="padding:7px 14px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);font-size:.82rem;color:var(--text);display:flex;align-items:center;gap:6px">
              <i class="fas fa-building" style="color:var(--teal);font-size:.72rem"></i>
              <strong><?= htmlspecialchars($saCollegeName) ?></strong>
              <i class="fas fa-chevron-right" style="opacity:.4;font-size:.65rem"></i>
              <span style="color:var(--muted)"><?= htmlspecialchars($saDeptName) ?></span>
            </div>
            <?php else: ?>
            <input type="hidden" id="saExamDept" value="">
            <div style="padding:7px 14px;background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius-sm);font-size:.82rem;color:var(--text);display:flex;align-items:center;gap:6px">
              <i class="fas fa-university" style="color:var(--teal);font-size:.72rem"></i>
              <strong><?= htmlspecialchars($saCollegeName) ?></strong>
              <span style="color:var(--muted);font-size:.75rem;margin-left:4px">· All Departments</span>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <select id="saExamCollege" class="input-field" onchange="saExamCollegeChange()" style="min-width:160px">
              <option value="">All Colleges</option>
              <?php foreach ($all_colleges as $col): ?>
              <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="saExamDept" class="input-field" onchange="saLoadExams()" style="min-width:160px">
              <option value="">All Departments</option>
              <?php foreach ($all_departments as $d): ?>
              <option value="<?= $d['id'] ?>" data-college="<?= $d['college_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select id="saExamStatus" class="input-field" onchange="saLoadExams()" style="min-width:130px">
              <option value="">All Statuses</option>
              <option value="draft">Draft</option>
              <option value="upcoming">Upcoming</option>
              <option value="ongoing">Ongoing</option>
              <option value="completed">Completed</option>
              <option value="published">Published</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <input type="text" id="saExamSearch" class="input-field" placeholder="Search exam title…" oninput="saFilterExamsLocal()" style="min-width:180px;flex:1">
            <button class="btn-secondary" onclick="saLoadExams()"><i class="fas fa-rotate-right"></i> Refresh</button>
          </div>
          <div class="card">
            <div class="card-header"><h3><i class="fas fa-list"></i> All Exams</h3></div>
            <div id="saExamListWrap" style="padding:16px">
              <p style="color:var(--muted)">Select a college/department above or click Refresh.</p>
              <button class="btn-secondary" onclick="saLoadExams()"><i class="fas fa-list"></i> Load All Exams</button>
            </div>
          </div>
                </div><!-- /emPanel-list -->

        <!-- ══════════════════════════════════
             TAB: Q.PAPER APPROVALS
             ══════════════════════════════════ -->
        <div id="emPanel-qpaper" style="display:none">
          <div class="card">
            <div class="card-header">
              <h3><i class="fas fa-check-double"></i> Pending Q.Paper Approvals</h3>
              <button class="btn-secondary btn-sm" onclick="saLoadPendingQPapers()"><i class="fas fa-rotate-right"></i> Refresh</button>
            </div>
            <div id="saPendingQPapersWrap" style="padding:16px"><p style="color:var(--muted)">Loading…</p></div>
          </div>
        </div>


        <!-- ══════════════════════════════════
             TAB: SCHEDULE CONFLICTS
             ══════════════════════════════════ -->
        <div id="emPanel-conflicts" style="display:none">
          <div class="panel">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-triangle-exclamation" style="color:#f59e0b"></i> Schedule Conflicts</div>
              <button class="btn-secondary btn-sm" onclick="saLoadConflicts()"><i class="fas fa-rotate-right"></i> Refresh</button>
            </div>
            <div id="saConflictsWrap" style="padding:16px">
              <p style="color:var(--muted)">Loading conflicts…</p>
            </div>
          </div>
        </div>

        <!-- ── TAB: EXAM HALLS MANAGER ── -->
        <div id="emPanel-halls" style="display:none">
          <div class="panel">
            <div class="panel-header">
              <div class="panel-title"><i class="fas fa-door-open"></i> Exam Halls</div>
              <button class="btn-add btn-sm" onclick="openExamHallModal()"><i class="fas fa-plus"></i> Add Hall</button>
            </div>
            <div id="emHallsManagerWrap" style="padding:16px">
              <p style="color:var(--muted);text-align:center;padding:20px"><i class="fas fa-spinner fa-spin"></i> Loading…</p>
            </div>
          </div>
        </div>

          </div><!-- /main content area -->
        </div><!-- /flex row: stats sidebar + content -->

      </div><!-- /view:exam-management -->


      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: accountant-credentials                          -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="accountant-credentials" style="display:none">
        <div class="view-header">
          <h2 class="view-title"><i class="fas fa-coins" style="color:var(--teal)"></i> Accountant Credentials</h2>
          <p style="font-size:.78rem;color:var(--muted);margin:4px 0 0">Create login credentials for each college's accounts portal. One active credential per college.</p>
        </div>

        <div class="panel" style="margin-bottom:20px">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-key"></i> Manage Accountant Logins</div>
            <button class="btn-primary btn-sm" onclick="openAccCredModal()">
              <i class="fas fa-plus"></i> Create Credential
            </button>
          </div>
          <div class="panel-body">

            <!-- College filter -->
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px">
              <label style="font-size:.75rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">College:</label>
              <?php
              $dbC = getDB();
              $cRowsForFilter = [];
              if ($dbC) {
                  if ($saCollegeId) {
                      // Principal: only their own college, pre-selected
                      $cStF = $dbC->prepare('SELECT id, name FROM colleges WHERE id = ? AND status = "active" LIMIT 1');
                      $cStF->execute([$saCollegeId]);
                      $cRowsForFilter = $cStF->fetchAll(PDO::FETCH_ASSOC);
                  } else {
                      // Superadmin: all colleges
                      $cRowsForFilter = $dbC->query('SELECT id, name FROM colleges WHERE status="active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
                  }
              }
              ?>
              <select id="accCredCollegeFilter" onchange="loadAccCredentials()"
                style="padding:7px 14px;background:rgba(15,118,110,.04);border:1px solid rgba(15,118,110,.1);border-radius:8px;color:var(--text);font-family:inherit;font-size:.84rem;outline:none;min-width:240px"
                <?= $saCollegeId ? 'disabled' : '' ?>>
                <?php if (!$saCollegeId): ?><option value="">— Select a College —</option><?php endif; ?>
                <?php foreach ($cRowsForFilter as $cr): ?>
                <option value="<?= (int)$cr['id'] ?>" <?= ((int)$cr['id'] === $saCollegeId) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cr['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if ($saCollegeId): ?>
              <span style="font-size:.74rem;color:var(--muted);font-style:italic">
                <i class="fas fa-lock" style="font-size:.65rem;color:var(--teal)"></i>
                Scoped to your college
              </span>
              <?php endif; ?>
              <button onclick="loadAccCredentials()" class="btn-secondary btn-sm">
                <i class="fas fa-rotate-right"></i> Refresh
              </button>
            </div>

            <!-- Info strip -->
            <div style="background:rgba(244,162,97,.07);border:1px solid rgba(244,162,97,.2);border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:.76rem;color:rgba(244,162,97,.88);display:flex;gap:9px;align-items:flex-start">
              <i class="fas fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i>
              <span>Creating a new credential <strong>deactivates the previous one</strong> for that college. Accountants log in at <code style="font-family:monospace;background:rgba(15,118,110,.05);padding:1px 5px;border-radius:4px">/auth/accounts_login.php</code> — not the main ERP login.</span>
            </div>

            <!-- Credentials table (loaded via JS) -->
            <div id="accCredTable">
              <p style="color:var(--muted);font-size:.83rem;padding:8px 0">Select a college above to view its accountant credentials.</p>
            </div>

          </div>
        </div>
      </div><!-- /view:accountant-credentials -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: expense-applications                             -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="expense-applications" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px">
          <div>
            <h2 class="view-title"><i class="fas fa-file-invoice-dollar" style="color:#f59e0b"></i> Expense Applications</h2>
            <p style="font-size:.78rem;color:var(--muted);margin:4px 0 0">Review and action faculty expense requests for your college / department.</p>
          </div>
          <button class="btn-secondary btn-sm" onclick="loadExpenses()" style="align-self:flex-start">
            <i class="fas fa-rotate-right"></i> Refresh
          </button>
        </div>

        <!-- Summary stat bar -->
        <div class="exp-stat-bar" id="expStatBar">
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatTotal" style="color:#0F766E">—</div>
            <div class="exp-stat-lbl">Total</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatPending" style="color:#f59e0b">—</div>
            <div class="exp-stat-lbl">Pending</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatApproved" style="color:#10b981">—</div>
            <div class="exp-stat-lbl">Approved</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatRejected" style="color:#ef4444">—</div>
            <div class="exp-stat-lbl">Rejected</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatDisbursed" style="color:#3b82f6">—</div>
            <div class="exp-stat-lbl">Disbursed</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="expStatAmount" style="color:#8b5cf6;font-size:1.1rem">—</div>
            <div class="exp-stat-lbl">Total Amount (Approved)</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-list"></i> Applications List</div>
          </div>
          <div class="panel-body">

            <!-- Filters -->
            <div class="exp-filter-bar">
              <?php
              $dbExpF = getDB();
              $expColleges = [];
              if ($dbExpF) {
                  if ($saCollegeId) {
                      $cs = $dbExpF->prepare('SELECT id, name FROM colleges WHERE id=? AND status="active" LIMIT 1');
                      $cs->execute([$saCollegeId]);
                      $expColleges = $cs->fetchAll(PDO::FETCH_ASSOC);
                  } else {
                      $expColleges = $dbExpF->query('SELECT id, name FROM colleges WHERE status="active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
                  }
              }
              ?>
              <?php if (!$saCollegeId): ?>
              <select id="expFilterCollege" onchange="loadExpenses()">
                <option value="">All Colleges</option>
                <?php foreach ($expColleges as $ec): ?>
                <option value="<?= (int)$ec['id'] ?>"><?= htmlspecialchars($ec['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="hidden" id="expFilterCollege" value="<?= (int)$saCollegeId ?>">
              <?php endif; ?>

              <?php if ($saCollegeRole !== 'hod' || !$saDeptId): ?>
              <select id="expFilterDept" onchange="loadExpenses()">
                <option value="">All Departments</option>
              </select>
              <?php else: ?>
              <input type="hidden" id="expFilterDept" value="<?= (int)$saDeptId ?>">
              <?php endif; ?>

              <select id="expFilterStatus" onchange="loadExpenses()">
                <option value="all">All Statuses</option>
                <?php if ($saCollegeRole === 'hod'): ?>
                  <!-- HOD only sees pending queue -->
                  <option value="pending">Pending</option>
                <?php elseif ($saCollegeRole === 'principal'): ?>
                  <!-- Principal sees HOD-forwarded and beyond -->
                  <option value="review">Forwarded (Under Review)</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="disbursed">Disbursed</option>
                <?php else: ?>
                  <!-- SuperAdmin sees all -->
                  <option value="pending">Pending (HOD Queue)</option>
                  <option value="review">Forwarded to Principal</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="disbursed">Disbursed</option>
                <?php endif; ?>
              </select>

              <select id="expFilterPriority" onchange="loadExpenses()">
                <option value="all">All Priorities</option>
                <option value="urgent">Urgent</option>
                <option value="high">High</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
              </select>
            </div>

            <!-- HOD info banner -->
            <?php if ($saCollegeRole === 'hod'): ?>
            <div style="background:rgba(244,162,97,.07);border:1px solid rgba(244,162,97,.2);border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:.76rem;color:rgba(244,162,97,.88);display:flex;gap:9px;align-items:center">
              <i class="fas fa-sitemap" style="flex-shrink:0"></i>
              <span>
                <strong>HOD Queue</strong> — These are applications awaiting your review.
                Click <strong>Forward</strong> to send an application to the Principal for final approval,
                or <strong>Reject</strong> to decline it. Applications you forward will <em>no longer appear here</em> and will move to the Principal's queue.
              </span>
            </div>
            <?php elseif ($saCollegeRole === 'principal'): ?>
            <div style="background:rgba(20,184,166,.05);border:1px solid rgba(20,184,166,.18);border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:.76rem;color:rgba(20,184,166,.88);display:flex;gap:9px;align-items:center">
              <i class="fas fa-crown" style="flex-shrink:0"></i>
              <span>
                <strong>Principal Queue</strong> — Only applications <em>forwarded by the HOD</em> appear here (status: <strong>Forwarded</strong>).
                Raw faculty submissions are reviewed by the HOD first. You can <strong>Approve</strong>, <strong>Reject</strong>, or mark as <strong>Disbursed</strong>.
              </span>
            </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="table-wrap" id="expTableWrap">
              <p style="color:var(--muted);font-size:.83rem;padding:8px 0">Loading expense applications…</p>
            </div>

          </div>
        </div>
      </div><!-- /view:expense-applications -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: admission-applications (Principal accept/reject) -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="admission-applications" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px">
          <div>
            <h2 class="view-title"><i class="fas fa-user-graduate" style="color:#7c3aed"></i> Admission Applications</h2>
            <p style="font-size:.78rem;color:var(--muted);margin:4px 0 0">
              Review forwarded student applications. Accept to enrol as a student, or Reject to decline.
            </p>
          </div>
          <button class="btn-secondary btn-sm" onclick="loadAdmissions()" style="align-self:flex-start">
            <i class="fas fa-rotate-right"></i> Refresh
          </button>
        </div>

        <!-- Stats bar -->
        <div class="exp-stat-bar" id="admStatBar">
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="admStatReview" style="color:#7c3aed">—</div>
            <div class="exp-stat-lbl">Awaiting Review</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="admStatApproved" style="color:#10b981">—</div>
            <div class="exp-stat-lbl">Accepted</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="admStatRejected" style="color:#ef4444">—</div>
            <div class="exp-stat-lbl">Rejected</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="admStatTotal" style="color:#0F766E">—</div>
            <div class="exp-stat-lbl">Total (Paid)</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header" style="flex-wrap:wrap;gap:8px">
            <div class="panel-title"><i class="fas fa-list"></i> Applications</div>
            <!-- Status filter -->
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <select id="admFilterStatus" onchange="loadAdmissions()"
                style="padding:5px 10px;border:1px solid var(--border);border-radius:7px;font-size:.77rem;background:var(--bg);color:var(--text);font-family:var(--font)">
                <option value="all">All Statuses</option>
                <option value="Under Review">Awaiting Review</option>
                <option value="Approved">Accepted</option>
                <option value="Rejected">Rejected</option>
              </select>
              <input id="admSearch" type="text" placeholder="Search name / app no / course…"
                oninput="filterAdmTable()"
                style="padding:5px 12px;border:1px solid var(--border);border-radius:7px;font-size:.77rem;background:var(--bg);color:var(--text);font-family:var(--font);min-width:220px">
            </div>
          </div>
          <div class="panel-body">

            <!-- Role banner -->
            <div style="background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.18);border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:.76rem;color:rgba(124,58,237,.9);display:flex;gap:9px;align-items:center">
              <i class="fas fa-crown" style="flex-shrink:0"></i>
              <span>
                <strong>Principal Queue</strong> — Applications forwarded by the Accounts office (fee paid &amp; status <em>Under Review</em>).
                Click <strong>Accept</strong> to enrol the student (auto-creates login) or <strong>Reject</strong> to decline.
              </span>
            </div>

            <!-- Table -->
            <div class="table-wrap" id="admTableWrap">
              <p style="color:var(--muted);font-size:.83rem;padding:8px 0">Loading admission applications…</p>
            </div>

          </div>
        </div>
      </div><!-- /view:admission-applications -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: leave-applications (HOD approve; Principal view) -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="leave-applications" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px">
          <div>
            <h2 class="view-title"><i class="fas fa-calendar-minus" style="color:#a78bfa"></i> Leave Applications</h2>
            <p style="font-size:.78rem;color:var(--muted);margin:4px 0 0">
              <?php if ($saCollegeRole === 'hod'): ?>
                Review and action staff leave requests forwarded for HOD approval.
              <?php elseif ($saCollegeRole === 'principal'): ?>
                View all staff leave applications for your college (read-only).
              <?php else: ?>
                Monitor all leave applications across colleges.
              <?php endif; ?>
            </p>
          </div>
          <button class="btn-secondary btn-sm" onclick="loadLeaves()" style="align-self:flex-start">
            <i class="fas fa-rotate-right"></i> Refresh
          </button>
        </div>

        <!-- Stats bar -->
        <div class="exp-stat-bar" id="leaveStatBar">
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="leaveStatTotal" style="color:#0F766E">—</div>
            <div class="exp-stat-lbl">Total</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="leaveStatHodPending" style="color:#a78bfa">—</div>
            <div class="exp-stat-lbl">Awaiting HOD</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="leaveStatApproved" style="color:#10b981">—</div>
            <div class="exp-stat-lbl">Approved</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="leaveStatRejected" style="color:#ef4444">—</div>
            <div class="exp-stat-lbl">Rejected</div>
          </div>
          <div class="exp-stat-card">
            <div class="exp-stat-num" id="leaveStatPending" style="color:#f59e0b">—</div>
            <div class="exp-stat-lbl">Pending Admin</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-list"></i> Applications List</div>
          </div>
          <div class="panel-body">

            <!-- Filters -->
            <div class="exp-filter-bar">
              <?php
              $dbLeaveF = getDB();
              $leaveColleges = [];
              if ($dbLeaveF) {
                  if ($saCollegeId) {
                      $cs = $dbLeaveF->prepare('SELECT id, name FROM colleges WHERE id=? AND status="active" LIMIT 1');
                      $cs->execute([$saCollegeId]);
                      $leaveColleges = $cs->fetchAll(PDO::FETCH_ASSOC);
                  } else {
                      $leaveColleges = $dbLeaveF->query('SELECT id, name FROM colleges WHERE status="active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
                  }
              }
              ?>
              <?php if (!$saCollegeId): ?>
              <select id="leaveFilterCollege" onchange="loadLeaves()">
                <option value="">All Colleges</option>
                <?php foreach ($leaveColleges as $lc): ?>
                <option value="<?= (int)$lc['id'] ?>"><?= htmlspecialchars($lc['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="hidden" id="leaveFilterCollege" value="<?= (int)$saCollegeId ?>">
              <?php endif; ?>

              <select id="leaveFilterStatus" onchange="loadLeaves()">
                <option value="all">All Statuses</option>
                <?php if ($saCollegeRole === 'hod'): ?>
                  <option value="hod_pending">Awaiting HOD Approval</option>
                  <option value="pending">Pending (Direct)</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                <?php else: ?>
                  <option value="pending">Pending (Admin)</option>
                  <option value="hod_pending">Awaiting HOD</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                  <option value="cancelled">Cancelled</option>
                <?php endif; ?>
              </select>
            </div>

            <!-- Role banners -->
            <?php if ($saCollegeRole === 'hod'): ?>
            <div style="background:rgba(167,139,250,.07);border:1px solid rgba(167,139,250,.22);border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:.76rem;color:rgba(167,139,250,.9);display:flex;gap:9px;align-items:center">
              <i class="fas fa-user-tie" style="flex-shrink:0"></i>
              <span>
                <strong>HOD Queue</strong> — Applications forwarded by the college admin for your final approval.
                Click <strong>Approve</strong> to grant leave or <strong>Reject</strong> to decline.
              </span>
            </div>
            <?php elseif ($saCollegeRole === 'principal'): ?>
            <div style="background:rgba(20,184,166,.05);border:1px solid rgba(20,184,166,.18);border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:.76rem;color:rgba(20,184,166,.88);display:flex;gap:9px;align-items:center">
              <i class="fas fa-crown" style="flex-shrink:0"></i>
              <span>
                <strong>Principal View</strong> — You can view all leave applications for your college.
                Leave decisions are made by the Admin (stage 1) and HOD (stage 2).
              </span>
            </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="table-wrap" id="leaveTableWrap">
              <p style="color:var(--muted);font-size:.83rem;padding:8px 0">Loading leave applications…</p>
            </div>

          </div>
        </div>
      </div><!-- /view:leave-applications -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: hod-marks-viewer (Semester-wise Student Marks)  -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="hod-marks-viewer" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px;align-items:center">
          <div>
            <h2 class="view-title" style="margin-bottom:2px"><i class="fas fa-chart-bar"></i> Marks Viewer</h2>
            <span style="font-size:.78rem;color:var(--muted)">
              <?php if ($saCollegeRole === 'hod' && $saDeptName): ?>
                Semester-wise student marks — <strong style="color:var(--teal)"><?= htmlspecialchars($saDeptName) ?></strong> Department
              <?php else: ?>
                Semester &amp; subject-wise student marks by department
              <?php endif; ?>
            </span>
          </div>
          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="hmvExportCSV()" class="btn-secondary" style="padding:8px 14px;font-size:.82rem" title="Export to CSV"><i class="fas fa-download"></i> Export CSV</button>
            <button onclick="window.print()" class="btn-secondary" style="padding:8px 14px;font-size:.82rem" title="Print"><i class="fas fa-print"></i> Print</button>
          </div>
        </div>

        <!-- ── Filter Bar ─────────────────────────────────────── -->
        <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;align-items:end" id="hmv-filter-bar">

          <!-- College (locked for HOD) -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">College</label>
            <?php if ($saCollegeId): ?>
            <input type="text" value="<?= htmlspecialchars($saCollegeName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="hmv-college" value="<?= $saCollegeId ?>">
            <?php else: ?>
            <select id="hmv-college" onchange="hmvOnCollegeChange()"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select College —</option>
              <?php foreach ($all_colleges as $col): ?>
              <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>

          <!-- Department (locked for HOD, selectable for others) -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Department</label>
            <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
            <input type="text" value="<?= htmlspecialchars($saDeptName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="hmv-dept" value="<?= $saDeptId ?>">
            <?php else: ?>
            <select id="hmv-dept"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select Department —</option>
            </select>
            <?php endif; ?>
          </div>

          <!-- Semester -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Semester *</label>
            <select id="hmv-semester"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select Semester —</option>
              <?php for ($s=1;$s<=8;$s++): ?>
              <option value="<?= $s ?>">Semester <?= $s ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <!-- Academic Year -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Academic Year</label>
            <select id="hmv-acyear"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">All Years</option>
              <option value="2025-26" selected>2025-26</option>
              <option value="2024-25">2024-25</option>
              <option value="2023-24">2023-24</option>
            </select>
          </div>

          <!-- Load Button -->
          <div style="display:flex;gap:8px;align-items:flex-end">
            <button onclick="hmvLoad()" class="btn-primary" style="padding:9px 18px;flex:1"><i class="fas fa-search"></i> Load Marks</button>
          </div>
        </div>

        <!-- ── Context Banner (dept + semester info) ──────────── -->
        <div id="hmv-context-banner" style="display:none;background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.22);border-radius:var(--radius);padding:13px 20px;margin-bottom:16px;flex-wrap:wrap;gap:18px;align-items:center;font-size:.84rem"></div>

        <!-- ── Summary Stats ──────────────────────────────────── -->
        <div id="hmv-stats-row" style="display:none;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:14px;margin-bottom:20px">
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Students</div>
            <div id="hmvs-students" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:var(--teal);line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Subjects</div>
            <div id="hmvs-subjects" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#a78bfa;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Avg Total %</div>
            <div id="hmvs-avg" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#f4a261;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">All Pass</div>
            <div id="hmvs-pass" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#2dd4aa;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Has Fail</div>
            <div id="hmvs-fail" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#e76f51;line-height:1">—</div>
          </div>
        </div>

        <!-- ── Marks Table ─────────────────────────────────────── -->
        <div class="panel" id="hmv-panel">
          <div class="panel-header" style="flex-wrap:wrap;gap:10px">
            <div class="panel-title"><i class="fas fa-table-cells"></i> <span id="hmv-panel-title">Student Marks — Semester View</span></div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
              <input type="text" id="hmv-search" placeholder="Search student…"
                     oninput="hmvFilterRows()"
                     style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.82rem;padding:7px 12px;outline:none;width:180px">
              <select id="hmv-result-filter" onchange="hmvFilterRows()"
                      style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.82rem;padding:7px 12px;outline:none">
                <option value="">All Results</option>
                <option value="pass">All Pass</option>
                <option value="fail">Has Fail</option>
              </select>
            </div>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap" id="hmv-table-wrap">
              <!-- thead & tbody are built dynamically by hmvRenderSemesterTable() -->
              <table class="data-table" id="hmv-table" style="min-width:900px">
                <thead id="hmv-thead"></thead>
                <tbody id="hmv-tbody">
                  <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:50px 20px">
                    <i class="fas fa-chart-bar" style="font-size:2.4rem;opacity:.2;display:block;margin-bottom:12px"></i>
                    Select your department's semester above and click <strong style="color:var(--teal)">Load Marks</strong>
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div><!-- /view:hod-marks-viewer -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: hod-course-marks (Course-wise Exam Marks)       -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="hod-course-marks" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px;align-items:center">
          <div>
            <h2 class="view-title" style="margin-bottom:2px"><i class="fas fa-book-open"></i> Course-wise Marks</h2>
            <span style="font-size:.78rem;color:var(--muted)">
              View marks for every exam created under a specific course
            </span>
          </div>
          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="hcmExportCSV()" class="btn-secondary" style="padding:8px 14px;font-size:.82rem" title="Export to CSV"><i class="fas fa-download"></i> Export CSV</button>
            <button onclick="window.print()" class="btn-secondary" style="padding:8px 14px;font-size:.82rem" title="Print"><i class="fas fa-print"></i> Print</button>
          </div>
        </div>

        <!-- ── Filter Bar ──────────────────────────────────────── -->
        <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;align-items:end" id="hcm-filter-bar">

          <!-- College -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">College</label>
            <?php if ($saCollegeId): ?>
            <input type="text" value="<?= htmlspecialchars($saCollegeName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="hcm-college" value="<?= $saCollegeId ?>">
            <?php else: ?>
            <select id="hcm-college" onchange="hcmOnCollegeChange()"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select College —</option>
              <?php foreach ($all_colleges as $col): ?>
              <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>

          <!-- Department -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Department</label>
            <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
            <input type="text" value="<?= htmlspecialchars($saDeptName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="hcm-dept" value="<?= $saDeptId ?>">
            <?php else: ?>
            <select id="hcm-dept" onchange="hcmOnDeptChange()"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select Department —</option>
            </select>
            <?php endif; ?>
          </div>

          <!-- Course -->
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Course *</label>
            <select id="hcm-course"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">— Select Course —</option>
            </select>
          </div>

          <!-- Load Button -->
          <div style="display:flex;gap:8px;align-items:flex-end">
            <button onclick="hcmLoad()" class="btn-primary" style="padding:9px 18px;flex:1"><i class="fas fa-search"></i> Load Marks</button>
          </div>
        </div>

        <!-- ── Course Info Banner ─────────────────────────────── -->
        <div id="hcm-context-banner" style="display:none;background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.22);border-radius:var(--radius);padding:13px 20px;margin-bottom:16px;flex-wrap:wrap;gap:18px;align-items:center;font-size:.84rem"></div>

        <!-- ── Exam Type Legend ──────────────────────────────────── -->
        <div id="hcm-exam-badges" style="display:none;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center">
          <span style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-right:4px">Exams:</span>
        </div>

        <!-- ── Summary Stats ──────────────────────────────────── -->
        <div id="hcm-stats-row" style="display:none;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:14px;margin-bottom:20px">
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Students</div>
            <div id="hcms-students" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:var(--teal);line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Exams</div>
            <div id="hcms-exams" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#a78bfa;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Avg %</div>
            <div id="hcms-avg" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#f4a261;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">All Pass</div>
            <div id="hcms-pass" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#2dd4aa;line-height:1">—</div>
          </div>
          <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Has Fail</div>
            <div id="hcms-fail" style="font-family:var(--font-head);font-size:1.9rem;font-weight:800;color:#e76f51;line-height:1">—</div>
          </div>
        </div>

        <!-- ── Marks Table ─────────────────────────────────────── -->
        <div class="panel" id="hcm-panel">
          <div class="panel-header" style="flex-wrap:wrap;gap:10px">
            <div class="panel-title"><i class="fas fa-table-cells"></i> <span id="hcm-panel-title">Course Exam Marks</span></div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
              <input type="text" id="hcm-search" placeholder="Search student…"
                     oninput="hcmFilterRows()"
                     style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.82rem;padding:7px 12px;outline:none;width:180px">
              <select id="hcm-result-filter" onchange="hcmFilterRows()"
                      style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.82rem;padding:7px 12px;outline:none">
                <option value="">All Students</option>
                <option value="pass">All Pass</option>
                <option value="fail">Has Fail</option>
              </select>
            </div>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap" id="hcm-table-wrap">
              <table class="data-table" id="hcm-table" style="min-width:900px">
                <thead id="hcm-thead"></thead>
                <tbody id="hcm-tbody">
                  <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:50px 20px">
                    <i class="fas fa-book-open" style="font-size:2.4rem;opacity:.2;display:block;margin-bottom:12px"></i>
                    Select a course and click <strong style="color:var(--teal)">Load Marks</strong> to view all exam marks
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div><!-- /view:hod-course-marks -->

      <!-- ══════════════════════════════════════════════════════ -->
      <!-- VIEW: question-papers-viewer                          -->
      <!-- ══════════════════════════════════════════════════════ -->
      <div data-view="question-papers-viewer" style="display:none">
        <div class="view-header" style="flex-wrap:wrap;gap:10px">
          <h2 class="view-title"><i class="fas fa-file-lines"></i> Question Papers</h2>
          <span style="font-size:.78rem;color:var(--muted)">View all question papers across departments and colleges.</span>
        </div>

        <!-- Filters -->
        <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:var(--radius);padding:20px 24px;margin-bottom:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;align-items:end">
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">College</label>
            <?php if ($saCollegeId): ?>
            <input type="text" value="<?= htmlspecialchars($saCollegeName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="qpv-college" value="<?= $saCollegeId ?>">
            <?php else: ?>
            <select id="qpv-college" onchange="qpvCollegeChange()"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">All Colleges</option>
              <?php foreach ($all_colleges as $col): ?>
              <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Department</label>
            <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
            <input type="text" value="<?= htmlspecialchars($saDeptName) ?>" readonly
                   style="background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.3);color:var(--teal);font-weight:600;cursor:not-allowed;padding:9px 12px;border-radius:var(--radius-sm);font-size:.85rem;width:100%">
            <input type="hidden" id="qpv-dept" value="<?= $saDeptId ?>">
            <?php else: ?>
            <select id="qpv-dept"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">All Departments</option>
            </select>
            <?php endif; ?>
          </div>
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Status</label>
            <select id="qpv-status"
                    style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
              <option value="">All Statuses</option>
              <option value="draft">Draft</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="approved">Approved</option>
              <option value="released">Released</option>
            </select>
          </div>
          <div>
            <label style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:6px">Search</label>
            <input type="text" id="qpv-search" placeholder="Paper title…" oninput="qpvFilterLocal()"
                   style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:.85rem;padding:9px 12px;outline:none;width:100%">
          </div>
          <div style="display:flex;gap:8px;align-items:flex-end">
            <button onclick="qpvLoad()" class="btn-primary" style="padding:9px 18px;flex:1"><i class="fas fa-search"></i> Load</button>
            <button onclick="qpvReset()" class="btn-secondary" style="padding:9px 12px" title="Reset"><i class="fas fa-rotate-left"></i></button>
          </div>
        </div>

        <!-- Status Summary Pills -->
        <div id="qpv-status-pills" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px"></div>

        <!-- Papers Table -->
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><i class="fas fa-file-lines"></i> Question Papers</div>
            <span id="qpv-count-badge" style="font-size:.75rem;color:var(--muted)"></span>
          </div>
          <div class="panel-body panel-body-table">
            <div class="table-wrap">
              <table class="data-table" id="qpv-table" style="min-width:960px">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Paper Title</th>
                    <th>Exam</th>
                    <th>Course</th>
                    <th>Department</th>
                    <th>College</th>
                    <th>Total Marks</th>
                    <th>Questions</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="qpv-tbody">
                  <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:40px">
                    <i class="fas fa-file-lines" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>
                    Click <strong style="color:var(--teal)">Load</strong> to view question papers
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Q.Paper Detail Modal -->
        <div class="form-modal-overlay" id="qpv-detail-modal">
          <div class="form-modal" style="max-width:780px;max-height:90vh;display:flex;flex-direction:column">
            <div class="form-modal-header" style="flex-shrink:0">
              <div class="form-modal-title"><i class="fas fa-file-lines"></i> Question Paper Details</div>
              <button class="form-modal-close" onclick="closeModal('qpv-detail-modal')"><i class="fas fa-xmark"></i></button>
            </div>
            <div id="qpv-detail-body" style="padding:0 0 8px;overflow-y:auto;flex:1;min-height:0"></div>
            <div style="padding:14px 22px;border-top:1px solid var(--border2);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;flex-shrink:0">
              <div id="qpv-approve-btns" style="display:flex;gap:8px"></div>
              <button class="btn-secondary" onclick="closeModal('qpv-detail-modal')">Close</button>
            </div>
          </div>
        </div>
      </div><!-- /view:question-papers-viewer -->

      <!-- ══════════════════════════════════════════════════════════════════
           VIEW: college-profile
      ══════════════════════════════════════════════════════════════════════ -->
      <div data-view="college-profile" style="display:none">

        <!-- ── Page header ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
          <div>
            <h1 style="font-size:1.25rem;font-weight:800;color:var(--text);margin:0 0 2px">College Profile</h1>
            <p style="font-size:.78rem;color:var(--muted);margin:0">Manage your college's identity, contacts and information</p>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <span id="cpSaveStatus" style="font-size:.78rem;display:none;padding:5px 12px;border-radius:20px;font-weight:600"></span>
            <button type="button" class="btn-primary" id="cpSaveBtn" onclick="saveCollegeProfile()" style="padding:9px 22px;font-size:.82rem;display:flex;align-items:center;gap:7px">
              <i class="fas fa-floppy-disk"></i> Save Changes
            </button>
          </div>
        </div>

        <form id="cpForm" onsubmit="return false">
          <input type="hidden" id="cpCollegeId">

          <!-- ── TOP: Banner + Logo hero ── -->
          <div style="position:relative;margin-bottom:24px">

            <!-- Banner -->
            <div id="cpBannerHero" style="position:relative;width:100%;height:150px;border-radius:14px;overflow:hidden;background:linear-gradient(135deg,#0f2744 0%,#0d3d3a 60%,#14403b 100%);box-shadow:0 4px 20px rgba(0,0,0,.2)">
              <img id="cpBannerImg" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none">
              <div id="cpBannerPlaceholder" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;pointer-events:none">
                <i class="fas fa-panorama" style="font-size:1.8rem;color:rgba(255,255,255,.12)"></i>
                <span style="font-size:.7rem;color:rgba(255,255,255,.18);letter-spacing:.08em;text-transform:uppercase">Click "Change Banner" to upload • 1200×300 recommended</span>
              </div>
              <label for="cpBannerInput" style="position:absolute;top:10px;right:12px;background:rgba(0,0,0,.55);color:#fff;border-radius:8px;padding:6px 14px;cursor:pointer;font-size:.7rem;display:inline-flex;align-items:center;gap:6px;backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.15);transition:.2s" onmouseover="this.style.background='rgba(20,184,166,.8)'" onmouseout="this.style.background='rgba(0,0,0,.55)'">
                <i class="fas fa-camera"></i> Change Banner
              </label>
              <input type="file" id="cpBannerInput" accept="image/*" style="display:none">
              <!-- bottom fade -->
              <div style="position:absolute;bottom:0;left:0;right:0;height:60px;background:linear-gradient(transparent,rgba(0,0,0,.5));pointer-events:none"></div>
            </div>

            <!-- Logo + college name card — sits below banner, logo overlaps banner bottom -->
            <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 20px 16px 20px;display:flex;align-items:center;gap:18px;margin-top:0;box-shadow:0 2px 12px rgba(0,0,0,.1)">
              <!-- Logo box with camera overlay -->
              <div style="position:relative;flex-shrink:0;margin-top:-48px">
                <div id="cpLogoWrap" style="width:76px;height:76px;border-radius:14px;border:3px solid var(--card);background:var(--bg);box-shadow:0 4px 16px rgba(0,0,0,.25);overflow:hidden;display:flex;align-items:center;justify-content:center">
                  <img id="cpLogoImg" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none">
                  <i id="cpLogoPlaceholder" class="fas fa-university" style="font-size:1.9rem;color:var(--teal);opacity:.45"></i>
                </div>
                <label for="cpLogoInput" title="Upload Logo" style="position:absolute;bottom:-5px;right:-5px;width:22px;height:22px;background:var(--teal);color:#fff;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.58rem;border:2px solid var(--card);box-shadow:0 2px 6px rgba(0,0,0,.3);z-index:2">
                  <i class="fas fa-camera"></i>
                </label>
                <input type="file" id="cpLogoInput" accept="image/*" style="display:none">
              </div>
              <!-- Name / tagline -->
              <div style="min-width:0;flex:1">
                <h2 id="cpCollegeHeading" style="font-size:1.15rem;font-weight:800;color:var(--text);margin:0 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></h2>
                <p id="cpCollegeTagline" style="font-size:.76rem;color:var(--muted);margin:0;font-style:italic"></p>
              </div>
            </div>
          </div>

          <!-- ── TWO-COLUMN LAYOUT ── -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

            <!-- LEFT column -->
            <div style="display:flex;flex-direction:column;gap:24px">

              <!-- Basic Info -->
              <div class="panel">
                <div class="panel-header" style="margin-bottom:0">
                  <div class="panel-title"><i class="fas fa-building-columns" style="color:#38bdf8"></i> Basic Information</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:18px;padding:20px 22px 22px">
                  <div class="form-group" style="margin:0">
                    <label>College Name *</label>
                    <input type="text" id="cp_name" class="input-field" placeholder="Full official college name">
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Tagline / Motto</label>
                    <input type="text" id="cp_tagline" class="input-field" placeholder="e.g. Empowering Minds Since…">
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group" style="margin:0">
                      <label>College Code</label>
                      <input type="text" id="cp_code_display" class="input-field" disabled style="opacity:.5;cursor:not-allowed">
                    </div>
                    <div class="form-group" style="margin:0">
                      <label>Year Established</label>
                      <input type="number" id="cp_established" class="input-field" placeholder="e.g. 1998" min="1800" max="2099">
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group" style="margin:0">
                      <label>College Type</label>
                      <select id="cp_college_type" class="input-field">
                        <option value="">— Select —</option>
                        <option>Government</option>
                        <option>Aided</option>
                        <option>Private / Unaided</option>
                        <option>Autonomous</option>
                        <option>Deemed University</option>
                        <option>Central University</option>
                        <option>Open University</option>
                      </select>
                    </div>
                    <div class="form-group" style="margin:0">
                      <label>NAAC Grade</label>
                      <select id="cp_naac_grade" class="input-field">
                        <option value="">— Not Accredited —</option>
                        <option>A++</option><option>A+</option><option>A</option>
                        <option>B++</option><option>B+</option><option>B</option>
                        <option>C</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Affiliation / University</label>
                    <input type="text" id="cp_affiliation" class="input-field" placeholder="e.g. VTU, Anna University">
                  </div>
                </div>
              </div>

              <!-- Contact Details -->
              <div class="panel">
                <div class="panel-header" style="margin-bottom:0">
                  <div class="panel-title"><i class="fas fa-address-book" style="color:#a78bfa"></i> Contact Details</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:18px;padding:20px 22px 22px">
                  <div class="form-group" style="margin:0">
                    <label>Address</label>
                    <textarea id="cp_address" class="input-field" rows="2" placeholder="Full postal address" style="resize:vertical"></textarea>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group" style="margin:0">
                      <label>Phone</label>
                      <input type="text" id="cp_phone" class="input-field" placeholder="+91-xxx-xxxxxxx">
                    </div>
                    <div class="form-group" style="margin:0">
                      <label>Email</label>
                      <input type="email" id="cp_email" class="input-field" placeholder="contact@college.edu">
                    </div>
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Website</label>
                    <input type="url" id="cp_website" class="input-field" placeholder="https://college.edu">
                  </div>
                </div>
              </div>

            </div><!-- /left -->

            <!-- RIGHT column -->
            <div style="display:flex;flex-direction:column;gap:24px">

              <!-- Principal -->
              <div class="panel">
                <div class="panel-header" style="margin-bottom:0">
                  <div class="panel-title"><i class="fas fa-user-tie" style="color:#f59e0b"></i> Principal / Head of Institution</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:18px;padding:20px 22px 22px">
                  <div class="form-group" style="margin:0">
                    <label>Principal Name</label>
                    <input type="text" id="cp_principal_name" class="input-field" placeholder="Dr. Full Name">
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Principal Email</label>
                    <input type="email" id="cp_principal_email" class="input-field" placeholder="principal@college.edu">
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Principal Phone</label>
                    <input type="text" id="cp_principal_phone" class="input-field" placeholder="+91-xxx-xxxxxxx">
                  </div>
                </div>
              </div>

              <!-- Vision & Mission -->
              <div class="panel">
                <div class="panel-header" style="margin-bottom:0">
                  <div class="panel-title"><i class="fas fa-bullseye" style="color:#f472b6"></i> Vision & Mission</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:18px;padding:20px 22px 22px">
                  <div class="form-group" style="margin:0">
                    <label>Vision</label>
                    <textarea id="cp_vision" class="input-field" rows="3" placeholder="College's vision statement…" style="resize:vertical"></textarea>
                  </div>
                  <div class="form-group" style="margin:0">
                    <label>Mission</label>
                    <textarea id="cp_mission" class="input-field" rows="3" placeholder="College's mission statement…" style="resize:vertical"></textarea>
                  </div>
                </div>
              </div>

              <!-- Facilities -->
              <div class="panel">
                <div class="panel-header" style="margin-bottom:0">
                  <div class="panel-title"><i class="fas fa-landmark" style="color:#34d399"></i> Key Facilities</div>
                </div>
                <div style="padding:20px 22px 22px">
                <div class="form-group" style="margin:0">
                  <label style="font-size:.72rem;color:var(--muted);font-weight:400">Enter comma-separated list</label>
                  <textarea id="cp_facilities" class="input-field" rows="3" placeholder="e.g. Library, Hostel, Lab, Sports Complex, Wi-Fi Campus, Cafeteria" style="resize:vertical"></textarea>
                </div>
                <!-- Facility chips preview -->
                <div id="cpFacilityChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px"></div>
                </div><!-- /padding wrapper -->
              </div>

            </div><!-- /right -->
          </div><!-- /two-col -->

          <!-- Bottom save bar -->
          <div style="margin-top:20px;padding:14px 20px;background:var(--card);border-radius:12px;border:1px solid var(--border);display:flex;align-items:center;justify-content:flex-end;gap:12px">
            <span style="font-size:.78rem;color:var(--muted)">All changes are saved to the database immediately.</span>
            <button type="button" class="btn-primary" onclick="saveCollegeProfile()" style="padding:9px 24px;font-size:.82rem;display:flex;align-items:center;gap:7px">
              <i class="fas fa-floppy-disk"></i> Save Profile
            </button>
          </div>

        </form>

      </div><!-- /view:college-profile -->

    </div><!-- /content -->
  </main>
</div><!-- /app-shell -->


<!-- ─── Edit Exam Modal ─────────────────────────────────────────────────── -->
<div class="form-modal-overlay" id="modalEditExam">
  <div class="form-modal" style="max-width:560px">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-pencil"></i> Edit Exam</div>
      <button class="form-modal-close" onclick="closeModal('modalEditExam')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body">
      <input type="hidden" id="ee_id">
      <div class="form-grid">
        <div class="form-group full">
          <label>Exam Title *</label>
          <input type="text" id="ee_title" class="input-field" placeholder="Exam title">
        </div>
        <div class="form-group">
          <label>Type</label>
          <select id="ee_type" class="input-field">
            <option value="unit_test">Unit Test</option>
            <option value="mid_term">Mid Term</option>
            <option value="final">Final / End-Sem</option>
            <option value="practical">Practical</option>
            <option value="assignment">Assignment</option>
            <option value="viva">Viva</option>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select id="ee_status" class="input-field">
            <option value="draft">Draft</option>
            <option value="upcoming">Upcoming</option>
            <option value="ongoing">Ongoing</option>
            <option value="completed">Completed</option>
            <option value="published">Published</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div class="form-group">
          <label>Exam Date *</label>
          <input type="date" id="ee_date" class="input-field">
        </div>
        <div class="form-group">
          <label>Max Marks</label>
          <input type="number" id="ee_max" class="input-field" value="100" min="1">
        </div>
        <div class="form-group">
          <label>Pass Marks</label>
          <input type="number" id="ee_pass" class="input-field" value="40" min="1">
        </div>
        <div class="form-group full">
          <label>Remarks</label>
          <input type="text" id="ee_remarks" class="input-field" placeholder="Optional remarks">
        </div>
      </div>
      <div id="alertEditExam" class="form-alert"></div>
    </div>
    <div class="form-actions" style="padding:16px 22px;border-top:1px solid var(--border2);display:flex;justify-content:flex-end;gap:10px">
      <button class="btn-secondary" onclick="closeModal('modalEditExam')">Cancel</button>
      <button class="btn-primary" id="btnEditExam" onclick="submitEditExam()"><i class="fas fa-floppy-disk"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ─── Timetable Modals ────────────────────────────────────────────────────── -->

<!-- Slot Assign Modal -->
<div class="form-modal-overlay" id="tt-modal-slot">
  <div class="form-modal" style="max-width:520px">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-pencil-ruler"></i> Assign Slot</div>
      <button class="form-modal-close" onclick="closeModal('tt-modal-slot')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body">
      <div id="tt-slot-info" style="background:rgba(20,184,166,0.06);border:1px solid rgba(20,184,166,0.2);border-radius:8px;padding:10px 14px;font-size:0.82rem;margin-bottom:16px;color:var(--text)"></div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Subject / Course <span style="font-size:0.7rem;color:var(--muted)">(filtered by department)</span></label>
          <select id="tt-slot-course"><option value="">— Select Course —</option></select>
        </div>
        <div class="form-group full">
          <label>Faculty <span style="font-size:0.7rem;color:var(--muted)">(filtered by department)</span></label>
          <select id="tt-slot-faculty"><option value="">— Select Faculty —</option></select>
        </div>
        <div class="form-group full">
          <label>Room / Lab</label>
          <select id="tt-slot-room"><option value="">— Select Room —</option></select>
        </div>
      </div>
    </div>
    <div class="form-actions" style="padding:16px 22px;border-top:1px solid var(--border2)">
      <button class="btn-secondary" style="margin-right:auto;color:#ef4444;border-color:rgba(239,68,68,0.3)" onclick="ttClearSlot()"><i class="fas fa-eraser"></i> Clear Slot</button>
      <button class="btn-secondary" onclick="closeModal('tt-modal-slot')">Cancel</button>
      <button class="btn-primary"   onclick="ttSaveSlot()"><i class="fas fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>

<!-- Load/Create Timetable Modal -->
<div class="form-modal-overlay" id="tt-modal-create">
  <div class="form-modal" style="max-width:460px">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-calendar-plus"></i> Load or Create Timetable</div>
      <button class="form-modal-close" onclick="closeModal('tt-modal-create')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body">
      <div style="font-size:0.78rem;color:var(--muted);background:rgba(20,184,166,0.06);border:1px solid rgba(20,184,166,0.18);border-radius:8px;padding:10px 14px;margin-bottom:16px;">
        <i class="fas fa-circle-info" style="color:var(--teal)"></i>
        &nbsp;Creating for: <strong id="tt-create-scope-label" style="color:var(--text)">—</strong>
      </div>
      <div class="form-grid">
        <div class="form-group full">
          <label>Academic Year</label>
          <input id="tt-create-year" placeholder="e.g. 2025-26">
        </div>
      </div>
    </div>
    <div class="form-actions" style="padding:16px 22px;border-top:1px solid var(--border2)">
      <button class="btn-secondary" onclick="closeModal('tt-modal-create')">Cancel</button>
      <button class="btn-primary"   onclick="ttDoCreate()"><i class="fas fa-check"></i> Load / Create</button>
    </div>
  </div>
</div>

<!-- Clear All Confirm -->
<div class="form-modal-overlay" id="tt-modal-clear">
  <div class="form-modal" style="max-width:360px">
    <div class="form-modal-header">
      <div class="form-modal-title" style="color:#ef4444"><i class="fas fa-triangle-exclamation"></i> Confirm Clear</div>
      <button class="form-modal-close" onclick="closeModal('tt-modal-clear')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body">
      <p style="font-size:0.88rem;line-height:1.6;color:var(--text)">Clear <strong style="color:#ef4444">all slots</strong> in this timetable? This cannot be undone.</p>
    </div>
    <div class="form-actions" style="padding:16px 22px;border-top:1px solid var(--border2)">
      <button class="btn-secondary" onclick="closeModal('tt-modal-clear')">Cancel</button>
      <button class="btn-primary" style="background:linear-gradient(135deg,#ef4444,#dc2626)" onclick="ttDoClearAll()"><i class="fas fa-trash"></i> Clear All</button>
    </div>
  </div>
</div>

<!-- View Timetable (read-only) Modal -->
<div class="form-modal-overlay" id="tt-modal-view" style="align-items:flex-start;padding-top:32px">
  <div class="form-modal" style="max-width:920px;width:96vw;max-height:88vh;overflow-y:auto">
    <div class="form-modal-header" style="position:sticky;top:0;background:var(--card);z-index:2;border-radius:14px 14px 0 0">
      <div class="form-modal-title"><i class="fas fa-calendar-check" style="color:var(--teal)"></i> <span id="tt-view-title">Timetable</span></div>
      <button class="form-modal-close" onclick="closeModal('tt-modal-view')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body" style="padding:20px 22px">
      <!-- Info bar -->
      <div style="font-size:0.82rem;color:var(--muted);background:rgba(20,184,166,0.06);border:1px solid rgba(20,184,166,0.18);border-radius:8px;padding:10px 14px;margin-bottom:18px">
        <i class="fas fa-circle-info" style="color:var(--teal)"></i>
        &nbsp;<span id="tt-view-scope">—</span>
      </div>
      <!-- Grid rendered here -->
      <div id="tt-view-wrap">
        <div class="tt-empty"><i class="fas fa-spinner fa-spin"></i><p>Loading timetable…</p></div>
      </div>
      <div class="tt-legend" id="tt-view-legend" style="display:none;margin-top:12px"></div>
    </div>
    <div class="form-actions" style="padding:14px 22px;border-top:1px solid var(--border2);position:sticky;bottom:0;background:var(--card);z-index:2">
      <button class="btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <button class="btn-secondary" onclick="closeModal('tt-modal-view')" style="margin-left:auto">Close</button>
    </div>
  </div>
</div>

<!-- ─── CRUD Modals ────────────────────────────────────────────────────────── -->

<!-- Colleges JSON for JS -->
<script>
const COLLEGES_DATA = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']], $recent_colleges)) ?>;
</script>

<!-- ── Add College Modal ── -->
<div class="form-modal-overlay" id="modalCollege">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-building"></i> Add New College</div>
      <button class="form-modal-close" onclick="closeModal('modalCollege')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertCollege"></div>
    <div class="form-grid">
      <div class="form-group full"><label>College Name *</label><input type="text" id="c_name" placeholder="e.g. National Institute of Technology"></div>
      <div class="form-group"><label>College Code *</label><input type="text" id="c_code" placeholder="e.g. NIT001" style="text-transform:uppercase"></div>
      <div class="form-group"><label>Established Year</label><input type="number" id="c_established" placeholder="e.g. 2001" min="1800" max="2030"></div>
      <div class="form-group"><label>Phone</label><input type="text" id="c_phone" placeholder="+91-80-12345678"></div>
      <div class="form-group"><label>Email</label><input type="email" id="c_email" placeholder="admin@college.edu"></div>
      <div class="form-group"><label>Website</label><input type="text" id="c_website" placeholder="https://college.edu"></div>
      <div class="form-group full"><label>Address</label><textarea id="c_address" placeholder="Full address..."></textarea></div>
      <div class="form-group"><label>Status</label>
        <select id="c_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalCollege')">Cancel</button>
      <button class="btn-primary" id="btnAddCollege" onclick="submitCollege()"><i class="fas fa-plus"></i> Add College</button>
    </div>
  </div>
</div>

<!-- ── Add User Modal ── -->
<div class="form-modal-overlay" id="modalUser">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-user-plus"></i> Add New User</div>
      <button class="form-modal-close" onclick="closeModal('modalUser')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertUser"></div>
    <div class="form-grid">
      <div class="form-group full"><label>Full Name *</label><input type="text" id="u_name" placeholder="Dr. John Doe"></div>
      <div class="form-group"><label>Username *</label><input type="text" id="u_username" placeholder="johndoe"></div>
      <div class="form-group"><label>Email *</label><input type="email" id="u_email" placeholder="john@college.edu"></div>
      <div class="form-group"><label>Password *</label><input type="password" id="u_password" placeholder="Min 6 characters"></div>
      <div class="form-group"><label>Role *</label>
        <select id="u_role">
          <option value="college_admin">College Admin</option>
          <option value="faculty">Faculty</option>
          <option value="student">Student</option>
        </select>
      </div>
      <div class="form-group"><label>College</label>
        <select id="u_college" onchange="loadDepts('u_dept',this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($recent_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Department</label>
        <select id="u_dept"><option value="">— Select Department —</option></select>
      </div>
      <div class="form-group"><label>Phone</label><input type="text" id="u_phone" placeholder="+91-..."></div>
      <div class="form-group"><label>Designation</label><input type="text" id="u_designation" placeholder="e.g. Associate Professor"></div>
      <div class="form-group"><label>Status</label>
        <select id="u_status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="pending">Pending</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalUser')">Cancel</button>
      <button class="btn-primary" id="btnAddUser" onclick="submitUser()"><i class="fas fa-plus"></i> Add User</button>
    </div>
  </div>
</div>

<!-- ── Add Department Modal ── -->
<div class="form-modal-overlay" id="modalDept">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-sitemap"></i> Add New Department</div>
      <button class="form-modal-close" onclick="closeModal('modalDept')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertDept"></div>
    <div class="form-grid">
      <div class="form-group full"><label>Department Name *</label><input type="text" id="d_name" placeholder="e.g. Computer Science & Engineering"></div>
      <div class="form-group"><label>Code *</label><input type="text" id="d_code" placeholder="e.g. CSE" style="text-transform:uppercase"></div>
      <?php if ($saCollegeId): ?>
      <!-- Scoped: college is auto-set from session, show as read-only info -->
      <input type="hidden" id="d_college" value="<?= $saCollegeId ?>">
      <div class="form-group">
        <label>College</label>
        <div style="background:rgba(20,184,166,.07);border:1px solid rgba(20,184,166,.25);border-radius:8px;padding:10px 14px;font-size:.85rem;color:var(--teal);font-weight:600;display:flex;align-items:center;gap:8px">
          <i class="fas fa-building" style="opacity:.7;font-size:.8rem"></i>
          <?= htmlspecialchars($saCollegeName) ?>
          <span style="margin-left:auto;font-size:.68rem;color:var(--muted);font-weight:400;background:rgba(20,184,166,.12);padding:2px 8px;border-radius:4px">Auto-assigned</span>
        </div>
      </div>
      <?php else: ?>
      <!-- Super Admin: show full college selector -->
      <div class="form-group"><label>College *</label>
        <select id="d_college">
          <option value="">— Select College —</option>
          <?php foreach ($recent_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Head of Department</label><input type="text" id="d_hod" placeholder="Dr. Full Name"></div>
      <div class="form-group"><label>Status</label>
        <select id="d_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalDept')">Cancel</button>
      <button class="btn-primary" id="btnAddDept" onclick="submitDept()"><i class="fas fa-plus"></i> Add Department</button>
    </div>
  </div>
</div>

<!-- ── Add Course Modal ── -->
<div class="form-modal-overlay" id="modalCourse">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-book"></i> Add New Course</div>
      <button class="form-modal-close" onclick="closeModal('modalCourse')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertCourse"></div>
    <div class="form-grid">
      <div class="form-group full"><label>Course Name *</label><input type="text" id="co_name" placeholder="e.g. Data Structures & Algorithms"></div>
      <div class="form-group"><label>Course Code *</label><input type="text" id="co_code" placeholder="e.g. CSE301" style="text-transform:uppercase"></div>
      <?php if ($saCollegeId): ?>
      <input type="hidden" id="co_college" value="<?= $saCollegeId ?>">
      <div class="form-group">
        <label>College</label>
        <div style="background:rgba(20,184,166,.07);border:1px solid rgba(20,184,166,.25);border-radius:8px;padding:10px 14px;font-size:.85rem;color:var(--teal);font-weight:600;display:flex;align-items:center;gap:8px">
          <i class="fas fa-building" style="opacity:.7;font-size:.8rem"></i>
          <?= htmlspecialchars($saCollegeName) ?>
          <span style="margin-left:auto;font-size:.68rem;color:var(--muted);font-weight:400;background:rgba(20,184,166,.12);padding:2px 8px;border-radius:4px">Auto-assigned</span>
        </div>
      </div>
      <?php else: ?>
      <div class="form-group"><label>College *</label>
        <select id="co_college" onchange="loadDepts('co_dept',this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($recent_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Department *</label>
        <select id="co_dept"><option value="">— Select Dept —</option>
        <?php if ($saCollegeId): foreach ($all_departments as $dep): ?>
        <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
        <?php endforeach; endif; ?>
        </select>
      </div>
      <div class="form-group"><label>Credits</label><input type="number" id="co_credits" value="3" min="1" max="10" step="0.5"></div>
      <div class="form-group"><label>Semester</label>
        <select id="co_semester">
          <option value="">— Any —</option>
          <?php for ($i=1;$i<=8;$i++): ?><option value="<?=$i?>">Semester <?=$i?></option><?php endfor; ?>
        </select>
      </div>
      <div class="form-group full"><label>Description</label><textarea id="co_desc" placeholder="Brief course description..."></textarea></div>
      <div class="form-group"><label>Status</label>
        <select id="co_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalCourse')">Cancel</button>
      <button class="btn-primary" id="btnAddCourse" onclick="submitCourse()"><i class="fas fa-plus"></i> Add Course</button>
    </div>
  </div>
</div>

<!-- ── Add Student Modal ── -->
<div class="form-modal-overlay" id="modalStudent">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-user-graduate"></i> Add New Student</div>
      <button class="form-modal-close" onclick="closeModal('modalStudent')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertStudent"></div>
    <div class="form-grid">
      <div class="form-group full"><label>Full Name *</label><input type="text" id="st_name" placeholder="Student Full Name"></div>
      <div class="form-group"><label>Username *</label><input type="text" id="st_username" placeholder="student1"></div>
      <div class="form-group"><label>Email *</label><input type="email" id="st_email" placeholder="student@college.edu"></div>
      <div class="form-group"><label>Password *</label><input type="password" id="st_password" placeholder="Min 6 characters"></div>
      <div class="form-group"><label>Roll Number</label><input type="text" id="st_roll" placeholder="e.g. 2023CS001"></div>
      <div class="form-group"><label>Phone</label><input type="text" id="st_phone" placeholder="+91-..."></div>
      <?php if ($saCollegeId): ?>
      <input type="hidden" id="st_college" value="<?= $saCollegeId ?>">
      <div class="form-group">
        <label>College</label>
        <div style="background:rgba(20,184,166,.07);border:1px solid rgba(20,184,166,.25);border-radius:8px;padding:10px 14px;font-size:.85rem;color:var(--teal);font-weight:600;display:flex;align-items:center;gap:8px">
          <i class="fas fa-building" style="opacity:.7;font-size:.8rem"></i>
          <?= htmlspecialchars($saCollegeName) ?>
          <span style="margin-left:auto;font-size:.68rem;color:var(--muted);font-weight:400;background:rgba(20,184,166,.12);padding:2px 8px;border-radius:4px">Auto-assigned</span>
        </div>
      </div>
      <?php else: ?>
      <div class="form-group"><label>College *</label>
        <select id="st_college" onchange="loadDepts('st_dept',this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($recent_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Department</label>
        <select id="st_dept"><option value="">— Select Dept —</option>
        <?php if ($saCollegeId): foreach ($all_departments as $dep): ?>
        <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
        <?php endforeach; endif; ?>
        </select>
      </div>
      <div class="form-group"><label>Status</label>
        <select id="st_status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="pending">Pending</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalStudent')">Cancel</button>
      <button class="btn-primary" id="btnAddStudent" onclick="submitStudent()"><i class="fas fa-plus"></i> Add Student</button>
    </div>
  </div>
</div>

<!-- ── Add Faculty Modal ── -->
<div class="form-modal-overlay" id="modalFaculty">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-chalkboard-teacher"></i> Add New Faculty</div>
      <button class="form-modal-close" onclick="closeModal('modalFaculty')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertFaculty"></div>
    <div class="form-grid">
      <div class="form-group full"><label>Full Name *</label><input type="text" id="f_name" placeholder="Dr. Full Name"></div>
      <div class="form-group"><label>Username *</label><input type="text" id="f_username" placeholder="faculty1"></div>
      <div class="form-group"><label>Email *</label><input type="email" id="f_email" placeholder="faculty@college.edu"></div>
      <div class="form-group"><label>Password *</label><input type="password" id="f_password" placeholder="Min 6 characters"></div>
      <div class="form-group"><label>Designation</label><input type="text" id="f_designation" placeholder="e.g. Associate Professor"></div>
      <div class="form-group"><label>Phone</label><input type="text" id="f_phone" placeholder="+91-..."></div>
      <?php if ($saCollegeId): ?>
      <input type="hidden" id="f_college" value="<?= $saCollegeId ?>">
      <div class="form-group">
        <label>College</label>
        <div style="background:rgba(20,184,166,.07);border:1px solid rgba(20,184,166,.25);border-radius:8px;padding:10px 14px;font-size:.85rem;color:var(--teal);font-weight:600;display:flex;align-items:center;gap:8px">
          <i class="fas fa-building" style="opacity:.7;font-size:.8rem"></i>
          <?= htmlspecialchars($saCollegeName) ?>
          <span style="margin-left:auto;font-size:.68rem;color:var(--muted);font-weight:400;background:rgba(20,184,166,.12);padding:2px 8px;border-radius:4px">Auto-assigned</span>
        </div>
      </div>
      <?php else: ?>
      <div class="form-group"><label>College *</label>
        <select id="f_college" onchange="loadDepts('f_dept',this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($recent_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Department</label>
        <select id="f_dept"><option value="">— Select Dept —</option>
        <?php if ($saCollegeId): foreach ($all_departments as $dep): ?>
        <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
        <?php endforeach; endif; ?>
        </select>
      </div>
      <div class="form-group"><label>Status</label>
        <select id="f_status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="pending">Pending</option></select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalFaculty')">Cancel</button>
      <button class="btn-primary" id="btnAddFaculty" onclick="submitFaculty()"><i class="fas fa-plus"></i> Add Faculty</button>
    </div>
  </div>
</div>

<!-- ─── Faculty Assignment Modal ───────────────────────────────────────────── -->
<div class="form-modal-overlay" id="modalFacultyAssignment">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-user-check"></i> Assign Faculty to Course</div>
      <button class="form-modal-close" onclick="closeModal('modalFacultyAssignment')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertFacultyAssignment"></div>
    <?php if ($saCollegeRole === 'hod'): ?>
    <div style="font-size:0.78rem;color:var(--muted);padding:0 0 12px">
      <i class="fas fa-info-circle" style="color:var(--teal)"></i>
      Courses are filtered to your department. If a course is missing, use <strong style="color:#6366f1">Create Course</strong> first.
    </div>
    <?php endif; ?>
    <div class="form-grid">
      <div class="form-group full">
        <label>College *</label>
        <?php if ($saCollegeId): ?>
        <!-- Scoped mode: college locked, show read-only display + hidden input -->
        <input type="hidden" id="fa_college" value="<?= $saCollegeId ?>">
        <div class="input-field" style="cursor:default;opacity:0.7;display:flex;align-items:center;gap:8px">
          <i class="fas fa-lock" style="color:var(--teal);font-size:0.75rem"></i>
          <?= htmlspecialchars($saCollegeName) ?>
        </div>
        <?php else: ?>
        <select id="fa_college" class="input-field" onchange="onCollegeChange(this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($all_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Department *</label>
        <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
        <!-- HOD: department locked to their own dept -->
        <input type="hidden" id="fa_dept" value="<?= $saDeptId ?>">
        <div class="input-field" style="cursor:default;opacity:0.7;display:flex;align-items:center;gap:8px">
          <i class="fas fa-lock" style="color:var(--teal);font-size:0.75rem"></i>
          <?= htmlspecialchars($saDeptName) ?>
        </div>
        <?php else: ?>
        <select id="fa_dept" class="input-field" onchange="loadCoursesForDept(this.value)">
          <option value="">— Select Department —</option>
        </select>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Faculty *</label>
        <select id="fa_faculty" class="input-field">
          <option value="">— Select Faculty —</option>
        </select>
      </div>
      <div class="form-group">
        <label>Academic Year</label>
        <input type="text" id="fa_academic_year" class="input-field" placeholder="e.g. 2025-26" value="2025-26"
               onchange="<?= $saCollegeRole==='hod' ? 'loadCoursesForDept(document.getElementById(\'fa_dept\').value)' : '' ?>">
      </div>
      <div class="form-group full">
        <label>Course / Subject *</label>
        <select id="fa_course" class="input-field" onchange="updateSemesterFromCourse(this)">
          <option value="">— Select Course —</option>
        </select>
      </div>
      <!-- Semester is auto-filled from the selected course — no visible field needed -->
      <input type="hidden" id="fa_semester" value="">
      <div class="form-group">
        <label>Period</label>
        <select id="fa_period" class="input-field">
          <option value="All Periods (Daily)">All Periods (Daily)</option>
          <option value="P1">Period 1</option>
          <option value="P2">Period 2</option>
          <option value="P3">Period 3</option>
          <option value="P4">Period 4</option>
          <option value="P5">Period 5</option>
          <option value="P6">Period 6</option>
        </select>
      </div>
      <div class="form-group">
        <label>Primary Teacher</label>
        <select id="fa_is_primary" class="input-field">
          <option value="1">Yes - Primary</option>
          <option value="0">No - Assistant</option>
        </select>
      </div>
      <div class="form-group full">
        <label>Remarks</label>
        <textarea id="fa_remarks" class="input-field" rows="2" placeholder="Optional notes..."></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalFacultyAssignment')">Cancel</button>
      <button class="btn-primary" id="btnAddFacultyAssignment" onclick="submitFacultyAssignment()">
        <i class="fas fa-check"></i> Assign Faculty
      </button>
    </div>
  </div>
</div>

<!-- ─── HOD: Create Course / Subject Modal ───────────────────────────────── -->
<div class="form-modal-overlay" id="modalCreateCourseHOD">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-book-open" style="color:#6366f1"></i> Create Course / Subject</div>
      <button class="form-modal-close" onclick="closeModal('modalCreateCourseHOD')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertCreateCourseHOD"></div>
    <div style="font-size:0.78rem;color:var(--muted);padding:0 0 12px;margin-bottom:4px">
      <i class="fas fa-info-circle" style="color:#6366f1"></i>
      Create a new course/subject for your department. After creating, use <strong>Assign Faculty</strong> to assign a teacher to it.
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Course Name *</label>
        <input type="text" id="hcc_name" class="input-field" placeholder="e.g. Data Structures & Algorithms">
      </div>
      <div class="form-group">
        <label>Course Code *</label>
        <input type="text" id="hcc_code" class="input-field" placeholder="e.g. CSE301"
               oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="form-group">
        <label>Semester * <span style="color:var(--muted);font-weight:400">(1–12)</span></label>
        <input type="number" id="hcc_semester" class="input-field" placeholder="e.g. 3" min="1" max="12">
      </div>
      <div class="form-group">
        <label>Academic Year *</label>
        <input type="text" id="hcc_academic_year" class="input-field" placeholder="e.g. 2025-26" value="2025-26">
      </div>
      <div class="form-group">
        <label>Credits</label>
        <input type="number" id="hcc_credits" class="input-field" placeholder="3" min="1" max="10" step="0.5" value="3">
      </div>
      <div class="form-group full">
        <label>Description <span style="color:var(--muted);font-weight:400">(optional)</span></label>
        <textarea id="hcc_desc" class="input-field" rows="2" placeholder="Brief course description..."></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalCreateCourseHOD')">Cancel</button>
      <button class="btn-primary" id="btnCreateCourseHOD" onclick="submitCreateCourseHOD()" style="background:#6366f1">
        <i class="fas fa-plus"></i> Create Course
      </button>
    </div>
  </div>
</div>

<!-- ─── Edit Faculty Assignment Modal ────────────────────────────────────── -->
<div class="form-modal-overlay" id="modalEditAssignment">
  <div class="form-modal">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-edit"></i> Edit Faculty Assignment</div>
      <button class="form-modal-close" onclick="closeModal('modalEditAssignment')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertEditAssignment"></div>
    <input type="hidden" id="ea_id">
    <div class="form-grid">
      <div class="form-group full">
        <label>Faculty *</label>
        <select id="ea_faculty" class="input-field">
          <option value="">— Select Faculty —</option>
          <?php foreach ($all_faculty as $fac): ?>
          <option value="<?= $fac['id'] ?>"><?= htmlspecialchars($fac['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group full">
        <label>College *</label>
        <select id="ea_college" class="input-field" onchange="onEditCollegeChange(this.value)">
          <option value="">— Select College —</option>
          <?php foreach ($all_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Department *</label>
        <select id="ea_dept" class="input-field" onchange="loadEditCoursesForDept(this.value)">
          <option value="">— Select Department —</option>
        </select>
      </div>
      <div class="form-group">
        <label>Course / Subject *</label>
        <select id="ea_course" class="input-field" onchange="updateEditSemesterFromCourse(this)">
          <option value="">— Select Course —</option>
        </select>
      </div>
      <!-- Semester auto-filled from selected course -->
      <input type="hidden" id="ea_semester" value="">
      <div class="form-group">
        <label>Academic Year</label>
        <input type="text" id="ea_academic_year" class="input-field" placeholder="e.g. 2025-26">
      </div>
      <div class="form-group">
        <label>Period</label>
        <select id="ea_period" class="input-field">
          <option value="All Periods (Daily)">All Periods (Daily)</option>
          <option value="P1">Period 1</option>
          <option value="P2">Period 2</option>
          <option value="P3">Period 3</option>
          <option value="P4">Period 4</option>
          <option value="P5">Period 5</option>
          <option value="P6">Period 6</option>
        </select>
      </div>
      <div class="form-group">
        <label>Primary Teacher</label>
        <select id="ea_is_primary" class="input-field">
          <option value="1">Yes - Primary</option>
          <option value="0">No - Assistant</option>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="ea_status" class="input-field">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="form-group full">
        <label>Remarks</label>
        <textarea id="ea_remarks" class="input-field" rows="2" placeholder="Optional notes..."></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalEditAssignment')">Cancel</button>
      <button class="btn-primary" id="btnEditAssignment" onclick="submitEditAssignment()">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- ─── Create Exam Modal ──────────────────────────────────────────────────── -->
<div class="form-modal-overlay" id="modalCreateExam">
  <div class="form-modal" style="max-width:560px">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-file-invoice"></i> Create New Exam</div>
      <button class="form-modal-close" onclick="closeModal('modalCreateExam')"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-alert" id="alertCreateExam"></div>
    <div class="form-grid">
      <div class="form-group full">
        <label>Exam Title *</label>
        <input type="text" id="ce_title" placeholder="e.g. Mid-Term Examination — May 2026">
      </div>
      <div class="form-group">
        <label>College *</label>
        <select id="ce_college" onchange="ceCollegeChange()">
          <option value="">— Select College —</option>
          <?php foreach ($all_colleges as $col): ?>
          <option value="<?= $col['id'] ?>"><?= htmlspecialchars($col['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Department *</label>
        <select id="ce_dept" onchange="ceDeptChange()">
          <option value="">— Select Dept —</option>
        </select>
      </div>
      <div class="form-group">
        <label>Course / Subject *</label>
        <select id="ce_course" onchange="ceCourseChange(this)">
          <option value="">— Select Course —</option>
        </select>
      </div>
      <div class="form-group">
        <label>Exam Date *</label>
        <input type="date" id="ce_date">
      </div>
      <div class="form-group">
        <label>Exam Type</label>
        <select id="ce_type">
          <option value="mid_term">Mid Term</option>
          <option value="unit_test">Unit Test</option>
          <option value="final">Final / End-Sem</option>
          <option value="practical">Practical</option>
          <option value="assignment">Assignment</option>
          <option value="viva">Viva</option>
        </select>
      </div>
      <div class="form-group">
        <label>Max Marks</label>
        <input type="number" id="ce_max" value="100" min="1">
      </div>
      <div class="form-group">
        <label>Pass Marks</label>
        <input type="number" id="ce_pass" value="40" min="1">
      </div>
      <div class="form-group">
        <label>Semester</label>
        <input type="number" id="ce_sem" value="1" min="1" max="8">
      </div>
      <div class="form-group">
        <label>Academic Year</label>
        <input type="text" id="ce_year" value="2025-26" placeholder="e.g. 2025-26">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal('modalCreateExam')">Cancel</button>
      <button class="btn-primary" id="btnCreateExam" onclick="submitCreateExam()">
        <i class="fas fa-plus"></i> Create Exam
      </button>
    </div>
  </div>
</div>

<!-- ─── Logout Modal ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="logoutModal">
  <div class="modal-box">
    <div class="modal-icon"><i class="fas fa-right-from-bracket"></i></div>
    <h3>Sign Out?</h3>
    <p>You're currently signed in as <strong style="color:var(--text)"><?= $adminName ?></strong>. Are you sure you want to sign out?</p>
    <div class="modal-btns">
      <button class="btn-cancel" onclick="document.getElementById('logoutModal').classList.remove('open')">Cancel</button>
      <button class="btn-logout" onclick="doLogout()">Sign Out</button>
    </div>
  </div>
</div>

<script>
// PHP → JS college context
window._currentCollegeId = <?= (int)$saCollegeId ?>;

// ── Live clock ───────────────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', {
    hour: '2-digit', minute: '2-digit', second: '2-digit'
  });
}
updateClock(); setInterval(updateClock, 1000);

// ── Counter animation ────────────────────────────────────────────────────────
document.querySelectorAll('.stat-value[data-target]').forEach(el => {
  const target = parseInt(el.dataset.target);
  let cur = 0;
  const step = Math.max(1, Math.ceil(target / 30));
  const t = setInterval(() => {
    cur = Math.min(cur + step, target);
    el.textContent = cur;
    if (cur >= target) clearInterval(t);
  }, 40);
});

// ── Health bar animation ─────────────────────────────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.health-bar[data-width]').forEach(bar => {
    bar.style.width = bar.dataset.width;
  });
}, 400);

// ── Logout ───────────────────────────────────────────────────────────────────
async function doLogout() {
  const endpoints = ['../auth/auth_handler.php', '../../auth/auth_handler.php', 'auth_handler.php'];
  let done = false;
  for (const url of endpoints) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=logout',
        signal: AbortSignal.timeout(3000),
      });
      if (res.ok) {
        const data = await res.json();
        window.location.href = data.redirect ?? '../auth/login.php';
        done = true; break;
      }
    } catch {}
  }
  if (!done) window.location.href = '../auth/login.php';
}

// ── Close modal on overlay click ─────────────────────────────────────────────
document.getElementById('logoutModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});

// ── View switching ────────────────────────────────────────────────────────────
function switchView(viewName) {
  // Hide all content view panels (direct children of .content with data-view)
  document.querySelectorAll('.content > [data-view]').forEach(el => {
    el.style.display = 'none';
  });

  // Show the selected view
  const target = document.querySelector('.content > [data-view="' + viewName + '"]');
  if (target) target.style.display = 'block';

  // Update active state on sidebar nav links
  document.querySelectorAll('.sidebar .nav-link[data-view]').forEach(l => {
    l.classList.remove('active');
  });
  document.querySelectorAll('.sidebar .nav-link[data-view="' + viewName + '"]').forEach(l => {
    l.classList.add('active');
  });

  // Load faculty assignments when that view is shown
  if (viewName === 'faculty-assignments') {
    loadFacultyAssignments();
  }
  // Load academic details when that view is shown
  if (viewName === 'academic-details') {
    loadAcademicDetails();
  }
  // Load college profile when that view is shown
  if (viewName === 'college-profile') {
    loadCollegeProfile();
  }
  // Load analytics when that view is shown
  if (viewName === 'analytics') {
    loadAnalytics();
  }

  // Scroll content to top
  const contentEl = document.querySelector('.content');
  if (contentEl) contentEl.scrollTop = 0;
}

// Sidebar nav links
document.querySelectorAll('.sidebar .nav-link[data-view]').forEach(link => {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    switchView(this.getAttribute('data-view'));
  });
});

// "View All →" inline links inside dashboard panels
document.querySelectorAll('.nav-link-inline[data-view]').forEach(link => {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    switchView(this.getAttribute('data-view'));
  });
});

// ── CRUD helpers ─────────────────────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  el.classList.add('open');
  // Reset submit button state in case it was left disabled from a previous submission
  // Auto-load departments for scoped modals (college already fixed in session)
  if ((id==='modalCourse'||id==='modalFaculty'||id==='modalStudent') && <?= $saCollegeId ? 'true' : 'false' ?>) {
    const targets = {modalCourse:'co_dept', modalFaculty:'f_dept', modalStudent:'st_dept'};
    loadDepts(targets[id], '<?= $saCollegeId ?>');
  }
  if (id === 'modalFacultyAssignment') {
    const btn = document.getElementById('btnAddFacultyAssignment');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Assign Faculty'; }
    // HOD: dept is locked — auto-load courses and faculty immediately
    <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
    loadCoursesForDept(<?= $saDeptId ?>);
    loadFacultyForCollege(<?= $saCollegeId ?>);
    <?php endif; ?>
  }
  if (id === 'modalEditAssignment') {
    const btn = document.getElementById('btnEditAssignment');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
  }
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  // clear alerts
  const a = document.querySelector('#'+id+' .form-alert');
  if (a) { a.className = 'form-alert'; a.textContent=''; }
}
// close on overlay click
document.querySelectorAll('.form-modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => { if (e.target===ov) closeModal(ov.id); });
});

function showAlert(id, msg, type='error') {
  const a = document.getElementById(id);
  a.className = 'form-alert ' + type;
  a.textContent = msg;
}

async function postAction(data) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) fd.append(k,v);
  const res = await fetch(window.location.href, { method:'POST', body: fd });
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch(e) {
    // Return a structured error so callers can check r.ok === false
    console.error('postAction: non-JSON response for', data.ajax_action, text.substring(0,300));
    return { ok: false, msg: 'Server returned invalid response. Check PHP error logs.' };
  }
}

// ── Load departments dynamically ──────────────────────────────────────────────
async function loadDepts(selectId, collegeId) {
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option value="">— Loading... —</option>';
  if (!collegeId) { sel.innerHTML = '<option value="">— Select Dept —</option>'; return; }
  const r = await postAction({ ajax_action:'get_departments', college_id:collegeId });
  if (r.ok) {
    sel.innerHTML = '<option value="">— Select Department —</option>' +
      r.departments.map(d=>`<option value="${d.id}">${d.name} (${d.code})</option>`).join('');
  }
}

// College change handler — sequential async so dept+faculty load in order
async function onCollegeChange(collegeId) {
  document.getElementById('fa_dept').innerHTML    = '<option value="">— Loading... —</option>';
  document.getElementById('fa_course').innerHTML  = '<option value="">— Select Course —</option>';
  document.getElementById('fa_faculty').innerHTML = '<option value="">— Loading... —</option>';
  // Clear any stale alert
  const alertEl = document.getElementById('alertFacultyAssignment');
  if (alertEl) { alertEl.className = 'form-alert'; alertEl.textContent = ''; }
  if (!collegeId) {
    document.getElementById('fa_dept').innerHTML    = '<option value="">— Select Department —</option>';
    document.getElementById('fa_faculty').innerHTML = '<option value="">— Select Faculty —</option>';
    return;
  }
  await Promise.all([
    loadDepts('fa_dept', collegeId),
    loadFacultyForCollege(collegeId)
  ]);
}

// ── Status Toggle ─────────────────────────────────────────────────────────────
async function toggleStatus(btn, type) {
  const id = btn.dataset.id;
  const curStatus = btn.dataset.status;
  const newStatus = curStatus === 'active' ? 'inactive' : 'active';

  let action;
  if (type==='college') action='update_college_status';
  else if (type==='user') action='update_user_status';
  else if (type==='dept') action='update_dept_status';
  else if (type==='course') action='update_course_status';
  else return;

  btn.disabled = true;
  btn.textContent = '...';
  const r = await postAction({ ajax_action:action, id, status:newStatus });
  if (r.ok) {
    btn.dataset.status = newStatus;
    btn.className = 'status-toggle ' + newStatus;
    btn.textContent = newStatus.toUpperCase();
  } else {
    btn.className = 'status-toggle ' + curStatus;
    btn.textContent = curStatus.toUpperCase();
    alert('Error: ' + r.msg);
  }
  btn.disabled = false;
}

// ── Add College ───────────────────────────────────────────────────────────────
async function submitCollege() {
  const btn = document.getElementById('btnAddCollege');
  const name=document.getElementById('c_name').value.trim();
  const code=document.getElementById('c_code').value.trim();
  if (!name||!code) { showAlert('alertCollege','Name and Code are required'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_college', name, code,
    phone: document.getElementById('c_phone').value,
    email: document.getElementById('c_email').value,
    website: document.getElementById('c_website').value,
    address: document.getElementById('c_address').value,
    established: document.getElementById('c_established').value,
    status: document.getElementById('c_status').value
  });
  if (r.ok) {
    showAlert('alertCollege', '✓ College added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertCollege', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add College';
  }
}

// ── Add User ──────────────────────────────────────────────────────────────────
async function submitUser() {
  const btn = document.getElementById('btnAddUser');
  const name=document.getElementById('u_name').value.trim();
  const username=document.getElementById('u_username').value.trim();
  const email=document.getElementById('u_email').value.trim();
  const password=document.getElementById('u_password').value;
  if (!name||!username||!email||!password) { showAlert('alertUser','Name, username, email and password are required'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_user', full_name:name, username, email, password,
    role: document.getElementById('u_role').value,
    college_id: document.getElementById('u_college').value,
    department_id: document.getElementById('u_dept').value,
    phone: document.getElementById('u_phone').value,
    designation: document.getElementById('u_designation').value,
    status: document.getElementById('u_status').value
  });
  if (r.ok) {
    showAlert('alertUser', '✓ User added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertUser', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add User';
  }
}

// ── Add Department ────────────────────────────────────────────────────────────
async function submitDept() {
  const btn = document.getElementById('btnAddDept');
  const name=document.getElementById('d_name').value.trim();
  const code=document.getElementById('d_code').value.trim();
  const college_id=document.getElementById('d_college').value;
  if (!name||!code||!college_id) { showAlert('alertDept','Name, code and college are required'); return; }
  // college_id is either auto-set (hidden input) or chosen from select — always present
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_department', name, code, college_id,
    hod_name: document.getElementById('d_hod').value,
    status: document.getElementById('d_status').value
  });
  if (r.ok) {
    showAlert('alertDept', '✓ Department added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertDept', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add Department';
  }
}

// ── Add Course ────────────────────────────────────────────────────────────────
async function submitCourse() {
  const btn = document.getElementById('btnAddCourse');
  const name=document.getElementById('co_name').value.trim();
  const code=document.getElementById('co_code').value.trim();
  const college_id=document.getElementById('co_college').value;
  const dept_id=document.getElementById('co_dept').value;
  if (!name||!code||!college_id||!dept_id) { showAlert('alertCourse','Name, code and department are required'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_course', name, code, college_id, department_id:dept_id,
    credits: document.getElementById('co_credits').value,
    semester: document.getElementById('co_semester').value,
    description: document.getElementById('co_desc').value,
    status: document.getElementById('co_status').value
  });
  if (r.ok) {
    showAlert('alertCourse', '✓ Course added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertCourse', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add Course';
  }
}

// ── Add Student ───────────────────────────────────────────────────────────────
async function submitStudent() {
  const btn = document.getElementById('btnAddStudent');
  const name=document.getElementById('st_name').value.trim();
  const username=document.getElementById('st_username').value.trim();
  const email=document.getElementById('st_email').value.trim();
  const password=document.getElementById('st_password').value;
  if (!name||!username||!email||!password) { showAlert('alertStudent','Name, username, email and password are required'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_student', full_name:name, username, email, password,
    roll_number: document.getElementById('st_roll').value,
    phone: document.getElementById('st_phone').value,
    college_id: document.getElementById('st_college').value,
    department_id: document.getElementById('st_dept').value,
    status: document.getElementById('st_status').value
  });
  if (r.ok) {
    showAlert('alertStudent', '✓ Student added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertStudent', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add Student';
  }
}

// ── Add Faculty ───────────────────────────────────────────────────────────────
async function submitFaculty() {
  const btn = document.getElementById('btnAddFaculty');
  const name=document.getElementById('f_name').value.trim();
  const username=document.getElementById('f_username').value.trim();
  const email=document.getElementById('f_email').value.trim();
  const password=document.getElementById('f_password').value;
  if (!name||!username||!email||!password) { showAlert('alertFaculty','Name, username, email and password are required'); return; }
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  const r = await postAction({
    ajax_action:'add_faculty', full_name:name, username, email, password,
    designation: document.getElementById('f_designation').value,
    phone: document.getElementById('f_phone').value,
    college_id: document.getElementById('f_college').value,
    department_id: document.getElementById('f_dept').value,
    status: document.getElementById('f_status').value
  });
  if (r.ok) {
    showAlert('alertFaculty', '✓ Faculty added! Refreshing...', 'success');
    setTimeout(()=>location.reload(), 1200);
  } else {
    showAlert('alertFaculty', r.msg);
    btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add Faculty';
  }
}

// ── Faculty Assignment Functions ──────────────────────────────────────────────

// Quick-assign: pre-fill modal from the All Faculty table or Assignments view
async function quickAssignFaculty(facultyId, facultyName, collegeId, deptId) {
  openModal('modalFacultyAssignment');

  // Reset dependent dropdowns
  document.getElementById('fa_dept').innerHTML    = '<option value="">— Select Department —</option>';
  document.getElementById('fa_course').innerHTML  = '<option value="">— Select Course —</option>';
  document.getElementById('fa_faculty').innerHTML = '<option value="">— Select Faculty —</option>';
  document.getElementById('fa_semester').value    = '';

  // Resolve the effective college: locked (scoped) or passed in
  const colEl       = document.getElementById('fa_college');
  const lockedColId = (colEl && colEl.tagName === 'INPUT') ? parseInt(colEl.value, 10) : 0;
  const effectiveCollegeId = lockedColId || collegeId || 0;

  // Step 1: Set college dropdown value (only for full <select> — not the locked hidden input)
  if (colEl && colEl.tagName === 'SELECT') {
    colEl.value = effectiveCollegeId ? String(effectiveCollegeId) : '';
  }

  // Step 2: Load departments AND faculty for the resolved college
  if (effectiveCollegeId > 0) {
    await Promise.all([
      loadDepts('fa_dept', effectiveCollegeId),
      loadFacultyForCollege(effectiveCollegeId)
    ]);
  }

  // Step 3: Pre-select the faculty (after faculty list is loaded)
  const facSelect = document.getElementById('fa_faculty');
  facSelect.value = String(facultyId);
  if (!facSelect.value || facSelect.value !== String(facultyId)) {
    // Faculty not in list (e.g. college_id was NULL) — append manually
    const opt = document.createElement('option');
    opt.value = facultyId;
    opt.textContent = facultyName;
    opt.selected = true;
    facSelect.appendChild(opt);
  }

  // Step 4: Pre-select department, then load its courses
  const effectiveDeptId = deptId || 0;
  if (effectiveDeptId > 0) {
    const deptSelect = document.getElementById('fa_dept');
    deptSelect.value = String(effectiveDeptId);
    // Explicitly call — setting .value does NOT fire onchange
    await loadCoursesForDept(effectiveDeptId);
  }

  // Show helper message
  showAlert('alertFacultyAssignment', `Assigning for: ${facultyName} — select Course below`, 'success');
}

// Clear assignment filters
function clearAssignmentFilters() {
  // filter-college may be a hidden locked input (scoped mode) — only reset a SELECT
  const colEl = document.getElementById('filter-college');
  if (colEl && colEl.tagName === 'SELECT') colEl.value = '';
  const deptEl = document.getElementById('filter-department');
  if (deptEl) deptEl.value = '';
  const facEl = document.getElementById('filter-faculty');
  if (facEl) facEl.value = '';
  loadFacultyAssignments();
}

// Load courses for selected department
async function loadCoursesForDept(deptId) {
  const courseSelect = document.getElementById('fa_course');
  courseSelect.innerHTML = '<option value="">— Select Course —</option>';
  if (!deptId) return;
  const yearEl = document.getElementById('fa_academic_year');
  const acad_year = yearEl ? yearEl.value.trim() : '';
  const r = await postAction({ ajax_action: 'get_courses', department_id: deptId, academic_year: acad_year });
  if (r.ok && r.courses) {
    r.courses.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      const yearLabel = c.academic_year ? ` [${c.academic_year}]` : '';
      opt.textContent = `Sem ${c.semester||'?'} — ${c.name} (${c.code})${yearLabel}`;
      opt.dataset.semester = c.semester || '';
      courseSelect.appendChild(opt);
    });
  }
}

// Load faculty for selected college
async function loadFacultyForCollege(collegeId) {
  const facultySelect = document.getElementById('fa_faculty');
  facultySelect.innerHTML = '<option value="">— Select Faculty —</option>';
  if (!collegeId) return;
  
  const r = await postAction({ ajax_action: 'get_faculty', college_id: collegeId });
  if (r.ok && r.faculty) {
    r.faculty.forEach(f => {
      const opt = document.createElement('option');
      opt.value = f.id;
      opt.textContent = f.full_name;
      facultySelect.appendChild(opt);
    });
  }
}

// Auto-fill semester from selected course
function updateSemesterFromCourse(select) {
  const selectedOption = select.options[select.selectedIndex];
  const semester = selectedOption.dataset.semester;
  if (semester) {
    document.getElementById('fa_semester').value = semester;
  }
}

// Submit faculty assignment
async function submitFacultyAssignment() {
  const btn = document.getElementById('btnAddFacultyAssignment');
  const colEl  = document.getElementById('fa_college');
  const college = colEl ? colEl.value : '';
  const dept    = document.getElementById('fa_dept').value;
  const faculty = document.getElementById('fa_faculty').value;
  const course  = document.getElementById('fa_course').value;
  
  if (!college || !dept || !faculty || !course) {
    showAlert('alertFacultyAssignment', 'College, Department, Faculty and Course are required');
    return;
  }
  
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';
  
  const r = await postAction({
    ajax_action: 'add_faculty_assignment',
    faculty_id: faculty,
    college_id: college,
    department_id: dept,
    course_id: course,
    semester: document.getElementById('fa_semester').value,
    academic_year: document.getElementById('fa_academic_year').value,
    period: document.getElementById('fa_period').value,
    is_primary: document.getElementById('fa_is_primary').value,
    remarks: document.getElementById('fa_remarks').value
  });
  
  if (r.ok) {
    showAlert('alertFacultyAssignment', '✓ Faculty assigned successfully!', 'success');
    setTimeout(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check"></i> Assign Faculty';
      closeModal('modalFacultyAssignment');
      loadFacultyAssignments();
      // Refresh faculty tab table if it's the active visible view
      const facView = document.querySelector('[data-view="faculty"]');
      if (facView && facView.style.display !== 'none') {
        loadSection('faculty');
      }
    }, 1200);
  } else {
    showAlert('alertFacultyAssignment', r.msg);
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Assign Faculty';
  }
}

// ── HOD: Create Course / Subject ─────────────────────────────────────────────
async function submitCreateCourseHOD() {
  const btn     = document.getElementById('btnCreateCourseHOD');
  const alertEl = document.getElementById('alertCreateCourseHOD');
  const name    = document.getElementById('hcc_name').value.trim();
  const code    = document.getElementById('hcc_code').value.trim().toUpperCase();
  const sem     = document.getElementById('hcc_semester').value.trim();
  const year    = document.getElementById('hcc_academic_year').value.trim();
  const cred    = document.getElementById('hcc_credits').value.trim() || '3';
  const desc    = document.getElementById('hcc_desc').value.trim();

  function showHccAlert(msg, type='error') {
    alertEl.className = 'form-alert ' + type;
    alertEl.textContent = msg;
  }

  if (!name)  { showHccAlert('Course name is required.');      return; }
  if (!code)  { showHccAlert('Course code is required.');      return; }
  if (!sem || isNaN(parseInt(sem)) || parseInt(sem) < 1 || parseInt(sem) > 12) {
    showHccAlert('A valid semester (1–12) is required.'); return;
  }
  if (!year || !/^\d{4}-\d{2,4}$/.test(year)) {
    showHccAlert('Academic year must be like 2025-26.');  return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

  const r = await postAction({
    ajax_action:   'create_course_hod',
    name, code,
    semester:      sem,
    academic_year: year,
    credits:       cred,
    description:   desc
  });

  if (r.ok) {
    showHccAlert('✓ ' + r.msg, 'success');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-plus"></i> Create Course';
    // Reset fields
    ['hcc_name','hcc_code','hcc_semester','hcc_desc'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    document.getElementById('hcc_academic_year').value = '2025-26';
    document.getElementById('hcc_credits').value = '3';
    // After 1.8 s close modal and reload assignments (courses list updated)
    setTimeout(() => {
      closeModal('modalCreateCourseHOD');
      loadFacultyAssignments();
    }, 1800);
  } else {
    showHccAlert(r.msg || 'Failed to create course.');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-plus"></i> Create Course';
  }
}

// Load and display faculty assignments
async function loadFacultyAssignments() {
  // Clear stale assignment data so edit buttons always reference fresh data
  Object.keys(_assignmentDataMap).forEach(k => delete _assignmentDataMap[k]);

  const tbody = document.getElementById('assignments-tbody');
  const countEl = document.getElementById('assignments-count');
  tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--muted);padding:40px"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

  // filter-college is either a <select> (super-admin mode) or a <input type="hidden"> (scoped mode)
  const collegeEl = document.getElementById('filter-college');
  const college   = collegeEl ? collegeEl.value : '';
  const dept      = document.getElementById('filter-department') ? document.getElementById('filter-department').value : '';
  const faculty   = document.getElementById('filter-faculty') ? document.getElementById('filter-faculty').value : '';

  // scoped: college is locked, so badges only need that college's count
  const badgeCollegeId = college || '';

  let rFiltered, rAll;
  try {
    [rFiltered, rAll] = await Promise.all([
      postAction({ ajax_action: 'get_faculty_assignments', college_id: college, department_id: dept, faculty_id: faculty }),
      postAction({ ajax_action: 'get_faculty_assignments', college_id: badgeCollegeId, department_id: '', faculty_id: '' })
    ]);
  } catch (fetchErr) {
    rFiltered = { ok: false, msg: 'Network error: ' + fetchErr.message };
    rAll = { ok: false };
  }

  // ── Update per-faculty assignment count badges ──
  // Always update badges — even when rAll fails, reset them to "No assignment"
  const countMap = {};
  if (rAll && rAll.ok && rAll.assignments) {
    rAll.assignments.forEach(a => {
      countMap[a.faculty_id] = (countMap[a.faculty_id] || 0) + 1;
    });
  }
  document.querySelectorAll('[id^="fa-badge-"]').forEach(badge => {
    const fid = badge.id.replace('fa-badge-', '');
    const cnt = countMap[fid] || 0;
    if (cnt === 0) {
      badge.style.background = 'rgba(255,90,90,.15)';
      badge.style.color = '#ff5a5a';
      badge.textContent = 'No assignment';
    } else {
      badge.style.background = 'rgba(20,184,166,.12)';
      badge.style.color = 'var(--teal)';
      badge.textContent = cnt + ' course' + (cnt !== 1 ? 's' : '');
    }
  });

  // Whether to show the College column (hidden in scoped/principal/hod mode)
  const showCollegeCol = !college; // if no college filter locked, show college col

  const r = rFiltered;
  if (r && r.ok && r.assignments) {
    if (r.assignments.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${showCollegeCol ? 10 : 9}" style="text-align:center;color:var(--muted);padding:40px">No assignments found</td></tr>`;
      countEl.textContent = '0 assignments';
    } else {
      tbody.innerHTML = '';
      countEl.textContent = `${r.assignments.length} assignment${r.assignments.length !== 1 ? 's' : ''}`;

      r.assignments.forEach(a => {
        // ── FIX: Populate the data map so openEditAssignmentById works ──
        _assignmentDataMap[a.assignment_id] = a;

        const tr = document.createElement('tr');
        // Build action buttons using data-attributes to avoid apostrophe injection
        const addBtn = document.createElement('button');
        addBtn.className = 'btn';
        addBtn.style.cssText = 'background:var(--teal);padding:4px 10px;font-size:0.75rem;border-radius:5px;margin-right:4px';
        addBtn.title = 'Add another course to this faculty';
        addBtn.innerHTML = '<i class="fas fa-plus"></i>';
        addBtn.addEventListener('click', () => quickAssignFaculty(a.faculty_id, a.faculty_name, a.college_id, a.department_id));

        const editBtn = document.createElement('button');
        editBtn.className = 'btn';
        editBtn.style.cssText = 'background:var(--amber);color:#fff;padding:4px 10px;font-size:0.75rem;border-radius:5px;margin-right:4px';
        editBtn.title = 'Edit assignment';
        editBtn.innerHTML = '<i class="fas fa-edit"></i>';
        editBtn.addEventListener('click', () => openEditAssignmentById(a.assignment_id));

        const delBtn = document.createElement('button');
        delBtn.className = 'btn-icon-delete';
        delBtn.title = 'Delete assignment';
        delBtn.innerHTML = '<i class="fas fa-trash"></i>';
        delBtn.addEventListener('click', () => deleteFacultyAssignment(a.assignment_id));

        const actionTd = document.createElement('td');
        actionTd.style.cssText = 'text-align:center;white-space:nowrap';
        actionTd.appendChild(addBtn);
        actionTd.appendChild(editBtn);
        actionTd.appendChild(delBtn);

        tr.innerHTML = `
          <td style="font-weight:600;color:var(--text)">
            ${htmlEscape(a.faculty_name)}
            <div style="font-size:0.73rem;color:var(--muted)">${htmlEscape(a.faculty_email || '')}</div>
          </td>
          ${showCollegeCol ? `<td style="font-size:0.82rem">${htmlEscape(a.college_name)}</td>` : ''}
          <td style="font-size:0.82rem">${htmlEscape(a.department_name)} <span style="color:var(--muted)">(${htmlEscape(a.department_code)})</span></td>
          <td style="font-size:0.82rem;color:var(--teal)">${htmlEscape(a.course_name)} <span style="color:var(--muted)">(${htmlEscape(a.course_code)})</span></td>
          <td style="font-size:0.82rem;text-align:center">${a.semester || '—'}</td>
          <td style="font-size:0.82rem;text-align:center">${htmlEscape(a.academic_year) || '—'}</td>
          <td style="font-size:0.82rem">${htmlEscape(a.period) || 'All'}</td>
          <td style="text-align:center">
            ${a.is_primary == 1 ? '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> Primary</span>' : '<span style="color:var(--muted)">Assistant</span>'}
          </td>
          <td>
            <span class="status-badge ${htmlEscape(a.status)}">${htmlEscape(a.status).toUpperCase()}</span>
          </td>
        `;
        tr.appendChild(actionTd);
        tbody.appendChild(tr);
      });
    }
  } else {
    const errMsg = (r && r.msg) ? r.msg : 'Could not load assignments. Check your database connection.';
    tbody.innerHTML = `<tr><td colspan="${showCollegeCol ? 10 : 9}" style="text-align:center;color:var(--red);padding:40px">
      <i class="fas fa-circle-exclamation" style="font-size:1.5rem;margin-bottom:8px;display:block"></i>
      <strong>Error loading assignments</strong><br>
      <span style="font-size:0.78rem;color:var(--muted);margin-top:6px;display:block">${errMsg}</span>
    </td></tr>`;
    countEl.textContent = '0 assignments';
  }
}

// Delete faculty assignment
async function deleteFacultyAssignment(id) {
  if (!confirm('Are you sure you want to delete this faculty assignment?')) return;
  
  const r = await postAction({
    ajax_action: 'delete_faculty_assignment',
    id: id
  });
  
  if (r.ok) {
    // Remove stale entry from the data map so edit button won't find old data
    delete _assignmentDataMap[id];
    showToast('Assignment deleted successfully.', 'success');
    loadFacultyAssignments();
  } else {
    showToast('Error: ' + (r.msg || 'Could not delete assignment.'), 'error');
  }
}

// Assignment data store — avoids JSON injection in onclick attributes
const _assignmentDataMap = {};

// Open edit modal by assignment id (safe, no JSON in HTML)
function openEditAssignmentById(assignmentId) {
  const a = _assignmentDataMap[assignmentId];
  if (!a) { alert('Assignment data not found. Please reload the page.'); return; }
  openEditAssignment(a);
}

// HTML escape helper
function htmlEscape(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}


// ══════════════════════════════════════════════════════════════════════════
//  ATTENDANCE VIEW
// ══════════════════════════════════════════════════════════════════════════

let ATT_DATA = null; // last loaded response

// Init date inputs on page load
(function initAttDates() {
  const today = new Date();
  const y = today.getFullYear();
  const m = String(today.getMonth() + 1).padStart(2, '0');
  const d = String(today.getDate()).padStart(2, '0');
  const fromEl = document.getElementById('att-from');
  const toEl   = document.getElementById('att-to');
  if (fromEl) fromEl.value = `${y}-${m}-01`;
  if (toEl)   toEl.value   = `${y}-${m}-${d}`;
})();

// ── Filter cascade: college → filter department options ────────────────
function attCollegeChange() {
  const colEl  = document.getElementById('att-college');
  const cid = colEl ? String(colEl.value || '') : '';
  const deptSel = document.getElementById('att-dept');

  // Show only depts matching the selected college
  if (deptSel && deptSel.tagName === 'SELECT') {
    Array.from(deptSel.options).forEach(o => {
      if (!o.value) return;
      o.style.display = (!cid || o.dataset.college === cid) ? '' : 'none';
    });
    // Reset dept if current selection is now hidden
    const selDeptOpt = deptSel.options[deptSel.selectedIndex];
    if (deptSel.value && selDeptOpt && cid && selDeptOpt.dataset.college !== cid) {
      deptSel.value = '';
    }
  }
  attDeptChange();
}

function attDeptChange() {
  // Resolve current college and dept filter values
  const colEl  = document.getElementById('att-college');
  const deptEl = document.getElementById('att-dept');
  const cid = colEl  ? String(colEl.value  || '') : '';
  const did = deptEl ? String(deptEl.value || '') : '';

  const courseSel = document.getElementById('att-course');
  if (!courseSel) return;

  // Show course options only when BOTH college AND dept match
  Array.from(courseSel.options).forEach(o => {
    if (!o.value) return;
    const colMatch  = !cid || o.dataset.college === cid;
    const deptMatch = !did || o.dataset.dept    === did;
    o.style.display = (colMatch && deptMatch) ? '' : 'none';
  });

  // Reset course value if the currently-selected option is now hidden
  if (courseSel.value) {
    const selOpt = courseSel.options[courseSel.selectedIndex];
    if (selOpt && selOpt.style.display === 'none') {
      courseSel.value = '';
    }
  }
}

function resetAttFilters() {
  // Only reset college if it's a real select (not locked in scoped mode)
  const colEl = document.getElementById('att-college');
  if (colEl && colEl.tagName === 'SELECT') colEl.value = '';
  const deptEl = document.getElementById('att-dept');
  if (deptEl) deptEl.value = '';
  const courseEl = document.getElementById('att-course');
  if (courseEl) courseEl.value = '';
  const searchEl = document.getElementById('att-search');
  if (searchEl) searchEl.value = '';
  attCollegeChange();
  // Reset dates to current month
  const today = new Date();
  const y = today.getFullYear(), m = String(today.getMonth()+1).padStart(2,'0'), d = String(today.getDate()).padStart(2,'0');
  document.getElementById('att-from').value = `${y}-${m}-01`;
  document.getElementById('att-to').value   = `${y}-${m}-${d}`;
  // Reset table
  document.getElementById('att-stats').style.display = 'none';
  const defStrip = document.getElementById('att-defaulter-strip');
  if (defStrip) defStrip.style.display = 'none';
  document.getElementById('att-tbody').innerHTML =
    `<tr><td colspan="9"><div class="att-loading">
       <i class="fas fa-clipboard-check"></i>
       Select filters above and click <strong style="color:var(--teal)">Load</strong> to view attendance
     </div></td></tr>`;
  document.getElementById('att-count-badge').textContent = '<?= $saCollegeId ? htmlspecialchars($saCollegeName) : 'All colleges' ?>';
  document.getElementById('att-range-label').textContent = '';
  // Reset course-wise panel too
  const cwLabel = document.getElementById('att-range-label-cw');
  if (cwLabel) cwLabel.textContent = '';
  const cwCards = document.getElementById('att-course-cards');
  if (cwCards) cwCards.innerHTML = '<div class="att-loading" style="padding:40px 0;text-align:center;color:var(--muted)"><i class="fas fa-book"></i> Select filters above and click <strong style="color:var(--teal)">Load</strong> to view course-wise attendance</div>';
  ATT_DATA = null;
  attClearStale();
}

// ── Stale-filter indicator ─────────────────────────────────────────────
// Visually pulses the Load button when filters changed but data not yet reloaded
function attMarkStale() {
  const btn = document.getElementById('att-load-btn');
  if (!btn) return;
  if (!ATT_DATA) return; // no data loaded yet — nothing to mark stale
  btn.style.background   = '#f59e0b';
  btn.style.boxShadow    = '0 0 0 3px rgba(245,158,11,.25)';
  btn.innerHTML          = '<i class="fas fa-triangle-exclamation"></i> Reload';
  btn._isStale           = true;
}
function attClearStale() {
  const btn = document.getElementById('att-load-btn');
  if (!btn) return;
  btn.style.background = '';
  btn.style.boxShadow  = '';
  btn.innerHTML        = '<i class="fas fa-search"></i> Load';
  btn._isStale         = false;
}

// ── Main load ──────────────────────────────────────────────────────────
async function loadAttendance() {
  const tbody  = document.getElementById('att-tbody');
  const statsEl = document.getElementById('att-stats');

  tbody.innerHTML = `<tr><td colspan="9">
    <div class="att-loading">
      <i class="fas fa-spinner fa-spin" style="font-size:2rem;opacity:.6"></i>
      Loading attendance data…
    </div></td></tr>`;
  statsEl.style.display = 'none';
  document.getElementById('att-defaulter-strip').style.display = 'none';

  const from   = document.getElementById('att-from').value;
  const to     = document.getElementById('att-to').value;
  if (!from || !to) { alert('Please select a date range.'); return; }
  if (from > to)    { alert('From date must be before To date.'); return; }

  const data = await postAction({
    ajax_action: 'get_attendance',
    college_id:  document.getElementById('att-college').value,
    dept_id:     document.getElementById('att-dept').value,
    course_id:   document.getElementById('att-course').value,
    from, to,
    search:      document.getElementById('att-search').value,
  });

  if (!data.ok) {
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:var(--red);padding:40px">
      <i class="fas fa-exclamation-circle"></i> ${htmlEscape(data.msg || 'Failed to load data')}
    </td></tr>`;
    return;
  }

  ATT_DATA = data;
  attClearStale();
  renderAttendanceTable(data);
}

function pctClass(p) { return p >= 85 ? 'hi' : p >= 75 ? 'md' : 'lo'; }
function pad2(n)     { return String(n).padStart(2, '0'); }

const STATUS_COL = {
  P:'rgba(45,212,170,.14)',A:'rgba(231,111,81,.14)',L:'rgba(244,162,97,.14)',
  H:'rgba(59,130,246,.14)',LV:'rgba(139,92,246,.14)',ML:'rgba(6,182,212,.14)',
  HOL:'rgba(100,116,139,.14)'
};

function renderAttendanceTable(data) {
  const rows   = data.rows    || [];
  const dates  = data.dates   || [];
  const calMap = data.cal_map || {};
  const totals = data.totals  || {};
  const from   = data.from;
  const to     = data.to;

  const dFrom = new Date(from + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  const dTo   = new Date(to   + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  document.getElementById('att-range-label').textContent = `${dFrom} → ${dTo}`;

  // Badge count — safely get college label from either <select> or <input type=hidden>
  const collegeSel = document.getElementById('att-college');
  let scopeLabel = 'All Colleges';
  if (collegeSel) {
    if (collegeSel.tagName === 'SELECT') {
      scopeLabel = collegeSel.value
        ? collegeSel.options[collegeSel.selectedIndex]?.text || 'All Colleges'
        : 'All Colleges';
    } else {
      // hidden input in scoped mode — use PHP-baked name
      scopeLabel = <?= json_encode($saCollegeId ? $saCollegeName : '') ?> || 'College';
    }
  }
  document.getElementById('att-count-badge').textContent = `${rows.length} student${rows.length !== 1 ? 's' : ''} · ${scopeLabel}`;

  // ── Stats cards ────────────────────────────────────────────────────
  if (rows.length > 0) {
    document.getElementById('att-stats').style.display = 'grid';
    const setS = (id, val, pct, fillId) => {
      document.getElementById(id).textContent = val;
      setTimeout(() => { document.getElementById(fillId).style.width = Math.min(pct, 100) + '%'; }, 50);
    };
    const maxStudents = rows.length;
    setS('as-students', rows.length,          100,                       'asb-students');
    setS('as-present',  totals.present  || 0, (totals.present  / Math.max(totals.total,1))*100, 'asb-present');
    setS('as-absent',   totals.absent   || 0, (totals.absent   / Math.max(totals.total,1))*100, 'asb-absent');
    setS('as-leave',    (totals.leave_cnt||0) + (totals.late||0),
                        ((totals.leave_cnt||0) / Math.max(totals.total,1))*100, 'asb-leave');
    document.getElementById('as-pct').textContent = (totals.pct || 0) + '%';
    setTimeout(() => {
      document.getElementById('asb-pct').style.width = Math.min(totals.pct || 0, 100) + '%';
    }, 50);
  }

  // ── Defaulters strip ───────────────────────────────────────────────
  const defaulters = rows.filter(r => r.total > 0 && parseFloat(r.pct) < 75);
  const strip = document.getElementById('att-defaulter-strip');
  const dText = document.getElementById('att-defaulter-text');
  if (defaulters.length > 0) {
    strip.style.display = 'block';
    dText.textContent = ` ${defaulters.length} student${defaulters.length !== 1 ? 's' : ''} below 75% attendance — highlighted in red below.`;
  } else {
    strip.style.display = 'none';
  }

  // ── Build thead (summary cols + optional date strip) ───────────────
  const showDates  = dates.length > 0 && dates.length <= 31;
  const isScoped   = !!(<?= json_encode((bool)$saCollegeId) ?>); // college locked
  const isDeptLocked = !!(<?= json_encode((bool)$saDeptId && $saCollegeRole === 'hod') ?>);
  const selectedCourseId = document.getElementById('att-course').value; // '' = all courses
  const showCourseCol = !selectedCourseId; // show Course col only when viewing all courses
  const thead = document.getElementById('att-thead');
  thead.innerHTML = `<tr>
    <th style="width:36px;text-align:center">#</th>
    <th>Student</th>
    <th style="width:110px">Roll No.</th>
    ${!isScoped   ? '<th>College</th>'    : ''}
    ${!isDeptLocked ? '<th>Department</th>' : ''}
    ${showCourseCol ? '<th>Course</th>' : ''}
    <th style="width:130px">Attendance %</th>
    <th style="width:70px;text-align:center">Present</th>
    <th style="width:70px;text-align:center">Absent</th>
    <th style="width:70px;text-align:center">Leave</th>
    <th style="width:80px;text-align:center">Total</th>
    ${showDates ? dates.map(d => {
        const dt = new Date(d + 'T00:00:00');
        const day = ['Su','Mo','Tu','We','Th','Fr','Sa'][dt.getDay()];
        return `<th class="date-col" title="${d}">${dt.getDate()}<br><span style="color:var(--muted)">${day}</span></th>`;
      }).join('') : ''}
  </tr>`;

  // ── Tbody ──────────────────────────────────────────────────────────
  const tbody = document.getElementById('att-tbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${10 + (showDates ? dates.length : 0)}">
      <div class="att-loading">
        <i class="fas fa-search"></i>
        No attendance records found for the selected filters.
      </div>
    </td></tr>`;
    return;
  }

  const COLORS = ['#00c6ae','#f4a261','#7c3aed','#3b82f6','#e76f51','#06b6d4','#52c41a','#a78bfa'];

  tbody.innerHTML = '';
  rows.forEach((s, idx) => {
    const pct = parseFloat(s.pct) || 0;
    const pc  = pctClass(pct);
    const color = s.avatar_color || COLORS[idx % COLORS.length];
    const parts = (s.full_name || '').split(' ');
    const initials = ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
    const isDefaulter = s.total > 0 && pct < 75;

    let dateCells = '';
    if (showDates) {
      const mapKey = s.student_id + '|' + (s.course_id || 0);
      dateCells = dates.map(d => {
        const st = (calMap[mapKey] || {})[d];
        if (!st) return `<td class="date-col"><span class="st-badge none">–</span></td>`;
        const bg = STATUS_COL[st] || 'rgba(255,255,255,.05)';
        return `<td class="date-col"><span class="st-badge ${st}" style="background:${bg}" title="${d}: ${st}">${st}</span></td>`;
      }).join('');
    }

    const tr = document.createElement('tr');
    if (isDefaulter) tr.style.background = 'rgba(231,111,81,0.04)';

    tr.innerHTML = `
      <td style="color:var(--muted);font-size:0.78rem;text-align:center">${idx + 1}</td>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:32px;height:32px;border-radius:8px;flex-shrink:0;
            background:${color}22;color:${color};display:grid;place-items:center;
            font-size:0.72rem;font-weight:700">${htmlEscape(initials)}</div>
          <div>
            <div style="font-weight:600;color:var(--text);font-size:0.85rem">
              ${htmlEscape(s.full_name)}
              ${isDefaulter ? '<span style="margin-left:6px;font-size:0.62rem;background:rgba(231,111,81,.15);color:#e76f51;padding:1px 7px;border-radius:20px;font-weight:700">LOW</span>' : ''}
            </div>
          </div>
        </div>
      </td>
      <td style="font-family:monospace;font-size:0.78rem;color:var(--teal)">${htmlEscape(s.roll_number || '—')}</td>
      ${!isScoped   ? `<td style="font-size:0.8rem">${htmlEscape(s.college_name)}</td>` : ''}
      ${!isDeptLocked ? `<td style="font-size:0.8rem">${htmlEscape(s.dept_name)}</td>` : ''}
      ${showCourseCol ? `<td style="font-size:0.78rem;color:var(--text)">
        <div style="font-weight:600">${htmlEscape(s.course_name || '—')}</div>
        ${s.course_code && s.course_code !== '—' ? `<div style="font-size:0.68rem;color:var(--muted);font-family:monospace">${htmlEscape(s.course_code)}</div>` : ''}
      </td>` : ''}
      <td>
        <div class="pct-wrap">
          <div class="pct-bar"><div class="pct-fill ${pc}" style="width:0%" data-w="${pct}"></div></div>
          <span class="pct-num ${pc}">${pct}%</span>
        </div>
      </td>
      <td style="text-align:center;color:#2dd4aa;font-weight:700">${s.present || 0}</td>
      <td style="text-align:center;color:#e76f51;font-weight:700">${s.absent  || 0}</td>
      <td style="text-align:center;color:#a78bfa;font-weight:700">${(parseInt(s.leave_cnt||0) + parseInt(s.late||0))}</td>
      <td style="text-align:center;color:var(--muted);font-weight:600">${s.total || 0}</td>
      ${dateCells}
    `;
    tbody.appendChild(tr);
  });

  // Animate pct bars after DOM renders
  setTimeout(() => {
    document.querySelectorAll('#att-tbody .pct-fill[data-w]').forEach(el => {
      el.style.width = el.dataset.w + '%';
    });
  }, 60);

  // If course-wise mode is active, re-render course panel too
  if (typeof _attViewMode !== 'undefined' && _attViewMode === 'course' && ATT_DATA) {
    renderCourseWiseAttendance(ATT_DATA);
  }
}

// ── Attendance view toggle ──────────────────────────────────────────────
let _attViewMode = 'student'; // 'student' | 'course'

function setAttView(mode) {
  _attViewMode = mode;
  const btnS = document.getElementById('att-view-student');
  const btnC = document.getElementById('att-view-course');
  const panelS = document.getElementById('att-student-panel');
  const panelC = document.getElementById('att-course-panel');
  if (mode === 'course') {
    btnS.style.background = 'transparent';
    btnS.style.color = 'var(--teal)';
    btnS.style.fontWeight = '600';
    btnC.style.background = 'var(--teal)';
    btnC.style.color = '#0a0f1a';
    btnC.style.fontWeight = '700';
    panelS.style.display = 'none';
    panelC.style.display = 'block';
    if (ATT_DATA) renderCourseWiseAttendance(ATT_DATA);
  } else {
    btnS.style.background = 'var(--teal)';
    btnS.style.color = '#0a0f1a';
    btnS.style.fontWeight = '700';
    btnC.style.background = 'transparent';
    btnC.style.color = 'var(--teal)';
    btnC.style.fontWeight = '600';
    panelS.style.display = 'block';
    panelC.style.display = 'none';
  }
}

// ── Course-wise renderer ────────────────────────────────────────────────
function renderCourseWiseAttendance(data) {
  const rows   = data.rows   || [];
  const from   = data.from;
  const to     = data.to;
  const calMap = data.cal_map || {};
  const dates  = data.dates  || [];

  // Update range label
  const dFrom = new Date(from + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  const dTo   = new Date(to   + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  const rangeLabelCw = document.getElementById('att-range-label-cw');
  if (rangeLabelCw) rangeLabelCw.textContent = dFrom + ' → ' + dTo;

  const container = document.getElementById('att-course-cards');
  if (!rows.length) {
    container.innerHTML = '<div class="att-loading" style="padding:40px 0;text-align:center;color:var(--muted)"><i class="fas fa-search"></i> No attendance records found for the selected filters.</div>';
    return;
  }

  // Group rows by course_id (definitive key — avoids same-name course conflicts)
  const courseMap = {};
  rows.forEach(s => {
    const key = String(s.course_id || '0');
    if (!courseMap[key]) {
      courseMap[key] = {
        course_id:    s.course_id    || 0,
        course_name:  s.course_name  || '—',
        course_code:  s.course_code  || '—',
        college_name: s.college_name || '—',
        dept_name:    s.dept_name    || '—',
        students: [],
        total: 0, present: 0, absent: 0, leave_cnt: 0, late: 0
      };
    }
    const c = courseMap[key];
    c.students.push(s);
    c.total      += parseInt(s.total      || 0);
    c.present    += parseInt(s.present    || 0);
    c.absent     += parseInt(s.absent     || 0);
    c.leave_cnt  += parseInt(s.leave_cnt  || 0);
    c.late       += parseInt(s.late       || 0);
  });

  const COLORS = ['#00c6ae','#f4a261','#7c3aed','#3b82f6','#e76f51','#06b6d4','#52c41a','#a78bfa'];

  function pctClass(p) {
    return p >= 75 ? 'good' : p >= 60 ? 'warn' : 'low';
  }
  function pctStyle(p) {
    const pc = pctClass(p);
    if (pc === 'good') return {badge:'background:rgba(45,212,170,.12);color:#2dd4aa;border:1px solid rgba(45,212,170,.3)', bar:'#2dd4aa', num:'#2dd4aa'};
    if (pc === 'warn') return {badge:'background:rgba(244,162,97,.12);color:#D97706;border:1px solid rgba(244,162,97,.3)',  bar:'#f4a261', num:'#f4a261'};
    return                    {badge:'background:rgba(231,111,81,.12);color:#e76f51;border:1px solid rgba(231,111,81,.3)',  bar:'#e76f51', num:'#e76f51'};
  }

  let html = '';
  Object.values(courseMap).forEach((course, ci) => {
    const pct = course.total > 0 ? Math.round(course.present * 100 / course.total * 10) / 10 : 0;
    const st  = pctStyle(pct);
    const leave = course.leave_cnt + course.late;

    const showDates = dates.length > 0 && dates.length <= 31;
    let dateThCols = '';
    if (showDates) {
      dateThCols = dates.map(d => {
        const dt  = new Date(d + 'T00:00:00');
        const day = ['Su','Mo','Tu','We','Th','Fr','Sa'][dt.getDay()];
        return `<th style="text-align:center;font-size:0.58rem;padding:6px 3px;min-width:30px;white-space:nowrap" title="${d}">${dt.getDate()}<br><span style="color:var(--muted)">${day}</span></th>`;
      }).join('');
    }
    const theadHtml = `<tr style="background:rgba(15,118,110,.03)">
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:left">#</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:left">Student</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:left">Roll No.</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted)">Attendance %</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:center">Present</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:center">Absent</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:center">Leave</th>
      <th style="padding:7px 12px;font-size:0.7rem;font-weight:600;color:var(--muted);text-align:center">Total</th>
      ${dateThCols}
    </tr>`;

    // Per-student rows
    let studentRows = '';
    course.students.forEach((s, idx) => {
      const sp      = parseFloat(s.pct) || 0;
      const sst     = pctStyle(sp);
      const isDeflt = s.total > 0 && sp < 75;
      const color   = s.avatar_color || COLORS[(ci + idx) % COLORS.length];
      const parts   = (s.full_name || '').split(' ');
      const initials= ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
      const sLeave  = parseInt(s.leave_cnt || 0) + parseInt(s.late || 0);

      // Per-student date cells
      let dateCells = '';
      if (showDates) {
        const mapKey = s.student_id + '|' + (s.course_id || 0);
        dateCells = dates.map(d => {
          const dst = (calMap[mapKey] || {})[d];
          if (!dst) return '<td style="text-align:center;padding:6px 4px"><span style="font-size:0.62rem;color:var(--muted)">–</span></td>';
          const SC = {P:'rgba(45,212,170,.18)',A:'rgba(231,111,81,.18)',L:'rgba(244,162,97,.18)',LV:'rgba(139,92,246,.18)',ML:'rgba(139,92,246,.18)',HOL:'rgba(100,116,139,.18)',H:'rgba(100,116,139,.18)'};
          const ST = {P:'#2dd4aa',A:'#e76f51',L:'#f4a261',LV:'#a78bfa',ML:'#a78bfa',HOL:'#94a3b8',H:'#94a3b8'};
          const bg  = SC[dst] || 'rgba(255,255,255,.05)';
          const col = ST[dst] || 'var(--muted)';
          return `<td style="text-align:center;padding:6px 4px"><span style="font-size:0.6rem;font-weight:700;background:${bg};color:${col};padding:2px 5px;border-radius:3px" title="${d}: ${dst}">${dst}</span></td>`;
        }).join('');
      }

      studentRows += `<tr style="${isDeflt ? 'background:rgba(231,111,81,0.04)' : ''}">
        <td style="padding:8px 12px;color:var(--muted);font-size:0.76rem">${idx + 1}</td>
        <td style="padding:8px 12px">
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:28px;height:28px;border-radius:7px;flex-shrink:0;background:${color}22;color:${color};display:grid;place-items:center;font-size:0.68rem;font-weight:700">${htmlEscape(initials)}</div>
            <div>
              <div style="font-weight:600;color:var(--text);font-size:0.83rem">${htmlEscape(s.full_name)}${isDeflt ? '<span style="margin-left:6px;font-size:0.6rem;background:rgba(231,111,81,.15);color:#e76f51;padding:1px 6px;border-radius:20px;font-weight:700">LOW</span>' : ''}</div>
            </div>
          </div>
        </td>
        <td style="padding:8px 12px;font-family:monospace;font-size:0.76rem;color:var(--teal)">${htmlEscape(s.roll_number || '—')}</td>
        <td style="padding:8px 12px">
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:4px;background:rgba(15,118,110,.10);border-radius:2px;overflow:hidden;min-width:50px">
              <div style="height:100%;width:${Math.min(sp,100)}%;background:${sst.bar};border-radius:2px"></div>
            </div>
            <span style="font-size:0.78rem;font-weight:700;color:${sst.num};min-width:36px;text-align:right">${sp}%</span>
          </div>
        </td>
        <td style="padding:8px 12px;text-align:center;color:#2dd4aa;font-weight:700">${s.present || 0}</td>
        <td style="padding:8px 12px;text-align:center;color:#e76f51;font-weight:700">${s.absent  || 0}</td>
        <td style="padding:8px 12px;text-align:center;color:#a78bfa;font-weight:700">${sLeave}</td>
        <td style="padding:8px 12px;text-align:center;color:var(--muted)">${s.total || 0}</td>
        ${dateCells}
      </tr>`;
    });

    html += `
    <div style="margin-bottom:18px;border:1px solid var(--border2);border-radius:var(--radius,8px);overflow:hidden">
      <!-- Card header -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 18px;background:linear-gradient(90deg,rgba(20,184,166,.04),transparent);border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="width:34px;height:34px;border-radius:8px;background:rgba(20,184,166,.12);color:#0F766E;display:grid;place-items:center;font-size:0.8rem;flex-shrink:0">
            <i class="fas fa-book"></i>
          </div>
          <div>
            <div style="font-weight:700;color:var(--text);font-size:0.92rem">${htmlEscape(course.course_name)}</div>
            <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">${htmlEscape(course.college_name)} &nbsp;·&nbsp; ${htmlEscape(course.dept_name)}</div>
          </div>
          <span style="font-size:0.72rem;font-family:monospace;background:rgba(20,184,166,.1);color:#0F766E;padding:3px 10px;border-radius:4px">${htmlEscape(course.course_code)}</span>
        </div>
        <div style="display:flex;align-items:center;gap:16px">
          <div style="font-size:0.72rem;color:var(--muted);text-align:right">
            <span>${course.students.length} student${course.students.length !== 1 ? 's' : ''}</span>
          </div>
          <span style="font-size:0.88rem;font-weight:700;padding:5px 16px;border-radius:20px;${st.badge}">${pct}%</span>
        </div>
      </div>
      <!-- Summary stats row -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--border)">
        <div style="padding:10px 16px;border-right:1px solid var(--border);text-align:center">
          <div style="font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Present</div>
          <div style="font-size:1.3rem;font-weight:700;color:#2dd4aa">${course.present}</div>
        </div>
        <div style="padding:10px 16px;border-right:1px solid var(--border);text-align:center">
          <div style="font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Absent</div>
          <div style="font-size:1.3rem;font-weight:700;color:#e76f51">${course.absent}</div>
        </div>
        <div style="padding:10px 16px;border-right:1px solid var(--border);text-align:center">
          <div style="font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Leave</div>
          <div style="font-size:1.3rem;font-weight:700;color:#a78bfa">${leave}</div>
        </div>
        <div style="padding:10px 16px;text-align:center">
          <div style="font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Total Sessions</div>
          <div style="font-size:1.3rem;font-weight:700;color:var(--muted)">${course.total}</div>
        </div>
      </div>
      <!-- Student breakdown table -->
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
          <thead>${theadHtml}</thead>
          <tbody>${studentRows}</tbody>
        </table>
      </div>
    </div>`;
  });

  container.innerHTML = html;
}

// ── Export CSV ─────────────────────────────────────────────────────────
function exportAttendanceCSV() {
  if (!ATT_DATA || !ATT_DATA.rows.length) {
    alert('Load attendance data first.'); return;
  }
  const rows   = ATT_DATA.rows;
  const dates  = ATT_DATA.dates || [];
  const calMap = ATT_DATA.cal_map || {};
  const header = ['#','Student','Roll No.','College','Department','Course','Att%','Present','Absent','Leave','Total',...dates];
  const lines  = [header];
  rows.forEach((s, i) => {
    const dateCols = dates.map(d => (calMap[s.student_id] || {})[d] || '—');
    lines.push([
      i+1, s.full_name, s.roll_number||'—', s.college_name, s.dept_name,
      s.course_name !== '—' ? s.course_name : '',
      s.pct+'%', s.present||0, s.absent||0,
      (parseInt(s.leave_cnt||0)+parseInt(s.late||0)), s.total||0,
      ...dateCols
    ]);
  });
  const csv = lines.map(r => r.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
  a.download = `attendance_${ATT_DATA.from}_to_${ATT_DATA.to}.csv`;
  a.click();
}

// Load attendance automatically when the view is switched to
const _origSwitchView = switchView;
window.switchView = function(viewName) {
  _origSwitchView(viewName);
  if (viewName === 'attendance' && !ATT_DATA) {
    document.getElementById('att-stats').style.display = 'none';
  }
  if (viewName === 'hod-access-codes') {
    hodCodesRefresh();
  }
  // HOD mode: auto-seed TT with locked college + dept, show overview
  if (viewName === 'timetable' && <?= $saDeptId ? 'true' : 'false' ?>) {
    TT.collegeId = <?= (int)$saCollegeId ?>;
    TT.deptId    = <?= (int)$saDeptId ?>;
    const overviewPanel = document.getElementById('tt-overview-panel');
    if (overviewPanel) { overviewPanel.style.display = ''; ttRefreshOverview(); }
    const scopeLabel = document.getElementById('tt-scope-label');
    if (scopeLabel) scopeLabel.textContent = <?= json_encode($saCollegeName) ?>;
  }
};

// ══════════════════════════════════════════════════════════════════════════
//  HOD ACCESS CODES  — Principal management panel
// ══════════════════════════════════════════════════════════════════════════
async function hodCodesRefresh() {
  const body = document.getElementById('hodCodesPanelBody');
  if (!body) return;
  body.innerHTML = '<div style="text-align:center;color:var(--muted);padding:32px"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';

  const collegeId = CS.collegeId || 0;
  if (!collegeId) {
    body.innerHTML = '<div style="color:var(--muted);padding:20px;text-align:center">No college selected. Please switch to a college as Principal first.</div>';
    return;
  }

  // Load departments, faculty list, current HOD assignments, and access codes in parallel
  const [deptRes, facRes, hodRes, codeRes] = await Promise.all([
    postAction({ ajax_action:'get_departments', college_id:collegeId }),
    postAction({ ajax_action:'get_faculty',     college_id:collegeId }),
    postAction({ ajax_action:'get_dept_hods',   college_id:collegeId }),
    postAction({ ajax_action:'get_hod_codes',   college_id:collegeId }),
  ]);

  const depts   = (deptRes.ok  && deptRes.departments) ? deptRes.departments : [];
  const faculty = (facRes.ok   && facRes.faculty)       ? facRes.faculty      : [];
  const codes   = {};
  const hodMap  = {}; // dept_id → { hod_user_id, hod_name, hod_full_name, hod_designation }

  if (codeRes.ok && codeRes.codes)
    codeRes.codes.forEach(c => { codes[c.dept_id] = c; });
  if (hodRes.ok && hodRes.hods)
    hodRes.hods.forEach(h => { hodMap[h.dept_id] = h; });

  if (!depts.length) {
    body.innerHTML = '<div style="color:var(--muted);padding:20px;text-align:center">No departments found for this college.</div>';
    return;
  }

  let rows = depts.map(d => {
    const existing   = codes[d.id];
    const hodInfo    = hodMap[d.id] || {};
    const hasCode    = !!existing;
    const used       = hasCode && existing.used == 1;
    const code       = hasCode ? existing.code : '';
    const currentHodId   = hodInfo.hod_user_id || '';
    // Prefer the linked user's name; fall back to the plain-text hod_name field
    const currentHodName = hodInfo.hod_full_name || hodInfo.hod_name || d.hod_name || '—';
    const currentHodDesig= hodInfo.hod_designation ? ' · ' + hodInfo.hod_designation : '';

    const codeBadge = hasCode
      ? (used
          ? `<span style="background:rgba(239,68,68,.15);color:#ef4444;border-radius:20px;padding:2px 9px;font-size:.65rem;font-weight:700">USED</span>`
          : `<span style="background:rgba(45,212,170,.12);color:#2dd4aa;border-radius:20px;padding:2px 9px;font-size:.65rem;font-weight:700">ACTIVE</span>`)
      : `<span style="background:rgba(122,147,172,.12);color:#7a93ac;border-radius:20px;padding:2px 9px;font-size:.65rem;font-weight:700">NOT SET</span>`;

    const hodBadge = currentHodId
      ? `<span style="background:rgba(20,184,166,.1);color:var(--teal);border-radius:20px;padding:2px 9px;font-size:.65rem;font-weight:700">ASSIGNED</span>`
      : `<span style="background:rgba(122,147,172,.12);color:#7a93ac;border-radius:20px;padding:2px 9px;font-size:.65rem;font-weight:700">NOT SET</span>`;

    // Faculty options for this dept's dropdown
    const facOptions = faculty.map(f =>
      `<option value="${f.id}" ${f.id == currentHodId ? 'selected' : ''}>${f.full_name}${f.designation ? ' · ' + f.designation : ''}</option>`
    ).join('');

    return `<tr style="border-bottom:1px solid var(--border)">

      <!-- Department name & code -->
      <td style="padding:14px 16px;vertical-align:middle">
        <div style="font-weight:700;color:var(--text)">${d.name}</div>
        <div style="font-size:.7rem;color:var(--teal);font-family:monospace;margin-top:2px">${d.code}</div>
      </td>

      <!-- HOD Faculty Picker -->
      <td style="padding:14px 16px;vertical-align:middle">
        <div style="font-size:.7rem;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          ${hodBadge}
          <span id="hodName_${d.id}" style="color:var(--text);font-weight:600;line-height:1.2">${currentHodName}</span>
          <span style="color:var(--muted)">${currentHodDesig}</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <select id="hodSel_${d.id}"
            style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:6px;color:var(--text);padding:8px 10px;font-size:.82rem;flex:1;min-width:0;outline:none;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif"
            onfocus="this.style.borderColor='rgba(20,184,166,.5)'"
            onblur="this.style.borderColor='var(--border2)'">
            <option value="">— No HOD assigned —</option>
            ${facOptions}
          </select>
          <button onclick="hodSetFaculty(${d.id},'${d.name.replace(/'/g,"\\'")}')"
            style="background:linear-gradient(135deg,#14B8A6,#0F766E);color:var(--text);border:none;border-radius:6px;padding:8px 16px;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">
            <i class="fas fa-user-check"></i> Set HOD
          </button>
        </div>
        <div id="hodFacMsg_${d.id}" style="font-size:.7rem;margin-top:4px;display:none"></div>
      </td>

      <!-- Code status -->
      <td style="padding:14px 16px;vertical-align:middle;text-align:center">${codeBadge}</td>

      <!-- Access code input + save -->
      <td style="padding:14px 16px;vertical-align:middle">
        <div style="display:flex;align-items:center;gap:10px">
          <input id="hc_inp_${d.id}" type="text" value="${code}"
            maxlength="20" placeholder="e.g. HOD2025"
            style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:6px;color:var(--text);padding:7px 12px;font-size:.84rem;font-family:monospace;flex:1;min-width:0;outline:none;text-transform:uppercase"
            oninput="this.value=this.value.toUpperCase()"
            onfocus="this.style.borderColor='rgba(244,162,97,.6)'"
            onblur="this.style.borderColor='rgba(15,118,110,.25)'">
          <button onclick="hodCodeSave(${d.id},'${d.name.replace(/'/g,"\\'")}')"
            style="background:linear-gradient(135deg,#f4a261,#e07a3a);color:var(--text);border:none;border-radius:6px;padding:7px 18px;font-size:.8rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap">
            <i class="fas fa-save"></i> ${hasCode ? 'Update' : 'Set'} Code
          </button>
          <button onclick="hodCodeGenerate(${d.id})" title="Auto-generate a random code"
            style="background:rgba(244,162,97,.12);color:#D97706;border:1px solid rgba(244,162,97,.2);border-radius:6px;padding:7px 12px;font-size:.78rem;cursor:pointer">
            <i class="fas fa-wand-magic-sparkles"></i>
          </button>
        </div>
        <div id="hc_msg_${d.id}" style="font-size:.72rem;margin-top:4px;display:none"></div>
      </td>
    </tr>`;
  }).join('');

  body.innerHTML = `
    <div style="font-size:.78rem;color:var(--muted);margin-bottom:16px;padding:10px 14px;background:rgba(20,184,166,.05);border:1px solid rgba(20,184,166,.15);border-radius:8px">
      <i class="fas fa-circle-info" style="color:var(--teal);margin-right:6px"></i>
      <strong style="color:var(--text)">Step 1:</strong> Use <strong style="color:var(--teal)">Set HOD</strong> to assign a faculty member as Head of Department.
      &nbsp;|&nbsp;
      <strong style="color:var(--text)">Step 2:</strong> Generate a one-time <strong style="color:#D97706">Access Code</strong> and share it with the HOD so they can log in.
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;table-layout:fixed">
      <colgroup>
        <col style="width:18%">
        <col style="width:36%">
        <col style="width:12%">
        <col style="width:34%">
      </colgroup>
      <thead>
        <tr style="border-bottom:1px solid rgba(15,118,110,.12)">
          <th style="padding:10px 16px;text-align:left;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Department</th>
          <th style="padding:10px 16px;text-align:left;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Head of Department (HOD)</th>
          <th style="padding:10px 16px;text-align:center;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">Code Status</th>
          <th style="padding:10px 16px;text-align:left;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">HOD Access Code</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
    </div>`;
}

// Save HOD faculty selection for a department
async function hodSetFaculty(deptId, deptName) {
  const sel  = document.getElementById(`hodSel_${deptId}`);
  const msg  = document.getElementById(`hodFacMsg_${deptId}`);
  const nameEl = document.getElementById(`hodName_${deptId}`);
  const userId = sel ? sel.value : '';

  if (msg) { msg.textContent = 'Saving…'; msg.style.color = 'var(--muted)'; msg.style.display = 'block'; }

  const data = await postAction({
    ajax_action:  'set_dept_hod',
    college_id:   CS.collegeId,
    dept_id:      deptId,
    hod_user_id:  userId
  });

  if (data.ok) {
    const displayName = data.hod_name || 'None';
    if (msg)    { msg.textContent = '✓ HOD set to: ' + displayName; msg.style.color = '#16A34A'; msg.style.display = 'block'; }
    if (nameEl) nameEl.textContent = displayName;
    setTimeout(() => { if (msg) msg.style.display = 'none'; }, 3000);
  } else {
    if (msg) { msg.textContent = data.msg || 'Error saving HOD.'; msg.style.color = '#ef4444'; msg.style.display = 'block'; }
  }
}

async function hodCodeSave(deptId, deptName) {
  const inp = document.getElementById(`hc_inp_${deptId}`);
  const msg = document.getElementById(`hc_msg_${deptId}`);
  const code = inp ? inp.value.trim().toUpperCase() : '';
  if (code.length < 4) {
    if(msg){msg.textContent='Code must be at least 4 characters.';msg.style.color='#ef4444';msg.style.display='block';}
    return;
  }
  if(msg){msg.textContent='Saving…';msg.style.color='var(--muted)';msg.style.display='block';}
  const data = await postAction({ ajax_action:'set_hod_code', college_id:CS.collegeId, dept_id:deptId, code });
  if (data.ok) {
    if(msg){msg.textContent='✓ Code saved. Share this with the HOD of '+deptName;msg.style.color='#16A34A';msg.style.display='block';}
    setTimeout(hodCodesRefresh, 1800);
  } else {
    if(msg){msg.textContent=data.msg||'Error saving.';msg.style.color='#ef4444';msg.style.display='block';}
  }
}

function hodCodeGenerate(deptId) {
  // Generate a random 8-char alphanumeric code
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let code = '';
  for(let i=0;i<8;i++) code += chars[Math.floor(Math.random()*chars.length)];
  const inp = document.getElementById(`hc_inp_${deptId}`);
  if(inp){ inp.value=code; inp.style.borderColor='rgba(244,162,97,.6)'; }
}


// ══════════════════════════════════════════════════════════════════════════
//  TIMETABLE VIEW  (wizard-style: step 1 college+dept → step 2 section → step 3 grid)
// ══════════════════════════════════════════════════════════════════════════

const TT_DAYS      = ['Mon','Tue','Wed','Thu','Fri','Sat'];
const TT_DAY_FULL  = {Mon:'Monday',Tue:'Tuesday',Wed:'Wednesday',Thu:'Thursday',Fri:'Friday',Sat:'Saturday'};
const TT_COLORS    = ['tc1','tc2','tc3','tc4','tc5','tc6','tc7','tc8'];
const TT_COLOR_HEX = ['#00c6ae','#f59e0b','#a78bfa','#ef4444','#60a5fa','#10b981','#f472b6','#fb923c'];

let TT = {
  collegeId: 0, deptId: 0, semId: 0, secId: 0,
  ttId: null, periods: [], courses: [], faculty: [], rooms: [],
  slotPeriodId: null, slotDay: null,
};

// ── Ajax helper ──────────────────────────────────────────────────────────
async function ttPost(data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch(location.href, { method: 'POST', body: fd });
  return r.json();
}

// ── Wizard navigation ─────────────────────────────────────────────────────
function ttSetStep(n) {
  for (let i = 1; i <= 4; i++) {
    const step = document.getElementById('ttstep-' + i);
    const wp   = document.getElementById('ttwp-'   + i);
    if (step) step.classList.toggle('active', i <= n);
    if (wp)   wp.classList.toggle('active',   i === n);
  }
}

function ttGoToStep1() {
  ttSetStep(1);
}

async function ttGoToStep2() {
  if (!TT.collegeId) { showNotification('Please select a college.', 'warning'); return; }
  if (!TT.deptId)    { showNotification('Please select a department.', 'warning'); return; }
  // Load semesters for this college (annotated with student section counts)
  const semSel = document.getElementById('tt-sem');
  semSel.innerHTML = '<option value="">— Loading… —</option>';
  const r = await ttPost({ ajax_action: 'tt_get_semesters', college_id: TT.collegeId, dept_id: TT.deptId });
  semSel.innerHTML = '<option value="">— Select Semester —</option>';
  (r.semesters || []).forEach(s => {
    // Build a descriptive label: name + status badge + student section count
    const statusTag  = s.status === 'Current'   ? ' ● Current'
                     : s.status === 'Upcoming'   ? ' ◌ Upcoming'
                     : s.status === 'Completed'  ? ' ✓ Completed' : '';
    const secNote    = (s.student_sections !== undefined)
                     ? (s.student_sections > 0
                          ? `  [${s.student_sections} section${s.student_sections > 1 ? 's' : ''}]`
                          : '  [no student sections]')
                     : '';
    const isSelected = (s.status === 'Current') ? ' selected' : '';
    semSel.innerHTML += `<option value="${s.id}" data-sem-num="${s.sem_num||0}"${isSelected}>${he(s.name)}${statusTag}${secNote}</option>`;
  });
  if (semSel.value) {
    TT.semId = parseInt(semSel.value);
    await ttSemChange();
  }
  // Show dept label in step 2 — safely handles both <select> and hidden <input> (HOD mode)
  const deptEl2 = document.getElementById('tt-dept');
  let deptDisplayName = '—';
  if (deptEl2) {
    if (deptEl2.tagName === 'SELECT') {
      deptDisplayName = deptEl2.options[deptEl2.selectedIndex]?.text || '—';
    } else {
      // Hidden input in HOD mode — use the PHP-baked dept name
      deptDisplayName = <?= json_encode($saDeptName ?: '') ?> || deptEl2.value || '—';
    }
  }
  const step2Label = document.getElementById('tt-step2-dept-label');
  if (step2Label) step2Label.textContent = deptDisplayName;
  ttSetStep(2);
}

async function ttGoToStep3() {
  TT.semId = parseInt(document.getElementById('tt-sem').value) || 0;
  TT.secId = parseInt(document.getElementById('tt-sec').value) || 0;
  if (!TT.semId) { showNotification('Please select a semester.', 'warning'); return; }
  if (!TT.secId) { showNotification('Please select a section.', 'warning'); return; }
  // Show current selection in controls bar
  const _dEl   = document.getElementById('tt-dept');
  const _dText = (_dEl && _dEl.tagName === 'SELECT')
    ? (_dEl.options[_dEl.selectedIndex]?.text || 'Dept')
    : (<?= json_encode($saDeptName ?: '') ?> || 'Dept');
  const _smOpt = document.getElementById('tt-sem').options[document.getElementById('tt-sem').selectedIndex];
  const _scOpt = document.getElementById('tt-sec').options[document.getElementById('tt-sec').selectedIndex];
  const _selInfo = document.getElementById('tt-selection-info');
  if (_selInfo) { _selInfo.textContent = `${_dText} › ${_smOpt?.text||'Sem'} › ${_scOpt?.text||'Sec'}`; _selInfo.style.display = 'inline'; }
  ttSetStep(3);
  // Load meta (periods/courses/faculty/rooms)
  await ttLoadMeta();
  // Try to find existing timetable
  const fr = await ttPost({ ajax_action:'tt_find', college_id:TT.collegeId, dept_id:TT.deptId, sem_id:TT.semId, sec_id:TT.secId });
  if (fr.timetable) {
    TT.ttId = fr.timetable.id;
    const badge = document.getElementById('tt-edit-badge');
    badge.textContent = `#${TT.ttId} · ${fr.timetable.academic_year||'—'} · ${fr.timetable.status}`;
    badge.className = 'tt-status ' + fr.timetable.status;
    document.getElementById('tt-grid-status').textContent = `Timetable #${TT.ttId}`;
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
    showNotification('Timetable loaded. Click any cell to assign.', 'success');
  } else {
    TT.ttId = null;
    document.getElementById('tt-edit-badge').textContent = 'No timetable yet';
    document.getElementById('tt-edit-badge').className = 'tt-status draft';
    document.getElementById('tt-grid-status').textContent = '';
    document.getElementById('tt-edit-wrap').innerHTML = '<div class="tt-empty"><i class="fas fa-calendar-plus"></i><p>No timetable yet for this section.<br>Click <strong style="color:var(--teal)">Load / Create Timetable</strong> above to create one.</p></div>';
    document.getElementById('tt-edit-legend').style.display = 'none';
  }
}

// ── College change ────────────────────────────────────────────────────────
async function ttCollegeChange() {
  const colEl = document.getElementById('tt-college');
  TT.collegeId = parseInt(colEl ? colEl.value : 0) || 0;
  TT.ttId = null;

  // Filter dept dropdown to only show depts for this college
  // (skip if dept is a hidden input — HOD mode with locked dept)
  const deptSel = document.getElementById('tt-dept');
  if (deptSel && deptSel.tagName === 'SELECT') {
    Array.from(deptSel.options).forEach(o => {
      if (!o.value) return;
      o.style.display = (!TT.collegeId || parseInt(o.dataset.college) === TT.collegeId) ? '' : 'none';
    });
    if (deptSel.value && parseInt(deptSel.options[deptSel.selectedIndex]?.dataset?.college) !== TT.collegeId)
      deptSel.value = '';
    TT.deptId = parseInt(deptSel.value) || 0;
  }

  // Filter setup dept dropdown
  const setupDept = document.getElementById('tts-sec-dept');
  if (setupDept) {
    Array.from(setupDept.options).forEach(o => {
      if (!o.value) return;
      o.style.display = (!TT.collegeId || parseInt(o.dataset.college) === TT.collegeId) ? '' : 'none';
    });
  }

  // Show/hide overview panel
  const overviewPanel = document.getElementById('tt-overview-panel');
  if (TT.collegeId) {
    overviewPanel.style.display = '';
    ttRefreshOverview();
  } else {
    overviewPanel.style.display = 'none';
  }

  // Update scope label safely (works for both <select> and hidden input)
  const scopeLabel = document.getElementById('tt-scope-label');
  if (scopeLabel) {
    if (TT.collegeId) {
      // Try select first, fall back to PHP-baked college name
      const colName = (colEl && colEl.tagName === 'SELECT' && colEl.selectedIndex >= 0)
        ? colEl.options[colEl.selectedIndex].text
        : <?= json_encode($saCollegeId ? $saCollegeName : '') ?> || 'College selected';
      scopeLabel.textContent = colName;
    } else {
      scopeLabel.textContent = 'Select a college to begin';
    }
  }

  await ttSetupSemLoaded();
}

function ttDeptChange() {
  const el = document.getElementById('tt-dept');
  TT.deptId = parseInt(el ? el.value : 0) || 0;
  TT.ttId = null;
}

async function ttSemChange() {
  TT.semId = parseInt(document.getElementById('tt-sem').value) || 0;
  TT.ttId = null;
  const secSel = document.getElementById('tt-sec');
  secSel.innerHTML = '<option value="">— Loading… —</option>';
  if (TT.collegeId && TT.deptId && TT.semId) {
    const r = await ttPost({ ajax_action: 'tt_get_sections', college_id: TT.collegeId, dept_id: TT.deptId, sem_id: TT.semId });
    const secs = r.sections || [];
    if (secs.length) {
      secSel.innerHTML = '<option value="">— Select Section —</option>';
      secs.forEach(s => {
        // Show source: 'students' means derived from real enrolled users
        const srcTag = s.source === 'students'
          ? `${s.strength} student${s.strength != 1 ? 's' : ''}`
          : `${s.strength} seats`;
        secSel.innerHTML += `<option value="${s.id}">Section ${he(s.label)}  (${srcTag})</option>`;
      });
      // Show inline info about data source
      const infoEl = document.getElementById('tt-sec-source-note');
      const src = secs[0]?.source || 'manual';
      if (infoEl) {
        infoEl.textContent = src === 'students'
          ? `✓ Sections auto-detected from ${secs.reduce((a,s)=>a+(parseInt(s.strength)||0),0)} enrolled students`
          : '⚠ No student records found — showing manually configured sections';
        infoEl.style.color = src === 'students' ? 'var(--teal)' : '#f59e0b';
        infoEl.style.display = 'block';
      }
    } else {
      secSel.innerHTML = '<option value="">— No sections found for this semester —</option>';
      const infoEl = document.getElementById('tt-sec-source-note');
      if (infoEl) { infoEl.textContent = 'No enrolled students or manual sections found for this semester.'; infoEl.style.color='#ef4444'; infoEl.style.display='block'; }
    }
  } else {
    secSel.innerHTML = '<option value="">— Select Semester first —</option>';
    const infoEl = document.getElementById('tt-sec-source-note');
    if (infoEl) infoEl.style.display = 'none';
  }
}

// ── Load/create timetable modal ───────────────────────────────────────────
function ttDoLoadOrCreate() {
  if (!TT.collegeId) { showNotification('Go back to Step 1 and select a college.', 'warning'); ttGoToStep1(); return; }
  if (!TT.deptId)    { showNotification('Go back to Step 1 and select a department.', 'warning'); ttGoToStep1(); return; }
  if (!TT.semId)     { showNotification('Go back to Step 2 and select a semester.', 'warning'); ttGoToStep2(); return; }
  if (!TT.secId)     { showNotification('Go back to Step 2 and select a section.', 'warning'); ttGoToStep2(); return; }

  // Safely get college name — works for both <select> and hidden input
  const colEl   = document.getElementById('tt-college');
  const colName = (colEl && colEl.tagName === 'SELECT' && colEl.selectedIndex >= 0)
    ? colEl.options[colEl.selectedIndex].text
    : <?= json_encode($saCollegeId ? $saCollegeName : '') ?> || 'College';
  const _deptEl2  = document.getElementById('tt-dept');
  const deptText2 = (_deptEl2 && _deptEl2.tagName === 'SELECT')
    ? (_deptEl2.options[_deptEl2.selectedIndex]?.text || '?')
    : (<?= json_encode($saDeptName ?: '') ?> || '?');
  const semOpt  = document.getElementById('tt-sem')?.options[document.getElementById('tt-sem')?.selectedIndex];
  const secOpt  = document.getElementById('tt-sec')?.options[document.getElementById('tt-sec')?.selectedIndex];
  document.getElementById('tt-create-scope-label').textContent =
    `${colName} › ${deptText2} › ${semOpt?.text||'Sem '+TT.semId} › ${secOpt?.text||'Sec '+TT.secId}`;

  // Pre-fill year
  const now = new Date();
  const yr = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1;
  const yearInput = document.getElementById('tt-create-year');
  if (!yearInput.value) yearInput.value = `${yr}-${String(yr+1).slice(2)}`;
  openModal('tt-modal-create');
}

async function ttDoCreate() {
  if (!TT.collegeId || !TT.deptId || !TT.semId || !TT.secId) {
    showNotification('Selection incomplete — please go back through the steps.', 'warning');
    return;
  }
  const acYear = document.getElementById('tt-create-year').value.trim();

  const r = await ttPost({
    ajax_action: 'tt_create',
    college_id:  TT.collegeId,
    dept_id:     TT.deptId,
    sem_id:      TT.semId,
    sec_id:      TT.secId,
    acad_year:   acYear,
  });

  if (r.ok) {
    TT.ttId = r.tt_id;
    closeModal('tt-modal-create');
    showNotification(r.existing ? 'Timetable loaded! Click any cell to assign.' : 'Timetable created! Click any cell to assign.', 'success');
    const badge = document.getElementById('tt-edit-badge');
    badge.textContent = `#${TT.ttId} · ${acYear||'—'} · draft`;
    badge.className = 'tt-status draft';
    document.getElementById('tt-grid-status').textContent = `Timetable #${TT.ttId}`;
    // Load meta + slots then render grid
    await ttLoadMeta();
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
    ttSetStep(3); // Stay on step 3 (grid panel)
    ttRefreshOverview();
  } else {
    showNotification(r.msg || 'Failed to create timetable. Check all fields.', 'error');
  }
}

// ── Load meta (periods, courses, faculty, rooms) ───────────────────────────
async function ttLoadMeta() {
  const [rp, rc, rf, rr] = await Promise.all([
    ttPost({ ajax_action:'tt_get_periods', college_id:TT.collegeId }),
    ttPost({ ajax_action:'tt_get_courses', college_id:TT.collegeId, dept_id:TT.deptId, sem_id:TT.semId }),
    ttPost({ ajax_action:'tt_get_faculty', college_id:TT.collegeId, dept_id:TT.deptId }),
    ttPost({ ajax_action:'tt_get_rooms',   college_id:TT.collegeId }),
  ]);
  TT.periods = (rp.periods || []).sort((a,b) => a.sort_order - b.sort_order);
  TT.courses = rc.courses || [];
  TT.faculty = rf.faculty || [];
  TT.rooms   = rr.rooms   || [];
}

// ── Render grid ────────────────────────────────────────────────────────────
function ttRenderGrid(slots, wrapId, legendId, editable) {
  if (!TT.periods.length) {
    document.getElementById(wrapId).innerHTML =
      '<div class="tt-empty"><i class="fas fa-clock"></i><p>No periods configured for this college.<br>Add period slots in the Setup section below.</p></div>';
    return;
  }

  // Build slot lookup  [period_id][day]
  // Normalise to Number keys so slotMap[p.id] (number) always hits correctly.
  const slotMap = {};
  slots.forEach(s => {
    const pid = Number(s.period_id);
    (slotMap[pid] = slotMap[pid] || {})[s.day] = s;
  });

  // Course colour map — also normalise to Number keys
  const colorMap = {};
  let ci = 0;
  slots.forEach(s => {
    const cid = Number(s.course_id);
    if (cid && !colorMap[cid]) {
      colorMap[cid] = [TT_COLORS[ci % 8], TT_COLOR_HEX[ci % 8]];
      ci++;
    }
  });

  const today = new Date().toLocaleString('en', { weekday: 'short' });
  let html = `<div class="tt-grid-wrap"><table class="tt-grid">
    <thead><tr>
      <th style="min-width:88px">Time</th>
      ${TT_DAYS.map(d =>
        `<th class="${d === today ? 'today-h' : 'day-h'}">${TT_DAY_FULL[d]}${d===today?'<span style="font-size:.58rem;display:block;color:#10b981">(Today)</span>':''}</th>`
      ).join('')}
    </tr></thead><tbody>`;

  TT.periods.forEach(p => {
    const isBreak = p.type === 'Break', isLunch = p.type === 'Lunch';
    const timeStr = `${p.start_time.slice(0,5)} – ${p.end_time.slice(0,5)}`;
    html += `<tr><td class="tt-time-col">${timeStr}<br><small style="font-size:.57rem">${he(p.label)}</small></td>`;

    if (isBreak) {
      html += `<td class="break-td" colspan="6"><div class="tt-break-lbl"><i class="fas fa-mug-hot"></i> Break — ${ttDur(p.start_time,p.end_time)} min</div></td>`;
    } else if (isLunch) {
      html += `<td class="lunch-td" colspan="6"><div class="tt-lunch-lbl"><i class="fas fa-utensils"></i> Lunch — ${ttDur(p.start_time,p.end_time)} min</div></td>`;
    } else {
      TT_DAYS.forEach(day => {
        const slot = (slotMap[p.id] || {})[day];
        if (slot) {
          const [cc, hex] = colorMap[Number(slot.course_id)] || [TT_COLORS[0], TT_COLOR_HEX[0]];
          const isLab = slot.room_type === 'Lab';
          html += `<td class="filled ${cc}" ${editable ? `onclick="ttOpenSlotModal(${p.id},'${day}',${slot.course_id||0},${slot.faculty_id||0},${slot.room_id||0})"` : ''}>
            <div class="tt-slot-inner">
              <div>
                <div class="tt-s-name">${he(slot.course_name || '?')}</div>
                <div class="tt-s-code">${he(slot.course_code || '')}</div>
              </div>
              <div>
                ${slot.faculty_name ? `<div class="tt-s-fac"><i class="fas fa-user" style="font-size:.55rem"></i> ${he(slot.faculty_name.split(' ').slice(0,2).join(' '))}</div>` : ''}
                ${slot.room_name ? `<div class="tt-s-room"><i class="fas fa-${isLab?'flask':'door-open'}" style="font-size:.56rem${isLab?';color:#f59e0b':''}"></i> ${he(slot.room_name)}</div>` : ''}
              </div>
            </div>
            ${editable ? `<button class="tt-del-btn" onclick="event.stopPropagation();ttDeleteSlot(${p.id},'${day}')" title="Clear"><i class="fas fa-times"></i></button>` : ''}
          </td>`;
        } else {
          html += `<td class="${editable ? 'empty-editable' : 'empty-ro'}" ${editable ? `onclick="ttOpenSlotModal(${p.id},'${day}')"` : ''}>
            ${editable ? '<div class="tt-add-hint"><i class="fas fa-plus-circle"></i></div>' : ''}
          </td>`;
        }
      });
    }
    html += '</tr>';
  });

  html += '</tbody></table></div>';
  document.getElementById(wrapId).innerHTML = html;

  // Legend
  const leg = document.getElementById(legendId);
  let legHtml = '<span style="font-size:.71rem;color:var(--muted);font-weight:600">Courses: </span>';
  const seen = new Set();
  slots.forEach(s => {
    const cid = Number(s.course_id);
    if (cid && !seen.has(cid)) {
      seen.add(cid);
      const [cc, hex] = colorMap[cid] || [TT_COLORS[0], TT_COLOR_HEX[0]];
      legHtml += `<span class="tt-legend-item" style="color:${hex}"><i class="fas fa-square" style="font-size:.65rem"></i>${he(s.course_name)}</span>`;
    }
  });
  leg.innerHTML = legHtml;
  leg.style.display = 'flex';
}

// ── Slot modal ────────────────────────────────────────────────────────────
function ttOpenSlotModal(periodId, day, existCourse=0, existFaculty=0, existRoom=0) {
  if (!TT.ttId) { showNotification('Load or create a timetable first (click "Load / Create Timetable").', 'warning'); return; }
  TT.slotPeriodId = periodId;
  TT.slotDay = day;

  const p = TT.periods.find(x => x.id == periodId);
  document.getElementById('tt-slot-info').innerHTML =
    `<i class="fas fa-circle-info" style="color:var(--teal)"></i> &nbsp;<strong>${TT_DAY_FULL[day]}</strong> — ${p ? p.label + ' (' + p.start_time.slice(0,5) + ' – ' + p.end_time.slice(0,5) + ')' : ''}`;

  // Populate courses filtered by dept
  const cSel = document.getElementById('tt-slot-course');
  cSel.innerHTML = '<option value="">— Select Course —</option>';
  if (TT.courses.length) {
    TT.courses.forEach(c => cSel.innerHTML += `<option value="${c.id}"${c.id==existCourse?' selected':''}>${he(c.name)} (${he(c.code)})</option>`);
  } else {
    cSel.innerHTML = '<option value="">— No courses for this dept —</option>';
  }

  // Populate faculty filtered by dept
  const fSel = document.getElementById('tt-slot-faculty');
  fSel.innerHTML = '<option value="">— Select Faculty —</option>';
  if (TT.faculty.length) {
    TT.faculty.forEach(f => fSel.innerHTML += `<option value="${f.id}"${f.id==existFaculty?' selected':''}>${he(f.full_name)}</option>`);
  } else {
    fSel.innerHTML = '<option value="">— No faculty for this dept —</option>';
  }

  // Populate rooms (all rooms for the college)
  const rSel = document.getElementById('tt-slot-room');
  rSel.innerHTML = '<option value="">— Select Room —</option>';
  if (TT.rooms.length) {
    TT.rooms.forEach(r => rSel.innerHTML += `<option value="${r.id}"${r.id==existRoom?' selected':''}>${he(r.name)} (${he(r.type)})</option>`);
  } else {
    rSel.innerHTML = '<option value="">— No rooms configured —</option>';
  }

  openModal('tt-modal-slot');
}

async function ttSaveSlot() {
  if (!TT.ttId) { showNotification('No timetable loaded. Create one first.', 'error'); return; }
  const courseId  = document.getElementById('tt-slot-course').value;
  const facultyId = document.getElementById('tt-slot-faculty').value;
  const roomId    = document.getElementById('tt-slot-room').value;
  if (!courseId) { showNotification('Please select a course before saving.', 'warning'); return; }
  const r = await ttPost({
    ajax_action: 'tt_save_slot', tt_id: TT.ttId,
    period_id:  TT.slotPeriodId, day: TT.slotDay,
    course_id:  courseId,
    faculty_id: facultyId,
    room_id:    roomId,
  });
  if (r.ok) {
    // Reload grid FIRST, then close modal — prevents the overlay transition
    // (z-index:2000, pointer-events:all for 0.25s) from visually blocking the repaint.
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
    closeModal('tt-modal-slot');
    showNotification('Slot saved!', 'success');
  } else showNotification(r.msg || 'Error saving slot', 'error');
}

async function ttClearSlot() {
  if (!TT.ttId) return;
  const r = await ttPost({ ajax_action:'tt_clear_slot', tt_id:TT.ttId, period_id:TT.slotPeriodId, day:TT.slotDay });
  if (r.ok) {
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
    closeModal('tt-modal-slot');
    showNotification('Slot cleared.', 'warning');
  } else showNotification(r.msg || 'Error', 'error');
}

async function ttDeleteSlot(periodId, day) {
  if (!TT.ttId) return;
  const r = await ttPost({ ajax_action:'tt_clear_slot', tt_id:TT.ttId, period_id:periodId, day });
  if (r.ok) {
    showNotification('Slot cleared.', 'warning');
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
  } else showNotification(r.msg || 'Error', 'error');
}

// ── Activate & clear all ──────────────────────────────────────────────────
async function ttActivate() {
  if (!TT.ttId) { showNotification('Load a timetable first.', 'warning'); return; }
  const r = await ttPost({ ajax_action:'tt_activate', tt_id:TT.ttId });
  if (r.ok) {
    showNotification('Timetable activated!', 'success');
    const badge = document.getElementById('tt-edit-badge');
    badge.textContent = badge.textContent.replace(/draft|active/, 'active');
    badge.className = 'tt-status active';
  } else showNotification(r.msg||'Error','error');
}

async function ttDoClearAll() {
  if (!TT.ttId) return;
  const r = await ttPost({ ajax_action:'tt_clear_all', tt_id:TT.ttId });
  if (r.ok) {
    closeModal('tt-modal-clear');
    showNotification('All slots cleared.','warning');
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
  } else showNotification(r.msg||'Error','error');
}

// ── Auto-generate ─────────────────────────────────────────────────────────
async function ttAutoGen() {
  if (!TT.ttId) { showNotification('Load or create a timetable first.','warning'); return; }
  showNotification('Auto-generating timetable…','warning');
  const r = await ttPost({ ajax_action:'tt_auto_generate', tt_id:TT.ttId, college_id:TT.collegeId, dept_id:TT.deptId });
  if (r.ok) {
    showNotification('Auto-generated successfully!','success');
    const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id: TT.ttId });
    ttRenderGrid(lr.slots || [], 'tt-edit-wrap', 'tt-edit-legend', true);
  } else showNotification(r.msg||'Error','error');
}

// ── View timetable (read-only modal) ──────────────────────────────────────
async function ttViewTimetable(ttId, deptId, semId, secId, deptName, semName, secLabel) {
  openModal('tt-modal-view');
  document.getElementById('tt-view-title').textContent = `${deptName || 'Timetable'} — ${semName || ''}`;
  document.getElementById('tt-view-scope').innerHTML =
    `<strong>${he(deptName)}</strong> &rsaquo; ${he(semName)} &rsaquo; <strong style="color:var(--teal)">Section ${he(secLabel)}</strong>`;
  document.getElementById('tt-view-wrap').innerHTML =
    '<div class="tt-empty"><i class="fas fa-spinner fa-spin"></i><p>Loading…</p></div>';
  document.getElementById('tt-view-legend').style.display = 'none';

  // Need TT.periods and TT.courses loaded for the render; use a temp copy of TT
  const prevDeptId = TT.deptId, prevSemId = TT.semId;
  TT.deptId = parseInt(deptId) || TT.deptId;
  TT.semId  = parseInt(semId)  || TT.semId;
  await ttLoadMeta();
  TT.deptId = prevDeptId; TT.semId = prevSemId;

  const lr = await ttPost({ ajax_action: 'tt_load_slots', tt_id: ttId });
  ttRenderGrid(lr.slots || [], 'tt-view-wrap', 'tt-view-legend', false);
}

// ── Quick-load from overview table ────────────────────────────────────────
async function ttQuickLoad(ttId, deptId, semId, secId) {
  TT.ttId   = ttId;
  if (deptId) { TT.deptId = parseInt(deptId); document.getElementById('tt-dept').value = TT.deptId; }
  if (semId)  { TT.semId  = parseInt(semId); }
  if (secId)  { TT.secId  = parseInt(secId); }
  // Load meta with restored dept context so faculty/courses are dept-filtered
  await ttLoadMeta();
  const lr = await ttPost({ ajax_action:'tt_load_slots', tt_id:ttId });
  ttRenderGrid(lr.slots||[], 'tt-edit-wrap', 'tt-edit-legend', true);
  const badge = document.getElementById('tt-edit-badge');
  badge.textContent = `#${ttId} — loaded. Click a cell to assign.`;
  badge.className = 'tt-status active';
  document.getElementById('tt-grid-status').textContent = `Timetable #${ttId}`;
  const _selInfo2 = document.getElementById('tt-selection-info');
  if (_selInfo2) { _selInfo2.style.display = 'none'; } // loaded from overview, no step2 labels
  ttSetStep(3);
  showNotification(`Timetable #${ttId} loaded. Click any empty cell to assign.`, 'success');
}

// ── Overview table ─────────────────────────────────────────────────────────
async function ttRefreshOverview() {
  if (!TT.collegeId) return;
  const tbody = document.getElementById('tt-overview-tbody');
  tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
  const deptFilt = parseInt(document.getElementById('tt-dept').value) || 0;
  const r = await ttPost({ ajax_action:'tt_list', college_id:TT.collegeId, dept_id:deptFilt });
  const rows = r.timetables || [];
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8"><div class="tt-empty"><i class="fas fa-calendar-xmark"></i><p>No timetables found for this college.</p></div></td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((t, i) => `
    <tr>
      <td style="color:var(--muted);font-size:0.78rem">${i+1}</td>
      <td style="font-weight:600;color:var(--text)">${he(t.dept_name)} <span style="font-family:monospace;font-size:0.72rem;color:var(--teal)">${he(t.dept_code)}</span></td>
      <td style="font-size:0.82rem">${he(t.sem_name)}</td>
      <td style="font-family:monospace;color:var(--teal)">Sec ${he(t.sec_label)}</td>
      <td style="font-size:0.8rem;color:var(--muted)">${he(t.academic_year||'—')}</td>
      <td style="text-align:center"><span style="font-weight:700;color:${parseInt(t.slot_count)>0?'#10b981':'var(--muted)'}">${t.slot_count}</span></td>
      <td style="text-align:center"><span class="tt-status ${t.status}">${t.status}</span></td>
      <td style="text-align:center">
        <button class="btn-secondary" style="padding:5px 10px;font-size:0.74rem" onclick="ttViewTimetable(${t.id},${t.department_id},${t.semester_id},${t.section_id},'${he(t.dept_name)}','${he(t.sem_name)}','${he(t.sec_label)}')">
          <i class="fas fa-eye"></i> View
        </button>
        <button class="btn-secondary" style="padding:5px 10px;font-size:0.74rem;margin-left:4px" onclick="ttQuickLoad(${t.id},${t.department_id},${t.semester_id},${t.section_id})">
          <i class="fas fa-pen-to-square"></i> Edit
        </button>
      </td>
    </tr>
  `).join('');
}

// ── Setup helpers ─────────────────────────────────────────────────────────
async function ttSetupSemLoaded() {
  const semSel = document.getElementById('tts-sec-sem');
  semSel.innerHTML = '<option value="">— Select Semester —</option>';
  if (!TT.collegeId) return;
  const r = await ttPost({ ajax_action:'tt_get_semesters', college_id:TT.collegeId, dept_id:TT.deptId });
  (r.semesters||[]).forEach(s => semSel.innerHTML += `<option value="${s.id}">${he(s.name)}</option>`);
}

async function ttAddSemester() {
  if (!TT.collegeId) { showNotification('Select a college first.','warning'); return; }
  const r = await ttPost({
    ajax_action:'tt_add_semester', college_id:TT.collegeId,
    name:       document.getElementById('tts-sem-name').value,
    start_date: document.getElementById('tts-sem-start').value,
    end_date:   document.getElementById('tts-sem-end').value,
    status:     document.getElementById('tts-sem-status').value,
  });
  if (r.ok) {
    showNotification('Semester added!','success');
    document.getElementById('tts-sem-name').value = '';
    ttSetupSemLoaded();
  } else showNotification(r.msg||'Error','error');
}

async function ttAddSection() {
  if (!TT.collegeId) { showNotification('Select a college first.','warning'); return; }
  const r = await ttPost({
    ajax_action:'tt_add_section', college_id:TT.collegeId,
    dept_id:  document.getElementById('tts-sec-dept').value,
    sem_id:   document.getElementById('tts-sec-sem').value,
    label:    document.getElementById('tts-sec-label').value,
    strength: document.getElementById('tts-sec-strength').value,
  });
  if (r.ok) {
    showNotification('Section added!','success');
    document.getElementById('tts-sec-label').value = '';
  } else showNotification(r.msg||'Error','error');
}

async function ttAddPeriod() {
  if (!TT.collegeId) { showNotification('Select a college first.','warning'); return; }
  const r = await ttPost({
    ajax_action:'tt_add_period', college_id:TT.collegeId,
    label:      document.getElementById('tts-per-label').value,
    type:       document.getElementById('tts-per-type').value,
    start_time: document.getElementById('tts-per-start').value,
    end_time:   document.getElementById('tts-per-end').value,
    sort_order: document.getElementById('tts-per-sort').value,
  });
  if (r.ok) {
    showNotification('Period added!','success');
    ['tts-per-label','tts-per-start','tts-per-end','tts-per-sort'].forEach(id => document.getElementById(id).value='');
  } else showNotification(r.msg||'Error','error');
}

async function ttAddRoom() {
  if (!TT.collegeId) { showNotification('Select a college first.','warning'); return; }
  const r = await ttPost({
    ajax_action:'tt_add_room', college_id:TT.collegeId,
    name:     document.getElementById('tts-room-name').value,
    type:     document.getElementById('tts-room-type').value,
    block:    document.getElementById('tts-room-block').value,
    capacity: document.getElementById('tts-room-capacity').value,
  });
  if (r.ok) {
    showNotification('Room added!','success');
    ['tts-room-name','tts-room-block','tts-room-capacity'].forEach(id => document.getElementById(id).value='');
  } else showNotification(r.msg||'Error','error');
}

// ── When timetable view opened ────────────────────────────────────────────
(function() {
  const base = window.switchView;
  window.switchView = function(name) {
    base(name);
    if (name === 'timetable') {
      ttSetupSemLoaded();
    }
    if (name === 'exam-management') {
      emSwitchTab('wizard');
      saInitExamView();
      // Ensure college state is always in sync with the dropdown
      if (!emState.college) emInitCollegeFromDropdown();
    }
  };
})();

// ── Helpers ───────────────────────────────────────────────────────────────
function ttDur(s, e) {
  const [sh,sm] = s.split(':').map(Number);
  const [eh,em] = e.split(':').map(Number);
  return (eh*60+em) - (sh*60+sm);
}
function he(s) {
  return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Show dashboard on page load
switchView('dashboard');

// Auto-load accountant credentials when principal navigates to that view
(function() {
  var _sv = window.switchView;
  window.switchView = function(name) {
    _sv(name);
    if (name === 'accountant-credentials') {
      var sel = document.getElementById('accCredCollegeFilter');
      if (sel && sel.value) {
        // Small delay so the panel becomes visible first
        setTimeout(loadAccCredentials, 120);
      }
    }
  };
})();
</script>
<script>
// ── Edit Faculty Assignment ──────────────────────────────────────────────────

async function openEditAssignment(a) {
  // Populate hidden id
  document.getElementById('ea_id').value = a.assignment_id;

  // Clear dependent dropdowns first
  document.getElementById('ea_dept').innerHTML   = '<option value="">— Loading… —</option>';
  document.getElementById('ea_course').innerHTML = '<option value="">— Select Course —</option>';

  // Pre-fill static fields immediately
  document.getElementById('ea_faculty').value       = String(a.faculty_id);
  document.getElementById('ea_academic_year').value = a.academic_year || '2025-26';
  document.getElementById('ea_semester').value      = a.semester || '';
  document.getElementById('ea_is_primary').value    = String(a.is_primary);
  document.getElementById('ea_status').value        = a.status || 'active';
  document.getElementById('ea_remarks').value       = a.remarks || '';

  // Set period dropdown
  const periodSel = document.getElementById('ea_period');
  periodSel.value = a.period || 'All Periods (Daily)';

  // Set college, then load departments, then pre-select dept, then load & select course
  const collegeSel = document.getElementById('ea_college');
  collegeSel.value = String(a.college_id);

  await loadDepts('ea_dept', a.college_id);

  const deptSel = document.getElementById('ea_dept');
  deptSel.value = String(a.department_id);

  await loadEditCoursesForDept(a.department_id);

  document.getElementById('ea_course').value = String(a.course_id);

  showAlert('alertEditAssignment', '', '');   // clear any previous alert
  openModal('modalEditAssignment');
}

async function onEditCollegeChange(collegeId) {
  document.getElementById('ea_dept').innerHTML   = '<option value="">— Select Department —</option>';
  document.getElementById('ea_course').innerHTML = '<option value="">— Select Course —</option>';
  if (!collegeId) return;
  await loadDepts('ea_dept', collegeId);
}

async function loadEditCoursesForDept(deptId) {
  const courseSelect = document.getElementById('ea_course');
  courseSelect.innerHTML = '<option value="">— Select Course —</option>';
  if (!deptId) return;
  const r = await postAction({ ajax_action: 'get_courses', department_id: deptId });
  if (r.ok && r.courses) {
    r.courses.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = `${c.name} (${c.code})`;
      opt.dataset.semester = c.semester || '';
      courseSelect.appendChild(opt);
    });
  }
}

function updateEditSemesterFromCourse(select) {
  const sem = select.options[select.selectedIndex]?.dataset?.semester;
  if (sem) document.getElementById('ea_semester').value = sem;
}

async function submitEditAssignment() {
  const btn      = document.getElementById('btnEditAssignment');
  const id       = document.getElementById('ea_id').value;
  const faculty  = document.getElementById('ea_faculty').value;
  const college  = document.getElementById('ea_college').value;
  const dept     = document.getElementById('ea_dept').value;
  const course   = document.getElementById('ea_course').value;

  if (!id || !faculty || !college || !dept || !course) {
    showAlert('alertEditAssignment', 'Faculty, College, Department and Course are required.');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const r = await postAction({
    ajax_action:   'update_faculty_assignment',
    id:            id,
    faculty_id:    faculty,
    college_id:    college,
    department_id: dept,
    course_id:     course,
    semester:      document.getElementById('ea_semester').value,
    academic_year: document.getElementById('ea_academic_year').value,
    period:        document.getElementById('ea_period').value,
    is_primary:    document.getElementById('ea_is_primary').value,
    status:        document.getElementById('ea_status').value,
    remarks:       document.getElementById('ea_remarks').value,
  });

  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';

  if (r.ok) {
    showAlert('alertEditAssignment', '✓ Assignment updated successfully!', 'success');
    setTimeout(() => {
      closeModal('modalEditAssignment');
      loadFacultyAssignments();
      // Refresh faculty tab if it's open so college/dept columns reflect any change
      if (document.getElementById('view-faculty') &&
          !document.getElementById('view-faculty').classList.contains('hidden')) {
        loadSection('faculty');
      }
    }, 1100);
  } else {
    showAlert('alertEditAssignment', r.msg || 'Update failed.');
  }
}

// ═══════════════════════════════════════════════════════════════════
//  EXAM MANAGEMENT — Superadmin JS Functions
// ═══════════════════════════════════════════════════════════════════

// ── Create Exam Modal helpers ─────────────────────────────────────────────────
async function ceCollegeChange() {
    const cid = document.getElementById('ce_college').value;
    document.getElementById('ce_dept').innerHTML = '<option value="">— Select Dept —</option>';
    document.getElementById('ce_course').innerHTML = '<option value="">— Select Course —</option>';
    if (!cid) return;
    const r = await postAction({ajax_action:'get_departments', college_id:cid});
    if (r.ok && r.departments) {
        r.departments.forEach(d => {
            document.getElementById('ce_dept').innerHTML +=
                `<option value="${d.id}">${d.name} (${d.code})</option>`;
        });
    }
}
async function ceDeptChange() {
    const did = document.getElementById('ce_dept').value;
    document.getElementById('ce_course').innerHTML = '<option value="">— Select Course —</option>';
    if (!did) return;
    const r = await postAction({ajax_action:'get_courses', department_id:did});
    if (r.ok && r.courses) {
        r.courses.forEach(c => {
            document.getElementById('ce_course').innerHTML +=
                `<option value="${c.id}" data-sem="${c.semester||''}">${c.name} (${c.code})</option>`;
        });
    }
}
function ceCourseChange(sel) {
    const sem = sel.options[sel.selectedIndex]?.dataset?.sem;
    if (sem) document.getElementById('ce_sem').value = sem;
}
async function submitCreateExam() {
    const btn = document.getElementById('btnCreateExam');
    const title      = document.getElementById('ce_title').value.trim();
    const college_id = document.getElementById('ce_college').value;
    const dept_id    = document.getElementById('ce_dept').value;
    const course_id  = document.getElementById('ce_course').value;
    const exam_date  = document.getElementById('ce_date').value;
    if (!title || !college_id || !dept_id || !course_id || !exam_date) {
        showAlert('alertCreateExam', 'Title, College, Department, Course and Date are required.');
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
    const r = await postAction({
        ajax_action:   'sa_create_exam',
        title,
        college_id,
        department_id: dept_id,
        course_id,
        exam_date,
        type:          document.getElementById('ce_type').value,
        max_marks:     document.getElementById('ce_max').value,
        pass_marks:    document.getElementById('ce_pass').value,
        semester:      document.getElementById('ce_sem').value,
        academic_year: document.getElementById('ce_year').value,
    });
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-plus"></i> Create Exam';
    if (r?.ok) {
        showAlert('alertCreateExam', '✓ Exam created (ID: ' + r.exam_id + ')! Loading exam list…', 'success');
        setTimeout(() => { closeModal('modalCreateExam'); saLoadExams(); }, 1400);
    } else {
        showAlert('alertCreateExam', r?.msg || 'Unknown error');
    }
}

// ── Populate hall dropdowns by college ─────────────────────────────────────────
async function saPopulateHalls(college_id) {
    const hallOpt = halls => '<option value="">Choose hall…</option>' +
        halls.map(h => `<option value="${h.id}">${h.name} (cap: ${h.capacity})</option>`).join('');
    const r = await postAction({ajax_action:'get_exam_halls', college_id: college_id||''});
    if (!r?.ok) return;
    ['saSchedHall','saInvigHall','saSeatHall'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = hallOpt(r.halls);
    });
}

// Notification helper (used by timetable + exam sections)
function showNotification(msg, type='success') {
  let toast = document.getElementById('notif-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'notif-toast';
    toast.className = 'notification-toast';
    toast.innerHTML = '<i class="fas fa-circle-check"></i><span id="notif-msg"></span>';
    document.body.appendChild(toast);
  }
  toast.className = 'notification-toast ' + type;
  const icon = type==='success'?'circle-check':type==='warning'?'triangle-exclamation':'circle-xmark';
  toast.querySelector('i').className = `fas fa-${icon}`;
  document.getElementById('notif-msg').textContent = msg;
  toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.classList.remove('show'), 3200);
}
// Alias used throughout exam wizard
const showToast = showNotification;

// Cascade dept filter when college changes in exam view
function saExamCollegeChange() {
  const cid = document.getElementById('saExamCollege').value;
  const deptSel = document.getElementById('saExamDept');
  Array.from(deptSel.options).forEach(o => {
    if (!o.value) return;
    o.style.display = (!cid || o.dataset.college === cid) ? '' : 'none';
  });
  if (cid && deptSel.value && deptSel.options[deptSel.selectedIndex]?.dataset?.college !== cid) {
    deptSel.value = '';
  }
  saPopulateHalls(cid);
  saLoadExams();
}

// Populate invigilator faculty select from all faculty
async function saInitExamView() {
    await Promise.all([saPopulateFaculty(), saPopulateHalls(''), saLoadExams()]);
}

async function saPopulateFaculty() {
  const facSel = document.getElementById('saInvigFaculty');
  if (!facSel || facSel.options.length > 1) return;
  const r = await postAction({ajax_action:'get_faculty', college_id:''});
  if (r?.faculty) {
    facSel.innerHTML = '<option value="">Choose faculty…</option>' +
      r.faculty.map(f => `<option value="${f.id}">${f.full_name}${f.designation?' – '+f.designation:''}</option>`).join('');
  }
}

async function saLoadExams() {
    const wrap = document.getElementById('saExamListWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div style="padding:12px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    const college_id = document.getElementById('saExamCollege')?.value || '';
    const department_id = document.getElementById('saExamDept')?.value || '';
    const r = await postAction({ajax_action:'sa_list_exams', college_id, department_id});
    if (!r?.ok || !r.exams?.length) {
        wrap.innerHTML = '<div style="padding:12px;color:var(--muted)">No exams found.</div>';
        return;
    }
    // Also populate schedule selects
    // sa_list_exams returns exam id (e.id) but saInvigSched/saSeatSched need the real schedule id.
    // Fetch real schedule ids for scheduled exams so invig/seating assignment works correctly.
    const scheduledExams = r.exams.filter(e => e.sched_date);
    const schedMap = {};
    await Promise.all(scheduledExams.map(async e => {
        const sr = await postAction({ajax_action:'sa_get_schedules_for_exam', exam_id: e.id});
        if (sr?.ok && sr.schedules?.length) schedMap[e.id] = sr.schedules[0].id;
    }));
    const schedOpts = '<option value="">Choose schedule…</option>' +
        scheduledExams.filter(e => schedMap[e.id]).map(e =>
            `<option value="${schedMap[e.id]}">${e.title} — ${e.sched_date} ${e.start_time?.slice(0,5)||''}</option>`
        ).join('');
    const examOpts = '<option value="">Choose exam…</option>' + r.exams.map(e=>`<option value="${e.id}">${e.title} (${e.course_code})</option>`).join('');
    ['saSchedExam'].forEach(id => { const el=document.getElementById(id); if(el) el.innerHTML = examOpts; });
    ['saInvigSched','saSeatSched'].forEach(id => { const el=document.getElementById(id); if(el) el.innerHTML = schedOpts; });
    // Populate halls based on selected college
    const cid = document.getElementById('saExamCollege')?.value || '';
    saPopulateHalls(cid);

    let html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.8rem"><thead><tr style="border-bottom:1px solid var(--border)">';
    ['Exam','Course','Dept','College','Scheduled','Hall','Admits','Invigs','Status'].forEach(h => {
        html += `<th style="text-align:left;padding:8px 10px;color:var(--muted);font-weight:600">${h}</th>`;
    });
    html += '</tr></thead><tbody>';
    r.exams.forEach(e => {
        html += `<tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px 10px"><strong style="color:var(--text)">${e.title}</strong><br><span style="color:var(--muted);font-size:.7rem">Sem ${e.semester||'—'} · ${e.academic_year||''}</span></td>
            <td style="padding:8px 10px">${e.course_name}<br><span style="color:var(--muted);font-size:.7rem;font-family:monospace">${e.course_code}</span></td>
            <td style="padding:8px 10px;color:var(--muted);font-size:.78rem">${e.dept_name}</td>
            <td style="padding:8px 10px;color:var(--muted);font-size:.78rem">${e.college_name}</td>
            <td style="padding:8px 10px;font-size:.78rem">${e.sched_date||'<span style="color:var(--muted)">—</span>'}${e.start_time?`<br><span style="color:var(--muted)">${e.start_time.slice(0,5)}–${e.end_time?.slice(0,5)}</span>`:''}</td>
            <td style="padding:8px 10px;font-size:.78rem">${e.hall_name||'<span style="color:var(--muted)">—</span>'}</td>
            <td style="padding:8px 10px;text-align:center">${e.admit_cards_issued||0}</td>
            <td style="padding:8px 10px;text-align:center">${e.invig_count||0}</td>
            <td style="padding:8px 10px"><span style="padding:2px 8px;border-radius:6px;font-size:.7rem;background:rgba(0,212,187,.1);color:var(--teal)">${e.status}</span></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
}

async function saLoadHallsFor(selIds) {
    const college_id = document.getElementById('saExamCollege')?.value || '';
    const r = await postAction({ajax_action:'get_exam_halls', college_id});
    if (!r?.ok || !r.halls) return;
    const hallOpts = '<option value="">Choose hall…</option>' +
        r.halls.map(h => `<option value="${h.id}">${h.name} (cap: ${h.capacity})</option>`).join('');
    selIds.forEach(id => { const el = document.getElementById(id); if (el) el.innerHTML = hallOpts; });
}

function openSACreateExamModal() {
    // Reset fields
    ['ce_title','ce_date','ce_sem','ce_year'].forEach(id => {
        const el = document.getElementById(id);
        if (el && id==='ce_year') el.value='2025-26';
        else if (el && id==='ce_sem') el.value='1';
        else if (el) el.value='';
    });
    ['ce_college','ce_dept','ce_course'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value='';
    });
    document.getElementById('ce_dept').innerHTML = '<option value="">— Select Dept —</option>';
    document.getElementById('ce_course').innerHTML = '<option value="">— Select Course —</option>';
    // Pre-select college/dept from current SA filters
    const colVal = document.getElementById('saExamCollege')?.value;
    const deptVal = document.getElementById('saExamDept')?.value;
    if (colVal) {
        document.getElementById('ce_college').value = colVal;
        ceCollegeChange().then(() => {
            if (deptVal) {
                document.getElementById('ce_dept').value = deptVal;
                ceDeptChange();
            }
        });
    }
    document.getElementById('alertCreateExam').className = 'form-alert';
    document.getElementById('alertCreateExam').textContent = '';
    openModal('modalCreateExam');
}


async function saAssignSchedule() {
    const exam_id = document.getElementById('saSchedExam')?.value;
    const hall_id = document.getElementById('saSchedHall')?.value;
    const exam_date = document.getElementById('saSchedDate')?.value;
    const start_time = document.getElementById('saSchedStart')?.value;
    const end_time = document.getElementById('saSchedEnd')?.value;
    const semester = document.getElementById('saSchedSem')?.value||'1';
    if (!exam_id||!hall_id||!exam_date||!start_time||!end_time) { showNotification('All schedule fields required', 'warning'); return; }
    const r = await postAction({ajax_action:'sa_assign_schedule',exam_id,hall_id,exam_date,start_time,end_time,semester,academic_year:'2025-26'});
    if (r?.ok) { showNotification('✓ ' + r.msg, 'success'); saLoadExams(); }
    else showNotification(r?.msg||'Error', 'error');
}

async function saAssignInvig() {
    const schedule_id = document.getElementById('saInvigSched')?.value;
    const faculty_id = document.getElementById('saInvigFaculty')?.value;
    const hall_id = document.getElementById('saInvigHall')?.value||'0';
    const duty_type = document.getElementById('saInvigDuty')?.value||'assistant';
    if (!schedule_id||!faculty_id) { showNotification('Schedule and faculty required', 'warning'); return; }
    const r = await postAction({ajax_action:'sa_assign_invig',schedule_id,faculty_id,hall_id,duty_type});
    if (r?.ok) { showNotification('✓ ' + r.msg, 'success'); }
    else showNotification(r?.msg||'Error', 'error');
}

async function saAssignSeating() {
    const schedule_id = document.getElementById('saSeatSched')?.value;
    const hall_id = document.getElementById('saSeatHall')?.value;
    if (!schedule_id||!hall_id) { showNotification('Schedule and hall required', 'warning'); return; }
    if (!confirm('Auto-assign all active students in this dept to seats?')) return;
    const r = await postAction({ajax_action:'sa_assign_seating',schedule_id,hall_id});
    if (r?.ok) { showNotification('✓ ' + r.msg, 'success'); }
    else showNotification(r?.msg||'Error', 'error');
}

async function saIssueAdmits() {
    const schedule_id = document.getElementById('saSeatSched')?.value;
    if (!schedule_id) { showNotification('Select a schedule first', 'warning'); return; }
    if (!confirm('Issue admit cards for all seated students?')) return;
    const r = await postAction({ajax_action:'sa_issue_admits',schedule_id});
    if (r?.ok) { showNotification('✓ ' + r.msg, 'success'); }
    else showNotification(r?.msg||'Error', 'error');
}

async function saLoadPendingQPapers() {
    const wrap = document.getElementById('saPendingQPapersWrap');
    if (!wrap) return;
    wrap.innerHTML = '<p style="color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
    // HOD: use injected dept/college; SA: use the college dropdown
    const college_id = emIsHod
        ? (window._currentCollegeId || '')
        : (document.getElementById('saExamCollege')?.value || '');
    const dept_id = emIsHod ? (emHodDeptId || '') : '';
    const r = await postAction({ajax_action:'sa_pending_qpapers', college_id, dept_id});
    if (!r?.ok || !r.papers?.length) {
        wrap.innerHTML = '<p style="color:var(--muted)">No pending Q.Papers.</p>'; return;
    }
    let html = '<div style="display:flex;flex-direction:column;gap:10px">';
    r.papers.forEach(p => {
        html += `<div style="padding:14px;background:var(--bg-card,rgba(14,29,47,.9));border:1px solid rgba(245,158,11,.2);border-radius:10px">
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div style="flex:1">
                    <strong style="color:var(--text)">${p.title}</strong> <span style="font-size:.7rem;color:var(--muted)">v${p.version}</span><br>
                    <span style="font-size:.75rem;color:var(--muted)">${p.exam_title} · ${p.course_name} (${p.course_code}) · ${p.dept_name} · ${p.college_name}</span><br>
                    <span style="font-size:.72rem;color:var(--muted)">By: ${p.created_by_name} · ${p.created_at} · ${p.total_marks} marks</span>
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn-add btn-sm" onclick="saApproveQPaper(${p.id},'approve')"><i class="fas fa-check"></i> Approve</button>
                    <button class="btn-secondary btn-sm" onclick="saApproveQPaper(${p.id},'reject')"><i class="fas fa-times"></i> Send Back</button>
                </div>
            </div>
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

async function saLoadInvigilators() {
    const wrap = document.getElementById('saInvigilatorsWrap');
    if (!wrap) return;
    wrap.style.display = 'block';
    const sched_id = document.getElementById('saInvigSched')?.value || '';
    if (!sched_id) { wrap.innerHTML = '<p style="color:var(--muted);font-size:.8rem">Select a schedule above to view assigned invigilators.</p>'; return; }
    wrap.innerHTML = '<p style="color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
    const r = await postAction({ajax_action: 'sa_get_invigilators', schedule_id: sched_id});
    if (!r?.ok || !r.invigilators?.length) {
        wrap.innerHTML = '<p style="color:var(--muted);font-size:.8rem">No invigilators assigned for this schedule yet.</p>';
        return;
    }
    const dutyLabel = d => d === 'chief' ? 'Chief Invigilator' : d === 'flying_squad' ? 'Flying Squad' : 'Assistant';
    wrap.innerHTML = '<div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">' +
        r.invigilators.map(inv => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(20,184,166,.05);border:1px solid rgba(20,184,166,.2);border-radius:var(--radius-sm)">
            <div>
                <strong style="color:var(--text);font-size:.85rem">${htmlEscape(inv.full_name)}</strong>
                <span style="color:var(--muted);font-size:.72rem;margin-left:8px">${htmlEscape(inv.designation||'')}</span>
                <div style="font-size:.72rem;color:var(--muted)">${htmlEscape(inv.email)}</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span style="padding:2px 10px;border-radius:50px;font-size:.72rem;font-weight:700;background:rgba(20,184,166,.12);color:var(--teal)">${dutyLabel(inv.duty_type)}</span>
                <button class="btn-secondary" style="padding:4px 10px;font-size:.72rem;color:#ef4444;border-color:rgba(239,68,68,.3)"
                    onclick="saRemoveInvig(${inv.schedule_id}, ${inv.faculty_id})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>`).join('') +
    '</div>';
}

async function saRemoveInvig(schedule_id, faculty_id) {
    if (!confirm('Remove this invigilator?')) return;
    const r = await postAction({ajax_action:'sa_remove_invig', schedule_id, faculty_id});
    if (r?.ok) { showNotification('Invigilator removed', 'success'); saLoadInvigilators(); }
    else showNotification(r?.msg||'Error', 'error');
}

async function saApproveQPaper(id, decision) {
    if (!confirm(decision==='approve'?'Approve this Q.Paper?':'Send back to draft?')) return;
    const r = await postAction({ajax_action:'sa_approve_qpaper',id,decision});
    if (r?.ok) { showNotification('✓ ' + r.msg, 'success'); saLoadPendingQPapers(); }
    else showNotification(r?.msg||'Error', 'error');
}

// ══════════════════════════════════════════════════════════════
// EXAM MANAGEMENT WIZARD — JS (College→Dept→Subject→Room→Seating→Invigilators→Summary)
// ══════════════════════════════════════════════════════════════

// Wizard state
const emState = {
  step: 1,
  college: null, collegeName: '', collegeCode: '',
  dept: null, deptName: '', deptCode: '',
  course: null, courseName: '', courseCode: '', courseSem: '',
  hall: null, hallName: '', hallCapacity: 60, hallRows: 6, hallCols: 10,
  scheduleId: null, examId: null, examTitle: '',
  examType: 'mid_term', examDate: '', examMaxMarks: 100, examPassMarks: 40,
  examSem: null, examAcadYear: '2025-26',
  students: [], seats: {}, invigilators: {}, facultyCache: {}
};

// PHP-injected HOD context (empty/0 for non-HOD roles)
const emHodDeptId   = <?= (int)$saDeptId ?>;
const emHodDeptName = <?= json_encode($saDeptName) ?>;
const emHodDeptCode = <?= json_encode('') ?>;
const emIsHod       = <?= ($saCollegeRole === 'hod' && $saDeptId) ? 'true' : 'false' ?>;
const emIsPrincipal = <?= ($saCollegeRole === 'principal') ? 'true' : 'false' ?>;
const emExamReadOnly = emIsPrincipal; // Principal: view-only exam list; HOD has full access

function emSwitchTab(tab) {
  ['wizard','list','qpaper','conflicts','halls'].forEach(t => {
    const p = document.getElementById('emPanel-'+t);
    const b = document.getElementById('emTab-'+t);
    if (p) p.style.display = t===tab?'':'none';
    if (b) { b.style.borderColor = t===tab?'rgba(20,184,166,.4)':''; b.style.color = t===tab?'var(--teal)':''; }
  });
}

function emUpdateStepBar() {
  for (let i=1;i<=7;i++) {
    const dot  = document.getElementById('emStepNum-'+i);
    const step = document.getElementById('emStep-'+i);
    if (!step) continue;
    step.classList.remove('active');
    if (i < emState.step) {
      step.classList.add('active');
      if (dot) dot.innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
      step.style.background='rgba(20,184,166,.08)'; step.style.borderColor='rgba(20,184,166,.25)'; step.style.color='var(--teal)';
    } else if (i === emState.step) {
      step.classList.add('active');
      if (dot) dot.textContent = i;
      step.style.background=''; step.style.borderColor=''; step.style.color='';
    } else {
      if (dot) dot.textContent = i;
      step.style.background=''; step.style.borderColor='transparent'; step.style.color='var(--muted)';
    }
  }
}

function emUpdateSelBar() {
  const bar = document.getElementById('emSelBar');
  if (!bar) return;
  const parts = [];
  if (emState.college) parts.push(`<span><i class="fas fa-building" style="color:var(--teal);font-size:.65rem"></i> <strong style="color:var(--text)">${emState.collegeCode}</strong></span>`);
  if (emState.dept)    parts.push(`<i class="fas fa-chevron-right" style="opacity:.4;font-size:.6rem"></i><span>Dept: <strong style="color:var(--text)">${emState.deptCode}</strong></span>`);
  if (emState.course)  parts.push(`<i class="fas fa-chevron-right" style="opacity:.4;font-size:.6rem"></i><span>Subject: <strong style="color:var(--text)">${emState.courseCode}</strong></span>`);
  if (emState.hall)    parts.push(`<i class="fas fa-chevron-right" style="opacity:.4;font-size:.6rem"></i><span>Room: <strong style="color:var(--text)">${emState.hallName}</strong></span>`);
  if (emState.examTitle) parts.push(`<i class="fas fa-chevron-right" style="opacity:.4;font-size:.6rem"></i><span>Exam: <strong style="color:var(--text)">${emState.examTitle}</strong></span>`);
  if (!parts.length) { bar.style.display='none'; return; }
  bar.style.display='flex'; bar.innerHTML = parts.join('');
}

// ── Per-step inline error helpers ────────────────────────────────────────────
function emShowStepError(step, msg) {
  // Hide all other step banners first
  for (let i = 1; i <= 6; i++) {
    const b = document.getElementById('emStep'+i+'Error');
    if (b && i !== step) b.style.display = 'none';
  }
  const banner = document.getElementById('emStep'+step+'Error');
  const span   = document.getElementById('emStep'+step+'ErrorMsg');
  if (banner && span) {
    span.textContent = msg;
    banner.style.display = 'block';
    banner.scrollIntoView({behavior:'smooth', block:'nearest'});
  }
  showToast(msg, 'error');
}
function emClearStepError(step) {
  const banner = document.getElementById('emStep'+step+'Error');
  if (banner) banner.style.display = 'none';
}
// Backwards-compat aliases used by oninput/onchange on Step 1 fields
function emShowStep1Error(msg) { emShowStepError(1, msg); }
function emClearStep1Error()   { emClearStepError(1); }

function emGoStep(n) {
  // Sync college from dropdown without wiping already-selected dept/course/hall
  emSyncCollegeFromDropdown();

  console.log('[emGoStep] n='+n+' | college='+emState.college+' | dept='+emState.dept+' | course='+emState.course+' | hall='+emState.hall);

  // ── Step 1 → 2: validate college + exam details ──────────────────────────
  if (n > 1) {
    const t = (document.getElementById('emTitle')?.value || '').trim();
    const d = (document.getElementById('emDate')?.value  || '').trim();
    if (!emState.college) {
      emShowStepError(1, '⚠ No college selected — please pick a college from the dropdown.');
      emGoStep(1); return;
    }
    if (!t) {
      emShowStepError(1, '⚠ Exam Title is required — please enter a title before continuing.');
      document.getElementById('emTitle')?.focus();
      emGoStep(1); return;
    }
    if (!d) {
      emShowStepError(1, '⚠ Exam Date is required — please pick a date before continuing.');
      document.getElementById('emDate')?.focus();
      emGoStep(1); return;
    }
    emClearStepError(1);
    emState.examTitle    = t;
    emState.examDate     = d;
    emState.examType     = document.getElementById('emType')?.value     || 'mid_term';
    emState.examMaxMarks = parseInt(document.getElementById('emMaxMarks')?.value)  || 100;
    emState.examPassMarks= parseInt(document.getElementById('emPassMarks')?.value) || 40;
    const semVal = document.getElementById('emSemester')?.value;
    if (!semVal) {
      emShowStepError(1, '⚠ Please select a semester before continuing.');
      document.getElementById('emSemester')?.focus();
      emGoStep(1); return;
    }
    emState.examSem      = parseInt(semVal) || 1;
    emState.examAcadYear = document.getElementById('emAcadYear')?.value || '2025-26';
  }

  // ── HOD: auto-select locked department when advancing to step 2+ ────────
  if (n >= 2 && emIsHod && emHodDeptId && !emState.dept) {
    emState.dept = emHodDeptId; emState.deptName = emHodDeptName; emState.deptCode = emHodDeptCode;
  }

  // ── Step 2 → 3: must have selected a department ──────────────────────────
  if (n > 2 && !emState.dept) {
    emShowStepError(2, '⚠ No department selected — please click a department card above before continuing.');
    // Navigate to step 2 so the error is visible
    emState.step = 2;
    for (let i=1;i<=7;i++) { const wp=document.getElementById('emWP-'+i); if(wp) wp.classList.toggle('active',i===2); }
    emUpdateStepBar(); emLoadDepts(); return;
  }

  // ── Step 3 → 4: must have selected a subject ─────────────────────────────
  if (n > 3 && !emState.course) {
    emShowStepError(3, '⚠ No subject selected — please click a subject card above before continuing.');
    emState.step = 3;
    for (let i=1;i<=7;i++) { const wp=document.getElementById('emWP-'+i); if(wp) wp.classList.toggle('active',i===3); }
    emUpdateStepBar(); emLoadCourses(); return;
  }

  // ── Step 4 → 5: must have selected a room ────────────────────────────────
  if (n > 4 && !emState.hall) {
    emShowStepError(4, '⚠ No exam room selected — please click a room card above before continuing.');
    emState.step = 4;
    for (let i=1;i<=7;i++) { const wp=document.getElementById('emWP-'+i); if(wp) wp.classList.toggle('active',i===4); }
    emUpdateStepBar(); emLoadHalls(); return;
  }

  // ── Step 5 → 6: warn if seating not saved (soft warning, don't block) ────
  if (n === 6) {
    const seated = Object.values(emState.seats).filter(s=>s&&!s.is_invig).length;
    if (seated === 0) {
      emShowStepError(5, '⚠ No students have been seated yet. You can auto-assign seats above before proceeding, or continue without seating.');
      // Don't return — allow proceeding, it's just a warning
    } else {
      emClearStepError(5);
    }
  }

  // ── Step 6 → 7: invigilators are optional — soft warning only ────────────
  if (n === 7) {
    emClearStepError(6);
  }

  // ── All checks passed — advance ──────────────────────────────────────────
  emState.step = n;
  for (let i=1;i<=7;i++) {
    const wp = document.getElementById('emWP-'+i);
    if (wp) wp.classList.toggle('active', i===n);
  }
  emUpdateStepBar();
  emUpdateSelBar();

  if (n===2) emLoadDepts();
  if (n===3) emLoadCourses();
  if (n===4) emLoadHalls();
  if (n===5) emLoadSeatingGrid();
  if (n===6) emLoadInvigList();
  if (n===7) emRenderSummary();
}

function emCollegeDropdownChange(sel) {
  if (!sel) return;
  // Works for <select> and <input type="hidden">
  let val, name, code;
  if (sel.tagName === 'SELECT') {
    const opt = sel.options[sel.selectedIndex];
    val  = opt?.value;
    name = opt?.dataset?.name || '';
    code = opt?.dataset?.code || '';
  } else {
    val  = sel.value;
    name = sel.dataset?.name || '';
    code = sel.dataset?.code || '';
  }
  if (val) {
    emSelectCollege(parseInt(val), name, code);
  } else {
    emState.college=null; emState.collegeName=''; emState.collegeCode='';
  }
}

function emSelectCollege(id, name, code) {
  emState.college=id; emState.collegeName=name; emState.collegeCode=code;
  emState.dept=null; emState.deptName=''; emState.course=null; emState.hall=null;
  emState.scheduleId=null; emState.examId=null;
  // Sync dropdown
  const sel = document.getElementById('emCollegeSelect');
  if (sel) sel.value = id;
  // Reload available semesters for this college
  emLoadSemesterDropdown();
}

// Populate the Semester <select> in Step 1 with semesters that actually exist
// in the HOD's locked dept (or the whole college for principal/superadmin).
async function emLoadSemesterDropdown() {
  const sel = document.getElementById('emSemester');
  if (!sel) return;
  const deptId    = emIsHod ? emHodDeptId : (emState.dept || 0);
  const collegeId = emState.college || 0;
  if (!deptId && !collegeId) {
    sel.innerHTML = '<option value="">— Select college first —</option>';
    return;
  }
  sel.innerHTML = '<option value="">Loading…</option>';
  const r = await postAction({ajax_action:'get_semesters_for_dept', dept_id:deptId, college_id:collegeId});
  if (!r?.ok || !r.semesters?.length) {
    sel.innerHTML = '<option value="">— No semesters found —</option>';
    return;
  }
  const semLabels = {1:'Semester 1',2:'Semester 2',3:'Semester 3',4:'Semester 4',5:'Semester 5',6:'Semester 6',7:'Semester 7',8:'Semester 8'};
  sel.innerHTML = '<option value="">— Select Semester —</option>' +
    r.semesters.map(s => `<option value="${s}">${semLabels[s] || 'Semester '+s}</option>`).join('');
  // Restore previously selected semester if still valid
  if (emState.examSem) sel.value = emState.examSem;
}

async function emLoadDepts() {
  const wrap = document.getElementById('em-dept-cards');
  // If HOD role: dept is already locked via PHP HTML — just ensure state is set
  if (emIsHod && emHodDeptId) {
    emState.dept = emHodDeptId; emState.deptName = emHodDeptName; emState.deptCode = emHodDeptCode;
    return; // no cards to render — PHP already rendered the locked badge
  }
  if (!wrap || !emState.college) return;
  wrap.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px"><i class="fas fa-spinner fa-spin"></i> Loading departments…</div>';
  const r = await postAction({ajax_action:'get_departments', college_id:emState.college});
  if (!r?.ok || !r.departments?.length) {
    wrap.innerHTML='<div style="color:var(--muted);text-align:center;padding:30px">No departments found for this college.</div>'; return;
  }
  wrap.innerHTML = r.departments.map(d=>`
    <div class="tt-setup-card" style="cursor:pointer;transition:all .2s" id="emDeptCard-${d.id}"
         onclick="emSelectDept(${d.id},'${d.name.replace(/'/g,"\\'")}','${d.code}')">
      <div style="font-size:.7rem;color:var(--muted);font-family:monospace;margin-bottom:4px">${d.code}</div>
      <div style="font-weight:700;color:var(--text);margin-bottom:4px">${d.name}</div>
      ${d.hod_name?`<div style="font-size:.72rem;color:var(--muted);margin-top:4px"><i class="fas fa-user-tie" style="color:var(--teal);font-size:.7rem"></i>&nbsp;HOD: <strong style="color:var(--text)">${d.hod_name}</strong></div>`:''}
    </div>`).join('');
  // Restore selection highlight if already chosen
  if (emState.dept) {
    const c = document.getElementById('emDeptCard-'+emState.dept);
    if (c) { c.style.border='2px solid var(--teal)'; c.style.background='rgba(20,184,166,.06)'; }
  }
}

function emSelectDept(id, name, code) {
  emState.dept=id; emState.deptName=name; emState.deptCode=code;
  emState.course=null;
  emClearStepError(2);
  document.querySelectorAll('[id^="emDeptCard-"]').forEach(c => { c.style.border='1px solid var(--border2)'; c.style.background=''; });
  const card = document.getElementById('emDeptCard-'+id);
  if (card) { card.style.border='2px solid var(--teal)'; card.style.background='rgba(20,184,166,.06)'; }
  showToast(`${name} selected`,'success');
}

async function emLoadCourses() {
  const wrap = document.getElementById('em-course-cards');
  if (!wrap || !emState.dept) return;
  wrap.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px"><i class="fas fa-spinner fa-spin"></i> Loading courses…</div>';
  // Filter courses by the semester chosen in Step 1
  const payload = {ajax_action:'get_courses', department_id:emState.dept};
  if (emState.examSem) payload.semester = emState.examSem;
  if (emState.examAcadYear) payload.academic_year = emState.examAcadYear;
  const r = await postAction(payload);
  if (!r?.ok || !r.courses?.length) {
    wrap.innerHTML=`<div style="color:var(--muted);grid-column:1/-1;text-align:center;padding:30px">No active courses found for Semester ${emState.examSem||'?'} in this department. Please add courses or change the semester.</div>`; return;
  }
  wrap.innerHTML = r.courses.map(c=>`
    <div class="tt-setup-card" style="cursor:pointer;transition:all .2s" id="emCourseCard-${c.id}"
         onclick="emSelectCourse(${c.id},'${c.name.replace(/'/g,"\\'")}','${c.code}','${c.semester||''}')">
      <div style="font-size:.7rem;color:var(--muted);font-family:monospace;margin-bottom:4px">${c.code}</div>
      <div style="font-weight:700;color:var(--text);margin-bottom:4px">${c.name}</div>
      <div style="font-size:.72rem;color:var(--muted)">Sem ${c.semester||'—'} &nbsp;·&nbsp; ${c.credits} cr</div>
    </div>`).join('');
  if (emState.course) {
    const c = document.getElementById('emCourseCard-'+emState.course);
    if (c) { c.style.border='2px solid var(--teal)'; c.style.background='rgba(20,184,166,.06)'; }
  }
}

function emSelectCourse(id, name, code, sem) {
  emState.course=id; emState.courseName=name; emState.courseCode=code; emState.courseSem=sem;
  emClearStepError(3);
  document.querySelectorAll('[id^="emCourseCard-"]').forEach(c => { c.style.border='1px solid var(--border2)'; c.style.background=''; });
  const card = document.getElementById('emCourseCard-'+id);
  if (card) { card.style.border='2px solid var(--teal)'; card.style.background='rgba(20,184,166,.06)'; }
  showToast(`${name} selected`,'success');
}

async function emLoadHalls() {
  const wrap = document.getElementById('em-hall-cards');
  if (!wrap || !emState.college) return;
  wrap.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px"><i class="fas fa-spinner fa-spin"></i> Loading halls…</div>';
  // Also fetch student count for this dept
  let studentCount = 0;
  const sr = await postAction({ajax_action:'sa_get_students_for_dept', college_id:emState.college, dept_id:emState.dept, semester:emState.examSem||0});
  if (sr?.ok) { emState.students = sr.students||[]; studentCount = emState.students.length; }
  const note = document.getElementById('emHallStudentNote');
  if (note) {
    note.style.display='block';
    note.style.background = 'rgba(20,184,166,.06)'; note.style.border='1px solid rgba(20,184,166,.2)'; note.style.borderRadius='var(--radius-sm)'; note.style.color='var(--text)';
    note.innerHTML = `<i class="fas fa-users" style="color:var(--teal)"></i> &nbsp;<strong style="color:var(--text)">${studentCount}</strong> students in ${emState.deptName || 'this department'}`;
  }

  const r = await postAction({ajax_action:'get_exam_halls', college_id:emState.college});
  if (!r?.ok || !r.halls?.length) { wrap.innerHTML='<div style="color:var(--muted);text-align:center;padding:30px">No active exam halls for this college.</div>'; return; }
  wrap.innerHTML = r.halls.map(h=>{
    const fits = h.capacity >= studentCount;
    const capColor = fits ? '#2dd4aa' : '#f4a261';
    return `<div class="tt-setup-card" style="cursor:pointer;transition:all .2s" id="emHallCard-${h.id}"
         onclick="emSelectHall(${h.id},'${h.name.replace(/'/g,"\\'")}',${h.capacity},${h.rows},${h.cols})">
      <div style="font-weight:700;color:var(--text);margin-bottom:4px">${h.name}</div>
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:6px">${h.building||''}</div>
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <span style="color:${capColor};font-weight:700">${h.capacity}</span>
        <span style="color:var(--muted);font-size:.72rem">seats</span>
        <span style="color:var(--muted);font-size:.72rem">·</span>
        <span style="color:var(--muted);font-size:.72rem">${h.rows}×${h.cols} grid</span>
      </div>
      ${!fits?`<div style="font-size:.68rem;color:var(--amber);margin-top:4px"><i class="fas fa-triangle-exclamation"></i> Hall may be too small</div>`:''}
      ${h.has_projector?'<span style="font-size:.65rem;color:var(--muted)"><i class="fas fa-projector"></i> Projector</span> ':''} 
      ${h.has_ac?'<span style="font-size:.65rem;color:var(--muted)"><i class="fas fa-snowflake"></i> AC</span>':''}
    </div>`;
  }).join('');
  if (emState.hall) {
    const c = document.getElementById('emHallCard-'+emState.hall);
    if (c) { c.style.border='2px solid var(--teal)'; c.style.background='rgba(20,184,166,.06)'; }
  }
  // Populate hall selects in old list tab too
  const hallOpts = '<option value="">Choose hall…</option>' + r.halls.map(h=>`<option value="${h.id}">${h.name} (${h.capacity})</option>`).join('');
  ['saSchedHall','saInvigHall','saSeatHall'].forEach(id => { const el=document.getElementById(id); if(el) el.innerHTML=hallOpts; });
}

function emSelectHall(id, name, capacity, rows, cols) {
  emState.hall=id; emState.hallName=name; emState.hallCapacity=capacity;
  emState.hallRows = rows || Math.ceil(capacity/10);
  emState.hallCols = cols || Math.min(10, capacity);
  emClearStepError(4);
  document.querySelectorAll('[id^="emHallCard-"]').forEach(c => { c.style.border='1px solid var(--border2)'; c.style.background=''; });
  const card = document.getElementById('emHallCard-'+id);
  if (card) { card.style.border='2px solid var(--teal)'; card.style.background='rgba(20,184,166,.06)'; }
  showToast(`${name} selected`,'success');
}

// Create exam record + schedule in one step (Step 4)
async function emCreateAndSchedule() {
  if (!emState.college||!emState.dept||!emState.course) { showToast('Complete steps 1–3 first','warning'); return; }
  if (!emState.hall)       { showToast('Select a hall first','warning'); return; }
  if (!emState.examTitle)  { showToast('Enter exam title in Step 1','warning'); return; }
  if (!emState.examDate)   { showToast('Enter exam date in Step 1','warning'); return; }

  const start = document.getElementById('emSchedStart')?.value || '09:00';
  const end   = document.getElementById('emSchedEnd')?.value   || '12:00';

  // 1. Create the exam
  const er = await postAction({
    ajax_action:'sa_create_exam',
    college_id: emState.college, department_id: emState.dept, course_id: emState.course,
    title: emState.examTitle, type: emState.examType,
    max_marks: emState.examMaxMarks, pass_marks: emState.examPassMarks,
    exam_date: emState.examDate, semester: emState.examSem, academic_year: emState.examAcadYear
  });
  if (!er?.ok) { showToast('Exam create failed: '+(er?.msg||'Error'),'error'); return; }
  emState.examId = er.exam_id;
  showToast('Exam created (ID '+er.exam_id+')','success');

  // 2. Create the schedule
  const sr = await postAction({
    ajax_action:'sa_assign_schedule',
    exam_id: emState.examId, hall_id: emState.hall,
    exam_date: emState.examDate, start_time: start, end_time: end,
    semester: emState.examSem, academic_year: emState.examAcadYear
  });
  if (sr?.ok) {
    // Get schedule ID back
    const scheds = await postAction({ajax_action:'sa_get_schedules_for_exam', exam_id: emState.examId});
    if (scheds?.ok && scheds.schedules?.length) {
      emState.scheduleId = scheds.schedules[0].id;
      if (scheds.schedules[0].rows) { emState.hallRows = parseInt(scheds.schedules[0].rows); emState.hallCols = parseInt(scheds.schedules[0].cols); }
    }
    showToast('Schedule saved! You can proceed to Seating.', 'success');
  } else {
    showToast('Schedule save failed: '+(sr?.msg||'Error'),'error');
  }
}

// Legacy kept for old direct-assign tab
async function emSaveScheduleAndHall() { return emCreateAndSchedule(); }

async function emLoadSeatingGrid() {
  const meta = document.getElementById('emGridMeta');
  const wrap = document.getElementById('emGridWrap');
  if (!emState.college || !emState.dept) {
    if (meta) meta.textContent = 'Complete steps 1–4 first.';
    return;
  }

  if (wrap) wrap.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i><div style="margin-top:10px;font-size:.82rem">Loading seating grid…</div></div>';

  // Always reload fresh from server when entering Step 5
  const sr = await postAction({ajax_action: 'sa_get_students_for_dept', college_id: emState.college, dept_id: emState.dept, semester: emState.examSem||0});
  emState.students = sr?.students || [];

  // Reset seats and load from DB if schedule exists
  emState.seats = {};
  if (emState.scheduleId || emState.examId) {
    const gr = await postAction({ajax_action: 'sa_get_seating_grid', schedule_id: emState.scheduleId || emState.examId});
    if (gr?.ok) {
      if (gr.students && gr.students.length) emState.students = gr.students;
      if (gr.seats && gr.seats.length) {
        gr.seats.forEach(function(s) {
          emState.seats[s.seat_number] = {
            student_id: s.student_id,
            name: s.full_name || '',
            roll: s.roll_number || '',
            dept: s.dept_name || emState.deptName || '',
            is_present: s.is_present,
            is_invig: false
          };
        });
      }
    }
  }

  emRenderGrid();
  emUpdateGridStats();
}

function emUpdateGridStats() {
  var all      = emState.students.length;
  var seated   = Object.values(emState.seats).filter(function(s){ return s && !s.is_invig; }).length;
  var present  = Object.values(emState.seats).filter(function(s){ return s && s.is_present === 1; }).length;
  var absent   = Object.values(emState.seats).filter(function(s){ return s && s.is_present === 0; }).length;
  var el = function(id){ return document.getElementById(id); };
  if (el('emStatTotal'))      el('emStatTotal').textContent      = all;
  if (el('emStatAssigned'))   el('emStatAssigned').textContent   = seated;
  if (el('emStatPresent'))    el('emStatPresent').textContent    = present;
  if (el('emStatAbsent'))     el('emStatAbsent').textContent     = absent;
  if (el('emStatUnassigned')) el('emStatUnassigned').textContent = Math.max(0, all - seated);
  if (el('emGridMeta')) el('emGridMeta').textContent = (emState.hallName || 'Hall') + ' · ' + emState.hallCapacity + ' seats · ' + seated + ' students assigned';
}

function emEsc(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function emRenderGrid() {
  var wrap = document.getElementById('emGridWrap');
  if (!wrap) return;
  var rows = emState.hallRows || 6;
  var cols = emState.hallCols || 10;

  var html = '<div style="display:inline-flex;flex-direction:column;gap:5px;margin-top:4px;padding:4px">';

  // ── Invigilator desk row ──────────────────────────────────────────────
  var invigMax = Math.max(3, Math.ceil(cols / 2));
  html += '<div style="margin-bottom:12px;padding:10px 14px;background:rgba(167,139,250,.07);border:1px dashed rgba(167,139,250,.35);border-radius:8px">';
  html += '<div style="font-size:.66rem;color:#a78bfa;font-weight:700;letter-spacing:.06em;margin-bottom:8px;display:flex;align-items:center;gap:6px">';
  html += '<i class="fas fa-chalkboard-teacher" style="font-size:.7rem"></i> INVIGILATOR / EXAMINER DESK';
  html += '</div>';
  html += '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
  for (var i = 1; i <= invigMax; i++) {
    var invKey = 'INV' + i;
    var taken  = emState.seats[invKey];
    var iBg    = taken ? 'rgba(167,139,250,.3)' : 'rgba(167,139,250,.07)';
    var iBd    = taken ? '#a78bfa' : 'rgba(167,139,250,.3)';
    var iCol   = taken ? '#a78bfa' : 'rgba(167,139,250,.45)';
    var iLbl   = taken ? '<i class="fas fa-user-tie" style="font-size:.75rem"></i>' : '<span style="font-size:.55rem">' + i + '</span>';
    var iTtl   = taken ? 'Invigilator seat ' + i + ' — click to remove' : 'Click to mark as Invigilator seat ' + i;
    html += '<div onclick="emInvigDeskClick(\'' + invKey + '\')" title="' + iTtl + '"';
    html += ' style="width:42px;height:42px;border-radius:6px;border:1px dashed ' + iBd + ';background:' + iBg + ';';
    html += 'color:' + iCol + ';cursor:pointer;display:flex;align-items:center;justify-content:center;';
    html += 'transition:all .15s;font-weight:700">' + iLbl + '</div>';
  }
  html += '<span style="font-size:.68rem;color:rgba(167,139,250,.5);margin-left:4px">Click a slot to assign/remove an invigilator seat</span>';
  html += '</div></div>';
  // ── End invigilator desk ──────────────────────────────────────────────

  // Column headers
  html += '<div style="display:flex;gap:5px;margin-left:36px;margin-bottom:2px">';
  for (var c = 1; c <= cols; c++) {
    html += '<div style="width:64px;text-align:center;font-size:.62rem;color:var(--muted);font-weight:600">' + c + '</div>';
  }
  html += '</div>';

  // Seat rows
  for (var r = 1; r <= rows; r++) {
    var rowLetter = r <= 26 ? String.fromCharCode(64 + r) : 'R' + r;
    html += '<div style="display:flex;gap:5px;align-items:center">';
    html += '<div style="width:28px;text-align:right;font-size:.62rem;color:var(--muted);margin-right:4px;font-weight:600;flex-shrink:0">' + rowLetter + '</div>';

    for (var c = 1; c <= cols; c++) {
      var key       = 'R' + r + 'C' + c;
      var seatLabel = rowLetter + '-' + String(c).padStart(2, '0');
      var seat      = emState.seats[key];

      var bg = 'rgba(15,118,110,.04)', border = 'var(--border2)', color = 'var(--muted)', fw = '400';
      var cellContent = '', titleAttr = 'Empty — click to assign next student';

      if (seat && seat.is_invig) {
        bg = 'rgba(167,139,250,.2)'; border = '#a78bfa'; color = '#a78bfa'; fw = '700';
        titleAttr = 'Invigilator seat — click to manage';
        cellContent = '<i class="fas fa-user-tie" style="font-size:.7rem"></i>'
                    + '<span style="display:block;font-size:.42rem;margin-top:2px;opacity:.8">INVIG</span>';
      } else if (seat) {
        bg = 'rgba(20,184,166,.14)'; border = 'rgba(20,184,166,.5)'; color = 'var(--teal)'; fw = '600';
        if (seat.is_present === 1) { bg = 'rgba(45,212,170,.22)'; border = '#2dd4aa'; color = '#2dd4aa'; }
        if (seat.is_present === 0) { bg = 'rgba(231,111,81,.18)';  border = '#e76f51'; color = '#e76f51'; }

        // Full name display — wrap nicely in cell
        var fullName  = seat.name || '?';
        var rollDisp  = seat.roll  || '';
        var deptDisp  = seat.dept  || emState.deptName || '';
        // Truncate name for display but keep full in tooltip
        var nameParts = fullName.trim().split(/\s+/);
        var nameDisp;
        if (fullName.length <= 10) {
          nameDisp = fullName;
        } else if (nameParts.length >= 2) {
          // First name + last initial
          nameDisp = nameParts[0] + ' ' + nameParts[nameParts.length - 1][0] + '.';
        } else {
          nameDisp = fullName.slice(0, 9) + '…';
        }

        titleAttr = fullName + (rollDisp ? ' | ' + rollDisp : '') + (deptDisp ? ' | ' + deptDisp : '') + ' | Seat ' + seatLabel;

        var statusIcon = '';
        if (seat.is_present === 1) statusIcon = '<span style="display:block;font-size:.42rem;line-height:1;margin-top:1px;color:#2dd4aa">✓ Present</span>';
        else if (seat.is_present === 0) statusIcon = '<span style="display:block;font-size:.42rem;line-height:1;margin-top:1px;color:#e76f51">✗ Absent</span>';

        cellContent = '<span style="display:block;font-size:.52rem;font-weight:700;line-height:1.25;word-break:break-word;max-width:60px;text-align:center;overflow:hidden">' + emEsc(nameDisp) + '</span>'
                    + (rollDisp ? '<span style="display:block;font-size:.44rem;opacity:.8;line-height:1.2;font-weight:400;max-width:60px;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + emEsc(rollDisp) + '</span>' : '')
                    + statusIcon;
      } else {
        // Empty seat — show seat label
        cellContent = '<span style="font-size:.52rem;opacity:.5">' + seatLabel + '</span>';
      }

      html += '<div onclick="emSeatClick(\'' + key + '\')" title="' + emEsc(titleAttr) + '"';
      html += ' style="width:64px;height:58px;border-radius:6px;border:1px solid ' + border + ';background:' + bg + ';';
      html += 'color:' + color + ';font-weight:' + fw + ';cursor:pointer;';
      html += 'display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;';
      html += 'transition:all .15s;overflow:hidden;line-height:1.2;padding:4px;box-sizing:border-box">';
      html += cellContent + '</div>';
    }
    html += '</div>';
  }

  html += '</div>';
  wrap.innerHTML = html;
  emUpdateGridStats();
}

function emSeatClick(key) {
  var popup = document.getElementById('emSeatPopup');
  if (!popup) return;
  var seat = emState.seats[key];

  // Human-readable seat label
  var m = key.match(/R(\d+)C(\d+)/);
  var r = m ? parseInt(m[1]) : 0, c = m ? parseInt(m[2]) : 0;
  var rowLetter = r <= 26 ? String.fromCharCode(64 + r) : 'R' + r;
  var humanKey  = rowLetter + '-' + String(c).padStart(2, '0');

  popup.style.display = 'none';
  popup.innerHTML = '';

  // ── Invigilator seat ──────────────────────────────────────────────────
  if (seat && seat.is_invig) {
    popup.style.display = 'block';
    popup.innerHTML =
      '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' +
        '<span style="font-size:.82rem;font-weight:600;color:#a78bfa"><i class="fas fa-user-tie"></i> Invigilator seat — ' + humanKey + '</span>' +
      '</div>' +
      '<div style="display:flex;gap:8px">' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem;color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="emRemoveSeat(\'' + key + '\')"><i class="fas fa-trash"></i> Remove</button>' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem" onclick="document.getElementById(\'emSeatPopup\').style.display=\'none\'">Close</button>' +
      '</div>';
    return;
  }

  // ── Occupied seat ─────────────────────────────────────────────────────
  if (seat) {
    var statusLabel =
      seat.is_present === 1 ? '<span style="color:#2dd4aa">● Present</span>' :
      seat.is_present === 0 ? '<span style="color:#e76f51">● Absent</span>' :
                              '<span style="color:var(--muted)">● Not marked</span>';
    var deptShow = seat.dept || emState.deptName || '';
    popup.style.display = 'block';
    popup.innerHTML =
      '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px">' +
        '<div style="width:38px;height:38px;border-radius:8px;background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.4);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.82rem;color:var(--teal);font-weight:700">' +
          emEsc((seat.name || '?').split(' ').map(function(w){ return w[0]; }).join('').slice(0,2).toUpperCase()) +
        '</div>' +
        '<div>' +
          '<div style="font-size:.9rem;font-weight:700;color:var(--text)">' + emEsc(seat.name || '?') + '</div>' +
          '<div style="font-size:.72rem;color:var(--muted);margin-top:2px">' +
            (seat.roll ? '<span style="font-family:monospace;color:var(--teal)">' + emEsc(seat.roll) + '</span> · ' : '') +
            (deptShow ? '<span>' + emEsc(deptShow) + '</span> · ' : '') +
            'Seat <strong style="color:var(--text)">' + humanKey + '</strong>' +
          '</div>' +
          '<div style="margin-top:4px;font-size:.75rem">' + statusLabel + '</div>' +
        '</div>' +
      '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
        '<button class="btn" style="background:rgba(45,212,170,.2);border:1px solid #2dd4aa;color:#2dd4aa;padding:5px 12px;font-size:.76rem;border-radius:6px" onclick="emMarkPresent(\'' + key + '\')"><i class="fas fa-check"></i> Present</button>' +
        '<button class="btn" style="background:rgba(231,111,81,.2);border:1px solid #e76f51;color:#e76f51;padding:5px 12px;font-size:.76rem;border-radius:6px" onclick="emMarkAbsent(\'' + key + '\')"><i class="fas fa-times"></i> Absent</button>' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem;color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="emRemoveSeat(\'' + key + '\')"><i class="fas fa-trash"></i> Remove</button>' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem" onclick="document.getElementById(\'emSeatPopup\').style.display=\'none\'">Close</button>' +
      '</div>';
    return;
  }

  // ── Empty seat ────────────────────────────────────────────────────────
  var assignedIds = Object.values(emState.seats)
    .filter(function(s){ return s && !s.is_invig; })
    .map(function(s){ return s.student_id; });
  var available = emState.students.filter(function(s){ return !assignedIds.includes(s.id); });

  if (!available.length) {
    // All assigned — offer invigilator
    popup.style.display = 'block';
    popup.innerHTML =
      '<div style="font-size:.82rem;color:var(--muted);margin-bottom:8px">All students assigned. Mark seat <strong style="color:var(--text)">' + humanKey + '</strong> as:</div>' +
      '<div style="display:flex;gap:8px">' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem;color:#a78bfa;border-color:rgba(167,139,250,.3)" onclick="emSetInvigSeat(\'' + key + '\')"><i class="fas fa-user-tie"></i> Invigilator seat</button>' +
        '<button class="btn-secondary" style="padding:5px 12px;font-size:.76rem" onclick="document.getElementById(\'emSeatPopup\').style.display=\'none\'">Cancel</button>' +
      '</div>';
    return;
  }

  // Build student picker dropdown
  var opts = available.map(function(s){
    return '<option value="' + s.id + '" data-name="' + emEsc(s.full_name) + '" data-roll="' + emEsc(s.roll_number || '') + '">'
         + emEsc(s.full_name) + (s.roll_number ? ' (' + s.roll_number + ')' : '')
         + '</option>';
  }).join('');

  popup.style.display = 'block';
  popup.innerHTML =
    '<div style="font-size:.8rem;font-weight:600;color:var(--text);margin-bottom:8px">' +
      '<i class="fas fa-chair" style="color:var(--teal)"></i> Assign student to seat <strong style="color:var(--teal)">' + humanKey + '</strong>' +
      ' <span style="font-size:.72rem;color:var(--muted);font-weight:400">— ' + available.length + ' unassigned</span>' +
    '</div>' +
    '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
      '<select id="emSeatStudentSel" class="input-field" style="min-width:220px;flex:1">' + opts + '</select>' +
      '<button class="btn-primary" style="padding:6px 14px;font-size:.78rem" onclick="emAssignSeat(\'' + key + '\')"><i class="fas fa-check"></i> Assign</button>' +
      '<button class="btn-secondary" style="padding:6px 12px;font-size:.78rem" onclick="document.getElementById(\'emSeatPopup\').style.display=\'none\'">Cancel</button>' +
    '</div>';
}

async function emDoAssignSeat(key, sid, name, roll) {
  var parts = key.match(/R(\d+)C(\d+)/);
  var row = parts ? parseInt(parts[1]) : 0;
  var col = parts ? parseInt(parts[2]) : 0;
  // Remove if this student was elsewhere
  Object.keys(emState.seats).forEach(function(k){
    if (emState.seats[k] && emState.seats[k].student_id === sid) delete emState.seats[k];
  });
  emState.seats[key] = {
    student_id: sid,
    name: name,
    roll: roll,
    dept: emState.deptName || '',
    is_present: null,
    is_invig: false
  };
  document.getElementById('emSeatPopup').style.display = 'none';
  emRenderGrid();

  if (emState.scheduleId) {
    await postAction({
      ajax_action: 'sa_save_seat',
      schedule_id: emState.scheduleId,
      hall_id: emState.hall,
      student_id: sid,
      seat_number: key,
      row_no: row,
      col_no: col
    });
  }
}

async function emAssignSeat(key) {
  var sel = document.getElementById('emSeatStudentSel');
  if (!sel || !sel.value) return;
  var sid  = parseInt(sel.value);
  var opt  = sel.options[sel.selectedIndex];
  var name = (opt && opt.dataset.name) ? opt.dataset.name : '';
  var roll = (opt && opt.dataset.roll) ? opt.dataset.roll : '';
  await emDoAssignSeat(key, sid, name, roll);
}

function emSetInvigSeat(key) {
  emState.seats[key] = {is_invig: true};
  document.getElementById('emSeatPopup').style.display = 'none';
  emRenderGrid();
}

function emInvigDeskClick(key) {
  if (emState.seats[key] && emState.seats[key].is_invig) {
    delete emState.seats[key];
    showToast('Invigilator seat removed', 'success');
  } else {
    emState.seats[key] = {is_invig: true};
    showToast('Invigilator seat assigned', 'success');
  }
  emRenderGrid();
}

async function emMarkPresent(key) {
  var seat = emState.seats[key]; if (!seat) return;
  seat.is_present = seat.is_present === 1 ? null : 1;
  if (emState.scheduleId) await postAction({ajax_action:'sa_mark_seat_status', schedule_id:emState.scheduleId, student_id:seat.student_id, is_present: seat.is_present === null ? '' : seat.is_present});
  document.getElementById('emSeatPopup').style.display = 'none';
  emRenderGrid();
}

async function emMarkAbsent(key) {
  var seat = emState.seats[key]; if (!seat) return;
  seat.is_present = seat.is_present === 0 ? null : 0;
  if (emState.scheduleId) await postAction({ajax_action:'sa_mark_seat_status', schedule_id:emState.scheduleId, student_id:seat.student_id, is_present: seat.is_present === null ? '' : seat.is_present});
  document.getElementById('emSeatPopup').style.display = 'none';
  emRenderGrid();
}

async function emRemoveSeat(key) {
  if (emState.scheduleId && emState.seats[key] && !emState.seats[key].is_invig) {
    await postAction({ajax_action:'sa_remove_seat', schedule_id:emState.scheduleId, seat_number:key});
  }
  delete emState.seats[key];
  document.getElementById('emSeatPopup').style.display = 'none';
  emRenderGrid();
}

function emAutoAssignSeats() {
  if (!emState.students.length) { showToast('No students found for this department', 'warning'); return; }
  // Clear non-invigilator seats
  Object.keys(emState.seats).forEach(function(k){
    if (!emState.seats[k] || !emState.seats[k].is_invig) delete emState.seats[k];
  });
  var rows = emState.hallRows || 6;
  var cols = emState.hallCols || 10;
  var si = 0;
  for (var r = 1; r <= rows && si < emState.students.length; r++) {
    for (var c = 1; c <= cols && si < emState.students.length; c++) {
      var key = 'R' + r + 'C' + c;
      if (!emState.seats[key]) {
        var s = emState.students[si];
        emState.seats[key] = {
          student_id: s.id,
          name: s.full_name || '',
          roll: s.roll_number || '',
          dept: emState.deptName || '',
          is_present: null,
          is_invig: false
        };
        si++;
      }
    }
  }
  emRenderGrid();
  emClearStepError(5);
  if (emState.scheduleId) {
    postAction({ajax_action:'sa_assign_seating', schedule_id:emState.scheduleId, hall_id:emState.hall})
      .then(function(r){ if (r && r.ok) showToast(r.msg, 'success'); });
  } else {
    showToast(si + ' students assigned (save schedule first to persist)', 'success');
  }
}

function emClearAllSeats() {
  if (!confirm('Clear all seat assignments?')) return;
  emState.seats = {};
  emRenderGrid();
  var p = document.getElementById('emSeatPopup');
  if (p) p.style.display = 'none';
  showToast('Seats cleared', 'success');
}


async function emLoadInvigList() {
  const wrap = document.getElementById('em-invig-list');
  if (!wrap || !emState.college) return;
  wrap.innerHTML='<div style="color:var(--muted);text-align:center;padding:30px"><i class="fas fa-spinner fa-spin"></i> Loading faculty…</div>';
  const r = await postAction({ajax_action:'get_faculty', college_id:emState.college});
  const faculty = (r?.faculty||[]).filter(f=>f.college_id==emState.college||!f.college_id);
  if (!faculty.length) {
    wrap.innerHTML='<div style="color:var(--muted);text-align:center;padding:30px">No faculty found for this college.</div>'; return;
  }
  // Cache faculty names for summary display
  if (!emState.facultyCache) emState.facultyCache = {};
  faculty.forEach(f => { emState.facultyCache[f.id] = f.full_name; });
  wrap.innerHTML = faculty.map(f=>{
    const initials = f.full_name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
    const checked  = !!emState.invigilators[f.id];
    const duty     = emState.invigilators[f.id+'_duty']||'assistant';
    const deptLabel = f.faculty_department || '';
    return `<div id="emInvigRow-${f.id}" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid ${checked?'rgba(20,184,166,.35)':'var(--border2)'};border-radius:var(--radius-sm);background:${checked?'rgba(20,184,166,.06)':'transparent'};transition:all .15s">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:34px;height:34px;border-radius:50%;background:rgba(20,184,166,.15);color:var(--teal);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;flex-shrink:0">${initials}</div>
        <div>
          <div style="font-weight:600;color:var(--text);font-size:.88rem">${f.full_name}</div>
          <div style="font-size:.72rem;color:var(--muted)">${f.designation||''} ${f.designation&&deptLabel?'·':''} ${deptLabel} ${(f.designation||deptLabel)?'·':''} ${f.email}</div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <div id="emInvigDutyWrap-${f.id}" style="display:${checked?'block':'none'}">
          <select style="background:rgba(15,118,110,.04);border:1px solid var(--border2);border-radius:6px;color:var(--text);padding:4px 8px;font-size:.76rem"
            onchange="emState.invigilators['${f.id}_duty']=this.value">
            ${['assistant','chief','flying_squad'].map(t=>`<option value="${t}" ${duty===t?'selected':''}>${t.replace('_',' ')}</option>`).join('')}
          </select>
        </div>
        <input type="checkbox" ${checked?'checked':''} style="width:18px;height:18px;cursor:pointer;accent-color:var(--teal)"
          onchange="emToggleInvig(${f.id},this.checked)">
      </div>
    </div>`;
  }).join('');
}

function emToggleInvig(id, checked) {
  const row  = document.getElementById('emInvigRow-'+id);
  const duty = document.getElementById('emInvigDutyWrap-'+id);
  if (checked) {
    emState.invigilators[id] = true;
    if (!emState.invigilators[id+'_duty']) emState.invigilators[id+'_duty'] = 'assistant';
    if (row) { row.style.border='1px solid rgba(20,184,166,.35)'; row.style.background='rgba(20,184,166,.06)'; }
    if (duty) duty.style.display='block';
  } else {
    delete emState.invigilators[id]; delete emState.invigilators[id+'_duty'];
    if (row) { row.style.border='1px solid var(--border2)'; row.style.background='transparent'; }
    if (duty) duty.style.display='none';
  }
}

function emRenderSummary() {
  const sb = document.getElementById('emSummaryBody');
  if (!sb) return;
  const seated   = Object.values(emState.seats).filter(s=>s&&!s.is_invig).length;
  const present  = Object.values(emState.seats).filter(s=>s&&s.is_present===1).length;
  const absent   = Object.values(emState.seats).filter(s=>s&&s.is_present===0).length;
  const invigIds = Object.keys(emState.invigilators).filter(k=>!k.includes('_')&&emState.invigilators[k]);

  const row = (label,val)=>`<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:.85rem">
    <span style="color:var(--muted)">${label}</span><strong style="color:var(--text)">${val}</strong></div>`;
  const chip = (v,c='var(--teal)')=>`<span style="background:rgba(20,184,166,.1);color:${c};border:1px solid rgba(20,184,166,.2);border-radius:50px;padding:2px 10px;font-size:.72rem;font-weight:700">${v}</span>`;

  sb.innerHTML = `
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:20px">
    <div style="background:rgba(15,118,110,.05);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:14px">
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:6px">EXAM</div>
      ${row('Title', emState.examTitle||'—')}
      ${row('Type', (emState.examType||'—').replace('_',' '))}
      ${row('Date', emState.examDate||'—')}
      ${row('Marks', `${emState.examMaxMarks} / pass ${emState.examPassMarks}`)}
    </div>
    <div style="background:rgba(15,118,110,.05);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:14px">
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:6px">COLLEGE &amp; DEPT</div>
      ${row('College', emState.collegeName)}
      ${row('Department', emState.deptName+' ('+emState.deptCode+')')}
      ${row('Subject', emState.courseName)}
      ${row('Course code', emState.courseCode+' · Sem '+emState.courseSem)}
    </div>
    <div style="background:rgba(15,118,110,.05);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:14px">
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:6px">HALL &amp; SEATING</div>
      ${row('Hall', emState.hallName)}
      ${row('Grid', emState.hallRows+'×'+emState.hallCols+' ('+emState.hallCapacity+' seats)')}
      ${row('Students in dept', emState.students.length)}
      ${row('Assigned seats', seated)}
      ${row('Present / Absent', present+' / '+absent)}
    </div>
  </div>
  <div style="margin-bottom:16px">
    <div style="font-size:.72rem;color:var(--muted);margin-bottom:8px">INVIGILATORS (${invigIds.length})</div>
    ${invigIds.length===0?'<div style="color:var(--muted);font-size:.82rem">None assigned</div>':
      invigIds.map(fid=>{
        const duty = emState.invigilators[fid+'_duty']||'assistant';
        const fname = emState.facultyCache?.[fid] || 'Faculty #'+fid;
        return `<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)">
          <i class="fas fa-user-tie" style="color:var(--teal);font-size:.8rem"></i>
          <span style="font-size:.85rem;color:var(--text);flex:1">${fname}</span>
          ${chip(duty.replace('_',' '))}
        </div>`;
      }).join('')
    }
  </div>
  ${emState.scheduleId?`<div style="margin-bottom:16px;padding:8px 12px;background:rgba(45,212,170,.06);border:1px solid rgba(45,212,170,.2);border-radius:var(--radius-sm);font-size:.8rem;color:#2dd4aa">
    <i class="fas fa-circle-check"></i> &nbsp;Schedule ID <strong>#${emState.scheduleId}</strong> saved · Exam ID <strong>#${emState.examId}</strong> created
  </div>`:'<div style="margin-bottom:16px;padding:8px 12px;background:rgba(244,162,97,.08);border:1px solid rgba(244,162,97,.2);border-radius:var(--radius-sm);font-size:.8rem;color:var(--amber)"><i class="fas fa-triangle-exclamation"></i> &nbsp;Exam &amp; schedule not saved yet. Use "Create Exam &amp; Save Schedule" in Step 4.</div>'}
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn-primary" onclick="emFinishSetup()"><i class="fas fa-circle-check"></i> Finish &amp; Save Invigilators</button>
    <button class="btn-secondary" onclick="saIssueAdmitsEM()"><i class="fas fa-id-card"></i> Issue Admit Cards</button>
    <button class="btn-secondary" onclick="emResetWizard()"><i class="fas fa-plus"></i> New Setup</button>
  </div>`;
}

async function emFinishSetup() {
  // Save all checked invigilators to DB
  const invigIds = Object.keys(emState.invigilators).filter(k=>!k.includes('_')&&emState.invigilators[k]);
  let savedInvigs = 0;
  for (const fid of invigIds) {
    const duty = emState.invigilators[fid+'_duty']||'assistant';
    if (emState.scheduleId) {
      const r = await postAction({ajax_action:'sa_assign_invig',schedule_id:emState.scheduleId,faculty_id:fid,hall_id:emState.hall||0,duty_type:duty});
      if (r?.ok) savedInvigs++;
    } else savedInvigs++;
  }

  // Mark exam as scheduled/complete in DB
  if (emState.examId) {
    await postAction({ajax_action:'sa_complete_exam', exam_id: emState.examId});
  }

  // Build compact seat preview
  const rows = emState.hallRows, cols = emState.hallCols;
  const invigSlotKeys = Object.keys(emState.seats).filter(k=>k.startsWith('INV')&&emState.seats[k]?.is_invig);
  let seatPreviewHtml = '';

  // Invigilator desk row
  if (invigSlotKeys.length) {
    seatPreviewHtml += `<div style="margin-bottom:8px;padding:6px 10px;background:rgba(167,139,250,.07);border:1px dashed rgba(167,139,250,.3);border-radius:6px;font-size:.7rem;color:#a78bfa;display:flex;align-items:center;gap:8px">
      <i class="fas fa-chalkboard-teacher"></i>
      <strong>Invigilator Desk</strong>
      <span style="color:rgba(167,139,250,.6);">${invigSlotKeys.length} seat(s) assigned</span>
    </div>`;
  }

  // Student seat grid (mini)
  seatPreviewHtml += `<div style="overflow-x:auto;margin-bottom:14px"><div style="display:inline-flex;flex-direction:column;gap:3px;padding:4px">`;
  // Col headers
  seatPreviewHtml += `<div style="display:flex;gap:3px;margin-left:24px">`;
  for (let c=1;c<=cols;c++) seatPreviewHtml += `<div style="width:28px;text-align:center;font-size:.55rem;color:var(--muted)">${c}</div>`;
  seatPreviewHtml += '</div>';
  for (let r=1;r<=rows;r++) {
    const rowLetter = r<=26 ? String.fromCharCode(64+r) : 'R'+r;
    seatPreviewHtml += `<div style="display:flex;gap:3px;align-items:center">`;
    seatPreviewHtml += `<div style="width:20px;text-align:right;font-size:.55rem;color:var(--muted);margin-right:4px">${rowLetter}</div>`;
    for (let c=1;c<=cols;c++) {
      const key = `R${r}C${c}`;
      const seat = emState.seats[key];
      let bg='rgba(15,118,110,.04)', border='var(--border2)', title='';
      if (seat?.is_invig) { bg='rgba(167,139,250,.3)'; border='#a78bfa'; title='Invigilator'; }
      else if (seat?.is_present===1) { bg='rgba(45,212,170,.35)'; border='#2dd4aa'; title=seat.name; }
      else if (seat?.is_present===0) { bg='rgba(231,111,81,.35)'; border='#e76f51'; title=seat.name; }
      else if (seat) { bg='rgba(20,184,166,.2)'; border='rgba(20,184,166,.5)'; title=seat.name||'Assigned'; }
      seatPreviewHtml += `<div title="${title}" style="width:28px;height:28px;border-radius:3px;border:1px solid ${border};background:${bg}"></div>`;
    }
    seatPreviewHtml += '</div>';
  }
  seatPreviewHtml += '</div></div>';

  // Show done banner in summary panel
  const sp = document.getElementById('emSummaryPanel');
  const sd = document.getElementById('emSummaryDone');
  if (sp && sd) {
    const seated = Object.keys(emState.seats).filter(k=>emState.seats[k]&&!emState.seats[k].is_invig&&!k.startsWith('INV')).length;
    sd.innerHTML = `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:rgba(45,212,170,.08);border:1px solid rgba(45,212,170,.3);border-radius:8px">
      <i class="fas fa-circle-check" style="color:#2dd4aa;font-size:1.4rem"></i>
      <div>
        <div style="font-weight:700;color:#2dd4aa;font-size:.95rem">Exam Setup Complete</div>
        <div style="font-size:.75rem;color:var(--muted)">Status updated to <strong style="color:#2dd4aa">Scheduled</strong></div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:16px">
      ${[['College',emState.collegeName],['Department',emState.deptName],['Subject',emState.courseCode],['Room',emState.hallName],['Students seated',''+seated],['Invigilators',''+savedInvigs]]
        .map(([lbl,val])=>`<div style="background:rgba(15,118,110,.05);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:12px">
          <div style="font-size:.66rem;color:var(--muted);margin-bottom:4px">${lbl}</div>
          <div style="font-weight:700;color:var(--text);font-size:.92rem">${val}</div>
        </div>`).join('')}
    </div>
    <div style="margin-bottom:14px">
      <div style="font-size:.72rem;color:var(--muted);font-weight:700;letter-spacing:.05em;margin-bottom:8px">SEAT LAYOUT PREVIEW</div>
      ${seatPreviewHtml}
      <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:.68rem;margin-top:4px">
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:rgba(20,184,166,.2);border:1px solid rgba(20,184,166,.5);display:inline-block"></span><span style="color:var(--muted)">Assigned</span></span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:rgba(45,212,170,.35);border:1px solid #2dd4aa;display:inline-block"></span><span style="color:var(--muted)">Present</span></span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:rgba(231,111,81,.35);border:1px solid #e76f51;display:inline-block"></span><span style="color:var(--muted)">Absent</span></span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:rgba(167,139,250,.3);border:1px solid #a78bfa;display:inline-block"></span><span style="color:var(--muted)">Invigilator</span></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn-primary" onclick="saIssueAdmitsEM()"><i class="fas fa-id-card"></i> Issue Admit Cards</button>
      <button class="btn-secondary" onclick="emResetWizard()"><i class="fas fa-plus"></i> New Setup</button>
    </div>`;
    sp.style.display='block';
    sp.scrollIntoView({behavior:'smooth',block:'start'});
  }
  showToast('Exam setup complete! Status → Scheduled. '+savedInvigs+' invigilators saved.','success');
  saLoadExams(); // refresh exam list tab — status now shows "scheduled"
}

function emSyncCollegeFromDropdown() {
  // Like emInitCollegeFromDropdown but does NOT reset dept/course/hall
  // when the college hasn't actually changed — so user selections survive navigation.
  const sel = document.getElementById('emCollegeSelect');
  if (!sel) return;

  // Handle BOTH <select> and <input type="hidden"> (scoped/principal mode)
  let val, name, code;
  if (sel.tagName === 'SELECT') {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || !opt.dataset.name) return;
    val  = opt.value;
    name = opt.dataset.name;
    code = opt.dataset.code || '';
  } else {
    // <input type="hidden"> — read directly from element attributes
    val  = sel.value;
    name = sel.dataset.name || '';
    code = sel.dataset.code || '';
    if (!val || !name) return;
  }

  const newId = parseInt(val);
  if (newId === emState.college) {
    // College unchanged — just ensure name/code are set, leave downstream state intact
    emState.collegeName = emState.collegeName || name;
    emState.collegeCode = emState.collegeCode || code;
    return;
  }
  // College changed — full reset via emSelectCollege
  emSelectCollege(newId, name, code);
}

function emInitCollegeFromDropdown() {
  const sel = document.getElementById('emCollegeSelect');
  if (!sel) return;
  const val = sel.value;
  if (!val) return;
  // Works for both <select> and <input type="hidden">
  const name = sel.dataset?.name
    || (sel.tagName === 'SELECT' ? sel.options[sel.selectedIndex]?.dataset?.name : '')
    || '';
  const code = sel.dataset?.code
    || (sel.tagName === 'SELECT' ? sel.options[sel.selectedIndex]?.dataset?.code : '')
    || '';
  if (name) {
    emSelectCollege(parseInt(val), name, code);
  }
}

function emResetWizard() {
  Object.assign(emState, {
    step:1,college:null,collegeName:'',collegeCode:'',
    dept:null,deptName:'',deptCode:'',
    course:null,courseName:'',courseCode:'',courseSem:'',
    hall:null,hallName:'',hallCapacity:60,hallRows:6,hallCols:10,
    scheduleId:null,examId:null,examTitle:'',
    examType:'mid_term',examDate:'',examMaxMarks:100,examPassMarks:40,
    examSem:3,examAcadYear:'2025-26',
    students:[],seats:{},invigilators:{},facultyCache:{}
  });
  document.getElementById('emSummaryPanel').style.display='none';
  // Clear Step 1 fields (title & date only; keep college dropdown selection)
  ['emTitle','emDate'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  // Re-initialise college from whichever option is currently selected
  emInitCollegeFromDropdown();
  emGoStep(1);
}

async function saIssueAdmitsEM() {
  if (!emState.scheduleId) { showToast('No schedule ID — save schedule first (Step 4)','warning'); return; }
  if (!confirm('Issue admit cards for all seated students?')) return;
  const r = await postAction({ajax_action:'sa_issue_admits',schedule_id:emState.scheduleId});
  if (r?.ok) showToast('✓ '+r.msg,'success');
  else showToast(r?.msg||'Error','error');
}


// ══════════════════════════════════════════════════════════════════════════
// EXAM MANAGEMENT — ADVANCED FEATURES
// ══════════════════════════════════════════════════════════════════════════

// ── Exam stats dashboard ──────────────────────────────────────────────────
async function saLoadExamStats() {
  var college_id = document.getElementById('saExamCollege')?.value || '';
  var r = await postAction({ajax_action: 'sa_exam_stats', college_id: college_id});
  if (!r?.ok) return;
  var set = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
  set('emStat-total',     r.total     || 0);
  set('emStat-upcoming',  r.upcoming  || 0);
  set('emStat-scheduled', r.by_status?.scheduled  || 0);
  set('emStat-completed', r.by_status?.completed  || 0);
  set('emStat-conflicts', r.conflicts || 0);
  set('emStat-published', r.by_status?.published  || 0);
  // Conflict badge in tab
  var badge = document.getElementById('emConflictBadge');
  if (badge) {
    badge.textContent = r.conflicts;
    badge.style.display = r.conflicts > 0 ? 'inline' : 'none';
  }
  // Conflict card highlight
  var card = document.getElementById('emStat-conflictCard');
  if (card && r.conflicts > 0) {
    card.style.borderColor = 'rgba(239,68,68,.45)';
    card.style.background  = 'rgba(239,68,68,.06)';
  }
}

// ── Exam List: tab switch enhancement ────────────────────────────────────
var _origEmSwitchTab = null;
document.addEventListener('DOMContentLoaded', function() {
  _origEmSwitchTab = emSwitchTab;
  // Override to handle conflicts tab + stats reload
  window.emSwitchTab = function(tab) {
    ['wizard','list','qpaper','conflicts','halls'].forEach(function(t) {
      var p = document.getElementById('emPanel-' + t);
      var b = document.getElementById('emTab-' + t);
      if (p) p.style.display = t === tab ? '' : 'none';
      if (b) {
        b.style.borderColor = t === tab ? 'rgba(20,184,166,.4)' : '';
        b.style.color = t === tab ? 'var(--teal)' : '';
      }
    });
    if (tab === 'list') saLoadExamStats();
  };
  saLoadExamStats();
  // HOD/Principal: default to Exam List tab and auto-load
  if (emExamReadOnly) {
    emSwitchTab('list');
    saLoadExams();
  }
});

// ── Local search / filter on exam list (no server roundtrip) ─────────────
function saFilterExamsLocal() {
  var q = (document.getElementById('saExamSearch')?.value || '').toLowerCase();
  var rows = document.querySelectorAll('#saExamListWrap tbody tr[data-title]');
  rows.forEach(function(row) {
    var title = (row.dataset.title || '').toLowerCase();
    row.style.display = (!q || title.includes(q)) ? '' : 'none';
  });
}

// ── saLoadExams: upgraded with status filter + action buttons ─────────────
var _origSaLoadExams = saLoadExams;
saLoadExams = async function() {
  var wrap = document.getElementById('saExamListWrap');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:12px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
  var college_id    = document.getElementById('saExamCollege')?.value    || '';
  var department_id = document.getElementById('saExamDept')?.value       || '';
  var status_filter = document.getElementById('saExamStatus')?.value     || '';
  var r = await postAction({ajax_action:'sa_list_exams', college_id, department_id});
  if (!r?.ok || !r.exams?.length) {
    wrap.innerHTML = '<div style="padding:16px;color:var(--muted);text-align:center"><i class="fas fa-inbox" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>No exams found.</div>';
    saLoadExamStats();
    return;
  }
  var exams = status_filter ? r.exams.filter(function(e){ return e.status === status_filter; }) : r.exams;

  var statusColor = {
    draft:'rgba(148,163,184,.8)', upcoming:'var(--teal)', ongoing:'#f59e0b',
    completed:'#2dd4aa', published:'#a78bfa', cancelled:'#ef4444'
  };
  var statusBg = {
    draft:'rgba(148,163,184,.1)', upcoming:'rgba(20,184,166,.1)', ongoing:'rgba(245,158,11,.1)',
    completed:'rgba(45,212,170,.1)', published:'rgba(167,139,250,.1)', cancelled:'rgba(239,68,68,.1)'
  };

  // ── Store exams in a map so edit button can look up by id (avoids JSON.stringify breakage in onclick) ──
  window._saExamMap = {};
  exams.forEach(function(e) { window._saExamMap[e.id] = e; });

  var html = '<div style="overflow-x:auto"><table id="saExamTable" style="width:100%;border-collapse:collapse;font-size:.8rem">';
  html += '<thead><tr style="border-bottom:2px solid var(--border)">';
  var _headers = emExamReadOnly
    ? ['Exam Title','Course','Department','Scheduled','Hall','Admits','Invigs','Status']
    : ['Exam Title','Course','Department','Scheduled','Hall','Admits','Invigs','Status','Actions'];
  _headers.forEach(function(h) {
    html += '<th style="text-align:left;padding:9px 10px;color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap">' + h + '</th>';
  });
  html += '</tr></thead><tbody>';

  exams.forEach(function(e) {
    var sc = statusColor[e.status] || 'var(--muted)';
    var sb = statusBg[e.status]   || 'rgba(255,255,255,.05)';
    var schedCell = e.sched_date
      ? e.sched_date + '<br><span style="color:var(--muted)">' + (e.start_time ? e.start_time.slice(0,5) + '–' + (e.end_time?.slice(0,5)||'') : '') + '</span>'
      : '<span style="color:var(--muted)">—</span>';
    var canDelete = ['draft','upcoming','cancelled'].includes(e.status);
    html += '<tr style="border-bottom:1px solid var(--border);transition:background .12s" data-title="' + emEsc(e.title) + '" onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\'\'">';
    html += '<td style="padding:9px 10px"><strong style="color:var(--text)">' + emEsc(e.title) + '</strong>'
          + '<br><span style="color:var(--muted);font-size:.7rem">Sem ' + (e.semester||'—') + ' · ' + (e.academic_year||'') + '</span></td>';
    html += '<td style="padding:9px 10px">' + emEsc(e.course_name)
          + '<br><span style="font-family:monospace;font-size:.7rem;color:var(--muted)">' + emEsc(e.course_code) + '</span></td>';
    html += '<td style="padding:9px 10px;font-size:.78rem;color:var(--muted)">' + emEsc(e.dept_name) + '</td>';
    html += '<td style="padding:9px 10px;font-size:.78rem">' + schedCell + '</td>';
    html += '<td style="padding:9px 10px;font-size:.78rem">' + (e.hall_name ? emEsc(e.hall_name) : '<span style="color:var(--muted)">—</span>') + '</td>';
    html += '<td style="padding:9px 10px;text-align:center;font-size:.82rem;color:' + (e.admit_cards_issued>0?'#2dd4aa':'var(--muted)') + '">' + (e.admit_cards_issued||0) + '</td>';
    html += '<td style="padding:9px 10px;text-align:center;font-size:.82rem;color:' + (e.invig_count>0?'var(--teal)':'var(--muted)') + '">' + (e.invig_count||0) + '</td>';
    html += '<td style="padding:9px 10px"><span style="padding:3px 10px;border-radius:50px;font-size:.7rem;font-weight:700;background:' + sb + ';color:' + sc + '">' + (e.status||'—') + '</span></td>';
    if (!emExamReadOnly) {
      html += '<td style="padding:9px 10px;white-space:nowrap">'
            + '<button title="Edit exam" onclick="openEditExamModal(window._saExamMap[' + e.id + '])" style="background:none;border:1px solid rgba(20,184,166,.3);color:var(--teal);padding:4px 8px;border-radius:5px;cursor:pointer;font-size:.72rem;margin-right:4px"><i class="fas fa-pencil"></i></button>'
            + (canDelete ? '<button title="Delete exam" onclick="saDeleteExam(' + e.id + ',\'' + emEsc(e.title) + '\')" style="background:none;border:1px solid rgba(239,68,68,.3);color:#ef4444;padding:4px 8px;border-radius:5px;cursor:pointer;font-size:.72rem"><i class="fas fa-trash"></i></button>' : '')
            + '</td>';
    }
    html += '</tr>';
  });
  html += '</tbody></table></div>';
  wrap.innerHTML = html;
  saLoadExamStats();
};

// ── Edit exam modal ───────────────────────────────────────────────────────
function openEditExamModal(exam) {
  if (!exam) { console.error('openEditExamModal: exam not found in _saExamMap'); return; }
  document.getElementById('ee_id').value     = exam.id;
  document.getElementById('ee_title').value  = exam.title || '';
  document.getElementById('ee_type').value   = exam.type  || 'mid_term';
  document.getElementById('ee_status').value = exam.status || 'upcoming';
  document.getElementById('ee_date').value   = exam.exam_date || '';
  document.getElementById('ee_max').value    = exam.max_marks  || 100;
  document.getElementById('ee_pass').value   = exam.pass_marks || 40;
  document.getElementById('ee_remarks').value= exam.remarks || '';
  var alert = document.getElementById('alertEditExam');
  if (alert) { alert.className = 'form-alert'; alert.textContent = ''; }
  openModal('modalEditExam');
}

async function submitEditExam() {
  var btn = document.getElementById('btnEditExam');
  var id    = document.getElementById('ee_id').value;
  var title = document.getElementById('ee_title').value.trim();
  var date  = document.getElementById('ee_date').value;
  if (!title || !date) { showAlert('alertEditExam','Title and Date are required.'); return; }
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  var r = await postAction({
    ajax_action: 'sa_edit_exam',
    exam_id:     id,
    title:       title,
    type:        document.getElementById('ee_type').value,
    status:      document.getElementById('ee_status').value,
    exam_date:   date,
    max_marks:   document.getElementById('ee_max').value,
    pass_marks:  document.getElementById('ee_pass').value,
    remarks:     document.getElementById('ee_remarks').value,
  });
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Changes';
  if (r?.ok) {
    showAlert('alertEditExam', '✓ Exam updated!', 'success');
    setTimeout(function(){ closeModal('modalEditExam'); saLoadExams(); }, 900);
  } else {
    showAlert('alertEditExam', r?.msg || 'Error saving exam');
  }
}

async function saDeleteExam(id, title) {
  if (!confirm('Delete exam "' + title + '"?\nThis will also remove its schedule, seating, and admit cards.')) return;
  var r = await postAction({ajax_action: 'sa_delete_exam', exam_id: id});
  if (r?.ok) { showToast('✓ ' + r.msg, 'success'); saLoadExams(); }
  else showToast(r?.msg || 'Error', 'error');
}

// ── Conflict detector ─────────────────────────────────────────────────────
async function saLoadConflicts() {
  var wrap = document.getElementById('saConflictsWrap');
  if (!wrap) return;
  wrap.innerHTML = '<p style="color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
  var college_id = document.getElementById('saExamCollege')?.value || '';
  var r = await postAction({ajax_action: 'sa_get_conflicts', college_id: college_id});
  if (!r?.ok || !r.conflicts?.length) {
    wrap.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted)"><i class="fas fa-circle-check" style="color:#2dd4aa;font-size:1.5rem;display:block;margin-bottom:8px"></i>No unresolved conflicts found.</div>';
    return;
  }
  var typeIcon = {
    hall_double_booked:          'fas fa-building',
    invigilator_double_assigned: 'fas fa-user-tie',
    student_double_exam:         'fas fa-user-graduate',
    time_overlap:                'fas fa-clock'
  };
  var typeLabel = {
    hall_double_booked:          'Hall Double-Booked',
    invigilator_double_assigned: 'Invigilator Double-Assigned',
    student_double_exam:         'Student Double-Exam',
    time_overlap:                'Time Overlap'
  };
  var html = '<div style="display:flex;flex-direction:column;gap:10px">';
  r.conflicts.forEach(function(c) {
    var icon  = typeIcon[c.conflict_type]  || 'fas fa-exclamation';
    var label = typeLabel[c.conflict_type] || c.conflict_type;
    html += '<div style="padding:14px 16px;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm)">';
    html += '<div style="display:flex;align-items:flex-start;gap:12px">';
    html += '<div style="width:32px;height:32px;background:rgba(245,158,11,.15);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#f59e0b"><i class="' + icon + '"></i></div>';
    html += '<div style="flex:1">';
    html += '<div style="font-weight:700;color:var(--text);font-size:.85rem;margin-bottom:2px">' + label + '</div>';
    html += '<div style="font-size:.78rem;color:var(--muted);margin-bottom:4px">' + emEsc(c.description || '') + '</div>';
    if (c.exam1_title || c.exam2_title) {
      html += '<div style="font-size:.74rem;color:rgba(245,158,11,.8)">';
      if (c.exam1_title) html += '<span style="margin-right:8px"><i class="fas fa-file-alt" style="font-size:.65rem"></i> ' + emEsc(c.exam1_title) + '</span>';
      if (c.exam2_title) html += '<span><i class="fas fa-file-alt" style="font-size:.65rem"></i> ' + emEsc(c.exam2_title) + '</span>';
      html += '</div>';
    }
    html += '</div>';
    html += '<span style="font-size:.68rem;color:var(--muted);white-space:nowrap">' + (c.created_at ? c.created_at.slice(0,10) : '') + '</span>';
    html += '</div></div>';
  });
  html += '</div>';
  wrap.innerHTML = html;
}


// ── Exam Halls Manager ────────────────────────────────────────────────────
async function emLoadHallsManager() {
  var wrap = document.getElementById('emHallsManagerWrap');
  if (!wrap) return;
  var college_id = document.getElementById('saExamCollege')?.value || '<?= (int)$saCollegeId ?>';
  if (!college_id) {
    wrap.innerHTML = '<p style="color:var(--muted);text-align:center;padding:20px">Select a college first.</p>';
    return;
  }
  wrap.innerHTML = '<p style="color:var(--muted);text-align:center;padding:20px"><i class="fas fa-spinner fa-spin"></i> Loading halls\u2026</p>';
  var r = await postAction({ajax_action:'sa_list_exam_halls', college_id: college_id});
  if (!r?.ok) { wrap.innerHTML = '<p style="color:#ef4444;padding:16px">Failed to load halls.</p>'; return; }
  if (!r.halls?.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted)"><i class="fas fa-door-closed" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4"></i>No exam halls yet. Click <b>Add Hall</b> to create one.</div>';
    return;
  }
  var html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">';
  html += '<thead><tr style="border-bottom:2px solid var(--border2)">';
  ['Hall Name','Code','Capacity','Building','Floor','Projector','AC','Status',''].forEach(function(h) {
    html += '<th style="padding:10px 12px;text-align:left;font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">' + h + '</th>';
  });
  html += '</tr></thead><tbody>';
  r.halls.forEach(function(h) {
    var active = h.status === 'active';
    html += '<tr style="border-bottom:1px solid var(--border2)">';
    html += '<td style="padding:10px 12px;font-size:.84rem;font-weight:600;color:var(--text)">' + emEsc(h.name) + '</td>';
    html += '<td style="padding:10px 12px;font-size:.78rem;font-family:var(--mono);color:var(--muted)">' + (h.code || '\u2014') + '</td>';
    html += '<td style="padding:10px 12px;font-size:.84rem;color:var(--text)">' + h.capacity + '</td>';
    html += '<td style="padding:10px 12px;font-size:.78rem;color:var(--muted)">' + (h.building || '\u2014') + '</td>';
    html += '<td style="padding:10px 12px;font-size:.78rem;color:var(--muted)">' + (h.floor || '\u2014') + '</td>';
    html += '<td style="padding:10px 12px;text-align:center">' + (h.has_projector == 1 ? '<i class="fas fa-check" style="color:#2dd4aa"></i>' : '<i class="fas fa-xmark" style="color:var(--muted)"></i>') + '</td>';
    html += '<td style="padding:10px 12px;text-align:center">' + (h.has_ac == 1 ? '<i class="fas fa-check" style="color:#2dd4aa"></i>' : '<i class="fas fa-xmark" style="color:var(--muted)"></i>') + '</td>';
    html += '<td style="padding:10px 12px"><span style="font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:20px;background:' + (active ? 'rgba(45,212,170,.15)' : 'rgba(148,163,184,.12)') + ';color:' + (active ? '#0f766e' : 'var(--muted)') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>';
    html += '<td style="padding:10px 12px">' + (active ? '<button onclick="deleteExamHall(' + h.id + ',\'' + emEsc(h.name).replace(/'/g,"\\'") + '\')" style="background:none;border:1px solid rgba(239,68,68,.35);color:#ef4444;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:.74rem;font-family:inherit"><i class="fas fa-trash"></i></button>' : '') + '</td>';
    html += '</tr>';
  });
  html += '</tbody></table></div>';
  wrap.innerHTML = html;
}

function openExamHallModal() {
  ['ehName','ehCode','ehCapacity','ehBuilding','ehFloor'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  document.getElementById('ehProjector').checked = false;
  document.getElementById('ehAC').checked = false;
  var msg = document.getElementById('examHallModalMsg');
  msg.style.display = 'none';
  document.getElementById('examHallModal').style.display = 'flex';
}

function closeExamHallModal() {
  document.getElementById('examHallModal').style.display = 'none';
}

async function saveExamHall() {
  var name     = document.getElementById('ehName').value.trim();
  var code     = document.getElementById('ehCode').value.trim();
  var capacity = document.getElementById('ehCapacity').value.trim();
  var building = document.getElementById('ehBuilding').value.trim();
  var floor    = document.getElementById('ehFloor').value.trim();
  var projector= document.getElementById('ehProjector').checked ? 1 : 0;
  var ac       = document.getElementById('ehAC').checked ? 1 : 0;
  var msg      = document.getElementById('examHallModalMsg');
  var btn      = document.getElementById('ehSaveBtn');
  if (!name || !capacity) {
    msg.style.cssText = 'display:block;padding:9px 13px;border-radius:8px;font-size:.8rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444';
    msg.textContent = 'Hall name and capacity are required.';
    return;
  }
  var college_id = document.getElementById('saExamCollege')?.value || '<?= (int)$saCollegeId ?>';
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';
  var r = await postAction({ajax_action:'sa_create_exam_hall', college_id, name, code, capacity, building, floor, has_projector:projector, has_ac:ac});
  btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Hall';
  if (r?.ok) {
    closeExamHallModal();
    emLoadHallsManager();
    emLoadHalls(); // refresh Step 4 hall cards too
  } else {
    msg.style.cssText = 'display:block;padding:9px 13px;border-radius:8px;font-size:.8rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444';
    msg.textContent = r?.msg || 'Failed to save hall.';
  }
}

async function deleteExamHall(id, name) {
  if (!confirm('Remove hall "' + name + '"? It will be set inactive and no longer available for new scheduling.')) return;
  var r = await postAction({ajax_action:'sa_delete_exam_hall', hall_id: id});
  if (r?.ok) { emLoadHallsManager(); emLoadHalls(); }
  else alert(r?.msg || 'Failed to remove hall.');
}

// ── Step 5 bulk mark attendance ───────────────────────────────────────────
async function emBulkMark(mark) {
  var label = mark === 'present' ? 'ALL PRESENT' : 'ALL ABSENT';
  if (!confirm('Mark ' + label + ' for all assigned students?')) return;
  // Update local state
  Object.keys(emState.seats).forEach(function(k) {
    var seat = emState.seats[k];
    if (seat && !seat.is_invig) {
      seat.is_present = mark === 'present' ? 1 : 0;
    }
  });
  emRenderGrid();
  // Persist to server if schedule exists
  if (emState.scheduleId) {
    var r = await postAction({ajax_action:'sa_bulk_mark_attendance', schedule_id:emState.scheduleId, mark:mark});
    if (r?.ok) showToast('✓ ' + label + ' — attendance saved', 'success');
    else showToast('Attendance updated locally only', 'warning');
  } else {
    showToast(label + ' marked (save schedule to persist)', 'success');
  }
}

// ── Export attendance CSV ─────────────────────────────────────────────────
async function emExportAttendance() {
  if (!emState.scheduleId) {
    // Export from local state
    var rows = [['Seat','Roll Number','Full Name','Department','Attendance']];
    Object.keys(emState.seats).sort().forEach(function(k) {
      var s = emState.seats[k];
      if (!s || s.is_invig) return;
      var m = k.match(/R(\d+)C(\d+)/);
      var r = m ? parseInt(m[1]) : 0, c = m ? parseInt(m[2]) : 0;
      var rl = r<=26 ? String.fromCharCode(64+r) : 'R'+r;
      var seat = rl + '-' + String(c).padStart(2,'0');
      var att = s.is_present===1 ? 'Present' : s.is_present===0 ? 'Absent' : 'Not Marked';
      rows.push([seat, s.roll||'', s.name||'', s.dept||emState.deptName||'', att]);
    });
    _emDownloadCsv(rows, 'attendance_' + (emState.examTitle||'exam').replace(/\s+/g,'_') + '.csv');
    return;
  }
  var r = await postAction({ajax_action:'sa_export_attendance', schedule_id:emState.scheduleId});
  if (!r?.ok) { showToast('Export failed', 'error'); return; }
  var rows = [['Seat','Roll Number','Full Name','Department','Attendance']];
  (r.rows||[]).forEach(function(row) {
    rows.push([row.seat_number||'', row.roll_number||'', row.full_name||'', row.dept_name||'', row.attendance||'']);
  });
  _emDownloadCsv(rows, 'attendance_schedule_' + emState.scheduleId + '.csv');
}

function _emDownloadCsv(rows, filename) {
  var csv = rows.map(function(row) {
    return row.map(function(v) {
      var s = String(v||'').replace(/"/g, '""');
      return /[,\n"]/.test(s) ? '"' + s + '"' : s;
    }).join(',');
  }).join('\n');
  var blob = new Blob([csv], {type: 'text/csv'});
  var url  = URL.createObjectURL(blob);
  var a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
  showToast('CSV downloaded: ' + filename, 'success');
}

// ── Print seating chart ───────────────────────────────────────────────────
function emPrintSeating() {
  var rows = emState.hallRows || 6;
  var cols = emState.hallCols || 10;
  var title = emState.examTitle || 'Exam';
  var hall  = emState.hallName  || 'Hall';
  var dept  = emState.deptName  || '';

  var html = '<!DOCTYPE html><html><head><title>Seating Chart — ' + title + '</title>';
  html += '<style>body{font-family:Arial,sans-serif;padding:20px;color:#000}';
  html += 'h2{margin-bottom:4px}p{margin:0 0 12px;font-size:13px;color:#555}';
  html += 'table{border-collapse:collapse;width:100%;margin-bottom:16px}';
  html += 'th,td{border:1px solid #ccc;padding:6px 8px;font-size:11px;text-align:center}';
  html += 'th{background:#f0f0f0;font-weight:600}';
  html += '.assigned{background:#e6fff9} .invig{background:#f0eeff}';
  html += '@media print{button{display:none}}</style></head><body>';
  html += '<h2>Seating Chart — ' + title + '</h2>';
  html += '<p>' + dept + ' · ' + hall + ' · ' + rows + '×' + cols + ' grid · Generated ' + new Date().toLocaleString() + '</p>';
  html += '<button onclick="window.print()" style="margin-bottom:16px;padding:6px 16px;cursor:pointer">🖨 Print</button>';
  html += '<table><thead><tr><th>Seat</th><th>Roll No.</th><th>Student Name</th><th>Department</th><th>Attendance</th></tr></thead><tbody>';

  for (var r = 1; r <= rows; r++) {
    for (var c = 1; c <= cols; c++) {
      var key = 'R' + r + 'C' + c;
      var rl  = r<=26 ? String.fromCharCode(64+r) : 'R'+r;
      var seatLabel = rl + '-' + String(c).padStart(2,'0');
      var seat = emState.seats[key];
      if (!seat) {
        html += '<tr><td>' + seatLabel + '</td><td colspan="4" style="color:#ccc">Empty</td></tr>';
      } else if (seat.is_invig) {
        html += '<tr class="invig"><td>' + seatLabel + '</td><td colspan="4">— Invigilator —</td></tr>';
      } else {
        var att = seat.is_present===1 ? '✓ Present' : seat.is_present===0 ? '✗ Absent' : '—';
        html += '<tr class="assigned"><td>' + seatLabel + '</td><td>' + emEsc(seat.roll||'') + '</td><td>' + emEsc(seat.name||'') + '</td><td>' + emEsc(seat.dept||dept) + '</td><td>' + att + '</td></tr>';
      }
    }
  }
  html += '</tbody></table></body></html>';

  var win = window.open('', '_blank');
  win.document.write(html);
  win.document.close();
}

// Initialise wizard tab state on page load
document.addEventListener('DOMContentLoaded', () => {
  emSwitchTab('wizard');
  // Auto-register college for exam management
  emInitCollegeFromDropdown();
  // Populate semester dropdown from HOD dept (or college) on load
  emLoadSemesterDropdown();

  <?php if ($saCollegeId): ?>
  // Scoped mode — auto-init timetable and attendance college cascade
  ttCollegeChange();
  attCollegeChange();
  <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
  attDeptChange(); // pre-filter courses to HOD dept
  <?php endif; ?>
  <?php endif; ?>
});
</script>

<script>
// ══════════════════════════════════════════════════════════════════════════
//  COLLEGE & ROLE SELECTION SYSTEM
// ══════════════════════════════════════════════════════════════════════════

let CS = {
  collegeId: <?= (int)$saCollegeId ?>,
  collegeName: <?= json_encode($saCollegeName) ?>,
  role: <?= json_encode($saCollegeRole) ?>, // 'superadmin' | 'principal' | 'hod' | ''
  deptId: <?= (int)$saDeptId ?>,
  deptName: <?= json_encode($deptNameDisplay ?? '') ?>
};

// On page load — apply role to <body> and UI
(function initRoleUI() {
  const role = CS.role || (CS.collegeId ? '' : 'superadmin');
  applyRole(role || 'superadmin');
})();

function applyRole(role) {
  const body = document.body;
  body.classList.remove('role-superadmin','role-principal','role-hod');

  const badge  = document.getElementById('sidebarRoleBadge');
  const label  = document.getElementById('sidebarRoleLabel');
  const header = document.querySelector('.header-left h1');
  const ctx    = document.getElementById('sidebarCollegeCtx');
  const cname  = document.getElementById('sidebarCollegeName');

  if (role === 'principal') {
    body.classList.add('role-principal');
    if (badge) { badge.className='admin-badge role-badge-principal'; }
    if (label) label.textContent = 'Principal';
    if (header) header.textContent = 'Principal Dashboard';
    if (ctx) ctx.style.display = '';
    if (cname) cname.textContent = CS.collegeName;
  } else if (role === 'hod') {
    body.classList.add('role-hod');
    if (badge) { badge.className='admin-badge role-badge-hod'; }
    if (label) label.textContent = 'Head of Department';
    if (header) header.textContent = 'HOD Dashboard';
    if (ctx) ctx.style.display = '';
    if (cname) cname.textContent = CS.collegeName;
    // Update existing sidebarHodDept (never insert a new one — PHP already rendered it)
    let deptBar = document.getElementById('sidebarHodDept');
    if (deptBar && CS.deptId) {
      const span = deptBar.querySelector('span');
      if (span) span.textContent = CS.deptName || 'Department';
      deptBar.style.display = 'flex';
    }
  } else {
    // superadmin
    body.classList.add('role-superadmin');
    if (badge) { badge.className='admin-badge role-badge-sa'; }
    if (label) label.textContent = 'Super Admin';
    if (header) header.textContent = 'Super Admin Dashboard';
    if (ctx && !CS.collegeId) ctx.style.display = 'none';
  }

  CS.role = role;

  // Explicitly control nav-col-mgmt and nav-sa-only nav-sections visibility
  // nav-col-mgmt: visible to principal + superadmin, NOT hod
  document.querySelectorAll('.nav-col-mgmt').forEach(el => {
    el.style.display = (role === 'principal' || role === 'superadmin') ? 'flex' : 'none';
  });
  // nav-sa-only nav-sections: visible ONLY to superadmin
  document.querySelectorAll('.nav-section.nav-sa-only').forEach(el => {
    el.style.display = (role === 'superadmin') ? 'block' : 'none';
  });
  // nav-sa-only links (inside non-sa sections, if any): only superadmin
  document.querySelectorAll('a.nav-sa-only, .nav-link.nav-sa-only').forEach(el => {
    el.style.display = (role === 'superadmin') ? 'flex' : 'none';
  });

  applyViewRestrictions(role);
}

function applyViewRestrictions(role) {
  // HOD cannot access: add-college, global users, system settings
  // These are hidden via CSS already through nav class toggling
}

// ════════════════════════════════════════════════════════════════════════
// College Chooser — multi-step access-code flow
// Flow:  Step1(pick college) → Step1b(college code) → Step2(pick role)
//        → if Principal: Step2b_principal(re-enter college code) → dashboard
//        → if HOD:       Step2b_hod(pick dept → enter HOD code set by principal) → dashboard
// ════════════════════════════════════════════════════════════════════════
let _csSelectedCollege = { id: 0, name: '', code: '' };
let _csSelectedRole    = '';
let _csSelectedDeptId  = 0;

const CS_STEPS = ['csStep1','csStep1b','csStep2','csStep2b_principal','csStep2b_hod'];

function _csShowOnly(stepId) {
  CS_STEPS.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = (id === stepId) ? '' : 'none';
  });
}

// ── Step 1 → 1b ──────────────────────────────────────────────────────────
function csSelectCollege(id, name, code) {
  _csSelectedCollege = { id, name, code };
  const nameEl = document.getElementById('csStep1bCollegeName');
  if (nameEl) nameEl.textContent = name;
  const inp = document.getElementById('csCollegeCodeInput');
  const err = document.getElementById('csCollegeCodeErr');
  if (inp) { inp.value = ''; inp.style.borderColor = 'rgba(255,255,255,.12)'; }
  if (err) { err.style.display = 'none'; }
  _csShowOnly('csStep1b');
  setTimeout(() => { if (inp) inp.focus(); }, 120);
}

function csBackToStep1() { _csShowOnly('csStep1'); }

// ── Step 1b: verify college code ─────────────────────────────────────────
async function csVerifyCollegeCode() {
  const inp = document.getElementById('csCollegeCodeInput');
  const err = document.getElementById('csCollegeCodeErr');
  const btn = document.getElementById('csCollegeCodeBtn');
  const code = inp ? inp.value.trim() : '';
  if (!code) { if(err){err.textContent='Please enter the access code.';err.style.display='block';} inp&&inp.focus(); return; }
  btn&&(btn.disabled=true, btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> &nbsp;Verifying…');
  if(err) err.style.display='none';
  try {
    const fd=new FormData();
    fd.append('ajax_action','verify_access_code');
    fd.append('college_id',_csSelectedCollege.id);
    fd.append('step','college'); fd.append('code',code);
    const data = await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
    if (data.ok) {
      const el=document.getElementById('csSelectedCollegeName');
      if(el) el.textContent=_csSelectedCollege.name;
      _csShowOnly('csStep2');
    } else {
      if(err){err.textContent=data.msg||'Incorrect code.';err.style.display='block';}
      if(inp){inp.value='';inp.style.borderColor='#ef4444';inp.focus();}
    }
  } catch(e) { if(err){err.textContent='Network error.';err.style.display='block';} }
  finally { btn&&(btn.disabled=false, btn.innerHTML='<i class="fas fa-unlock"></i> &nbsp;Verify &amp; Continue'); }
}

function csBackToStep1b() {
  const inp=document.getElementById('csCollegeCodeInput'); if(inp) inp.value='';
  _csShowOnly('csStep1b');
}

// ── Step 2: role selected ─────────────────────────────────────────────────
function csSelectRole(role) {
  _csSelectedRole = role;
  _csSelectedDeptId = 0;

  if (role === 'principal') {
    // Show principal verify panel
    const el=document.getElementById('cs2bpCollegeName'); if(el) el.textContent=_csSelectedCollege.name;
    const inp=document.getElementById('csPrincipalCodeInput');
    const err=document.getElementById('csPrincipalCodeErr');
    if(inp){inp.value='';inp.style.borderColor='rgba(255,255,255,.12)';}
    if(err){err.style.display='none';}
    _csShowOnly('csStep2b_principal');
    setTimeout(()=>{ if(inp) inp.focus(); },120);

  } else {
    // HOD — show dept picker first, load dept list
    const el=document.getElementById('cs2bHodCollegeName'); if(el) el.textContent=_csSelectedCollege.name;
    const codeBox=document.getElementById('csHodStep_code');
    if(codeBox) codeBox.style.display='none';
    const inp=document.getElementById('csHodCodeInput');
    const err=document.getElementById('csHodCodeErr');
    if(inp){inp.value='';inp.style.borderColor='rgba(255,255,255,.12)';}
    if(err){err.style.display='none';}
    _csShowOnly('csStep2b_hod');
    csLoadDepartments();
  }
}

function csBackToStep2() {
  _csSelectedRole=''; _csSelectedDeptId=0;
  _csShowOnly('csStep2');
}

// ── Principal: re-verify with college code ────────────────────────────────
async function csVerifyPrincipalCode() {
  const inp=document.getElementById('csPrincipalCodeInput');
  const err=document.getElementById('csPrincipalCodeErr');
  const btn=document.getElementById('csPrincipalCodeBtn');
  const code=inp?inp.value.trim():'';
  if(!code){if(err){err.textContent='Please enter the access code.';err.style.display='block';}inp&&inp.focus();return;}
  btn&&(btn.disabled=true,btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> &nbsp;Verifying…');
  if(err) err.style.display='none';
  try {
    const fd=new FormData();
    fd.append('ajax_action','verify_access_code');
    fd.append('college_id',_csSelectedCollege.id);
    fd.append('step','role'); fd.append('role','principal'); fd.append('code',code);
    const data=await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
    if(data.ok) {
      await csConfirmSelection('principal',0);
    } else {
      if(err){err.textContent=data.msg||'Incorrect code.';err.style.display='block';}
      if(inp){inp.value='';inp.style.borderColor='#ef4444';inp.focus();}
    }
  } catch(e){if(err){err.textContent='Network error.';err.style.display='block';}}
  finally{btn&&(btn.disabled=false,btn.innerHTML='<i class="fas fa-unlock"></i> &nbsp;Verify &amp; Enter Dashboard');}
}

// ── HOD: load departments into picker ────────────────────────────────────
async function csLoadDepartments() {
  const sel=document.getElementById('csHodDeptSel');
  if(!sel) return;
  sel.innerHTML='<option value="">— Loading… —</option>';
  const fd=new FormData();
  fd.append('ajax_action','get_departments');
  fd.append('college_id',_csSelectedCollege.id);
  const data=await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
  sel.innerHTML='<option value="">— Select Your Department —</option>';
  if(data.ok&&data.departments){
    data.departments.forEach(d=>{
      sel.innerHTML+=`<option value="${d.id}">${d.name} (${d.code})</option>`;
    });
  }
}

// ── HOD: dept chosen → reveal code entry ─────────────────────────────────
function csHodDeptSelected() {
  const sel=document.getElementById('csHodDeptSel');
  _csSelectedDeptId=parseInt(sel.value)||0;
  const codeBox=document.getElementById('csHodStep_code');
  if(codeBox) codeBox.style.display=_csSelectedDeptId?'':'none';
  if(_csSelectedDeptId){
    const inp=document.getElementById('csHodCodeInput');
    if(inp){inp.value='';inp.style.borderColor='rgba(255,255,255,.12)';}
    const err=document.getElementById('csHodCodeErr');
    if(err) err.style.display='none';
    setTimeout(()=>{ if(inp) inp.focus(); },80);
  }
}

// ── HOD: verify the principal-generated dept code ─────────────────────────
async function csVerifyHodCode() {
  const inp=document.getElementById('csHodCodeInput');
  const err=document.getElementById('csHodCodeErr');
  const btn=document.getElementById('csHodCodeBtn');
  const code=inp?inp.value.trim():'';
  if(!_csSelectedDeptId){if(err){err.textContent='Please select your department first.';err.style.display='block';}return;}
  if(!code){if(err){err.textContent='Please enter the HOD access code.';err.style.display='block';}inp&&inp.focus();return;}
  btn&&(btn.disabled=true,btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> &nbsp;Verifying…');
  if(err) err.style.display='none';
  try {
    const fd=new FormData();
    fd.append('ajax_action','verify_access_code');
    fd.append('college_id',_csSelectedCollege.id);
    fd.append('step','role'); fd.append('role','hod');
    fd.append('dept_id',_csSelectedDeptId);
    fd.append('code',code);
    const data=await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
    if(data.ok) {
      await csConfirmSelection('hod',_csSelectedDeptId);
    } else {
      if(err){err.textContent=data.msg||'Incorrect HOD code.';err.style.display='block';}
      if(inp){inp.value='';inp.style.borderColor='#ef4444';inp.focus();}
    }
  } catch(e){if(err){err.textContent='Network error.';err.style.display='block';}}
  finally{btn&&(btn.disabled=false,btn.innerHTML='<i class="fas fa-unlock"></i> &nbsp;Verify &amp; Enter HOD Dashboard');}
}

// ── Final: save session & reload ──────────────────────────────────────────
async function csConfirmSelection(role, deptId) {
  const fd = new FormData();
  fd.append('ajax_action', 'set_college_role');
  fd.append('college_id', _csSelectedCollege.id);
  fd.append('role', role);
  fd.append('dept_id', deptId);

  // Show loading overlay
  const ov = document.getElementById('collegeSelectOverlay');
  if (ov) {
    ov.innerHTML = `<div style="display:flex;flex-direction:column;align-items:center;gap:16px;color:var(--text)">
      <i class="fas fa-spinner fa-spin" style="font-size:2.5rem;color:var(--teal,#00c6ae)"></i>
      <div style="font-size:1rem;font-weight:700;color:var(--teal,#00c6ae)">Loading ${role==='hod'?'HOD':'Principal'} Dashboard…</div>
      <div style="font-size:0.82rem;color:rgba(255,255,255,.5)">${_csSelectedCollege.name}</div>
    </div>`;
  }

  await fetch(location.href, { method: 'POST', body: fd });
  window.location.reload();
}

function csSuperAdminMode() {
  const fd = new FormData();
  fd.append('ajax_action', 'clear_college_role');
  fetch(location.href, { method: 'POST', body: fd }).then(() => window.location.reload());
}

function hideOverlay() {
  const ov = document.getElementById('collegeSelectOverlay');
  ov.style.opacity = '0';
  ov.style.transition = 'opacity .4s';
  setTimeout(() => { ov.style.display = 'none'; }, 400);
}

function csSwitchCollege() {
  const ov = document.getElementById('collegeSelectOverlay');
  ov.style.display = 'flex';
  ov.style.opacity = '0';
  ov.style.transition = 'opacity .3s';
  setTimeout(() => { ov.style.opacity = '1'; }, 10);
  // Reset to step 1
  _csShowOnly('csStep1');
}
</script>

<!-- ─── Accountant Credential Modal ────────────────────────────────────────── -->
<div class="form-modal-overlay" id="accCredModal" onclick="if(event.target===this)closeAccCredModal()">
  <div class="form-modal" style="max-width:500px">
    <div class="form-modal-header">
      <div class="form-modal-title"><i class="fas fa-key" style="color:var(--teal)"></i> Create Accountant Credential</div>
      <button class="form-modal-close" onclick="closeAccCredModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="form-modal-body" style="padding:20px">
      <div style="background:rgba(244,162,97,.07);border:1px solid rgba(244,162,97,.22);border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:.76rem;color:rgba(244,162,97,.9);display:flex;gap:8px;align-items:flex-start">
        <i class="fas fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i>
        <span>Creating a new credential will <strong>deactivate the previous one</strong> for the selected college.</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">College <span style="color:#ef4444">*</span></label>
          <?php if ($saCollegeId): ?>
          <!-- Principal: college is fixed, show as read-only -->
          <input type="text" class="form-input" value="<?= htmlspecialchars($saCollegeName ?? 'Your College') ?>" readonly style="opacity:.7;cursor:default">
          <input type="hidden" id="accModal_college" value="<?= $saCollegeId ?>">
          <?php else: ?>
          <select id="accModal_college" class="form-input" required>
            <option value="">— Select College —</option>
            <?php if (!empty($cRowsForFilter)): foreach ($cRowsForFilter as $cr): ?>
            <option value="<?= (int)$cr['id'] ?>"><?= htmlspecialchars($cr['name']) ?></option>
            <?php endforeach; endif; ?>
          </select>
          <?php endif; ?>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Full Name</label>
          <input type="text" id="accModal_fullname" class="form-input" placeholder="e.g. NIT Accounts Office" value="Accountant">
        </div>
        <div class="form-group">
          <label class="form-label">Username <span style="color:#ef4444">*</span></label>
          <input type="text" id="accModal_username" class="form-input" placeholder="e.g. accounts_nit">
        </div>
        <div class="form-group">
          <label class="form-label">Password <span style="color:#ef4444">*</span></label>
          <div style="position:relative">
            <input type="password" id="accModal_password" class="form-input" placeholder="Min 8, 1 uppercase, 1 number" style="padding-right:38px">
            <button type="button" onclick="document.getElementById('accModal_password').type==='password'?(document.getElementById('accModal_password').type='text',this.innerHTML='<i class=\'fas fa-eye-slash\'></i>'):(document.getElementById('accModal_password').type='password',this.innerHTML='<i class=\'fas fa-eye\'></i>')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted)"><i class="fas fa-eye"></i></button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" id="accModal_email" class="form-input" placeholder="accounts@college.edu">
        </div>
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" id="accModal_phone" class="form-input" placeholder="10-digit mobile">
        </div>
      </div>
      <div id="accCredError" style="display:none;margin-top:12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.28);border-radius:8px;padding:10px 14px;font-size:.79rem;color:#f87171;flex-direction:row;gap:8px;align-items:flex-start">
        <i class="fas fa-circle-exclamation" style="margin-top:1px;flex-shrink:0"></i>
        <span id="accCredErrorMsg"></span>
      </div>
    </div>
    <div class="form-modal-footer">
      <button type="button" onclick="closeAccCredModal()" class="btn-secondary">Cancel</button>
      <button type="button" onclick="submitAccCred()" class="btn-primary" id="accCredSaveBtn">
        <i class="fas fa-key"></i> Create Credential
      </button>
    </div>
  </div>
</div>

<!-- ─── Accountant Credential JS ─────────────────────────────────────────── -->
<script>
function openAccCredModal() {
  var errEl = document.getElementById('accCredError');
  if (errEl) errEl.style.display = 'none';
  document.getElementById('accCredModal').classList.add('open');
  var f = document.getElementById('accCredCollegeFilter');
  var col = document.getElementById('accModal_college');
  if (f && f.value && col) col.value = f.value;
}
function closeAccCredModal() {
  document.getElementById('accCredModal').classList.remove('open');
}
function showAccCredErr(msg) {
  var el = document.getElementById('accCredError');
  el.style.display = 'flex';
  document.getElementById('accCredErrorMsg').textContent = msg;
}
function submitAccCred() {
  var col  = document.getElementById('accModal_college').value;
  var user = document.getElementById('accModal_username').value.trim();
  var pwd  = document.getElementById('accModal_password').value;
  var nm   = document.getElementById('accModal_fullname').value.trim();
  var em   = document.getElementById('accModal_email').value.trim();
  var ph   = document.getElementById('accModal_phone').value.trim();
  if (!col)  { showAccCredErr('Please select a college.'); return; }
  if (!user) { showAccCredErr('Username is required.'); return; }
  if (!pwd)  { showAccCredErr('Password is required.'); return; }
  var btn = document.getElementById('accCredSaveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
  document.getElementById('accCredError').style.display = 'none';
  var fd = new FormData();
  fd.append('ajax_action', 'create_accountant_credential');
  fd.append('college_id',  col);
  fd.append('username',    user);
  fd.append('password',    pwd);
  fd.append('full_name',   nm || 'Accountant');
  fd.append('email',       em);
  fd.append('phone',       ph);
  fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        closeAccCredModal();
        showToast('Accountant credential created!', 'success');
        loadAccCredentials();
        document.getElementById('accModal_username').value = '';
        document.getElementById('accModal_password').value = '';
        document.getElementById('accModal_email').value    = '';
        document.getElementById('accModal_phone').value    = '';
        document.getElementById('accModal_fullname').value = 'Accountant';
        document.getElementById('accModal_college').value  = '';
      } else { showAccCredErr(d.msg || 'Failed to create credential.'); }
    })
    .catch(() => showAccCredErr('Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Create Credential'; });
}
function loadAccCredentials() {
  var col = document.getElementById('accCredCollegeFilter') ? document.getElementById('accCredCollegeFilter').value : '';
  var box = document.getElementById('accCredTable');
  if (!box) return;
  if (!col) { box.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:8px 0">Select a college above to view its accountant credentials.</p>'; return; }
  box.innerHTML = '<p style="color:var(--muted);font-size:.83rem"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
  var fd = new FormData();
  fd.append('ajax_action', 'get_accountant_credentials');
  fd.append('college_id',  col);
  fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) {
        box.innerHTML = '<div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:9px;padding:14px 18px;font-size:.82rem;color:#f87171">'
          + '<i class="fas fa-circle-exclamation" style="margin-right:7px"></i>'
          + (d.msg || 'Failed to load credentials.') + '</div>';
        return;
      }
      var creds = d.credentials || [];
      if (!creds.length) {
        var hint = d.setup_needed
          ? '<div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);border-radius:9px;padding:14px 18px;font-size:.82rem;color:rgba(245,158,11,.9)">'
            + '<i class="fas fa-triangle-exclamation" style="margin-right:7px"></i>'
            + '<strong>Table not set up yet.</strong> Click <strong>+ Create Credential</strong> above — the table will be created automatically on first save.'
            + '</div>'
          : '<div style="background:rgba(20,184,166,.05);border:1px solid rgba(20,184,166,.18);border-radius:9px;padding:14px 18px;font-size:.82rem;color:var(--muted)">'
            + '<i class="fas fa-info-circle" style="margin-right:7px;color:var(--teal)"></i>'
            + 'No credentials yet for this college. Click <strong style="color:var(--teal)">+ Create Credential</strong> to set one up.'
            + '</div>';
        box.innerHTML = hint;
        return;
      }
      var rows = creds.map(c => {
        var badge = c.is_active == 1
          ? '<span style="background:rgba(20,184,166,.12);color:#2dd4aa;border:1px solid rgba(20,184,166,.3);border-radius:20px;padding:2px 10px;font-size:.68rem;font-weight:600">ACTIVE</span>'
          : '<span style="background:rgba(15,118,110,.06);color:var(--muted);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:2px 10px;font-size:.68rem">INACTIVE</span>';
        var ll = c.last_login ? new Date(c.last_login).toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
        var cr = new Date(c.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
        var newSt = c.is_active == 1 ? 0 : 1;
        var tLbl  = c.is_active == 1 ? 'Deactivate' : 'Activate';
        var tCol  = c.is_active == 1 ? '#ef4444' : '#10b981';
        return `<tr>
          <td style="font-family:monospace;font-weight:600;color:var(--text)">${escH(c.username)}</td>
          <td>${escH(c.full_name)}</td>
          <td style="font-size:.76rem">${escH(c.email||'—')}</td>
          <td>${badge}</td>
          <td style="font-size:.76rem">${ll}</td>
          <td style="font-size:.74rem;color:var(--muted)">${cr}</td>
          <td><button onclick="toggleAccCred(${c.id},${newSt})" style="background:none;border:1px solid ${tCol}44;color:${tCol};padding:4px 10px;border-radius:7px;cursor:pointer;font-size:.72rem;font-family:inherit;transition:all .15s">${tLbl}</button></td>
        </tr>`;
      }).join('');
      box.innerHTML = `<div style="overflow-x:auto"><table class="data-table" style="width:100%;min-width:700px">
        <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Status</th><th>Last Login</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>${rows}</tbody></table></div>`;
    })
    .catch(() => { box.innerHTML = '<p style="color:#ef4444;font-size:.83rem">Network error.</p>'; });
}
function toggleAccCred(id, newStatus) {
  if (!confirm((newStatus ? 'Activate' : 'Deactivate') + ' this credential?')) return;
  var fd = new FormData();
  fd.append('ajax_action', 'toggle_accountant_status');
  fd.append('cred_id',   id);
  fd.append('is_active', newStatus);
  fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if(d.ok){ showToast(d.msg,'success'); loadAccCredentials(); } else showToast(d.msg||'Error','error'); });
}
function escH(s) { var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML; }
// fallback toast if not already defined
if (typeof showToast === 'undefined') {
  window.showToast = function(msg, type) { alert((type==='success'?'✓ ':'✗ ')+msg); };
}

/* ═══════════════════════════════════════════════════
   HOD MARKS VIEWER
   ═══════════════════════════════════════════════════ */
var _hmvData = {marks:[], examInfo:null};

/* ═══════════════════════════════════════════════════
   MARKS VIEWER — Semester-wise, per-subject columns
   ═══════════════════════════════════════════════════ */
var _hmvData = { rows: [], courses: [], dept: null, semester: null, acYear: '' };

async function hmvOnCollegeChange() {
  const cid = (document.getElementById('hmv-college')||{}).value || '';
  const deptSel = document.getElementById('hmv-dept');
  if (!deptSel || deptSel.tagName !== 'SELECT') return;
  deptSel.innerHTML = '<option value="">— Select Department —</option>';
  if (!cid) return;
  const fd = new FormData();
  fd.append('ajax_action','sa_hod_marks'); fd.append('college_id', cid);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) return;
  (d.depts||[]).forEach(dep => {
    deptSel.innerHTML += `<option value="${dep.id}">${escH(dep.name)} (${escH(dep.code)})</option>`;
  });
}

async function hmvLoad() {
  const cid = (document.getElementById('hmv-college')||{}).value || '';
  const did = (document.getElementById('hmv-dept')||{}).value || '';
  const sem = (document.getElementById('hmv-semester')||{}).value || '';
  const ay  = (document.getElementById('hmv-acyear')||{}).value  || '';

  if (!cid) { showToast('Please select a college.', 'error'); return; }
  if (!did) { showToast('Please select a department.', 'error'); return; }
  if (!sem) { showToast('Please select a semester.', 'error'); return; }

  // Show loading state
  const colspan = (_hmvData.courses.length * 3) + 5 || 8;
  document.getElementById('hmv-tbody').innerHTML =
    `<tr><td colspan="${colspan}" style="text-align:center;padding:40px;color:var(--muted)">
       <i class="fas fa-spinner fa-spin"></i> Loading marks…
     </td></tr>`;

  const fd = new FormData();
  fd.append('ajax_action', 'sa_hod_semester_marks');
  fd.append('college_id',  cid);
  fd.append('dept_id',     did);
  fd.append('semester',    sem);
  fd.append('academic_year', ay);

  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) { showToast(d.msg || 'Error loading marks', 'error'); return; }

  _hmvData.rows     = d.rows    || [];
  _hmvData.courses  = d.courses || [];
  _hmvData.dept     = d.dept    || null;
  _hmvData.semester = sem;
  _hmvData.acYear   = ay;

  hmvRenderContextBanner();
  hmvRenderSemesterStats();
  hmvBuildTableHead();
  hmvRenderSemesterTable(_hmvData.rows);
}

function hmvRenderContextBanner() {
  const el = document.getElementById('hmv-context-banner');
  const dept = _hmvData.dept;
  const sem  = _hmvData.semester;
  const ay   = _hmvData.acYear;
  if (!dept) { el.style.display='none'; return; }
  el.style.display = 'flex';
  el.innerHTML = `
    <span><i class="fas fa-building-columns" style="color:var(--teal);margin-right:5px"></i>
      <strong style="color:var(--teal)">${escH(dept.name)}</strong>
      <span style="color:var(--muted);font-size:.76rem;margin-left:4px">(${escH(dept.code)})</span>
    </span>
    <span style="color:var(--muted)">Semester: <strong style="color:var(--text)">${escH(sem)}</strong></span>
    ${ay ? `<span style="color:var(--muted)">A.Y.: <strong style="color:var(--text)">${escH(ay)}</strong></span>` : ''}
    <span style="color:var(--muted)">Subjects: <strong style="color:#a78bfa">${_hmvData.courses.length}</strong></span>
    <span style="color:var(--muted)">Students: <strong style="color:var(--teal)">${_hmvData.rows.length}</strong></span>`;
}

function hmvRenderSemesterStats() {
  const rows = _hmvData.rows;
  const statsEl = document.getElementById('hmv-stats-row');
  if (!rows.length) { statsEl.style.display='none'; return; }

  const total = rows.length;
  const allPass  = rows.filter(r => r.overall_result === 'PASS').length;
  const hasFail  = rows.filter(r => r.overall_result === 'FAIL').length;
  const subjects = _hmvData.courses.length;
  const avgPct   = rows.filter(r => r.total_pct !== null).length > 0
    ? (rows.reduce((s,r) => s + (parseFloat(r.total_pct)||0), 0) / rows.length).toFixed(1) + '%'
    : '—';

  document.getElementById('hmvs-students').textContent = total;
  document.getElementById('hmvs-subjects').textContent = subjects;
  document.getElementById('hmvs-avg').textContent      = avgPct;
  document.getElementById('hmvs-pass').textContent     = allPass;
  document.getElementById('hmvs-fail').textContent     = hasFail;
  statsEl.style.display = 'grid';
}

function hmvGradeColor(grade) {
  if (!grade) return 'var(--muted)';
  const g = grade.toUpperCase();
  if (g==='O'||g==='A+'||g==='A') return '#2dd4aa';
  if (g==='B+'||g==='B')          return '#00c6ae';
  if (g==='C+'||g==='C')          return '#f4a261';
  if (g==='D')                    return '#f59e0b';
  if (g==='F')                    return '#e76f51';
  return 'var(--text)';
}

function hmvBuildTableHead() {
  const courses = _hmvData.courses;
  const thead   = document.getElementById('hmv-thead');

  // Row 1: group headers
  let r1 = `<tr style="background:rgba(20,184,166,.06)">
    <th rowspan="2" style="text-align:center">#</th>
    <th rowspan="2">Student</th>
    <th rowspan="2">Roll No.</th>`;
  courses.forEach(c => {
    r1 += `<th colspan="3" style="text-align:center;border-left:1px solid var(--border2);font-size:.72rem;padding:6px 4px">
      <div style="font-weight:700;color:var(--text)">${escH(c.name)}</div>
      <div style="font-size:.65rem;color:var(--muted);font-family:monospace">${escH(c.code)}</div>
    </th>`;
  });
  r1 += `<th colspan="3" style="text-align:center;border-left:2px solid rgba(20,184,166,.4);background:rgba(20,184,166,.08);font-size:.72rem;font-weight:700;color:var(--teal)">TOTAL</th>
  </tr>`;

  // Row 2: sub-column headers
  let r2 = '<tr style="background:rgba(20,184,166,.03)">';
  courses.forEach(() => {
    r2 += `<th style="text-align:center;border-left:1px solid var(--border2);font-size:.68rem;color:var(--muted);padding:4px 6px">Marks</th>
            <th style="text-align:center;font-size:.68rem;color:var(--muted);padding:4px 6px">Max</th>
            <th style="text-align:center;font-size:.68rem;color:var(--muted);padding:4px 6px">Grade</th>`;
  });
  r2 += `<th style="text-align:center;border-left:2px solid rgba(20,184,166,.4);font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Obt</th>
          <th style="text-align:center;font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Avg %</th>
          <th style="text-align:center;font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Result</th>
         </tr>`;

  thead.innerHTML = r1 + r2;

  // Update panel title
  const sem = _hmvData.semester;
  const dept = _hmvData.dept;
  document.getElementById('hmv-panel-title').textContent =
    `${dept ? dept.name + ' — ' : ''}Semester ${sem} Marks`;
}

function hmvRenderSemesterTable(rows) {
  const tbody   = document.getElementById('hmv-tbody');
  const courses = _hmvData.courses;
  const colSpan = courses.length * 3 + 5;

  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center;color:var(--muted);padding:40px 20px">
      <i class="fas fa-inbox" style="font-size:2rem;opacity:.25;display:block;margin-bottom:10px"></i>
      No students or marks found for this semester.
    </td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map((row, idx) => {
    // Per-subject cells
    const subCells = courses.map(c => {
      const sub = (row.subjects || {})[c.id];
      if (!sub) {
        return `<td style="text-align:center;color:var(--muted);border-left:1px solid var(--border2)">—</td>
                <td style="text-align:center;color:var(--muted)">—</td>
                <td style="text-align:center;color:var(--muted)">—</td>`;
      }
      const absent = sub.is_absent;
      const obt    = absent ? '<span style="color:#a78bfa;font-size:.72rem">ABS</span>'
                            : (sub.obt !== null ? parseFloat(sub.obt).toFixed(1) : '—');
      const maxVal = sub.max !== null ? sub.max : '—';
      const grade  = sub.grade || '—';
      const gc     = hmvGradeColor(sub.grade);
      const bg     = absent ? 'rgba(167,139,250,.07)'
                   : (sub.is_pass == 0 && sub.is_pass !== null ? 'rgba(231,111,81,.06)' : '');
      return `<td style="text-align:center;font-weight:600;color:var(--text);border-left:1px solid var(--border2);${bg?'background:'+bg:''}">${obt}</td>
              <td style="text-align:center;color:var(--muted);font-size:.78rem;${bg?'background:'+bg:''}">${maxVal}</td>
              <td style="text-align:center;font-weight:700;color:${gc};${bg?'background:'+bg:''}">${escH(grade)}</td>`;
    }).join('');

    // Total columns
    const totalObt = row.total_obt !== null && row.total_obt !== undefined
      ? parseFloat(row.total_obt).toFixed(1) : '—';
    const totalPctRaw = row.total_pct !== null && row.total_pct !== undefined ? parseFloat(row.total_pct) : null;
    const totalPct = totalPctRaw !== null ? totalPctRaw.toFixed(1) + '%' : '—';
    const overallRes = row.overall_result || '—';
    const resBg  = overallRes==='PASS' ? 'rgba(45,212,170,.13)' : overallRes==='FAIL' ? 'rgba(231,111,81,.13)' : 'rgba(167,139,250,.1)';
    const resCol = overallRes==='PASS' ? '#2dd4aa' : overallRes==='FAIL' ? '#e76f51' : '#a78bfa';

    return `<tr class="hmv-student-row" data-name="${escH((row.full_name||'').toLowerCase())}" data-roll="${escH((row.roll_number||'').toLowerCase())}" data-result="${escH(overallRes.toLowerCase())}">
      <td style="color:var(--muted);font-size:.78rem;text-align:center">${idx+1}</td>
      <td>
        <div style="font-weight:600;color:var(--text);font-size:.86rem">${escH(row.full_name||'')}</div>
        <div style="font-size:.7rem;color:var(--muted)">${escH(row.email||'')}</div>
      </td>
      <td style="font-family:monospace;font-size:.79rem;color:var(--teal)">${escH(row.roll_number||'—')}</td>
      ${subCells}
      <td style="text-align:center;font-weight:700;color:var(--text);border-left:2px solid rgba(20,184,166,.3);background:rgba(20,184,166,.04)">${totalObt}</td>
      <td style="text-align:center;font-weight:700;color:${totalPctRaw===null?'var(--muted)':totalPctRaw>=75?'#2dd4aa':totalPctRaw>=50?'#f4a261':'#e76f51'};background:rgba(20,184,166,.04)" title="Average % across courses">${totalPct}</td>
      <td style="text-align:center;background:rgba(20,184,166,.04)">
        <span style="font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:5px;background:${resBg};color:${resCol}">${escH(overallRes)}</span>
      </td>
    </tr>`;
  }).join('');
}

function hmvFilterRows() {
  const q   = (document.getElementById('hmv-search').value || '').toLowerCase().trim();
  const res = (document.getElementById('hmv-result-filter').value || '').toLowerCase();
  document.querySelectorAll('.hmv-student-row').forEach(tr => {
    const nameOk   = !q   || tr.dataset.name.includes(q) || tr.dataset.roll.includes(q);
    const resultOk = !res || (res==='pass' ? tr.dataset.result==='pass' : tr.dataset.result==='fail');
    tr.style.display = (nameOk && resultOk) ? '' : 'none';
  });
}

function hmvExportCSV() {
  const rows    = _hmvData.rows;
  const courses = _hmvData.courses;
  if (!rows.length) { showToast('Load marks first.', 'error'); return; }

  const subHeaders = courses.flatMap(c => [`${c.name} (${c.code}) - Marks`, `${c.name} - Max`, `${c.name} - Grade`]);
  const header = ['#', 'Student', 'Roll No.', 'Email', ...subHeaders, 'Total Obtained', 'Total %', 'Result'];

  const dataRows = rows.map((row, idx) => {
    const subCols = courses.flatMap(c => {
      const sub = (row.subjects||{})[c.id];
      if (!sub) return ['—', '—', '—'];
      return [
        sub.is_absent ? 'ABSENT' : (sub.obt !== null ? parseFloat(sub.obt).toFixed(1) : '—'),
        sub.max !== null ? sub.max : '—',
        sub.grade || '—'
      ];
    });
    return [
      idx+1, row.full_name||'', row.roll_number||'', row.email||'',
      ...subCols,
      row.total_obt !== null ? parseFloat(row.total_obt).toFixed(1) : '—',
      row.total_pct !== null ? parseFloat(row.total_pct).toFixed(1)+'%' : '—',
      row.overall_result || '—'
    ];
  });

  const csv = [header, ...dataRows]
    .map(r => r.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(','))
    .join('\n');
  const a = document.createElement('a');
  const dept = _hmvData.dept;
  const fname = `marks_${dept?dept.code+'_':''}sem${_hmvData.semester}_${Date.now()}.csv`;
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = fname;
  a.click();
}

/* ═══════════════════════════════════════════════════
   COURSE-WISE MARKS VIEWER (hcm)
   ═══════════════════════════════════════════════════ */
var _hcmData = { rows: [], exams: [], courseInfo: null };

// Colour helper for exam type badge
function hcmTypeColor(type) {
  const map = {
    unit_test:  { bg:'rgba(20,184,166,.13)',  col:'#2dd4aa' },
    mid_term:   { bg:'rgba(167,139,250,.15)', col:'#a78bfa' },
    final:      { bg:'rgba(231,111,81,.13)',  col:'#e76f51' },
    practical:  { bg:'rgba(52,211,153,.13)',  col:'#10b981' },
    assignment: { bg:'rgba(251,191,36,.13)',  col:'#f59e0b' },
    project:    { bg:'rgba(96,165,250,.13)',  col:'#60a5fa' },
    viva:       { bg:'rgba(244,114,182,.13)', col:'#f472b6' },
  };
  return map[type] || { bg:'rgba(100,116,139,.13)', col:'#94a3b8' };
}

function hcmTypeLabel(type) {
  const map = { unit_test:'Unit Test', mid_term:'Mid Term', final:'Final', practical:'Practical', assignment:'Assignment', project:'Project', viva:'Viva' };
  return map[type] || type;
}

async function hcmOnCollegeChange() {
  const cid = (document.getElementById('hcm-college')||{}).value || '';
  const deptSel = document.getElementById('hcm-dept');
  if (!deptSel || deptSel.tagName !== 'SELECT') return;
  deptSel.innerHTML = '<option value="">— Select Department —</option>';
  document.getElementById('hcm-course').innerHTML = '<option value="">— Select Course —</option>';
  if (!cid) return;
  const fd = new FormData();
  fd.append('ajax_action', 'sa_hod_course_marks');
  fd.append('college_id', cid);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) return;
  (d.depts||[]).forEach(dep => {
    deptSel.innerHTML += `<option value="${dep.id}">${escH(dep.name)} (${escH(dep.code)})</option>`;
  });
  // Also load all courses for this college
  hcmPopulateCourses(d.courses || []);
}

async function hcmOnDeptChange() {
  const cid = (document.getElementById('hcm-college')||{}).value || '';
  const did = (document.getElementById('hcm-dept')||{}).value  || '';
  if (!cid) return;
  const fd = new FormData();
  fd.append('ajax_action', 'sa_hod_course_marks');
  fd.append('college_id', cid);
  if (did) fd.append('dept_id', did);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) return;
  hcmPopulateCourses(d.courses || []);
}

function hcmPopulateCourses(courses) {
  const sel = document.getElementById('hcm-course');
  sel.innerHTML = '<option value="">— Select Course —</option>';
  courses.forEach(c => {
    sel.innerHTML += `<option value="${c.id}">[Sem ${c.semester||'?'}] ${escH(c.name)} (${escH(c.code)})</option>`;
  });
}

async function hcmLoad() {
  const cid = (document.getElementById('hcm-college')||{}).value || '';
  const did = (document.getElementById('hcm-dept')||{}).value   || '';
  const crs = (document.getElementById('hcm-course')||{}).value || '';

  if (!cid) { showToast('Please select a college.', 'error'); return; }
  if (!crs) { showToast('Please select a course.', 'error'); return; }

  document.getElementById('hcm-tbody').innerHTML =
    `<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">
       <i class="fas fa-spinner fa-spin"></i> Loading marks…
     </td></tr>`;

  const fd = new FormData();
  fd.append('ajax_action', 'sa_hod_course_marks');
  fd.append('college_id', cid);
  if (did) fd.append('dept_id', did);
  fd.append('course_id', crs);

  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  if (!d.ok) { showToast(d.msg || 'Error loading marks', 'error'); return; }

  _hcmData.rows       = d.rows       || [];
  _hcmData.exams      = d.exams      || [];
  _hcmData.courseInfo = d.course_info || null;

  hcmRenderContextBanner();
  hcmRenderExamBadges();
  hcmRenderStats();
  hcmBuildTableHead();
  hcmRenderTable(_hcmData.rows);
}

function hcmRenderContextBanner() {
  const el = document.getElementById('hcm-context-banner');
  const ci = _hcmData.courseInfo;
  if (!ci) { el.style.display='none'; return; }
  el.style.display = 'flex';
  el.innerHTML = `
    <span><i class="fas fa-book" style="color:var(--teal);margin-right:5px"></i>
      <strong style="color:var(--teal)">${escH(ci.name)}</strong>
      <span style="color:var(--muted);font-size:.76rem;margin-left:4px">(${escH(ci.code)})</span>
    </span>
    <span style="color:var(--muted)">Dept: <strong style="color:var(--text)">${escH(ci.dept_name||'—')}</strong></span>
    <span style="color:var(--muted)">Semester: <strong style="color:var(--text)">${ci.semester||'—'}</strong></span>
    ${ci.academic_year ? `<span style="color:var(--muted)">A.Y.: <strong style="color:var(--text)">${escH(ci.academic_year)}</strong></span>` : ''}
    <span style="color:var(--muted)">Exams: <strong style="color:#a78bfa">${_hcmData.exams.length}</strong></span>
    <span style="color:var(--muted)">Students: <strong style="color:var(--teal)">${_hcmData.rows.length}</strong></span>`;
}

function hcmRenderExamBadges() {
  const el = document.getElementById('hcm-exam-badges');
  if (!_hcmData.exams.length) { el.style.display='none'; return; }
  el.style.display = 'flex';
  el.innerHTML = '<span style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-right:4px">Exams:</span>';
  _hcmData.exams.forEach(ex => {
    const tc = hcmTypeColor(ex.type);
    const dt = ex.exam_date ? ` · ${ex.exam_date}` : '';
    el.innerHTML += `<span style="font-size:.74rem;padding:4px 10px;border-radius:20px;background:${tc.bg};color:${tc.col};font-weight:600;border:1px solid ${tc.col}40" title="${escH(ex.type)} · Max: ${ex.max_marks}">${escH(ex.title)}${dt}</span>`;
  });
}

function hcmRenderStats() {
  const rows  = _hcmData.rows;
  const statsEl = document.getElementById('hcm-stats-row');
  if (!rows.length) { statsEl.style.display='none'; return; }

  const allPass = rows.filter(r => !r.any_fail).length;
  const hasFail = rows.filter(r =>  r.any_fail).length;
  const withPct = rows.filter(r => r.total_pct !== null);
  const avgPct  = withPct.length
    ? (withPct.reduce((s,r) => s + parseFloat(r.total_pct), 0) / withPct.length).toFixed(1) + '%'
    : '—';

  document.getElementById('hcms-students').textContent = rows.length;
  document.getElementById('hcms-exams').textContent    = _hcmData.exams.length;
  document.getElementById('hcms-avg').textContent      = avgPct;
  document.getElementById('hcms-pass').textContent     = allPass;
  document.getElementById('hcms-fail').textContent     = hasFail;
  statsEl.style.display = 'grid';
}

function hcmBuildTableHead() {
  const exams  = _hcmData.exams;
  const ci     = _hcmData.courseInfo;
  const thead  = document.getElementById('hcm-thead');

  // Row 1: group headers — each exam spans 3 cols (Marks / Max / Grade), plus fixed cols + TOTAL
  let r1 = `<tr style="background:rgba(20,184,166,.06)">
    <th rowspan="2" style="text-align:center">#</th>
    <th rowspan="2">Student</th>
    <th rowspan="2">Roll No.</th>`;
  exams.forEach(ex => {
    const tc  = hcmTypeColor(ex.type);
    const lbl = hcmTypeLabel(ex.type);
    r1 += `<th colspan="3" style="text-align:center;border-left:1px solid var(--border2);font-size:.71rem;padding:6px 4px">
      <div style="font-weight:700;color:var(--text);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escH(ex.title)}">${escH(ex.title)}</div>
      <div style="display:flex;justify-content:center;gap:4px;margin-top:2px">
        <span style="font-size:.62rem;padding:1px 6px;border-radius:10px;background:${tc.bg};color:${tc.col};font-weight:600">${escH(lbl)}</span>
        <span style="font-size:.62rem;color:var(--muted);font-family:monospace">${ex.max_marks}</span>
      </div>
    </th>`;
  });
  r1 += `<th colspan="3" style="text-align:center;border-left:2px solid rgba(20,184,166,.4);background:rgba(20,184,166,.08);font-size:.72rem;font-weight:700;color:var(--teal)">TOTAL</th>
  </tr>`;

  // Row 2: sub-column headers per exam
  let r2 = '<tr style="background:rgba(20,184,166,.03)">';
  exams.forEach(() => {
    r2 += `<th style="text-align:center;border-left:1px solid var(--border2);font-size:.68rem;color:var(--muted);padding:4px 6px">Marks</th>
            <th style="text-align:center;font-size:.68rem;color:var(--muted);padding:4px 6px">Max</th>
            <th style="text-align:center;font-size:.68rem;color:var(--muted);padding:4px 6px">Grade</th>`;
  });
  r2 += `<th style="text-align:center;border-left:2px solid rgba(20,184,166,.4);font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Obt</th>
          <th style="text-align:center;font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Avg %</th>
          <th style="text-align:center;font-size:.68rem;font-weight:700;color:var(--teal);padding:4px 6px">Result</th>
         </tr>`;

  thead.innerHTML = r1 + r2;

  document.getElementById('hcm-panel-title').textContent =
    ci ? `${ci.name} (${ci.code}) — Exam-wise Marks` : 'Course Exam Marks';
}

function hcmRenderTable(rows) {
  const tbody  = document.getElementById('hcm-tbody');
  const exams  = _hcmData.exams;
  const colSpan = exams.length * 3 + 5;

  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center;color:var(--muted);padding:40px 20px">
      <i class="fas fa-inbox" style="font-size:2rem;opacity:.25;display:block;margin-bottom:10px"></i>
      No students found for this course.
    </td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map((row, idx) => {
    const examCells = exams.map(ex => {
      const e = (row.exams || {})[ex.id];
      if (!e) {
        return `<td style="text-align:center;color:var(--muted);border-left:1px solid var(--border2)">—</td>
                <td style="text-align:center;color:var(--muted)">—</td>
                <td style="text-align:center;color:var(--muted)">—</td>`;
      }
      const absent = e.is_absent;
      const obt    = absent ? '<span style="color:#a78bfa;font-size:.72rem">ABS</span>'
                            : (e.obt !== null ? parseFloat(e.obt).toFixed(1) : '—');
      const maxVal = e.max !== null ? e.max : '—';
      const grade  = e.grade || '—';
      const gc     = hmvGradeColor(e.grade);
      const bg     = absent ? 'rgba(167,139,250,.07)'
                   : (e.is_pass === 0 || e.is_pass === false ? 'rgba(231,111,81,.06)' : '');
      return `<td style="text-align:center;font-weight:600;color:var(--text);border-left:1px solid var(--border2);${bg?'background:'+bg:''}">${obt}</td>
              <td style="text-align:center;color:var(--muted);font-size:.78rem;${bg?'background:'+bg:''}">${maxVal}</td>
              <td style="text-align:center;font-weight:700;color:${gc};${bg?'background:'+bg:''}">${escH(grade)}</td>`;
    }).join('');

    const totalObt = row.total_obt !== null && row.total_obt !== undefined
      ? parseFloat(row.total_obt).toFixed(1) : '—';
    const totalPctRaw = row.total_pct !== null && row.total_pct !== undefined
      ? parseFloat(row.total_pct) : null;
    const totalPct = totalPctRaw !== null ? totalPctRaw.toFixed(1) + '%' : '—';
    // Colour based on performance band
    const pctCol = totalPctRaw === null ? 'var(--muted)'
      : totalPctRaw >= 75 ? '#2dd4aa'
      : totalPctRaw >= 50 ? '#f4a261'
      : '#e76f51';
    const hasFail  = row.any_fail;
    const resLabel = row.any_fail ? 'HAS FAIL' : (row.total_obt !== null ? 'PASS' : '—');
    const resBg    = row.any_fail ? 'rgba(231,111,81,.13)' : (row.total_obt !== null ? 'rgba(45,212,170,.13)' : 'rgba(167,139,250,.1)');
    const resCol   = row.any_fail ? '#e76f51' : (row.total_obt !== null ? '#2dd4aa' : '#a78bfa');

    return `<tr class="hcm-student-row" data-name="${escH((row.full_name||'').toLowerCase())}" data-roll="${escH((row.roll_number||'').toLowerCase())}" data-fail="${row.any_fail ? 'fail' : 'pass'}">
      <td style="color:var(--muted);font-size:.78rem;text-align:center">${idx+1}</td>
      <td>
        <div style="font-weight:600;color:var(--text);font-size:.86rem">${escH(row.full_name||'')}</div>
        <div style="font-size:.7rem;color:var(--muted)">${escH(row.email||'')}</div>
      </td>
      <td style="font-family:monospace;font-size:.79rem;color:var(--teal)">${escH(row.roll_number||'—')}</td>
      ${examCells}
      <td style="text-align:center;font-weight:700;color:var(--text);border-left:2px solid rgba(20,184,166,.3);background:rgba(20,184,166,.04)" title="Total marks obtained across graded exams">${totalObt}</td>
      <td style="text-align:center;font-weight:700;color:${pctCol};background:rgba(20,184,166,.04);font-size:.88rem" title="Average % across exams with marks entered">${totalPct}</td>
      <td style="text-align:center;background:rgba(20,184,166,.04)">
        <span style="font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:5px;background:${resBg};color:${resCol}">${resLabel}</span>
      </td>
    </tr>`;
  }).join('');
}

function hcmFilterRows() {
  const q   = (document.getElementById('hcm-search').value || '').toLowerCase().trim();
  const res = (document.getElementById('hcm-result-filter').value || '').toLowerCase();
  document.querySelectorAll('.hcm-student-row').forEach(tr => {
    const nameOk   = !q   || tr.dataset.name.includes(q) || tr.dataset.roll.includes(q);
    const resultOk = !res || tr.dataset.fail === res;
    tr.style.display = (nameOk && resultOk) ? '' : 'none';
  });
}

function hcmExportCSV() {
  const rows  = _hcmData.rows;
  const exams = _hcmData.exams;
  const ci    = _hcmData.courseInfo;
  if (!rows.length) { showToast('Load marks first.', 'error'); return; }

  const examHeaders = exams.flatMap(ex => [
    `${ex.title} - Marks`, `${ex.title} - Max`, `${ex.title} - Grade`
  ]);
  const header = ['#', 'Student', 'Roll No.', 'Email', ...examHeaders, 'Total Obt', 'Total %', 'Result'];

  const dataRows = rows.map((row, idx) => {
    const examCols = exams.flatMap(ex => {
      const e = (row.exams||{})[ex.id];
      if (!e) return ['—', '—', '—'];
      return [
        e.is_absent ? 'ABSENT' : (e.obt !== null ? parseFloat(e.obt).toFixed(1) : '—'),
        e.max !== null ? e.max : '—',
        e.grade || '—'
      ];
    });
    return [
      idx+1, row.full_name||'', row.roll_number||'', row.email||'',
      ...examCols,
      row.total_obt !== null ? parseFloat(row.total_obt).toFixed(1) : '—',
      row.total_pct !== null ? parseFloat(row.total_pct).toFixed(1)+'%' : '—',
      row.any_fail ? 'HAS FAIL' : (row.total_obt !== null ? 'PASS' : '—')
    ];
  });

  const csv = [header, ...dataRows]
    .map(r => r.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(','))
    .join('\n');
  const a = document.createElement('a');
  const fname = `course_marks_${ci?ci.code+'_':''}${Date.now()}.csv`;
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = fname;
  a.click();
}

/* ═══════════════════════════════════════════════════
   QUESTION PAPERS VIEWER
   ═══════════════════════════════════════════════════ */
var _qpvData = [];

async function qpvCollegeChange() {
  const cid = (document.getElementById('qpv-college')||{}).value||'';
  const deptSel = document.getElementById('qpv-dept');
  deptSel.innerHTML = '<option value="">All Departments</option>';
  if (!cid) return;
  const fd = new FormData();
  fd.append('ajax_action','sa_all_qpapers'); fd.append('college_id',cid);
  const r = await fetch('',{method:'POST',body:fd});
  const d = await r.json();
  if (!d.ok) return;
  (d.depts||[]).forEach(dep => {
    deptSel.innerHTML += `<option value="${dep.id}">${escH(dep.name)} (${escH(dep.code)})</option>`;
  });
}

async function qpvLoad() {
  const cid = (document.getElementById('qpv-college')||{}).value||'';
  const deptEl = document.getElementById('qpv-dept');
  const did = deptEl ? deptEl.value||'' : '';
  const status = document.getElementById('qpv-status').value||'';
  document.getElementById('qpv-tbody').innerHTML = '<tr><td colspan="12" style="text-align:center;padding:40px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
  const fd = new FormData();
  fd.append('ajax_action','sa_all_qpapers');
  fd.append('college_id',cid); fd.append('dept_id',did); fd.append('status',status);
  const r = await fetch('',{method:'POST',body:fd});
  const d = await r.json();
  if (!d.ok) { showToast(d.msg||'Error','error'); return; }
  _qpvData = d.papers||[];
  // Update dept dropdown if college selected and dept is a <select>
  const deptSel = document.getElementById('qpv-dept');
  if (cid && d.depts && deptSel && deptSel.tagName === 'SELECT' && deptSel.options.length <= 1) {
    d.depts.forEach(dep => {
      deptSel.innerHTML += `<option value="${dep.id}">${escH(dep.name)}</option>`;
    });
  }
  qpvRender(_qpvData);
}

function qpvStatusStyle(st) {
  const map = {
    draft:            ['rgba(122,147,172,.15)','#7a93ac'],
    pending_approval: ['rgba(244,162,97,.15)','#f4a261'],
    approved:         ['rgba(45,212,170,.15)','#2dd4aa'],
    released:         ['rgba(20,184,166,.15)','#00c6ae'],
  };
  return map[st] || ['rgba(122,147,172,.12)','#7a93ac'];
}

function qpvRender(papers) {
  const tbody = document.getElementById('qpv-tbody');
  document.getElementById('qpv-count-badge').textContent = papers.length+' papers';
  // Status pills
  const statusCounts = {};
  papers.forEach(p => statusCounts[p.status] = (statusCounts[p.status]||0)+1);
  const pillsEl = document.getElementById('qpv-status-pills');
  pillsEl.innerHTML = Object.entries(statusCounts).map(([st,cnt]) => {
    const [bg,col] = qpvStatusStyle(st);
    return `<span style="background:${bg};color:${col};border:1px solid ${col}44;padding:4px 12px;border-radius:50px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em">${escH(st.replace(/_/g,' '))} <strong>${cnt}</strong></span>`;
  }).join('');

  if (!papers.length) {
    tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;color:var(--muted);padding:30px">No question papers found.</td></tr>';
    return;
  }
  tbody.innerHTML = papers.map((p,i) => {
    const [bg,col] = qpvStatusStyle(p.status);
    const dateStr = p.created_at ? p.created_at.substring(0,10) : '—';
    const approveBtn = (p.status==='pending_approval')
      ? `<button onclick="qpvApprove(${p.id},'approve')" style="background:rgba(45,212,170,.15);color:#2dd4aa;border:1px solid #2dd4aa44;padding:4px 9px;border-radius:6px;cursor:pointer;font-size:.7rem;font-family:inherit" title="Approve"><i class="fas fa-check"></i></button>
         <button onclick="qpvApprove(${p.id},'reject')" style="background:rgba(231,111,81,.12);color:#e76f51;border:1px solid #e76f5144;padding:4px 9px;border-radius:6px;cursor:pointer;font-size:.7rem;font-family:inherit;margin-left:4px" title="Send back"><i class="fas fa-rotate-left"></i></button>` : '';
    return `<tr>
      <td style="color:var(--muted);font-size:.78rem">${i+1}</td>
      <td>
        <div style="font-weight:600;color:var(--text);font-size:.87rem">${escH(p.title)}</div>
        <div style="font-size:.7rem;color:var(--muted)">v${p.version||1}</div>
      </td>
      <td style="font-size:.8rem">${escH(p.exam_title||'—')}<div style="font-size:.7rem;color:var(--muted)">${p.exam_date||''}</div></td>
      <td><span style="font-size:.73rem;color:var(--teal);font-family:monospace">${escH(p.course_code)}</span><div style="font-size:.7rem;color:var(--muted)">${escH(p.course_name)}</div></td>
      <td style="font-size:.8rem">${escH(p.dept_name||'—')}</td>
      <td style="font-size:.78rem;color:var(--muted)">${escH(p.college_name||'—')}</td>
      <td style="text-align:center;font-weight:700;color:var(--amber)">${p.total_marks||'—'}</td>
      <td style="text-align:center">${p.total_questions||'—'}</td>
      <td><span style="font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:5px;background:${bg};color:${col};text-transform:uppercase;letter-spacing:.05em">${escH(p.status.replace(/_/g,' '))}</span></td>
      <td style="font-size:.78rem;color:var(--muted)">${escH(p.created_by_name||'—')}</td>
      <td style="font-size:.75rem;color:var(--muted)">${dateStr}</td>
      <td style="white-space:nowrap">
        <button onclick="qpvShowDetail(${i})" style="background:rgba(20,184,166,.12);color:var(--teal);border:1px solid rgba(20,184,166,.3);padding:4px 9px;border-radius:6px;cursor:pointer;font-size:.72rem;font-family:inherit" title="View Details"><i class="fas fa-eye"></i></button>
        ${approveBtn}
      </td>
    </tr>`;
  }).join('');
}

function qpvFilterLocal() {
  const q = (document.getElementById('qpv-search').value||'').toLowerCase();
  const filtered = _qpvData.filter(p =>
    !q || (p.title||'').toLowerCase().includes(q) ||
    (p.exam_title||'').toLowerCase().includes(q) ||
    (p.course_name||'').toLowerCase().includes(q) ||
    (p.dept_name||'').toLowerCase().includes(q)
  );
  qpvRender(filtered);
}

function qpvReset() {
  const cs = document.getElementById('qpv-college');
  if (cs && cs.tagName === 'SELECT') cs.value='';
  const ds = document.getElementById('qpv-dept');
  if (ds && ds.tagName === 'SELECT') { ds.innerHTML='<option value="">All Departments</option>'; }
  document.getElementById('qpv-status').value='';
  document.getElementById('qpv-search').value='';
  document.getElementById('qpv-tbody').innerHTML='<tr><td colspan="12" style="text-align:center;color:var(--muted);padding:30px">Click <strong style="color:var(--teal)">Load</strong> to view question papers.</td></tr>';
  document.getElementById('qpv-status-pills').innerHTML='';
  document.getElementById('qpv-count-badge').textContent='';
  _qpvData=[];
}

async function qpvShowDetail(idx) {
  const p = _qpvData[idx];
  if (!p) return;
  const [bg,col] = qpvStatusStyle(p.status);
  const body = document.getElementById('qpv-detail-body');

  // Show meta immediately, questions loading spinner below
  body.innerHTML = `
    <div style="padding:18px 22px;border-bottom:1px solid var(--border2)">
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:6px">${escH(p.title)}</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:5px;background:${bg};color:${col};text-transform:uppercase;letter-spacing:.05em">${p.status.replace(/_/g,' ')}</span>
        <span style="font-size:.72rem;color:var(--muted);padding:3px 8px;border-radius:5px;background:rgba(100,116,139,.08)">Version ${escH(p.version||'1')}</span>
      </div>
      <!-- 2-column info grid -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:.82rem">
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Exam</span>
          <strong style="color:var(--text);word-break:break-word">${escH(p.exam_title||'—')}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Date</span>
          <strong style="color:var(--text)">${p.exam_date||'—'}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Course</span>
          <strong style="color:var(--teal);word-break:break-word">${escH(p.course_code)} — ${escH(p.course_name)}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Department</span>
          <strong style="color:var(--text);word-break:break-word">${escH(p.dept_code)} — ${escH(p.dept_name)}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">College</span>
          <strong style="color:var(--text);word-break:break-word">${escH(p.college_name)}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Total Marks</span>
          <strong style="color:var(--amber);font-size:.95rem">${p.total_marks||'—'}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Questions</span>
          <strong style="color:var(--text)">${p.total_questions||'—'}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Created By</span>
          <strong style="color:var(--text)">${escH(p.created_by_name||'—')}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Approved By</span>
          <strong style="color:var(--text)">${escH(p.approved_by_name||'Not yet')}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Created</span>
          <strong style="color:var(--text)">${(p.created_at||'').substring(0,16)}</strong>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Updated</span>
          <strong style="color:var(--text)">${(p.updated_at||'').substring(0,16)}</strong>
        </div>
      </div>
    </div>
    <div id="qpv-questions-section" style="padding:16px 22px">
      <div style="text-align:center;color:var(--muted);padding:20px 0"><i class="fas fa-spinner fa-spin"></i> Loading questions…</div>
    </div>`;

  // Show approve buttons if pending
  const btnEl = document.getElementById('qpv-approve-btns');
  if (p.status==='pending_approval') {
    btnEl.innerHTML = `
      <button onclick="qpvApprove(${p.id},'approve')" class="btn-primary" style="background:linear-gradient(135deg,#2dd4aa,#10b981)">
        <i class="fas fa-check"></i> Approve Paper
      </button>
      <button onclick="qpvApprove(${p.id},'reject')" class="btn-secondary" style="color:#e76f51;border-color:rgba(231,111,81,.3)">
        <i class="fas fa-rotate-left"></i> Send Back to Draft
      </button>`;
  } else {
    btnEl.innerHTML = '';
  }
  document.getElementById('qpv-detail-modal').classList.add('open');

  // Fetch full questions from server
  try {
    const fd = new FormData();
    fd.append('ajax_action','sa_get_qpaper_questions');
    fd.append('paper_id', p.id);
    const r = await fetch('', {method:'POST', body:fd});
    const d = await r.json();
    const sec = document.getElementById('qpv-questions-section');
    if (!sec) return; // Modal was closed
    if (!d.ok) { sec.innerHTML = `<div style="color:#e76f51;font-size:.82rem"><i class="fas fa-triangle-exclamation"></i> ${escH(d.msg||'Could not load questions')}</div>`; return; }

    const qs = d.questions||[];
    const paper = d.paper||{};

    // Instructions banner
    let instrHtml = '';
    if (paper.instructions && paper.instructions.trim()) {
      instrHtml = `<div style="background:rgba(45,212,170,.08);border:1px solid rgba(45,212,170,.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.8rem;color:var(--text)">
        <span style="color:var(--teal);font-weight:700;margin-right:6px"><i class="fas fa-circle-info"></i> Instructions:</span>${escH(paper.instructions)}
      </div>`;
    }

    if (!qs.length) {
      sec.innerHTML = instrHtml + `<div style="text-align:center;color:var(--muted);padding:20px 0;font-size:.85rem"><i class="fas fa-circle-question" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>No questions linked to this paper yet.</div>`;
      return;
    }

    // Difficulty badge colors
    const diffColor = { easy:'#2dd4aa', medium:'#f4a261', hard:'#e76f51' };
    const typeLabel  = { mcq:'MCQ', short:'Short', long:'Long', true_false:'T/F', fill_blank:'Fill', match:'Match' };

    const qHtml = qs.map((q, i) => {
      const marks = q.marks_override != null ? parseFloat(q.marks_override) : parseFloat(q.marks||0);
      const dc = diffColor[q.difficulty] || '#aaa';
      const tl = typeLabel[q.question_type] || q.question_type;
      let optionsHtml = '';
      if (q.question_type === 'mcq' && q.options && q.options.length) {
        const letters = ['A','B','C','D','E'];
        optionsHtml = `<div style="margin-top:10px;display:flex;flex-direction:column;gap:5px">` +
          q.options.map((o, oi) => `
            <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem;padding:6px 10px;border-radius:6px;background:${o.is_correct==1?'rgba(45,212,170,.1)':'rgba(15,118,110,.03)'};border:1px solid ${o.is_correct==1?'rgba(45,212,170,.3)':'var(--border2)'}">
              <span style="font-weight:700;color:${o.is_correct==1?'#2dd4aa':'var(--muted)'};min-width:18px;flex-shrink:0">${letters[oi]||oi+1}.</span>
              <span style="color:${o.is_correct==1?'#2dd4aa':'var(--text)'};word-break:break-word;flex:1">${escH(o.option_text)}</span>
              ${o.is_correct==1?'<span style="flex-shrink:0;color:#2dd4aa;font-size:.68rem;font-weight:700">✓</span>':''}
            </div>`).join('') + `</div>`;
      }
      const tags = [
        q.unit_no   ? `Unit ${q.unit_no}` : null,
        q.topic     ? escH(q.topic)        : null,
        q.bloom_level ? escH(q.bloom_level.charAt(0).toUpperCase()+q.bloom_level.slice(1)) : null,
      ].filter(Boolean);
      return `
        <div style="border:1px solid var(--border2);border-radius:10px;padding:14px 16px;background:rgba(15,118,110,.02);margin-bottom:10px;overflow:hidden">
          <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px">
            <span style="font-size:.75rem;font-weight:800;color:var(--teal);flex-shrink:0;padding-top:2px;min-width:28px">Q${i+1}.</span>
            <div style="flex:1;min-width:0">
              <div style="font-size:.88rem;color:var(--text);line-height:1.6;font-weight:500;word-break:break-word;white-space:pre-wrap">${escH(q.question_text)}</div>
              ${optionsHtml}
            </div>
            <div style="flex-shrink:0;text-align:right;padding-left:8px">
              <div style="font-size:.88rem;font-weight:700;color:var(--amber);white-space:nowrap">${marks} <span style="font-size:.68rem;font-weight:400;color:var(--muted)">mk</span></div>
            </div>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:5px;padding-top:6px;border-top:1px solid var(--border2)">
            <span style="font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:4px;background:rgba(100,116,139,.1);color:var(--muted);text-transform:uppercase">${tl}</span>
            <span style="font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:4px;background:${dc}22;color:${dc};text-transform:capitalize">${q.difficulty||'—'}</span>
            ${tags.map(t=>`<span style="font-size:.67rem;padding:2px 8px;border-radius:4px;background:rgba(20,184,166,.07);color:var(--teal)">${t}</span>`).join('')}
          </div>
        </div>`;
    }).join('');

    // Group questions by type for section headers
    const typeOrder = ['mcq','short','long','true_false','fill_blank','match'];
    const grouped = {};
    qs.forEach((q,i) => {
      const t = q.question_type||'other';
      if (!grouped[t]) grouped[t] = [];
      grouped[t].push({q, i});
    });

    const sectionHtml = typeOrder.filter(t => grouped[t]).map(t => {
      const items = grouped[t];
      const sectionMarks = items.reduce((a,{q})=>a+(parseFloat(q.marks_override??q.marks)||0),0);
      const sLabel = (typeLabel[t]||t).toUpperCase();
      const rows = items.map(({q,i}) => {
        const marks = q.marks_override != null ? parseFloat(q.marks_override) : parseFloat(q.marks||0);
        const dc = diffColor[q.difficulty] || '#aaa';
        const tl = typeLabel[q.question_type] || q.question_type;
        let optionsHtml = '';
        if (q.question_type === 'mcq' && q.options && q.options.length) {
          const letters = ['A','B','C','D','E'];
          optionsHtml = `<div style="margin-top:10px;display:flex;flex-direction:column;gap:5px">` +
            q.options.map((o, oi) => `
              <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem;padding:6px 10px;border-radius:6px;background:${o.is_correct==1?'rgba(45,212,170,.1)':'rgba(15,118,110,.03)'};border:1px solid ${o.is_correct==1?'rgba(45,212,170,.3)':'var(--border2)'}">
                <span style="font-weight:700;color:${o.is_correct==1?'#2dd4aa':'var(--muted)'};min-width:18px;flex-shrink:0">${letters[oi]||oi+1}.</span>
                <span style="color:${o.is_correct==1?'#2dd4aa':'var(--text)'};word-break:break-word;flex:1">${escH(o.option_text)}</span>
                ${o.is_correct==1?'<span style="flex-shrink:0;color:#2dd4aa;font-size:.68rem;font-weight:700">✓</span>':''}
              </div>`).join('') + `</div>`;
        }
        const tags = [
          q.unit_no   ? `Unit ${q.unit_no}` : null,
          q.topic     ? escH(q.topic)        : null,
          q.bloom_level ? escH(q.bloom_level.charAt(0).toUpperCase()+q.bloom_level.slice(1)) : null,
        ].filter(Boolean);
        return `
          <div style="border:1px solid var(--border2);border-radius:10px;padding:14px 16px;background:rgba(15,118,110,.02);margin-bottom:10px;overflow:hidden">
            <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px">
              <span style="font-size:.75rem;font-weight:800;color:var(--teal);flex-shrink:0;padding-top:2px;min-width:28px">Q${i+1}.</span>
              <div style="flex:1;min-width:0">
                <div style="font-size:.88rem;color:var(--text);line-height:1.6;font-weight:500;word-break:break-word;white-space:pre-wrap">${escH(q.question_text)}</div>
                ${optionsHtml}
              </div>
              <div style="flex-shrink:0;text-align:right;padding-left:8px">
                <div style="font-size:.88rem;font-weight:700;color:var(--amber);white-space:nowrap">${marks} <span style="font-size:.68rem;font-weight:400;color:var(--muted)">mk</span></div>
              </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;padding-top:6px;border-top:1px solid var(--border2)">
              <span style="font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:4px;background:rgba(100,116,139,.1);color:var(--muted);text-transform:uppercase">${tl}</span>
              <span style="font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:4px;background:${dc}22;color:${dc};text-transform:capitalize">${q.difficulty||'—'}</span>
              ${tags.map(tag=>`<span style="font-size:.67rem;padding:2px 8px;border-radius:4px;background:rgba(20,184,166,.07);color:var(--teal)">${tag}</span>`).join('')}
            </div>
          </div>`;
      }).join('');
      return `
        <div style="margin-bottom:18px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding:8px 12px;background:rgba(15,118,110,.05);border-radius:8px;border-left:3px solid var(--teal)">
            <span style="font-size:.78rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.06em">
              <i class="fas fa-layer-group" style="margin-right:6px"></i>Section — ${sLabel}
              <span style="color:var(--muted);font-weight:400;margin-left:6px">${items.length} question${items.length>1?'s':''}</span>
            </span>
            <span style="font-size:.78rem;font-weight:700;color:var(--amber)">${sectionMarks.toFixed(1)} marks</span>
          </div>
          ${rows}
        </div>`;
    }).join('');

    sec.innerHTML = `
      ${instrHtml}
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:.85rem;font-weight:700;color:var(--text)"><i class="fas fa-list-ol" style="color:var(--teal);margin-right:6px"></i>Questions <span style="color:var(--muted);font-weight:400">(${qs.length})</span></div>
        <div style="font-size:.78rem;color:var(--muted)">Total: <strong style="color:var(--amber)">${qs.reduce((a,q)=>a+(parseFloat(q.marks_override??q.marks)||0),0).toFixed(2)} marks</strong></div>
      </div>
      ${sectionHtml}`;
  } catch(e) {
    const sec = document.getElementById('qpv-questions-section');
    if (sec) sec.innerHTML = `<div style="color:#e76f51;font-size:.82rem"><i class="fas fa-triangle-exclamation"></i> Failed to load questions.</div>`;
  }
}

async function qpvApprove(id, decision) {
  const fd = new FormData();
  fd.append('ajax_action','sa_approve_qpaper'); fd.append('id',id); fd.append('decision',decision);
  const r = await fetch('',{method:'POST',body:fd});
  const d = await r.json();
  if (d.ok) {
    showToast(d.msg,'success');
    closeModal('qpv-detail-modal');
    qpvLoad();
  } else {
    showToast(d.msg||'Error','error');
  }
}

// Auto-load scoped college views
(function() {
  <?php if ($saCollegeId): ?>
  // If scoped, pre-load depts for marks viewer and qpv
  var fd1 = new FormData(); fd1.append('ajax_action','sa_hod_marks'); fd1.append('college_id','<?= $saCollegeId ?>');
  fetch('',{method:'POST',body:fd1}).then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    var ds = document.getElementById('hmv-dept');
    if (ds) { d.depts.forEach(dep=>{ ds.innerHTML+=`<option value="${dep.id}">${dep.name}</option>`; }); }
    var es = document.getElementById('hmv-exam');
    if (es) { d.exams.forEach(ex=>{ es.innerHTML+=`<option value="${ex.id}">[${ex.dept_name}] ${ex.title} — ${ex.exam_date||''}</option>`; }); }
  });
  var fd2 = new FormData(); fd2.append('ajax_action','sa_all_qpapers'); fd2.append('college_id','<?= $saCollegeId ?>');
  fetch('',{method:'POST',body:fd2}).then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    var ds = document.getElementById('qpv-dept');
    if (ds) { d.depts.forEach(dep=>{ ds.innerHTML+=`<option value="${dep.id}">${dep.name}</option>`; }); }
  });
  // Pre-load courses for course-marks viewer
  var fd3 = new FormData(); fd3.append('ajax_action','sa_hod_course_marks'); fd3.append('college_id','<?= $saCollegeId ?>');
  <?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
  fd3.append('dept_id','<?= $saDeptId ?>');
  <?php endif; ?>
  fetch('',{method:'POST',body:fd3}).then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    var deptSel = document.getElementById('hcm-dept');
    if (deptSel && deptSel.tagName === 'SELECT') {
      d.depts.forEach(dep=>{ deptSel.innerHTML+=`<option value="${dep.id}">${dep.name} (${dep.code})</option>`; });
    }
    var cSel = document.getElementById('hcm-course');
    if (cSel) { (d.courses||[]).forEach(c=>{ cSel.innerHTML+=`<option value="${c.id}">[Sem ${c.semester||'?'}] ${c.name} (${c.code})</option>`; }); }
  });
  <?php endif; ?>
})();

// ═══════════════════════════════════════════════════════════════════════
// EXPENSE APPLICATIONS — JS
// ═══════════════════════════════════════════════════════════════════════
let EXP_DATA = [];

// Load departments into the dept filter when college changes (superadmin mode)
const expCollegeFilter = document.getElementById('expFilterCollege');
if (expCollegeFilter && expCollegeFilter.tagName === 'SELECT') {
  expCollegeFilter.addEventListener('change', function() {
    const deptSel = document.getElementById('expFilterDept');
    if (!deptSel || deptSel.tagName !== 'SELECT') return;
    deptSel.innerHTML = '<option value="">All Departments</option>';
    const cid = this.value;
    if (!cid) return;
    const fd = new FormData();
    fd.append('ajax_action','get_departments'); fd.append('college_id', cid);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
      if (!d.ok || !d.departments) return;
      d.departments.forEach(dep => {
        deptSel.innerHTML += `<option value="${dep.id}">${dep.name}</option>`;
      });
    });
  });
  // Trigger once if college is pre-selected (principal mode)
  if (expCollegeFilter.value) expCollegeFilter.dispatchEvent(new Event('change'));
}

function loadExpenses() {
  const wrap = document.getElementById('expTableWrap');
  if (!wrap) return;
  wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:8px 0"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';

  const fd = new FormData();
  fd.append('ajax_action', 'expense_list');

  const cEl = document.getElementById('expFilterCollege');
  const dEl = document.getElementById('expFilterDept');
  const sEl = document.getElementById('expFilterStatus');
  const pEl = document.getElementById('expFilterPriority');

  if (cEl) fd.append('college_id', cEl.value || '');
  if (dEl) fd.append('dept_id',    dEl.value || '');
  if (sEl) fd.append('status',     sEl.value || 'all');
  if (pEl) fd.append('priority',   pEl.value || 'all');

  fetch('', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { wrap.innerHTML = `<p style="color:#ef4444;padding:8px">${d.msg||'Error loading data'}</p>`; return; }
      EXP_DATA = d.expenses || [];
      renderExpenseTable(EXP_DATA);
      updateExpStats(EXP_DATA);
    })
    .catch(() => { wrap.innerHTML = '<p style="color:#ef4444;padding:8px">Network error</p>'; });
}

function updateExpStats(rows) {
  const counts = {pending:0, review:0, approved:0, rejected:0, disbursed:0};
  let approvedAmt = 0;
  rows.forEach(r => {
    if (counts[r.status] !== undefined) counts[r.status]++;
    if (r.status === 'approved' || r.status === 'disbursed') approvedAmt += parseFloat(r.amount||0);
  });
  const setEl = (id, val) => { const el=document.getElementById(id); if(el) el.textContent=val; };
  setEl('expStatTotal',    rows.length);
  setEl('expStatPending',  counts.pending + counts.review);
  setEl('expStatApproved', counts.approved);
  setEl('expStatRejected', counts.rejected);
  setEl('expStatDisbursed',counts.disbursed);
  setEl('expStatAmount',   '₹' + approvedAmt.toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}));

  // Nav badge for pending
  const badge = document.getElementById('expNavBadge');
  if (badge) {
    const pending = counts.pending + counts.review;
    badge.textContent = pending;
    badge.style.display = pending > 0 ? 'inline-block' : 'none';
  }
}

function renderExpenseTable(rows) {
  const wrap = document.getElementById('expTableWrap');
  if (!wrap) return;

  if (!rows.length) {
    wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:16px;text-align:center"><i class="fas fa-folder-open" style="display:block;font-size:2rem;opacity:.2;margin-bottom:8px"></i>No expense applications found.</p>';
    return;
  }

  const saRole = '<?= htmlspecialchars($saCollegeRole) ?>';  // 'principal', 'hod', or ''

  let html = `<table class="data-table" style="min-width:900px">
    <thead><tr>
      <th>#</th><th>Faculty</th><th>Dept / College</th>
      <th>Title</th><th>Category</th>
      <th>Amount</th><th>Date</th>
      <th>Priority</th><th>Status</th><th>Actions</th>
    </tr></thead><tbody>`;

  rows.forEach(r => {
    // Human-readable status label
    const statusLabels = {pending:'Pending',review:'Forwarded to Principal',approved:'Approved',rejected:'Rejected',disbursed:'Disbursed'};
    const statusPill   = `<span class="exp-pill ${r.status}">${statusLabels[r.status] || ucfirst(r.status)}</span>`;
    const priorityPill = `<span class="exp-pill ${r.priority}">${ucfirst(r.priority)}</span>`;
    const catLabel     = r.category ? r.category.replace(/_/g,' ').replace(/\b\w/g, c=>c.toUpperCase()) : '—';
    const submitted    = r.submitted_at ? r.submitted_at.split(' ')[0] : '—';

    // Determine available actions based on role and current status
    let actionBtns = '';
    if (saRole === 'hod') {
      // HOD only sees 'pending' rows (server-enforced), can Forward or Reject
      if (r.status === 'pending') {
        actionBtns = `
          <button class="exp-action-btn review"  onclick="expAction(${r.id},'review','hod')"><i class="fas fa-forward"></i> Forward to Principal</button>
          <button class="exp-action-btn reject"  onclick="expAction(${r.id},'rejected','hod')"><i class="fas fa-xmark"></i> Reject</button>`;
      }
    } else if (saRole === 'principal') {
      // Principal only sees 'review' and beyond (server-enforced)
      // Can Approve/Reject forwarded ones, Disburse approved ones
      if (r.status === 'review') {
        actionBtns = `
          <button class="exp-action-btn approve" onclick="expAction(${r.id},'approved','admin')"><i class="fas fa-check"></i> Approve</button>
          <button class="exp-action-btn reject"  onclick="expAction(${r.id},'rejected','admin')"><i class="fas fa-xmark"></i> Reject</button>`;
      } else if (r.status === 'approved') {
        actionBtns = `<button class="exp-action-btn disburse" onclick="expAction(${r.id},'disbursed','admin')"><i class="fas fa-money-bill-wave"></i> Mark Disbursed</button>`;
      }
    } else {
      // SuperAdmin sees all, can do everything
      if (r.status === 'pending') {
        actionBtns = `
          <button class="exp-action-btn review"  onclick="expAction(${r.id},'review','hod')"><i class="fas fa-forward"></i> Forward</button>
          <button class="exp-action-btn reject"  onclick="expAction(${r.id},'rejected','hod')"><i class="fas fa-xmark"></i> Reject</button>`;
      } else if (r.status === 'review') {
        actionBtns = `
          <button class="exp-action-btn approve" onclick="expAction(${r.id},'approved','admin')"><i class="fas fa-check"></i> Approve</button>
          <button class="exp-action-btn reject"  onclick="expAction(${r.id},'rejected','admin')"><i class="fas fa-xmark"></i> Reject</button>`;
      } else if (r.status === 'approved') {
        actionBtns = `<button class="exp-action-btn disburse" onclick="expAction(${r.id},'disbursed','admin')"><i class="fas fa-money-bill-wave"></i> Disburse</button>`;
      }
    }

    const receiptLink = r.receipt_path
      ? `<a href="../uploads/receipts/${encodeURIComponent(r.receipt_path)}" target="_blank" style="color:var(--teal);font-size:.7rem"><i class="fas fa-paperclip"></i></a>`
      : '';

    html += `<tr>
      <td style="font-family:monospace;color:var(--muted);font-size:.72rem">#${r.id}</td>
      <td style="font-weight:600;color:var(--text-bright)">${escHtml(r.faculty_name||'—')}</td>
      <td style="font-size:.75rem;color:var(--muted)">${escHtml(r.dept_name||'—')}<br><span style="font-size:.68rem">${escHtml(r.college_name||'')}</span></td>
      <td>
        <div style="font-weight:600;font-size:.82rem;color:var(--text);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escHtml(r.expense_title)}">${escHtml(r.expense_title)}</div>
        <div style="font-size:.68rem;color:var(--muted);margin-top:2px">${escHtml(r.reason||'')}</div>
      </td>
      <td style="font-size:.75rem">${catLabel}</td>
      <td style="font-weight:700;color:var(--text);font-family:monospace;font-size:.84rem;white-space:nowrap">${escHtml(r.currency||'INR')} ${parseFloat(r.amount||0).toLocaleString('en-IN',{minimumFractionDigits:2})} ${receiptLink}</td>
      <td style="font-size:.75rem;color:var(--muted);white-space:nowrap">${submitted}</td>
      <td>${priorityPill}</td>
      <td>${statusPill}</td>
      <td style="white-space:nowrap;min-width:140px">${actionBtns || '<span style="color:var(--muted);font-size:.72rem">—</span>'}</td>
    </tr>`;

    // Remarks row if any
    if (r.hod_remarks || r.admin_remarks) {
      const rmks = [];
      if (r.hod_remarks)   rmks.push(`<span style="color:#D97706"><i class="fas fa-user-tie"></i> HOD:</span> ${escHtml(r.hod_remarks)}`);
      if (r.admin_remarks) rmks.push(`<span style="color:#0F766E"><i class="fas fa-crown"></i> Principal:</span> ${escHtml(r.admin_remarks)}`);
      html += `<tr><td colspan="10" style="padding:0 14px 12px"><div class="exp-detail-row">${rmks.join(' &nbsp;·&nbsp; ')}</div></td></tr>`;
    }
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;
}

function expAction(expId, newStatus, remarker) {
  const labels = {review:'Forward to Principal', approved:'Approve', rejected:'Reject', disbursed:'Mark as Disbursed'};
  const label  = labels[newStatus] || newStatus;
  const needsRemarks = ['rejected','review'].includes(newStatus);
  let remarks = '';
  if (needsRemarks) {
    remarks = prompt(`${label} — add a remark (optional):`);
    if (remarks === null) return; // cancelled
  } else {
    if (!confirm(`${label} this expense application?`)) return;
  }

  const fd = new FormData();
  fd.append('ajax_action',  'expense_update_status');
  fd.append('expense_id',   expId);
  fd.append('new_status',   newStatus);
  fd.append('remarker',     remarker);
  if (remarks) fd.append('remarks', remarks);

  fetch('', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      if (d.ok) { showToast(d.msg || 'Updated', 'success'); loadExpenses(); }
      else showToast(d.msg || 'Error', 'error');
    })
    .catch(() => showToast('Network error', 'error'));
}

function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

// Auto-load when navigating to expense view
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.sidebar .nav-link[data-view="expense-applications"]').forEach(link => {
    link.addEventListener('click', function() {
      setTimeout(loadExpenses, 80);
    });
  });

  // Auto-load leave applications when navigating to leave view
  document.querySelectorAll('.sidebar .nav-link[data-view="leave-applications"]').forEach(link => {
    link.addEventListener('click', function() {
      setTimeout(loadLeaves, 80);
    });
  });
});

/* ══════════════════════════════════════════════════════════════════════════
   LEAVE APPLICATIONS — HOD Approve/Reject · Principal View
══════════════════════════════════════════════════════════════════════════ */

const LEAVE_TYPE_LABELS = {
  casual:'Casual Leave', medical:'Medical Leave', earned:'Earned Leave',
  maternity:'Maternity Leave', paternity:'Paternity Leave',
  compensatory:'Compensatory Leave', duty:'On Duty', unpaid:'Unpaid Leave', other:'Other'
};

async function loadLeaves() {
  const wrap = document.getElementById('leaveTableWrap');
  if (!wrap) return;
  wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:8px 0"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';

  const collegeEl = document.getElementById('leaveFilterCollege');
  const statusEl  = document.getElementById('leaveFilterStatus');
  const collegeId = collegeEl ? collegeEl.value : '';
  const status    = statusEl  ? statusEl.value  : 'all';

  const fd = new FormData();
  fd.append('ajax_action', 'leave_list');
  if (collegeId) fd.append('college_id', collegeId);
  fd.append('status', status);

  try {
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      updateLeaveStats(d.leaves || []);
      renderLeaveTable(d.leaves || []);
    } else {
      wrap.innerHTML = `<p style="color:var(--red);padding:8px 0">${escHtml(d.msg || 'Error loading leaves.')}</p>`;
    }
  } catch(e) {
    wrap.innerHTML = '<p style="color:var(--red);padding:8px 0">Network error.</p>';
  }
}

function updateLeaveStats(rows) {
  const counts = { pending:0, hod_pending:0, approved:0, rejected:0, cancelled:0 };
  rows.forEach(r => { if (counts[r.status] !== undefined) counts[r.status]++; });
  const total = rows.length;
  const awaitingHod = counts.hod_pending + counts.pending;
  const setEl = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v; };
  setEl('leaveStatTotal',      total);
  setEl('leaveStatHodPending', awaitingHod);
  setEl('leaveStatApproved',   counts.approved);
  setEl('leaveStatRejected',   counts.rejected);
  setEl('leaveStatPending',    counts.pending);

  // Badge
  const badge = document.getElementById('leaveNavBadge');
  if (badge) {
    badge.textContent = awaitingHod;
    badge.style.display = awaitingHod > 0 ? 'inline-block' : 'none';
  }
}

function renderLeaveTable(rows) {
  const wrap = document.getElementById('leaveTableWrap');
  if (!wrap) return;

  if (!rows.length) {
    wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:16px;text-align:center"><i class="fas fa-calendar-xmark" style="display:block;font-size:2rem;opacity:.2;margin-bottom:8px"></i>No leave applications found.</p>';
    return;
  }

  const saRole = '<?= htmlspecialchars($saCollegeRole) ?>';
  const statusLabels = {
    pending:'Pending (Admin)', hod_pending:'Awaiting HOD',
    approved:'Approved', rejected:'Rejected', cancelled:'Cancelled'
  };
  const statusColors = {
    pending:'#f59e0b', hod_pending:'#a78bfa',
    approved:'#10b981', rejected:'#ef4444', cancelled:'#6b7280'
  };

  let html = `<table class="data-table" style="min-width:850px">
    <thead><tr>
      <th>#</th><th>Applicant</th><th>Department</th>
      <th>Leave Type</th><th>From</th><th>To</th><th>Days</th>
      <th>Status</th><th>Applied On</th>
      ${saRole !== 'principal' ? '<th>Actions</th>' : '<th>HOD Remarks</th>'}
    </tr></thead><tbody>`;

  rows.forEach(r => {
    const color = statusColors[r.status] || '#6b7280';
    const label = statusLabels[r.status] || r.status;
    const statusPill = `<span class="exp-pill" style="background:${color}22;color:${color};border:1px solid ${color}44">${label}</span>`;
    const ltLabel = LEAVE_TYPE_LABELS[r.leave_type] || r.leave_type;
    const from = r.from_date ? r.from_date.split(' ')[0] : '—';
    const to   = r.to_date   ? r.to_date.split(' ')[0]   : '—';
    const applied = r.created_at ? r.created_at.split(' ')[0] : '—';
    const days = r.total_days + (r.half_day ? ' (½)' : '');

    let actionBtns = '';
    if (saRole === 'hod' && (r.status === 'hod_pending' || r.status === 'pending')) {
      actionBtns = `
        <button class="exp-action-btn approve" onclick="leaveHodAction(${r.id},'approved')"><i class="fas fa-check"></i> Approve</button>
        <button class="exp-action-btn reject"  onclick="leaveHodAction(${r.id},'rejected')"><i class="fas fa-xmark"></i> Reject</button>`;
    } else if (saRole === 'principal') {
      actionBtns = r.hod_remarks ? escHtml(r.hod_remarks) : '<span style="color:var(--muted);font-size:.72rem">—</span>';
    } else {
      // SuperAdmin: show info only
      actionBtns = '<span style="color:var(--muted);font-size:.72rem">—</span>';
    }

    html += `<tr>
      <td style="font-family:monospace;color:var(--muted);font-size:.72rem">#${r.id}</td>
      <td style="font-weight:600;color:var(--text-bright)">${escHtml(r.applicant_name||'—')}
        <div style="font-size:.68rem;color:var(--muted)">${escHtml(r.designation||'')}</div>
      </td>
      <td style="font-size:.75rem;color:var(--muted)">${escHtml(r.dept_name||'—')}</td>
      <td style="font-size:.78rem">${escHtml(ltLabel)}</td>
      <td style="font-size:.75rem;font-family:monospace">${from}</td>
      <td style="font-size:.75rem;font-family:monospace">${to}</td>
      <td style="font-weight:700;font-family:monospace;font-size:.84rem">${days}</td>
      <td>${statusPill}</td>
      <td style="font-size:.72rem;color:var(--muted);font-family:monospace">${applied}</td>
      <td style="white-space:nowrap;min-width:140px">${actionBtns}</td>
    </tr>`;

    // Remarks sub-row
    const rmks = [];
    if (r.review_remarks)  rmks.push(`<span style="color:#D97706"><i class="fas fa-shield-halved"></i> Admin:</span> ${escHtml(r.review_remarks)}`);
    if (r.hod_remarks)     rmks.push(`<span style="color:#a78bfa"><i class="fas fa-user-tie"></i> HOD:</span> ${escHtml(r.hod_remarks)}`);
    if (rmks.length) {
      html += `<tr><td colspan="10" style="padding:0 14px 10px"><div class="exp-detail-row" style="font-size:.73rem">${rmks.join(' &nbsp;·&nbsp; ')}</div></td></tr>`;
    }
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;
}

async function leaveHodAction(leaveId, newStatus) {
  const labels = { approved: 'Approve', rejected: 'Reject' };
  const label  = labels[newStatus] || newStatus;
  let remarks = '';
  if (newStatus === 'rejected') {
    remarks = prompt(`${label} leave — add a remark (optional):`);
    if (remarks === null) return;
  } else {
    if (!confirm(`${label} this leave application?`)) return;
  }

  const fd = new FormData();
  fd.append('ajax_action', 'leave_hod_action');
  fd.append('leave_id',    leaveId);
  fd.append('new_status',  newStatus);
  if (remarks) fd.append('remarks', remarks);

  try {
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) { showToast(d.msg || 'Updated', 'success'); loadLeaves(); }
    else showToast(d.msg || 'Error', 'error');
  } catch { showToast('Network error', 'error'); }
}

// ── Staff Noticeboard (SA Dashboard) ─────────────────────────────────────────

const SN_PRIORITY_COLORS = {critical:'#e76f51',high:'#f4a261',normal:'#60a5fa',low:'#94a3b8'};
const SN_TYPE_LABELS = {general:'General',urgent:'Urgent',event:'Event',circular:'Circular',holiday:'Holiday',academic:'Academic',administrative:'Administrative'};
const SN_COLLEGE_ID = <?= (int)$saCollegeId ?>;
const SN_ROLE       = '<?= $saCollegeRole ?>'; // 'principal', 'hod', or ''

function snEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function snLoadNotices(){
  const wrap = document.getElementById('sn-list-wrap');
  if(!wrap) return;
  wrap.innerHTML = '<div class="panel"><div class="panel-body" style="text-align:center;color:var(--muted);padding:48px"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:10px"></i>Loading notices…</div></div>';

  const fd = new FormData();
  fd.append('ajax_action','sn_list');
  fd.append('college_id', SN_COLLEGE_ID || document.getElementById('snCollegeSel')?.value || '');
  fd.append('type',     document.getElementById('sn-filter-type')?.value  || 'all');
  fd.append('priority', document.getElementById('sn-filter-prio')?.value  || 'all');
  fd.append('show',     document.getElementById('sn-filter-show')?.value  || 'active');

  try {
    const r = await fetch('superadmin_dashboard.php',{method:'POST',body:fd});
    const d = await r.json();
    if(!d.ok){ wrap.innerHTML='<div class="panel"><div class="panel-body" style="color:var(--red);padding:24px">'+snEsc(d.msg||'Error')+'</div></div>'; return; }

    const notices = d.notices || [];
    document.getElementById('sn-count').textContent = notices.length + ' notice' + (notices.length!==1?'s':'');

    if(!notices.length){
      wrap.innerHTML='<div class="panel"><div class="panel-body" style="text-align:center;color:var(--muted);padding:48px"><i class="fas fa-clipboard" style="font-size:2rem;opacity:.2;display:block;margin-bottom:12px"></i>No notices match your filters.</div></div>';
      return;
    }

    let html = '<div style="display:grid;gap:14px">';
    notices.forEach(n => {
      const pColor = SN_PRIORITY_COLORS[n.priority]||'#94a3b8';
      const active = parseInt(n.is_active);
      html += `<div class="panel" style="border-left:4px solid ${pColor};${active?'':'opacity:.62;'}position:relative">
        ${!active ? '<span style="position:absolute;top:10px;right:14px;font-size:.58rem;font-weight:700;letter-spacing:.12em;background:rgba(255,255,255,.07);color:var(--muted);padding:3px 8px;border-radius:5px;font-family:monospace">INACTIVE</span>' : ''}
        <div class="panel-body" style="padding:18px 22px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap">
            <div style="font-size:1.02rem;font-weight:700;color:var(--text);flex:1">${snEsc(n.title)}</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0">
              <span style="padding:3px 9px;border-radius:6px;font-size:.65rem;font-weight:700;text-transform:uppercase;background:rgba(255,255,255,.06);color:${pColor}">${snEsc(n.priority)}</span>
              <span style="padding:3px 9px;border-radius:6px;font-size:.65rem;font-weight:700;text-transform:uppercase;background:rgba(20,184,166,.1);color:var(--teal)">${snEsc(SN_TYPE_LABELS[n.notice_type]||n.notice_type)}</span>
              ${n.target_audience==='department_specific' ? `<span style="padding:3px 9px;border-radius:6px;font-size:.65rem;font-weight:700;background:rgba(139,92,246,.12);color:#a78bfa"><i class="fas fa-sitemap"></i> ${snEsc(n.department_name||'Dept')}</span>` : ''}
              ${n.target_audience==='college_specific' ? `<span style="padding:3px 9px;border-radius:6px;font-size:.65rem;font-weight:700;background:rgba(29,78,216,.12);color:#60a5fa"><i class="fas fa-school"></i> ${snEsc(n.college_name||'College')}</span>` : ''}
            </div>
          </div>
          <div style="color:var(--muted);font-size:.84rem;line-height:1.7;margin-bottom:14px;border-left:2px solid rgba(255,255,255,.08);padding-left:12px;white-space:pre-wrap;word-break:break-word">${snEsc(n.content)}</div>
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding-top:12px;border-top:1px solid var(--border)">
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:.73rem;color:var(--muted)">
              <span><i class="fas fa-user" style="color:var(--teal);font-size:.62rem;margin-right:4px"></i>${snEsc(n.created_by_name||'Admin')}</span>
              <span><i class="fas fa-calendar" style="color:var(--teal);font-size:.62rem;margin-right:4px"></i>${snEsc(n.publish_date||'')}</span>
              ${n.expiry_date ? `<span><i class="fas fa-clock" style="color:var(--teal);font-size:.62rem;margin-right:4px"></i>Expires ${snEsc(n.expiry_date)}</span>` : ''}
              <span><i class="fas fa-users" style="color:var(--teal);font-size:.62rem;margin-right:4px"></i>${(n.target_audience||'').replace(/_/g,' ')}</span>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn-secondary" style="padding:5px 12px;font-size:.75rem" onclick="snOpenEdit(${JSON.stringify(n).replace(/'/g,'&#39;')})"><i class="fas fa-pen"></i> Edit</button>
              <button class="btn-secondary" style="padding:5px 12px;font-size:.75rem;color:${active?'#f4a261':'#2dd4aa'};border-color:${active?'rgba(244,162,97,.3)':'rgba(45,212,170,.3)'}" onclick="snToggle(${n.id},this)"><i class="fas fa-${active?'eye-slash':'eye'}"></i> ${active?'Deactivate':'Activate'}</button>
              <button class="btn-icon-delete" style="padding:5px 10px;font-size:.75rem" onclick="snDelete(${n.id})"><i class="fas fa-trash"></i></button>
            </div>
          </div>
        </div>
      </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
  } catch(e){ wrap.innerHTML='<div class="panel"><div class="panel-body" style="color:var(--red);padding:24px">Network error: '+snEsc(e.message)+'</div></div>'; }
}

function snOpenCreate(){
  document.getElementById('snModalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Create Notice';
  document.getElementById('snNoticeId').value='';
  document.getElementById('snTitle').value='';
  document.getElementById('snContent').value='';
  document.getElementById('snType').value='general';
  document.getElementById('snPriority').value='normal';
  const aud = document.getElementById('snAudience');
  if(aud && aud.tagName==='SELECT') aud.value='all';
  const dept = document.getElementById('snDept'); if(dept) dept.value='';
  const expiry = document.getElementById('snExpiry'); if(expiry) expiry.value='';
  snToggleTargetFields();
  document.getElementById('snModal').classList.add('open');
}

function snOpenEdit(n){
  document.getElementById('snModalTitle').innerHTML='<i class="fas fa-pen"></i> Edit Notice';
  document.getElementById('snNoticeId').value=n.id;
  document.getElementById('snTitle').value=n.title||'';
  document.getElementById('snContent').value=n.content||'';
  document.getElementById('snType').value=n.notice_type||'general';
  document.getElementById('snPriority').value=n.priority||'normal';
  const aud = document.getElementById('snAudience');
  if(aud && aud.tagName==='SELECT') aud.value=n.target_audience||'all';
  const dept=document.getElementById('snDept'); if(dept) dept.value=n.department_id||'';
  const expiry=document.getElementById('snExpiry'); if(expiry) expiry.value=n.expiry_date||'';
  const colSel=document.getElementById('snCollegeSel'); if(colSel) colSel.value=n.college_id||'';
  snToggleTargetFields();
  document.getElementById('snModal').classList.add('open');
}

function snCloseModal(){ document.getElementById('snModal').classList.remove('open'); }

function snToggleTargetFields(){
  const aud = document.getElementById('snAudience');
  const val = aud ? (aud.tagName==='SELECT' ? aud.value : aud.value) : '';
  const df = document.getElementById('snDeptField');
  if(df) df.style.display = val==='department_specific' ? 'flex' : 'none';
}

async function snCollegeChanged(){
  const colId = document.getElementById('snCollegeSel')?.value;
  if(!colId) return;
  const fd=new FormData(); fd.append('ajax_action','sn_get_depts'); fd.append('college_id',colId);
  const r=await fetch('superadmin_dashboard.php',{method:'POST',body:fd});
  const d=await r.json();
  const sel=document.getElementById('snDept');
  if(sel && d.ok){
    sel.innerHTML='<option value="">— Select Department —</option>'+
      d.depts.map(dep=>`<option value="${dep.id}">${snEsc(dep.name)}</option>`).join('');
  }
}

async function snSubmitNotice(){
  const id=document.getElementById('snNoticeId').value;
  const title=document.getElementById('snTitle').value.trim();
  const content=document.getElementById('snContent').value.trim();
  const alertEl=document.getElementById('snModalAlert');
  alertEl.className='form-alert'; alertEl.textContent='';
  if(!title||!content){ alertEl.className='form-alert error'; alertEl.textContent='Title and content are required.'; return; }

  const aud=document.getElementById('snAudience');
  const audVal=aud?(aud.tagName==='SELECT'?aud.value:aud.value):'all';
  const colSel=document.getElementById('snCollegeSel');
  const colId=colSel?colSel.value:(SN_COLLEGE_ID||document.getElementById('snNoticeCollegeId').value);

  const fd=new FormData();
  fd.append('ajax_action', id ? 'sn_update' : 'sn_create');
  if(id) fd.append('notice_id', id);
  fd.append('college_id',  colId);
  fd.append('title',       title);
  fd.append('content',     content);
  fd.append('notice_type', document.getElementById('snType').value);
  fd.append('priority',    document.getElementById('snPriority').value);
  fd.append('target_audience', audVal);
  fd.append('department_id',   document.getElementById('snDept')?.value||'');
  fd.append('expiry_date',     document.getElementById('snExpiry')?.value||'');

  try {
    const r=await fetch('superadmin_dashboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){ snCloseModal(); showToast(d.msg||'Notice saved.','success'); snLoadNotices(); }
    else { alertEl.className='form-alert error'; alertEl.textContent=d.msg||'Error saving notice.'; }
  } catch { alertEl.className='form-alert error'; alertEl.textContent='Network error.'; }
}

async function snToggle(id,btn){
  const fd=new FormData(); fd.append('ajax_action','sn_toggle'); fd.append('notice_id',id);
  try {
    const r=await fetch('superadmin_dashboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){ showToast(d.msg||'Toggled.','success'); snLoadNotices(); }
    else showToast(d.msg||'Error','error');
  } catch { showToast('Network error','error'); }
}

async function snDelete(id){
  if(!confirm('Delete this notice permanently? This cannot be undone.')) return;
  const fd=new FormData(); fd.append('ajax_action','sn_delete'); fd.append('notice_id',id);
  try {
    const r=await fetch('superadmin_dashboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){ showToast(d.msg||'Deleted.','success'); snLoadNotices(); }
    else showToast(d.msg||'Error','error');
  } catch { showToast('Network error','error'); }
}

// Auto-load notices when navigating to the view
(function(){
  const _sv=window.switchView;
  window.switchView=function(name){
    _sv(name);
    if(name==='staff-noticeboard') setTimeout(snLoadNotices,120);
    if(name==='admission-applications') setTimeout(loadAdmissions, 80);
  };
})();

// ══════════════════════════════════════════════════════════════════
// ADMISSION APPLICATIONS — Principal accept / reject
// ══════════════════════════════════════════════════════════════════

let _admAllRows = [];

async function loadAdmissions() {
  const wrap   = document.getElementById('admTableWrap');
  const status = document.getElementById('admFilterStatus')?.value || 'all';
  if (!wrap) return;
  wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:8px 0"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';

  const fd = new FormData();
  fd.append('ajax_action', 'admission_list');
  fd.append('status', status);

  try {
    const r = await fetch('superadmin_dashboard.php', { method:'POST', body:fd });
    const d = await r.json();
    if (!d.ok) { wrap.innerHTML = `<p style="color:var(--red);font-size:.83rem">${d.msg||'Error loading.'}</p>`; return; }

    // Update stats
    const s = d.stats || {};
    const reviewCount = s['Under Review'] || 0;
    document.getElementById('admStatReview').textContent   = reviewCount;
    document.getElementById('admStatApproved').textContent = s['Approved'] || 0;
    document.getElementById('admStatRejected').textContent = s['Rejected'] || 0;
    document.getElementById('admStatTotal').textContent    = Object.values(s).reduce((a,b)=>a+b,0);

    // Badge
    const badge = document.getElementById('admNavBadge');
    if (badge) { badge.textContent = reviewCount; badge.style.display = reviewCount ? '' : 'none'; }

    _admAllRows = d.applications || [];
    renderAdmTable(_admAllRows);
  } catch(e) {
    wrap.innerHTML = '<p style="color:var(--red);font-size:.83rem">Network error.</p>';
  }
}

function filterAdmTable() {
  const q = (document.getElementById('admSearch')?.value || '').toLowerCase();
  if (!q) { renderAdmTable(_admAllRows); return; }
  renderAdmTable(_admAllRows.filter(a =>
    (a.full_name||'').toLowerCase().includes(q) ||
    (a.application_no||'').toLowerCase().includes(q) ||
    (a.course_name||'').toLowerCase().includes(q) ||
    (a.course_code||'').toLowerCase().includes(q) ||
    (a.email||'').toLowerCase().includes(q)
  ));
}

function renderAdmTable(rows) {
  const wrap = document.getElementById('admTableWrap');
  if (!rows.length) {
    wrap.innerHTML = '<p style="color:var(--muted);font-size:.83rem;padding:8px 0">No applications found.</p>';
    return;
  }

  const statusColors = {
    'Under Review': { bg:'rgba(124,58,237,.10)', color:'#7c3aed', border:'rgba(124,58,237,.28)' },
    'Approved':     { bg:'rgba(22,163,74,.09)',  color:'#16a34a', border:'rgba(22,163,74,.25)' },
    'Rejected':     { bg:'rgba(220,38,38,.08)',  color:'#dc2626', border:'rgba(220,38,38,.22)' },
  };

  let html = `
    <table style="width:100%;border-collapse:collapse;font-size:.79rem">
      <thead>
        <tr style="background:rgba(124,58,237,.05);border-bottom:2px solid rgba(124,58,237,.15)">
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">App No</th>
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Student</th>
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Course</th>
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Academics</th>
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Submitted</th>
          <th style="padding:9px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Status</th>
          <th style="padding:9px 12px;text-align:right;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Action</th>
        </tr>
      </thead>
      <tbody>`;

  rows.forEach(a => {
    const sc = statusColors[a.application_status] || { bg:'#f1f5f9', color:'#64748b', border:'#e2e8f0' };
    const isPending = a.application_status === 'Under Review';
    const dob = a.dob ? new Date(a.dob).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
    const submitted = a.submitted_at ? new Date(a.submitted_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
    const acad = [
      a.qual_10_percent ? `10th: ${a.qual_10_percent}%` : '',
      a.qual_12_percent ? `12th: ${a.qual_12_percent}%` : '',
      a.prev_cgpa       ? `UG CGPA: ${a.prev_cgpa}` : '',
    ].filter(Boolean).join(' · ') || '—';

    html += `
      <tr style="border-bottom:1px solid var(--border);transition:background .14s" onmouseover="this.style.background='rgba(124,58,237,.03)'" onmouseout="this.style.background=''">
        <td style="padding:10px 12px;font-family:var(--mono);font-size:.73rem;color:var(--teal)">${a.application_no||'—'}</td>
        <td style="padding:10px 12px">
          <div style="font-weight:600;color:var(--text)">${escHtml(a.full_name)}</div>
          <div style="font-size:.68rem;color:var(--muted)">${escHtml(a.email)}</div>
          <div style="font-size:.68rem;color:var(--muted)">${escHtml(a.mobile||'')} · ${escHtml(a.gender||'')} · DOB: ${dob}</div>
        </td>
        <td style="padding:10px 12px">
          <div style="font-weight:700;color:#7c3aed;font-size:.75rem">${escHtml(a.course_code||'')}</div>
          <div style="font-size:.7rem;color:var(--muted)">${escHtml(a.course_name||'')}</div>
          <div style="font-size:.67rem;color:var(--muted);margin-top:2px">${escHtml(a.admission_type||'')}${a.entrance_exam ? ' · '+escHtml(a.entrance_exam) : ''}</div>
        </td>
        <td style="padding:10px 12px;font-size:.72rem;color:var(--muted)">${acad}</td>
        <td style="padding:10px 12px;font-size:.72rem;color:var(--muted);white-space:nowrap">${submitted}</td>
        <td style="padding:10px 12px">
          <span style="display:inline-block;padding:3px 9px;border-radius:20px;font-size:.68rem;font-weight:700;background:${sc.bg};color:${sc.color};border:1px solid ${sc.border}">
            ${a.application_status}
          </span>
        </td>
        <td style="padding:10px 12px;text-align:right">
          ${isPending ? `
          <div style="display:flex;gap:6px;justify-content:flex-end">
            <button onclick="openAdmDecision(${a.id},'${escAttr(a.application_no)}','${escAttr(a.full_name)}','Approved')"
              style="background:rgba(22,163,74,.10);border:1px solid rgba(22,163,74,.3);color:#16a34a;padding:5px 13px;border-radius:7px;cursor:pointer;font-size:.72rem;font-weight:700;font-family:var(--font);display:inline-flex;align-items:center;gap:5px;transition:all .15s"
              onmouseover="this.style.background='rgba(22,163,74,.2)'" onmouseout="this.style.background='rgba(22,163,74,.10)'">
              <i class="fas fa-check"></i> Accept
            </button>
            <button onclick="openAdmDecision(${a.id},'${escAttr(a.application_no)}','${escAttr(a.full_name)}','Rejected')"
              style="background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);color:#dc2626;padding:5px 13px;border-radius:7px;cursor:pointer;font-size:.72rem;font-weight:700;font-family:var(--font);display:inline-flex;align-items:center;gap:5px;transition:all .15s"
              onmouseover="this.style.background='rgba(220,38,38,.18)'" onmouseout="this.style.background='rgba(220,38,38,.08)'">
              <i class="fas fa-xmark"></i> Reject
            </button>
          </div>` : `<span style="font-size:.71rem;color:var(--muted)">${a.application_status==='Approved'?'<i class="fas fa-user-graduate" style="color:#16a34a"></i> Enrolled':'<i class="fas fa-ban" style="color:#dc2626"></i> Declined'}</span>`}
        </td>
      </tr>`;
  });

  html += '</tbody></table>';
  wrap.innerHTML = html;
}

// helpers for safe HTML in JS
function escHtml(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function escAttr(s){ return (s||'').replace(/'/g,"&#39;").replace(/"/g,'&quot;'); }

// ── Decision modal ────────────────────────────────────────────────
let _admCurrent = {};

function openAdmDecision(id, appNo, name, decision) {
  _admCurrent = { id, appNo, name, decision };
  const isAccept = decision === 'Approved';
  document.getElementById('admDecTitle').textContent   = isAccept ? 'Accept Application' : 'Reject Application';
  document.getElementById('admDecSubtitle').textContent = appNo + ' · ' + name;
  document.getElementById('admDecIcon').className      = isAccept ? 'fas fa-user-graduate' : 'fas fa-ban';
  document.getElementById('admDecIcon').style.color    = isAccept ? '#16a34a' : '#dc2626';
  const iconWrap = document.getElementById('admDecIconWrap');
  iconWrap.style.background = isAccept ? 'rgba(22,163,74,.12)' : 'rgba(220,38,38,.10)';
  const confirmBtn = document.getElementById('admDecConfirmBtn');
  confirmBtn.style.background = isAccept ? '#16a34a' : '#dc2626';
  confirmBtn.innerHTML = isAccept
    ? '<i class="fas fa-check"></i> Confirm Accept'
    : '<i class="fas fa-xmark"></i> Confirm Reject';
  document.getElementById('admDecRemarks').value = '';
  document.getElementById('admDecMsg').style.display = 'none';
  document.getElementById('admDecModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeAdmDecModal() {
  document.getElementById('admDecModal').style.display = 'none';
  document.body.style.overflow = '';
}

async function submitAdmDecision() {
  const btn     = document.getElementById('admDecConfirmBtn');
  const remarks = document.getElementById('admDecRemarks').value.trim();
  const msgEl   = document.getElementById('admDecMsg');
  const isAccept = _admCurrent.decision === 'Approved';

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

  const fd = new FormData();
  fd.append('ajax_action', 'admission_action');
  fd.append('app_id',   _admCurrent.id);
  fd.append('decision', _admCurrent.decision);
  fd.append('remarks',  remarks);

  try {
    const r = await fetch('superadmin_dashboard.php', { method:'POST', body:fd });
    const d = await r.json();

    msgEl.style.display = 'block';
    if (d.ok) {
      msgEl.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:12px;background:rgba(22,163,74,.09);border:1px solid rgba(22,163,74,.25);color:#16a34a';
      msgEl.innerHTML = '<i class="fas fa-circle-check"></i> ' + d.msg
        + (d.roll_number ? `<br><span style="font-size:.72rem;opacity:.85">Roll No: <strong>${d.roll_number}</strong></span>` : '');
      document.getElementById('admDecConfirmBtn').style.display = 'none';
      showToast(isAccept ? 'Student enrolled successfully!' : 'Application rejected.', isAccept ? 'success' : 'info');
      setTimeout(() => { closeAdmDecModal(); loadAdmissions(); }, 2200);
    } else {
      msgEl.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:12px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.22);color:#dc2626';
      msgEl.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + (d.msg||'Error occurred.');
      btn.disabled = false;
      btn.innerHTML = isAccept ? '<i class="fas fa-check"></i> Confirm Accept' : '<i class="fas fa-xmark"></i> Confirm Reject';
    }
  } catch {
    btn.disabled = false;
    btn.innerHTML = isAccept ? '<i class="fas fa-check"></i> Confirm Accept' : '<i class="fas fa-xmark"></i> Confirm Reject';
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ACADEMIC DETAILS — HOD view: semester-wise courses + assigned faculty
// ═══════════════════════════════════════════════════════════════════════════
let _acadRawGroups = []; // cache for client-side semester filter

async function loadAcademicDetails() {
  const container = document.getElementById('acad-groups-container');
  if (!container) return;

  const yearEl = document.getElementById('acad-filter-year');
  const acad_year = yearEl ? yearEl.value : '';

  container.innerHTML = `
    <div style="text-align:center;padding:60px;color:var(--muted)">
      <i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px;display:block"></i>
      Loading academic details...
    </div>`;

  const r = await postAction({
    ajax_action:   'get_academic_details',
    college_id:    <?= (int)$saCollegeId ?>,
    dept_id:       <?= (int)$saDeptId ?>,
    academic_year: acad_year
  });

  if (!r.ok) {
    container.innerHTML = `<div class="panel"><div class="panel-body" style="padding:40px;text-align:center;color:var(--muted)">${r.msg || 'Failed to load.'}</div></div>`;
    return;
  }

  _acadRawGroups = r.groups || [];

  // Populate year filter dropdown (only on first load)
  if (yearEl && yearEl.options.length <= 1 && r.years) {
    r.years.forEach(y => {
      const o = document.createElement('option');
      o.value = o.textContent = y;
      if (y === '2025-26') o.selected = !acad_year;
      yearEl.appendChild(o);
    });
    // Set default to current year on first load
    if (!acad_year && r.years.length) {
      yearEl.value = r.years[0];
    }
  }

  // Populate semester filter
  const semEl = document.getElementById('acad-filter-sem');
  if (semEl) {
    const prevSem = semEl.value;
    semEl.innerHTML = '<option value="">All Semesters</option>';
    const sems = [...new Set(_acadRawGroups.map(g => g.semester))].sort((a,b)=>a-b);
    sems.forEach(s => {
      const o = document.createElement('option');
      o.value = s;
      o.textContent = `Semester ${s}`;
      if (String(s) === prevSem) o.selected = true;
      semEl.appendChild(o);
    });
  }

  // Stats
  const totalCourses  = r.total_courses || 0;
  const totalSems     = _acadRawGroups.length;
  const totalAssigned = _acadRawGroups.reduce((sum, g) =>
    sum + g.courses.reduce((s2, c) => s2 + (c.faculty ? c.faculty.length : 0), 0), 0);

  const el = id => document.getElementById(id);
  if (el('acad-stat-courses')) el('acad-stat-courses').textContent = totalCourses;
  if (el('acad-stat-sems'))    el('acad-stat-sems').textContent    = totalSems;
  if (el('acad-stat-faculty')) el('acad-stat-faculty').textContent = totalAssigned;
  if (el('acad-count'))        el('acad-count').textContent        = `${totalCourses} course${totalCourses !== 1 ? 's' : ''}`;

  renderAcademicGroups(_acadRawGroups);
}

function filterAcademicDetails() {
  const semEl = document.getElementById('acad-filter-sem');
  const sem   = semEl ? semEl.value : '';
  const filtered = sem
    ? _acadRawGroups.filter(g => String(g.semester) === sem)
    : _acadRawGroups;
  renderAcademicGroups(filtered);
}

function renderAcademicGroups(groups) {
  const container = document.getElementById('acad-groups-container');
  if (!container) return;

  if (!groups || groups.length === 0) {
    container.innerHTML = `
      <div class="panel">
        <div class="panel-body" style="padding:50px;text-align:center;color:var(--muted)">
          <i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:14px;display:block;opacity:.4"></i>
          No courses found for the selected filters.<br>
          <span style="font-size:0.8rem">Use <strong>Create Course</strong> in Assign Faculty to add courses.</span>
        </div>
      </div>`;
    return;
  }

  container.innerHTML = '';

  groups.forEach(group => {
    const semLabel   = group.semester ? `Semester ${group.semester}` : 'Unassigned Semester';
    const yearLabel  = group.academic_year || '—';
    const courses    = group.courses || [];
    const allFaculty = courses.flatMap(c => c.faculty || []);
    const unassigned = courses.filter(c => !c.faculty || c.faculty.length === 0).length;

    // Sem header card
    const card = document.createElement('div');
    card.className = 'panel';
    card.style.marginBottom = '20px';
    card.dataset.sem = group.semester;

    card.innerHTML = `
      <!-- Sem group header -->
      <div class="panel-body" style="padding:14px 22px;display:flex;align-items:center;gap:14px;
           border-bottom:1px solid rgba(255,255,255,.06);flex-wrap:wrap">
        <div style="width:42px;height:42px;border-radius:10px;background:rgba(99,102,241,.15);
             display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="font-weight:800;color:#6366f1;font-size:1rem">${group.semester || '?'}</span>
        </div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:1rem;color:var(--text)">${semLabel}</div>
          <div style="font-size:0.74rem;color:var(--muted)">
            ${yearLabel} &nbsp;·&nbsp; ${courses.length} course${courses.length !== 1 ? 's' : ''}
            &nbsp;·&nbsp; ${allFaculty.length} assignment${allFaculty.length !== 1 ? 's' : ''}
            ${unassigned > 0 ? `&nbsp;·&nbsp; <span style="color:#f87171">${unassigned} unassigned</span>` : ''}
          </div>
        </div>
        <div style="display:flex;gap:8px">
          ${unassigned > 0
            ? `<span style="font-size:0.72rem;padding:3px 10px;border-radius:20px;
                background:rgba(248,113,113,.12);color:#f87171">
                <i class="fas fa-triangle-exclamation"></i> ${unassigned} unassigned
               </span>`
            : `<span style="font-size:0.72rem;padding:3px 10px;border-radius:20px;
                background:rgba(20,184,166,.12);color:var(--teal)">
                <i class="fas fa-circle-check"></i> All assigned
               </span>`
          }
        </div>
      </div>

      <!-- Courses table -->
      <div class="panel-body panel-body-table" style="padding-top:0">
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width:40px;text-align:center">#</th>
                <th>Course / Subject</th>
                <th style="width:110px;text-align:center">Code</th>
                <th style="width:80px;text-align:center">Credits</th>
                <th>Assigned Faculty</th>
                <th style="width:120px;text-align:center">Status</th>
              </tr>
            </thead>
            <tbody>
              ${courses.map((c, idx) => {
                const faculty = c.faculty || [];
                const primaryFac = faculty.filter(f => f.is_primary == 1);
                const assistFac  = faculty.filter(f => f.is_primary == 0);

                const facHtml = faculty.length === 0
                  ? `<span style="color:#f87171;font-size:0.78rem">
                       <i class="fas fa-circle-exclamation"></i> Not assigned
                     </span>`
                  : faculty.map(f => `
                      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                             background:${f.is_primary == 1 ? 'rgba(20,184,166,.2)' : 'rgba(245,158,11,.15)'};
                             display:flex;align-items:center;justify-content:center">
                          <i class="fas fa-user" style="font-size:0.62rem;color:${f.is_primary == 1 ? 'var(--teal)' : '#f59e0b'}"></i>
                        </div>
                        <div style="min-width:0">
                          <div style="font-weight:600;font-size:0.83rem;color:var(--text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(f.full_name)}</div>
                          <div style="display:flex;align-items:center;gap:4px;margin-top:1px;flex-wrap:wrap">
                            ${f.designation ? `<span style="font-size:0.69rem;color:var(--muted)">${escHtml(f.designation)}</span><span style="color:var(--muted);font-size:.6rem;line-height:1">&#183;</span>` : ''}
                            <span style="font-size:0.64rem;padding:1px 7px;border-radius:10px;font-weight:600;
                              background:${f.is_primary == 1 ? 'rgba(20,184,166,.15)' : 'rgba(245,158,11,.12)'};
                              color:${f.is_primary == 1 ? 'var(--teal)' : '#f59e0b'}">
                              ${f.is_primary == 1 ? 'Primary' : 'Assistant'}
                            </span>
                          </div>
                        </div>
                      </div>`).join('');

                return `<tr>
                  <td style="color:var(--muted);font-size:0.78rem;text-align:center;vertical-align:top;padding-top:14px">${idx + 1}</td>
                  <td style="vertical-align:top">
                    <div style="font-weight:600;color:var(--text);font-size:0.88rem">${escHtml(c.name)}</div>
                    ${c.academic_year ? `<div style="font-size:0.72rem;color:var(--muted);margin-top:2px">${escHtml(c.academic_year)}</div>` : ''}
                  </td>
                  <td style="text-align:center;vertical-align:top;padding-top:12px">
                    <span style="font-family:monospace;font-size:0.78rem;padding:2px 8px;border-radius:5px;
                          background:rgba(99,102,241,.1);color:#818cf8;white-space:nowrap">${escHtml(c.code)}</span>
                  </td>
                  <td style="text-align:center;vertical-align:top;padding-top:14px;color:var(--text);font-weight:600;font-size:0.85rem">${parseFloat(c.credits||0).toFixed(2)}</td>
                  <td style="vertical-align:top;padding-top:10px">${facHtml}</td>
                  <td style="text-align:center;vertical-align:top;padding-top:12px">
                    ${faculty.length > 0
                      ? `<span style="font-size:0.72rem;padding:3px 10px;border-radius:20px;
                              background:rgba(20,184,166,.12);color:var(--teal);white-space:nowrap">
                           <i class="fas fa-circle-check"></i> Active
                         </span>`
                      : `<span style="font-size:0.72rem;padding:3px 10px;border-radius:20px;
                              background:rgba(248,113,113,.12);color:#f87171;white-space:nowrap">
                           <i class="fas fa-circle-xmark"></i> Unassigned
                         </span>`
                    }
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;

    container.appendChild(card);
  });
}

// HTML escape helper (reuse if already defined, else define here)
function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── College Profile ────────────────────────────────────────────────────────────
const CP_ASSET_BASE = '../uploads/college_assets/';

function cpRenderFacilityChips() {
  const val = (document.getElementById('cp_facilities').value || '').trim();
  const wrap = document.getElementById('cpFacilityChips');
  if (!wrap) return;
  if (!val) { wrap.innerHTML = ''; return; }
  const chips = val.split(',').map(s => s.trim()).filter(Boolean);
  wrap.innerHTML = chips.map(c =>
    `<span style="background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25);border-radius:20px;padding:3px 10px;font-size:.7rem;font-weight:600">${escHtml(c)}</span>`
  ).join('');
}

function cpSetLogo(path) {
  if (!path) return;
  const url = CP_ASSET_BASE + encodeURIComponent(path) + '?t=' + Date.now();
  // Profile panel logo
  const li = document.getElementById('cpLogoImg');
  if (li) { li.src = url; li.style.display = 'block'; document.getElementById('cpLogoPlaceholder').style.display = 'none'; }
  // Sidebar avatar
  const ai = document.getElementById('sidebarAdminAvatarImg');
  const initials = document.getElementById('sidebarAdminAvatarInitials');
  if (ai) { ai.src = url; ai.style.display = 'block'; }
  if (initials) initials.style.display = 'none';
}

async function loadCollegeProfile() {
  const cid = window._currentCollegeId || 0;
  const r = await postAction({ ajax_action: 'get_college_profile', college_id: cid });
  if (!r.ok) return;
  const p = r.profile;
  document.getElementById('cpCollegeId').value        = p.id;
  document.getElementById('cp_name').value            = p.name        || '';
  document.getElementById('cp_tagline').value         = p.tagline     || '';
  document.getElementById('cp_code_display').value    = p.code        || '';
  document.getElementById('cp_phone').value           = p.phone       || '';
  document.getElementById('cp_email').value           = p.email       || '';
  document.getElementById('cp_website').value         = p.website     || '';
  document.getElementById('cp_address').value         = p.address     || '';
  document.getElementById('cp_established').value     = p.established || '';
  document.getElementById('cp_affiliation').value     = p.affiliation || '';
  document.getElementById('cp_naac_grade').value      = p.naac_grade  || '';
  document.getElementById('cp_vision').value          = p.vision      || '';
  document.getElementById('cp_mission').value         = p.mission     || '';
  document.getElementById('cp_facilities').value      = p.facilities  || '';
  document.getElementById('cp_principal_name').value  = p.principal_name  || '';
  document.getElementById('cp_principal_email').value = p.principal_email || '';
  document.getElementById('cp_principal_phone').value = p.principal_phone || '';
  // College type
  const typeEl = document.getElementById('cp_college_type');
  if (typeEl && p.college_type) {
    for (let i = 0; i < typeEl.options.length; i++) {
      if (typeEl.options[i].text === p.college_type || typeEl.options[i].value === p.college_type) { typeEl.selectedIndex = i; break; }
    }
  }
  // Heading & tagline
  document.getElementById('cpCollegeHeading').textContent = p.name    || '';
  document.getElementById('cpCollegeTagline').textContent = p.tagline || '';
  // Logo
  if (p.logo_path) {
    const url = CP_ASSET_BASE + encodeURIComponent(p.logo_path);
    const li = document.getElementById('cpLogoImg');
    li.src = url; li.style.display = 'block';
    document.getElementById('cpLogoPlaceholder').style.display = 'none';
    // Also update sidebar avatar
    const ai = document.getElementById('sidebarAdminAvatarImg');
    const initials = document.getElementById('sidebarAdminAvatarInitials');
    if (ai) { ai.src = url; ai.style.display = 'block'; }
    if (initials) initials.style.display = 'none';
  }
  // Banner
  if (p.banner_path) {
    const bi = document.getElementById('cpBannerImg');
    bi.src = CP_ASSET_BASE + encodeURIComponent(p.banner_path);
    bi.style.display = 'block';
    document.getElementById('cpBannerPlaceholder').style.display = 'none';
  }
  // Facility chips
  cpRenderFacilityChips();
  // Live chip update on typing
  document.getElementById('cp_facilities').oninput = cpRenderFacilityChips;
}

// ── ANALYTICS ───────────────────────────────────────────────────────────
async function loadAnalytics() {
  const cid = window._currentCollegeId || 0;
  const loading   = document.getElementById('anlLoading');
  const noCollege = document.getElementById('anlNoCollege');
  const content   = document.getElementById('anlContent');
  loading.style.display='block'; noCollege.style.display='none'; content.style.display='none';
  if (!cid) { loading.style.display='none'; noCollege.style.display='block'; return; }

  const r = await postAction({ ajax_action:'analytics_data', college_id: cid });
  loading.style.display='none';
  if (!r.ok) { noCollege.style.display='block'; return; }
  content.style.display='block';

  // College badge
  const badge = document.getElementById('anlCollegeBadge');
  if (badge) badge.textContent = document.getElementById('sidebarCollegeName')?.textContent || '';

  // ── helper: bar chart ──────────────────────────────────────────────────
  function bar(containerId, rows, labelKey, cntKey, color, labelW=110) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!rows || !rows.length) { el.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No data</div>'; return; }
    const max = Math.max(...rows.map(x=>+x[cntKey]), 1);
    el.innerHTML = rows.map(x => `
      <div style="display:flex;align-items:center;gap:9px;font-size:.75rem">
        <div style="flex:0 0 ${labelW}px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);font-weight:600" title="${x[labelKey]}">${x[labelKey]}</div>
        <div style="flex:1;background:var(--border2);border-radius:4px;height:7px;overflow:hidden">
          <div style="width:${Math.max(2,Math.round(100*x[cntKey]/max))}%;height:100%;background:${color};border-radius:4px;transition:width .6s ease"></div>
        </div>
        <div style="flex:0 0 32px;text-align:right;color:var(--muted);font-weight:700">${x[cntKey]}</div>
      </div>`).join('');
  }

  // ── helper: pill stat row ──────────────────────────────────────────────
  function statRow(label, val, color) {
    return `<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 10px;background:${color}11;border-radius:7px;border-left:3px solid ${color}">
      <span style="color:var(--muted);font-size:.76rem">${label}</span>
      <span style="font-weight:700;color:${color};font-size:.82rem">${val}</span></div>`;
  }

  // ── KPI row (8 cards, 2 rows of 4) ───────────────────────────────────
  const fee = r.totals.fee_collected;
  const feeStr = fee >= 100000 ? '₹' + (fee/100000).toFixed(1) + 'L' : fee >= 1000 ? '₹' + (fee/1000).toFixed(1) + 'K' : '₹' + fee;
  const kpis = [
    { label:'Students',      val: r.totals.students,       icon:'fa-user-graduate',    color:'#38bdf8' },
    { label:'Faculty',       val: r.totals.faculty,        icon:'fa-chalkboard-teacher',color:'#a78bfa' },
    { label:'Departments',   val: r.totals.departments,    icon:'fa-building',          color:'#f59e0b' },
    { label:'Active Courses',val: r.totals.courses,        icon:'fa-book-open',         color:'#34d399' },
    { label:'Total Exams',   val: r.totals.exams,          icon:'fa-file-pen',          color:'#f472b6' },
    { label:'Applications',  val: r.totals.applications,   icon:'fa-inbox',             color:'#818cf8' },
    { label:'Fee Collected', val: feeStr,                  icon:'fa-indian-rupee-sign', color:'#34d399' },
    { label:'Pending Leaves',val: r.totals.pending_leaves, icon:'fa-calendar-xmark',    color:'#f87171' },
  ];
  document.getElementById('anlKpiRow').innerHTML = kpis.map(k => `
    <div style="background:var(--card-bg);border:1px solid var(--border2);border-radius:12px;padding:16px 18px;box-shadow:0 1px 6px rgba(15,118,110,.06)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <span style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em">${k.label}</span>
        <div style="width:32px;height:32px;border-radius:8px;background:${k.color}22;display:grid;place-items:center">
          <i class="fas ${k.icon}" style="color:${k.color};font-size:.8rem"></i>
        </div>
      </div>
      <div style="font-size:1.75rem;font-weight:800;color:var(--text);line-height:1">${k.val ?? 0}</div>
    </div>`).join('');

  // ── Dept charts ───────────────────────────────────────────────────────
  bar('anlDeptStudentsChart', r.deptStudents, 'name', 'cnt', '#38bdf8', 115);
  bar('anlDeptFacultyChart',  r.deptFaculty,  'name', 'cnt', '#a78bfa', 115);

  // ── Attendance ring ───────────────────────────────────────────────────
  const att = r.attendance;
  const ring  = document.getElementById('anlAttRing');
  const pctEl = document.getElementById('anlAttPct');
  const statsEl= document.getElementById('anlAttStats');
  if (!att.total) {
    ring.setAttribute('stroke-dasharray','0 100'); pctEl.textContent='—';
    statsEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.78rem">No attendance data yet</div>';
  } else {
    const pct = att.pct;
    const rc = pct >= 75 ? '#34d399' : pct >= 50 ? '#f59e0b' : '#f87171';
    ring.setAttribute('stroke', rc);
    setTimeout(() => ring.setAttribute('stroke-dasharray', `${pct} ${100-pct}`), 60);
    pctEl.textContent = pct + '%';
    statsEl.innerHTML =
      statRow('Present', att.present, '#34d399') +
      statRow('Absent',  att.absent,  '#f87171') +
      (att.late ? statRow('Late', att.late, '#f59e0b') : '') +
      statRow('Total records', att.total, '#64748b');
  }

  // ── Attendance trend ──────────────────────────────────────────────────
  const attTrEl = document.getElementById('anlAttTrendChart');
  if (!r.attTrend || !r.attTrend.length) {
    attTrEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No trend data</div>';
  } else {
    const maxPct = 100;
    attTrEl.innerHTML = r.attTrend.map(x => {
      const p = parseFloat(x.pct) || 0;
      const c = p>=75?'#34d399':p>=50?'#f59e0b':'#f87171';
      return `<div style="display:flex;align-items:center;gap:9px;font-size:.75rem">
        <div style="flex:0 0 64px;color:var(--text);font-weight:600">${x.mon}</div>
        <div style="flex:1;background:var(--border2);border-radius:4px;height:7px;overflow:hidden">
          <div style="width:${Math.max(2,Math.round(p))}%;height:100%;background:${c};border-radius:4px;transition:width .6s ease"></div>
        </div>
        <div style="flex:0 0 38px;text-align:right;color:var(--muted);font-weight:700">${p}%</div>
      </div>`;
    }).join('');
  }

  // ── Exam status ───────────────────────────────────────────────────────
  const es = r.examStats;
  const examEl = document.getElementById('anlExamStatusChart');
  const examRows = [
    {label:'Upcoming',  val: es.upcoming||0,  color:'#38bdf8'},
    {label:'Published', val: es.published||0, color:'#34d399'},
    {label:'Draft',     val: es.draft||0,     color:'#f59e0b'},
    {label:'Completed', val: es.completed||0, color:'#a78bfa'},
  ];
  const totalExams = examRows.reduce((s,x)=>s+x.val,0);
  if (!totalExams) {
    examEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No exams yet</div>';
  } else {
    examEl.innerHTML = examRows.map(x => statRow(x.label, x.val, x.color)).join('');
    // add exam type breakdown
    if (r.examTypes && r.examTypes.length) {
      examEl.innerHTML += '<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border2)">';
      r.examTypes.forEach(t => {
        const label = t.type.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
        examEl.innerHTML += `<div style="display:flex;justify-content:space-between;font-size:.74rem;padding:2px 0">
          <span style="color:var(--muted)">${label}</span><span style="font-weight:700;color:var(--text)">${t.cnt}</span></div>`;
      });
      examEl.innerHTML += '</div>';
    }
  }

  // ── Courses by semester ───────────────────────────────────────────────
  const semEl = document.getElementById('anlSemCoursesChart');
  if (!r.semCourses || !r.semCourses.length) {
    semEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No data</div>';
  } else {
    bar('anlSemCoursesChart', r.semCourses.map(x=>({name:'Sem '+x.semester,cnt:x.cnt})), 'name', 'cnt', '#34d399', 56);
  }

  // ── Avg marks per course ──────────────────────────────────────────────
  const marksEl = document.getElementById('anlCourseMarksChart');
  if (!r.courseMarks || !r.courseMarks.length) {
    marksEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No marks data yet</div>';
  } else {
    const maxMark = Math.max(...r.courseMarks.map(x=>+x.avg_pct), 1);
    marksEl.innerHTML = r.courseMarks.map(x => `
      <div style="display:flex;align-items:center;gap:9px;font-size:.75rem">
        <div style="flex:0 0 100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);font-weight:600" title="${x.course}">${x.course}</div>
        <div style="flex:1;background:var(--border2);border-radius:4px;height:7px;overflow:hidden">
          <div style="width:${Math.max(2,Math.round(x.avg_pct/maxMark*100))}%;height:100%;background:#38bdf8;border-radius:4px"></div>
        </div>
        <div style="flex:0 0 42px;text-align:right;color:var(--muted);font-weight:700">${x.avg_pct}%</div>
      </div>`).join('');
  }

  // ── Fee ring ──────────────────────────────────────────────────────────
  const fs = r.feeStats;
  const feeRing = document.getElementById('anlFeeRing');
  const feePctEl = document.getElementById('anlFeePct');
  const feeStEl  = document.getElementById('anlFeeStats');
  const totalFee = parseFloat(fs.total_fee)||0;
  const paidFee  = parseFloat(fs.paid)||0;
  const balFee   = parseFloat(fs.balance)||0;
  if (!totalFee) {
    feeRing.setAttribute('stroke-dasharray','0 100'); feePctEl.textContent='—';
    feeStEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.78rem">No fee data</div>';
  } else {
    const fp = Math.min(100, Math.round(paidFee/totalFee*100));
    feeRing.setAttribute('stroke', fp>=80?'#34d399':fp>=50?'#f59e0b':'#f87171');
    setTimeout(() => feeRing.setAttribute('stroke-dasharray', `${fp} ${100-fp}`), 60);
    feePctEl.textContent = fp + '%';
    const fmt = v => v>=100000 ? '₹'+(v/100000).toFixed(1)+'L' : v>=1000 ? '₹'+(v/1000).toFixed(1)+'K' : '₹'+v;
    feeStEl.innerHTML =
      statRow('Total Fee',   fmt(totalFee), '#64748b') +
      statRow('Collected',   fmt(paidFee),  '#34d399') +
      statRow('Balance',     fmt(balFee),   balFee>0?'#f87171':'#34d399');
  }

  // ── Leave applications ────────────────────────────────────────────────
  const lv = r.leaveStats;
  const leaveEl = document.getElementById('anlLeaveChart');
  const lvRows = [
    {label:'Pending',  val:lv.pending||0,  color:'#f59e0b'},
    {label:'Approved', val:lv.approved||0, color:'#34d399'},
    {label:'Rejected', val:lv.rejected||0, color:'#f87171'},
  ];
  const totalLv = lvRows.reduce((s,x)=>s+x.val,0);
  if (!totalLv) {
    leaveEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No leave applications</div>';
  } else {
    leaveEl.innerHTML = lvRows.map(x => statRow(x.label, x.val, x.color)).join('');
  }

  // ── Applications by status ────────────────────────────────────────────
  const appEl = document.getElementById('anlAppStatusChart');
  const appColors = {Submitted:'#38bdf8', Accepted:'#34d399', Rejected:'#f87171', 'Under Review':'#f59e0b', Waitlisted:'#a78bfa'};
  if (!r.appStats || !r.appStats.length) {
    appEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No applications yet</div>';
  } else {
    const maxApp = Math.max(...r.appStats.map(x=>+x.cnt), 1);
    appEl.innerHTML = r.appStats.map(x => {
      const c = appColors[x.status] || '#94a3b8';
      return `<div style="display:flex;align-items:center;gap:9px;font-size:.75rem">
        <div style="flex:0 0 100px;color:var(--text);font-weight:600">${x.status}</div>
        <div style="flex:1;background:var(--border2);border-radius:4px;height:7px;overflow:hidden">
          <div style="width:${Math.max(2,Math.round(100*x.cnt/maxApp))}%;height:100%;background:${c};border-radius:4px;transition:width .6s ease"></div>
        </div>
        <div style="flex:0 0 28px;text-align:right;color:var(--muted);font-weight:700">${x.cnt}</div>
      </div>`;
    }).join('');
  }

  // ── Registration trend ────────────────────────────────────────────────
  const regEl = document.getElementById('anlRegTrendChart');
  if (!r.regTrend || !r.regTrend.length) {
    regEl.innerHTML='<div style="text-align:center;color:var(--muted);font-size:.79rem;padding:18px 0">No recent registrations</div>';
  } else {
    bar('anlRegTrendChart', r.regTrend.map(x=>({name:x.mon,cnt:x.cnt})), 'name', 'cnt', '#f472b6', 68);
  }
}

async function saveCollegeProfile() {
  const btn  = document.getElementById('cpSaveBtn');
  const status = document.getElementById('cpSaveStatus');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  if (status) status.style.display = 'none';

  const fd = new FormData();
  fd.append('ajax_action',      'save_college_profile');
  fd.append('college_id',       document.getElementById('cpCollegeId').value);
  fd.append('name',             document.getElementById('cp_name').value);
  fd.append('tagline',          document.getElementById('cp_tagline').value);
  fd.append('phone',            document.getElementById('cp_phone').value);
  fd.append('email',            document.getElementById('cp_email').value);
  fd.append('website',          document.getElementById('cp_website').value);
  fd.append('address',          document.getElementById('cp_address').value);
  fd.append('established',      document.getElementById('cp_established').value);
  fd.append('college_type',     document.getElementById('cp_college_type').value);
  fd.append('affiliation',      document.getElementById('cp_affiliation').value);
  fd.append('naac_grade',       document.getElementById('cp_naac_grade').value);
  fd.append('vision',           document.getElementById('cp_vision').value);
  fd.append('mission',          document.getElementById('cp_mission').value);
  fd.append('facilities',       document.getElementById('cp_facilities').value);
  fd.append('principal_name',   document.getElementById('cp_principal_name').value);
  fd.append('principal_email',  document.getElementById('cp_principal_email').value);
  fd.append('principal_phone',  document.getElementById('cp_principal_phone').value);
  const logoFile   = document.getElementById('cpLogoInput').files[0];
  const bannerFile = document.getElementById('cpBannerInput').files[0];
  if (logoFile)   fd.append('logo',   logoFile);
  if (bannerFile) fd.append('banner', bannerFile);

  try {
    const res = await fetch(window.location.href, { method: 'POST', body: fd });
    const r   = await res.json();
    if (r.ok) {
      if (status) {
        status.style.cssText = 'display:inline;font-size:.78rem;color:#fff;background:rgba(52,211,153,.2);border:1px solid rgba(52,211,153,.4);padding:5px 14px;border-radius:20px;font-weight:600';
        status.textContent   = '✓ ' + r.msg;
      }
      document.getElementById('cpCollegeHeading').textContent = document.getElementById('cp_name').value;
      document.getElementById('cpCollegeTagline').textContent = document.getElementById('cp_tagline').value;
      const scn = document.getElementById('sidebarCollegeName');
      if (scn) scn.textContent = document.getElementById('cp_name').value;
      if (r.logo_path)   cpSetLogo(r.logo_path);
      if (r.banner_path) {
        const bi = document.getElementById('cpBannerImg');
        bi.src = CP_ASSET_BASE + encodeURIComponent(r.banner_path) + '?t=' + Date.now();
        bi.style.display = 'block';
        document.getElementById('cpBannerPlaceholder').style.display = 'none';
      }
      cpRenderFacilityChips();
    } else {
      if (status) {
        status.style.cssText = 'display:inline;font-size:.78rem;color:#fff;background:rgba(248,113,113,.18);border:1px solid rgba(248,113,113,.4);padding:5px 14px;border-radius:20px;font-weight:600';
        status.textContent   = '✗ ' + (r.msg || 'Save failed');
      }
    }
  } catch(e) {
    if (status) {
      status.style.cssText = 'display:inline;font-size:.78rem;color:#f87171;padding:5px 0';
      status.textContent   = '✗ Network error';
    }
  }
  btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Changes';
  if (status) setTimeout(() => { status.style.display = 'none'; }, 4000);
}

// Logo preview on select
document.getElementById('cpLogoInput').addEventListener('change', function() {
  const file = this.files[0]; if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const li = document.getElementById('cpLogoImg');
    li.src = e.target.result; li.style.display = 'block';
    document.getElementById('cpLogoPlaceholder').style.display = 'none';
    // Live preview in sidebar avatar too
    const ai = document.getElementById('sidebarAdminAvatarImg');
    const initials = document.getElementById('sidebarAdminAvatarInitials');
    if (ai) { ai.src = e.target.result; ai.style.display = 'block'; }
    if (initials) initials.style.display = 'none';
  };
  reader.readAsDataURL(file);
});

// Banner preview on select
document.getElementById('cpBannerInput').addEventListener('change', function() {
  const file = this.files[0]; if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const bi = document.getElementById('cpBannerImg');
    bi.src = e.target.result; bi.style.display = 'block';
    document.getElementById('cpBannerPlaceholder').style.display = 'none';
  };
  reader.readAsDataURL(file);
});

</script>

<!-- ══ ADMISSION DECISION MODAL ═══════════════════════════════════════════ -->
<div id="admDecModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeAdmDecModal()">
  <div style="background:#fff;border-radius:16px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18);overflow:hidden;animation:slideUp .25s ease both">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,rgba(124,58,237,.08),rgba(124,58,237,.03));border-bottom:1px solid rgba(124,58,237,.12);padding:18px 20px;display:flex;align-items:center;gap:12px">
      <div id="admDecIconWrap" style="width:42px;height:42px;border-radius:10px;display:grid;place-items:center;flex-shrink:0">
        <i id="admDecIcon" class="fas fa-user-graduate" style="font-size:.95rem"></i>
      </div>
      <div>
        <div id="admDecTitle" style="font-size:.93rem;font-weight:700;color:var(--text)">Accept Application</div>
        <div id="admDecSubtitle" style="font-size:.69rem;color:var(--muted);margin-top:2px;font-family:var(--mono)"></div>
      </div>
      <button onclick="closeAdmDecModal()" style="margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px"><i class="fas fa-xmark"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:20px">
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text);margin-bottom:6px">
          Remarks <span style="color:var(--muted);font-weight:400">(optional)</span>
        </label>
        <textarea id="admDecRemarks" rows="3" placeholder="Add remarks for this decision…"
          style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid var(--border);border-radius:8px;font-size:.81rem;font-family:var(--font);color:var(--text);background:var(--bg);resize:vertical;outline:none;transition:border .18s"
          onfocus="this.style.borderColor='rgba(124,58,237,.4)'" onblur="this.style.borderColor='var(--border)'"></textarea>
      </div>
      <div id="admDecMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:12px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button onclick="closeAdmDecModal()" style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:var(--font);font-weight:600;transition:all .18s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">
          Cancel
        </button>
        <button id="admDecConfirmBtn" onclick="submitAdmDecision()"
          style="border:none;color:#fff;padding:8px 20px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:var(--font);font-weight:700;display:inline-flex;align-items:center;gap:7px;transition:background .18s">
          <i class="fas fa-check"></i> Confirm
        </button>
      </div>
    </div>
  </div>
</div>
<!-- ══ EXAM HALL MODAL ═══════════════════════════════════════════════════ -->
<div id="examHallModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeExamHallModal()">
  <div style="background:var(--surface,#fff);border-radius:16px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18);overflow:hidden;animation:slideUp .25s ease both">
    <div style="background:linear-gradient(135deg,rgba(20,184,166,.1),rgba(20,184,166,.04));border-bottom:1px solid var(--border2);padding:16px 20px;display:flex;align-items:center;gap:12px">
      <div style="width:38px;height:38px;border-radius:10px;background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.3);display:grid;place-items:center;flex-shrink:0">
        <i class="fas fa-door-open" style="color:var(--teal);font-size:.9rem"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:.92rem;color:var(--text)">Add Exam Hall</div>
        <div style="font-size:.7rem;color:var(--muted);margin-top:1px">Create a new exam hall for the selected college</div>
      </div>
      <button onclick="closeExamHallModal()" style="margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px"><i class="fas fa-xmark"></i></button>
    </div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:14px">
      <div id="examHallModalMsg" style="display:none;padding:9px 13px;border-radius:8px;font-size:.8rem"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="grid-column:1/-1">
          <label class="form-label">Hall Name *</label>
          <input id="ehName" type="text" class="input-field" placeholder="e.g. Main Examination Hall">
        </div>
        <div>
          <label class="form-label">Hall Code</label>
          <input id="ehCode" type="text" class="input-field" placeholder="e.g. MEH-01" style="text-transform:uppercase">
        </div>
        <div>
          <label class="form-label">Capacity *</label>
          <input id="ehCapacity" type="number" class="input-field" placeholder="60" min="1" max="500">
        </div>
        <div>
          <label class="form-label">Building</label>
          <input id="ehBuilding" type="text" class="input-field" placeholder="e.g. Block A">
        </div>
        <div>
          <label class="form-label">Floor</label>
          <input id="ehFloor" type="text" class="input-field" placeholder="e.g. Ground Floor">
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding-top:18px">
          <label style="display:flex;align-items:center;gap:6px;font-size:.8rem;color:var(--text);cursor:pointer">
            <input type="checkbox" id="ehProjector"> Projector
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:.8rem;color:var(--text);cursor:pointer">
            <input type="checkbox" id="ehAC"> AC
          </label>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
        <button onclick="closeExamHallModal()" style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:inherit;font-weight:600">Cancel</button>
        <button id="ehSaveBtn" onclick="saveExamHall()" style="background:var(--teal,#0f766e);color:#fff;border:none;padding:8px 22px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:inherit;font-weight:700;display:inline-flex;align-items:center;gap:7px">
          <i class="fas fa-floppy-disk"></i> Save Hall
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ HOD ALLOT YEAR & SECTION MODAL (body-level — must be outside data-view divs) ═══════════════ -->
<?php if ($saCollegeRole === 'hod' && $saDeptId): ?>
<div id="hodAllotModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.48);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeHodAllotModal()">
  <div style="background:var(--surface,#ffffff);border:1px solid var(--border,rgba(0,0,0,.1));border-radius:16px;max-width:660px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden;animation:slideUp .22s ease both;max-height:90vh;display:flex;flex-direction:column">
    <!-- Header -->
    <div style="padding:18px 20px;border-bottom:1px solid var(--border,rgba(0,0,0,.08));display:flex;align-items:center;gap:12px;flex-shrink:0">
      <div style="width:40px;height:40px;border-radius:10px;background:rgba(124,58,237,.14);border:1px solid rgba(124,58,237,.24);display:grid;place-items:center;flex-shrink:0">
        <i class="fas fa-layer-group" style="color:#7c3aed;font-size:0.9rem"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:0.95rem;color:var(--text)">Allot Year &amp; Section</div>
        <div style="font-size:0.72rem;color:var(--muted);margin-top:2px">Set year, semester and section for your department's students</div>
      </div>
      <button onclick="closeHodAllotModal()" style="margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px"><i class="fas fa-xmark"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:18px 20px;overflow-y:auto;flex:1">
      <div id="hodAllotMsg" style="display:none;margin-bottom:12px;padding:9px 13px;border-radius:8px;font-size:0.79rem"></div>
      <div style="background:rgba(124,58,237,.05);border:1px solid rgba(124,58,237,.14);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:0.76rem;color:#7c3aed;display:flex;align-items:flex-start;gap:8px">
        <i class="fas fa-circle-info" style="margin-top:2px;flex-shrink:0"></i>
        <span>Only rows where all three fields (Year, Semester, Section) are selected will be saved.</span>
      </div>
      <!-- Loading state -->
      <div id="hodAllotLoading" style="text-align:center;padding:24px;color:var(--muted)">
        <i class="fas fa-spinner fa-spin"></i> Loading students…
      </div>
      <!-- Student rows container -->
      <div id="hodAllotList" style="display:none;border:1px solid var(--border,rgba(0,0,0,.1));border-radius:10px;overflow:hidden"></div>
    </div>
    <!-- Footer -->
    <div style="padding:14px 20px;border-top:1px solid var(--border,rgba(0,0,0,.08));display:flex;justify-content:flex-end;gap:10px;flex-shrink:0">
      <button onclick="closeHodAllotModal()" style="background:none;border:1px solid var(--border,rgba(0,0,0,.15));color:var(--muted);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:0.82rem;font-family:inherit;font-weight:600;transition:all .15s">
        <i class="fas fa-xmark"></i> Cancel
      </button>
      <button id="hodAllotSaveBtn" onclick="saveHodAllotment()" style="background:#7c3aed;color:#fff;border:none;padding:8px 20px;border-radius:8px;cursor:pointer;font-size:0.82rem;font-family:inherit;font-weight:700;display:inline-flex;align-items:center;gap:7px;transition:background .15s" onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
        <i class="fas fa-save"></i> Save All Allotments
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

</body>
</html>