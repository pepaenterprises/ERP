<?php
// pages/expense_apply.php
// ─────────────────────────────────────────────────────────────────────────────
// FACULTY EXPENSE APPLICATION FORM — matches EduNexus dashboard UI
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']            ?? 'faculty';
$userId    = (int)($user['id']        ?? 0);
$collegeId = (int)($user['college_id']    ?? 0);
$deptId    = (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']       ?? 'Faculty';
$firstName = explode(' ', trim($fullName))[0];

if (!in_array($role, ['faculty', 'college_admin', 'super_admin'])) {
    header('Location: students.php'); exit;
}

function esc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtDate($d): string { return ($d && $d !== '0000-00-00') ? date('d M Y', strtotime($d)) : '—'; }
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):''));
}
$avatarInitials = initials($fullName);

$db = getDB();

// ── Avatar ────────────────────────────────────────────────────────────────────
$avatarPath  = '';
$avatarColor = '#00d4bb';
if ($db && $userId) {
    try {
        $stAv = $db->prepare('SELECT avatar_path, avatar_color FROM users WHERE id = ? LIMIT 1');
        $stAv->execute([$userId]);
        if ($avRow = $stAv->fetch(PDO::FETCH_ASSOC)) {
            $avatarColor = $avRow['avatar_color'] ?: '#00d4bb';
            $rawPath     = $avRow['avatar_path']  ?? '';
            if ($rawPath && file_exists(__DIR__ . '/../' . $rawPath))
                $avatarPath = '../' . $rawPath . '?v=' . time();
        }
    } catch(Exception $e) {}
}

// ── Load department assignments ───────────────────────────────────────────────
$myDeptIds   = [];
$myDeptNames = [];
$myDepts     = [];
$deptName    = 'Your Department';
$deptCode    = '';
$myCourses   = 0;
$pendingMarks  = 0;
$upcomingExams = 0;
$myStudents    = 0;
$collegeName   = 'Your College';
$deptCode      = '';

if ($db && $userId) {
    try {
        $st = $db->prepare('
            SELECT DISTINCT fa.department_id, fa.college_id,
                   d.name AS dept_name, d.code AS dept_code, d.hod_name,
                   c.name AS college_name, c.code AS college_code, fa.is_primary
            FROM   faculty_assignments fa
            JOIN   departments d ON d.id = fa.department_id
            JOIN   colleges    c ON c.id = fa.college_id
            WHERE  fa.faculty_id = ? AND fa.status = "active"
            ORDER  BY fa.is_primary DESC, d.name ASC
        ');
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $first = true;
        foreach ($rows as $r) {
            $did = (int)$r['department_id'];
            if (!in_array($did, $myDeptIds)) $myDeptIds[] = $did;
            if (!in_array($r['dept_name'], $myDeptNames)) $myDeptNames[] = $r['dept_name'];
            $myDepts[] = ['id' => $did, 'name' => $r['dept_name'], 'code' => $r['dept_code']];
            if ($first) {
                $collegeName = $r['college_name'];
                $deptName    = $r['dept_name'];
                $deptCode    = $r['dept_code'];
                $first = false;
            }
        }
    } catch(Exception $e) {}

    // Stats for sidebar badges
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM faculty_assignments WHERE faculty_id=? AND status="active" AND course_id IS NOT NULL');
        $st->execute([$userId]); $myCourses = (int)$st->fetchColumn();
    } catch(Exception $e) {}
    try {
        $st = $db->prepare('SELECT COUNT(DISTINCT m.id) FROM marks m JOIN exams e ON e.id=m.exam_id JOIN faculty_assignments fa ON fa.course_id=e.course_id WHERE fa.faculty_id=? AND fa.status="active" AND m.obtained_marks IS NULL AND m.is_absent=0');
        $st->execute([$userId]); $pendingMarks = (int)$st->fetchColumn();
    } catch(Exception $e) {}
    try {
        $st = $db->prepare('SELECT COUNT(DISTINCT e.id) FROM exams e JOIN faculty_assignments fa ON fa.course_id=e.course_id WHERE fa.faculty_id=? AND fa.status="active" AND e.exam_date>=CURDATE() AND e.status IN ("upcoming","ongoing")');
        $st->execute([$userId]); $upcomingExams = (int)$st->fetchColumn();
    } catch(Exception $e) {}
    if (!empty($myDeptIds)) {
        try {
            $ph = implode(',', array_fill(0, count($myDeptIds), '?'));
            $st = $db->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND department_id IN ($ph) AND status='active'");
            $st->execute($myDeptIds); $myStudents = (int)$st->fetchColumn();
        } catch(Exception $e) {}
    }
}

// ── Form submission ───────────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';
$newExpenseId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_expense'])) {
    $expenseTitle  = trim($_POST['expense_title']  ?? '');
    $category      = trim($_POST['category']       ?? '');
    $amount        = trim($_POST['amount']         ?? '');
    $currency      = trim($_POST['currency']       ?? 'INR');
    $expenseDate   = trim($_POST['expense_date']   ?? '');
    $reason        = trim($_POST['reason']         ?? '');
    $description   = trim($_POST['description']    ?? '');
    $priority      = trim($_POST['priority']       ?? 'normal');
    $paymentMode   = trim($_POST['payment_mode']   ?? '');
    $vendorName    = trim($_POST['vendor_name']    ?? '');
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $deptSel       = trim($_POST['department']     ?? $deptId);
    $projectCode   = trim($_POST['project_code']   ?? '');
    $attendees     = trim($_POST['attendees']      ?? '');
    $termsAccepted = isset($_POST['terms_accepted']);

    if (!$expenseTitle || !$category || !$amount || !$expenseDate || !$reason || !$paymentMode) {
        $errorMsg = 'Please fill in all required fields.';
    } elseif (!is_numeric($amount) || (float)$amount <= 0) {
        $errorMsg = 'Please enter a valid positive amount.';
    } elseif (!$termsAccepted) {
        $errorMsg = 'You must accept the declaration to submit.';
    } else {
        $receiptPath = null;
        if (!empty($_FILES['receipt']['name'])) {
            $allowed = ['jpg','jpeg','png','pdf','gif'];
            $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $errorMsg = 'Invalid receipt type. Allowed: JPG, PNG, PDF, GIF.';
            } elseif ($_FILES['receipt']['size'] > 5*1024*1024) {
                $errorMsg = 'Receipt must be under 5 MB.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/receipts/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = 'receipt_'.$userId.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $uploadDir.$filename))
                    $receiptPath = $filename;
                else
                    $errorMsg = 'Failed to upload receipt.';
            }
        }
        if (!$errorMsg) {
            $refId = 'EXP-'.date('Ymd').'-'.rand(1000,9999);
            try {
                if ($db) {
                    $stmt = $db->prepare("INSERT INTO expense_applications
                        (faculty_id,college_id,department_id,expense_title,category,amount,currency,
                         expense_date,reason,description,priority,payment_mode,vendor_name,
                         invoice_number,project_code,attendees,receipt_path,status,submitted_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',NOW())");
                    $stmt->execute([$userId,$collegeId,$deptSel,$expenseTitle,$category,$amount,$currency,
                        $expenseDate,$reason,$description,$priority,$paymentMode,$vendorName,
                        $invoiceNumber,$projectCode,$attendees,$receiptPath]);
                    $newExpenseId = $db->lastInsertId();
                    $refId = '#'.$newExpenseId;
                    $db->prepare("INSERT INTO activity_log(user_id,action,ip_address) VALUES(?,?,?)")
                       ->execute([$userId,"Submitted expense application $refId: $expenseTitle",$_SERVER['REMOTE_ADDR']??'']);
                }
            } catch(Exception $e) {}
            $successMsg = $refId;
        }
    }
}

