<?php
// ─────────────────────────────────────────────────────────────────────────────
// profile.php — Faculty Profile & Settings
// Allows faculty to view and update their personal, contact, and account info.
// All updates are scoped to the logged-in user only.
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

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function initials($name) {
    $p = explode(' ', trim($name));
    return strtoupper(substr($p[0],0,1).(isset($p[1])?substr($p[1],0,1):''));
}

$db = getDB();
if ($db) {
    try { $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Exception $e) {}
}

// ── Load fresh user row ────────────────────────────────────────────────────
$profileUser = [];
if ($db && $userId) {
    $st = $db->prepare('SELECT u.*, d.name AS dept_name, d.code AS dept_code, c.name AS college_name, c.code AS college_code
                        FROM users u
                        LEFT JOIN departments d ON d.id = u.department_id
                        LEFT JOIN colleges    c ON c.id = u.college_id
                        WHERE u.id = ? LIMIT 1');
    $st->execute([$userId]);
    $profileUser = $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ── Load extra profile details ─────────────────────────────────────────────
$extraProfile = [];
if ($db && $userId) {
    try {
        $st = $db->prepare('SELECT * FROM faculty_profiles WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $extraProfile = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $extraProfile = []; // table may not exist yet
    }
}

// ── Merge for display ──────────────────────────────────────────────────────
$pf = array_merge([
    'full_name'          => '',
    'email'              => '',
    'phone'              => '',
    'username'           => '',
    'designation'        => '',
    'faculty_department' => '',
    'avatar_color'       => '#00d4bb',
    'dept_name'          => '',
    'dept_code'          => '',
    'college_name'       => '',
    'college_code'       => '',
], $profileUser, [
    'bio'                => $extraProfile['bio']                ?? '',
    'dob'                => $extraProfile['dob']                ?? '',
    'gender'             => $extraProfile['gender']             ?? '',
    'address'            => $extraProfile['address']            ?? '',
    'city'               => $extraProfile['city']               ?? '',
    'state'              => $extraProfile['state']              ?? '',
    'pincode'            => $extraProfile['pincode']            ?? '',
    'emergency_contact'  => $extraProfile['emergency_contact']  ?? '',
    'emergency_name'     => $extraProfile['emergency_name']     ?? '',
    'blood_group'        => $extraProfile['blood_group']        ?? '',
    'qualification'      => $extraProfile['qualification']      ?? '',
    'experience_years'   => $extraProfile['experience_years']   ?? '',
    'specialization'     => $extraProfile['specialization']     ?? '',
    'linkedin'           => $extraProfile['linkedin']           ?? '',
    'research_interests' => $extraProfile['research_interests'] ?? '',
    'publications'       => $extraProfile['publications']       ?? '',
    'avatar_color'       => $profileUser['avatar_color']        ?? '#00d4bb',
    'avatar_path'        => $profileUser['avatar_path']         ?? '',
]);

$successMsg = '';
$errorMsg   = '';

// ══════════════════════════════════════════════════════════════════════════════
// AJAX: save_profile
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_profile']) && $db) {
    header('Content-Type: application/json');

    $section = $_POST['section'] ?? '';

    // ── Personal Info ──────────────────────────────────────────────────────
    if ($section === 'personal') {
        $fullName    = trim($_POST['full_name']    ?? '');
        $phone       = trim($_POST['phone']        ?? '');
        $designation = trim($_POST['designation']  ?? '');
        $facDept     = trim($_POST['faculty_department'] ?? '');
        $avatarColor = trim($_POST['avatar_color'] ?? '#00d4bb');

        // Validate full name
        if (!$fullName)
            { echo json_encode(['ok'=>false,'field'=>'pFullName','msg'=>'Full name is required.']); exit; }
        if (strlen($fullName) < 3)
            { echo json_encode(['ok'=>false,'field'=>'pFullName','msg'=>'Full name must be at least 3 characters.']); exit; }
        if (strlen($fullName) > 150)
            { echo json_encode(['ok'=>false,'field'=>'pFullName','msg'=>'Full name must be under 150 characters.']); exit; }
        if (!preg_match('/^[a-zA-Z\s\.\-\']+$/u', $fullName))
            { echo json_encode(['ok'=>false,'field'=>'pFullName','msg'=>'Full name must contain only letters, spaces, dots, hyphens.']); exit; }

        // Validate phone (format: +dialcode 10digits, e.g. +91 9876543210)
        if ($phone) {
            $digitsOnly = preg_replace('/\D/', '', $phone);
            if (strlen($digitsOnly) < 10 || strlen($digitsOnly) > 15)
                { echo json_encode(['ok'=>false,'field'=>'pPhone','msg'=>'Enter a valid phone number (10 digits after country code).']); exit; }
        }

        // Validate designation
        if ($designation && strlen($designation) > 80)
            { echo json_encode(['ok'=>false,'field'=>'pDesignation','msg'=>'Designation must be under 80 characters.']); exit; }
        if ($designation && preg_match('/[<>{}]/', $designation))
            { echo json_encode(['ok'=>false,'field'=>'pDesignation','msg'=>'Designation contains invalid characters.']); exit; }

        // Validate faculty dept label
        if ($facDept && strlen($facDept) > 120)
            { echo json_encode(['ok'=>false,'field'=>'pFacDept','msg'=>'Department label must be under 120 characters.']); exit; }

        // Validate avatar color
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $avatarColor))
            { $avatarColor = '#00d4bb'; }

        try {
            $db->prepare('UPDATE users SET full_name=?, phone=?, designation=?, faculty_department=?, avatar_color=?, updated_at=NOW() WHERE id=?')
               ->execute([$fullName, $phone ?: null, $designation ?: null, $facDept ?: null, $avatarColor, $userId]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Personal info updated successfully.','full_name'=>$fullName,'avatar_color'=>$avatarColor,'initials'=>initials($fullName)]);
        exit;
    }

    // ── Academic & Professional ────────────────────────────────────────────
    if ($section === 'academic') {
        $bio           = trim($_POST['bio']               ?? '');
        $qualification = trim($_POST['qualification']     ?? '');
        $expYears      = trim($_POST['experience_years']  ?? '');
        $specialization= trim($_POST['specialization']    ?? '');
        $research      = trim($_POST['research_interests']?? '');
        $publications  = trim($_POST['publications']      ?? '');
        $linkedin      = trim($_POST['linkedin']          ?? '');

        // Bio
        if (strlen($bio) > 500)
            { echo json_encode(['ok'=>false,'field'=>'aBio','msg'=>'Bio must be under 500 characters.']); exit; }

        // Qualification
        if (strlen($qualification) > 200)
            { echo json_encode(['ok'=>false,'field'=>'aQual','msg'=>'Qualification must be under 200 characters.']); exit; }

        // Experience years
        if ($expYears !== '') {
            if (!ctype_digit($expYears) && !is_numeric($expYears))
                { echo json_encode(['ok'=>false,'field'=>'aExp','msg'=>'Experience must be a number.']); exit; }
            $expYearsInt = (int)$expYears;
            if ($expYearsInt < 0 || $expYearsInt > 60)
                { echo json_encode(['ok'=>false,'field'=>'aExp','msg'=>'Experience years must be between 0 and 60.']); exit; }
        } else { $expYearsInt = null; }

        // Specialization
        if (strlen($specialization) > 200)
            { echo json_encode(['ok'=>false,'field'=>'aSpec','msg'=>'Specialization must be under 200 characters.']); exit; }

        // Research interests
        if (strlen($research) > 1000)
            { echo json_encode(['ok'=>false,'field'=>'aResearch','msg'=>'Research interests must be under 1000 characters.']); exit; }

        // Publications
        if (strlen($publications) > 1000)
            { echo json_encode(['ok'=>false,'field'=>'aPublications','msg'=>'Publications must be under 1000 characters.']); exit; }

        // LinkedIn URL
        if ($linkedin) {
            if (!filter_var($linkedin, FILTER_VALIDATE_URL))
                { echo json_encode(['ok'=>false,'field'=>'aLinkedin','msg'=>'Enter a valid LinkedIn URL (e.g. https://linkedin.com/in/yourname).']); exit; }
            if (!preg_match('/^https?:\/\/(www\.)?linkedin\.com\//i', $linkedin))
                { echo json_encode(['ok'=>false,'field'=>'aLinkedin','msg'=>'URL must be a LinkedIn URL (linkedin.com/...).']); exit; }
            if (strlen($linkedin) > 255)
                { echo json_encode(['ok'=>false,'field'=>'aLinkedin','msg'=>'LinkedIn URL too long (max 255 chars).']); exit; }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS `faculty_profiles` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL UNIQUE,
            `bio` TEXT,`dob` DATE DEFAULT NULL,`gender` ENUM('Male','Female','Other') DEFAULT NULL,
            `blood_group` VARCHAR(10) DEFAULT NULL,`address` TEXT,`city` VARCHAR(80) DEFAULT NULL,
            `state` VARCHAR(80) DEFAULT NULL,`pincode` VARCHAR(12) DEFAULT NULL,
            `emergency_name` VARCHAR(120) DEFAULT NULL,`emergency_contact` VARCHAR(20) DEFAULT NULL,
            `qualification` VARCHAR(200) DEFAULT NULL,`experience_years` TINYINT UNSIGNED DEFAULT NULL,
            `specialization` VARCHAR(200) DEFAULT NULL,`research_interests` TEXT,`publications` TEXT,
            `linkedin` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $db->prepare('INSERT INTO faculty_profiles (user_id,bio,qualification,experience_years,specialization,research_interests,publications,linkedin)
                          VALUES(?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE bio=VALUES(bio),qualification=VALUES(qualification),
                            experience_years=VALUES(experience_years),specialization=VALUES(specialization),
                            research_interests=VALUES(research_interests),publications=VALUES(publications),
                            linkedin=VALUES(linkedin),updated_at=NOW()')
               ->execute([$userId, $bio ?: null, $qualification ?: null, $expYearsInt,
                          $specialization ?: null, $research ?: null, $publications ?: null, $linkedin ?: null]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Academic details updated successfully.']);
        exit;
    }

    // ── Personal Detail (DOB, gender, blood group) ─────────────────────────
    if ($section === 'personal_detail') {
        $dob        = trim($_POST['dob']         ?? '');
        $gender     = trim($_POST['gender']      ?? '');
        $bloodGroup = trim($_POST['blood_group'] ?? '');

        // Validate DOB
        if ($dob) {
            $dobTs = strtotime($dob);
            if (!$dobTs || !checkdate(date('m',$dobTs),date('d',$dobTs),date('Y',$dobTs)))
                { echo json_encode(['ok'=>false,'field'=>'pdDob','msg'=>'Enter a valid date of birth.']); exit; }
            $age = (int)((time() - $dobTs) / 31557600); // approx years
            if ($age < 18)
                { echo json_encode(['ok'=>false,'field'=>'pdDob','msg'=>'Age must be at least 18 years.']); exit; }
            if ($age > 100)
                { echo json_encode(['ok'=>false,'field'=>'pdDob','msg'=>'Please enter a realistic date of birth.']); exit; }
            if ($dobTs > time())
                { echo json_encode(['ok'=>false,'field'=>'pdDob','msg'=>'Date of birth cannot be in the future.']); exit; }
        }

        // Validate gender
        if ($gender && !in_array($gender, ['Male','Female','Other']))
            { echo json_encode(['ok'=>false,'field'=>'pdGender','msg'=>'Invalid gender selection.']); exit; }

        // Validate blood group
        if ($bloodGroup && !in_array($bloodGroup, ['A+','A-','B+','B-','AB+','AB-','O+','O-']))
            { echo json_encode(['ok'=>false,'field'=>'pdBlood','msg'=>'Invalid blood group selection.']); exit; }

        $db->exec("CREATE TABLE IF NOT EXISTS `faculty_profiles` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL UNIQUE,
            `bio` TEXT,`dob` DATE DEFAULT NULL,`gender` ENUM('Male','Female','Other') DEFAULT NULL,
            `blood_group` VARCHAR(10) DEFAULT NULL,`address` TEXT,`city` VARCHAR(80) DEFAULT NULL,
            `state` VARCHAR(80) DEFAULT NULL,`pincode` VARCHAR(12) DEFAULT NULL,
            `emergency_name` VARCHAR(120) DEFAULT NULL,`emergency_contact` VARCHAR(20) DEFAULT NULL,
            `qualification` VARCHAR(200) DEFAULT NULL,`experience_years` TINYINT UNSIGNED DEFAULT NULL,
            `specialization` VARCHAR(200) DEFAULT NULL,`research_interests` TEXT,`publications` TEXT,
            `linkedin` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $db->prepare('INSERT INTO faculty_profiles (user_id,dob,gender,blood_group)
                          VALUES(?,?,?,?)
                          ON DUPLICATE KEY UPDATE dob=VALUES(dob),gender=VALUES(gender),blood_group=VALUES(blood_group),updated_at=NOW()')
               ->execute([$userId, $dob ?: null, $gender ?: null, $bloodGroup ?: null]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Personal details updated successfully.']);
        exit;
    }

    // ── Address & Emergency Contact ────────────────────────────────────────
    if ($section === 'address') {
        $address   = trim($_POST['address']           ?? '');
        $city      = trim($_POST['city']              ?? '');
        $state     = trim($_POST['state']             ?? '');
        $pincode   = trim($_POST['pincode']           ?? '');
        $emgName   = trim($_POST['emergency_name']    ?? '');
        $emgPhone  = trim($_POST['emergency_contact'] ?? '');

        // Address
        if ($address && strlen($address) > 500)
            { echo json_encode(['ok'=>false,'field'=>'adAddress','msg'=>'Address must be under 500 characters.']); exit; }

        // City
        if ($city) {
            if (strlen($city) > 80)
                { echo json_encode(['ok'=>false,'field'=>'adCity','msg'=>'City name must be under 80 characters.']); exit; }
            if (!preg_match('/^[a-zA-Z\s\-\.]+$/u', $city))
                { echo json_encode(['ok'=>false,'field'=>'adCity','msg'=>'City name must contain only letters, spaces, hyphens.']); exit; }
        }

        // State
        if ($state) {
            if (strlen($state) > 80)
                { echo json_encode(['ok'=>false,'field'=>'adState','msg'=>'State name must be under 80 characters.']); exit; }
            if (!preg_match('/^[a-zA-Z\s\-\.]+$/u', $state))
                { echo json_encode(['ok'=>false,'field'=>'adState','msg'=>'State name must contain only letters, spaces, hyphens.']); exit; }
        }

        // PIN code
        if ($pincode) {
            if (!preg_match('/^\d{5,10}$/', preg_replace('/\s/','',$pincode)))
                { echo json_encode(['ok'=>false,'field'=>'adPincode','msg'=>'Enter a valid PIN/ZIP code (5–10 digits).']); exit; }
        }

        // Emergency contact name
        if ($emgName) {
            if (strlen($emgName) > 120)
                { echo json_encode(['ok'=>false,'field'=>'adEmgName','msg'=>'Emergency contact name must be under 120 characters.']); exit; }
            if (!preg_match('/^[a-zA-Z\s\.\-\']+$/u', $emgName))
                { echo json_encode(['ok'=>false,'field'=>'adEmgName','msg'=>'Contact name must contain only letters, spaces, dots, hyphens.']); exit; }
        }

        // Emergency phone — required if name is given
        if ($emgName && !$emgPhone)
            { echo json_encode(['ok'=>false,'field'=>'adEmgPhone','msg'=>'Please provide a phone number for the emergency contact.']); exit; }
        if ($emgPhone) {
            $emgDigits = preg_replace('/\D/', '', $emgPhone);
            if (strlen($emgDigits) < 10 || strlen($emgDigits) > 15)
                { echo json_encode(['ok'=>false,'field'=>'adEmgPhone','msg'=>'Enter a valid phone number (10 digits after country code).']); exit; }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS `faculty_profiles` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL UNIQUE,
            `bio` TEXT,`dob` DATE DEFAULT NULL,`gender` ENUM('Male','Female','Other') DEFAULT NULL,
            `blood_group` VARCHAR(10) DEFAULT NULL,`address` TEXT,`city` VARCHAR(80) DEFAULT NULL,
            `state` VARCHAR(80) DEFAULT NULL,`pincode` VARCHAR(12) DEFAULT NULL,
            `emergency_name` VARCHAR(120) DEFAULT NULL,`emergency_contact` VARCHAR(20) DEFAULT NULL,
            `qualification` VARCHAR(200) DEFAULT NULL,`experience_years` TINYINT UNSIGNED DEFAULT NULL,
            `specialization` VARCHAR(200) DEFAULT NULL,`research_interests` TEXT,`publications` TEXT,
            `linkedin` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $db->prepare('INSERT INTO faculty_profiles (user_id,address,city,state,pincode,emergency_name,emergency_contact)
                          VALUES(?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE address=VALUES(address),city=VALUES(city),state=VALUES(state),
                            pincode=VALUES(pincode),emergency_name=VALUES(emergency_name),
                            emergency_contact=VALUES(emergency_contact),updated_at=NOW()')
               ->execute([$userId, $address ?: null, $city ?: null, $state ?: null,
                          $pincode ?: null, $emgName ?: null, $emgPhone ?: null]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Address & emergency contact updated successfully.']);
        exit;
    }

    // ── Change Password ────────────────────────────────────────────────────
    if ($section === 'password') {
        $current = $_POST['current_password'] ?? '';
        $newPwd  = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current)
            { echo json_encode(['ok'=>false,'field'=>'currentPwd','msg'=>'Current password is required.']); exit; }
        if (!$newPwd)
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'New password is required.']); exit; }
        if (strlen($newPwd) < 8)
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'New password must be at least 8 characters.']); exit; }
        if (strlen($newPwd) > 128)
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'Password must be under 128 characters.']); exit; }
        if (!preg_match('/[A-Z]/', $newPwd))
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'Password must contain at least one uppercase letter.']); exit; }
        if (!preg_match('/[0-9]/', $newPwd))
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'Password must contain at least one number.']); exit; }
        if (!$confirm)
            { echo json_encode(['ok'=>false,'field'=>'confirmPwd','msg'=>'Please confirm your new password.']); exit; }
        if ($newPwd !== $confirm)
            { echo json_encode(['ok'=>false,'field'=>'confirmPwd','msg'=>'Passwords do not match.']); exit; }
        if ($current === $newPwd)
            { echo json_encode(['ok'=>false,'field'=>'newPwd','msg'=>'New password must be different from current password.']); exit; }

        $st = $db->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password']))
            { echo json_encode(['ok'=>false,'field'=>'currentPwd','msg'=>'Current password is incorrect.']); exit; }

        try {
            $db->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?')
               ->execute([password_hash($newPwd, PASSWORD_BCRYPT), $userId]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Password changed successfully.']);
        exit;
    }

    // ── Change Email ───────────────────────────────────────────────────────
    if ($section === 'email') {
        $newEmail = trim($_POST['new_email'] ?? '');
        if (!$newEmail)
            { echo json_encode(['ok'=>false,'field'=>'newEmail','msg'=>'New email address is required.']); exit; }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))
            { echo json_encode(['ok'=>false,'field'=>'newEmail','msg'=>'Enter a valid email address.']); exit; }
        if (strlen($newEmail) > 120)
            { echo json_encode(['ok'=>false,'field'=>'newEmail','msg'=>'Email address too long (max 120 characters).']); exit; }

        // Must not be same as current
        $st = $db->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
        $st->execute([$userId]);
        $curRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($curRow && strtolower($curRow['email']) === strtolower($newEmail))
            { echo json_encode(['ok'=>false,'field'=>'newEmail','msg'=>'This is already your current email address.']); exit; }

        // Uniqueness check
        $st = $db->prepare('SELECT id FROM users WHERE email=? AND id!=? LIMIT 1');
        $st->execute([$newEmail, $userId]);
        if ($st->fetch())
            { echo json_encode(['ok'=>false,'field'=>'newEmail','msg'=>'This email is already in use by another account.']); exit; }

        try {
            $db->prepare('UPDATE users SET email=?, updated_at=NOW() WHERE id=?')->execute([$newEmail, $userId]);
        } catch (Exception $e) {
            echo json_encode(['ok'=>false,'msg'=>'Database error: '.$e->getMessage()]); exit;
        }
        echo json_encode(['ok'=>true,'msg'=>'Email updated successfully.','new_email'=>$newEmail]);
        exit;
    }

    // ── Avatar color ───────────────────────────────────────────────────────
    if ($section === 'avatar_color') {
        $color = trim($_POST['color'] ?? '#00d4bb');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color))
            { echo json_encode(['ok'=>false,'msg'=>'Invalid color format.']); exit; }
        $db->prepare('UPDATE users SET avatar_color=?, updated_at=NOW() WHERE id=?')->execute([$color, $userId]);
        echo json_encode(['ok'=>true,'msg'=>'Avatar color updated.','color'=>$color]);
        exit;
    }

    // ── Upload Profile Photo ──────────────────────────────────────────────
    if ($section === 'photo_upload') {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)
            { echo json_encode(['ok'=>false,'msg'=>'No file uploaded or upload error.']); exit; }
        $file = $_FILES['photo'];
        $allowed = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed))
            { echo json_encode(['ok'=>false,'msg'=>'Only JPG, PNG, GIF or WebP images allowed.']); exit; }
        if ($file['size'] > 2 * 1024 * 1024)
            { echo json_encode(['ok'=>false,'msg'=>'Image must be under 2 MB.']); exit; }
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dir  = __DIR__ . '/../uploads/profile_photos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'user_' . $userId . '_' . time() . '.' . $ext;
        $dest  = $dir . $fname;
        if (!move_uploaded_file($file['tmp_name'], $dest))
            { echo json_encode(['ok'=>false,'msg'=>'Could not save the file. Please try again.']); exit; }
        // Delete old photo
        $stOld = $db->prepare('SELECT avatar_path FROM users WHERE id=? LIMIT 1');
        $stOld->execute([$userId]);
        $oldRow = $stOld->fetch(PDO::FETCH_ASSOC);
        if ($oldRow && $oldRow['avatar_path']) {
            $oldFile = __DIR__ . '/../' . $oldRow['avatar_path'];
            if (file_exists($oldFile)) @unlink($oldFile);
        }
        $relPath = 'uploads/profile_photos/' . $fname;
        $db->prepare('UPDATE users SET avatar_path=?, updated_at=NOW() WHERE id=?')->execute([$relPath, $userId]);
        $url = '../' . $relPath . '?v=' . time();
        echo json_encode(['ok'=>true,'msg'=>'Profile photo updated successfully.','url'=>$url]);
        exit;
    }

    // ── Remove Profile Photo ───────────────────────────────────────────────
    if ($section === 'remove_photo') {
        $stOld = $db->prepare('SELECT avatar_path FROM users WHERE id=? LIMIT 1');
        $stOld->execute([$userId]);
        $oldRow = $stOld->fetch(PDO::FETCH_ASSOC);
        if ($oldRow && $oldRow['avatar_path']) {
            $oldFile = __DIR__ . '/../' . $oldRow['avatar_path'];
            if (file_exists($oldFile)) @unlink($oldFile);
        }
        $db->prepare('UPDATE users SET avatar_path=NULL, updated_at=NOW() WHERE id=?')->execute([$userId]);
        echo json_encode(['ok'=>true,'msg'=>'Profile photo removed.']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown section.']); exit;
}

// ── Load assignment summary ────────────────────────────────────────────────
$assignments = [];
if ($db && $userId) {
    $st = $db->prepare('SELECT fa.*, d.name dept_name, d.code dept_code, c.name course_name, c.code course_code, col.name college_name
                        FROM faculty_assignments fa
                        JOIN departments d  ON d.id  = fa.department_id
                        JOIN courses     c  ON c.id  = fa.course_id
                        JOIN colleges    col ON col.id = fa.college_id
                        WHERE fa.faculty_id=? AND fa.status="active"
                        ORDER BY fa.is_primary DESC, d.name');
    $st->execute([$userId]);
    $assignments = $st->fetchAll(PDO::FETCH_ASSOC);
}

$collegeName = $pf['college_name'] ?: 'Your College';
$deptName    = $pf['dept_name']    ?: 'Your Department';
$deptCode    = $pf['dept_code']    ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — <?= h($pf['full_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ──────────────────────────────────────────────────────────*/
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === DASHBOARD PALETTE (teal/amber/light) === */
  --teal:#0F766E;--teal-dark:#0D5C56;
  --teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --teal3:rgba(20,184,166,.10);--teal4:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --amber-soft:rgba(245,158,11,.12);
  --page-bg:#F8FAFC;
  --success:#16A34A;--green:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;--text:#0F172A;--muted:#475569;
  --border:rgba(15,118,110,.10);--border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;--card-hover:#F0FDFA;
  --sidebar-w:264px;--sb-collapsed-w:72px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  --sb-text:rgba(255,255,255,.78);--sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);--sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);--sb-active-border:rgba(245,158,11,.38);
}
html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* ── BG ──────────────────────────────────────────────────────────────────────*/
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,52px 52px,52px 52px;
}

