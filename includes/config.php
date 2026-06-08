<?php
// ─── Database Configuration ───────────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')     ?: '3306');
define('DB_NAME',    getenv('DB_NAME')     ?: 'college_erp');
define('DB_USER',    getenv('DB_USER')     ?: 'root');
define('DB_PASS',    getenv('DB_PASS')     ?: '');
define('DB_CHARSET', 'utf8mb4');

// ─── App Settings ─────────────────────────────────────────────────────────────
define('APP_NAME',    'EduNexus ERP');
define('APP_VERSION', '1.0.0');
define('APP_URL',     'http://localhost/erp');
define('SESSION_LIFETIME', 3600); // 1 hour

// ─── Singleton PDO Connection ─────────────────────────────────────────────────
function getDB(): ?PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Return a mock connection indicator for demo purposes
            error_log('DB Connection failed: ' . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function isAjax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function currentUser(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['user'] ?? null;
}

function requireAuth(): void {
    if (!currentUser()) {
        if (isAjax()) jsonResponse(['success' => false, 'message' => 'Unauthenticated'], 401);
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }
}

function logActivity(int $userId, string $action): void {
    $db = getDB();
    if (!$db) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare('INSERT INTO activity_log (user_id, action, ip_address) VALUES (?,?,?)')
       ->execute([$userId, $action, $ip]);
}