// ── Recent expenses ───────────────────────────────────────────────────────────
$recentExpenses = [];
if ($db && $userId) {
    try {
        $st = $db->prepare("SELECT id,expense_title,category,amount,currency,expense_date,priority,status,submitted_at FROM expense_applications WHERE faculty_id=? ORDER BY submitted_at DESC LIMIT 6");
        $st->execute([$userId]);
        $recentExpenses = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expense Application – EduNexus ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ─────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === TEAL · LIGHT TEAL · SLATE · AMBER PALETTE (matches dashboard) === */
  --teal:#0F766E;
  --teal-dark:#0D5C56;
  --teal-mid:#0F766E;
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
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;
  --text:#0F172A;
  --text-muted:#475569;
  --muted:#475569;
  --text-light:#94A3B8;
  --border:rgba(15,118,110,.10);
  --border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;
  --card-hover:#F0FDFA;
  /* Aliases for form components */
  --teal2:var(--teal-dark);
  --teal3:var(--teal-soft);
  --teal4:var(--teal-soft2);
  --green:#16A34A;
  --navy:#0F172A;
  --sidebar-w:264px;--sb-collapsed-w:72px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  /* Sidebar glassmorphic vars */
  --sb-text:rgba(255,255,255,.78);
  --sb-text-active:#FFFFFF;
  --sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);
  --sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);
  --sb-active-border:rgba(245,158,11,.38);
}
html,body{height:100%;font-family:var(--font);background:#F0FAFA;color:var(--text);overflow-x:hidden}

/* ── Subtle teal bg ─────────────────────────────────────────────────────────── */
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background:linear-gradient(180deg,rgba(20,184,166,.04) 0%,transparent 40%);
}

/* ── Layout ─────────────────────────────────────────────────────────────────── */
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── SIDEBAR — Glassmorphic Deep Teal (matches dashboard) ───────────────────── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(
    168deg,
    rgba(13,92,86,.98) 0%,
    rgba(15,118,110,.95) 40%,
    rgba(17,140,130,.92) 72%,
    rgba(13,92,86,.98) 100%
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
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);
  pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);
  pointer-events:none}
.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}
.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12)}
.logo-mark{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15)}
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
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:var(--sb-text-active)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--teal-light);color:#fff;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.nav-badge.amber{background:var(--amber-acc);color:#fff}
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--amber-acc),var(--amber-dark));display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#fff}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.65rem;color:var(--sb-muted);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(239,68,68,.9)}
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
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i,.sidebar.collapsed a.nav-item.active i{color:#ffffff!important;opacity:1!important;visibility:visible!important;-webkit-text-fill-color:#ffffff!important}
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

/* ── Main ───────────────────────────────────────────────────────────────────── */
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

/* Topbar */
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);
  position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1;display:flex;align-items:center}
.topbar-title .tb-main{font-weight:700;color:#fff}
.topbar-title .tb-sep{color:rgba(255,255,255,.45);margin:0 7px;font-weight:300}
.topbar-title .tb-sub{color:rgba(255,255,255,.52);font-weight:400;font-size:.78rem}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);color:#ffffff;display:grid;place-items:center;cursor:pointer;transition:all .18s;position:relative;font-size:.82rem;text-decoration:none}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{height:36px;display:flex;align-items:center;font-size:.72rem;color:#ffffff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);padding:0 11px;border-radius:9px;font-family:var(--mono)}
.topbar-avatar-wrap{position:relative}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;cursor:pointer;border:2px solid rgba(245,158,11,.30);transition:border-color .2s,box-shadow .2s;user-select:none;flex-shrink:0}
.topbar-avatar:hover,.topbar-avatar.open{border-color:var(--amber-acc);box-shadow:0 0 0 3px rgba(245,158,11,.22)}
.avatar-dropdown{position:absolute;top:calc(100% + 10px);right:0;min-width:200px;background:var(--white);border:1px solid var(--border);border-radius:12px;padding:6px;box-shadow:0 16px 48px rgba(15,118,110,.12);z-index:200;opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease}
.avatar-dropdown.open{opacity:1;pointer-events:auto;transform:translateY(0)}
.ad-header{padding:10px 12px 8px;border-bottom:1px solid var(--border);margin-bottom:4px}
.ad-name{font-size:.84rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-role{font-size:.65rem;color:var(--teal);font-family:var(--mono);margin-top:2px}
.ad-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;color:var(--text-muted);font-size:.8rem;font-weight:500;text-decoration:none;transition:background .15s,color .15s;cursor:pointer;border:none;background:none;width:100%;text-align:left}
.ad-item i{width:15px;text-align:center;font-size:.78rem;color:var(--text-light);flex-shrink:0}
.ad-item:hover{background:var(--teal-soft);color:var(--teal)}
.ad-item:hover i{color:var(--teal)}
.ad-item.danger:hover{background:var(--red2);color:var(--red)}
.ad-item.danger:hover i{color:var(--red)}
.ad-sep{height:1px;background:var(--border);margin:4px 0}

/* ── Content ────────────────────────────────────────────────────────────────── */
.content{padding:20px 24px;flex:1}

/* ── Page header ────────────────────────────────────────────────────────────── */
.page-hd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap;animation:slideUp .4s ease both}
.page-hd-left{}
.page-title{font-size:1.3rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px}
.page-title-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--teal),var(--teal2));display:grid;place-items:center;color:#fff;font-size:.95rem;flex-shrink:0;box-shadow:0 4px 14px rgba(0,212,187,.3)}
.page-sub{font-size:.75rem;color:var(--muted);margin-top:5px}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;border:1px solid var(--border);background:var(--white);color:var(--text-muted);font-size:.78rem;font-weight:600;text-decoration:none;transition:all .18s;cursor:pointer}
.back-btn:hover{border-color:rgba(0,212,187,.3);color:var(--teal);background:var(--teal3)}

