<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PEPA ERP — Accounts Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --navy:    #0a4f4a;
  --navy2:   #0F766E;
  --teal:    #14B8A6;
  --teal2:   #0F766E;
  --teal3:   #0D5C56;
  --amber:   #F59E0B;
  --red:     #EF4444;
  --green:   #10b981;
  --light:   #F8FAFC;
  --white:   #ffffff;
  --text:    #1e3a38;
  --muted:   #5f8f8a;
  --border:  rgba(15,118,110,0.14);
  --card-bg: #ffffff;
  --shadow:  0 8px 40px rgba(15,118,110,0.13), 0 0 0 1px rgba(15,118,110,0.08);
  --radius:  18px;
  --font:    'Inter', sans-serif;
}
html, body {
  height: 100%; font-family: var(--font);
  background: var(--light); color: var(--text); overflow-x: hidden;
}
/* Background */
.bg-static {
  position: fixed; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 70% 60% at 0% 0%,    rgba(20,184,166,0.13) 0%, transparent 55%),
    radial-gradient(ellipse 55% 50% at 100% 100%, rgba(15,118,110,0.11) 0%, transparent 55%),
    radial-gradient(ellipse 45% 40% at 100% 0%,   rgba(245,158,11,0.07) 0%, transparent 50%),
    linear-gradient(160deg, #edfaf8 0%, #F0FDFB 35%, #F8FAFC 65%, #EFF6FF 100%);
}
.bg-grid {
  position: fixed; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(15,118,110,0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15,118,110,0.05) 1px, transparent 1px);
  background-size: 48px 48px;
}

/* ─── PEPA Brand Watermark ───────────────────────────────────────────────── */
.bg-pepa-watermark {
  position: fixed; inset: 0; z-index: 0;
  pointer-events: none;
  overflow: hidden;
}
.bg-pepa-watermark svg {
  position: absolute;
  bottom: -4%;
  left: -2%;
  width: 62%;
  height: auto;
  opacity: 1;
}

/* Layout */
.page-wrap {
  position: relative; z-index: 1;
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 32px 16px;
}

/* Card */
.login-card {
  background: #ffffff;
  backdrop-filter: blur(12px);
  border: 1px solid rgba(15,118,110,0.12);
  border-radius: var(--radius);
  box-shadow: 0 8px 40px rgba(15,118,110,0.12), 0 0 0 1px rgba(15,118,110,0.06);
  width: 100%; max-width: 440px;
  padding: 44px 40px 38px;
  animation: slideIn 0.5s ease both;
}
@keyframes slideIn { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:none} }
@media(max-width:480px){ .login-card{ padding:32px 20px 28px; } }

/* Logo area */
.logo-ring {
  width: 70px; height: 70px;
  border-radius: 50%;
  background: linear-gradient(135deg, rgba(15,118,110,.13), rgba(20,184,166,.07));
  border: 2px solid rgba(15,118,110,.25);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
  box-shadow: 0 0 24px rgba(15,118,110,.12);
}
.logo-ring i { font-size: 28px; color: var(--teal2); }

.card-title {
  text-align: center;
  font-size: 1.5rem; font-weight: 800;
  color: #0a2e2c; letter-spacing: -.3px;
  margin-bottom: 4px;
}
.card-sub {
  text-align: center;
  font-size: .82rem; color: var(--muted);
  margin-bottom: 28px;
}
.badge-role {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(15,118,110,.09);
  border: 1px solid rgba(15,118,110,.22);
  border-radius: 20px;
  padding: 4px 14px;
  font-size: .72rem; font-weight: 600;
  color: var(--teal2); letter-spacing: .4px; text-transform: uppercase;
  margin: 0 auto 24px; display: block; width: fit-content;
}

