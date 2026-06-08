<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PEPA ERP — Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ─── Reset & Tokens ──────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --navy:    #0a4f4a;
  --navy2:   #0F766E;
  --teal:    #14B8A6;
  --teal2:   #0F766E;
  --teal3:   #0D5C56;
  --amber:   #F59E0B;
  --red:     #EF4444;
  --light:   #F8FAFC;
  --white:   #ffffff;
  --text:    #1e3a38;
  --muted:   #5f8f8a;
  --border:  rgba(15,118,110,0.14);
  --card-bg: rgba(255,255,255,0.92);
  --shadow:  0 8px 40px rgba(15,118,110,0.13), 0 0 0 1px rgba(15,118,110,0.08);
  --radius:  18px;
  --font-head: 'Inter', sans-serif;
  --font-body: 'Inter', sans-serif;
}

html, body {
  height: 100%;
  font-family: var(--font-body);
  background: var(--light);
  color: var(--text);
  overflow-x: hidden;
}

/* ─── Background ──────────────────────────────────────────────────────────── */
.bg-static {
  position: fixed; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 70% 60% at 0% 0%,   rgba(20,184,166,0.13) 0%, transparent 55%),
    radial-gradient(ellipse 55% 50% at 100% 100%, rgba(15,118,110,0.11) 0%, transparent 55%),
    radial-gradient(ellipse 45% 40% at 100% 0%,  rgba(245,158,11,0.07) 0%, transparent 50%),
    linear-gradient(160deg, #edfaf8 0%, #F0FDFB 35%, #F8FAFC 65%, #EFF6FF 100%);
}
.bg-grid {
  position: fixed; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(15,118,110,0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15,118,110,0.05) 1px, transparent 1px);
  background-size: 48px 48px;
}

/* ─── PEPA Brand Watermark ────────────────────────────────────────────────── */
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

/* ─── Page Layout ─────────────────────────────────────────────────────────── */
.page-wrap {
  position: relative; z-index: 1;
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 1fr;
  align-items: start;
}

/* ─── Left Hero Panel ─────────────────────────────────────────────────────── */
.hero-panel {
  display: flex; flex-direction: column; justify-content: center;
  padding: 60px 70px;
  position: relative; overflow: hidden;
}

.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(15,118,110,0.09); border: 1px solid rgba(15,118,110,0.25);
  color: var(--teal2); font-family: var(--font-head); font-size: 0.7rem; font-weight: 700;
  letter-spacing: 0.12em; text-transform: uppercase;
  padding: 6px 14px; border-radius: 50px; width: fit-content; margin-bottom: 32px;
  animation: fadeUp 0.6s ease both;
}
.hero-badge i { font-size: 0.65rem; }

.hero-title {
  font-family: var(--font-head); font-size: clamp(2.6rem, 4vw, 3.8rem);
  font-weight: 800; line-height: 1.1; color: #0a2e2c;
  margin-bottom: 22px;
  animation: fadeUp 0.6s 0.1s ease both;
}
.hero-title .accent { color: var(--teal); }
.hero-title .block  { display: block; }

.hero-desc {
  font-size: 1rem; color: var(--muted); line-height: 1.75; max-width: 400px;
  margin-bottom: 48px;
  animation: fadeUp 0.6s 0.2s ease both;
}