/* ── Steps bar ──────────────────────────────────────────────────────────────── */
.steps-bar{display:flex;align-items:center;background:var(--card);border:1px solid rgba(15,118,110,.13);border-radius:var(--radius);padding:14px 20px;margin-bottom:18px;overflow-x:auto;gap:0;animation:slideUp .4s .05s ease both;box-shadow:0 1px 6px rgba(15,118,110,.06)}
.step{display:flex;align-items:center;gap:9px;flex-shrink:0}
.step-circle{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-size:.68rem;font-weight:700;border:1.5px solid rgba(15,118,110,.18);color:var(--text-muted);background:#fff;flex-shrink:0;transition:all .3s;font-family:var(--mono)}
.step.active .step-circle{border-color:var(--teal);background:var(--teal);color:#fff;box-shadow:0 0 0 4px rgba(15,118,110,.12)}
.step.done .step-circle{border-color:var(--green);background:var(--green);color:#fff}
.step-info{}
.step-name{font-size:.74rem;font-weight:700;color:var(--text-muted)}
.step.active .step-name{color:var(--teal)}
.step.done .step-name{color:var(--green)}
.step-desc{font-size:.62rem;color:#1e293b;opacity:1}
.step-line{flex:1;height:1.5px;background:rgba(15,118,110,.12);min-width:16px;margin:0 8px}
.step-line.done{background:var(--green)}

/* ── Cards ──────────────────────────────────────────────────────────────────── */
.card{background:var(--card);border:1px solid rgba(15,118,110,.13);border-radius:var(--radius);box-shadow:0 1px 6px rgba(15,118,110,.07),0 0 0 0 transparent;overflow:hidden;margin-bottom:16px;animation:slideUp .45s .1s ease both}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid rgba(15,118,110,.10);background:linear-gradient(90deg,rgba(20,184,166,.05),transparent)}
.card-hd-left{display:flex;align-items:center;gap:10px}
.card-hd-icon{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;font-size:.8rem;flex-shrink:0}
.icon-teal{background:var(--teal-soft);color:var(--teal)}
.icon-amber{background:var(--amber-soft);color:var(--amber)}
.icon-purple{background:var(--purple2);color:var(--purple)}
.icon-blue{background:var(--blue2);color:var(--blue)}
.icon-green{background:var(--green2);color:var(--success)}
.icon-red{background:var(--red2);color:var(--red)}
.card-title{font-size:.88rem;font-weight:700;color:var(--text)}
.card-sub{font-size:.68rem;color:var(--text-muted);margin-top:1px}
.card-body{padding:20px}

/* ── Form elements ──────────────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.g3{grid-template-columns:1fr 1fr 1fr}
.col2{grid-column:span 2}
.col3{grid-column:span 3}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:.72rem;font-weight:700;color:var(--text-muted);letter-spacing:.04em;text-transform:uppercase;display:flex;align-items:center;gap:5px}
.form-label .req{color:var(--red);font-size:.68rem}
.form-label .tip{font-size:.62rem;padding:1px 6px;border-radius:5px;background:var(--teal-soft);color:var(--teal);font-weight:600;text-transform:none;letter-spacing:0}

.form-input,.form-select,.form-textarea{
  width:100%;padding:10px 13px;
  border:1px solid rgba(15,118,110,.15);border-radius:9px;
  font-size:.83rem;color:var(--text);background:var(--white);
  font-family:var(--font);transition:border-color .2s,box-shadow .2s;outline:none;
}
.form-input::placeholder,.form-textarea::placeholder{color:var(--text-light);font-size:.8rem}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-soft)}
.form-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23475569' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:34px}
.form-select option{background:var(--white);color:var(--text)}
.form-textarea{resize:vertical;min-height:88px;line-height:1.6}
.field-hint{font-size:.67rem;color:var(--text-muted);margin-top:1px;display:flex;align-items:center;gap:4px}
.field-hint i{color:var(--teal);font-size:.62rem}

/* Amount row */
.amt-row{display:flex}
.amt-prefix{padding:10px 12px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.15);border-right:none;border-radius:9px 0 0 9px;font-size:.75rem;font-weight:700;color:var(--teal);display:flex;align-items:center;white-space:nowrap}
.amt-row .form-select.curr{width:auto;min-width:72px;border-radius:0;border-left:none;border-right:none;font-size:.75rem;background-color:var(--white)}
.amt-row .form-input.amt{border-radius:0 9px 9px 0}

/* Priority selector */
.priority-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.p-opt{position:relative}
.p-opt input[type=radio]{position:absolute;opacity:0;width:0;height:0}
.p-label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:all .2s;font-size:.7rem;font-weight:700;color:var(--text-muted);text-align:center;background:var(--white)}
.p-label .p-ico{font-size:1.2rem}
.p-label .p-time{font-size:.6rem;font-weight:400;font-family:var(--mono);color:var(--text-light)}
.p-opt input:checked + .p-label{border-width:1.5px}
.p-low   input:checked + .p-label{border-color:var(--success);background:var(--green2);color:var(--success)}
.p-normal input:checked + .p-label{border-color:var(--teal);background:var(--teal-soft);color:var(--teal)}
.p-high  input:checked + .p-label{border-color:var(--amber);background:var(--amber-soft);color:var(--amber)}
.p-urgent input:checked + .p-label{border-color:var(--red);background:var(--red2);color:var(--red)}
.p-label:hover{border-color:var(--border-accent);background:var(--teal-soft)}

/* File upload */
.upload-zone{border:1.5px dashed rgba(15,118,110,.2);border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;background:rgba(20,184,166,.02)}
.upload-zone:hover,.upload-zone.drag{border-color:var(--teal);background:var(--teal-soft)}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-ico{font-size:2rem;color:var(--teal-light);margin-bottom:10px;opacity:.5}
.upload-title{font-size:.83rem;font-weight:600;color:var(--text)}
.upload-hint{font-size:.7rem;color:var(--text-muted);margin-top:4px}
.file-confirm{display:none;align-items:center;gap:10px;padding:10px 14px;background:var(--green2);border:1px solid rgba(22,163,74,.25);border-radius:9px;margin-top:10px;font-size:.78rem;color:var(--success);font-weight:600}
.file-confirm.on{display:flex}
.file-confirm i{font-size:.9rem}
.file-confirm .rm{margin-left:auto;background:none;border:none;color:var(--red);cursor:pointer;font-size:.85rem;padding:2px 5px}

/* Declaration checkbox */
.decl-box{display:flex;align-items:flex-start;gap:12px;padding:16px;background:var(--teal-soft);border:1px solid var(--border-accent);border-radius:10px}
.decl-box input[type=checkbox]{width:16px;height:16px;accent-color:var(--teal);margin-top:2px;flex-shrink:0;cursor:pointer}
.decl-text{font-size:.78rem;color:var(--text-muted);line-height:1.6}
.decl-text strong{color:var(--text)}

/* Submit row */
.submit-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:18px}
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:linear-gradient(135deg,var(--teal-light),var(--teal));color:#fff;border:none;border-radius:9px;font-size:.85rem;font-weight:800;cursor:pointer;transition:all .2s;font-family:var(--font);box-shadow:0 4px 16px rgba(20,184,166,.3)}
.btn-primary:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 20px rgba(20,184,166,.45)}
.btn-primary:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-ghost{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:var(--white);border:1px solid var(--border);border-radius:9px;font-size:.8rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all .18s;font-family:var(--font)}
.btn-ghost:hover{background:var(--red2);border-color:rgba(220,38,38,.3);color:var(--red)}
.submit-note{font-size:.7rem;color:var(--text-muted);display:flex;align-items:center;gap:5px}
.submit-note i{color:var(--teal);font-size:.65rem}