/* ── Shell ───────────────────────────────────────────────────────────────────*/
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── Sidebar ─────────────────────────────────────────────────────────────────*/
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease,width .3s ease;overflow:hidden;flex-shrink:0;
}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);pointer-events:none}
.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12)}
.logo-mark{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#F59E0B,#D97706);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;color:#0D5C56;flex-shrink:0;box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15)}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}
.scope-chip{margin:10px 12px 0;background:linear-gradient(135deg,rgba(20,184,166,.13) 0%,rgba(15,118,110,.09) 100%);border:1px solid rgba(20,184,166,.32);border-radius:12px;padding:11px 13px 12px;box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 4px 16px rgba(0,0,0,.12);position:relative;overflow:hidden;flex-shrink:0}
.scope-chip::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent)}
.sc-label{font-size:.52rem;color:rgba(94,234,212,.90);text-transform:uppercase;letter-spacing:.16em;font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.sc-label i{font-size:.54rem}
.sc-college{font-size:.86rem;font-weight:800;color:#FFFFFF;line-height:1.25;margin-bottom:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;letter-spacing:.01em}
.sc-depts{display:flex;flex-direction:column;gap:4px;margin-top:0}
.sc-dept-tag{font-size:.72rem;color:rgba(186,230,253,.90);display:inline-flex;align-items:center;gap:5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.sc-dept-tag i{font-size:.62rem;flex-shrink:0;color:rgba(94,234,212,.80)}
.sidebar-nav{flex:1;padding:12px 10px 20px;overflow-y:auto}
.sidebar-nav::-webkit-scrollbar{width:3px}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}

/* ── Collapse button ── */
.sb-collapse-btn{margin-left:auto;flex-shrink:0;width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.55);cursor:pointer;display:grid;place-items:center;font-size:.68rem;transition:all .2s}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber-acc)}

