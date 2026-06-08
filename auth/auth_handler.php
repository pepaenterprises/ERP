<?php
// ─── Auth AJAX Handler ────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ── ROLE → PAGE MAP ───────────────────────────────────────────────────────────
function getRedirectByRole(string $role): string {
    return match($role) {
        'student'       => '../ERP/pages/students.php',
        'faculty'       => '../ERP/pages/dashboard.php',
        'college_admin' => '../ERP/pages/dashboard.php',
        'super_admin'   => '../ERP/pages/superadmin_dashboard.php',
        default         => '../ERP/pages/dashboard.php',
    };
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']         ?? '';
    $remember = !empty($_POST['remember']);

    if (!$username || !$password) {
        jsonResponse(['success' => false, 'message' => 'Username and password are required.']);
    }

    $db = getDB();

    // ── Ultimate Admin — hardcoded, bypasses DB entirely ─────────────────────
    if ($username === 'pepaadmin' && $password === 'pepa123') {
        $_SESSION['ultimate_admin'] = true;
        $_SESSION['user'] = [
            'id'         => 0,
            'username'   => 'pepaadmin',
            'full_name'  => 'Pepa — Ultimate Admin',
            'role'       => 'ultimate_admin',
            'college_id' => null,
            'email'      => 'pepaadmin@erp.sys',
        ];
        jsonResponse(['success' => true, 'role' => 'ultimate_admin', 'name' => 'Pepa — Ultimate Admin', 'redirect' => 'ultimate.php']);
    }

    // Demo mode — no real DB available
    if (!$db) {
        $demoUsers = [
            'superadmin' => ['password' => 'Admin@123', 'role' => 'super_admin',    'full_name' => 'Super Administrator',  'college_id' => 1, 'id' => 1],
            'nitadmin'   => ['password' => 'Admin@123', 'role' => 'college_admin',  'full_name' => 'NIT College Admin',    'college_id' => 1, 'id' => 2],
            'faculty1'   => ['password' => 'Faculty@1', 'role' => 'faculty',        'full_name' => 'Dr. Ramesh Kumar',     'college_id' => 1, 'id' => 3],
            'student1'   => ['password' => 'Student@1', 'role' => 'student',        'full_name' => 'Arjun Sharma',         'college_id' => 1, 'id' => 4],
        ];

        if (isset($demoUsers[$username]) && $demoUsers[$username]['password'] === $password) {
            $u = $demoUsers[$username];
            $_SESSION['user'] = [
                'id'        => $u['id'],
                'username'  => $username,
                'full_name' => $u['full_name'],
                'role'      => $u['role'],
                'college_id'=> $u['college_id'],
                'email'     => $username . '@erp.edu',
            ];
            jsonResponse(['success' => true, 'role' => $u['role'], 'name' => $u['full_name'], 'redirect' => getRedirectByRole($u['role'])]);
        }
        jsonResponse(['success' => false, 'message' => 'Invalid credentials. Try superadmin / Admin@123']);
    }

    // Real DB path
    $stmt = $db->prepare('SELECT * FROM users WHERE (username=? OR email=?) AND status="active" LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        jsonResponse(['success' => false, 'message' => 'Invalid username or password.']);
    }

    // If a user in the DB carries the ultimate_admin role, honour it
    if ($user['role'] === 'ultimate_admin') {
        $_SESSION['ultimate_admin'] = true;
        $_SESSION['user'] = [
            'id'        => $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => 'ultimate_admin',
            'college_id'=> null,
            'email'     => $user['email'],
        ];
        jsonResponse(['success' => true, 'role' => 'ultimate_admin', 'name' => $user['full_name'], 'redirect' => 'ultimate.php']);
    }

    $_SESSION['user'] = [
        'id'            => $user['id'],
        'username'      => $user['username'],
        'full_name'     => $user['full_name'],
        'role'          => $user['role'],
        'college_id'    => $user['college_id'],
        'department_id' => $user['department_id'],
        'email'         => $user['email'],
        'avatar_path'   => $user['avatar_path'],
    ];
    $_SESSION['_last_activity'] = time();

    // Update last login
    $db->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
    logActivity($user['id'], 'User logged in');

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $db->prepare('UPDATE users SET token=?, token_expiry=? WHERE id=?')
           ->execute([$token, $expiry, $user['id']]);
        setcookie('erp_token', $token, strtotime('+30 days'), '/', '', false, true);
    }

    jsonResponse(['success' => true, 'role' => $user['role'], 'name' => $user['full_name'], 'redirect' => getRedirectByRole($user['role'])]);
}

// ── MAILER CONFIG ─────────────────────────────────────────────────────────────
define('SMTP_USER',      'slaybeast01@gmail.com'); // ← your Gmail address
define('SMTP_PASS',      'fchd brmo htii fouj');   // ← Gmail App Password (16 chars)
define('SMTP_FROM_NAME', 'PEPA ERP');