/* ── Alerts ─────────────────────────────────────────────────────────────────── */
.alert{padding:14px 18px;border-radius:10px;display:flex;align-items:flex-start;gap:12px;margin-bottom:18px;font-size:.82rem;line-height:1.5;animation:slideUp .3s ease}
.alert i{font-size:.95rem;margin-top:2px;flex-shrink:0}
.alert-success{background:var(--green2);border:1px solid rgba(22,163,74,.3);color:var(--success)}
.alert-error{background:var(--red2);border:1px solid rgba(220,38,38,.3);color:var(--red)}
.alert-warn{background:var(--amber-soft);border:1px solid rgba(217,119,6,.3);color:var(--amber)}

/* ── Char counter ───────────────────────────────────────────────────────────── */
.char-ctr{font-size:.65rem;color:var(--muted);text-align:right}
.char-ctr.warn{color:var(--amber)}
.char-ctr.over{color:var(--red);font-weight:700}

/* ── Live summary ───────────────────────────────────────────────────────────── */
.summary-box{display:none;background:rgba(0,212,187,.04);border:1px solid rgba(0,212,187,.18);border-radius:10px;padding:14px 16px;margin-top:16px}
.summary-box.on{display:block}
.summary-title{font-size:.72rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
.summary-item{}
.summary-key{font-size:.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px}
.summary-val{font-size:.78rem;font-weight:700;color:var(--text);font-family:var(--mono)}

/* ── Success overlay ────────────────────────────────────────────────────────── */
.success-card{text-align:center;padding:48px 32px}
.success-icon{width:68px;height:68px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:2rem;color:var(--green)}
.success-title{font-size:1.2rem;font-weight:800;color:var(--text);margin-bottom:8px}
.success-sub{font-size:.82rem;color:var(--muted);max-width:380px;margin:0 auto 10px}
.success-id{font-family:var(--mono);font-size:1rem;font-weight:700;color:var(--teal);background:var(--teal3);border:1px solid var(--teal4);border-radius:8px;padding:8px 20px;display:inline-block;margin:10px 0 22px}
.success-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

/* ── Table ──────────────────────────────────────────────────────────────────── */
.t-wrap{padding:0 0 4px;overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
tbody td{padding:11px 14px;font-size:.8rem;color:var(--text);vertical-align:middle}
tbody tr:not(:last-child) td{border-bottom:1px solid var(--border)}
tbody tr:hover td{background:var(--card-hover)}
.mono{font-family:var(--mono);font-size:.7rem;color:var(--muted)}
.s-name{font-size:.82rem;font-weight:600;color:var(--text)}
.pill{font-size:.63rem;font-weight:700;padding:3px 9px;border-radius:6px;display:inline-block}
.pill.pending{background:var(--amber2);color:var(--amber)}
.pill.approved{background:var(--green2);color:var(--green)}
.pill.rejected{background:var(--red2);color:var(--red)}
.pill.review{background:var(--purple2);color:var(--purple)}
.pill.disbursed{background:var(--blue2);color:var(--blue)}
.pill.low{background:var(--green2);color:var(--green)}
.pill.normal{background:var(--teal3);color:var(--teal)}
.pill.high{background:var(--amber2);color:var(--amber)}
.pill.urgent{background:var(--red2);color:var(--red)}
.empty-state{padding:32px;text-align:center;color:var(--muted);font-size:.8rem}
.empty-state i{font-size:1.8rem;display:block;margin-bottom:8px;opacity:.2}

/* ── Urgent notice ──────────────────────────────────────────────────────────── */
#urgentNotice{display:none;margin-top:14px}

/* ── Animations ─────────────────────────────────────────────────────────────── */
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Scrollbar ──────────────────────────────────────────────────────────────── */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(0,212,187,.3)}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%}
  .content{padding:14px}
  .hamburger{display:grid}
  .form-grid,.form-grid.g3{grid-template-columns:1fr}
  .col2,.col3{grid-column:span 1}
  .priority-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
  .priority-grid{grid-template-columns:1fr 1fr}
  .steps-bar{gap:0}
  .step-desc{display:none}
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="bg-grid"></div>