/* ── Collapsed state ── */
.sidebar.collapsed{width:var(--sb-collapsed-w)}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0}
.sidebar.collapsed .logo-text{display:none !important}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important}
.sidebar.collapsed .nav-item.active,.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12)!important;border-color:rgba(255,255,255,.15)!important;box-shadow:none!important;color:#ffffff!important}
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i{color:#ffffff!important;opacity:1!important;visibility:visible!important;-webkit-text-fill-color:#ffffff!important}
.sidebar.collapsed .scope-chip,.sidebar.collapsed .scope-chip *,.sidebar.collapsed .scope-chip::before{display:none!important;height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:none!important;overflow:hidden!important;visibility:hidden!important;opacity:0!important}
.sidebar.collapsed .nav-label{display:none!important;height:0!important;margin:0!important;padding:0!important;overflow:hidden!important}
.sidebar.collapsed .sidebar-nav{padding:6px 8px 16px;margin-top:46px}
.sidebar.collapsed .nav-item{display:flex!important;justify-content:center!important;align-items:center!important;padding:10px 0!important;gap:0!important;width:100%;overflow:hidden;border-radius:10px}
.sidebar.collapsed .nav-item i{width:20px;text-align:center;font-size:.9rem;flex-shrink:0;margin:0;padding:0}
.sidebar.collapsed .nav-text,.sidebar.collapsed .nav-badge{display:none!important;width:0!important;height:0!important;overflow:hidden!important;padding:0!important;margin:0!important}
.sidebar.collapsed .sidebar-user{padding:10px 0;justify-content:center;gap:0}
.sidebar.collapsed .user-info,.sidebar.collapsed .logout-btn{display:none!important;width:0!important;overflow:hidden!important}
.sidebar.collapsed .user-avatar{margin:0 auto;flex-shrink:0}
.sidebar.collapsed .nav-item::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#0F172A;color:#fff;font-size:.71rem;font-weight:500;font-family:var(--font);padding:5px 11px;border-radius:7px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999;box-shadow:0 4px 18px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08)}
.sidebar.collapsed .nav-item:hover::after{opacity:1}
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;color:#0D5C56;box-shadow:0 2px 10px rgba(245,158,11,.28)}
.user-info{flex:1;min-width:0}
.user-name{font-size:.8rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.63rem;color:var(--teal-light)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}

/* Mobile overlay */
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}

/* ── Main ────────────────────────────────────────────────────────────────────*/
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease}
.sidebar.collapsed ~ .main,.shell:has(.sidebar.collapsed) .main{margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w))}
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:14px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none;width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);align-items:center;justify-content:center;cursor:pointer;font-size:.82rem;flex-shrink:0}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:rgba(255,255,255,.52);font-weight:400;font-size:.8rem;margin-left:8px}
.topbar-actions{display:flex;align-items:center;gap:10px}
.date-chip{font-size:.72rem;color:rgba(255,255,255,.72);background:rgba(255,255,255,.10);border:1px solid rgba(245,158,11,.20);padding:5px 12px;border-radius:7px;font-family:var(--mono)}

/* Avatar dropdown (topbar) */
.topbar-avatar-wrap{position:relative}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;cursor:pointer;border:2px solid rgba(245,158,11,.30);transition:border-color .2s,box-shadow .2s;user-select:none;flex-shrink:0}
.topbar-avatar:hover,.topbar-avatar.open{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{position:absolute;top:calc(100% + 10px);right:0;min-width:200px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);z-index:200;opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;color:var(--muted);font-size:.8rem;font-weight:500;text-decoration:none;transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:#94A3B8;flex-shrink:0}
.ad-item:hover{background:var(--card-hover);color:var(--text)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}

.content{padding:24px 28px;flex:1}

/* ── Animations ──────────────────────────────────────────────────────────────*/
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}

/* ── Profile Layout ──────────────────────────────────────────────────────────*/
.profile-layout{display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start}
.profile-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:28px 24px;text-align:center;box-shadow:0 1px 8px rgba(15,118,110,.06);
  animation:fadeUp .4s ease both;position:sticky;top:80px}
.avatar-ring{position:relative;width:96px;height:96px;border-radius:50%;margin:0 auto 16px;
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;font-weight:800;color:#fff;
  box-shadow:0 0 0 4px rgba(20,184,166,.22),0 8px 32px rgba(15,118,110,.18)}
.avatar-edit-btn{position:absolute;bottom:0;right:0;width:28px;height:28px;border-radius:50%;
  background:var(--teal);border:2px solid #fff;color:#fff;
  display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.65rem;transition:all .2s}
.avatar-edit-btn:hover{transform:scale(1.1)}
.profile-name{font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:4px}
.profile-role{font-size:.75rem;color:var(--teal);font-weight:600;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.profile-dept{font-size:.78rem;color:var(--muted);margin-bottom:16px}
.profile-meta{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
.meta-row{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--text);text-align:left}
.meta-row i{color:var(--teal);width:14px;flex-shrink:0;font-size:.72rem}
.profile-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px}
.pstat{background:var(--teal-soft);border:1px solid var(--border);border-radius:9px;padding:10px 8px;text-align:center}
.pstat-val{font-size:1.2rem;font-weight:800;color:var(--text)}
.pstat-label{font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.color-swatches{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:12px}
.swatch{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:all .2s}
.swatch:hover,.swatch.active{border-color:var(--teal);transform:scale(1.15);box-shadow:0 0 0 2px rgba(20,184,166,.30)}

/* ── Settings Sections ───────────────────────────────────────────────────────*/
.settings-col{display:flex;flex-direction:column;gap:20px}
.settings-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:24px;box-shadow:0 1px 8px rgba(15,118,110,.05);animation:fadeUp .4s ease both}
.settings-card:nth-child(1){animation-delay:.05s}
.settings-card:nth-child(2){animation-delay:.10s}
.settings-card:nth-child(3){animation-delay:.15s}
.settings-card:nth-child(4){animation-delay:.20s}
.settings-card:nth-child(5){animation-delay:.25s}
.card-heading{display:flex;align-items:center;gap:10px;margin-bottom:20px}
.card-heading-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.card-heading-icon.teal{background:var(--teal-soft);color:var(--teal)}
.card-heading-icon.amber{background:var(--amber-soft);color:var(--amber-acc)}
.card-heading-icon.green{background:var(--green2);color:var(--success)}
.card-heading-icon.purple{background:var(--purple2);color:var(--purple)}
.card-heading-icon.blue{background:var(--blue2);color:var(--blue)}
.card-heading-icon.red{background:var(--red2);color:var(--red)}
.card-heading h3{font-size:.95rem;font-weight:700;color:var(--text)}
.card-heading p{font-size:.72rem;color:var(--muted);margin-top:1px}

/* ── Form ────────────────────────────────────────────────────────────────────*/
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.three{grid-template-columns:1fr 1fr 1fr}
.form-grid.full{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.span2{grid-column:span 2}
.form-label{font-size:.7rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}
.form-input,.form-select,.form-textarea{
  background:#fff;border:1px solid var(--border);color:var(--text);
  font-size:.85rem;padding:9px 12px;border-radius:9px;outline:none;
  transition:border-color .2s,background .2s,box-shadow .2s;font-family:var(--font);width:100%}
.form-input:focus,.form-select:focus,.form-textarea:focus{
  border-color:var(--teal-light);background:#fff;box-shadow:0 0 0 3px rgba(20,184,166,.08)}
.form-input::placeholder,.form-textarea::placeholder{color:#94A3B8}
.form-select option{background:#fff;color:var(--text)}
.form-textarea{resize:vertical;min-height:80px}
.input-with-icon{position:relative}
.input-with-icon i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94A3B8;font-size:.75rem;pointer-events:none}
.input-with-icon i.pwd-eye{left:auto;right:10px;pointer-events:none;z-index:1}
.pwd-toggle-btn{pointer-events:all !important;position:absolute;right:0;top:0;height:100%;width:36px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.8rem;z-index:3;padding:0;display:flex;align-items:center;justify-content:center}
/* Fix browser autofill on light theme */
input:-webkit-autofill,input:-webkit-autofill:hover,input:-webkit-autofill:focus,
input:-webkit-autofill:active{
  -webkit-box-shadow:0 0 0 100px #fff inset !important;
  -webkit-text-fill-color:var(--text) !important;
  caret-color:var(--text);
  border-color:var(--teal-light) !important;
  transition:background-color 5000s ease-in-out 0s;
}
.input-with-icon .form-input{padding-left:32px}
.char-hint{font-size:.65rem;color:var(--muted);text-align:right;margin-top:2px}
.form-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border)}

/* ── Buttons ─────────────────────────────────────────────────────────────────*/
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:9px;
  font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;
  text-decoration:none;font-family:var(--font)}