// ── sendOtpEmail — uses PHPMailer; returns true on success ───────────────────
// $errorMsg is filled with the real reason on failure (shown to user in dev)
function sendOtpEmail(string $toEmail, string $otp, string &$errorMsg = ''): bool {

    // ── Load PHPMailer ───────────────────────────────────────────────────────
    // Searches for the vendor/ folder from the project root downward.
    // Just place the downloaded vendor/ folder in your project root.
    $projectRoot = dirname(__DIR__); // one level above /auth/ or wherever this file lives
    $candidates  = [
        $projectRoot,                    // <project_root>/vendor/...  ← most common
        dirname($projectRoot),           // one level higher, just in case
        __DIR__,                         // same folder as auth_handler.php
    ];

    $loaded = false;
    foreach ($candidates as $base) {
        $autoload = $base . '/vendor/autoload.php';
        $manual   = $base . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            $loaded = true; break;
        }
        if (file_exists($manual)) {
            require_once $manual;
            require_once $base . '/vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once $base . '/vendor/phpmailer/phpmailer/src/Exception.php';
            $loaded = true; break;
        }
    }

    if (!$loaded) {
        // Tell the developer exactly where we looked
        $looked = implode(', ', array_map(fn($b) => $b . '/vendor/', $candidates));
        $errorMsg = "PHPMailer not found. Looked in: {$looked} — Place the downloaded vendor/ folder in your project root.";
        error_log('[PEPA SMTP] ' . $errorMsg);
        return false;
    }

    // ── HTML email ────────────────────────────────────────────────────────────
    $htmlBody = '
<div style="font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;
            background:#F0FDFB;border-radius:14px;overflow:hidden;
            border:1px solid rgba(15,118,110,0.18);">
  <div style="background:linear-gradient(135deg,#0F766E,#14B8A6);padding:28px 32px;">
    <p style="margin:0;color:#fff;font-size:11px;letter-spacing:2px;
              text-transform:uppercase;opacity:0.85;">PEPA — Multi-College ERP</p>
    <h1 style="margin:8px 0 0;color:#fff;font-size:22px;font-weight:800;">
      Email Verification</h1>
  </div>
  <div style="padding:32px;">
    <p style="color:#374151;font-size:15px;margin-top:0;">
      Use the code below to verify your email address.<br>
      It expires in <strong>10 minutes</strong>.
    </p>
    <div style="background:#fff;border:2px solid rgba(15,118,110,0.25);
                border-radius:10px;text-align:center;padding:22px;margin:24px 0;
                letter-spacing:12px;font-size:36px;font-weight:800;
                color:#0F766E;font-family:monospace;">' . $otp . '</div>
    <p style="color:#6B7280;font-size:13px;margin-bottom:0;">
      If you did not request this, please ignore this email.
    </p>
  </div>
  <div style="background:rgba(15,118,110,0.06);padding:14px 32px;text-align:center;
              color:#9CA3AF;font-size:12px;border-top:1px solid rgba(15,118,110,0.10);">
    &copy; ' . date('Y') . ' PEPA ERP &mdash; This is an automated message.
  </div>
</div>';

    $plainBody = "Your PEPA ERP verification code is: {$otp}\r\nExpires in 10 minutes.";

    // ── Send ──────────────────────────────────────────────────────────────────
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);    // ← dynamic — whoever is registering

        $mail->isHTML(true);
        $mail->Subject = 'PEPA ERP — Your Verification Code';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
        return true;

    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        error_log('[PEPA SMTP] ' . $errorMsg);
        return false;
    }
}

// ── SEND OTP ──────────────────────────────────────────────────────────────────
if ($action === 'send_otp') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $db = getDB();
    if ($db) {
        // Invalidate any previous unused codes for this email
        $db->prepare('UPDATE email_verifications SET is_used=1 WHERE email=? AND is_used=0')
           ->execute([$email]);
        // Use UTC_TIMESTAMP() so expires_at and NOW() in the verify query are in the same timezone
        $db->prepare('INSERT INTO email_verifications (email, otp_code, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))')
           ->execute([$email, $otp]);
    }

    $smtpError = '';
    $sent = sendOtpEmail($email, $otp, $smtpError);

    if ($sent) {
        jsonResponse(['success' => true,
            'message' => "Verification code sent to {$email}. Check your inbox (and spam folder)."]);
    } else {
        // Return the REAL error so you can diagnose it directly in the browser
        $displayMsg = $smtpError ?: 'Unknown SMTP error — check your PHP error log (xampp/php/logs/php_error_log).';
        jsonResponse(['success' => false, 'message' => 'Email failed: ' . $displayMsg]);
    }
}