<div class="shell">

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════════ -->
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
    <!-- Assigned To chip: college name + dept(s) -->
    <div class="scope-chip">
      <div class="sc-label"><i class="fas fa-shield-halved"></i> Assigned To</div>
      <div class="sc-college"><?= esc($collegeName) ?></div>
      <div class="sc-depts">
        <?php if (!empty($myDeptNames)): ?>
          <?php foreach ($myDeptNames as $dn): ?>
            <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($dn) ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="sc-dept-tag"><i class="fas fa-sitemap"></i><?= esc($deptName) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <nav class="sidebar-nav" style="flex:1;min-height:0">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item" data-tip="Dashboard">
        <i class="fas fa-gauge-high"></i><span class="nav-text"> Dashboard</span>
      </a>
      <a href="students.php" class="nav-item" data-tip="Students">
        <i class="fas fa-user-graduate"></i><span class="nav-text"> Students</span>
        <?php if ($myStudents): ?><span class="nav-badge"><?= $myStudents ?></span><?php endif; ?>
      </a>

      <div class="nav-label nav-section-label">Academic</div>
      <a href="attendance.php" class="nav-item" data-tip="Attendance">
        <i class="fas fa-clipboard-check"></i><span class="nav-text"> Attendance</span>
      </a>
      <a href="grades_results.php" class="nav-item" data-tip="Grades &amp; Results">
        <i class="fas fa-chart-bar"></i><span class="nav-text"> Grades &amp; Results</span>
        <?php if ($pendingMarks): ?><span class="nav-badge red"><?= $pendingMarks ?></span><?php endif; ?>
      </a>
      <a href="examinations.php" class="nav-item" data-tip="Examinations">
        <i class="fas fa-file-invoice"></i><span class="nav-text"> Examinations</span>
        <?php if ($upcomingExams): ?><span class="nav-badge amber"><?= $upcomingExams ?></span><?php endif; ?>
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
      <a href="expense_apply.php" class="nav-item active" data-tip="Expense Apply">
        <i class="fas fa-receipt"></i><span class="nav-text"> Expense Apply</span>
      </a>

      <div class="nav-label nav-section-label">Account</div>
      <a href="profile.php" class="nav-item" data-tip="My Profile">
        <i class="fas fa-circle-user"></i><span class="nav-text"> My Profile</span>
      </a>
    </nav>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:'.$avatarColor ?>">
      <?php if ($avatarPath): ?>
        <img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt="">
      <?php else: ?><?= esc($avatarInitials) ?><?php endif; ?>
    </div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag">Faculty · <?= count($myDeptIds) > 1 ? count($myDeptIds).' Depts' : esc($deptCode ?: $deptName) ?></div>
    </div>
    <button class="logout-btn" title="Logout" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i></button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <span class="tb-main">Expense Application</span>
    </div>
    <div class="topbar-actions">
      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if ($pendingMarks || $upcomingExams): ?><span style="position:absolute;top:5px;right:5px;width:6px;height:6px;background:var(--amber-acc);border-radius:50%;border:1.5px solid #0F766E"></span><?php endif; ?>
      </div>
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)"
             style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:'.$avatarColor ?>">
          <?php if ($avatarPath): ?>
            <img src="<?= esc($avatarPath) ?>" alt="<?= esc($avatarInitials) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block">
          <?php else: ?><?= esc($avatarInitials) ?><?php endif; ?>
        </div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= esc($fullName) ?></div>
            <div class="ad-role"><i class="fas fa-chalkboard-user" style="margin-right:4px"></i><?= ucfirst(str_replace('_',' ',$role)) ?></div>
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

    <!-- Page Header -->
    <div class="page-hd">
      <div class="page-hd-left">
        <div class="page-title">
          <div class="page-title-icon"><i class="fas fa-file-invoice-dollar"></i></div>
          Expense Application
        </div>
        <div class="page-sub" style="color:#1e293b">Submit a reimbursement or advance expense request</div>
      </div>
      <!-- back-btn removed -->
    </div>

    <!-- Steps -->
    <div class="steps-bar">
      <div class="step active">
        <div class="step-circle">1</div>
        <div class="step-info"><div class="step-name">Fill Form</div><div class="step-desc">Enter details</div></div>
      </div>
      <div class="step-line"></div>
      <div class="step">
        <div class="step-circle">2</div>
        <div class="step-info"><div class="step-name">HOD Review</div><div class="step-desc">Head reviews</div></div>
      </div>
      <div class="step-line"></div>
      <div class="step">
        <div class="step-circle">3</div>
        <div class="step-info"><div class="step-name">Admin Approval</div><div class="step-desc">Principal approves</div></div>
      </div>
      <div class="step-line"></div>
      <div class="step">
        <div class="step-circle">4</div>
        <div class="step-info"><div class="step-name">Disbursement</div><div class="step-desc">Amount credited</div></div>
      </div>
    </div>

    <!-- Alerts -->
    <?php if ($errorMsg): ?>
    <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i><div><?= esc($errorMsg) ?></div></div>
    <?php endif; ?>

    <?php if ($successMsg): ?>
    <!-- ── SUCCESS STATE ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="success-card">
        <div class="success-icon"><i class="fas fa-circle-check"></i></div>
        <div class="success-title">Application Submitted!</div>
        <div class="success-sub">Your expense application has been sent for HOD review. You will be notified once a decision is made.</div>
        <div class="success-id"><?= esc($successMsg) ?></div>
        <div class="success-btns">
          <a href="expense_apply.php" class="btn-primary"><i class="fas fa-plus"></i> New Application</a>
          <a href="dashboard.php" class="btn-ghost" style="color:var(--text)"><i class="fas fa-grid-2"></i> Dashboard</a>
        </div>
      </div>
    </div>
    <?php else: ?>

    <!-- ── THE FORM ──────────────────────────────────────────────────────── -->
    <div class="alert alert-warn">
      <i class="fas fa-circle-info"></i>
      <div>Attach original receipts. Applications above ₹5,000 require HOD approval before Admin sign-off. Reimbursements are processed within 7 working days of full approval.</div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="expenseForm" novalidate>

      <!-- 1. Basic Info -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-teal"><i class="fas fa-pen-to-square"></i></div>
            <div><div class="card-title">Basic Information</div><div class="card-sub">Title, category and department</div></div>
          </div>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group col2">
              <label class="form-label">Expense Title <span class="req">*</span></label>
              <input type="text" name="expense_title" class="form-input" maxlength="150" required
                     placeholder="e.g. Lab Equipment Purchase, Conference Registration, Stationery"
                     value="<?= esc($_POST['expense_title'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Category <span class="req">*</span></label>
              <select name="category" class="form-select" required>
                <option value="">— Select category —</option>
                <optgroup label="Travel & Transport">
                  <option value="travel_local"        <?= (($_POST['category']??'')==='travel_local')        ?'selected':'' ?>>Local Travel</option>
                  <option value="travel_intercity"    <?= (($_POST['category']??'')==='travel_intercity')    ?'selected':'' ?>>Inter-city Travel</option>
                  <option value="travel_international"<?= (($_POST['category']??'')==='travel_international')?'selected':'' ?>>International Travel</option>
                </optgroup>
                <optgroup label="Academic & Research">
                  <option value="conference"    <?= (($_POST['category']??'')==='conference')    ?'selected':'' ?>>Conference / Seminar</option>
                  <option value="workshop"      <?= (($_POST['category']??'')==='workshop')      ?'selected':'' ?>>Workshop / Training</option>
                  <option value="research"      <?= (($_POST['category']??'')==='research')      ?'selected':'' ?>>Research Material</option>
                  <option value="books"         <?= (($_POST['category']??'')==='books')         ?'selected':'' ?>>Books / Journals</option>
                  <option value="lab_equipment" <?= (($_POST['category']??'')==='lab_equipment') ?'selected':'' ?>>Lab Equipment</option>
                </optgroup>
                <optgroup label="Office & Admin">
                  <option value="stationery"    <?= (($_POST['category']??'')==='stationery')   ?'selected':'' ?>>Stationery / Supplies</option>
                  <option value="printing"      <?= (($_POST['category']??'')==='printing')     ?'selected':'' ?>>Printing / Photocopy</option>
                  <option value="communication" <?= (($_POST['category']??'')==='communication')?'selected':'' ?>>Communication / Internet</option>
                  <option value="software"      <?= (($_POST['category']??'')==='software')     ?'selected':'' ?>>Software / Licenses</option>
                </optgroup>
                <optgroup label="Events">
                  <option value="student_event" <?= (($_POST['category']??'')==='student_event')?'selected':'' ?>>Student Event / Activity</option>
                  <option value="sports"        <?= (($_POST['category']??'')==='sports')       ?'selected':'' ?>>Sports & Competitions</option>
                  <option value="cultural"      <?= (($_POST['category']??'')==='cultural')     ?'selected':'' ?>>Cultural Program</option>
                  <option value="guest_lecture" <?= (($_POST['category']??'')==='guest_lecture')?'selected':'' ?>>Guest Lecture / Expert Visit</option>
                </optgroup>
                <optgroup label="Other">
                  <option value="food"        <?= (($_POST['category']??'')==='food')       ?'selected':'' ?>>Food & Refreshments</option>
                  <option value="medical"     <?= (($_POST['category']??'')==='medical')    ?'selected':'' ?>>Medical / Health</option>
                  <option value="maintenance" <?= (($_POST['category']??'')==='maintenance')?'selected':'' ?>>Maintenance / Repair</option>
                  <option value="other"       <?= (($_POST['category']??'')==='other')      ?'selected':'' ?>>Other</option>
                </optgroup>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Department <span class="req">*</span></label>
              <?php if (!empty($myDepts)): ?>
              <select name="department" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($myDepts as $d): ?>
                <option value="<?= esc($d['id']) ?>" <?= (($_POST['department']??'')==$d['id'])?'selected':'' ?>>
                  <?= esc($d['name']) ?> (<?= esc($d['code']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="text" name="department" class="form-input" placeholder="Department"
                     value="<?= esc($_POST['department'] ?? $deptId) ?>">
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label">Expense Date <span class="req">*</span></label>
              <input type="date" name="expense_date" class="form-input" max="<?= $today ?>" required
                     value="<?= esc($_POST['expense_date'] ?? $today) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Project / Event Code <span class="tip">Optional</span></label>
              <input type="text" name="project_code" class="form-input"
                     placeholder="e.g. PROJ-2026-01, EVT-CS-023"
                     value="<?= esc($_POST['project_code'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- 2. Amount & Payment -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-amber"><i class="fas fa-indian-rupee-sign"></i></div>
            <div><div class="card-title">Amount & Payment</div><div class="card-sub">Expense amount, mode and vendor info</div></div>
          </div>
        </div>
        <div class="card-body">
          <div class="form-grid g3">
            <div class="form-group col2">
              <label class="form-label">Amount <span class="req">*</span></label>
              <div class="amt-row">
                <span class="amt-prefix"><i class="fas fa-indian-rupee-sign"></i></span>
                <select name="currency" class="form-select curr">
                  <option value="INR" <?= (($_POST['currency']??'INR')==='INR')?'selected':'' ?>>INR</option>
                  <option value="USD" <?= (($_POST['currency']??'')==='USD')?'selected':'' ?>>USD</option>
                  <option value="EUR" <?= (($_POST['currency']??'')==='EUR')?'selected':'' ?>>EUR</option>
                </select>
                <input type="number" name="amount" class="form-input amt"
                       placeholder="0.00" min="1" step="0.01" required
                       value="<?= esc($_POST['amount'] ?? '') ?>">
              </div>
              <div class="field-hint"><i class="fas fa-circle-info"></i> Amounts above ₹5,000 require additional HOD approval</div>
            </div>

            <div class="form-group">
              <label class="form-label">Payment Mode <span class="req">*</span></label>
              <select name="payment_mode" class="form-select" required>
                <option value="">— Select —</option>
                <option value="cash"          <?= (($_POST['payment_mode']??'')==='cash')         ?'selected':'' ?>>Cash</option>
                <option value="upi"           <?= (($_POST['payment_mode']??'')==='upi')          ?'selected':'' ?>>UPI / Google Pay</option>
                <option value="bank_transfer" <?= (($_POST['payment_mode']??'')==='bank_transfer')?'selected':'' ?>>Bank Transfer / NEFT</option>
                <option value="cheque"        <?= (($_POST['payment_mode']??'')==='cheque')       ?'selected':'' ?>>Cheque</option>
                <option value="credit_card"   <?= (($_POST['payment_mode']??'')==='credit_card')  ?'selected':'' ?>>Credit / Debit Card</option>
                <option value="dd"            <?= (($_POST['payment_mode']??'')==='dd')           ?'selected':'' ?>>Demand Draft</option>
                <option value="advance"       <?= (($_POST['payment_mode']??'')==='advance')      ?'selected':'' ?>>Advance Required</option>
              </select>
            </div>

            <div class="form-group col2">
              <label class="form-label">Vendor / Supplier Name <span class="tip">Optional</span></label>
              <input type="text" name="vendor_name" class="form-input"
                     placeholder="Name of shop, vendor or service provider"
                     value="<?= esc($_POST['vendor_name'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Invoice / Bill Number <span class="tip">Optional</span></label>
              <input type="text" name="invoice_number" class="form-input"
                     placeholder="e.g. INV-2026-0042"
                     value="<?= esc($_POST['invoice_number'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- 3. Justification -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-purple"><i class="fas fa-file-lines"></i></div>
            <div><div class="card-title">Justification</div><div class="card-sub">Reason and supporting context</div></div>
          </div>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group col2">
              <label class="form-label">Reason for Expense <span class="req">*</span></label>
              <input type="text" name="reason" class="form-input" maxlength="200" required
                     placeholder="Brief one-line reason (e.g. Purchased whiteboard markers for classroom use)"
                     value="<?= esc($_POST['reason'] ?? '') ?>">
            </div>

            <div class="form-group col2">
              <label class="form-label">Detailed Description <span class="tip">Recommended</span></label>
              <textarea name="description" class="form-textarea" id="descTA" maxlength="1000"
                        placeholder="Full context: what was purchased/attended, how it benefits students or the department, alternatives considered, etc."><?= esc($_POST['description'] ?? '') ?></textarea>
              <div class="char-ctr" id="charCtr">0 / 1000</div>
            </div>

            <div class="form-group col2">
              <label class="form-label">Attendees / Beneficiaries <span class="tip">If applicable</span></label>
              <input type="text" name="attendees" class="form-input"
                     placeholder="e.g. 45 students from CSE 3rd year, All faculty of Physics dept"
                     value="<?= esc($_POST['attendees'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- 4. Priority -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-blue"><i class="fas fa-gauge-high"></i></div>
            <div><div class="card-title">Priority & Urgency</div><div class="card-sub">How quickly this needs to be processed</div></div>
          </div>
        </div>
        <div class="card-body">
          <label class="form-label" style="margin-bottom:12px">Request Priority <span class="req">*</span></label>
          <div class="priority-grid">
            <div class="p-opt p-low">
              <input type="radio" name="priority" id="p_low" value="low" <?= (($_POST['priority']??'normal')==='low')?'checked':'' ?>>
              <label for="p_low" class="p-label">
                <span class="p-ico">🟢</span><span>Low</span><span class="p-time">14+ days</span>
              </label>
            </div>
            <div class="p-opt p-normal">
              <input type="radio" name="priority" id="p_normal" value="normal" <?= (($_POST['priority']??'normal')==='normal')?'checked':'' ?>>
              <label for="p_normal" class="p-label">
                <span class="p-ico">🔵</span><span>Normal</span><span class="p-time">7–14 days</span>
              </label>
            </div>
            <div class="p-opt p-high">
              <input type="radio" name="priority" id="p_high" value="high" <?= (($_POST['priority']??'normal')==='high')?'checked':'' ?>>
              <label for="p_high" class="p-label">
                <span class="p-ico">🟡</span><span>High</span><span class="p-time">3–7 days</span>
              </label>
            </div>
            <div class="p-opt p-urgent">
              <input type="radio" name="priority" id="p_urgent" value="urgent" <?= (($_POST['priority']??'normal')==='urgent')?'checked':'' ?>>
              <label for="p_urgent" class="p-label">
                <span class="p-ico">🔴</span><span>Urgent</span><span class="p-time">Within 48hrs</span>
              </label>
            </div>
          </div>
          <div class="alert alert-error" id="urgentNotice">
            <i class="fas fa-triangle-exclamation"></i>
            <div>Urgent requests require direct HOD approval and strong justification. Misuse of urgent priority may result in rejection.</div>
          </div>
        </div>
      </div>

      <!-- 5. Receipt -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-green"><i class="fas fa-paperclip"></i></div>
            <div><div class="card-title">Receipt / Supporting Document</div><div class="card-sub">Bill, invoice or proof of payment</div></div>
          </div>
        </div>
        <div class="card-body">
          <div class="upload-zone" id="uploadZone">
            <input type="file" name="receipt" id="receiptFile" accept=".jpg,.jpeg,.png,.pdf,.gif" onchange="handleFile(this)">
            <div id="uploadPH">
              <div class="upload-ico"><i class="fas fa-cloud-arrow-up"></i></div>
              <div class="upload-title">Click to upload or drag & drop</div>
              <div class="upload-hint">JPG, PNG, PDF or GIF · Max 5 MB</div>
            </div>
          </div>
          <div class="file-confirm" id="fileConfirm">
            <i class="fas fa-file-circle-check"></i>
            <span id="fileName">—</span>
            <button type="button" class="rm" onclick="clearFile()"><i class="fas fa-xmark"></i></button>
          </div>
          <div style="margin-top:14px" class="alert alert-warn">
            <i class="fas fa-lightbulb"></i>
            <div>Ensure the receipt shows vendor name, date, amount and your name/signature. Originals required for reimbursements above ₹500.</div>
          </div>
        </div>
      </div>

      <!-- 6. Declaration & Submit -->
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <div class="card-hd-icon icon-teal"><i class="fas fa-shield-halved"></i></div>
            <div><div class="card-title">Declaration & Submission</div><div class="card-sub">Review and confirm before submitting</div></div>
          </div>
        </div>
        <div class="card-body">

          <div class="summary-box" id="summaryBox">
            <div class="summary-title"><i class="fas fa-list-check"></i> Application Summary</div>
            <div class="summary-grid" id="summaryGrid"></div>
          </div>

          <div class="decl-box" style="margin-top:16px">
            <input type="checkbox" name="terms_accepted" id="termsCheck" required>
            <label for="termsCheck" class="decl-text">
              I, <strong><?= esc($fullName) ?></strong>, declare that the information provided is
              <strong>true and correct</strong> to the best of my knowledge. This expense was incurred
              in official capacity for legitimate college/department purposes.
              I understand that <strong>false or fraudulent claims</strong> may result in disciplinary action.
            </label>
          </div>

          <div class="submit-row">
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button type="submit" name="submit_expense" class="btn-primary" id="submitBtn" disabled>
                <i class="fas fa-paper-plane"></i> Submit Application
              </button>
              <button type="reset" class="btn-ghost" onclick="resetForm()" style="background:var(--teal-soft);color:var(--teal);border-color:rgba(15,118,110,.25)">
                <i class="fas fa-rotate-left"></i> Reset
              </button>
            </div>
            <div class="submit-note"><i class="fas fa-lock"></i> Forwarded to HOD after submission</div>
          </div>
        </div>
      </div>

    </form>
    <?php endif; ?>

    <!-- Recent Applications -->
    <div class="card" style="animation-delay:.25s">
      <div class="card-hd">
        <div class="card-hd-left">
          <div class="card-hd-icon icon-blue"><i class="fas fa-clock-rotate-left"></i></div>
          <div><div class="card-title">My Recent Applications</div><div class="card-sub">Last 6 submitted expense requests</div></div>
        </div>
      </div>
      <?php if (empty($recentExpenses)): ?>
      <div class="empty-state"><i class="fas fa-folder-open"></i>No expense applications yet.</div>
      <?php else: ?>
      <div style="padding:0 20px 16px">
        <div class="t-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Title</th><th>Category</th><th>Amount</th><th>Date</th><th>Priority</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentExpenses as $ex): ?>
              <tr>
                <td><span class="mono">#<?= esc($ex['id']) ?></span></td>
                <td><span class="s-name"><?= esc(mb_strimwidth($ex['expense_title'],0,28,'…')) ?></span></td>
                <td><span class="mono"><?= esc(str_replace('_',' ',ucfirst($ex['category']))) ?></span></td>
                <td><span style="font-weight:700;color:var(--text);font-family:var(--mono)"><?= esc($ex['currency']??'INR') ?> <?= number_format((float)$ex['amount'],2) ?></span></td>
                <td class="mono"><?= esc(date('d M Y',strtotime($ex['expense_date']))) ?></td>
                <td><span class="pill <?= esc($ex['priority']) ?>"><?= ucfirst(esc($ex['priority'])) ?></span></td>
                <td><span class="pill <?= esc($ex['status']) ?>"><?= ucfirst(esc($ex['status'])) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ══ SQL (run once in your DB) ═══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `expense_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `college_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `expense_title` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(5) NOT NULL DEFAULT 'INR',
  `expense_date` date NOT NULL,
  `reason` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `payment_mode` varchar(30) DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `invoice_number` varchar(60) DEFAULT NULL,
  `project_code` varchar(60) DEFAULT NULL,
  `attendees` varchar(250) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','review','approved','rejected','disbursed') NOT NULL DEFAULT 'pending',
  `hod_remarks` text DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `disbursed_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `faculty_id` (`faculty_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
══════════════════════════════════════════════════════════════════════════════ -->

<script>
/* ── Clock ───────────────────────────────────────────────────────────────── */
function tick(){
  const now=new Date();
  // topbarDate removed
  if(el) el.textContent=now.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
tick(); setInterval(tick,10000);

/* ── Sidebar collapse (desktop) ──────────────────────────────────────────── */
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

/* ── Sidebar toggle ──────────────────────────────────────────────────────── */
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  const mt=document.getElementById('menuToggle');
  if(window.innerWidth<=800 && sb.classList.contains('open') && !sb.contains(e.target) && mt && !mt.contains(e.target))
    sb.classList.remove('open');
});

/* ── Avatar dropdown ─────────────────────────────────────────────────────── */
function toggleAvatarMenu(e){
  e.stopPropagation();
  const av=document.getElementById('topbarAvatar');
  const dd=document.getElementById('avatarDropdown');
  const open=dd.classList.toggle('open');
  av.classList.toggle('open',open);
}
document.addEventListener('click',function(e){
  const wrap=document.querySelector('.topbar-avatar-wrap');
  if(wrap && !wrap.contains(e.target)){
    document.getElementById('avatarDropdown').classList.remove('open');
    document.getElementById('topbarAvatar').classList.remove('open');
  }
});

/* ── Logout ──────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const res=await fetch('../auth/auth_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
    const data=await res.json();
    if(data.redirect) window.location.href=data.redirect;
  } catch{ window.location.href='../login.php'; }
}

/* ── File upload ─────────────────────────────────────────────────────────── */
function handleFile(input){
  if(input.files && input.files[0]){
    const f=input.files[0];
    if(f.size>5*1024*1024){ alert('File too large. Max 5 MB.'); input.value=''; return; }
    document.getElementById('fileName').textContent=f.name+' ('+(f.size/1024).toFixed(1)+' KB)';
    document.getElementById('fileConfirm').classList.add('on');
    document.getElementById('uploadPH').style.opacity='.3';
  }
}
function clearFile(){
  document.getElementById('receiptFile').value='';
  document.getElementById('fileConfirm').classList.remove('on');
  document.getElementById('uploadPH').style.opacity='1';
}
const zone=document.getElementById('uploadZone');
if(zone){
  zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('drag')});
  zone.addEventListener('dragleave',()=>zone.classList.remove('drag'));
  zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('drag')});
}

