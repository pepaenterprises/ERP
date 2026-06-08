<?php
// pages/staff_noticeboard.php
// ─────────────────────────────────────────────────────────────────────────────
// STAFF NOTICEBOARD — Create · Edit · Delete · Toggle Active · View
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

$db       = getDB();
$isAdmin  = in_array($role, ['college_admin', 'super_admin', 'principal', 'hod']);
$isHod       = ($role === 'hod');
$isPrincipal = ($role === 'principal');
$isSuperAdmin= ($role === 'super_admin');

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function initials(string $n): string {
    $p = explode(' ', trim($n));
    return strtoupper(substr($p[0],0,1) . (isset($p[1]) ? substr($p[1],0,1) : ''));
}
$avatarInitials = initials($fullName);

// Fetch avatar photo & colour
$avatarPath  = '';
$avatarColor = '#0F766E';
if ($db && $userId) {
    $stAv = $db->prepare('SELECT avatar_path, avatar_color FROM users WHERE id = ? LIMIT 1');
    $stAv->execute([$userId]);
    if ($avRow = $stAv->fetch(PDO::FETCH_ASSOC)) {
        $avatarColor = $avRow['avatar_color'] ?: '#0F766E';
        $rawPath     = $avRow['avatar_path']  ?? '';
        if ($rawPath && file_exists(__DIR__ . '/../' . $rawPath)) {
            $avatarPath = '../' . $rawPath . '?v=' . time();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── Create / Update ───────────────────────────────────────────────────────
    if (in_array($action, ['create_notice','update_notice']) && $isAdmin) {
        $nid     = (int)($_POST['notice_id'] ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $content = trim($_POST['content'] ?? '');
        $type    = trim($_POST['notice_type']     ?? 'general');
        $prio    = trim($_POST['priority']        ?? 'normal');
        $tgt     = trim($_POST['target_audience'] ?? 'all');
        $expiry  = trim($_POST['expiry_date'] ?? '') ?: null;
        $dTarget = intval($_POST['department_id'] ?? 0) ?: null;
        $cTarget = intval($_POST['college_id']    ?? 0) ?: null;

        // HOD: force scope to their own department only
        if ($isHod) {
            $tgt     = 'department_specific';
            $dTarget = $deptId;
            $cTarget = $collegeId;
        }
        // Principal: force scope to their own college only
        if ($isPrincipal) {
            if (!in_array($tgt, ['all','faculty','department_specific','college_specific'])) {
                $tgt = 'college_specific';
            }
            $cTarget = $collegeId;
            // If principal picks department_specific, validate it belongs to their college
            if ($tgt === 'department_specific' && $dTarget) {
                $chkDept = $db->prepare('SELECT id FROM departments WHERE id=? AND college_id=? LIMIT 1');
                $chkDept->execute([$dTarget, $collegeId]);
                if (!$chkDept->fetch()) {
                    echo json_encode(['success'=>false,'message'=>'Department does not belong to your college.']);
                    exit;
                }
            }
        }

        if (!$title || !$content) {
            echo json_encode(['success'=>false,'message'=>'Title and content are required.']);
            exit;
        }
        try {
            if ($action === 'create_notice') {
                $st = $db->prepare('
                    INSERT INTO staff_notices
                        (title,content,notice_type,priority,target_audience,department_id,college_id,expiry_date,created_by,publish_date)
                    VALUES (?,?,?,?,?,?,?,?,?,CURDATE())
                ');
                $st->execute([$title,$content,$type,$prio,$tgt,$dTarget,$cTarget,$expiry,$userId]);
                echo json_encode(['success'=>true,'message'=>'Notice published successfully.']);
            } else {
                // HOD/Principal: restrict update to their own college only
                if ($isHod || $isPrincipal) {
                    $st = $db->prepare('
                        UPDATE staff_notices
                        SET title=?,content=?,notice_type=?,priority=?,target_audience=?,
                            department_id=?,college_id=?,expiry_date=?
                        WHERE id=? AND college_id=?
                    ');
                    $st->execute([$title,$content,$type,$prio,$tgt,$dTarget,$cTarget,$expiry,$nid,$collegeId]);
                } else {
                    $st = $db->prepare('
                        UPDATE staff_notices
                        SET title=?,content=?,notice_type=?,priority=?,target_audience=?,
                            department_id=?,college_id=?,expiry_date=?
                        WHERE id=?
                    ');
                    $st->execute([$title,$content,$type,$prio,$tgt,$dTarget,$cTarget,$expiry,$nid]);
                }
                echo json_encode(['success'=>true,'message'=>'Notice updated successfully.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action === 'delete_notice' && $isAdmin) {
        $nid = (int)($_POST['notice_id'] ?? 0);
        try {
            // HOD/Principal can only delete notices from their own college
            if ($isHod || $isPrincipal) {
                $db->prepare('DELETE FROM staff_notices WHERE id=? AND college_id=?')->execute([$nid, $collegeId]);
            } else {
                $db->prepare('DELETE FROM staff_notices WHERE id=?')->execute([$nid]);
            }
            echo json_encode(['success'=>true,'message'=>'Notice deleted.']);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Delete failed.']);
        }
        exit;
    }

    // ── Toggle active ─────────────────────────────────────────────────────────
    if ($action === 'toggle_active' && $isAdmin) {
        $nid = (int)($_POST['notice_id'] ?? 0);
        try {
            if ($isHod || $isPrincipal) {
                $db->prepare('UPDATE staff_notices SET is_active = NOT is_active WHERE id=? AND college_id=?')->execute([$nid, $collegeId]);
            } else {
                $db->prepare('UPDATE staff_notices SET is_active = NOT is_active WHERE id=?')->execute([$nid]);
            }
            echo json_encode(['success'=>true,'message'=>'Status toggled.']);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Toggle failed.']);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Invalid action or insufficient permissions.']);
    exit;
}

// ── Fetch notices ─────────────────────────────────────────────────────────────
$notices     = [];
$departments = [];
$colleges    = [];
$stats       = ['total'=>0,'critical'=>0,'high'=>0,'my_dept'=>0];

if ($db) {
    $fType = $_GET['type']     ?? 'all';
    $fPrio = $_GET['priority'] ?? 'all';
    $fShow = $_GET['show']     ?? 'active'; // active | all (admin only)

    $params = [];
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

    // Active filter
    if (!$isAdmin || $fShow !== 'all') {
        $sql .= ' AND n.is_active = 1 AND (n.expiry_date IS NULL OR n.expiry_date >= CURDATE())';
    }

    // Role-based audience + college scoping
    if ($isSuperAdmin) {
        // Super admin sees everything (no extra filter)
    } elseif ($isHod) {
        // HOD sees notices from their college only
        $sql .= ' AND n.college_id = ?';
        $params[] = $collegeId;
        $sql .= ' AND (
            n.target_audience = "all"
            OR n.target_audience = "faculty"
            OR (n.target_audience = "department_specific" AND n.department_id = ?)
            OR n.target_audience = "college_specific"
        )';
        $params[] = $deptId;
    } elseif ($isPrincipal || ($role === 'college_admin')) {
        // Principal/college_admin sees all notices for their college
        $sql .= ' AND n.college_id = ?';
        $params[] = $collegeId;
    } else {
        // Faculty: only active notices for their college, audience-filtered
        $sql .= ' AND n.college_id = ?';
        $params[] = $collegeId;
        $sql .= ' AND (
            n.target_audience = "all"
            OR n.target_audience = "faculty"
            OR (n.target_audience = "department_specific" AND n.department_id = ?)
            OR n.target_audience = "college_specific"
        )';
        $params[] = $deptId;
    }

    if ($fType !== 'all') { $sql .= ' AND n.notice_type = ?'; $params[] = $fType; }
    if ($fPrio !== 'all') { $sql .= ' AND n.priority = ?';    $params[] = $fPrio; }

    $sql .= ' ORDER BY FIELD(n.priority,"critical","high","normal","low"), n.publish_date DESC LIMIT 120';

    $st = $db->prepare($sql);
    $st->execute($params);
    $notices = $st->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats['total'] = count($notices);
    foreach ($notices as $n) {
        if ($n['priority'] === 'critical') $stats['critical']++;
        if ($n['priority'] === 'high')     $stats['high']++;
        if ($n['department_id'] == $deptId) $stats['my_dept']++;
    }

    if ($isAdmin) {
        if ($isSuperAdmin || $role === 'college_admin') {
            $departments = $db->query('SELECT id,name FROM departments WHERE status="active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $colleges    = $db->query('SELECT id,name FROM colleges    WHERE status="active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($isPrincipal) {
            // Principal can target any dept in their college
            $st = $db->prepare('SELECT id,name FROM departments WHERE college_id=? AND status="active" ORDER BY name');
            $st->execute([$collegeId]);
            $departments = $st->fetchAll(PDO::FETCH_ASSOC);
            $colleges    = []; // principal sees only their own college, no dropdown needed
        } elseif ($isHod) {
            // HOD can only post to their own dept — no dropdowns needed
            $departments = [];
            $colleges    = [];
        }
    }
}

$typeLabels = [
    'general'=>'General','urgent'=>'Urgent','event'=>'Event',
    'circular'=>'Circular','holiday'=>'Holiday','academic'=>'Academic','administrative'=>'Administrative'
];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EduNexus — Staff Noticeboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & tokens ──────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === DASHBOARD PALETTE (teal/amber/light) === */
  --teal:#0F766E;--teal-dark:#0D5C56;
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

/* ── Layout ─────────────────────────────────────────────────────────────────── */
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── Sidebar ────────────────────────────────────────────────────────────────── */
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
.scope-chip{margin:10px 12px 0;background:linear-gradient(135deg,rgba(20,184,166,.13) 0%,rgba(15,118,110,.09) 100%);border:1px solid rgba(20,184,166,.32);border-radius:12px;padding:11px 13px 12px;box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 4px 16px rgba(0,0,0,.12);position:relative;overflow:hidden}
.scope-chip::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(20,184,166,.55),transparent)}
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
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}
.nav-badge{margin-left:auto;background:var(--amber-acc);color:#0D5C56;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:50px;font-family:var(--mono)}
.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{width:36px;height:36px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;box-shadow:0 2px 10px rgba(245,158,11,.28)}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.63rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}
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

/* Sidebar overlay (mobile) */
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}

/* ── Main ───────────────────────────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w));transition:margin-left .3s ease,width .3s ease}
.sidebar.collapsed ~ .main,.shell:has(.sidebar.collapsed) .main{margin-left:var(--sb-collapsed-w);width:calc(100% - var(--sb-collapsed-w))}
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

/* ── Stats row ──────────────────────────────────────────────────────────────── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px;display:flex;align-items:center;gap:14px;animation:slideUp .35s ease both;box-shadow:0 1px 8px rgba(15,118,110,.05);transition:transform .2s,box-shadow .2s}
.stat:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(15,118,110,.10)}
.stat:nth-child(1){animation-delay:.05s}.stat:nth-child(2){animation-delay:.10s}
.stat:nth-child(3){animation-delay:.15s}.stat:nth-child(4){animation-delay:.20s}
.stat-icon{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1rem;flex-shrink:0}
.si-teal{background:var(--teal-soft);color:var(--teal)}
.si-amber{background:var(--amber-soft);color:var(--amber-acc)}
.si-red{background:var(--red2);color:var(--red)}
.si-purple{background:var(--purple2);color:var(--purple)}
.stat-val{font-size:1.7rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.73rem;color:var(--muted);margin-top:2px}

/* ── Toolbar ────────────────────────────────────────────────────────────────── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:slideUp .35s .18s ease both}
.toolbar-left{display:flex;gap:8px;flex-wrap:wrap;flex:1}
select.filter{padding:8px 12px;background:var(--teal-soft);border:1px solid rgba(15,118,110,.25);border-radius:8px;color:var(--teal);font-size:.82rem;font-family:var(--font);cursor:pointer;transition:border-color .2s;accent-color:#0F766E}
select.filter:focus{border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.10);outline:none}
select.filter option{background:#fff;color:var(--text)}
select.filter:focus{outline:none;border-color:var(--teal-light)}
select.filter option{background:#fff}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;font-family:var(--font);text-decoration:none}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff}
.btn-teal:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,118,110,.28)}
.btn-ghost{background:#F8FAFC;color:var(--text);border:1px solid var(--border)}
.btn-ghost:hover{border-color:var(--border-accent);color:var(--teal);background:var(--teal-soft)}
.btn-danger{background:var(--red2);color:var(--red);border:1px solid rgba(220,38,38,.2)}
.btn-danger:hover{background:rgba(220,38,38,.2)}
.btn-sm{padding:5px 11px;font-size:.75rem}
.count-chip{font-size:.72rem;color:#fff;background:#0F766E;border:1px solid #0D5C56;padding:5px 12px;border-radius:7px;font-family:var(--mono);white-space:nowrap;display:flex;align-items:center;gap:6px}

/* ── Notice cards ───────────────────────────────────────────────────────────── */
.notices-grid{display:grid;gap:14px;animation:slideUp .35s .22s ease both}
.notice-card{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:22px 24px;border-left:4px solid var(--blue);
  box-shadow:0 1px 8px rgba(15,118,110,.05);
  transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden;
}
.notice-card:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(15,118,110,.10)}
.notice-card.priority-critical{border-left-color:var(--red)}
.notice-card.priority-high{border-left-color:var(--amber-acc)}
.notice-card.priority-normal{border-left-color:var(--blue)}
.notice-card.priority-low{border-left-color:#CBD5E1}
.notice-card.inactive{opacity:.6}
.notice-card.inactive::after{
  content:'INACTIVE';position:absolute;top:12px;right:14px;
  font-size:.58rem;font-weight:700;letter-spacing:.12em;
  background:#F1F5F9;color:var(--muted);
  padding:3px 8px;border-radius:5px;font-family:var(--mono);
}

.notice-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.notice-title{font-size:1.05rem;font-weight:700;color:var(--text);flex:1;line-height:1.3}
.notice-badges{display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;font-family:var(--mono)}
.badge.critical{background:var(--red2);color:var(--red)}
.badge.high{background:var(--amber-soft);color:var(--amber-acc)}
.badge.normal{background:var(--blue2);color:var(--blue)}
.badge.low{background:#F1F5F9;color:var(--muted)}
.badge.general{background:#F1F5F9;color:var(--muted)}
.badge.urgent{background:var(--red2);color:var(--red)}
.badge.event{background:var(--purple2);color:var(--purple)}
.badge.circular{background:var(--blue2);color:var(--blue)}
.badge.holiday{background:var(--green2);color:var(--success)}
.badge.academic{background:rgba(244,114,182,.12);color:#db2777}
.badge.administrative{background:var(--amber-soft);color:var(--amber-acc)}

.notice-content{
  color:var(--muted);font-size:.86rem;line-height:1.75;
  margin-bottom:16px;white-space:pre-wrap;word-break:break-word;
  border-left:2px solid var(--border);padding-left:14px;
}

.notice-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding-top:14px;border-top:1px solid var(--border)}
.notice-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:.74rem;color:var(--muted)}
.notice-meta span{display:flex;align-items:center;gap:5px}
.notice-meta i{font-size:.65rem;color:var(--teal)}
.notice-actions{display:flex;gap:8px}

/* ── Empty state ────────────────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:80px 20px;color:var(--muted);animation:slideUp .4s ease}
.empty-state i{font-size:3rem;display:block;margin-bottom:14px;opacity:.2}
.empty-state p{font-size:.9rem}

/* ── Modal ──────────────────────────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:200;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal.open{display:flex}
.modal-box{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  max-width:740px;width:100%;max-height:90vh;overflow-y:auto;
  box-shadow:0 28px 60px rgba(15,118,110,.18);animation:slideUp .25s ease;
}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--border);background:#F0FDFA}
.modal-hd h2{font-size:1rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.modal-hd h2 i{color:var(--teal)}
.close-btn{width:30px;height:30px;border-radius:7px;border:none;background:#F1F5F9;color:var(--muted);cursor:pointer;font-size:1rem;display:grid;place-items:center;transition:all .2s}
.close-btn:hover{background:var(--red2);color:var(--red)}
.modal-body{padding:24px}

/* Form */
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--text);margin-bottom:7px}
.form-group label span{color:var(--red);margin-left:2px}
.form-control{width:100%;padding:10px 13px;background:#fff;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:.86rem;font-family:var(--font);transition:border-color .2s}
.form-control:focus{outline:none;border-color:var(--teal-light);box-shadow:0 0 0 3px rgba(20,184,166,.08)}
textarea.form-control{resize:vertical;min-height:130px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-row{display:flex;gap:10px;justify-content:flex-end;margin-top:24px}

/* Toast */
.toast{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:10px;font-size:.84rem;font-weight:600;box-shadow:0 8px 24px rgba(15,118,110,.12);animation:slideUp .3s ease;min-width:220px;max-width:360px}
.toast.success{background:#fff;border:1px solid rgba(22,163,74,.3);color:var(--success)}
.toast.error{background:#fff;border:1px solid rgba(220,38,38,.3);color:var(--red)}
.toast i{font-size:.9rem;flex-shrink:0}

/* Animations */
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}.content{padding:14px}
  .hamburger{display:grid}
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-left{flex-direction:column}
  select.filter{width:100%}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .notice-card{padding:16px 18px}
  .modal-box{max-height:95vh}
}
</style>
</head>
<body>
<div class="bg"></div>
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
        <a href="staff_noticeboard.php" class="nav-item active" data-tip="Staff Noticeboard">
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
  <div class="sidebar-user">
    <div class="user-avatar" style="<?= $avatarPath ? 'background:none;padding:0;overflow:hidden' : 'background:' . esc($avatarColor) ?>"><?php if ($avatarPath): ?><img src="<?= esc($avatarPath) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:9px;display:block" alt=""><?php else: ?><?= esc($avatarInitials) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= ucfirst(str_replace('_',' ',$role)) ?> · <?= esc($deptCode ?: $deptName) ?></div>
    </div>
    <button class="logout-btn" title="Logout" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i></button>
  </div>
</aside>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <div class="topbar-title">Staff Noticeboard
      <?php if($isHod): ?>
        <span style="margin-left:8px;font-size:.68rem;background:rgba(124,58,237,.18);color:#a78bfa;border:1px solid rgba(124,58,237,.25);padding:3px 9px;border-radius:6px;font-weight:600">HOD — <?= esc($user['department_name'] ?? 'Your Dept') ?></span>
      <?php elseif($isPrincipal): ?>
        <span style="margin-left:8px;font-size:.68rem;background:rgba(245,158,11,.15);color:var(--amber-acc);border:1px solid rgba(245,158,11,.28);padding:3px 9px;border-radius:6px;font-weight:600">Principal — <?= esc($user['college_name'] ?? 'Your College') ?></span>
      <?php endif; ?>
    </div>
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
      <div class="stat">
        <div class="stat-icon si-teal"><i class="fas fa-clipboard-list"></i></div>
        <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">Active Notices</div></div>
      </div>
      <div class="stat">
        <div class="stat-icon si-red"><i class="fas fa-circle-exclamation"></i></div>
        <div><div class="stat-val"><?= $stats['critical'] ?></div><div class="stat-lbl">Critical Priority</div></div>
      </div>
      <div class="stat">
        <div class="stat-icon si-amber"><i class="fas fa-arrow-up"></i></div>
        <div><div class="stat-val"><?= $stats['high'] ?></div><div class="stat-lbl">High Priority</div></div>
      </div>
      <div class="stat">
        <div class="stat-icon si-purple"><i class="fas fa-sitemap"></i></div>
        <div><div class="stat-val"><?= $stats['my_dept'] ?></div><div class="stat-lbl">My Dept Notices</div></div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <select class="filter" id="selType" onchange="applyFilters()">
          <option value="all">All Types</option>
          <?php foreach($typeLabels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($_GET['type']??'all')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <select class="filter" id="selPrio" onchange="applyFilters()">
          <option value="all">All Priorities</option>
          <?php foreach(['critical'=>'Critical','high'=>'High','normal'=>'Normal','low'=>'Low'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($_GET['priority']??'all')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <?php if($isAdmin): ?>
        <select class="filter" id="selShow" onchange="applyFilters()">
          <option value="active" <?= ($_GET['show']??'active')==='active'?'selected':'' ?>>Active Only</option>
          <option value="all"    <?= ($_GET['show']??'active')==='all'   ?'selected':'' ?>>Show All</option>
        </select>
        <?php endif; ?>
      </div>
      <div class="count-chip"><i class="fas fa-eye"></i><?= $stats['total'] ?> notice<?= $stats['total']!=1?'s':'' ?></div>
      <?php if($isAdmin): ?>
      <button class="btn btn-teal" onclick="openCreate()">
        <i class="fas fa-plus"></i>
        <?php if($isHod): ?>New Dept Notice<?php elseif($isPrincipal): ?>New College Notice<?php else: ?>New Notice<?php endif; ?>
      </button>
      <?php endif; ?>
    </div>

    <!-- Notices -->
    <?php if(empty($notices)): ?>
      <div class="empty-state">
        <i class="fas fa-clipboard"></i>
        <p>No notices match your current filters.</p>
      </div>
    <?php else: ?>
      <div class="notices-grid">
        <?php foreach($notices as $n): ?>
        <div class="notice-card priority-<?= esc($n['priority']) ?> <?= $n['is_active']?'':'inactive' ?>">
          <div class="notice-top">
            <div class="notice-title"><?= esc($n['title']) ?></div>
            <div class="notice-badges">
              <span class="badge <?= esc($n['priority']) ?>"><?= ucfirst(esc($n['priority'])) ?></span>
              <span class="badge <?= esc($n['notice_type']) ?>"><?= esc($typeLabels[$n['notice_type']] ?? $n['notice_type']) ?></span>
              <?php if($n['target_audience']==='department_specific'): ?>
                <span class="badge" style="background:var(--purple2);color:var(--purple)"><i class="fas fa-sitemap"></i><?= esc($n['department_name']??'Dept') ?></span>
              <?php elseif($n['target_audience']==='college_specific'): ?>
                <span class="badge" style="background:var(--blue2);color:var(--blue)"><i class="fas fa-school"></i><?= esc($n['college_name']??'College') ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="notice-content"><?= nl2br(esc($n['content'])) ?></div>

          <div class="notice-footer">
            <div class="notice-meta">
              <span><i class="fas fa-user"></i><?= esc($n['created_by_name']??'Admin') ?></span>
              <span><i class="fas fa-calendar"></i><?= date('d M Y', strtotime($n['publish_date'])) ?></span>
              <?php if($n['expiry_date']): ?>
              <span><i class="fas fa-clock"></i>Expires <?= date('d M Y',strtotime($n['expiry_date'])) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-users"></i><?= ucwords(str_replace('_',' ',$n['target_audience'])) ?></span>
            </div>

            <?php if($isAdmin): ?>
            <div class="notice-actions">
              <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($n),ENT_QUOTES) ?>)">
                <i class="fas fa-pen"></i> Edit
              </button>
              <button class="btn btn-ghost btn-sm" onclick="toggleActive(<?= $n['id'] ?>,this)"
                      style="color:<?= $n['is_active']?'var(--amber)':'var(--green)' ?>">
                <i class="fas fa-<?= $n['is_active']?'eye-slash':'eye' ?>"></i>
                <?= $n['is_active']?'Deactivate':'Activate' ?>
              </button>
              <button class="btn btn-danger btn-sm" onclick="deleteNotice(<?= $n['id'] ?>)">
                <i class="fas fa-trash"></i>
              </button>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ══ MODAL ════════════════════════════════════════════════════════════════════ -->
<?php if($isAdmin): ?>
<div class="modal" id="noticeModal">
  <div class="modal-box">
    <div class="modal-hd">
      <h2 id="modalTitle"><i class="fas fa-plus-circle"></i> Create Notice</h2>
      <button class="close-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="noticeId">
      <input type="hidden" id="noticeActionField" value="create_notice">

      <div class="form-group">
        <label>Title <span>*</span></label>
        <input type="text" id="nTitle" class="form-control" placeholder="Notice title…" required>
      </div>
      <div class="form-group">
        <label>Content <span>*</span></label>
        <textarea id="nContent" class="form-control" rows="6" placeholder="Write the notice content…"></textarea>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label>Notice Type</label>
          <select id="nType" class="form-control">
            <?php foreach($typeLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Priority</label>
          <select id="nPriority" class="form-control">
            <option value="low">Low</option>
            <option value="normal" selected>Normal</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Target Audience</label>
        <?php if($isHod): ?>
          <input type="hidden" id="nAudience" value="department_specific">
          <input type="text" class="form-control" value="My Department Only" disabled style="background:#F0FDFA;color:var(--teal);font-weight:600">
        <?php elseif($isPrincipal): ?>
          <select id="nAudience" class="form-control" onchange="toggleTargetFields()">
            <option value="all">All Staff (College-wide)</option>
            <option value="faculty">Faculty Only</option>
            <option value="department_specific">Specific Department</option>
            <option value="college_specific">Whole College</option>
          </select>
        <?php else: ?>
          <select id="nAudience" class="form-control" onchange="toggleTargetFields()">
            <option value="all">All Staff</option>
            <option value="faculty">Faculty Only</option>
            <option value="admin">Administrators Only</option>
            <option value="department_specific">Specific Department</option>
            <option value="college_specific">Specific College</option>
          </select>
        <?php endif; ?>
      </div>

      <?php if(!$isHod): ?>
      <div class="form-group" id="deptField" style="display:none">
        <label>Department</label>
        <select id="nDept" class="form-control">
          <option value="">— Select Department —</option>
          <?php foreach($departments as $d): ?><option value="<?= $d['id'] ?>"><?= esc($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if(!$isHod && !$isPrincipal): ?>
      <div class="form-group" id="collegeFld" style="display:none">
        <label>College</label>
        <select id="nCollege" class="form-control">
          <option value="">— Select College —</option>
          <?php foreach($colleges as $c): ?><option value="<?= $c['id'] ?>"><?= esc($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Expiry Date <em style="font-weight:400;color:var(--muted)">(optional)</em></label>
        <input type="date" id="nExpiry" class="form-control" min="<?= date('Y-m-d') ?>">
      </div>

      <div class="form-row">
        <button class="btn btn-ghost" type="button" onclick="closeModal()">Cancel</button>
        <button class="btn btn-teal"  type="button" onclick="submitNotice()"><i class="fas fa-save"></i> Save Notice</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ── Clock ─────────────────────────────────────────────────────────────────── */
function tick(){
  const n=new Date();
  // topbarDate removed
  if(el) el.textContent=n.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
tick(); setInterval(tick,30000);

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
function applyFilters(){
  const p=new URLSearchParams();
  const t=document.getElementById('selType')?.value;
  const pr=document.getElementById('selPrio')?.value;
  const sh=document.getElementById('selShow')?.value;
  if(t&&t!=='all') p.set('type',t);
  if(pr&&pr!=='all') p.set('priority',pr);
  if(sh&&sh!=='active') p.set('show',sh);
  window.location.href='staff_noticeboard.php'+(p.toString()?'?'+p.toString():'');
}

/* ── Modal ──────────────────────────────────────────────────────────────────── */
function openCreate(){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-plus-circle"></i> Create Notice';
  document.getElementById('noticeActionField').value='create_notice';
  document.getElementById('noticeId').value='';
  document.getElementById('nTitle').value='';
  document.getElementById('nContent').value='';
  document.getElementById('nType').value='general';
  document.getElementById('nPriority').value='normal';
  document.getElementById('nAudience').value='all';
  if(document.getElementById('nDept'))    document.getElementById('nDept').value='';
  if(document.getElementById('nCollege')) document.getElementById('nCollege').value='';
  document.getElementById('nExpiry').value='';
  toggleTargetFields();
  document.getElementById('noticeModal').classList.add('open');
}

function openEdit(n){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-pen"></i> Edit Notice';
  document.getElementById('noticeActionField').value='update_notice';
  document.getElementById('noticeId').value=n.id;
  document.getElementById('nTitle').value=n.title;
  document.getElementById('nContent').value=n.content;
  document.getElementById('nType').value=n.notice_type;
  document.getElementById('nPriority').value=n.priority;
  document.getElementById('nAudience').value=n.target_audience;
  if(document.getElementById('nDept'))    document.getElementById('nDept').value=n.department_id||'';
  if(document.getElementById('nCollege')) document.getElementById('nCollege').value=n.college_id||'';
  document.getElementById('nExpiry').value=n.expiry_date||'';
  toggleTargetFields();
  document.getElementById('noticeModal').classList.add('open');
}

function closeModal(){document.getElementById('noticeModal').classList.remove('open');}
window.addEventListener('click',e=>{if(e.target.id==='noticeModal')closeModal();});

function toggleTargetFields(){
  const sel=document.getElementById('nAudience');
  const v=sel?sel.value:'department_specific';
  const df=document.getElementById('deptField');
  const cf=document.getElementById('collegeFld');
  if(df) df.style.display=v==='department_specific'?'block':'none';
  if(cf) cf.style.display=v==='college_specific'?'block':'none';
}

/* ── Submit ──────────────────────────────────────────────────────────────────── */
async function submitNotice(){
  const title=document.getElementById('nTitle').value.trim();
  const content=document.getElementById('nContent').value.trim();
  if(!title||!content){showToast('Title and content are required.','error');return;}

  const fd=new FormData();
  fd.append('ajax','1');
  fd.append('action',document.getElementById('noticeActionField').value);
  fd.append('notice_id',document.getElementById('noticeId').value);
  fd.append('title',title);
  fd.append('content',content);
  fd.append('notice_type',document.getElementById('nType').value);
  fd.append('priority',document.getElementById('nPriority').value);
  fd.append('target_audience',document.getElementById('nAudience').value);
  fd.append('department_id',(document.getElementById('nDept')||{value:''}).value);
  fd.append('college_id',(document.getElementById('nCollege')||{value:''}).value);
  fd.append('expiry_date',document.getElementById('nExpiry').value);

  try{
    const r=await fetch('staff_noticeboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');closeModal();setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── Toggle active ──────────────────────────────────────────────────────────── */
async function toggleActive(id,btn){
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action','toggle_active');fd.append('notice_id',id);
  try{
    const r=await fetch('staff_noticeboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── Delete ──────────────────────────────────────────────────────────────────── */
async function deleteNotice(id){
  if(!confirm('Delete this notice permanently? This cannot be undone.')) return;
  const fd=new FormData();
  fd.append('ajax','1');fd.append('action','delete_notice');fd.append('notice_id',id);
  try{
    const r=await fetch('staff_noticeboard.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showToast(d.message,'success');setTimeout(()=>location.reload(),900);}
    else showToast(d.message,'error');
  }catch{showToast('Server error.','error');}
}

/* ── Toast ───────────────────────────────────────────────────────────────────── */
function showToast(msg,type='success'){
  document.querySelectorAll('.toast').forEach(t=>t.remove());
  const t=document.createElement('div');
  t.className=`toast ${type}`;
  t.innerHTML=`<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

/* ── Logout ──────────────────────────────────────────────────────────────────── */
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