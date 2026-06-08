<?php
// ─── Accounts Auth Handler ────────────────────────────────────────────────────
// Handles login / logout specifically for the `accountant` role.
// Credentials are stored in `account_credentials` table, not the `users` table.
// Session key: $_SESSION['accountant'] — isolated from the main ERP session.
// ─────────────────────────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ── Shared JSON helper ────────────────────────────────────────────────────────
function accJson(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// ACTION: accounts_login
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'accounts_login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']       ?? '';
    $remember = !empty($_POST['remember']);

    if (!$username || !$password) {
        accJson(['success' => false, 'message' => 'Username and password are required.']);
    }

    $db = getDB();

    // ── Demo mode (no real DB) ────────────────────────────────────────────
    if (!$db) {
        $demo = [
            'accounts_nit' => [
                'password'   => 'Account@123',
                'full_name'  => 'NIT Accountant',
                'college_id' => 1,
                'id'         => 1,
            ],
        ];
        if (isset($demo[$username]) && $demo[$username]['password'] === $password) {
            $u = $demo[$username];
            $_SESSION['accountant'] = [
                'id'         => $u['id'],
                'username'   => $username,
                'full_name'  => $u['full_name'],
                'role'       => 'accountant',
                'college_id' => $u['college_id'],
                'email'      => $username . '@accounts.edu',
            ];
            accJson([
                'success'  => true,
                'name'     => $u['full_name'],
                'redirect' => '../pages/accounts.php',
            ]);
        }
        accJson(['success' => false, 'message' => 'Invalid credentials. Demo: accounts_nit / Account@123']);
    }

    // ── Real DB path ──────────────────────────────────────────────────────
    $stmt = $db->prepare('
        SELECT ac.*, c.name AS college_name, c.code AS college_code
        FROM   account_credentials ac
        JOIN   colleges c ON c.id = ac.college_id
        WHERE  ac.username = ?
          AND  ac.is_active = 1
        LIMIT  1
    ');
    $stmt->execute([$username]);
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cred || !password_verify($password, $cred['password_hash'])) {
        accJson(['success' => false, 'message' => 'Invalid username or password.']);
    }

    // Populate session — mirroring the structure used in accounts.php ─────
    $_SESSION['accountant'] = [
        'id'           => (int)$cred['id'],
        'username'     => $cred['username'],
        'full_name'    => $cred['full_name'],
        'role'         => 'accountant',
        'college_id'   => (int)$cred['college_id'],
        'college_name' => $cred['college_name'],
        'college_code' => $cred['college_code'],
        'email'        => $cred['email'] ?? '',
    ];
    $_SESSION['_acc_last_activity'] = time();

    // Update last_login
    $db->prepare('UPDATE account_credentials SET last_login = NOW() WHERE id = ?')
       ->execute([$cred['id']]);

    if (isset($GLOBALS['logActivity'])) {
        logActivity(null, 'Accountant logged in: ' . $username);
    }

    // Remember-me cookie  (30 days) — stores credential id + random token
    if ($remember) {
        $token  = bin2hex(random_bytes(32));
        $expiry = strtotime('+30 days');
        // Store token in a dedicated column (add if missing — see notes)
        try {
            $db->prepare('UPDATE account_credentials SET remember_token = ?, token_expiry = ? WHERE id = ?')
               ->execute([$token, date('Y-m-d H:i:s', $expiry), $cred['id']]);
        } catch (\Exception $ex) { /* column may not exist yet — silent */ }
        setcookie('acc_token', $cred['id'] . ':' . $token, $expiry, '/', '', false, true);
    }

    accJson([
        'success'  => true,
        'name'     => $cred['full_name'],
        'redirect' => '../pages/accounts.php',
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// ACTION: accounts_logout
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'accounts_logout') {
    $who = $_SESSION['accountant']['username'] ?? null;
    unset($_SESSION['accountant'], $_SESSION['_acc_last_activity']);
    setcookie('acc_token', '', time() - 3600, '/');
    accJson(['success' => true, 'redirect' => '../auth/accounts_login.php']);
}

// ════════════════════════════════════════════════════════════════════════════
// ACTION: check_session  (heartbeat — called by accounts.php JS if needed)
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'check_session') {
    if (!empty($_SESSION['accountant'])) {
        accJson(['active' => true, 'college_id' => $_SESSION['accountant']['college_id']]);
    }
    accJson(['active' => false, 'redirect' => '../auth/accounts_login.php']);
}

accJson(['success' => false, 'message' => 'Unknown action.'], 400);