/* ── Char counter ────────────────────────────────────────────────────────── */
const ta=document.getElementById('descTA');
const cc=document.getElementById('charCtr');
if(ta && cc){
  ta.addEventListener('input',function(){
    const l=this.value.length;
    cc.textContent=l+' / 1000';
    cc.className='char-ctr'+(l>900?' over':l>750?' warn':'');
  });
  ta.dispatchEvent(new Event('input'));
}

/* ── Priority urgent notice ──────────────────────────────────────────────── */
document.querySelectorAll('input[name="priority"]').forEach(r=>{
  r.addEventListener('change',function(){
    document.getElementById('urgentNotice').style.display=this.value==='urgent'?'flex':'none';
  });
});

/* ── Terms → enable submit + show summary ─────────────────────────────────── */
const termsCheck=document.getElementById('termsCheck');
const submitBtn=document.getElementById('submitBtn');
if(termsCheck){
  termsCheck.addEventListener('change',function(){
    submitBtn.disabled=!this.checked;
    buildSummary(this.checked);
  });
}

function val(n){
  const el=document.querySelector('[name="'+n+'"]');
  if(!el) return '—';
  if(el.tagName==='SELECT') return el.options[el.selectedIndex]?.text||'—';
  return el.value||'—';
}
function buildSummary(show){
  const box=document.getElementById('summaryBox');
  if(!box) return;
  if(!show){ box.classList.remove('on'); return; }
  const fields=[
    ['Title',val('expense_title')],
    ['Category',val('category')],
    ['Amount',val('currency')+' '+(parseFloat(val('amount'))||0).toFixed(2)],
    ['Date',val('expense_date')],
    ['Priority',val('priority')],
    ['Payment',val('payment_mode')],
  ];
  document.getElementById('summaryGrid').innerHTML=fields.map(([k,v])=>
    `<div class="summary-item"><div class="summary-key">${k}</div><div class="summary-val">${v}</div></div>`
  ).join('');
  box.classList.add('on');
}