.btn-primary{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 4px 16px rgba(15,118,110,.28)}
.btn-primary:disabled{opacity:.6;cursor:not-allowed;transform:none;filter:none}
.btn-outline{background:var(--teal-soft);color:var(--teal);border:1px solid rgba(15,118,110,.25)}
.btn-outline:hover{border-color:var(--border-accent);color:var(--teal);background:rgba(20,184,166,.18)}
.btn-danger{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.2)}
.btn-danger:hover{background:rgba(220,38,38,.2)}
.btn-sm{padding:6px 14px;font-size:.76rem}

/* ── Alerts ──────────────────────────────────────────────────────────────────*/
.alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:9px;font-size:.82rem;margin-bottom:16px}
.alert i{margin-top:1px;flex-shrink:0}
.alert-success{background:var(--green2);border:1px solid rgba(22,163,74,.2);color:var(--success)}
.alert-error{background:var(--red2);border:1px solid rgba(220,38,38,.2);color:var(--red)}
.alert-info{background:var(--teal-soft);border:1px solid rgba(20,184,166,.2);color:var(--teal)}

/* ── Assignments table ───────────────────────────────────────────────────────*/
.assign-table{width:100%;border-collapse:collapse}
.assign-table th{font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;
  padding:8px 12px;border-bottom:1px solid var(--border);text-align:left;font-weight:700;background:#F8FAFC}
.assign-table td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:.82rem;color:var(--text);vertical-align:middle}
.assign-table tr:last-child td{border-bottom:none}
.assign-table tbody tr:hover td{background:#F0FDFA}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;
  padding:3px 9px;border-radius:20px;white-space:nowrap}
.badge-teal{background:var(--teal-soft);color:var(--teal);border:1px solid rgba(20,184,166,.20)}
.badge-amber{background:var(--amber-soft);color:var(--amber-acc)}
.badge-green{background:var(--green2);color:var(--success)}

/* ── Password strength ───────────────────────────────────────────────────────*/
.strength-bar{height:4px;border-radius:2px;background:rgba(15,118,110,.10);overflow:hidden;margin-top:6px}
.strength-fill{height:100%;border-radius:2px;transition:width .4s,background .4s}
.strength-label{font-size:.65rem;margin-top:4px}

/* ── Toast ───────────────────────────────────────────────────────────────────*/
#toast{position:fixed;bottom:28px;right:28px;z-index:999;
  background:#fff;border:1px solid var(--border-accent);color:var(--teal);
  font-size:.84rem;font-weight:600;padding:12px 20px;border-radius:12px;
  box-shadow:0 8px 30px rgba(15,118,110,.12);display:flex;align-items:center;gap:10px;
  transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none;max-width:340px}

/* ── Field Validation States ──────────────────────────────────────────────────*/
.form-input.is-invalid,.form-select.is-invalid,.form-textarea.is-invalid{
  border-color:var(--red)!important;background:rgba(220,38,38,.03)!important}
.form-input.is-valid,.form-select.is-valid,.form-textarea.is-valid{
  border-color:var(--success)!important;background:rgba(22,163,74,.03)!important}
.field-error{font-size:.68rem;color:var(--red);margin-top:3px;display:flex;align-items:center;gap:4px;animation:fadeUp .2s ease both}
.field-error i{font-size:.6rem;flex-shrink:0}
.field-hint{font-size:.68rem;color:var(--muted);margin-top:3px}
.input-with-icon.is-invalid i:first-child{color:var(--red)}
.input-with-icon.is-valid i:first-child{color:var(--success)}
.char-warn{color:var(--amber)!important}
.char-over{color:var(--red)!important}

.saving-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--amber-acc);margin-left:6px;animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

