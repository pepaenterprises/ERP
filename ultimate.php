<?php
// ─── Ultimate Admin Guard ──────────────────────────────────────────────────
session_start();

define('UA_USER', 'pepaadmin');
define('UA_PASS',  'pepa123');

// ─── Normalize session from auth_handler redirect ─────────────────────────
if (!empty($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'ultimate_admin') {
    $_SESSION['ultimate_admin'] = true;
}

// ─── Direct login form ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ua_login'])) {
    if ($_POST['ua_user'] === UA_USER && $_POST['ua_pass'] === UA_PASS) {
        $_SESSION['ultimate_admin'] = true;
    } else {
        $loginError = 'Invalid credentials.';
    }
}

// ─── Logout ───────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION['ultimate_admin'] = false;
    session_destroy();
    header('Location: login.php');
    exit;
}

// ─── CSRF token ───────────────────────────────────────────────────────────
if (empty($_SESSION['ua_token'])) {
    $_SESSION['ua_token'] = bin2hex(random_bytes(16));
}
$UA_TOKEN = $_SESSION['ua_token'];

// ─── DB helper ────────────────────────────────────────────────────────────
$DB_ERROR = null;
function getUltimateDB() {
    global $DB_ERROR;
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        // ultimate.php lives in ERP root; config.php is at ERP/includes/config.php
        $configPath = __DIR__ . '/includes/config.php';
        if (!file_exists($configPath)) {
            $DB_ERROR = 'config.php not found at: ' . $configPath;
            return null;
        }
        require_once $configPath;
        // config.php defines getDB() — call it directly
        if (!function_exists('getDB')) {
            $DB_ERROR = 'getDB() function not found in config.php';
            return null;
        }
        $pdo = getDB();
        if (!$pdo) {
            $DB_ERROR = 'getDB() returned null — MySQL may be down or credentials wrong in config.php.';
        }
    } catch (Throwable $e) {
        $DB_ERROR = $e->getMessage();
        $pdo = null;
    }
    return $pdo;
}

// ─── AJAX gate ────────────────────────────────────────────────────────────
$isAjax    = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']));
$tokenOk   = ($isAjax && isset($_POST['ua_token']) && hash_equals($_SESSION['ua_token'] ?? '', $_POST['ua_token']));
$sessionOk = !empty($_SESSION['ultimate_admin']);

if ($isAjax && ($tokenOk || $sessionOk)) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $db     = getUltimateDB();

    if ($action === 'ua_list_colleges') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $st = $db->query("SELECT id, name, code, email, phone, address, established, status, access_code, created_at FROM colleges ORDER BY id DESC");
        echo json_encode(['ok'=>true,'colleges'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'ua_create_college') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $name    = trim($_POST['name']    ?? '');
        $code    = strtoupper(trim($_POST['code']    ?? ''));
        $email   = trim($_POST['email']   ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $estd    = trim($_POST['established'] ?? '');
        $access  = strtoupper(trim($_POST['access_code'] ?? ''));
        if (!$name || !$code) { echo json_encode(['ok'=>false,'msg'=>'Name and Code are required.']); exit; }
        if (!$access || strlen($access) < 4) { echo json_encode(['ok'=>false,'msg'=>'Principal access code must be at least 4 characters.']); exit; }
        $chk = $db->prepare("SELECT id FROM colleges WHERE code=?");
        $chk->execute([$code]);
        if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>"College code '$code' already exists."]); exit; }
        $st = $db->prepare("INSERT INTO colleges (name,code,email,phone,address,established,status,access_code,created_at,updated_at) VALUES (?,?,?,?,?,?,'active',?,NOW(),NOW())");
        $st->execute([$name,$code,$email,$phone,$address,$estd?:null,$access]);
        echo json_encode(['ok'=>true,'msg'=>"College '$name' created successfully.",'id'=>$db->lastInsertId()]);
        exit;
    }

    if ($action === 'ua_update_college') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name']    ?? '');
        $code    = strtoupper(trim($_POST['code']    ?? ''));
        $email   = trim($_POST['email']   ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $estd    = trim($_POST['established'] ?? '');
        $access  = strtoupper(trim($_POST['access_code'] ?? ''));
        $status  = in_array($_POST['status']??'',['active','inactive']) ? $_POST['status'] : 'active';
        if (!$id || !$name || !$code) { echo json_encode(['ok'=>false,'msg'=>'ID, Name and Code required.']); exit; }
        if (!$access || strlen($access) < 4) { echo json_encode(['ok'=>false,'msg'=>'Access code must be at least 4 characters.']); exit; }
        $chk = $db->prepare("SELECT id FROM colleges WHERE code=? AND id!=?");
        $chk->execute([$code,$id]);
        if ($chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>"College code '$code' already in use."]); exit; }
        $st = $db->prepare("UPDATE colleges SET name=?,code=?,email=?,phone=?,address=?,established=?,status=?,access_code=?,updated_at=NOW() WHERE id=?");
        $st->execute([$name,$code,$email,$phone,$address,$estd?:null,$status,$access,$id]);
        echo json_encode(['ok'=>true,'msg'=>'College updated successfully.']);
        exit;
    }

    if ($action === 'ua_toggle_college') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }
        $db->prepare("UPDATE colleges SET status=IF(status='active','inactive','active'), updated_at=NOW() WHERE id=?")->execute([$id]);
        $cur = $db->prepare("SELECT status FROM colleges WHERE id=?"); $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'status'=>$row['status']]);
        exit;
    }

    if ($action === 'ua_delete_college') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }
        $db->prepare("DELETE FROM colleges WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true,'msg'=>'College deleted.']);
        exit;
    }

    if ($action === 'ua_set_access_code') {
        if (!$db) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.($DB_ERROR??'unknown')]); exit; }
        $id     = (int)($_POST['id'] ?? 0);
        $access = strtoupper(trim($_POST['access_code'] ?? ''));
        if (!$id || strlen($access) < 4) { echo json_encode(['ok'=>false,'msg'=>'College ID and access code (min 4 chars) required.']); exit; }
        $db->prepare("UPDATE colleges SET access_code=?,updated_at=NOW() WHERE id=?")->execute([$access,$id]);
        echo json_encode(['ok'=>true,'msg'=>'Access code updated. Principal can now use this to log in.']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ─── PRE-LOAD COLLEGES DIRECTLY IN PHP (bypasses all AJAX/session issues) ──
$authenticated    = !empty($_SESSION['ultimate_admin']);
$phpColleges      = [];   // will hold real rows
$phpDbError       = null; // error message if DB fails

if ($authenticated) {
    $db = getUltimateDB();
    if ($db) {
        try {
            $st = $db->query("SELECT id, name, code, email, phone, address, established, status, access_code, created_at FROM colleges ORDER BY id DESC");
            $phpColleges = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $phpDbError = $e->getMessage();
        }
    } else {
        $phpDbError = $DB_ERROR ?? 'getDB() returned null. Check DB credentials in includes/config.php.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ultimate Admin — PEPA ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ─────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --teal:#0F766E;
  --teal-dark:#0D5C56;
  --teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);
  --teal-soft2:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;
  --amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);
  --amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;
  --success:#16A34A;
  --green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --amber2:rgba(217,119,6,.14);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;
  --text:#0F172A;
  --text-muted:#475569;
  --text-light:#94A3B8;
  --border:rgba(15,118,110,.10);
  --border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;
  --card-hover:#F0FDFA;
  --radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;
  --mono:'JetBrains Mono',monospace;
  /* sidebar vars */
  --sb-text:rgba(255,255,255,.78);
  --sb-text-active:#FFFFFF;
  --sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);
  --sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);
  --sb-active-border:rgba(245,158,11,.38);
}
html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;
}