/* Form */
.form-group { margin-bottom: 16px; }
.form-label {
  display: block; font-size: .75rem; font-weight: 600;
  color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
  margin-bottom: 7px;
}
.input-wrap { position: relative; }
.input-wrap .inp-icon {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  color: var(--muted); font-size: .88rem; pointer-events: none;
}
.form-input {
  width: 100%;
  padding: 11px 14px 11px 38px;
  background: #F8FAFC;
  border: 1px solid rgba(15,118,110,0.15);
  border-radius: 10px;
  color: #0a2e2c;
  font-family: var(--font); font-size: .9rem;
  outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.form-input:focus {
  border-color: var(--teal2);
  box-shadow: 0 0 0 3px rgba(20,184,166,0.12);
  background: #ffffff;
}
.form-input::placeholder { color: #9ab5b2; }
.pw-toggle {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  color: var(--muted); font-size: .88rem; padding: 4px;
}
.pw-toggle:hover { color: var(--teal2); }

/* Remember row */
.remember-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 22px; font-size: .78rem;
}
.remember-row label { display: flex; align-items: center; gap: 7px; cursor: pointer; color: var(--muted); }
.remember-row input[type=checkbox] { accent-color: var(--teal2); width: 14px; height: 14px; }

.btn-login {
  width: 100%;
  padding: 13px;
  background: linear-gradient(135deg, #0F766E 0%, #14B8A6 55%, #0F766E 100%);
  border: none; border-radius: 10px;
  color: #fff; font-family: var(--font);
  font-size: .95rem; font-weight: 700;
  cursor: pointer; letter-spacing: .2px;
  box-shadow: 0 4px 20px rgba(15,118,110,0.28);
  position: relative; overflow: hidden;
  transition: transform .2s, box-shadow .2s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-login:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 10px 36px rgba(15,118,110,0.42);
}
.btn-login:active:not(:disabled) { transform: translateY(0); }
.btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }

/* Alert */
.alert {
  border-radius: 10px; padding: 11px 14px;
  font-size: .83rem; margin-bottom: 18px;
  display: flex; align-items: flex-start; gap: 9px;
  animation: fadeIn .25s ease;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:none} }
.alert-error  { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.22); color: #b91c1c; }
.alert-success{ background: rgba(15,118,110,.09); border: 1px solid rgba(15,118,110,.22); color: var(--teal2); }
.alert i { margin-top: 1px; flex-shrink: 0; }

/* Divider */
.divider {
  border: none; border-top: 1px solid var(--border);
  margin: 26px 0 20px;
}

/* Back link */
.back-link {
  text-align: center; font-size: .78rem; color: var(--muted);
}
.back-link a { color: var(--teal2); text-decoration: none; font-weight: 600; }
.back-link a:hover { text-decoration: underline; }

/* Info strip */
.info-strip {
  margin-top: 18px;
  background: rgba(245,158,11,.06);
  border: 1px solid rgba(245,158,11,.18);
  border-radius: 10px;
  padding: 12px 16px;
  font-size: .76rem; color: #92400e;
  display: flex; gap: 9px; align-items: flex-start;
}
.info-strip i { margin-top: 1px; flex-shrink: 0; color: var(--amber); }
</style>
</head>
<body>

<div class="bg-static"></div>
<div class="bg-grid"></div>
<div class="bg-pepa-watermark" aria-hidden="true">
  <svg viewBox="0 0 900 220" xmlns="http://www.w3.org/2000/svg" fill="none">
    <defs>
      <filter id="pepaShadow" x="-5%" y="-5%" width="120%" height="130%">
        <feDropShadow dx="3" dy="6"  stdDeviation="2" flood-color="rgba(15,118,110,0.18)" />
        <feDropShadow dx="7" dy="14" stdDeviation="6" flood-color="rgba(15,118,110,0.10)" />
        <feDropShadow dx="0" dy="0"  stdDeviation="18" flood-color="rgba(20,184,166,0.07)" />
      </filter>
      <filter id="pepaGlow" x="-10%" y="-10%" width="130%" height="140%">
        <feGaussianBlur in="SourceGraphic" stdDeviation="3" result="blur"/>
        <feColorMatrix in="blur" type="matrix" values="0 0 0 0 0.08  0 0 0 0 0.46  0 0 0 0 0.43  0 0 0 0.25 0" result="coloredBlur"/>
        <feMerge><feMergeNode in="coloredBlur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <text x="0" y="195" font-family="'Inter', sans-serif" font-weight="900" font-size="210" letter-spacing="-6"
      fill="none" stroke="rgba(15,118,110,0.13)" stroke-width="1.2"
      filter="url(#pepaShadow)" paint-order="stroke">PEPA</text>
    <text x="0" y="195" font-family="'Inter', sans-serif" font-weight="900" font-size="210" letter-spacing="-6"
      fill="none" stroke="rgba(20,184,166,0.06)" stroke-width="3"
      filter="url(#pepaGlow)" paint-order="stroke">PEPA</text>
  </svg>