/* Watch fields for summary update */
['expense_title','category','amount','currency','expense_date','priority','payment_mode'].forEach(n=>{
  const el=document.querySelector('[name="'+n+'"]');
  if(el) el.addEventListener(el.tagName==='SELECT'?'change':'input',()=>{ if(termsCheck?.checked) buildSummary(true); });
});

/* ── Reset ───────────────────────────────────────────────────────────────── */
function resetForm(){
  clearFile();
  if(document.getElementById('summaryBox')) document.getElementById('summaryBox').classList.remove('on');
  document.getElementById('urgentNotice').style.display='none';
  if(submitBtn) submitBtn.disabled=true;
  if(termsCheck) termsCheck.checked=false;
  if(cc) cc.textContent='0 / 1000';
}

/* ── Client-side validation ──────────────────────────────────────────────── */
const form=document.getElementById('expenseForm');
if(form){
  form.addEventListener('submit',function(e){
    const required=this.querySelectorAll('[required]');
    let ok=true;
    required.forEach(el=>{
      const v=el.type==='checkbox'?el.checked:!!el.value.trim();
      el.style.outline=v?'':'1.5px solid var(--red)';
      if(!v){ ok=false; if(ok!==false) el.scrollIntoView({behavior:'smooth',block:'center'}); }
    });
    if(!ok){ e.preventDefault(); }
  });
}
</script>
</body>
</html>