/* ── Login Gate ─────────────────────────────────────────────────────────── */
.gate-wrap{
  position:relative;z-index:1;min-height:100vh;
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.gate-card{
  width:100%;max-width:420px;
  background:#fff;border:1px solid var(--border);border-radius:20px;
  padding:48px 44px;box-shadow:0 8px 40px rgba(15,118,110,.12);text-align:center;
  animation:slideUp .4s ease both;
}
.gate-logo{
  width:68px;height:68px;border-radius:18px;
  background:linear-gradient(135deg,#0F766E,#14B8A6);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;font-size:1.7rem;color:#fff;
  box-shadow:0 6px 24px rgba(15,118,110,.30);
}
.gate-title{font-size:1.55rem;font-weight:800;color:var(--text);margin-bottom:5px}
.gate-sub{font-size:.84rem;color:var(--text-muted);margin-bottom:30px}
.gate-card .inp{
  width:100%;padding:11px 15px;margin-bottom:12px;
  background:#F8FAFC;border:1px solid var(--border);border-radius:9px;
  color:var(--text);font-family:var(--font);font-size:.9rem;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.gate-card .inp:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-soft)}
.gate-card .inp::placeholder{color:var(--text-light)}
.gate-btn{
  width:100%;padding:13px;margin-top:4px;
  background:linear-gradient(135deg,#0F766E,#14B8A6);
  border:none;border-radius:9px;
  font-family:var(--font);font-size:.9rem;font-weight:700;color:#fff;
  cursor:pointer;letter-spacing:.02em;transition:transform .2s,box-shadow .2s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.gate-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,118,110,.30)}
.gate-error{
  background:var(--red2);border:1px solid rgba(220,38,38,.25);border-radius:8px;
  color:var(--red);padding:10px 14px;font-size:.81rem;margin-bottom:14px;text-align:left;
  display:flex;gap:8px;align-items:center;
}
.gate-warning{
  margin-top:18px;padding:11px 14px;
  background:var(--amber-soft);border:1px solid rgba(245,158,11,.25);border-radius:8px;
  font-size:.73rem;color:var(--text-muted);display:flex;align-items:center;gap:7px;
}

/* ── Dashboard shell ────────────────────────────────────────────────────── */
.dash{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column}

/* Topbar — matches dashboard.php gradient topbar */
.topnav{
  display:flex;align-items:center;gap:16px;padding:0 28px;height:64px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:100;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.topnav-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-badge{
  width:36px;height:36px;border-radius:10px;
  background:rgba(255,255,255,.15);
  display:flex;align-items:center;justify-content:center;
  font-size:.88rem;color:#0D5C56;font-weight:900;
  box-shadow:0 2px 10px rgba(0,0,0,.15);
  overflow:hidden;
}
.logo-text{font-size:.96rem;font-weight:700;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:rgba(255,255,255,.5);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:2px}
.topnav-chip{
  margin-left:8px;
  background:rgba(245,158,11,.18);border:1px solid rgba(245,158,11,.35);
  color:#FCD34D;border-radius:50px;padding:3px 12px;
  font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  display:flex;align-items:center;gap:5px;
}
.topnav-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.topnav-user{display:flex;align-items:center;gap:7px;color:rgba(255,255,255,.75);font-size:.82rem}
.topnav-user strong{color:#fff}
.date-chip{font-size:.71rem;color:rgba(255,255,255,.72);background:rgba(255,255,255,.10);border:1px solid rgba(245,158,11,.20);padding:5px 11px;border-radius:7px;font-family:var(--mono)}
.btn-logout{
  background:none;border:1px solid rgba(255,255,255,.22);border-radius:8px;
  color:rgba(255,255,255,.80);padding:6px 14px;font-size:.77rem;font-weight:600;
  cursor:pointer;font-family:var(--font);transition:all .2s;
  display:flex;align-items:center;gap:6px;
}
.btn-logout:hover{background:rgba(220,38,38,.18);border-color:rgba(252,165,165,.40);color:#FCA5A5}

/* Main content */
.main{flex:1;padding:24px 28px;max-width:1500px;width:100%;margin:0 auto}

/* ── Hero card (same glass style as dashboard) ──────────────────────────── */
.page-hero{
  position:relative;overflow:hidden;border-radius:20px;margin-bottom:20px;
  background:linear-gradient(135deg,#0a4f4a 0%,#0F766E 38%,#0d8f82 68%,#12a898 100%);
  box-shadow:0 8px 40px rgba(13,92,86,.38),inset 0 1px 0 rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.07);
  animation:slideUp .4s ease both;
}
.page-hero::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 60% 80% at 0% 50%,rgba(255,255,255,.055) 0%,transparent 55%),
    radial-gradient(ellipse 45% 60% at 100% 0%,rgba(20,184,166,.20) 0%,transparent 50%),
    url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='30' cy='30' r='1'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.page-hero::after{
  content:'';position:absolute;width:300px;height:300px;border-radius:50%;
  background:radial-gradient(circle,rgba(20,184,166,.13) 0%,transparent 65%);
  top:-80px;right:-60px;pointer-events:none;
}
.ph-body{position:relative;z-index:1;display:flex;align-items:center;gap:18px;padding:18px 22px}
.ph-icon{
  width:52px;height:52px;border-radius:14px;flex-shrink:0;
  background:rgba(245,158,11,.22);border:1px solid rgba(245,158,11,.38);
  display:grid;place-items:center;font-size:1.3rem;color:#FCD34D;
  box-shadow:0 4px 18px rgba(245,158,11,.20);
}
.ph-text{flex:1}
.ph-title{font-size:1.3rem;font-weight:800;color:#fff;margin-bottom:4px;letter-spacing:.01em}
.ph-sub{font-size:.78rem;color:rgba(255,255,255,.6)}
.ph-stats{display:flex;gap:0;align-items:stretch;margin-left:auto;
  background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.12);border-radius:12px;overflow:hidden}
.ph-stat{padding:12px 18px;text-align:center;cursor:default;transition:background .2s}
.ph-stat:hover{background:rgba(255,255,255,.06)}
.ph-stat-val{font-family:var(--mono);font-size:1.2rem;font-weight:800;color:#5EEAD4;line-height:1}
.ph-stat-lbl{font-size:.58rem;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.1em;margin-top:2px}
.ph-stat-sep{width:1px;background:rgba(255,255,255,.10);margin:8px 0}

/* ── Stats row ──────────────────────────────────────────────────────────── */
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-card{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 18px;display:flex;align-items:center;gap:14px;
  box-shadow:0 1px 8px rgba(15,118,110,.05);transition:transform .2s,box-shadow .2s;
  animation:slideUp .4s ease both;
}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(15,118,110,.10)}
.stat-card:nth-child(1){animation-delay:.08s}
.stat-card:nth-child(2){animation-delay:.13s}
.stat-card:nth-child(3){animation-delay:.18s}
.stat-card:nth-child(4){animation-delay:.23s}
.stat-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;font-size:.95rem;flex-shrink:0}
.c-teal  .stat-icon{background:var(--teal-soft);color:var(--teal)}
.c-green .stat-icon{background:var(--green2);color:var(--success)}
.c-amber .stat-icon{background:var(--amber-soft);color:var(--amber-acc)}
.c-purple .stat-icon{background:var(--purple2);color:var(--purple)}
.stat-val{font-family:var(--mono);font-size:1.6rem;font-weight:800;color:var(--text);line-height:1}
.stat-lbl{font-size:.72rem;color:var(--text-muted);margin-top:3px}

/* ── Two-column layout ──────────────────────────────────────────────────── */
.two-col{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:start}
@media(max-width:1100px){.two-col{grid-template-columns:1fr}}

/* ── Panel (card) — matches dashboard card style ────────────────────────── */
.panel{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;
  box-shadow:0 1px 8px rgba(15,118,110,.05);animation:slideUp .45s .15s ease both;
}
.panel-hd{
  display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
  padding:14px 20px;border-bottom:1px solid var(--border);background:#F0FDFA;
}
.panel-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.panel-title i{color:var(--teal);font-size:.8rem}
.panel-body{padding:20px}

/* ── Table ──────────────────────────────────────────────────────────────── */
.tbl{width:100%;border-collapse:collapse;font-size:.82rem;table-layout:fixed}
.tbl th{
  padding:9px 12px;text-align:left;
  font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--text-muted);border-bottom:1px solid var(--border);white-space:nowrap;
}
.tbl td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;overflow:hidden;text-overflow:ellipsis}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#F0FDFA}