</div>

<div class="page-wrap">
  <div class="login-card">

    <!-- Logo -->
    <div class="logo-ring">
      <i class="fas fa-coins"></i>
    </div>

    <h1 class="card-title">Accounts Portal</h1>
    <p class="card-sub">PEPA ERP — College Finance Management</p>
    <span class="badge-role"><i class="fas fa-shield-halved"></i> Accountant Access</span>

    <!-- Alert placeholder -->
    <div id="alertBox" style="display:none"></div>

    <!-- Login Form -->
    <form id="loginForm" autocomplete="off">
      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <div class="input-wrap">
          <i class="fas fa-user inp-icon"></i>
          <input type="text" id="username" name="username" class="form-input"
                 placeholder="Enter your username" required autocomplete="username">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-wrap">
          <i class="fas fa-lock inp-icon"></i>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="Enter your password" required autocomplete="current-password">
          <button type="button" class="pw-toggle" onclick="togglePw()" id="pwToggleBtn" title="Show/hide password">
            <i class="fas fa-eye" id="pwIcon"></i>
          </button>
        </div>
      </div>

      <div class="remember-row">
        <label>
          <input type="checkbox" name="remember" id="rememberMe">
          Remember me for 30 days
        </label>
      </div>

      <button type="submit" class="btn-login" id="loginBtn">
        <i class="fas fa-right-to-bracket"></i>
        Sign In to Accounts
      </button>
    </form>

    <hr class="divider">

    <div class="back-link">
      Not an accountant? &nbsp;<a href="../login.php"><i class="fas fa-arrow-left"></i> Back to main login</a>
    </div>

    <div class="info-strip">
      <i class="fas fa-circle-info"></i>
      <span>Your login credentials are provided by the college principal. Contact your administrator if you are unable to sign in.</span>
    </div>

  </div>
</div>

<script>
// ── Toggle password visibility ────────────────────────────────────────────────
function togglePw() {
  var inp  = document.getElementById('password');
  var icon = document.getElementById('pwIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    inp.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}

// ── Show alert helper ─────────────────────────────────────────────────────────
function showAlert(type, html) {
  var box = document.getElementById('alertBox');
  var icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
  box.className = 'alert alert-' + type;
  box.innerHTML = '<i class="fas ' + icon + '"></i><span>' + html + '</span>';
  box.style.display = 'flex';
}

// ── Form submit → AJAX to accounts_auth_handler.php ──────────────────────────
document.getElementById('loginForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var btn = document.getElementById('loginBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in…';

  var fd = new FormData(this);
  fd.append('action', 'accounts_login');

  fetch('../auth/accounts_auth_handler.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.success) {
        showAlert('success', '<strong>Welcome, ' + (d.name || 'Accountant') + '!</strong> Redirecting…');
        setTimeout(function(){ window.location.href = d.redirect || '../ERP/pages/accounts.php'; }, 900);
      } else {
        showAlert('error', d.message || 'Login failed. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-right-to-bracket"></i> Sign In to Accounts';
      }
    })
    .catch(function() {
      showAlert('error', 'Network error. Please check your connection and try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-right-to-bracket"></i> Sign In to Accounts';
    });
});
</script>
</body>
</html>