.stat-row {
  display: flex; gap: 36px;
  animation: fadeUp 0.6s 0.3s ease both;
}
.stat-item { display: flex; flex-direction: column; }
.stat-num  { font-family: var(--font-head); font-size: 2rem; font-weight: 800; color: #0a2e2c; }
.stat-lbl  { font-size: 0.78rem; color: var(--muted); letter-spacing: 0.05em; margin-top: 2px; }

/* Feature pills */
.feature-pills {
  display: flex; flex-wrap: wrap; gap: 10px; margin-top: 48px;
  animation: fadeUp 0.6s 0.4s ease both;
}
.pill {
  display: flex; align-items: center; gap: 7px;
  background: rgba(15,118,110,0.07); border: 1px solid rgba(15,118,110,0.18);
  padding: 7px 14px; border-radius: 50px;
  font-size: 0.8rem; color: var(--text);
  backdrop-filter: blur(8px);
  transition: all 0.25s;
}
.pill i { color: var(--teal2); font-size: 0.75rem; }
.pill:hover { background: rgba(15,118,110,0.13); border-color: rgba(15,118,110,0.30); color: #0a2e2c; }

/* Decorative college cards strip */
.college-cards-strip {
  position: absolute; bottom: 0; right: -20px;
  display: flex; gap: 12px;
  transform: rotate(-6deg) translateY(40%);
  opacity: 0.18;
}
.mini-card {
  width: 100px; height: 60px; border-radius: 10px;
  background: linear-gradient(135deg, rgba(0,198,174,0.4), rgba(13,27,42,0.8));
  border: 1px solid rgba(0,198,174,0.3);
}

/* ─── Right Auth Panel ────────────────────────────────────────────────────── */
.auth-panel {
  display: flex; flex-direction: column; justify-content: center; align-items: center;
  padding: 40px 50px;
  border-left: 1px solid rgba(15,118,110,0.10);
  min-height: 100vh;
  overflow-y: auto;
  align-self: start;
  position: sticky;
  top: 0;
}

.auth-card {
  width: 100%; max-width: 430px;
  background: #ffffff;
  border: 1px solid rgba(15,118,110,0.12);
  border-radius: var(--radius);
  padding: 48px 44px;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 40px rgba(15,118,110,0.12), 0 0 0 1px rgba(15,118,110,0.06);
  animation: slideIn 0.5s ease both;
}

/* Tab switcher */
.tab-switcher {
  display: flex; background: #F0FDFB; border-radius: 12px; border: 1px solid rgba(15,118,110,0.12);
  padding: 4px; margin-bottom: 36px; gap: 4px;
}
.tab-btn {
  flex: 1; padding: 10px; border: none; border-radius: 9px; cursor: pointer;
  font-family: var(--font-head); font-size: 0.85rem; font-weight: 600;
  background: transparent; color: var(--muted); transition: all 0.25s;
}
.tab-btn.active {
  background: linear-gradient(135deg, #0F766E, #14B8A6); color: #fff; box-shadow: 0 4px 18px rgba(15,118,110,0.28);
}

/* Form heading */
.form-heading { font-family: var(--font-head); font-size: 1.5rem; font-weight: 700; color: #0a2e2c; margin-bottom: 6px; }
.form-sub     { font-size: 0.85rem; color: var(--muted); margin-bottom: 28px; }

/* Input groups */
.input-group { margin-bottom: 18px; position: relative; }
.input-label {
  display: block; font-size: 0.78rem; font-weight: 500; color: var(--muted);
  letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 7px;
}
.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  color: var(--muted); font-size: 0.9rem; pointer-events: none;
  transition: color 0.2s;
}
.form-input {
  width: 100%; padding: 12px 14px 12px 42px;
  background: #F8FAFC; border: 1px solid rgba(15,118,110,0.15);
  border-radius: 10px; color: #0a2e2c; font-family: var(--font-body);
  font-size: 0.92rem; outline: none;
  transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.form-input::placeholder { color: #9ab5b2; }
.form-input:focus {
  border-color: var(--teal2); background: #ffffff;
  box-shadow: 0 0 0 3px rgba(20,184,166,0.12);
}
.form-input:focus + .input-icon,
.input-wrap:focus-within .input-icon { color: var(--teal); }
.toggle-pw {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: var(--muted); cursor: pointer; font-size: 0.9rem;
  transition: color 0.2s; padding: 4px;
}
.toggle-pw:hover { color: var(--teal); }

/* 2-col grid inside form */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* Select */
.form-select {
  width: 100%; padding: 12px 14px 12px 42px;
  background: #F8FAFC; border: 1px solid rgba(15,118,110,0.15);
  border-radius: 10px; color: #0a2e2c; font-family: var(--font-body);
  font-size: 0.92rem; outline: none; appearance: none; cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.form-select:focus { border-color: var(--teal2); box-shadow: 0 0 0 3px rgba(20,184,166,0.12); }
.form-select option { background: #fff; color: #0a2e2c; }

/* Faculty department field - hidden by default */
#deptGroup {
  display: none;
  animation: fadeUp 0.3s ease both;
}
#deptGroup.show { display: block; }

/* Password strength */
.pwd-strength { margin-top: 6px; }
.strength-bar { height: 3px; border-radius: 4px; background: rgba(255,255,255,0.1); overflow: hidden; }
.strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width 0.4s, background 0.4s; }
.strength-label { font-size: 0.72rem; color: var(--muted); margin-top: 4px; }

/* Password requirements checklist */
.pwd-requirements {
  margin-top: 10px;
  padding: 12px 14px;
  background: #F0FDFB;
  border: 1px solid rgba(15,118,110,0.12);
  border-radius: 9px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px 12px;
}
.req-item {
  display: flex; align-items: center; gap: 7px;
  font-size: 0.75rem; color: var(--muted);
  transition: color 0.25s;
}
.req-item i {
  font-size: 0.65rem; width: 14px; text-align: center;
  color: rgba(255,255,255,0.15);
  transition: color 0.25s;
}
.req-item.met { color: var(--teal2); }
.req-item.met i { color: var(--teal2); }

/* Field validation states */
.form-input.valid,
.form-select.valid {
  border-color: rgba(0,198,174,0.5);
}
.form-input.invalid,
.form-select.invalid {
  border-color: rgba(231,111,81,0.6);
  box-shadow: 0 0 0 3px rgba(231,111,81,0.08);
}
.field-error {
  font-size: 0.73rem;
  color: #f4a6a6;
  margin-top: 5px;
  display: none;
  align-items: center;
  gap: 5px;
  animation: fadeUp 0.2s ease both;
}
.field-error.show { display: flex; }
.field-error i { font-size: 0.65rem; }

/* ─── OTP Verification Step ─────────────────────────────────────────────────── */
#otpStep {
  display: none;
  animation: fadeUp 0.35s ease both;
}
#otpStep.show { display: block; }

.otp-banner {
  background: rgba(15,118,110,0.06);
  border: 1px solid rgba(15,118,110,0.18);
  border-radius: 12px;
  padding: 18px 20px;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 12px;
}
.otp-banner i { color: var(--teal2); font-size: 1.1rem; margin-top: 2px; flex-shrink: 0; }
.otp-banner-text { font-size: 0.84rem; color: var(--muted); line-height: 1.6; }
.otp-banner-text strong { color: var(--text); }

.otp-inputs {
  display: flex; gap: 10px; justify-content: center;
  margin: 22px 0 18px;
}
.otp-digit {
  width: 50px; height: 56px;
  text-align: center; font-size: 1.4rem; font-weight: 700;
  color: #0a2e2c; font-family: var(--font-head);
  background: #F8FAFC; border: 1.5px solid rgba(15,118,110,0.20);
  border-radius: 10px; outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
  caret-color: var(--teal);
}
.otp-digit:focus {
  border-color: var(--teal2);
  box-shadow: 0 0 0 3px rgba(20,184,166,0.14);
  background: #fff;
}
.otp-digit.filled { border-color: rgba(15,118,110,0.45); }
.otp-digit.error  { border-color: #EF4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.10); }

.otp-resend {
  text-align: center; font-size: 0.8rem; color: var(--muted);
  margin-bottom: 18px;
}
.otp-resend-btn {
  background: none; border: none; color: var(--teal2);
  font-size: 0.8rem; cursor: pointer; padding: 0;
  text-decoration: underline; font-family: var(--font-body);
}
.otp-resend-btn:disabled { color: var(--muted); text-decoration: none; cursor: default; }
#otpTimerSpan { color: var(--teal2); font-weight: 600; }

.otp-back-btn {
  background: none; border: 1px solid rgba(15,118,110,0.22);
  border-radius: 8px; padding: 10px; width: 100%;
  color: var(--muted); font-family: var(--font-body); font-size: 0.85rem;
  cursor: pointer; margin-top: 10px;
  transition: border-color 0.2s, color 0.2s;
}
.otp-back-btn:hover { border-color: rgba(15,118,110,0.40); color: var(--text); }

.email-verified-badge {
  display: none; align-items: center; gap: 8px;
  font-size: 0.8rem; color: var(--teal2);
  background: rgba(15,118,110,0.07); border: 1px solid rgba(15,118,110,0.20);
  border-radius: 8px; padding: 6px 12px; margin-top: 8px;
}
.email-verified-badge.show { display: flex; }
.email-verified-badge i { font-size: 0.75rem; }

/* Locked role badge */
.role-locked {
  width: 100%; padding: 12px 14px 12px 42px;
  background: rgba(15,118,110,0.07);
  border: 1px solid rgba(15,118,110,0.22);
  border-radius: 10px; color: var(--teal2);
  font-family: var(--font-body); font-size: 0.92rem;
  display: flex; align-items: center; gap: 8px;
  font-weight: 600; letter-spacing: 0.02em;
}
.role-locked .lock-badge {
  margin-left: auto;
  background: rgba(15,118,110,0.12);
  border: 1px solid rgba(15,118,110,0.22);
  border-radius: 50px; padding: 2px 8px;
  font-size: 0.68rem; font-weight: 600;
  letter-spacing: 0.06em; color: var(--teal2);
}

/* Checkbox row */
.checkbox-row { display: flex; align-items: center; justify-content: space-between; margin: 6px 0 22px; }
.check-wrap { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.check-wrap input[type=checkbox] { accent-color: var(--teal2); width: 15px; height: 15px; cursor: pointer; }
.check-label { font-size: 0.82rem; color: var(--muted); }
.forgot-link { font-size: 0.82rem; color: var(--teal2); text-decoration: none; }
.forgot-link:hover { text-decoration: underline; }

/* Submit button */
.btn-submit {
  width: 100%; padding: 14px;
  background: linear-gradient(135deg, #0F766E 0%, #14B8A6 55%, #0F766E 100%);
  border: none; border-radius: 10px;
  font-family: var(--font-head); font-size: 0.95rem; font-weight: 700;
  color: #fff; cursor: pointer; letter-spacing: 0.03em; box-shadow: 0 4px 20px rgba(15,118,110,0.28);
  position: relative; overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}
.btn-submit::before {
  content: ''; position: absolute; inset: 0;
  background: rgba(255,255,255,0.15);
  transform: translateX(-100%) skewX(-15deg);
  transition: transform 0.4s;
}
.btn-submit:hover::before { transform: translateX(100%) skewX(-15deg); }
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(0,212,187,0.50); }
.btn-submit:active { transform: translateY(0); }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-submit .spinner { display: none; }
.btn-submit.loading .btn-text { display: none; }
.btn-submit.loading .spinner { display: inline-flex; align-items: center; gap: 8px; }

/* Alert / toast */
.alert {
  padding: 12px 16px; border-radius: 10px; font-size: 0.85rem;
  margin-bottom: 18px; display: none; align-items: center; gap: 10px;
  opacity: 0; transition: opacity 0.25s ease;
}
.alert.show { display: flex; opacity: 1; animation: shake 0.4s ease; }
.alert-error   { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); color: #b91c1c; }
.alert-success { background: rgba(15,118,110,0.08); border: 1px solid rgba(15,118,110,0.22); color: var(--teal2); }
.alert-close {
  margin-left: auto; background: none; border: none; color: inherit;
  font-size: 1.1rem; cursor: pointer; line-height: 1; padding: 0 2px;
  opacity: 0.7; transition: opacity 0.15s;
}
.alert-close:hover { opacity: 1; }

/* Demo credentials box */
.demo-box {
  margin-top: 22px; padding: 14px 18px;
  background: rgba(245,158,11,0.06); border: 1px solid rgba(245,158,11,0.18);
  border-radius: 10px; font-size: 0.78rem; color: var(--muted);
}
.demo-box strong { color: #B45309; display: block; margin-bottom: 6px; font-size: 0.8rem; }
.demo-row { display: flex; justify-content: space-between; margin-top: 3px; }
.demo-row span:last-child { color: var(--text); font-family: monospace; }

/* Forms visibility */
#loginForm   { display: block; }
#registerForm { display: none; }

/* ─── Animations ──────────────────────────────────────────────────────────── */
@keyframes fadeUp  { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
@keyframes slideIn { from { opacity:0; transform:translateX(30px); } to { opacity:1; transform:translateX(0); } }
@keyframes shake   { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-6px)} 40%,80%{transform:translateX(6px)} }
@keyframes spin    { to { transform: rotate(360deg); } }

.spin { animation: spin 0.8s linear infinite; display: inline-block; }

/* ─── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .page-wrap { grid-template-columns: 1fr; }
  .hero-panel { padding: 50px 36px 36px; }
  .auth-panel { padding: 36px 24px 60px; border-left: none; border-top: 1px solid rgba(15,118,110,0.10); }
  .auth-card  { padding: 36px 28px; }
}
@media (max-width: 480px) {
  .form-row { grid-template-columns: 1fr; }
  .stat-row { gap: 24px; }
}
</style>
</head>
<body>

<!-- Background -->
<div class="bg-static"></div>
<div class="bg-grid"></div>
<div class="bg-pepa-watermark" aria-hidden="true">
  <svg viewBox="0 0 900 220" xmlns="http://www.w3.org/2000/svg" fill="none">
    <defs>
      <!-- Drop shadow filter — subtle, south-east offset -->
      <filter id="pepaShadow" x="-5%" y="-5%" width="120%" height="130%">
        <feDropShadow dx="3" dy="6"  stdDeviation="2" flood-color="rgba(15,118,110,0.18)" />
        <feDropShadow dx="7" dy="14" stdDeviation="6" flood-color="rgba(15,118,110,0.10)" />
        <feDropShadow dx="0" dy="0"  stdDeviation="18" flood-color="rgba(20,184,166,0.07)" />
      </filter>
      <!-- Thin inner glow for depth -->
      <filter id="pepaGlow" x="-10%" y="-10%" width="130%" height="140%">
        <feGaussianBlur in="SourceGraphic" stdDeviation="3" result="blur"/>
        <feColorMatrix in="blur" type="matrix" values="0 0 0 0 0.08  0 0 0 0 0.46  0 0 0 0 0.43  0 0 0 0.25 0" result="coloredBlur"/>
        <feMerge><feMergeNode in="coloredBlur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <!-- Stroke-only letterforms — transparent fill, thin teal outline -->
    <text
      x="0" y="195"
      font-family="'Inter', sans-serif"
      font-weight="900"
      font-size="210"
      letter-spacing="-6"
      fill="none"
      stroke="rgba(15,118,110,0.13)"
      stroke-width="1.2"
      filter="url(#pepaShadow)"
      paint-order="stroke"
    >PEPA</text>
    <!-- Identical second pass, glow only, slightly offset for depth -->
    <text
      x="0" y="195"
      font-family="'Inter', sans-serif"
      font-weight="900"
      font-size="210"
      letter-spacing="-6"
      fill="none"
      stroke="rgba(20,184,166,0.06)"
      stroke-width="3"
      filter="url(#pepaGlow)"
      paint-order="stroke"
    >PEPA</text>
  </svg>
</div>

<div class="page-wrap">

  <!-- ── Left Hero ──────────────────────────────────────────────────────── -->
  <section class="hero-panel">
    <div class="hero-badge"><i class="fas fa-graduation-cap"></i> PEPA — Multi-College ERP Platform</div>

    <h1 class="hero-title">
      <span class="block">Powering</span>
      <span class="block accent">Education</span>
      <span class="block">Excellence</span>
    </h1>

    <p class="hero-desc">
      PEPA brings colleges, faculty, and students into one unified platform —
      managing admissions, academics, finance, and more with precision.
    </p>

    <div class="stat-row">
      <div class="stat-item">
        <span class="stat-num">3+</span>
        <span class="stat-lbl">Colleges</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">50+</span>
        <span class="stat-lbl">Courses</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">1200+</span>
        <span class="stat-lbl">Students</span>
      </div>
      <div class="stat-item">
        <span class="stat-num">99.9%</span>
        <span class="stat-lbl">Uptime</span>
      </div>
    </div>

    <div class="feature-pills">
      <div class="pill"><i class="fas fa-user-graduate"></i> Student Portal</div>
      <div class="pill"><i class="fas fa-chalkboard-teacher"></i> Faculty Hub</div>
      <div class="pill"><i class="fas fa-chart-pie"></i> Analytics</div>
      <div class="pill"><i class="fas fa-file-invoice-dollar"></i> Finance</div>
      <div class="pill"><i class="fas fa-calendar-check"></i> Attendance</div>
      <div class="pill"><i class="fas fa-bell"></i> Notifications</div>
    </div>
  </section>

  <!-- ── Right Auth Panel ────────────────────────────────────────────────── -->
  <section class="auth-panel">
    <div class="auth-card">

      <!-- Tab Switcher -->
      <div class="tab-switcher">
        <button class="tab-btn active" onclick="switchTab('login')">Sign In</button>
        <button class="tab-btn" onclick="switchTab('register')">Register</button>
      </div>

      <!-- Alert Box -->
      <div class="alert" id="alertBox" role="alert" aria-live="polite">
        <i class="fas fa-circle-exclamation" id="alertIcon"></i>
        <span id="alertMsg"></span>
        <button class="alert-close" onclick="hideAlert()" aria-label="Dismiss">&times;</button>
      </div>

      <!-- ══ LOGIN FORM ══════════════════════════════════════════════════ -->
      <div id="loginForm">
        <h2 class="form-heading">Welcome back</h2>
        <p class="form-sub">Enter your credentials to access the portal</p>

        <div class="input-group">
          <label class="input-label">Username or Email</label>
          <div class="input-wrap">
            <i class="fas fa-user input-icon"></i>
            <input type="text" id="loginUsername" class="form-input" placeholder="username or email" autocomplete="username">
          </div>
        </div>

        <div class="input-group">
          <label class="input-label">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" id="loginPassword" class="form-input" placeholder="••••••••" autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="togglePw('loginPassword', this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <div class="checkbox-row">
          <label class="check-wrap">
            <input type="checkbox" id="rememberMe">
            <span class="check-label">Remember me</span>
          </label>
          <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <button class="btn-submit" id="loginBtn" onclick="doLogin()">
          <span class="btn-text"><i class="fas fa-arrow-right-to-bracket"></i> &nbsp;Sign In</span>
          <span class="spinner"><i class="fas fa-circle-notch spin"></i> Signing in…</span>
        </button>

        <!-- Demo credentials -->
        <div class="demo-box">
          <strong><i class="fas fa-key"></i> &nbsp;Demo Credentials</strong>
          <div class="demo-row"><span>Super Admin</span><span>superadmin / Admin@123</span></div>
          <div class="demo-row"><span>College Admin</span><span>nitadmin / Admin@123</span></div>
          <div class="demo-row"><span>Faculty</span><span>faculty1 / Faculty@1</span></div>
          <div class="demo-row"><span>Student</span><span>student1 / Student@1</span></div>
        </div>
        
        <!-- Accounts Portal link -->
        <a href="auth/accounts_login.php" style="
          display:flex;align-items:center;justify-content:center;gap:10px;
          margin-top:18px;padding:11px 18px;border-radius:10px;text-decoration:none;
          background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
          border:none;box-shadow:0 4px 20px rgba(15,118,110,0.28);
          color:#fff;font-size:0.84rem;font-weight:600;
          transition:transform 0.2s,box-shadow 0.2s;font-family:var(--font-head);
          position:relative;overflow:hidden;"
          onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 10px 40px rgba(0,212,187,0.50)'"
          onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 20px rgba(15,118,110,0.28)'">
          <i class="fas fa-coins"></i>
          Accounts Portal
          <span style="font-weight:400;opacity:0.8;font-size:0.75rem">— Finance &amp; Billing</span>
          <i class="fas fa-arrow-right" style="margin-left:auto;font-size:0.75rem;opacity:0.85"></i>
        </a>
      </div>

      <!-- ══ REGISTER FORM ════════════════════════════════════════════════ -->
      <div id="registerForm">
        <h2 class="form-heading">Create account</h2>
        <p class="form-sub">Join the PEPA platform today</p>

        <div class="form-row">
          <div class="input-group">
            <label class="input-label">Full Name</label>
            <div class="input-wrap">
              <i class="fas fa-id-card input-icon"></i>
              <input type="text" id="regFullname" class="form-input" placeholder="Your full name"
                     oninput="validateField('regFullname')" onblur="validateField('regFullname')">
            </div>
            <div class="field-error" id="err-regFullname"><i class="fas fa-circle-exclamation"></i><span></span></div>
          </div>
          <div class="input-group">
            <label class="input-label">Username</label>
            <div class="input-wrap">
              <i class="fas fa-at input-icon"></i>
              <input type="text" id="regUsername" class="form-input" placeholder="username"
                     oninput="validateField('regUsername')" onblur="validateField('regUsername')">
            </div>
            <div class="field-error" id="err-regUsername"><i class="fas fa-circle-exclamation"></i><span></span></div>
          </div>
        </div>

        <div class="input-group">
          <label class="input-label">Email Address</label>
          <div class="input-wrap">
            <i class="fas fa-envelope input-icon"></i>
            <input type="email" id="regEmail" class="form-input" placeholder="you@college.edu"
                   oninput="validateField('regEmail'); onEmailChange()" onblur="validateField('regEmail')">
          </div>
          <div class="field-error" id="err-regEmail"><i class="fas fa-circle-exclamation"></i><span></span></div>
          <div class="email-verified-badge" id="emailVerifiedBadge">
            <i class="fas fa-circle-check"></i> Email verified
          </div>
          <button type="button" id="sendOtpBtn" onclick="sendOtp()"
                  style="display:none;margin-top:8px;width:100%;padding:10px 14px;
                  background:rgba(15,118,110,0.08);border:1px solid rgba(15,118,110,0.22);
                  border-radius:9px;color:var(--teal2);font-family:var(--font-body);
                  font-size:0.85rem;font-weight:600;cursor:pointer;transition:background 0.2s;">
            <i class="fas fa-paper-plane"></i> &nbsp;Send Verification Code
          </button>
        </div>

        <!-- OTP Verification Step -->
        <div id="otpStep">
          <div class="otp-banner">
            <i class="fas fa-shield-halved"></i>
            <div class="otp-banner-text">
              We sent a 6-digit code to <strong id="otpEmailDisplay"></strong>.
              Enter it below &mdash; it expires in 10&nbsp;minutes.
            </div>
          </div>
          <div class="otp-inputs">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp0">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp1">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp2">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp3">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp4">
            <input class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp5">
          </div>
          <div class="otp-resend">
            Didn't receive it? &nbsp;
            <button type="button" class="otp-resend-btn" id="resendBtn" onclick="sendOtp(true)" disabled>Resend code</button>
            &nbsp;<span id="otpTimerSpan"></span>
          </div>
          <button class="btn-submit" id="verifyOtpBtn" onclick="verifyOtp()">
            <span class="btn-text"><i class="fas fa-check-circle"></i> &nbsp;Verify Email</span>
            <span class="spinner"><i class="fas fa-circle-notch spin"></i> Verifying…</span>
          </button>
          <button type="button" class="otp-back-btn" onclick="cancelOtp()">
            <i class="fas fa-arrow-left"></i> &nbsp;Back to form
          </button>
        </div>

        <!-- Rest of form shown after email verified -->
        <div id="regFields" style="display:none;">

        <div class="form-row">
          <div class="input-group">
            <label class="input-label">Phone</label>
            <div class="input-wrap">
              <i class="fas fa-phone input-icon"></i>
              <input type="tel" id="regPhone" class="form-input" placeholder="+91 XXXXXXXXXX"
                     oninput="validateField('regPhone')" onblur="validateField('regPhone')">
            </div>
            <div class="field-error" id="err-regPhone"><i class="fas fa-circle-exclamation"></i><span></span></div>
          </div>
          <div class="input-group">
            <label class="input-label">Role</label>
            <div class="input-wrap">
              <i class="fas fa-chalkboard-teacher input-icon" style="color:var(--teal)"></i>
              <div class="role-locked">
                Faculty
                <span class="lock-badge"><i class="fas fa-lock" style="margin-right:4px;font-size:0.6rem"></i>FIXED</span>
              </div>
              <input type="hidden" id="regRole" value="faculty">
            </div>
          </div>
        </div>

        <div class="input-group">
          <label class="input-label">College</label>
          <div class="input-wrap">
            <i class="fas fa-university input-icon"></i>
            <select id="regCollege" class="form-select" onchange="loadDepartments(this.value); validateField('regCollege')">
              <option value="">Loading colleges…</option>
            </select>
          </div>
          <div class="field-error" id="err-regCollege"><i class="fas fa-circle-exclamation"></i><span></span></div>
        </div>

        <!-- Department — shown for faculty role -->
        <div class="input-group" id="deptGroup">
          <label class="input-label">Department / Program</label>
          <div class="input-wrap">
            <i class="fas fa-chalkboard input-icon"></i>
            <select id="regDepartment" class="form-select" onchange="validateField('regDepartment')">
              <option value="">— Select College First —</option>
            </select>
          </div>
          <div class="field-error" id="err-regDepartment"><i class="fas fa-circle-exclamation"></i><span></span></div>
        </div>

        <div class="input-group">
          <label class="input-label">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" id="regPassword" class="form-input" placeholder="Create a strong password"
                   oninput="checkStrength(this.value); validateField('regPassword'); validateField('regConfirm');">
            <button type="button" class="toggle-pw" onclick="togglePw('regPassword', this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div class="pwd-strength">
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-label" id="strengthLabel">Enter a password</div>
          </div>
          <div class="pwd-requirements">
            <div class="req-item" id="req-length"><i class="fas fa-circle"></i> Min 8 characters</div>
            <div class="req-item" id="req-upper"><i class="fas fa-circle"></i> 1 uppercase letter</div>
            <div class="req-item" id="req-number"><i class="fas fa-circle"></i> 1 number</div>
            <div class="req-item" id="req-special"><i class="fas fa-circle"></i> 1 special character</div>
          </div>
          <div class="field-error" id="err-regPassword"><i class="fas fa-circle-exclamation"></i><span></span></div>
        </div>

        <div class="input-group">
          <label class="input-label">Confirm Password</label>
          <div class="input-wrap">
            <i class="fas fa-shield-halved input-icon"></i>
            <input type="password" id="regConfirm" class="form-input" placeholder="Repeat password"
                   oninput="validateField('regConfirm')" onblur="validateField('regConfirm')">
            <button type="button" class="toggle-pw" onclick="togglePw('regConfirm', this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div class="field-error" id="err-regConfirm"><i class="fas fa-circle-exclamation"></i><span></span></div>
        </div>

        <button class="btn-submit" id="registerBtn" onclick="doRegister()">
          <span class="btn-text"><i class="fas fa-user-plus"></i> &nbsp;Create Account</span>
          <span class="spinner"><i class="fas fa-circle-notch spin"></i> Creating…</span>
        </button>

        </div><!-- /#regFields -->
      </div>

    </div><!-- /.auth-card -->
  </section>

</div><!-- /.page-wrap -->

<script>
/* ─── Tab Switcher ──────────────────────────────────────────────────────── */
function switchTab(tab) {
  const isLogin = tab === 'login';
  document.getElementById('loginForm').style.display    = isLogin ? 'block' : 'none';
  document.getElementById('registerForm').style.display = isLogin ? 'none'  : 'block';
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    b.classList.toggle('active', (i === 0 && isLogin) || (i === 1 && !isLogin));
  });
  hideAlert();
  if (!isLogin) {
    // Reset verification state when opening register tab
    emailVerified = false;
    document.getElementById('emailVerifiedBadge').classList.remove('show');
    document.getElementById('otpStep').classList.remove('show');
    document.getElementById('regFields').style.display = 'none';
    const btn = document.getElementById('sendOtpBtn');
    btn.style.display = 'none';
    clearInterval(otpTimerInterval);
  }
}

function hideAlert() {
  const box = document.getElementById('alertBox');
  box.classList.remove('show');
  setTimeout(() => {
    if (!box.classList.contains('show')) {
      box.style.display = 'none';
      box.className = 'alert';
    }
  }, 300);
}

function showAlert(msg, type = 'error') {
  const box = document.getElementById('alertBox');
  const icon = document.getElementById('alertIcon');
  box.style.display = '';
  // Force reflow to allow animation restart
  void box.offsetHeight;
  box.className = `alert alert-${type} show`;
  icon.className = type === 'error'
    ? 'fas fa-circle-exclamation'
    : 'fas fa-circle-check';
  document.getElementById('alertMsg').textContent = msg;
}

/* ─── Toggle password visibility ───────────────────────────────────────── */
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}

/* ─── Password strength + requirements ──────────────────────────────────── */
function checkStrength(pw) {
  const checks = {
    'req-length':  pw.length >= 8,
    'req-upper':   /[A-Z]/.test(pw),
    'req-number':  /[0-9]/.test(pw),
    'req-special': /[^A-Za-z0-9]/.test(pw),
  };

  let score = 0;
  for (const [id, met] of Object.entries(checks)) {
    const el = document.getElementById(id);
    if (!el) continue;
    el.classList.toggle('met', met);
    const icon = el.querySelector('i');
    icon.className = met ? 'fas fa-check-circle' : 'fas fa-circle';
    if (met) score++;
  }

  const fill  = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  if (!fill || !label) return;

  const levels = [
    { pct: '0%',   color: 'transparent', text: 'Enter a password' },
    { pct: '25%',  color: '#e76f51',     text: 'Weak' },
    { pct: '50%',  color: '#f4a261',     text: 'Fair' },
    { pct: '75%',  color: '#00c6ae',     text: 'Good' },
    { pct: '100%', color: '#00c6ae',     text: 'Strong ✓' },
  ];
  const l = levels[score];
  fill.style.width      = l.pct;
  fill.style.background = l.color;
  label.textContent     = l.text;
  label.style.color     = score === 0 ? 'var(--muted)' : l.color;
}

/* ─── Per-field real-time validation ─────────────────────────────────────── */
const FIELD_RULES = {
  regFullname:   v => v.trim().length < 3  ? 'Full name must be at least 3 characters.' : '',
  regUsername:   v => v.trim().length < 4  ? 'Username must be at least 4 characters.' :
                      /\s/.test(v.trim())  ? 'Username cannot contain spaces.' : '',
  regEmail:      v => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? 'Enter a valid email address.' : '',
  regPhone:      v => v.trim() && !/^[+\d][\d\s\-]{7,14}$/.test(v.trim()) ? 'Enter a valid phone number.' : '',
  regCollege:    v => !v ? 'Please select your college.' : '',
  regDepartment: v => {
    const dg = document.getElementById('deptGroup');
    if (dg && dg.classList.contains('show') && !v) return 'Please select your department.';
    return '';
  },
  regPassword:   v => {
    if (!v) return 'Password is required.';
    if (v.length < 8) return 'Password must be at least 8 characters.';
    if (!/[A-Z]/.test(v)) return 'Add at least one uppercase letter.';
    if (!/[0-9]/.test(v)) return 'Add at least one number.';
    return '';
  },
  regConfirm:    v => {
    const pw = document.getElementById('regPassword');
    if (!v) return 'Please confirm your password.';
    if (pw && v !== pw.value) return 'Passwords do not match.';
    return '';
  },
};

function validateField(id) {
  const el = document.getElementById(id);
  const errBox = document.getElementById('err-' + id);
  if (!el || !errBox) return true;

  const rule = FIELD_RULES[id];
  if (!rule) return true;

  const msg = rule(el.value);
  const errSpan = errBox.querySelector('span');
  if (errSpan) errSpan.textContent = msg;

  if (msg) {
    el.classList.add('invalid'); el.classList.remove('valid');
    errBox.classList.add('show');
    return false;
  } else {
    el.classList.remove('invalid');
    if (el.value) el.classList.add('valid');
    errBox.classList.remove('show');
    return true;
  }
}

function validateAllFields() {
  const fields = ['regFullname','regUsername','regEmail','regPhone','regCollege','regDepartment','regPassword','regConfirm'];
  return fields.map(f => validateField(f)).every(Boolean);
}

/* ─── Demo credentials (mirrors auth_handler.php demo mode) ─────────────── */
const DEMO_USERS = {
  'superadmin': { password: 'Admin@123',  role: 'super_admin',     full_name: 'Super Administrator', college_id: 1, id: 1 },
  'nitadmin':   { password: 'Admin@123',  role: 'college_admin',   full_name: 'NIT College Admin',   college_id: 1, id: 2 },
  'faculty1':   { password: 'Faculty@1',  role: 'faculty',         full_name: 'Dr. Ramesh Kumar',    college_id: 1, id: 3 },
  'student1':   { password: 'Student@1',  role: 'student',         full_name: 'Arjun Sharma',        college_id: 1, id: 4 },
  'pepaadmin':  { password: 'pepa123',    role: 'ultimate_admin',  full_name: 'Pepa — Ultimate Admin', college_id: null, id: 0 },
};

const DEMO_COLLEGES = [
  { id: 1, name: 'National Institute of Technology', code: 'NIT001' },
  { id: 2, name: 'Global Business School',           code: 'GBS002' },
  { id: 3, name: 'State Engineering College',        code: 'SEC003' },
];

/* Try real backend; if unreachable, fall back to demo data */
async function tryFetch(body, timeoutMs = 4000) {
  const endpoints = [
    'auth_handler.php',
    '../auth/auth_handler.php',
    './auth/auth_handler.php',
  ];
  for (const url of endpoints) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
        signal: AbortSignal.timeout(timeoutMs),
      });
      if (res.ok) return await res.json();
    } catch { /* try next */ }
  }
  return null; // all endpoints failed → demo mode
}

