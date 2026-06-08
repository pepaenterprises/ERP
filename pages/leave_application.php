<?php
// pages/leave_application.php
// ─────────────────────────────────────────────────────────────────────────────
// LEAVE APPLICATION — Apply · View · Cancel (staff)  |  Approve/Reject (admin)
// Matches EduNexus dark theme from dashboard.php
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$user      = currentUser();
$role      = $user['role']          ?? 'faculty';
$userId    = (int)($user['id']      ?? 0);
$collegeId = (int)($user['college_id']    ?? 0);
$deptId    = (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']     ?? 'User';

$db      = getDB();
$isAdmin    = in_array($role, ['college_admin', 'super_admin']);
$isHod      = ($role === 'hod');
$isPrincipal = ($role === 'principal');

// Lazy migration: ensure new columns exist
if ($db) {
    try { $db->query("SELECT hod_reviewed_by FROM leave_applications LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("ALTER TABLE leave_applications
            ADD COLUMN `hod_reviewed_by`  INT DEFAULT NULL AFTER reviewed_by,
            ADD COLUMN `hod_reviewed_at`  DATETIME DEFAULT NULL AFTER hod_reviewed_by,
            ADD COLUMN `hod_remarks`      TEXT DEFAULT NULL AFTER hod_reviewed_at");
    }
    // Extend status ENUM to include hod_pending if not already
    try {
        $db->exec("ALTER TABLE leave_applications
            MODIFY COLUMN `status` ENUM('pending','hod_pending','approved','rejected','cancelled')
            NOT NULL DEFAULT 'pending'");
    } catch (Exception $ignored) {}
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function initials(string $n): string {
    $p = explode(' ', trim($n));
    return strtoupper(substr($p[0],0,1).(isset($p[1])?substr($p[1],0,1):''));
}
$avatarInitials = initials($fullName);

// Fetch avatar photo & colour
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

// Fetch college & department names for sidebar scope chip
$collegeName = 'Your College';
$deptName    = 'Your Department';
$deptCode    = '';
if ($db) {
    if ($collegeId) {
        $stC = $db->prepare('SELECT name FROM colleges WHERE id = ? LIMIT 1');
        $stC->execute([$collegeId]);
        if ($cRow = $stC->fetch(PDO::FETCH_ASSOC)) $collegeName = $cRow['name'];
    }
    if ($deptId) {
        $stD = $db->prepare('SELECT name, code FROM departments WHERE id = ? LIMIT 1');
        $stD->execute([$deptId]);
        if ($dRow = $stD->fetch(PDO::FETCH_ASSOC)) {
            $deptName = $dRow['name'];
            $deptCode = $dRow['code'] ?? '';
        }
    }
}

$leaveTypes = [
    'casual'        => 'Casual Leave',
    'medical'       => 'Medical Leave',
    'earned'        => 'Earned Leave',
    'maternity'     => 'Maternity Leave',
    'paternity'     => 'Paternity Leave',
    'compensatory'  => 'Compensatory Leave',
    'duty'          => 'On Duty',
    'unpaid'        => 'Unpaid Leave',
    'other'         => 'Other',
];

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── Apply for leave (any staff) ───────────────────────────────────────────
    if ($action === 'apply_leave') {
        $leaveType   = trim($_POST['leave_type']   ?? 'casual');
        $fromDate    = trim($_POST['from_date']    ?? '');
        $toDate      = trim($_POST['to_date']      ?? '');
        $reason      = trim($_POST['reason']       ?? '');
        $halfDay     = (int)($_POST['half_day']    ?? 0);
        $halfSession = trim($_POST['half_day_session'] ?? '') ?: null;
        $contact     = trim($_POST['contact']      ?? '') ?: null;
        $alternate   = trim($_POST['alternate']    ?? '') ?: null;

        if (!$fromDate || !$toDate || !$reason) {
            echo json_encode(['success'=>false,'message'=>'From date, to date, and reason are required.']);
            exit;
        }
        if (strtotime($toDate) < strtotime($fromDate)) {
            echo json_encode(['success'=>false,'message'=>'To date cannot be before From date.']);
            exit;
        }

        // Calculate working days (simple calendar diff; a real system could skip Sundays)
        $diff = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
        $days = $halfDay ? 0.5 : max(1, $diff);

        try {
            $st = $db->prepare('
                INSERT INTO leave_applications
                    (applicant_id,college_id,department_id,leave_type,from_date,to_date,total_days,
                     half_day,half_day_session,reason,contact_during_leave,alternate_arrangement)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $st->execute([
                $userId,$collegeId,$deptId,$leaveType,
                $fromDate,$toDate,$days,
                $halfDay,$halfSession,$reason,$contact,$alternate
            ]);
            echo json_encode(['success'=>true,'message'=>'Leave application submitted successfully.']);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── Cancel own application ────────────────────────────────────────────────
    if ($action === 'cancel_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        try {
            $st = $db->prepare('UPDATE leave_applications SET status="cancelled" WHERE id=? AND applicant_id=? AND status="pending"');
            $st->execute([$lid, $userId]);
            if ($st->rowCount())
                echo json_encode(['success'=>true,'message'=>'Application cancelled.']);
            else
                echo json_encode(['success'=>false,'message'=>'Cannot cancel this application (may already be reviewed).']);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── Approve / Reject (college_admin → sets hod_pending) ──────────────────
    if (in_array($action,['approve_leave','reject_leave']) && $isAdmin) {
        $lid     = (int)($_POST['leave_id']      ?? 0);
        $remarks = trim($_POST['review_remarks'] ?? '');
        // Admin approving forwards to HOD; admin rejecting ends it
        $newStatus = $action === 'approve_leave' ? 'hod_pending' : 'rejected';

        try {
            $st = $db->prepare('
                UPDATE leave_applications
                SET status=?, reviewed_by=?, reviewed_at=NOW(), review_remarks=?
                WHERE id=? AND college_id=? AND status="pending"
            ');
            $st->execute([$newStatus, $userId, $remarks, $lid, $collegeId]);
            if ($st->rowCount()) {
                $msg = $action === 'approve_leave'
                    ? 'Application forwarded to HOD for final approval.'
                    : 'Application rejected.';
                echo json_encode(['success'=>true,'message'=>$msg]);
            } else {
                echo json_encode(['success'=>false,'message'=>'Application not found or already reviewed.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── HOD Approve / Reject (hod_pending → approved/rejected) ───────────────
    if (in_array($action,['hod_approve_leave','hod_reject_leave']) && ($isHod || $isAdmin)) {
        $lid     = (int)($_POST['leave_id']      ?? 0);
        $remarks = trim($_POST['review_remarks'] ?? '');
        $newStatus = $action === 'hod_approve_leave' ? 'approved' : 'rejected';

        try {
            // HOD can only act on leaves from their own dept in their college
            $whereExtra = $isHod
                ? 'AND department_id=? AND college_id=?'
                : 'AND college_id=?';
            $params = $isHod
                ? [$newStatus, $userId, $remarks, $lid, $deptId, $collegeId]
                : [$newStatus, $userId, $remarks, $lid, $collegeId];

            $st = $db->prepare("
                UPDATE leave_applications
                SET status=?, hod_reviewed_by=?, hod_reviewed_at=NOW(), hod_remarks=?
                WHERE id=? AND status='hod_pending' $whereExtra
            ");
            $st->execute($params);
            if ($st->rowCount())
                echo json_encode(['success'=>true,'message'=>'Application '.$newStatus.' by HOD.']);
            else
                echo json_encode(['success'=>false,'message'=>'Application not found or not awaiting HOD review.']);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Invalid action or insufficient permissions.']);
    exit;
}

// ── Fetch leave records ───────────────────────────────────────────────────────
$applications = [];
$stats = ['total'=>0,'pending'=>0,'hod_pending'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0];

if ($db) {
    $fStatus = $_GET['status'] ?? 'all';
    $fType   = $_GET['type']   ?? 'all';

    $params = [];

    if ($isAdmin) {
        // Admin sees all applications in their college
        $sql = '
            SELECT la.*,
                   u.full_name  AS applicant_name, u.email AS applicant_email,
                   u.designation, u.role AS applicant_role,
                   d.name AS dept_name,
                   rv.full_name AS reviewer_name,
                   hv.full_name AS hod_reviewer_name
            FROM leave_applications la
            JOIN users       u  ON u.id  = la.applicant_id
            LEFT JOIN departments d  ON d.id  = la.department_id
            LEFT JOIN users       rv ON rv.id = la.reviewed_by
            LEFT JOIN users       hv ON hv.id = la.hod_reviewed_by
            WHERE la.college_id = ?
        ';
        $params[] = $collegeId;
    } elseif ($isHod) {
        // HOD sees leaves from their department in their college
        $sql = '
            SELECT la.*,
                   u.full_name  AS applicant_name, u.email AS applicant_email,
                   u.designation, u.role AS applicant_role,
                   d.name AS dept_name,
                   rv.full_name AS reviewer_name,
                   hv.full_name AS hod_reviewer_name
            FROM leave_applications la
            JOIN users       u  ON u.id  = la.applicant_id
            LEFT JOIN departments d  ON d.id  = la.department_id
            LEFT JOIN users       rv ON rv.id = la.reviewed_by
            LEFT JOIN users       hv ON hv.id = la.hod_reviewed_by
            WHERE la.college_id = ? AND la.department_id = ?
              AND la.status IN (\'hod_pending\',\'approved\',\'rejected\')
        ';
        $params[] = $collegeId;
        $params[] = $deptId;
    } elseif ($isPrincipal) {
        // Principal can view ALL leaves in their college (read-only)
        $sql = '
            SELECT la.*,
                   u.full_name  AS applicant_name, u.email AS applicant_email,
                   u.designation, u.role AS applicant_role,
                   d.name AS dept_name,
                   rv.full_name AS reviewer_name,
                   hv.full_name AS hod_reviewer_name
            FROM leave_applications la
            JOIN users       u  ON u.id  = la.applicant_id
            LEFT JOIN departments d  ON d.id  = la.department_id
            LEFT JOIN users       rv ON rv.id = la.reviewed_by
            LEFT JOIN users       hv ON hv.id = la.hod_reviewed_by
            WHERE la.college_id = ?
        ';
        $params[] = $collegeId;
    } else {
        $sql = '
            SELECT la.*,
                   u.full_name AS applicant_name, u.email AS applicant_email,
                   u.designation, u.role AS applicant_role,
                   d.name AS dept_name,
                   rv.full_name AS reviewer_name,
                   hv.full_name AS hod_reviewer_name
            FROM leave_applications la
            JOIN users       u  ON u.id  = la.applicant_id
            LEFT JOIN departments d  ON d.id  = la.department_id
            LEFT JOIN users       rv ON rv.id = la.reviewed_by
            LEFT JOIN users       hv ON hv.id = la.hod_reviewed_by
            WHERE la.applicant_id = ?
        ';
        $params[] = $userId;
    }

    if ($fStatus !== 'all') { $sql .= ' AND la.status = ?';     $params[] = $fStatus; }
    if ($fType   !== 'all') { $sql .= ' AND la.leave_type = ?'; $params[] = $fType;   }

    $sql .= ' ORDER BY la.created_at DESC LIMIT 200';

    $st = $db->prepare($sql);
    $st->execute($params);
    $applications = $st->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats
    if ($isAdmin || $isPrincipal) {
        $stStats = $db->prepare('SELECT status, COUNT(*) c FROM leave_applications WHERE college_id=? GROUP BY status');
        $stStats->execute([$collegeId]);
    } elseif ($isHod) {
        $stStats = $db->prepare('SELECT status, COUNT(*) c FROM leave_applications WHERE college_id=? AND department_id=? AND status IN (\'hod_pending\',\'approved\',\'rejected\') GROUP BY status');
        $stStats->execute([$collegeId, $deptId]);
    } else {
        $stStats = $db->prepare('SELECT status, COUNT(*) c FROM leave_applications WHERE applicant_id=? GROUP BY status');
        $stStats->execute([$userId]);
    }
    foreach ($stStats->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stats[$r['status']] = (int)$r['c'];
        $stats['total'] += (int)$r['c'];
    }
}

$statusColors = [
    'pending'     => ['bg'=>'var(--amber2)',  'c'=>'var(--amber)',  'icon'=>'fa-clock'],
    'hod_pending' => ['bg'=>'var(--purple2)', 'c'=>'var(--purple)', 'icon'=>'fa-user-tie'],
    'approved'    => ['bg'=>'var(--green2)',  'c'=>'var(--green)',  'icon'=>'fa-check-circle'],
    'rejected'    => ['bg'=>'var(--red2)',    'c'=>'var(--red)',    'icon'=>'fa-times-circle'],
    'cancelled'   => ['bg'=>'rgba(255,255,255,.06)', 'c'=>'var(--muted)', 'icon'=>'fa-ban'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EduNexus — Leave Application</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --teal:#0F766E;--teal-dark:#0D5C56;--teal-mid:#0F766E;
  --teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;
  --amber-soft:rgba(245,158,11,.12);--amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;
  --success:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
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

/* ══ SIDEBAR — Glassmorphic deep teal ══════════════════════════════════════ */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),inset 1px 0 0 rgba(20,184,166,.08),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease,width .3s ease;overflow:hidden;
}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);pointer-events:none}

.sidebar-scroll{flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-scroll::-webkit-scrollbar{width:3px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}

.sidebar-logo{padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12)}
.logo-mark{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15)}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}

/* ── Scope chip — "ASSIGNED TO" card ── */
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

/* ── Nav ── */
.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;margin-bottom:1px;color:var(--sb-text);font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--amber-acc);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.nav-badge.red{background:var(--red);color:#fff}
.nav-badge.amber{background:var(--amber);color:#fff}

/* ── Collapse button ── */
.sb-collapse-btn{
  margin-left:auto;flex-shrink:0;
  width:26px;height:26px;border-radius:7px;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.55);cursor:pointer;display:grid;place-items:center;
  font-size:.68rem;transition:all .2s;
}
.sb-collapse-btn:hover{background:rgba(245,158,11,.22);border-color:rgba(245,158,11,.50);color:var(--amber-acc)}

/* ── Collapsed state ── */
.sidebar.collapsed{width:var(--sb-collapsed-w)}
.sidebar.collapsed .sidebar-logo{padding:12px 10px 10px;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.sidebar.collapsed .logo-mark{width:34px;height:34px;font-size:.78rem;margin:0}
.sidebar.collapsed .logo-text{display:none !important}
.sidebar.collapsed .sb-collapse-btn{margin:0;width:38px;height:22px;border-radius:6px;font-size:.6rem}
.sidebar.collapsed .nav-item.active::before{display:none !important;content:none !important}
.sidebar.collapsed .nav-item.active,.sidebar.collapsed .nav-item.active:hover{background:rgba(255,255,255,.12)!important;border-color:rgba(255,255,255,.15)!important;box-shadow:none!important;color:#ffffff!important}
.sidebar.collapsed .nav-item.active i,.sidebar.collapsed .nav-item.active:hover i{color:#ffffff!important;opacity:1!important;visibility:visible!important}
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

/* ── User bar ── */
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;box-shadow:0 2px 10px rgba(245,158,11,.28)}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.63rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem}
.logout-btn:hover{color:rgba(252,165,165,.9)}

/* ── Main area transitions ── */
.main{
  margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;
  min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));
  transition:margin-left .3s ease,width .3s ease;
}
.sidebar.collapsed ~ .main,.shell:has(.sidebar.collapsed) .main{
  margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w));
}

/* ── Sidebar overlay (mobile) ── */
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}

/* ══ TOPBAR ════════════════════════════════════════════════════════════════ */
.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:#fff;font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-btn{width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.15);color:#ffffff;display:grid;place-items:center;cursor:pointer;transition:all .18s;font-size:.82rem;text-decoration:none}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{height:36px;display:flex;align-items:center;font-size:.72rem;color:#ffffff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.30);padding:0 11px;border-radius:9px;font-family:var(--mono)}
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

.content{padding:20px 24px;flex:1}

/* ══ STATS ════════════════════════════════════════════════════════════════ */
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px;display:flex;align-items:center;gap:12px;animation:slideUp .35s ease both;cursor:pointer;transition:transform .2s,border-color .2s,box-shadow .2s;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.stat:hover{border-color:var(--border-accent);transform:translateY(-2px);box-shadow:0 6px 20px rgba(15,118,110,.10)}
.stat.active-filter{border-color:var(--border-accent);background:var(--teal-soft);box-shadow:0 0 0 2px rgba(20,184,166,.15)}
.stat-icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.si-teal{background:var(--teal-soft);color:var(--teal)}
.si-amber{background:var(--amber-soft);color:var(--amber-acc)}
.si-green{background:var(--green2);color:var(--success)}
.si-red{background:var(--red2);color:var(--red)}
.si-muted{background:#F1F5F9;color:var(--muted)}
.stat-val{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.7rem;color:var(--muted);margin-top:1px}

/* ══ TOOLBAR ══════════════════════════════════════════════════════════════ */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:slideUp .35s .15s ease both}
select.filter{padding:8px 12px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.25);border-radius:8px;color:var(--teal);font-size:.82rem;font-family:var(--font);cursor:pointer;accent-color:#0F766E;transition:border-color .2s}
select.filter:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.10);outline:none}
select.filter option{background:#fff;color:var(--text)}
select.filter:focus{outline:none;border-color:var(--teal-light)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;font-family:var(--font);text-decoration:none}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-teal:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,118,110,.28)}
.btn-ghost{background:#F8FAFC;color:var(--text);border:1px solid var(--border)}
.btn-ghost:hover{border-color:var(--border-accent);color:var(--teal);background:var(--teal-soft)}
.btn-green{background:var(--green2);color:var(--success);border:1px solid rgba(22,163,74,.2)}
.btn-green:hover{background:rgba(22,163,74,.2)}
.btn-danger{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.2)}
.btn-danger:hover{background:rgba(220,38,38,.2)}
.btn-amber{background:var(--amber-soft);color:var(--amber-acc);border:1px solid rgba(245,158,11,.2)}
.btn-sm{padding:5px 11px;font-size:.75rem}

/* ══ CARD / TABLE ════════════════════════════════════════════════════════ */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);animation:slideUp .35s .2s ease both;overflow:hidden;box-shadow:0 1px 8px rgba(15,118,110,.05)}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--border);background:#F0FDFA}
.card-title{font-size:.9rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal)}
.t-wrap{overflow-x:auto;padding:0 0 4px}
table{width:100%;border-collapse:collapse;min-width:700px}
thead th{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;background:#F8FAFC}
tbody td{padding:11px 16px;font-size:.8rem;color:var(--text);vertical-align:middle;border-bottom:1px solid var(--border)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#F0FDFA}
.name-cell .nm{font-weight:600;color:var(--text);font-size:.83rem}
.name-cell .em{font-size:.7rem;color:var(--muted)}
.mono{font-family:var(--mono);font-size:.72rem;color:var(--muted)}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:7px;font-size:.68rem;font-weight:700;font-family:var(--mono)}

/* ══ EMPTY ══════════════════════════════════════════════════════════════ */
.empty-state{text-align:center;padding:70px 20px;color:var(--muted)}
.empty-state i{font-size:2.8rem;display:block;margin-bottom:14px;opacity:.2}
.empty-state p{font-size:.88rem}

/* ══ MODAL ══════════════════════════════════════════════════════════════ */
.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:200;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal.open{display:flex}
.modal-box{background:#fff;border:1px solid var(--border);border-radius:var(--radius);max-width:680px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 28px 60px rgba(15,118,110,.18);animation:slideUp .25s ease}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--border);background:#F0FDFA}
.modal-hd h2{font-size:1rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.modal-hd h2 i{color:var(--teal)}
.close-btn{width:30px;height:30px;border-radius:7px;border:none;background:#F1F5F9;color:var(--muted);cursor:pointer;font-size:1rem;display:grid;place-items:center;transition:all .2s}
.close-btn:hover{background:var(--red2);color:var(--red)}
.modal-body{padding:22px}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--text);margin-bottom:6px}
.form-group label span{color:var(--red);margin-left:2px}
.form-control{width:100%;padding:10px 13px;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:.86rem;font-family:var(--font);transition:border-color .2s}
.form-control:focus{outline:none;border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.08)}
textarea.form-control{resize:vertical;min-height:100px}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-row{display:flex;gap:10px;justify-content:flex-end;margin-top:22px;flex-wrap:wrap}
.check-row{display:flex;align-items:center;gap:8px;padding:4px 0}
.check-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--teal);cursor:pointer}
.check-row label{font-size:.84rem;color:var(--text);cursor:pointer}
.detail-row{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:.82rem}
.detail-row:last-child{border-bottom:none}
.detail-row .dl{color:var(--muted);width:140px;flex-shrink:0}
.detail-row .dv{color:var(--text);font-weight:500;flex:1}
.review-info{background:var(--teal-soft);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px}

/* ══ TOAST ══════════════════════════════════════════════════════════════ */
.toast{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:10px;font-size:.84rem;font-weight:600;box-shadow:0 8px 24px rgba(15,118,110,.12);animation:slideUp .3s ease;min-width:220px}
.toast.success{background:#fff;border:1px solid rgba(22,163,74,.3);color:var(--success)}
.toast.error{background:#fff;border:1px solid rgba(220,38,38,.3);color:var(--red)}

@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ══ RESPONSIVE ═════════════════════════════════════════════════════════ */
@media(max-width:1280px){.stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1100px){.stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}.content{padding:14px}.hamburger{display:grid}
  .stats{grid-template-columns:1fr 1fr}.form-grid-2{grid-template-columns:1fr}
  .toolbar{flex-direction:column;align-items:stretch}
  select.filter{width:100%}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .modal-box{max-height:95vh}
}
</style>
</head>
<body>
<div class="bg"></div><div class="bg-grid"></div>
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

  <!-- Scrollable area: scope chip + nav -->
  <div class="sidebar-scroll">
    <!-- Assigned To chip: college name + dept -->
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
      <a href="leave_application.php" class="nav-item active" data-tip="Leave Application">
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
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= ucfirst(str_replace('_',' ',$role)) ?> · <?= esc($deptCode ?: $deptName) ?></div>
    </div>
    <button class="logout-btn" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i></button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <div class="topbar-title">Leave Application</div>
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

    <!-- Stats -->
    <div class="stats">
      <div class="stat <?= ($_GET['status']??'all')==='all'?'active-filter':'' ?>" onclick="filterByStatus('all')">
        <div class="stat-icon si-teal"><i class="fas fa-layer-group"></i></div>
        <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">Total</div></div>
      </div>
      <div class="stat <?= ($_GET['status']??'')==='pending'?'active-filter':'' ?>" onclick="filterByStatus('pending')">
        <div class="stat-icon si-amber"><i class="fas fa-clock"></i></div>
        <div><div class="stat-val"><?= $stats['pending'] ?></div><div class="stat-lbl">Pending (Admin)</div></div>
      </div>
      <div class="stat <?= ($_GET['status']??'')==='hod_pending'?'active-filter':'' ?>" onclick="filterByStatus('hod_pending')">
        <div class="stat-icon" style="background:var(--purple2);color:var(--purple)"><i class="fas fa-user-tie"></i></div>
        <div><div class="stat-val"><?= $stats['hod_pending'] ?></div><div class="stat-lbl">Awaiting HOD</div></div>
      </div>
      <div class="stat <?= ($_GET['status']??'')==='approved'?'active-filter':'' ?>" onclick="filterByStatus('approved')">
        <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-val"><?= $stats['approved'] ?></div><div class="stat-lbl">Approved</div></div>
      </div>
      <div class="stat <?= ($_GET['status']??'')==='rejected'?'active-filter':'' ?>" onclick="filterByStatus('rejected')">
        <div class="stat-icon si-red"><i class="fas fa-times-circle"></i></div>
        <div><div class="stat-val"><?= $stats['rejected'] ?></div><div class="stat-lbl">Rejected</div></div>
      </div>
      <div class="stat <?= ($_GET['status']??'')==='cancelled'?'active-filter':'' ?>" onclick="filterByStatus('cancelled')">
        <div class="stat-icon si-muted"><i class="fas fa-ban"></i></div>
        <div><div class="stat-val"><?= $stats['cancelled'] ?></div><div class="stat-lbl">Cancelled</div></div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <select class="filter" id="selType" onchange="applyFilters()">
        <option value="all">All Leave Types</option>
        <?php foreach($leaveTypes as $k=>$v): ?>
        <option value="<?= $k ?>" <?= ($_GET['type']??'all')===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <div style="flex:1"></div>
      <?php if($isPrincipal): ?>
      <span style="font-size:.8rem;color:var(--muted);background:var(--blue2);color:var(--blue);padding:6px 14px;border-radius:8px;border:1px solid rgba(29,78,216,.2)">
        <i class="fas fa-eye"></i> View-only — Principal
      </span>
      <?php elseif($isHod): ?>
      <span style="font-size:.8rem;background:var(--purple2);color:var(--purple);padding:6px 14px;border-radius:8px;border:1px solid rgba(124,58,237,.2)">
        <i class="fas fa-user-tie"></i> HOD — Review Awaiting Approvals
      </span>
      <?php else: ?>
      <button class="btn btn-teal" onclick="event.stopPropagation();openApply()"><i class="fas fa-plus"></i> Apply for Leave</button>
      <?php endif; ?>
    </div>

    <!-- Table -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title">
          <i class="fas fa-calendar-minus"></i>
          <?php
            if ($isPrincipal)    echo 'All Staff Leave Applications (Principal View)';
            elseif ($isHod)      echo 'Department Leave Applications (HOD View)';
            elseif ($isAdmin)    echo 'All Staff Leave Applications';
            else                 echo 'My Leave Applications';
          ?>
        </div>
        <span class="mono"><?= count($applications) ?> record<?= count($applications)!=1?'s':'' ?></span>
      </div>

      <?php if(empty($applications)): ?>
        <div class="empty-state">
          <i class="fas fa-calendar-xmark"></i>
          <p>No leave applications found. Click "Apply for Leave" to get started.</p>
        </div>
      <?php else: ?>
        <div class="t-wrap">
          <table>
            <thead>
              <tr>
                <?php if($isAdmin || $isPrincipal || $isHod): ?><th>Staff</th><?php endif; ?>
                <th>Leave Type</th>
                <th>From</th>
                <th>To</th>
                <th>Days</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Applied On</th>
                <?php if(!$isPrincipal): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach($applications as $la):
              $sc = $statusColors[$la['status']] ?? $statusColors['cancelled'];
            ?>
            <tr>
              <?php if($isAdmin || $isPrincipal || $isHod): ?>
              <td>
                <div class="name-cell">
                  <div class="nm"><?= esc($la['applicant_name']) ?></div>
                  <div class="em"><?= esc($la['dept_name']??$la['applicant_role']) ?></div>
                </div>
              </td>
              <?php endif; ?>
              <td><?= esc($leaveTypes[$la['leave_type']] ?? $la['leave_type']) ?></td>
              <td><span class="mono"><?= date('d M Y',strtotime($la['from_date'])) ?></span></td>
              <td><span class="mono"><?= date('d M Y',strtotime($la['to_date'])) ?></span></td>
              <td>
                <span style="font-family:var(--mono);color:var(--text);font-weight:700"><?= $la['total_days'] ?></span>
                <?php if($la['half_day']): ?><span class="mono" style="color:var(--teal)">½ Day</span><?php endif; ?>
              </td>
              <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= esc($la['reason']) ?>">
                <?= esc(mb_strimwidth($la['reason'],0,60,'…')) ?>
              </td>
              <td>
                <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>">
                  <i class="fas <?= $sc['icon'] ?>"></i>
                  <?= $la['status']==='hod_pending' ? 'Awaiting HOD' : ucfirst($la['status']) ?>
                </span>
              </td>
              <td><span class="mono"><?= date('d M Y',strtotime($la['created_at'])) ?></span></td>
              <?php if(!$isPrincipal): ?>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:nowrap">
                  <button class="btn btn-ghost btn-sm" onclick="viewLeave(<?= htmlspecialchars(json_encode($la),ENT_QUOTES) ?>)" title="View details">
                    <i class="fas fa-eye"></i>
                  </button>
                  <?php if($isAdmin && $la['status']==='pending'): ?>
                  <button class="btn btn-green btn-sm" onclick="openReview(<?= (int)$la['id'] ?>,'approve')">
                    <i class="fas fa-forward"></i> Forward
                  </button>
                  <button class="btn btn-danger btn-sm" onclick="openReview(<?= (int)$la['id'] ?>,'reject')">
                    <i class="fas fa-times"></i>
                  </button>
                  <?php elseif($isHod && $la['status']==='hod_pending'): ?>
                  <button class="btn btn-green btn-sm" onclick="openHodReview(<?= (int)$la['id'] ?>,'hod_approve')">
                    <i class="fas fa-check"></i> Approve
                  </button>
                  <button class="btn btn-danger btn-sm" onclick="openHodReview(<?= (int)$la['id'] ?>,'hod_reject')">
                    <i class="fas fa-times"></i>
                  </button>
                  <?php elseif(!$isAdmin && !$isHod && $la['status']==='pending'): ?>
                  <button class="btn btn-amber btn-sm" onclick="cancelLeave(<?= (int)$la['id'] ?>)">
                    <i class="fas fa-ban"></i> Cancel
                  </button>
                  <?php endif; ?>
                </div>
              </td>
              <?php else: ?>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ══ APPLY MODAL ═══════════════════════════════════════════════════════════ -->
<div class="modal" id="applyModal">
  <div class="modal-box">
    <div class="modal-hd">
      <h2><i class="fas fa-calendar-plus"></i> Apply for Leave</h2>
      <button class="close-btn" onclick="closeModal('applyModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Leave Type <span>*</span></label>
        <select id="aType" class="form-control">
          <?php foreach($leaveTypes as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label>From Date <span>*</span></label>
          <input type="date" id="aFrom" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>To Date <span>*</span></label>
          <input type="date" id="aTo"   class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group">
        <div class="check-row">
          <input type="checkbox" id="aHalfDay" onchange="toggleHalfDay()">
          <label for="aHalfDay">Half Day Leave</label>
        </div>
      </div>
      <div class="form-group" id="halfDaySessionRow" style="display:none">
        <label>Half-Day Session</label>
        <select id="aHalfSession" class="form-control">
          <option value="morning">Morning</option>
          <option value="afternoon">Afternoon</option>
        </select>
      </div>
      <div class="form-group">
        <label>Reason for Leave <span>*</span></label>
        <textarea id="aReason" class="form-control" placeholder="Briefly describe the reason…"></textarea>
      </div>
      <div class="form-group">
        <label>Alternate Arrangement <em style="font-weight:400;color:var(--muted)">(who covers your duties)</em></label>
        <input type="text" id="aAlternate" class="form-control" placeholder="Name / details of colleague covering…">
      </div>
      <div class="form-group">
        <label>Contact During Leave</label>
        <input type="tel" id="aContact" class="form-control" placeholder="+91 9XXXXXXXX">
      </div>
      <div class="form-row">
        <button class="btn btn-ghost" onclick="closeModal('applyModal')">Cancel</button>
        <button class="btn btn-teal"  onclick="event.stopPropagation();submitApply()"><i class="fas fa-paper-plane"></i> Submit Application</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ VIEW MODAL ═══════════════════════════════════════════════════════════ -->
<div class="modal" id="viewModal">
  <div class="modal-box">
    <div class="modal-hd">
      <h2><i class="fas fa-info-circle"></i> Leave Details</h2>
      <button class="close-btn" onclick="closeModal('viewModal')">✕</button>
    </div>
    <div class="modal-body" id="viewBody"><!-- filled by JS --></div>
  </div>
</div>

<!-- ══ REVIEW MODAL (admin — forward to HOD) ═══════════════════════════════════════════ -->
<div class="modal" id="reviewModal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-hd">
      <h2 id="reviewTitle"><i class="fas fa-forward"></i> Forward to HOD</h2>
      <button class="close-btn" onclick="closeModal('reviewModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="reviewLeaveId">
      <input type="hidden" id="reviewAction">
      <p style="font-size:.84rem;color:var(--muted);margin-bottom:14px" id="reviewInfo"></p>
      <div class="form-group">
        <label>Remarks <em style="font-weight:400;color:var(--muted)">(optional)</em></label>
        <textarea id="reviewRemarks" class="form-control" rows="3" placeholder="Add a note for the HOD or applicant…"></textarea>
      </div>
      <div class="form-row">
        <button class="btn btn-ghost" onclick="closeModal('reviewModal')">Cancel</button>
        <button class="btn btn-teal"  id="reviewSubmitBtn" onclick="submitReview()"><i class="fas fa-forward"></i> Forward to HOD</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ HOD REVIEW MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal" id="hodReviewModal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-hd">
      <h2 id="hodReviewTitle"><i class="fas fa-user-tie"></i> HOD Review</h2>
      <button class="close-btn" onclick="closeModal('hodReviewModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="hodReviewLeaveId">
      <input type="hidden" id="hodReviewAction">
      <p style="font-size:.84rem;color:var(--muted);margin-bottom:14px" id="hodReviewInfo"></p>
      <div class="form-group">
        <label>HOD Remarks <em style="font-weight:400;color:var(--muted)">(optional)</em></label>
        <textarea id="hodReviewRemarks" class="form-control" rows="3" placeholder="Add a note for the applicant…"></textarea>
      </div>
      <div class="form-row">
        <button class="btn btn-ghost" onclick="closeModal('hodReviewModal')">Cancel</button>
        <button class="btn btn-teal"  id="hodReviewSubmitBtn" onclick="submitHodReview()"><i class="fas fa-check"></i> Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ── Clock ─────────────────────────────────────────────────────────────────── */
(function tick(){
  // topbarDate removed
  if(el) el.textContent=new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  setTimeout(tick,30000);
})();

/* ── Avatar dropdown ────────────────────────────────────────────────────────── */
function toggleAvatarMenu(e){
  e.stopPropagation();
  const btn=document.getElementById('topbarAvatar');
  const dd=document.getElementById('avatarDropdown');
  const isOpen=dd.classList.contains('open');
  closeAvatarMenu();
  if(!isOpen){btn.classList.add('open');dd.classList.add('open');}
}
function closeAvatarMenu(){
  document.getElementById('topbarAvatar')?.classList.remove('open');
  document.getElementById('avatarDropdown')?.classList.remove('open');
}

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

/* ── Sidebar ────────────────────────────────────────────────────────────────── */
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  const open = sb.classList.toggle('open');
  ov.classList.toggle('active', open);
}
document.addEventListener('click',e=>{
  const sb=document.getElementById('sidebar');
  if(window.innerWidth<=800&&sb.classList.contains('open')&&!sb.contains(e.target)&&!document.getElementById('menuToggle')?.contains(e.target)){
    sb.classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
  }
  const wrap=document.querySelector('.topbar-avatar-wrap');
  if(wrap&&!wrap.contains(e.target)) closeAvatarMenu();
});

/* ── Filters ────────────────────────────────────────────────────────────────── */
function filterByStatus(s){
  const p=new URLSearchParams(window.location.search);
  if(s==='all') p.delete('status'); else p.set('status',s);
  window.location.href='leave_application.php'+(p.toString()?'?'+p.toString():'');
}
function applyFilters(){
  const p=new URLSearchParams(window.location.search);
  const t=document.getElementById('selType').value;
  if(t==='all') p.delete('type'); else p.set('type',t);
  window.location.href='leave_application.php'+(p.toString()?'?'+p.toString():'');
}

/* ── Apply modal ─────────────────────────────────────────────────────────────── */
function openApply(){
  document.getElementById('aType').value='casual';
  document.getElementById('aFrom').value='';
  document.getElementById('aTo').value='';
  document.getElementById('aHalfDay').checked=false;
  document.getElementById('halfDaySessionRow').style.display='none';
  document.getElementById('aReason').value='';
  document.getElementById('aAlternate').value='';
  document.getElementById('aContact').value='';
  document.getElementById('applyModal').classList.add('open');
}
function toggleHalfDay(){
  const v=document.getElementById('aHalfDay').checked;
  document.getElementById('halfDaySessionRow').style.display=v?'block':'none';
  if(v) document.getElementById('aTo').value=document.getElementById('aFrom').value;
}

async function submitApply(){
  const from=document.getElementById('aFrom').value;
  const to=document.getElementById('aTo').value;
  const reason=document.getElementById('aReason').value.trim();
  if(!from||!to||!reason){showToast('Please fill in all required fields.','error');return;}
  if(new Date(to)<new Date(from)){showToast('To date cannot be before From date.','error');return;}
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action','apply_leave');
  fd.append('leave_type',document.getElementById('aType').value);
  fd.append('from_date',from);fd.append('to_date',to);
  fd.append('reason',reason);
  fd.append('half_day',document.getElementById('aHalfDay').checked?1:0);
  fd.append('half_day_session',document.getElementById('aHalfSession').value);
  fd.append('contact',document.getElementById('aContact').value);
  fd.append('alternate',document.getElementById('aAlternate').value);
  try{
    const r=await fetch('leave_application.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');closeModal('applyModal');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── View modal ─────────────────────────────────────────────────────────────── */
const LEAVE_TYPES = <?= json_encode($leaveTypes) ?>;
const STATUS_ICONS  = {pending:'fa-clock',hod_pending:'fa-user-tie',approved:'fa-check-circle',rejected:'fa-times-circle',cancelled:'fa-ban'};
const STATUS_COLORS = {pending:'var(--amber)',hod_pending:'var(--purple)',approved:'var(--green)',rejected:'var(--red)',cancelled:'var(--muted)'};
const STATUS_LABELS = {pending:'Pending (Admin Review)',hod_pending:'Awaiting HOD Approval',approved:'Approved',rejected:'Rejected',cancelled:'Cancelled'};

function viewLeave(la){
  const rows = [
    ['Leave Type',       LEAVE_TYPES[la.leave_type]||la.leave_type],
    ['From Date',        fmtDate(la.from_date)],
    ['To Date',          fmtDate(la.to_date)],
    ['Total Days',       la.total_days + (la.half_day?' (Half Day '+la.half_day_session+')'  :'')],
    ['Reason',           la.reason],
    ['Alternate',        la.alternate_arrangement||'—'],
    ['Contact',          la.contact_during_leave||'—'],
    ['Applied On',       fmtDate(la.created_at)],
    ['Department',       la.dept_name||'—'],
    ['Status',           `<span style="color:${STATUS_COLORS[la.status]||'var(--muted)'}"><i class="fas ${STATUS_ICONS[la.status]||'fa-circle'}"></i> ${STATUS_LABELS[la.status]||cap(la.status)}</span>`],
  ];
  // Stage 1: Admin review
  if(la.reviewer_name){
    rows.push(['Admin Reviewed By', la.reviewer_name]);
    rows.push(['Admin Reviewed On', la.reviewed_at?fmtDate(la.reviewed_at):'—']);
    if(la.review_remarks) rows.push(['Admin Remarks', la.review_remarks]);
  }
  // Stage 2: HOD review
  if(la.hod_reviewer_name){
    rows.push(['HOD Reviewed By', la.hod_reviewer_name]);
    rows.push(['HOD Reviewed On', la.hod_reviewed_at?fmtDate(la.hod_reviewed_at):'—']);
    if(la.hod_remarks) rows.push(['HOD Remarks', la.hod_remarks]);
  }
  if(la.applicant_name) rows.unshift(['Applicant', la.applicant_name]);

  document.getElementById('viewBody').innerHTML =
    '<div style="padding-bottom:8px">'+
    rows.map(r=>`<div class="detail-row"><div class="dl">${r[0]}</div><div class="dv">${r[1]}</div></div>`).join('')+
    '</div>'+
    '<div style="display:flex;justify-content:flex-end;margin-top:14px">'+
    '<button class="btn btn-ghost" onclick="closeModal(\'viewModal\')">Close</button></div>';
  document.getElementById('viewModal').classList.add('open');
}
function fmtDate(d){if(!d)return'—';try{return new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch{return d;}}
function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}

/* ── Cancel ─────────────────────────────────────────────────────────────────── */
async function cancelLeave(id){
  if(!confirm('Cancel this leave application?')) return;
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action','cancel_leave');fd.append('leave_id',id);
  try{
    const r=await fetch('leave_application.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── Review (admin) ──────────────────────────────────────────────────────────── */
function openReview(id, act){
  document.getElementById('reviewLeaveId').value=id;
  document.getElementById('reviewAction').value=act+'_leave';
  document.getElementById('reviewRemarks').value='';
  const isApprove=act==='approve';
  const actionLabel = isApprove ? 'Forward to HOD' : 'Reject';
  const actionIcon  = isApprove ? 'forward' : 'times';
  document.getElementById('reviewTitle').innerHTML=
    `<i class="fas fa-${actionIcon}-circle" style="color:${isApprove?'var(--teal)':'var(--red)'}"></i> `+actionLabel;
  document.getElementById('reviewInfo').textContent=
    isApprove
      ? `You are about to forward leave application #${id} to the HOD for final approval.`
      : `You are about to reject leave application #${id}. Add optional remarks below.`;
  const btn=document.getElementById('reviewSubmitBtn');
  btn.style.background=isApprove?'var(--teal)':'var(--red)';
  btn.style.color='#fff';
  btn.innerHTML=`<i class="fas fa-${actionIcon}"></i> ${actionLabel}`;
  document.getElementById('reviewModal').classList.add('open');
}

/* ── HOD Review ──────────────────────────────────────────────────────────────── */
function openHodReview(id, act){
  document.getElementById('hodReviewLeaveId').value=id;
  document.getElementById('hodReviewAction').value=act+'_leave';
  document.getElementById('hodReviewRemarks').value='';
  const isApprove=act==='hod_approve';
  document.getElementById('hodReviewTitle').innerHTML=
    `<i class="fas fa-${isApprove?'check':'times'}-circle" style="color:${isApprove?'var(--green)':'var(--red)'}"></i> `+
    (isApprove?'HOD Approve':'HOD Reject')+' Application';
  document.getElementById('hodReviewInfo').textContent=
    `You are about to ${isApprove?'approve':'reject'} leave application #${id} as HOD. Add optional remarks below.`;
  const btn=document.getElementById('hodReviewSubmitBtn');
  btn.style.background=isApprove?'var(--green)':'var(--red)';
  btn.style.color='#fff';
  btn.innerHTML=`<i class="fas fa-${isApprove?'check':'times'}"></i> ${isApprove?'Approve':'Reject'}`;
  document.getElementById('hodReviewModal').classList.add('open');
}

async function submitHodReview(){
  const id=document.getElementById('hodReviewLeaveId').value;
  const action=document.getElementById('hodReviewAction').value;
  const remarks=document.getElementById('hodReviewRemarks').value.trim();
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action',action);
  fd.append('leave_id',id);fd.append('review_remarks',remarks);
  try{
    const r=await fetch('leave_application.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');closeModal('hodReviewModal');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

async function submitReview(){
  const id=document.getElementById('reviewLeaveId').value;
  const action=document.getElementById('reviewAction').value;
  const remarks=document.getElementById('reviewRemarks').value.trim();
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action',action);
  fd.append('leave_id',id);fd.append('review_remarks',remarks);
  try{
    const r=await fetch('leave_application.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');closeModal('reviewModal');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── Modal helpers ───────────────────────────────────────────────────────────── */
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
// Close on backdrop click only (not on modal-box clicks)
document.querySelectorAll('.modal').forEach(m=>{
  m.addEventListener('click',e=>{if(e.target===m) closeModal(m.id);});
});

/* ── Toast ───────────────────────────────────────────────────────────────────── */
function showToast(msg,type='success'){
  document.querySelectorAll('.toast').forEach(t=>t.remove());
  const t=document.createElement('div');
  t.className=`toast ${type}`;
  t.innerHTML=`<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  try{
    const r=await fetch('../auth/auth_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
    const d=await r.json();
    if(d.redirect) window.location.href=d.redirect;
  }catch{window.location.href='../login.php';}
}
</script>
</body>
</html>