/* ── Scrollbar ───────────────────────────────────────────────────────────────*/
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Responsive ──────────────────────────────────────────────────────────────*/
@media(max-width:1100px){.profile-layout{grid-template-columns:1fr}.profile-card{position:static}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}.content{padding:16px}
  .hamburger{display:flex}
}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}.form-grid.three{grid-template-columns:1fr}.form-group.span2{grid-column:span 1}}
@media(max-width:480px){.content{padding:12px}.profile-card{padding:20px 16px}}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="shell">

  <!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">PE</div>
      <div class="logo-text">PEPA <span>ERP Platform</span></div>
      <button class="sb-collapse-btn" id="sidebarCollapseBtn" onclick="collapseSidebar()" title="Collapse sidebar">
        <i class="fas fa-angles-left" id="collapseIcon"></i>
      </button>
    </div>
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= h($collegeName) ?></div>
      <div class="sc-depts">
        <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= h($deptName) ?></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard">
        <i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span>
      </a>
      <a href="students.php" class="nav-item" data-tip="Students">
        <i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span>
      </a>

      <div class="nav-label">Academic</div>
      
      <a href="attendance.php" class="nav-item" data-tip="Attendance">
        <i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span>
      </a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results">
        <i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
      </a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations">
        <i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
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
      <a href="profile.php" class="nav-item active" data-tip="My Profile">
        <i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span>
      </a>
    </nav>
    <div class="sidebar-user">
      <div class="user-avatar" id="sidebarAvatar" style="background:<?= h($pf['avatar_color']) ?>"><?= h(initials($pf['full_name'])) ?></div>
      <div class="user-info">
        <div class="user-name" id="sidebarName"><?= h($pf['full_name']) ?></div>
        <div class="user-role-tag"><?= ucfirst(str_replace('_',' ',$role)) ?> · <?= h($deptCode ?: 'Admin') ?></div>
      </div>
      <button class="logout-btn" title="Logout" onclick="doLogout()">
        <i class="fas fa-arrow-right-from-bracket"></i>
      </button>
    </div>
  </aside>

  <!-- ── Main ─────────────────────────────────────────────────────────────── -->
  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="menuToggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <div class="topbar-title">My Profile <span>/ <?= h($pf['full_name']) ?></span></div>
      <div class="topbar-actions">
        <div class="date-chip" id="topbarDate"></div>
        <a href="dashboard.php" style="width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);display:grid;place-items:center;cursor:pointer;font-size:.82rem;text-decoration:none;transition:all .18s" title="Dashboard"><i class="fas fa-grid-2"></i></a>
        <!-- Avatar dropdown -->
        <div class="topbar-avatar-wrap">
          <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= h($pf['full_name']) ?>"
               style="<?= !empty($pf['avatar_path']) && file_exists(__DIR__.'/../'.$pf['avatar_path']) ? 'background:none;padding:0;overflow:hidden' : 'background:'.h($pf['avatar_color']) ?>">
            <?php if (!empty($pf['avatar_path']) && file_exists(__DIR__.'/../'.$pf['avatar_path'])): ?>
              <img src="../<?= h($pf['avatar_path']) ?>?v=<?= time() ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
            <?php else: ?>
              <?= h(initials($pf['full_name'])) ?>
            <?php endif; ?>
          </div>
          <div class="avatar-dropdown" id="avatarDropdown">
            <div class="ad-header">
              <div class="ad-name"><?= h($pf['full_name']) ?></div>
              <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= h(ucfirst(str_replace('_',' ',$role))) ?></div>
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

      <div class="profile-layout">

        <!-- ── Profile Card (left sticky) ─────────────────────────────── -->
        <div class="profile-card">
          <!-- Avatar -->
          <div class="avatar-ring" id="avatarRing" style="background:<?= h($pf['avatar_color']) ?>">
            <?php if (!empty($pf['avatar_path']) && file_exists(__DIR__ . '/../' . $pf['avatar_path'])): ?>
            <img src="../<?= h($pf['avatar_path']) ?>?v=<?= time() ?>" id="avatarPhoto"
                 style="width:100%;height:100%;object-fit:cover;border-radius:50%;position:absolute;inset:0" alt="Profile Photo">
            <span id="avatarInitials" style="display:none"><?= h(initials($pf['full_name'])) ?></span>
            <?php else: ?>
            <img src="" id="avatarPhoto" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;position:absolute;inset:0" alt="">
            <span id="avatarInitials"><?= h(initials($pf['full_name'])) ?></span>
            <?php endif; ?>
            <div class="avatar-edit-btn" onclick="document.getElementById('photoSection').scrollIntoView({behavior:'smooth'})" title="Change photo">
              <i class="fas fa-camera"></i>
            </div>
          </div>

          <div class="profile-name" id="cardName"><?= h($pf['full_name']) ?></div>
          <div class="profile-role"><?= ucfirst(str_replace('_',' ',$role)) ?></div>
          <div class="profile-dept"><?= h($pf['designation'] ?: 'Faculty') ?> · <?= h($deptName) ?></div>

          <div class="profile-meta">
            <?php if ($pf['email']): ?>
            <div class="meta-row"><i class="fas fa-envelope"></i><span id="cardEmail"><?= h($pf['email']) ?></span></div>
            <?php endif; ?>
            <?php if ($pf['phone']): ?>
            <div class="meta-row"><i class="fas fa-phone"></i><?= h($pf['phone']) ?></div>
            <?php endif; ?>
            <?php if ($pf['college_name']): ?>
            <div class="meta-row"><i class="fas fa-building-columns"></i><?= h($pf['college_name']) ?></div>
            <?php endif; ?>
            <?php if ($pf['username']): ?>
            <div class="meta-row"><i class="fas fa-at"></i><span style="font-family:var(--mono);font-size:.72rem"><?= h($pf['username']) ?></span></div>
            <?php endif; ?>
            <?php if ($pf['experience_years']): ?>
            <div class="meta-row"><i class="fas fa-briefcase"></i><?= h($pf['experience_years']) ?> yrs experience</div>
            <?php endif; ?>
            <?php if ($pf['blood_group']): ?>
            <div class="meta-row"><i class="fas fa-droplet" style="color:var(--red)"></i><?= h($pf['blood_group']) ?></div>
            <?php endif; ?>
          </div>

          <div class="profile-stats">
            <div class="pstat">
              <div class="pstat-val"><?= count($assignments) ?></div>
              <div class="pstat-label">Courses</div>
            </div>
            <div class="pstat">
              <div class="pstat-val"><?= $pf['experience_years'] ?: '—' ?></div>
              <div class="pstat-label">Exp. Yrs</div>
            </div>
          </div>

          <?php if ($pf['bio']): ?>
          <div style="font-size:.76rem;color:var(--muted);text-align:left;line-height:1.55;padding:10px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid var(--border)">
            <?= h($pf['bio']) ?>
          </div>
          <?php endif; ?>

          <!-- Color swatches -->
          <div id="colorSection" style="margin-top:16px">
            <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Avatar Color</div>
            <div class="color-swatches">
              <?php
              $swatches = ['#00d4bb','#3b82f6','#8b5cf6','#ef4444','#f59e0b','#10b981','#f97316','#ec4899','#6366f1','#14b8a6'];
              foreach ($swatches as $sw):
              ?>
              <div class="swatch <?= $pf['avatar_color']===$sw?'active':'' ?>" style="background:<?= h($sw) ?>"
                   onclick="pickColor('<?= h($sw) ?>')" title="<?= h($sw) ?>"></div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;align-items:center;margin-top:10px">
              <input type="color" id="customColorPicker" value="<?= h($pf['avatar_color']) ?>"
                style="width:32px;height:32px;border-radius:6px;border:1px solid var(--border);background:none;cursor:pointer;padding:2px"
                onchange="pickColor(this.value)">
              <span style="font-size:.7rem;color:var(--muted)">Custom color</span>
            </div>
          </div>

          <!-- Photo Upload -->
          <div id="photoSection" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
            <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">
              <i class="fas fa-camera" style="margin-right:4px"></i>Profile Photo
            </div>
            <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadPhoto(this)">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-outline btn-sm" style="font-size:.72rem;padding:6px 12px" onclick="document.getElementById('photoFileInput').click()">
                <i class="fas fa-upload"></i> Upload Photo
              </button>
              <?php if (!empty($pf['avatar_path'])): ?>
              <button class="btn btn-sm" id="removePhotoBtn"
                style="font-size:.72rem;padding:6px 12px;background:var(--red2);color:var(--red);border:1px solid var(--red);border-radius:var(--radius)"
                onclick="removePhoto()">
                <i class="fas fa-trash"></i> Remove
              </button>
              <?php endif; ?>
            </div>
            <div style="font-size:.65rem;color:var(--muted);margin-top:6px">JPG/PNG/GIF/WebP · Max 2 MB</div>
            <div id="photoMsg" style="font-size:.7rem;margin-top:6px;display:none"></div>
          </div>

          <!-- Last updated -->
          <?php if (!empty($profileUser['updated_at'])): ?>
          <div style="font-size:.65rem;color:var(--muted);margin-top:16px">
            Last updated: <?= date('d M Y, h:i A', strtotime($profileUser['updated_at'])) ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- ── Settings Column (right) ────────────────────────────── -->
        <div class="settings-col">

          <!-- ── 1. Personal Information ──────────────────────────── -->
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon teal"><i class="fas fa-user"></i></div>
              <div>
                <h3>Personal Information</h3>
                <p>Update your name, phone number and designation</p>
              </div>
            </div>

            <div id="personalAlert"></div>

            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Full Name <span style="color:var(--red)">*</span></label>
                <div class="input-with-icon" id="wrap_pFullName">
                  <i class="fas fa-user"></i>
                  <input type="text" id="pFullName" class="form-input" value="<?= h($pf['full_name']) ?>" placeholder="Dr. Firstname Lastname" maxlength="150"
                         oninput="liveValidate(this,'name')" onblur="liveValidate(this,'name')">
                </div>
                <div class="field-hint">Letters, spaces, dots and hyphens only. 3–150 characters.</div>
                <div class="field-error" id="err_pFullName" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Phone Number</label>
                <div style="display:flex;gap:0" id="wrap_pPhone">
                  <select id="pPhoneCode" class="form-select" style="width:130px;border-radius:var(--radius) 0 0 var(--radius);border-right:none;flex-shrink:0;font-size:.78rem">
                    <?php
                    $dialCodes = [
                      ['IN','+91','India'],['US','+1','USA'],['GB','+44','UK'],['AU','+61','Australia'],
                      ['CA','+1','Canada'],['AE','+971','UAE'],['SG','+65','Singapore'],['NZ','+64','New Zealand'],
                      ['DE','+49','Germany'],['FR','+33','France'],['JP','+81','Japan'],['CN','+86','China'],
                      ['BR','+55','Brazil'],['ZA','+27','South Africa'],['NG','+234','Nigeria'],
                      ['PK','+92','Pakistan'],['BD','+880','Bangladesh'],['LK','+94','Sri Lanka'],
                      ['NP','+977','Nepal'],['MY','+60','Malaysia'],['PH','+63','Philippines'],
                      ['ID','+62','Indonesia'],['TH','+66','Thailand'],['KR','+82','South Korea'],
                      ['SA','+966','Saudi Arabia'],['QA','+974','Qatar'],['KW','+965','Kuwait'],
                      ['OM','+968','Oman'],['BH','+973','Bahrain'],['EG','+20','Egypt'],
                      ['KE','+254','Kenya'],['GH','+233','Ghana'],['ET','+251','Ethiopia'],
                      ['IT','+39','Italy'],['ES','+34','Spain'],['NL','+31','Netherlands'],
                      ['SE','+46','Sweden'],['NO','+47','Norway'],['DK','+45','Denmark'],
                      ['FI','+358','Finland'],['CH','+41','Switzerland'],['AT','+43','Austria'],
                      ['RU','+7','Russia'],['UA','+380','Ukraine'],['PL','+48','Poland'],
                      ['TR','+90','Turkey'],['IR','+98','Iran'],['IQ','+964','Iraq'],
                      ['MX','+52','Mexico'],['AR','+54','Argentina'],['CO','+57','Colombia'],
                      ['CL','+56','Chile'],['PE','+51','Peru'],['VE','+58','Venezuela'],
                    ];
                    $savedPhone = $pf['phone'] ?? '';
                    // Parse saved phone to extract dial code
                    $savedCode = '+91'; $savedNum = $savedPhone;
                    foreach ($dialCodes as $dc) {
                        if (strpos($savedPhone, $dc[1]) === 0) {
                            $savedCode = $dc[1];
                            $savedNum  = ltrim(substr($savedPhone, strlen($dc[1])), ' ');
                            break;
                        }
                    }
                    foreach ($dialCodes as $dc):
                    ?>
                    <option value="<?= h($dc[1]) ?>" <?= $savedCode===$dc[1]?'selected':'' ?>><?= h($dc[0]) ?> <?= h($dc[1]) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="tel" id="pPhone" class="form-input"
                         style="border-radius:0 var(--radius) var(--radius) 0"
                         value="<?= h($savedNum) ?>"
                         placeholder="10-digit number" maxlength="10" minlength="10"
                         oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                         onblur="validatePhone10(this)">
                </div>
                <div class="field-hint">Select country code then enter 10-digit number.</div>
                <div class="field-error" id="err_pPhone" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Designation</label>
                <div class="input-with-icon" id="wrap_pDesignation">
                  <i class="fas fa-id-badge"></i>
                  <input type="text" id="pDesignation" class="form-input" value="<?= h($pf['designation']) ?>" placeholder="e.g. Assistant Professor" maxlength="80"
                         oninput="liveValidate(this,'text80')" onblur="liveValidate(this,'text80')">
                </div>
                <div class="field-hint">Your job title. Max 80 characters.</div>
                <div class="field-error" id="err_pDesignation" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Department Label</label>
                <div class="input-with-icon" id="wrap_pFacDept">
                  <i class="fas fa-sitemap"></i>
                  <input type="text" id="pFacDept" class="form-input" value="<?= h($pf['faculty_department']) ?>" placeholder="e.g. CSE, MBA" maxlength="120"
                         oninput="liveValidate(this,'text120')" onblur="liveValidate(this,'text120')">
                </div>
                <div class="field-hint">Short label for your department. Max 120 characters.</div>
                <div class="field-error" id="err_pFacDept" style="display:none"></div>
              </div>
            </div>
            <div class="form-footer">
              <button class="btn btn-outline btn-sm" onclick="resetPersonal()"><i class="fas fa-rotate-left"></i> Reset</button>
              <button class="btn btn-primary btn-sm" id="savePersonalBtn" onclick="saveSection('personal')">
                <i class="fas fa-floppy-disk"></i> Save Changes
              </button>
            </div>
          </div>

          <!-- ── 2. Personal Details ──────────────────────────────── -->
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon blue"><i class="fas fa-id-card"></i></div>
              <div>
                <h3>Personal Details</h3>
                <p>Date of birth, gender and blood group</p>
              </div>
            </div>

            <div id="personalDetailAlert"></div>

            <div class="form-grid three">
              <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" id="pdDob" class="form-input" value="<?= h($pf['dob']) ?>"
                       max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                       onchange="liveValidate(this,'dob')" onblur="liveValidate(this,'dob')">
                <div class="field-hint">Must be 18+ years old.</div>
                <div class="field-error" id="err_pdDob" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Gender</label>
                <select id="pdGender" class="form-select" onchange="liveValidate(this,'select')">
                  <option value="">— Select —</option>
                  <?php foreach(['Male','Female','Other'] as $g): ?>
                  <option value="<?= $g ?>" <?= $pf['gender']===$g?'selected':'' ?>><?= $g ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="field-error" id="err_pdGender" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Blood Group</label>
                <select id="pdBlood" class="form-select" onchange="liveValidate(this,'select')">
                  <option value="">— Select —</option>
                  <?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                  <option value="<?= $bg ?>" <?= $pf['blood_group']===$bg?'selected':'' ?>><?= $bg ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="field-error" id="err_pdBlood" style="display:none"></div>
              </div>
            </div>
            <div class="form-footer">
              <button class="btn btn-primary btn-sm" id="savePersonalDetailBtn" onclick="saveSection('personal_detail')">
                <i class="fas fa-floppy-disk"></i> Save
              </button>
            </div>
          </div>

          <!-- ── 3. Academic & Professional ──────────────────────── -->
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon purple"><i class="fas fa-graduation-cap"></i></div>
              <div>
                <h3>Academic &amp; Professional</h3>
                <p>Qualifications, specialization, experience and bio</p>
              </div>
            </div>

            <div id="academicAlert"></div>

            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Highest Qualification</label>
                <div class="input-with-icon" id="wrap_aQual">
                  <i class="fas fa-certificate"></i>
                  <input type="text" id="aQual" class="form-input" value="<?= h($pf['qualification']) ?>" placeholder="e.g. Ph.D. Computer Science" maxlength="200"
                         oninput="liveValidate(this,'text200')" onblur="liveValidate(this,'text200')">
                </div>
                <div class="field-hint">Max 200 characters.</div>
                <div class="field-error" id="err_aQual" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Experience (Years)</label>
                <div class="input-with-icon" id="wrap_aExp">
                  <i class="fas fa-briefcase"></i>
                  <input type="number" id="aExp" class="form-input" value="<?= h($pf['experience_years']) ?>" placeholder="0" min="0" max="60"
                         oninput="liveValidate(this,'expYears')" onblur="liveValidate(this,'expYears')">
                </div>
                <div class="field-hint">Enter a number between 0 and 60.</div>
                <div class="field-error" id="err_aExp" style="display:none"></div>
              </div>
              <div class="form-group span2">
                <label class="form-label">Specialization</label>
                <div class="input-with-icon" id="wrap_aSpec">
                  <i class="fas fa-flask"></i>
                  <input type="text" id="aSpec" class="form-input" value="<?= h($pf['specialization']) ?>" placeholder="e.g. Machine Learning, DBMS, Algorithms" maxlength="200"
                         oninput="liveValidate(this,'text200')" onblur="liveValidate(this,'text200')">
                </div>
                <div class="field-hint">Max 200 characters.</div>
                <div class="field-error" id="err_aSpec" style="display:none"></div>
              </div>
              <div class="form-group span2">
                <label class="form-label">Research Interests</label>
                <textarea id="aResearch" class="form-textarea" placeholder="Brief description of research areas…" maxlength="1000"
                          oninput="charCount(this,'researchCount',1000)" onblur="liveValidate(this,'text1000')"><?= h($pf['research_interests']) ?></textarea>
                <span class="char-hint" id="researchCount"><?= strlen($pf['research_interests']) ?>/1000</span>
                <div class="field-error" id="err_aResearch" style="display:none"></div>
              </div>
              <div class="form-group span2">
                <label class="form-label">Publications / Achievements</label>
                <textarea id="aPublications" class="form-textarea" placeholder="Notable publications, papers, awards…" maxlength="1000"
                          oninput="charCount(this,'pubCount',1000)"><?= h($pf['publications']) ?></textarea>
                <span class="char-hint" id="pubCount"><?= strlen($pf['publications']) ?>/1000</span>
                <div class="field-error" id="err_aPublications" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">LinkedIn Profile</label>
                <div class="input-with-icon" id="wrap_aLinkedin">
                  <i class="fab fa-linkedin"></i>
                  <input type="url" id="aLinkedin" class="form-input" value="<?= h($pf['linkedin']) ?>" placeholder="https://linkedin.com/in/yourname"
                         oninput="liveValidate(this,'linkedin')" onblur="liveValidate(this,'linkedin')">
                </div>
                <div class="field-hint">Must be a valid linkedin.com URL.</div>
                <div class="field-error" id="err_aLinkedin" style="display:none"></div>
              </div>
              <div class="form-group span2">
                <label class="form-label">Bio / About Me</label>
                <textarea id="aBio" class="form-textarea" placeholder="A short professional bio…" maxlength="500" style="min-height:72px"
                          oninput="charCount(this,'bioCount',500)" onblur="liveValidate(this,'text500')"><?= h($pf['bio']) ?></textarea>
                <span class="char-hint" id="bioCount"><?= strlen($pf['bio']) ?>/500</span>
                <div class="field-error" id="err_aBio" style="display:none"></div>
              </div>
            </div>
            <div class="form-footer">
              <button class="btn btn-primary btn-sm" id="saveAcademicBtn" onclick="saveSection('academic')">
                <i class="fas fa-floppy-disk"></i> Save Academic Info
              </button>
            </div>
          </div>

          <!-- ── 4. Address & Emergency Contact ──────────────────── -->
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon amber"><i class="fas fa-location-dot"></i></div>
              <div>
                <h3>Address &amp; Emergency Contact</h3>
                <p>Residential address and emergency contact details</p>
              </div>
            </div>

            <div id="addressAlert"></div>

            <div class="form-grid">
              <div class="form-group span2">
                <label class="form-label">Street Address</label>
                <textarea id="adAddress" class="form-textarea" style="min-height:60px" placeholder="House no., Street, Area…"
                          oninput="charCount(this,'addrCount',500)" onblur="liveValidate(this,'text500')"><?= h($pf['address']) ?></textarea>
                <span class="char-hint" id="addrCount"><?= strlen($pf['address']) ?>/500</span>
                <div class="field-error" id="err_adAddress" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" id="adCity" class="form-input" value="<?= h($pf['city']) ?>" placeholder="City" maxlength="80"
                       oninput="liveValidate(this,'citystate')" onblur="liveValidate(this,'citystate')">
                <div class="field-hint">Letters only. Max 80 characters.</div>
                <div class="field-error" id="err_adCity" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">State</label>
                <select id="adState" class="form-input" onchange="liveValidate(this,'citystate')" onblur="liveValidate(this,'citystate')" style="appearance:none;-webkit-appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%230F766E' stroke-width='1.5' stroke-linecap='round' fill='none'/%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;cursor:pointer">
                  <option value="">Select State</option>
                  <?php $states=['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Jammu and Kashmir','Ladakh','Lakshadweep','Puducherry']; foreach($states as $st): ?>
                  <option value="<?= h($st) ?>" <?= (($pf['state'] ?? '') === $st) ? 'selected' : '' ?>><?= h($st) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="field-hint">Select your state.</div>
                <div class="field-error" id="err_adState" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">PIN Code</label>
                <input type="text" id="adPincode" class="form-input" value="<?= h($pf['pincode']) ?>" placeholder="e.g. 600001" maxlength="12"
                       oninput="liveValidate(this,'pincode')" onblur="liveValidate(this,'pincode')">
                <div class="field-hint">5–10 digit postal code.</div>
                <div class="field-error" id="err_adPincode" style="display:none"></div>
              </div>
            </div>

            <div style="padding:12px 0 0;margin-top:12px;border-top:1px solid var(--border)">
              <div style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">
                <i class="fas fa-phone-volume" style="color:var(--red);margin-right:6px"></i>Emergency Contact
              </div>
              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label">Contact Name</label>
                  <div class="input-with-icon" id="wrap_adEmgName">
                    <i class="fas fa-user-nurse"></i>
                    <input type="text" id="adEmgName" class="form-input" value="<?= h($pf['emergency_name']) ?>" placeholder="Name of emergency contact" maxlength="120"
                           oninput="liveValidate(this,'name')" onblur="liveValidate(this,'name')">
                  </div>
                  <div class="field-hint">Full name. Letters only.</div>
                  <div class="field-error" id="err_adEmgName" style="display:none"></div>
                </div>
                <div class="form-group">
                  <label class="form-label">Contact Phone <span id="emgPhoneRequired" style="color:var(--red);display:none">*</span></label>
                  <div style="display:flex;gap:0" id="wrap_adEmgPhone">
                    <?php
                    $emgPhone = $pf['emergency_contact'] ?? '';
                    $emgCode = '+91'; $emgNum = $emgPhone;
                    foreach ($dialCodes as $dc) {
                        if (strpos($emgPhone, $dc[1]) === 0) {
                            $emgCode = $dc[1]; $emgNum = ltrim(substr($emgPhone, strlen($dc[1])), ' '); break;
                        }
                    }
                    ?>
                    <select id="adEmgPhoneCode" class="form-select" style="width:130px;border-radius:var(--radius) 0 0 var(--radius);border-right:none;flex-shrink:0;font-size:.78rem">
                      <?php foreach ($dialCodes as $dc): ?>
                      <option value="<?= h($dc[1]) ?>" <?= $emgCode===$dc[1]?'selected':'' ?>><?= h($dc[0]) ?> <?= h($dc[1]) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="tel" id="adEmgPhone" class="form-input"
                           style="border-radius:0 var(--radius) var(--radius) 0"
                           value="<?= h($emgNum) ?>"
                           placeholder="10-digit number" maxlength="10"
                           oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                           onblur="validatePhone10(this)">
                  </div>
                  <div class="field-hint">Required if contact name is entered.</div>
                  <div class="field-error" id="err_adEmgPhone" style="display:none"></div>
                </div>
              </div>
            </div>

            <div class="form-footer">
              <button class="btn btn-primary btn-sm" id="saveAddressBtn" onclick="saveSection('address')">
                <i class="fas fa-floppy-disk"></i> Save Address
              </button>
            </div>
          </div>

          <!-- ── 5. Account Security (Email + Password combined) ── -->
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon green"><i class="fas fa-shield-halved"></i></div>
              <div>
                <h3>Account Security</h3>
                <p>Update your login email address and password</p>
              </div>
            </div>

            <!-- Email sub-section -->
            <div style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">
              <i class="fas fa-envelope" style="color:var(--teal);margin-right:6px"></i>Email Address
            </div>
            <div id="emailAlert"></div>
            <div class="form-grid" style="margin-bottom:16px">
              <div class="form-group">
                <label class="form-label">Current Email</label>
                <div class="input-with-icon">
                  <i class="fas fa-envelope"></i>
                  <input type="text" class="form-input" value="<?= h($pf['email']) ?>" id="currentEmailDisplay" disabled style="opacity:.5">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">New Email <span style="color:var(--red)">*</span></label>
                <div class="input-with-icon" id="wrap_newEmail">
                  <i class="fas fa-envelope-open"></i>
                  <input type="email" id="newEmail" class="form-input" placeholder="New email address" maxlength="120"
                         oninput="liveValidate(this,'email')" onblur="liveValidate(this,'email')">
                </div>
                <div class="field-hint">Must be a valid email. Must not already be in use.</div>
                <div class="field-error" id="err_newEmail" style="display:none"></div>
              </div>
            </div>
            <div class="form-footer" style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)">
              <button class="btn btn-primary btn-sm" id="saveEmailBtn" onclick="saveSection('email')">
                <i class="fas fa-check"></i> Update Email
              </button>
            </div>

            <!-- Password sub-section -->
            <div style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">
              <i class="fas fa-lock" style="color:var(--red);margin-right:6px"></i>Change Password
            </div>
            <div id="passwordAlert"></div>
            <div class="form-grid">
              <div class="form-group span2">
                <label class="form-label">Current Password <span style="color:var(--red)">*</span></label>
                <div class="input-with-icon" id="wrap_currentPwd" style="position:relative">
                  <i class="fas fa-lock"></i>
                  <input type="password" id="currentPwd" class="form-input" placeholder="Your current password" autocomplete="current-password"
                         style="padding-right:38px"
                         oninput="clearFieldErr('currentPwd')" onblur="liveValidate(this,'required')">
                  <button type="button" onclick="togglePwd('currentPwd','eyeIcon0')" class="pwd-toggle-btn">
                    <i class="fas fa-eye pwd-eye" id="eyeIcon0"></i>
                  </button>
                </div>
                <div class="field-error" id="err_currentPwd" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">New Password <span style="color:var(--red)">*</span></label>
                <div class="input-with-icon" id="wrap_newPwd" style="position:relative">
                  <i class="fas fa-key"></i>
                  <input type="password" id="newPwd" class="form-input" placeholder="Min 8 chars, 1 uppercase, 1 number" autocomplete="new-password"
                         style="padding-right:38px"
                         oninput="checkStrength(this.value);liveValidate(this,'password')" onblur="liveValidate(this,'password')">
                  <button type="button" onclick="togglePwd('newPwd','eyeIcon1')" class="pwd-toggle-btn">
                    <i class="fas fa-eye pwd-eye" id="eyeIcon1"></i>
                  </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill" style="width:0%"></div></div>
                <div class="strength-label" id="strengthLabel" style="color:var(--muted)"></div>
                <div class="field-hint">Min 8 characters, at least 1 uppercase letter and 1 number.</div>
                <div class="field-error" id="err_newPwd" style="display:none"></div>
              </div>
              <div class="form-group">
                <label class="form-label">Confirm New Password <span style="color:var(--red)">*</span></label>
                <div class="input-with-icon" id="wrap_confirmPwd">
                  <i class="fas fa-key"></i>
                  <input type="password" id="confirmPwd" class="form-input" placeholder="Repeat new password" autocomplete="new-password"
                         oninput="liveValidate(this,'confirm')" onblur="liveValidate(this,'confirm')">
                </div>
                <div class="field-error" id="err_confirmPwd" style="display:none"></div>
              </div>
            </div>
            <div class="form-footer">
              <button class="btn btn-primary btn-sm" id="savePwdBtn" onclick="saveSection('password')">
                <i class="fas fa-shield-halved"></i> Change Password
              </button>
            </div>
          </div>

          <!-- ── 7. Course Assignments ─────────────────────────────── -->
          <?php if (!empty($assignments)): ?>
          <div class="settings-card">
            <div class="card-heading">
              <div class="card-heading-icon teal"><i class="fas fa-chalkboard-user"></i></div>
              <div>
                <h3>Course Assignments</h3>
                <p>Your active teaching assignments (managed by admin)</p>
              </div>
            </div>
            <table class="assign-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Course</th>
                  <th>Department</th>
                  <th>Semester</th>
                  <th>Role</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($assignments as $i => $asgn): ?>
                <tr>
                  <td style="color:var(--muted)"><?= $i+1 ?></td>
                  <td>
                    <strong style="color:var(--white)"><?= h($asgn['course_name']) ?></strong>
                    <div style="font-size:.7rem;color:var(--muted)"><?= h($asgn['course_code']) ?></div>
                  </td>
                  <td><?= h($asgn['dept_name']) ?><br><span style="font-size:.7rem;color:var(--muted)"><?= h($asgn['dept_code']) ?></span></td>
                  <td><?= $asgn['semester'] ? 'Sem '.$asgn['semester'] : '—' ?></td>
                  <td>
                    <?php if ($asgn['is_primary']): ?>
                    <span class="badge badge-teal"><i class="fas fa-star" style="font-size:.55rem"></i> Primary</span>
                    <?php else: ?>
                    <span class="badge badge-amber">Assistant</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

        </div><!-- /settings-col -->
      </div><!-- /profile-layout -->
    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /shell -->