/* ─── Load colleges via AJAX ────────────────────────────────────────────── */
async function loadColleges() {
  const sel = document.getElementById('regCollege');
  const data = await tryFetch('action=get_colleges');
  const colleges = data?.colleges ?? DEMO_COLLEGES;
  sel.innerHTML = '<option value="">— Select College —</option>' +
    colleges.map(c => `<option value="${c.id}">${c.name} (${c.code})</option>`).join('');
  // Reset departments when colleges reload
  document.getElementById('regDepartment').innerHTML = '<option value="">— Select College First —</option>';
}

/* ─── Load departments via AJAX (filtered by college) ───────────────────── */
const DEMO_DEPTS = {
  1: [{id:1,name:'Computer Science & Engineering'},{id:2,name:'Electronics & Communication'},{id:3,name:'Mechanical Engineering'}],
  2: [{id:4,name:'Business Administration'},{id:5,name:'Finance & Accounting'}],
  3: [{id:6,name:'Information Technology'},{id:7,name:'Civil Engineering'}],
};

async function loadDepartments(collegeId) {
  const sel = document.getElementById('regDepartment');
  if (!collegeId) {
    sel.innerHTML = '<option value="">— Select College First —</option>';
    return;
  }
  sel.innerHTML = '<option value="">Loading…</option>';
  const data = await tryFetch(new URLSearchParams({ action: 'get_departments', college_id: collegeId }));
  const depts = data?.departments ?? (DEMO_DEPTS[collegeId] || []);
  if (!depts.length) {
    sel.innerHTML = '<option value="">— No departments found —</option>';
    return;
  }
  sel.innerHTML = '<option value="">— Select Department —</option>' +
    depts.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
}