// ── VERIFY OTP ────────────────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    $email = trim($_POST['email'] ?? '');
    $code  = trim($_POST['otp']   ?? '');

    if (!$email || !$code) {
        jsonResponse(['success' => false, 'message' => 'Email and verification code are required.']);
    }

    $db = getDB();

    // Demo mode — accept any 6-digit code
    if (!$db) {
        if (preg_match('/^\d{6}$/', $code)) {
            $_SESSION['verified_email'] = $email;
            jsonResponse(['success' => true, 'message' => 'Email verified. (Demo Mode)']);
        }
        jsonResponse(['success' => false, 'message' => 'Invalid code. Enter any 6-digit number in demo mode.']);
    }

    $stmt = $db->prepare(
        'SELECT id FROM email_verifications
          WHERE email=? AND otp_code=? AND is_used=0 AND expires_at > UTC_TIMESTAMP()
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired code. Please request a new one.']);
    }

    // Mark as used
    $db->prepare('UPDATE email_verifications SET is_used=1 WHERE id=?')
       ->execute([$row['id']]);

    // Remember that this email is verified for the current registration session
    $_SESSION['verified_email'] = $email;

    jsonResponse(['success' => true, 'message' => 'Email verified successfully!']);
}

// ── REGISTER ──────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $fullName     = sanitize($_POST['full_name']         ?? '');
    $username     = sanitize($_POST['username']          ?? '');
    $email        = sanitize($_POST['email']             ?? '');
    $phone        = sanitize($_POST['phone']             ?? '');
    $collegeId    = (int)($_POST['college_id']          ?? 0);
    $departmentId = (int)($_POST['faculty_department']  ?? 0); // ← FIX: read department_id
    $role         = sanitize($_POST['role']              ?? 'student');
    $password     = $_POST['password']                   ?? '';
    $confirmPwd   = $_POST['confirm_password']           ?? '';

    // ── Guard: email must have been OTP-verified in this session ─────────────
    $verifiedEmail = $_SESSION['verified_email'] ?? '';
    if ($verifiedEmail !== $email) {
        jsonResponse(['success' => false, 'message' => 'Please verify your email address before registering.']);
    }

    $errors = [];
    if (strlen($fullName)  < 3)  $errors[] = 'Full name must be at least 3 characters.';
    if (strlen($username)  < 4)  $errors[] = 'Username must be at least 4 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($password)  < 8)  $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirmPwd) $errors[] = 'Passwords do not match.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain an uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain a number.';
    if (!in_array($role, ['student','faculty','college_admin'])) $errors[] = 'Invalid role selected.';
    // ← FIX: require department for faculty and students
    if (in_array($role, ['faculty', 'student']) && !$departmentId) {
        $errors[] = 'Please select your department.';
    }

    if ($errors) jsonResponse(['success' => false, 'message' => implode(' ', $errors)]);

    $db = getDB();

    if (!$db) {
        jsonResponse(['success' => true, 'message' => 'Registration successful! (Demo Mode — no DB connected.) You can now login.']);
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Username or email already exists.']);
    }

    $hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $status = ($role === 'student') ? 'active' : 'pending';

    // ← FIX: include department_id in INSERT
    $stmt = $db->prepare('INSERT INTO users (college_id, department_id, username, email, password, full_name, role, phone, status) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $collegeId    ?: null,
        $departmentId ?: null,   // ← FIX: save department_id
        $username,
        $email,
        $hash,
        $fullName,
        $role,
        $phone,
        $status,
    ]);
    $newId = (int)$db->lastInsertId();
    if ($newId > 0) logActivity($newId, 'New user registered');

    $msg = $status === 'active'
        ? 'Registration successful! You can now log in.'
        : 'Registration submitted! Awaiting admin approval.';

    jsonResponse(['success' => true, 'message' => $msg]);
}

// ── GET COLLEGES ──────────────────────────────────────────────────────────────
if ($action === 'get_colleges') {
    $db = getDB();
    if (!$db) {
        jsonResponse(['success' => true, 'colleges' => [
            ['id' => 1, 'name' => 'National Institute of Technology', 'code' => 'NIT001'],
            ['id' => 2, 'name' => 'Global Business School',           'code' => 'GBS002'],
            ['id' => 3, 'name' => 'State Engineering College',        'code' => 'SEC003'],
        ]]);
    }
    $colleges = $db->query('SELECT id, name, code FROM colleges WHERE status="active" ORDER BY name')->fetchAll();
    jsonResponse(['success' => true, 'colleges' => $colleges]);
}

// ── GET DEPARTMENTS ───────────────────────────────────────────────────────────
if ($action === 'get_departments') {
    $collegeId = (int)($_POST['college_id'] ?? 0);
    $db = getDB();
    if (!$db || !$collegeId) {
        jsonResponse(['success' => true, 'departments' => []]);
    }
    $stmt = $db->prepare('SELECT id, name FROM departments WHERE college_id=? AND status="active" ORDER BY name');
    $stmt->execute([$collegeId]);
    $departments = $stmt->fetchAll();
    jsonResponse(['success' => true, 'departments' => $departments]);
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    $user = $_SESSION['user'] ?? null;
    if ($user) logActivity($user['id'], 'User logged out');
    session_destroy();
    setcookie('erp_token', '', time() - 3600, '/');
    jsonResponse(['success' => true, 'redirect' => '../login.php']);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);