<!-- Toast -->
<div id="toast">
  <i class="fas fa-circle-check" id="toastIcon" style="color:var(--teal)"></i>
  <span id="toastMsg"></span>
</div>

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

/* ── Sidebar toggle (mobile) ────────────────────────────────────────────────── */
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  const open = sb.classList.toggle('open');
  ov.classList.toggle('active', open);
}

/* ── Avatar dropdown ────────────────────────────────────────────────────────── */
function toggleAvatarMenu(e){
  e.stopPropagation();
  const avatar = document.getElementById('topbarAvatar');
  const drop   = document.getElementById('avatarDropdown');
  const open   = drop.classList.toggle('open');
  avatar.classList.toggle('open', open);
}
document.addEventListener('click', function(e){
  const sb = document.getElementById('sidebar');
  if(window.innerWidth <= 800 && sb && sb.classList.contains('open') &&
     !sb.contains(e.target) && !document.getElementById('menuToggle')?.contains(e.target)){
    sb.classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
  }
  const wrap = document.querySelector('.topbar-avatar-wrap');
  if(wrap && !wrap.contains(e.target)){
    document.getElementById('avatarDropdown')?.classList.remove('open');
    document.getElementById('topbarAvatar')?.classList.remove('open');
  }
});

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res = await fetch('../auth/auth_handler.php',{
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=logout'
    });
    const data = await res.json();
    window.location.href = data.redirect || '../login.php';
  } catch { window.location.href = '../login.php'; }
}