/* ─── Login ─────────────────────────────────────────────────────────────── */
async function doLogin() {
  hideAlert();
  const username = document.getElementById('loginUsername').value.trim();
  const password = document.getElementById('loginPassword').value;
  const remember = document.getElementById('rememberMe').checked;

  if (!username || !password) { showAlert('Please enter username and password.'); return; }

  const btn = document.getElementById('loginBtn');
  btn.classList.add('loading'); btn.disabled = true;

  const body = new URLSearchParams({ action:'login', username, password, remember: remember ? '1':'' });
  const data = await tryFetch(body);

  if (data) {
    // Real backend responded
    if (data.success) {
      // Check ultimate admin via backend too
      if (data.role === 'ultimate_admin') {
        showAlert(`Welcome, ${data.name}! Entering Ultimate Admin…`, 'success');
        setTimeout(() => { window.location.href = 'ultimate.php'; }, 1200);
        return;
      }
      showAlert(`Welcome, ${data.name}! Redirecting…`, 'success');
      setTimeout(() => { window.location.href = data.redirect; }, 1200);
    } else {
      showAlert(data.message || 'Login failed.');
      btn.classList.remove('loading'); btn.disabled = false;
    }
  } else {
    // Demo mode fallback
    const u = DEMO_USERS[username];
    if (u && u.password === password) {
      if (u.role === 'ultimate_admin') {
        showAlert(`Welcome, ${u.full_name}! Entering Ultimate Admin…`, 'success');
        setTimeout(() => { window.location.href = 'ultimate.php'; }, 1200);
        return;
      }
      showAlert(`Welcome, ${u.full_name}! (Demo Mode)`, 'success');
      setTimeout(() => {
        btn.classList.remove('loading'); btn.disabled = false;
      }, 1200);
    } else {
      showAlert('Invalid credentials. Try superadmin / Admin@123');
      btn.classList.remove('loading'); btn.disabled = false;
    }
  }
}