/* ── Form fields ────────────────────────────────────────────────────────── */
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px}
.fld input,.fld select,.fld textarea{
  width:100%;padding:9px 13px;
  background:#F8FAFC;border:1px solid var(--border);border-radius:8px;
  color:var(--text);font-family:var(--font);font-size:.87rem;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.fld input::placeholder,.fld textarea::placeholder{color:var(--text-light)}
.fld input:focus,.fld select:focus,.fld textarea:focus{
  border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-soft);background:#fff;
}
.fld select option{background:#fff;color:var(--text)}
.fld textarea{resize:vertical;min-height:68px}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.access-field input{
  font-family:var(--mono);font-size:.88rem;letter-spacing:.08em;text-transform:uppercase;
  border-color:rgba(245,158,11,.30);background:rgba(245,158,11,.04);
}
.access-field input:focus{border-color:var(--amber-acc);box-shadow:0 0 0 3px var(--amber-soft)}
.access-hint{font-size:.7rem;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:5px}
.access-hint i{color:var(--amber-acc)}

/* ── Buttons ────────────────────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;border:none;border-radius:8px;
  cursor:pointer;font-family:var(--font);font-weight:700;transition:all .2s;white-space:nowrap;
}
.btn-primary{
  background:linear-gradient(135deg,#0F766E,#14B8A6);color:#fff;
  padding:9px 18px;font-size:.83rem;
  box-shadow:0 2px 10px rgba(15,118,110,.20);
}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(15,118,110,.30)}
.btn-amber{
  background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;
  padding:9px 18px;font-size:.83rem;
  box-shadow:0 2px 10px rgba(245,158,11,.20);
}
.btn-amber:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(245,158,11,.30)}
.btn-sm{padding:6px 11px;font-size:.73rem;border-radius:7px}
.btn-edit{background:var(--teal-soft);color:var(--teal);border:1px solid var(--border-accent)}.btn-edit:hover{background:var(--teal-soft2)}
.btn-code{background:var(--amber-soft);color:var(--amber-acc);border:1px solid rgba(245,158,11,.25)}.btn-code:hover{background:var(--amber-soft2)}
.btn-danger{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.20)}.btn-danger:hover{background:rgba(220,38,38,.20)}
.btn-toggle{
  background:var(--green2);color:var(--success);border:1px solid rgba(22,163,74,.20);
  font-size:.68rem;padding:4px 10px;border-radius:20px;font-weight:700;
}
.btn-toggle.inactive{background:#F1F5F9;color:var(--text-muted);border-color:var(--border)}
.btn-gen{background:var(--purple2);color:var(--purple);border:1px solid rgba(124,58,237,.20);padding:9px 12px}.btn-gen:hover{background:rgba(124,58,237,.18)}
.btn-full{width:100%;justify-content:center;padding:11px}
.btn-ghost{background:rgba(15,118,110,.06);border:1px solid var(--border);color:var(--text-muted)}
.btn-ghost:hover{background:var(--teal-soft);color:var(--teal)}

/* Pill badges */
.pill{font-size:.63rem;font-weight:700;padding:3px 8px;border-radius:6px;display:inline-block}
.pill.active{background:var(--green2);color:var(--success)}
.pill.inactive{background:#F1F5F9;color:var(--text-muted)}

/* ── Access code chip ────────────────────────────────────────────────────── */
.access-chip{
  font-family:var(--mono);font-size:.8rem;color:var(--amber-acc);
  background:var(--amber-soft);border:1px solid rgba(245,158,11,.22);
  border-radius:6px;padding:3px 10px;letter-spacing:.06em;display:inline-block;
}
.access-chip.unset{color:var(--text-muted);background:#F1F5F9;border-color:var(--border)}

/* DB error */
.db-error-banner{
  display:flex;align-items:flex-start;gap:10px;
  padding:12px 18px;background:var(--red2);border-bottom:1px solid rgba(220,38,38,.20);
  font-size:.78rem;color:var(--red);
}
.db-error-banner i{flex-shrink:0;margin-top:2px}
.db-error-banner code{font-family:var(--mono);font-size:.73rem;background:rgba(0,0,0,.07);padding:2px 5px;border-radius:4px;word-break:break-all}

/* Empty state */
.empty{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:.8rem}
.empty i{font-size:1.4rem;display:block;margin-bottom:7px;opacity:.25}

/* ── Toast ──────────────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-item{
  padding:11px 16px;border-radius:10px;font-size:.82rem;
  display:flex;align-items:center;gap:9px;
  box-shadow:0 8px 28px rgba(0,0,0,.12);animation:toastIn .3s ease both;pointer-events:auto;
}
.toast-item.success{background:#fff;border:1px solid rgba(22,163,74,.30);color:var(--success)}
.toast-item.error{background:#fff;border:1px solid rgba(220,38,38,.25);color:var(--red)}
@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}

/* ── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay{
  position:fixed;inset:0;background:rgba(15,23,42,.50);z-index:500;
  display:none;align-items:center;justify-content:center;padding:24px;
  backdrop-filter:blur(4px);
}
.modal-overlay.open{display:flex}
.modal{
  background:#fff;border:1px solid var(--border);border-radius:18px;
  width:100%;max-width:560px;max-height:90vh;overflow-y:auto;
  box-shadow:0 24px 60px rgba(15,118,110,.16);
}
.modal-hd{
  padding:18px 22px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;background:#F0FDFA;
}
.modal-hd h3{font-size:1rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.modal-hd h3 i{color:var(--teal)}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer;padding:4px;line-height:1;transition:color .2s}
.modal-close:hover{color:var(--text)}
.modal-body{padding:20px 22px}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:9px;justify-content:flex-end;background:#F8FAFC}

/* Access code section in modal */
.access-section{
  background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.22);
  border-radius:10px;padding:14px 16px;margin-top:8px;
}
.access-section-label{font-size:.72rem;font-weight:700;color:var(--amber-acc);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;display:flex;align-items:center;gap:6px}

/* How it works steps */
.steps{display:flex;flex-direction:column;gap:13px}
.step{display:flex;gap:11px;align-items:flex-start}
.step-num{
  width:26px;height:26px;border-radius:50%;flex-shrink:0;
  display:grid;place-items:center;font-weight:800;font-size:.75rem;
}
.step-num.t{background:var(--teal-soft);color:var(--teal)}
.step-num.a{background:var(--amber-soft);color:var(--amber-acc)}
.step-num.g{background:var(--green2);color:var(--success)}
.step-title{font-size:.82rem;font-weight:600;color:var(--text);margin-bottom:2px}
.step-desc{font-size:.73rem;color:var(--text-muted);line-height:1.5}

/* Scrollbar */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* Responsive */
@media(max-width:900px){.stats-bar{grid-template-columns:1fr 1fr}}
@media(max-width:600px){
  .stats-bar{grid-template-columns:1fr 1fr}
  .ph-stats{display:none}
  .main{padding:14px}
  .topnav{padding:0 14px}
}

/* Animations */
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="bg-grid"></div>

<?php if (!$authenticated): ?>
<!-- ══ LOGIN GATE ══════════════════════════════════════════════════════════ -->
<div class="gate-wrap">
  <div class="gate-card">
    <div class="gate-logo"><i class="fas fa-shield-halved"></i></div>
    <div class="gate-title">Ultimate Admin</div>
    <div class="gate-sub">PEPA ERP — Restricted Access</div>
    <?php if (!empty($loginError)): ?>
    <div class="gate-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="ua_login" value="1">
      <input class="inp" type="text"     name="ua_user" placeholder="Username" autocomplete="username" autofocus>
      <input class="inp" type="password" name="ua_pass" placeholder="Password" autocomplete="current-password">
      <button class="gate-btn" type="submit"><i class="fas fa-arrow-right-to-bracket"></i> Enter Ultimate Admin</button>
    </form>
    <div class="gate-warning"><i class="fas fa-triangle-exclamation" style="color:var(--amber-acc)"></i> This area is restricted. All actions are logged.</div>
  </div>
</div>

<?php else: ?>
<!-- ══ DASHBOARD ═══════════════════════════════════════════════════════════ -->
<?php
$total    = count($phpColleges);
$active   = count(array_filter($phpColleges, fn($c) => $c['status'] === 'active'));
$inactive = $total - $active;
$withCode = count(array_filter($phpColleges, fn($c) => !empty(trim($c['access_code'] ?? ''))));
?>
<div class="dash">
  <nav class="topnav">
    <a class="topnav-logo" href="#">
      <div class="logo-badge">
        <img src="pages/pepa_fevicon.png" alt="Logo" style="width:26px;height:26px;object-fit:contain;border-radius:6px;">
      </div>
      <div class="logo-text">PEPA ERP <span>Platform</span></div>
    </a>
    <div class="topnav-chip"><i class="fas fa-crown"></i> Ultimate Admin</div>
    <div class="topnav-right">
      <div class="date-chip" id="topbarDate"></div>
      <div class="topnav-user"><i class="fas fa-user-shield" style="color:#FCD34D"></i> Signed in as <strong>pepaadmin</strong></div>
      <a href="?logout=1" class="btn-logout"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
    </div>
  </nav>

  <main class="main">

    <!-- Hero Card -->
    <div class="page-hero">
      <div class="ph-body">
        <div class="ph-icon"><i class="fas fa-crown"></i></div>
        <div class="ph-text">
          <div class="ph-title">College Management</div>
          <div class="ph-sub">Create colleges, assign principal access codes, and manage the ERP ecosystem.</div>
        </div>
        <div class="ph-stats">
          <div class="ph-stat"><div class="ph-stat-val"><?= $total ?></div><div class="ph-stat-lbl">Total</div></div>
          <div class="ph-stat-sep"></div>
          <div class="ph-stat"><div class="ph-stat-val"><?= $active ?></div><div class="ph-stat-lbl">Active</div></div>
          <div class="ph-stat-sep"></div>
          <div class="ph-stat"><div class="ph-stat-val"><?= $withCode ?></div><div class="ph-stat-lbl">Coded</div></div>
        </div>
      </div>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar">
      <div class="stat-card c-teal">
        <div class="stat-icon"><i class="fas fa-university"></i></div>
        <div><div class="stat-val"><?= $total ?></div><div class="stat-lbl">Total Colleges</div></div>
      </div>
      <div class="stat-card c-green">
        <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
        <div><div class="stat-val"><?= $active ?></div><div class="stat-lbl">Active</div></div>
      </div>
      <div class="stat-card c-amber">
        <div class="stat-icon"><i class="fas fa-key"></i></div>
        <div><div class="stat-val"><?= $withCode ?></div><div class="stat-lbl">Access Codes Set</div></div>
      </div>
      <div class="stat-card c-purple">
        <div class="stat-icon"><i class="fas fa-ban"></i></div>
        <div><div class="stat-val"><?= $inactive ?></div><div class="stat-lbl">Inactive</div></div>
      </div>
    </div>

    <div class="two-col">

      <!-- Left: Colleges Table -->
      <div class="panel">
        <?php if ($phpDbError): ?>
        <div class="db-error-banner">
          <i class="fas fa-circle-exclamation"></i>
          <div>
            <strong>Database connection failed.</strong> Colleges cannot be loaded until this is resolved.<br>
            <code><?= htmlspecialchars($phpDbError) ?></code><br>
            <span style="opacity:.7;margin-top:4px;display:block">
              Expected: <code><?= htmlspecialchars(__DIR__ . '/includes/config.php') ?></code><br>
              Make sure MySQL is running in XAMPP and credentials in config.php are correct.
            </span>
          </div>
        </div>
        <?php endif; ?>
        <div class="panel-hd">
          <div class="panel-title">
            <i class="fas fa-list"></i> All Colleges
            <?php if ($total > 0): ?>
            <span style="font-family:var(--mono);font-size:.7rem;color:var(--text-muted);font-weight:400">(<?= $total ?>)</span>
            <?php endif; ?>
          </div>
          <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
            <i class="fas fa-plus"></i> New College
          </button>
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <colgroup>
              <col style="width:34%">
              <col style="width:11%">
              <col style="width:11%">
              <col style="width:24%">
              <col style="width:20%">
            </colgroup>
            <thead>
              <tr>
                <th>College</th>
                <th>Code</th>
                <th style="text-align:center">Status</th>
                <th>Principal Access Code</th>
                <th style="text-align:center">Actions</th>
              </tr>
            </thead>
            <tbody id="collegesTbody">
              <?php if (empty($phpColleges) && !$phpDbError): ?>
              <tr><td colspan="5" class="empty"><i class="fas fa-university"></i>No colleges yet. Create one!</td></tr>
              <?php elseif ($phpDbError): ?>
              <tr><td colspan="5" class="empty"><i class="fas fa-plug-circle-xmark" style="color:var(--red)"></i>Fix the database error above to see colleges.</td></tr>
              <?php else: ?>
              <?php foreach ($phpColleges as $c): ?>
              <tr id="row_<?= (int)$c['id'] ?>">
                <td>
                  <div style="font-weight:700;color:var(--text);font-size:.87rem"><?= htmlspecialchars($c['name']) ?></div>
                  <?php if (!empty($c['email'])): ?><div style="font-size:.71rem;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars($c['email']) ?></div><?php endif; ?>
                </td>
                <td><span style="font-family:var(--mono);font-size:.79rem;color:var(--teal)"><?= htmlspecialchars($c['code']) ?></span></td>
                <td style="text-align:center">
                  <button class="btn-toggle btn <?= $c['status']==='active'?'':'inactive' ?>" onclick="toggleStatus(<?= (int)$c['id'] ?>)">
                    <?= $c['status']==='active' ? '<i class="fas fa-check"></i> Active' : '<i class="fas fa-times"></i> Inactive' ?>
                  </button>
                </td>
                <td>
                  <span class="access-chip <?= empty(trim($c['access_code']??'')) ? 'unset' : '' ?>">
                    <?= htmlspecialchars($c['access_code'] ?: 'NOT SET') ?>
                  </span>
                </td>
                <td style="text-align:center">
                  <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap">
                    <button class="btn btn-edit btn-sm" onclick="openEditModal(<?= (int)$c['id'] ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                    <button class="btn btn-code btn-sm" onclick="openQuickCode(<?= (int)$c['id'] ?>)" title="Set Access Code"><i class="fas fa-key"></i></button>
                    <button class="btn btn-danger btn-sm" onclick="deleteCollege(<?= (int)$c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Right Column -->
      <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Quick Access Code -->
        <div class="panel">
          <div class="panel-hd">
            <div class="panel-title"><i class="fas fa-key" style="color:var(--amber-acc)"></i> Quick Access Code</div>
          </div>
          <div class="panel-body">
            <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:14px;line-height:1.6">
              Update a college's principal access code instantly.
            </p>
            <div class="fld">
              <label>Select College</label>
              <select id="qcCollege">
                <option value="">— Select College —</option>
                <?php foreach ($phpColleges as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fld access-field">
              <label><i class="fas fa-key" style="color:var(--amber-acc)"></i> New Access Code</label>
              <div style="display:flex;gap:8px">
                <input type="text" id="qcCode" placeholder="e.g. PEPA2025" maxlength="30" oninput="this.value=this.value.toUpperCase()">
                <button class="btn btn-gen" onclick="genCode('qcCode')" title="Auto-generate">
                  <i class="fas fa-wand-magic-sparkles"></i>
                </button>
              </div>
              <div class="access-hint"><i class="fas fa-circle-info"></i> Minimum 4 characters.</div>
            </div>
            <button class="btn btn-amber btn-full" onclick="quickSetCode()">
              <i class="fas fa-save"></i> Set Access Code
            </button>
            <div id="qcMsg" style="margin-top:10px;font-size:.77rem;display:none"></div>
          </div>
        </div>

        <!-- How it Works -->
        <div class="panel">
          <div class="panel-hd">
            <div class="panel-title"><i class="fas fa-circle-info"></i> How It Works</div>
          </div>
          <div class="panel-body">
            <div class="steps">
              <div class="step">
                <div class="step-num t">1</div>
                <div>
                  <div class="step-title">Create College</div>
                  <div class="step-desc">Add the college with a unique code and set a principal access code.</div>
                </div>
              </div>
              <div class="step">
                <div class="step-num a">2</div>
                <div>
                  <div class="step-title">Share Access Code</div>
                  <div class="step-desc">Give the access code to the college principal to log in.</div>
                </div>
              </div>
              <div class="step">
                <div class="step-num g">3</div>
                <div>
                  <div class="step-title">Reflects Everywhere</div>
                  <div class="step-desc">The college appears in login dropdowns, dashboards, and all ERP modules.</div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<!-- ══ CREATE / EDIT MODAL ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="collegeModal">
  <div class="modal">
    <div class="modal-hd">
      <h3 id="modalTitle"><i class="fas fa-plus"></i> New College</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editId">
      <div class="form-row-2">
        <div class="fld"><label>College Name *</label><input type="text" id="mName" placeholder="e.g. Pepa Institute of Technology"></div>
        <div class="fld"><label>College Code *</label><input type="text" id="mCode" placeholder="e.g. PIT001" maxlength="20" oninput="this.value=this.value.toUpperCase()"></div>
      </div>
      <div class="form-row-2">
        <div class="fld"><label>Email</label><input type="email" id="mEmail" placeholder="admin@college.edu"></div>
        <div class="fld"><label>Phone</label><input type="tel" id="mPhone" placeholder="+91 XXXXXXXXXX"></div>
      </div>
      <div class="form-row-2">
        <div class="fld"><label>Established Year</label><input type="number" id="mEstd" placeholder="e.g. 2000" min="1800" max="2099"></div>
        <div class="fld" id="statusField" style="display:none">
          <label>Status</label>
          <select id="mStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
      </div>
      <div class="fld"><label>Address</label><textarea id="mAddress" placeholder="Full college address…"></textarea></div>
      <div class="access-section">
        <div class="access-section-label"><i class="fas fa-key"></i> Principal Access Code *</div>
        <div style="display:flex;gap:8px" class="access-field">
          <input type="text" id="mAccess" placeholder="e.g. PEPA2025" maxlength="30"
                 oninput="this.value=this.value.toUpperCase()"
                 style="font-family:var(--mono);letter-spacing:.1em;font-size:.9rem">
          <button type="button" class="btn btn-gen" onclick="genCode('mAccess')" title="Auto-generate">
            <i class="fas fa-wand-magic-sparkles"></i>
          </button>
        </div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:8px;line-height:1.5;display:flex;gap:5px;align-items:center">
          <i class="fas fa-circle-info" style="color:var(--amber-acc)"></i>
          The principal enters this code at login to verify their college identity.
        </div>
      </div>
      <div id="modalMsg" style="margin-top:12px;font-size:.79rem;display:none;border-radius:8px;padding:9px 13px"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-sm btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary btn-sm" id="modalSaveBtn" onclick="saveCollege()"><i class="fas fa-save"></i> Save College</button>
    </div>
  </div>
</div>

<div id="toast"></div>
<?php endif; ?>

<script>
/* ─── Date chip clock ───────────────────────────────────────────── */
(function tick(){
  const el = document.getElementById('topbarDate');
  if(el) el.textContent = new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  setTimeout(tick, 60000);
})();

/* ─── colleges injected by PHP — no AJAX needed for initial render ── */
let _colleges = <?= json_encode($phpColleges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const UA_TOKEN = '<?= htmlspecialchars($UA_TOKEN ?? '') ?>';

function esc(s){ const d=document.createElement('div');d.textContent=String(s);return d.innerHTML; }

function toast(msg, type='success') {
  const c = document.getElementById('toast');
  if (!c) return;
  const el = document.createElement('div');
  el.className = `toast-item ${type}`;
  el.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'circle-exclamation'}"></i> ${esc(msg)}`;
  c.appendChild(el);
  setTimeout(()=>el.remove(), 4500);
}

async function api(payload) {
  const fd = new FormData();
  fd.append('ua_token', UA_TOKEN);
  Object.entries(payload).forEach(([k,v]) => fd.append(k, v));
  const r = await fetch(location.href, { method:'POST', body:fd, credentials:'same-origin' });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

function genCode(inputId) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let code = '';
  for(let i=0;i<8;i++) code += chars[Math.floor(Math.random()*chars.length)];
  const el = document.getElementById(inputId);
  if(el){ el.value = code; }
}

/* ─── Re-render the table from _colleges (after mutations) ─────────── */
function renderTable() {
  const tbody = document.getElementById('collegesTbody');
  if (!tbody) return;

  if (!_colleges.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty"><i class="fas fa-university"></i><br>No colleges yet. Create one!</td></tr>';
    return;
  }

  tbody.innerHTML = _colleges.map(c => `
    <tr id="row_${c.id}">
      <td>
        <div style="font-weight:700;color:var(--text);font-size:.88rem">${esc(c.name)}</div>
        ${c.email ? `<div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">${esc(c.email)}</div>` : ''}
      </td>
      <td><span style="font-family:var(--mono);font-size:.79rem;color:var(--teal)">${esc(c.code)}</span></td>
      <td style="text-align:center">
        <button class="btn-toggle btn ${c.status==='active'?'':'inactive'}" onclick="toggleStatus(${c.id})">
          ${c.status==='active'?'<i class="fas fa-check"></i> Active':'<i class="fas fa-times"></i> Inactive'}
        </button>
      </td>
      <td>
        <span class="access-chip ${(c.access_code||"").trim()==="" ? "unset" : ""}">${esc(c.access_code||"NOT SET")}</span>
        <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
          <button class="btn btn-edit btn-sm" onclick="openEditModal(${c.id})" title="Edit"><i class="fas fa-pen"></i></button>
          <button class="btn btn-code btn-sm" onclick="openQuickCode(${c.id})" title="Set Access Code"><i class="fas fa-key"></i></button>
          <button class="btn btn-danger btn-sm" onclick="deleteCollege(${c.id},'${esc(c.name)}')" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      </td>
    </tr>
  `).join('');

  // Update quick-code dropdown
  const qcSel = document.getElementById('qcCollege');
  if (qcSel) {
    qcSel.innerHTML = '<option value="">— Select College —</option>' +
      _colleges.map(c=>`<option value="${c.id}">${esc(c.name)} (${esc(c.code)})</option>`).join('');
  }
}

/* ─── Reload from server (called after create/update/delete) ─────── */
async function reloadColleges() {
  try {
    const d = await api({ajax_action:'ua_list_colleges'});
    if (d.ok) {
      _colleges = d.colleges || [];
      renderTable();
    } else {
      toast(d.msg || 'Could not refresh list — reload the page.', 'error');
    }
  } catch(e) {
    toast('Network error. Reload the page to see latest data.', 'error');
  }
}

/* ─── Modals ─────────────────────────────────────────────────────── */
function openCreateModal() {
  document.getElementById('editId').value = '';
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus" style="color:var(--teal)"></i> &nbsp;New College';
  document.getElementById('modalSaveBtn').innerHTML = '<i class="fas fa-save"></i> Save College';
  ['mName','mCode','mEmail','mPhone','mEstd','mAddress','mAccess'].forEach(id=>{ document.getElementById(id).value=''; });
  document.getElementById('statusField').style.display = 'none';
  document.getElementById('modalMsg').style.display = 'none';
  document.getElementById('collegeModal').classList.add('open');
}

function openEditModal(id) {
  const c = _colleges.find(x=>+x.id===+id);
  if (!c) return;
  document.getElementById('editId').value = c.id;
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--teal)"></i> &nbsp;Edit College';
  document.getElementById('modalSaveBtn').innerHTML = '<i class="fas fa-save"></i> Update College';
  document.getElementById('mName').value    = c.name||'';
  document.getElementById('mCode').value    = c.code||'';
  document.getElementById('mEmail').value   = c.email||'';
  document.getElementById('mPhone').value   = c.phone||'';
  document.getElementById('mEstd').value    = c.established||'';
  document.getElementById('mAddress').value = c.address||'';
  document.getElementById('mAccess').value  = c.access_code||'';
  document.getElementById('mStatus').value  = c.status||'active';
  document.getElementById('statusField').style.display = 'block';
  document.getElementById('modalMsg').style.display = 'none';
  document.getElementById('collegeModal').classList.add('open');
}

function closeModal() { document.getElementById('collegeModal').classList.remove('open'); }

async function saveCollege() {
  const editId = document.getElementById('editId').value;
  const name   = document.getElementById('mName').value.trim();
  const code   = document.getElementById('mCode').value.trim();
  const access = document.getElementById('mAccess').value.trim();
  const btn    = document.getElementById('modalSaveBtn');

  if (!name || !code)          { showModalMsg('College name and code are required.','error'); return; }
  if (!access || access.length < 4) { showModalMsg('Principal access code must be at least 4 characters.','error'); return; }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  const payload = {
    ajax_action: editId ? 'ua_update_college' : 'ua_create_college',
    id: editId, name, code,
    email:       document.getElementById('mEmail').value.trim(),
    phone:       document.getElementById('mPhone').value.trim(),
    address:     document.getElementById('mAddress').value.trim(),
    established: document.getElementById('mEstd').value.trim(),
    access_code: access,
    status:      document.getElementById('mStatus').value,
  };

  try {
    const d = await api(payload);
    btn.disabled = false;
    btn.innerHTML = `<i class="fas fa-save"></i> ${editId ? 'Update' : 'Save'} College`;
    if (d.ok) { toast(d.msg,'success'); closeModal(); reloadColleges(); }
    else { showModalMsg(d.msg||'Error saving college.','error'); }
  } catch(e) {
    btn.disabled = false;
    btn.innerHTML = `<i class="fas fa-save"></i> ${editId ? 'Update' : 'Save'} College`;
    showModalMsg('Network error. Check your connection.','error');
  }
}

function showModalMsg(msg, type) {
  const el = document.getElementById('modalMsg');
  el.style.display = 'block';
  el.style.background = type==='error' ? 'rgba(231,111,81,.12)' : 'rgba(45,212,170,.1)';
  el.style.border     = `1px solid ${type==='error' ? 'rgba(231,111,81,.3)' : 'rgba(45,212,170,.3)'}`;
  el.style.color      = type==='error' ? '#f4a6a6' : 'var(--green)';
  el.textContent      = msg;
}

async function toggleStatus(id) {
  try {
    const d = await api({ajax_action:'ua_toggle_college', id});
    if (d.ok) { toast(`Status changed to ${d.status}`,'success'); reloadColleges(); }
    else toast(d.msg||'Error','error');
  } catch(e) { toast('Network error','error'); }
}

async function deleteCollege(id, name) {
  if (!confirm(`Delete "${name}"?\n\nThis removes the college from the database. Linked records may be affected.\n\nProceed?`)) return;
  try {
    const d = await api({ajax_action:'ua_delete_college', id});
    if (d.ok) { toast(d.msg,'success'); reloadColleges(); }
    else toast(d.msg||'Error deleting.','error');
  } catch(e) { toast('Network error','error'); }
}

function openQuickCode(id) {
  const c = _colleges.find(x=>+x.id===+id);
  if (!c) return;
  document.getElementById('qcCollege').value = id;
  document.getElementById('qcCode').value = c.access_code||'';
  document.getElementById('qcCode').focus();
  document.getElementById('qcCollege').scrollIntoView({behavior:'smooth',block:'center'});
}

async function quickSetCode() {
  const id   = document.getElementById('qcCollege').value;
  const code = document.getElementById('qcCode').value.trim().toUpperCase();
  const msg  = document.getElementById('qcMsg');
  if (!id)          { msg.style.display='block';msg.style.color='#f4a6a6';msg.textContent='Please select a college.'; return; }
  if (code.length<4){ msg.style.display='block';msg.style.color='#f4a6a6';msg.textContent='Code must be at least 4 characters.'; return; }
  msg.style.display='block';msg.style.color='var(--muted)';msg.textContent='Saving…';
  try {
    const d = await api({ajax_action:'ua_set_access_code', id, access_code:code});
    if (d.ok) { msg.style.color='var(--green)'; msg.textContent='✓ '+d.msg; toast(d.msg,'success'); reloadColleges(); }
    else { msg.style.color='#f4a6a6'; msg.textContent=d.msg||'Error.'; toast(d.msg||'Error','error'); }
  } catch(e) { msg.style.color='#f4a6a6'; msg.textContent='Network error.'; }
}

document.getElementById('collegeModal')?.addEventListener('click', function(e){
  if (e.target === this) closeModal();
});
</script>
</body>
</html>