/* ── Stored originals for reset ──────────────────────────────────────────────*/
const _orig = {
  full_name:    <?= json_encode($pf['full_name']) ?>,
  phone:        <?= json_encode($pf['phone']) ?>,
  designation:  <?= json_encode($pf['designation']) ?>,
  faculty_dept: <?= json_encode($pf['faculty_department']) ?>,
};
function resetPersonal(){
  document.getElementById('pFullName').value    = _orig.full_name;
  document.getElementById('pPhone').value       = _orig.phone;
  document.getElementById('pDesignation').value = _orig.designation;
  document.getElementById('pFacDept').value     = _orig.faculty_dept;
  ['pFullName','pPhone','pDesignation','pFacDept'].forEach(id=>clearFieldErr(id));
}

/* ── Toast ───────────────────────────────────────────────────────────────────*/
function showToast(msg, isErr=false){
  const t=document.getElementById('toast'),ic=document.getElementById('toastIcon');
  t.style.background  = isErr?'rgba(239,68,68,.12)':'rgba(0,212,187,.12)';
  t.style.borderColor = isErr?'rgba(239,68,68,.3)' :'rgba(0,212,187,.3)';
  ic.className = 'fas fa-'+(isErr?'triangle-exclamation':'circle-check');
  ic.style.color = isErr?'var(--red)':'var(--teal)';
  document.getElementById('toastMsg').textContent = msg;
  t.style.transform='translateY(0)'; t.style.opacity='1';
  setTimeout(()=>{t.style.transform='translateY(80px)';t.style.opacity='0';},3500);
}

/* ── Alert banner ────────────────────────────────────────────────────────────*/
function showAlert(id, msg, type='success'){
  const el=document.getElementById(id);
  if(!el) return;
  const icon=type==='success'?'circle-check':'triangle-exclamation';
  el.innerHTML=`<div class="alert alert-${type==='success'?'success':'error'}"><i class="fas fa-${icon}"></i><span>${msg}</span></div>`;
  setTimeout(()=>{ if(el) el.innerHTML=''; },4500);
}

/* ── Field-level error helpers ───────────────────────────────────────────────*/
function setFieldErr(fieldId, msg){
  const inp = document.getElementById(fieldId);
  const err = document.getElementById('err_'+fieldId);
  const wrap= document.getElementById('wrap_'+fieldId);
  if(inp){ inp.classList.add('is-invalid'); inp.classList.remove('is-valid'); }
  if(wrap){ wrap.classList.add('is-invalid'); wrap.classList.remove('is-valid'); }
  if(err){ err.innerHTML=`<i class="fas fa-circle-exclamation"></i>${msg}`; err.style.display='flex'; }
}
function setFieldOk(fieldId){
  const inp = document.getElementById(fieldId);
  const err = document.getElementById('err_'+fieldId);
  const wrap= document.getElementById('wrap_'+fieldId);
  if(inp){ inp.classList.remove('is-invalid'); inp.classList.add('is-valid'); }
  if(wrap){ wrap.classList.remove('is-invalid'); wrap.classList.add('is-valid'); }
  if(err){ err.innerHTML=''; err.style.display='none'; }
}
function clearFieldErr(fieldId){
  const inp=document.getElementById(fieldId);
  const err=document.getElementById('err_'+fieldId);
  const wrap=document.getElementById('wrap_'+fieldId);
  if(inp){ inp.classList.remove('is-invalid','is-valid'); }
  if(wrap){ wrap.classList.remove('is-invalid','is-valid'); }
  if(err){ err.innerHTML=''; err.style.display='none'; }
}