/* ─── OTP / Email Verification ──────────────────────────────────────────── */
let emailVerified = false;
let otpTimerInterval = null;

function onEmailChange() {
  // If email changes after verification, reset
  if (emailVerified) {
    emailVerified = false;
    document.getElementById('emailVerifiedBadge').classList.remove('show');
    document.getElementById('regFields').style.display = 'none';
    document.getElementById('sendOtpBtn').style.display = '';
    cancelOtp();
  }
  const email = document.getElementById('regEmail').value.trim();
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  const btn = document.getElementById('sendOtpBtn');
  if (!emailVerified) btn.style.display = valid ? '' : 'none';
}

async function sendOtp(isResend = false) {
  const email = document.getElementById('regEmail').value.trim();
  if (!email) { showAlert('Please enter your email address first.'); return; }

  const btn = isResend ? document.getElementById('resendBtn') : document.getElementById('sendOtpBtn');
  btn.disabled = true;
  if (!isResend) btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Sending\u2026';

  const data = await tryFetch(new URLSearchParams({ action: 'send_otp', email }), 15000); // email sending needs more time
  if (!isResend) btn.innerHTML = '<i class="fas fa-paper-plane"></i> &nbsp;Send Verification Code';

  if (data && data.success) {
    document.getElementById('otpEmailDisplay').textContent = email;
    document.getElementById('sendOtpBtn').style.display = 'none';
    document.getElementById('otpStep').classList.add('show');
    document.getElementById('regFields').style.display = 'none';
    // Clear digits
    for (let i = 0; i < 6; i++) {
      const d = document.getElementById('otp' + i);
      d.value = ''; d.classList.remove('filled','error');
    }
    document.getElementById('otp0').focus();
    startOtpTimer(600); // 10 min
    if (isResend) showAlert('A new code was sent to ' + email, 'success');
  } else {
    showAlert(data?.message || 'Failed to send code. Please try again.');
    btn.disabled = false;
  }
}