/* ── Live validation rules ───────────────────────────────────────────────────*/
function liveValidate(el, rule){
  const id=el.id, val=el.value.trim();
  switch(rule){
    case 'name':
      if(!val){ setFieldErr(id,'This field is required.'); return false; }
      if(val.length<3){ setFieldErr(id,'Must be at least 3 characters.'); return false; }
      if(val.length>150){ setFieldErr(id,'Must be under 150 characters.'); return false; }
      if(!/^[a-zA-Z\s.\-']+$/.test(val)){ setFieldErr(id,'Only letters, spaces, dots and hyphens allowed.'); return false; }
      break;
    case 'phone':
      if(val){
        if(!/^[\+\d\s\-\(\)]{7,20}$/.test(val)){ setFieldErr(id,'Enter a valid phone number.'); return false; }
        if(val.replace(/\D/g,'').length < 7){ setFieldErr(id,'Must contain at least 7 digits.'); return false; }
      }
      break;
    case 'text80':
      if(val.length>80){ setFieldErr(id,'Must be under 80 characters.'); return false; }
      if(val && /[<>{}]/.test(val)){ setFieldErr(id,'Invalid characters detected.'); return false; }
      break;
    case 'text120':
      if(val.length>120){ setFieldErr(id,'Must be under 120 characters.'); return false; }
      break;
    case 'text200':
      if(val.length>200){ setFieldErr(id,'Must be under 200 characters.'); return false; }
      break;
    case 'text500':
      if(val.length>500){ setFieldErr(id,'Must be under 500 characters.'); return false; }
      break;
    case 'text1000':
      if(val.length>1000){ setFieldErr(id,'Must be under 1000 characters.'); return false; }
      break;
    case 'expYears':
      if(val){
        if(!/^\d+$/.test(val)){ setFieldErr(id,'Must be a number.'); return false; }
        const n=parseInt(val);
        if(n<0||n>60){ setFieldErr(id,'Must be between 0 and 60.'); return false; }
      }
      break;
    case 'linkedin':
      if(val){
        if(!/^https?:\/\//i.test(val)){ setFieldErr(id,'Must start with https://'); return false; }
        if(!/linkedin\.com\//i.test(val)){ setFieldErr(id,'Must be a linkedin.com URL.'); return false; }
        if(val.length>255){ setFieldErr(id,'URL too long (max 255 chars).'); return false; }
      }
      break;
    case 'email':
      if(!val){ setFieldErr(id,'Email address is required.'); return false; }
      if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)){ setFieldErr(id,'Enter a valid email address.'); return false; }
      if(val.length>120){ setFieldErr(id,'Email too long (max 120 chars).'); return false; }
      break;
    case 'password':
      if(!val){ setFieldErr(id,'Password is required.'); return false; }
      if(val.length<8){ setFieldErr(id,'Must be at least 8 characters.'); return false; }
      if(!/[A-Z]/.test(val)){ setFieldErr(id,'Must contain at least one uppercase letter.'); return false; }
      if(!/[0-9]/.test(val)){ setFieldErr(id,'Must contain at least one number.'); return false; }
      break;
    case 'confirm':
      const pwd=document.getElementById('newPwd')?.value||'';
      if(!val){ setFieldErr(id,'Please confirm your password.'); return false; }
      if(val!==pwd){ setFieldErr(id,'Passwords do not match.'); return false; }
      break;
    case 'dob':
      if(val){
        const ts=new Date(val).getTime();
        const age=Math.floor((Date.now()-ts)/31557600000);
        if(ts>Date.now()){ setFieldErr(id,'Date cannot be in the future.'); return false; }
        if(age<18){ setFieldErr(id,'Must be at least 18 years old.'); return false; }
        if(age>100){ setFieldErr(id,'Please enter a realistic date of birth.'); return false; }
      }
      break;
    case 'pincode':
      if(val && !/^\d{5,10}$/.test(val.replace(/\s/g,''))){ setFieldErr(id,'Enter a valid 5–10 digit PIN/ZIP code.'); return false; }
      break;
    case 'citystate':
      if(val){
        if(val.length>80){ setFieldErr(id,'Must be under 80 characters.'); return false; }
        if(!/^[a-zA-Z\s\-\.]+$/.test(val)){ setFieldErr(id,'Letters, spaces and hyphens only.'); return false; }
      }
      break;
    case 'required':
      if(!val){ setFieldErr(id,'This field is required.'); return false; }
      break;
    case 'select':
      break; // optional fields
  }
  if(val) setFieldOk(id); else clearFieldErr(id);
  return true;
}

/* ── Client-side pre-validation before submitting ────────────────────────────*/
function validateSection(section){
  let ok=true;

  // Only fails if liveValidate returns false — safe wrapper
  function chk(id, rule){
    const el=document.getElementById(id);
    if(!el) return;
    if(!liveValidate(el, rule)){
      ok=false;
      el.scrollIntoView({behavior:'smooth',block:'center'});
    }
  }
  // Only validate if field has a value (optional fields)
  function chkOpt(id, rule){
    const el=document.getElementById(id);
    if(!el||!el.value.trim()) return;
    if(!liveValidate(el, rule)){
      ok=false;
      el.scrollIntoView({behavior:'smooth',block:'center'});
    }
  }

  if(section==='personal'){
    chk('pFullName','name');                    // required
    chkOpt('pPhone','phone');                   // optional
    chkOpt('pDesignation','text80');            // optional
    chkOpt('pFacDept','text120');               // optional

  } else if(section==='personal_detail'){
    chkOpt('pdDob','dob');                      // optional

  } else if(section==='academic'){
    chkOpt('aQual','text200');
    chkOpt('aExp','expYears');
    chkOpt('aSpec','text200');
    chkOpt('aResearch','text1000');
    chkOpt('aPublications','text1000');
    chkOpt('aLinkedin','linkedin');
    chkOpt('aBio','text500');

  } else if(section==='address'){
    chkOpt('adAddress','text500');
    chkOpt('adCity','citystate');
    chkOpt('adState','citystate');
    chkOpt('adPincode','pincode');
    // Emergency: name is optional, but if given phone is required
    const emgName=(document.getElementById('adEmgName')?.value||'').trim();
    const emgPhone=(document.getElementById('adEmgPhone')?.value||'').trim();
    if(emgName){
      chkOpt('adEmgName','name');               // validate name only if filled
      if(!emgPhone){ setFieldErr('adEmgPhone','Phone is required when a contact name is given.'); ok=false; }
      else { const emgEl=document.getElementById('adEmgPhone'); if(emgEl&&!validatePhone10(emgEl)) ok=false; }
    }

  } else if(section==='password'){
    chk('currentPwd','required');
    chk('newPwd','password');
    chk('confirmPwd','confirm');

  } else if(section==='email'){
    chk('newEmail','email');
  }

  return ok;
}

/* ── Set btn loading state ───────────────────────────────────────────────────*/
function setLoading(btnId, loading, label='Save'){
  const btn=document.getElementById(btnId);
  if(!btn) return;
  btn.disabled=loading;
  btn.innerHTML=loading
    ?`<i class="fas fa-spinner fa-spin"></i> Saving…`
    :`<i class="fas fa-floppy-disk"></i> ${label}`;
}

/* ── Universal save ──────────────────────────────────────────────────────────*/
async function saveSection(section){
  // Client-side validation first
  if(!validateSection(section)) return;

  const fd = new FormData();
  fd.append('ajax_save_profile','1');
  fd.append('section', section);

  const alertId = {
    personal:'personalAlert', personal_detail:'personalDetailAlert',
    academic:'academicAlert', address:'addressAlert',
    password:'passwordAlert', email:'emailAlert', avatar_color:'personalAlert'
  }[section]||'personalAlert';

  const btnId = {
    personal:'savePersonalBtn', personal_detail:'savePersonalDetailBtn',
    academic:'saveAcademicBtn', address:'saveAddressBtn',
    password:'savePwdBtn', email:'saveEmailBtn'
  }[section]||'savePersonalBtn';

  setLoading(btnId, true);

  if(section==='personal'){
    fd.append('full_name',   document.getElementById('pFullName').value.trim());
    const pCode=document.getElementById('pPhoneCode').value;
    const pNum=document.getElementById('pPhone').value.trim();
    fd.append('phone', pNum ? pCode+' '+pNum : '');
    fd.append('designation', document.getElementById('pDesignation').value.trim());
    fd.append('faculty_department', document.getElementById('pFacDept').value.trim());
    fd.append('avatar_color', document.getElementById('customColorPicker').value);
  } else if(section==='personal_detail'){
    fd.append('dob',         document.getElementById('pdDob').value);
    fd.append('gender',      document.getElementById('pdGender').value);
    fd.append('blood_group', document.getElementById('pdBlood').value);
  } else if(section==='academic'){
    fd.append('qualification',    document.getElementById('aQual').value.trim());
    fd.append('experience_years', document.getElementById('aExp').value);
    fd.append('specialization',   document.getElementById('aSpec').value.trim());
    fd.append('research_interests', document.getElementById('aResearch').value.trim());
    fd.append('publications',     document.getElementById('aPublications').value.trim());
    fd.append('linkedin',         document.getElementById('aLinkedin').value.trim());
    fd.append('bio',              document.getElementById('aBio').value.trim());
  } else if(section==='address'){
    fd.append('address',           document.getElementById('adAddress').value.trim());
    fd.append('city',              document.getElementById('adCity').value.trim());
    fd.append('state',             document.getElementById('adState').value.trim());
    fd.append('pincode',           document.getElementById('adPincode').value.trim());
    fd.append('emergency_name',    document.getElementById('adEmgName').value.trim());
    const emgCode=document.getElementById('adEmgPhoneCode').value;
    const emgNum=document.getElementById('adEmgPhone').value.trim();
    fd.append('emergency_contact', emgNum ? emgCode+' '+emgNum : '');
  } else if(section==='password'){
    fd.append('current_password', document.getElementById('currentPwd').value);
    fd.append('new_password',     document.getElementById('newPwd').value);
    fd.append('confirm_password', document.getElementById('confirmPwd').value);
  } else if(section==='email'){
    fd.append('new_email', document.getElementById('newEmail').value.trim());
  }

  try {
    const res  = await fetch(window.location.pathname,{method:'POST',body:fd});
    const data = await res.json();

    if(data.ok){
      showToast(data.msg);
      showAlert(alertId, data.msg, 'success');

      // Live UI updates
      if(section==='personal' && data.full_name){
        document.getElementById('cardName').textContent          = data.full_name;
        document.getElementById('avatarInitials').textContent    = data.initials;
        document.getElementById('avatarRing').style.background   = data.avatar_color;
        document.getElementById('sidebarAvatar').textContent     = data.initials;
        document.getElementById('sidebarAvatar').style.background= data.avatar_color;
        document.getElementById('sidebarName').textContent       = data.full_name;
      }
      if(section==='email' && data.new_email){
        document.getElementById('currentEmailDisplay').value = data.new_email;
        document.getElementById('cardEmail').textContent     = data.new_email;
        document.getElementById('newEmail').value = '';
        clearFieldErr('newEmail');
      }
      if(section==='password'){
        ['currentPwd','newPwd','confirmPwd'].forEach(id=>{document.getElementById(id).value='';clearFieldErr(id);});
        document.getElementById('strengthFill').style.width='0%';
        document.getElementById('strengthLabel').textContent='';
      }
    } else {
      showToast(data.msg, true);
      showAlert(alertId, data.msg, 'error');
      // Highlight field returned from server
      if(data.field) setFieldErr(data.field, data.msg);
    }
  } catch(e){
    showToast('Network error. Please try again.', true);
    showAlert(alertId, 'Network error.', 'error');
  } finally {
    const labelMap={personal:'Save Changes',personal_detail:'Save',academic:'Save Academic Info',address:'Save Address',password:'Change Password',email:'Update Email'};
    setLoading(btnId, false, labelMap[section]||'Save');
  }
}

/* ── Avatar color picker ─────────────────────────────────────────────────────*/
function pickColor(color){
  document.getElementById('avatarRing').style.background  = color;
  document.getElementById('sidebarAvatar').style.background= color;
  document.getElementById('customColorPicker').value = color;
  document.querySelectorAll('.swatch').forEach(s=>{
    s.classList.toggle('active', s.getAttribute('onclick')?.includes(color));
  });
  const fd=new FormData();
  fd.append('ajax_save_profile','1');fd.append('section','avatar_color');fd.append('color',color);
  fetch(window.location.pathname,{method:'POST',body:fd}).then(r=>r.json())
    .then(d=>{ if(d.ok) showToast('Avatar color updated!'); }).catch(()=>{});
}
function hexToRgb(hex){
  const r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16);
  return `rgb(${r}, ${g}, ${b})`;
}

/* ── Password helpers ────────────────────────────────────────────────────────*/
function togglePwd(inputId, iconId){
  const inp=document.getElementById(inputId),ic=document.getElementById(iconId);
  const show=inp.type==='password';
  inp.type=show?'text':'password';
  ic.className='fas fa-'+(show?'eye-slash':'eye');
}
function checkStrength(val){
  const fill=document.getElementById('strengthFill'),label=document.getElementById('strengthLabel');
  let score=0;
  if(val.length>=8) score++;
  if(val.length>=12) score++;
  if(/[A-Z]/.test(val)) score++;
  if(/[0-9]/.test(val)) score++;
  if(/[^A-Za-z0-9]/.test(val)) score++;
  const levels=[
    {w:'0%',  c:'transparent',t:''},
    {w:'20%', c:'var(--red)',  t:'Very Weak'},
    {w:'40%', c:'var(--red)',  t:'Weak'},
    {w:'60%', c:'var(--amber)',t:'Fair'},
    {w:'80%', c:'var(--green)',t:'Strong'},
    {w:'100%',c:'var(--teal)', t:'Very Strong'},
  ];
  const l=levels[score]||levels[0];
  fill.style.width=l.w; fill.style.background=l.c;
  label.textContent=l.t; label.style.color=l.c;
}

/* ── Character counter (with color warnings) ─────────────────────────────────*/
function charCount(el, countId, max){
  const len=el.value.length;
  const counter=document.getElementById(countId);
  if(!counter) return;
  counter.textContent=len+'/'+max;
  counter.className='char-hint'+(len>max*0.9?' char-warn':'')+(len>=max?' char-over':'');
  if(len>max) el.classList.add('is-invalid'); else el.classList.remove('is-invalid');
}

/* ── Emergency name triggers phone required asterisk ────────────────────────*/
document.getElementById('adEmgName')?.addEventListener('input', function(){
  const req=document.getElementById('emgPhoneRequired');
  if(req) req.style.display=this.value.trim()?'':'none';
});

/* ── Date chip ───────────────────────────────────────────────────────────────*/
document.getElementById('topbarDate').textContent =
  new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});

/* ── phone10 validator (standalone, no liveValidate override needed) ────────────*/
function validatePhone10(el){
  const v=(el.value||'').replace(/\D/g,'');
  const wrap=el.closest('[id^="wrap_"]')||el.closest('.form-group');
  const err=document.getElementById('err_'+el.id);
  if(v.length>0 && v.length!==10){
    if(wrap) wrap.classList.add('has-error');
    if(err){err.textContent='Phone number must be exactly 10 digits.';err.style.display='block';}
    return false;
  } else {
    if(wrap) wrap.classList.remove('has-error');
    if(err) err.style.display='none';
    return true;
  }
}

/* ── Photo upload ────────────────────────────────────────────────────────────*/
async function uploadPhoto(input){
  const file=input.files[0];
  if(!file) return;
  const msg=document.getElementById('photoMsg');
  msg.style.display='block'; msg.style.color='var(--muted)'; msg.textContent='Uploading…';
  const fd=new FormData();
  fd.append('ajax_save_profile','1');
  fd.append('section','photo_upload');
  fd.append('photo', file);
  try{
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(data.ok){
      msg.style.color='var(--teal)'; msg.textContent='Photo updated!';
      // Show in avatar ring
      const img=document.getElementById('avatarPhoto');
      const ini=document.getElementById('avatarInitials');
      const sidebarAvatar=document.getElementById('sidebarAvatar');
      if(img){ img.src=data.url; img.style.display='block'; }
      if(ini) ini.style.display='none';
      // Show remove button if not already present
      const removeBtn=document.getElementById('removePhotoBtn');
      if(!removeBtn){
        const btn=document.createElement('button');
        btn.id='removePhotoBtn'; btn.className='btn btn-sm';
        btn.style.cssText='font-size:.72rem;padding:6px 12px;background:var(--red2);color:var(--red);border:1px solid var(--red);border-radius:var(--radius)';
        btn.innerHTML='<i class="fas fa-trash"></i> Remove';
        btn.onclick=removePhoto;
        document.querySelector('#photoSection .btn-outline').after(btn);
      }
      showToast('Profile photo updated!');
    } else {
      msg.style.color='var(--red)'; msg.textContent=data.msg||'Upload failed.';
      showToast(data.msg||'Upload failed.', true);
    }
  }catch(e){ msg.style.color='var(--red)'; msg.textContent='Network error.'; }
  input.value='';
}

async function removePhoto(){
  if(!confirm('Remove your profile photo?')) return;
  const fd=new FormData();
  fd.append('ajax_save_profile','1'); fd.append('section','remove_photo');
  try{
    const res=await fetch(window.location.pathname,{method:'POST',body:fd});
    const data=await res.json();
    if(data.ok){
      const img=document.getElementById('avatarPhoto');
      const ini=document.getElementById('avatarInitials');
      if(img){ img.src=''; img.style.display='none'; }
      if(ini) ini.style.display='';
      const removeBtn=document.getElementById('removePhotoBtn');
      if(removeBtn) removeBtn.remove();
      document.getElementById('photoMsg').style.display='none';
      showToast('Profile photo removed.');
    } else showToast(data.msg||'Remove failed.',true);
  }catch(e){ showToast('Network error.',true); }
}
</script>
</body>
</html>