function startOtpTimer(seconds) {
  clearInterval(otpTimerInterval);
  const resendBtn = document.getElementById('resendBtn');
  const timerSpan = document.getElementById('otpTimerSpan');
  resendBtn.disabled = true;

  let remaining = seconds;
  function tick() {
    const m = Math.floor(remaining / 60);
    const s = remaining % 60;
    timerSpan.textContent = `(${m}:${s.toString().padStart(2,'0')})`;
    if (remaining <= 0) {
      clearInterval(otpTimerInterval);
      timerSpan.textContent = '';
      resendBtn.disabled = false;
    }
    remaining--;
  }
  tick();
  otpTimerInterval = setInterval(tick, 1000);
}

function cancelOtp() {
  document.getElementById('otpStep').classList.remove('show');
  clearInterval(otpTimerInterval);
  const email = document.getElementById('regEmail').value.trim();
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  if (!emailVerified && valid) document.getElementById('sendOtpBtn').style.display = '';
}

async function verifyOtp() {
  const email = document.getElementById('regEmail').value.trim();
  let code = '';
  for (let i = 0; i < 6; i++) code += document.getElementById('otp' + i).value;

  if (code.length < 6) {
    for (let i = 0; i < 6; i++) document.getElementById('otp' + i).classList.add('error');
    showAlert('Please enter the full 6-digit code.');
    return;
  }

  const btn = document.getElementById('verifyOtpBtn');
  btn.classList.add('loading'); btn.disabled = true;

  const data = await tryFetch(new URLSearchParams({ action: 'verify_otp', email, otp: code }));

  btn.classList.remove('loading'); btn.disabled = false;

  if (data && data.success) {
    emailVerified = true;
    clearInterval(otpTimerInterval);
    document.getElementById('otpStep').classList.remove('show');
    document.getElementById('sendOtpBtn').style.display = 'none';
    document.getElementById('emailVerifiedBadge').classList.add('show');
    document.getElementById('regFields').style.display = 'block';
    showAlert('Email verified! Please complete your registration below.', 'success');
    loadColleges();
    // Show department group
    const dg = document.getElementById('deptGroup');
    if (dg) dg.classList.add('show');
  } else {
    for (let i = 0; i < 6; i++) document.getElementById('otp' + i).classList.add('error');
    showAlert(data?.message || 'Invalid code. Please try again.');
  }
}

// OTP digit auto-advance
document.addEventListener('DOMContentLoaded', () => {
  for (let i = 0; i < 6; i++) {
    const inp = document.getElementById('otp' + i);
    inp.addEventListener('input', function() {
      this.classList.remove('error');
      const v = this.value.replace(/\D/g, '');
      this.value = v.slice(-1);
      if (v) {
        this.classList.add('filled');
        if (i < 5) document.getElementById('otp' + (i + 1)).focus();
        else verifyOtp(); // auto-submit on last digit
      } else {
        this.classList.remove('filled');
      }
    });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Backspace' && !this.value && i > 0) {
        document.getElementById('otp' + (i - 1)).focus();
      }
    });
    inp.addEventListener('paste', function(e) {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      for (let j = 0; j < pasted.length && (i + j) < 6; j++) {
        const target = document.getElementById('otp' + (i + j));
        target.value = pasted[j];
        target.classList.add('filled'); target.classList.remove('error');
      }
      const next = Math.min(i + pasted.length, 5);
      document.getElementById('otp' + next).focus();
    });
  }
});

/* ─── Register ──────────────────────────────────────────────────────────── */
async function doRegister() {
  hideAlert();

  if (!emailVerified) {
    showAlert('Please verify your email address before registering.');
    document.getElementById('regEmail').focus();
    return;
  }

  // Run all validations first and show inline errors
  if (!validateAllFields()) {
    showAlert('Please fix the errors highlighted below.');
    return;
  }

  const fullName   = document.getElementById('regFullname').value.trim();
  const username   = document.getElementById('regUsername').value.trim();
  const email      = document.getElementById('regEmail').value.trim();
  const password   = document.getElementById('regPassword').value;
  const confirmPwd = document.getElementById('regConfirm').value;
  const role       = document.getElementById('regRole').value; // always 'faculty'

  const btn = document.getElementById('registerBtn');
  btn.classList.add('loading'); btn.disabled = true;

  const postData = {
    action: 'register', full_name: fullName, username, email,
    phone: document.getElementById('regPhone').value.trim(),
    role, college_id: document.getElementById('regCollege').value,
    faculty_department: document.getElementById('regDepartment').value, // ← FIX: always send department
    password, confirm_password: confirmPwd,
  };

  const data = await tryFetch(new URLSearchParams(postData));

  if (data) {
    if (data.success) {
      showAlert(data.message, 'success');
      setTimeout(() => switchTab('login'), 2500);
    } else {
      showAlert(data.message || 'Registration failed.');
    }
  } else {
    // Demo mode: simulate success
    showAlert('Registration successful! (Demo Mode — no DB connected.) You can now login.', 'success');
    setTimeout(() => switchTab('login'), 2500);
  }

  btn.classList.remove('loading'); btn.disabled = false;
}

/* ─── Enter key support ────────────────────────────────────────────────── */
document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    const loginVisible = document.getElementById('loginForm').style.display !== 'none';
    if (loginVisible) doLogin(); else doRegister();
  }
});

/* ─── Role → Department visibility ─────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // Always show department group (role is fixed to faculty)
  const deptGroup = document.getElementById('deptGroup');
  // deptGroup starts hidden; shown after email verification

  // Ensure alert is fully hidden on page load
  const box = document.getElementById('alertBox');
  box.className = 'alert';
  box.style.display = 'none';
});
</script>

</body>
</html>