<?php
// pages/accounts.php
// ─────────────────────────────────────────────────────────────────────────────
// ACCOUNTS SECTION – strictly scoped to the logged-in user's college_id.
// No cross-college data ever leaks: every query carries WHERE college_id = ?
// ─────────────────────────────────────────────────────────────────────────────

// ── Raise upload limits at runtime (before any output) ────────────────────
@ini_set('upload_max_filesize', '20M');  // per-file limit
@ini_set('post_max_size',       '60M');  // total POST body (7 files × ~5 MB each + form fields)
@ini_set('max_file_uploads',    '20');   // max number of files per request
@ini_set('memory_limit',        '256M');

require_once __DIR__ . '/../includes/config.php';

// ── Ensure session is started ──────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ══════════════════════════════════════════════════════════════════════════
// AUTH: resolve user from either accountant session OR main ERP session
// Supports TWO session types:
//   1. $_SESSION['accountant']  – Accountant logged in via accounts_login.php
//                                  (credentials in account_credentials table)
//   2. $_SESSION['user']        – ERP users: super_admin / college_admin / faculty
//                                  (normal users table)
// ══════════════════════════════════════════════════════════════════════════
$_resolvedUser   = null;
$_isAccountant   = false;   // true = native accountant login

// 1. Accountant native session (set by accounts_auth_handler.php)
if (!empty($_SESSION['accountant']) && ($_SESSION['accountant']['role'] ?? '') === 'accountant') {
    // Session timeout: 4 hours inactivity
    $timeout = 4 * 3600;
    if (isset($_SESSION['_acc_last_activity']) && (time() - $_SESSION['_acc_last_activity']) > $timeout) {
        unset($_SESSION['accountant'], $_SESSION['_acc_last_activity']);
        header('Location: ../auth/accounts_login.php?timeout=1');
        exit;
    }
    $_SESSION['_acc_last_activity'] = time();
    $_resolvedUser = $_SESSION['accountant'];
    $_isAccountant = true;
}

// 2. Main ERP session (super_admin / college_admin / faculty)
if (!$_resolvedUser && !empty($_SESSION['user'])) {
    $erpRole = $_SESSION['user']['role'] ?? '';
    if (in_array($erpRole, ['super_admin', 'college_admin', 'faculty'])) {
        requireAuth();   // uses config.php guard
        $_resolvedUser = $_SESSION['user'];
    }
}

// 3. No valid session → redirect to accounts login
if (!$_resolvedUser) {
    header('Location: ../auth/accounts_login.php');
    exit;
}

// ── Populate standard variables (same names the rest of the file uses) ────
$user      = $_resolvedUser;
$role      = $user['role']          ?? 'accountant';
$userId    = (int)($user['id']      ?? 0);
$collegeId = (int)($user['college_id']    ?? 0);   // ← SCOPE ANCHOR – from session only
$deptId    = (int)($user['department_id'] ?? 0);
$fullName  = $user['full_name']     ?? 'Accountant';

// ── Role gate ──────────────────────────────────────────────────────────────
if (!in_array($role, ['super_admin', 'college_admin', 'faculty', 'accountant'])) {
    if ($_isAccountant) { header('Location: ../auth/accounts_login.php'); }
    else                { header('Location: dashboard.php'); }
    exit;
}

// ── Hard-stop: no college_id means no data, redirect ──────────────────────
if (!$collegeId) {
    if ($_isAccountant) { header('Location: ../auth/accounts_login.php?error=no_college'); }
    else                { header('Location: dashboard.php?error=no_college'); }
    exit;
}

$db = getDB();

// ── Early defaults (needed before POST handler) ────────────────────────────
$collegeName = 'Your College';
$collegeCode = '';

// ── Fetch college code early so POST handler can use it ───────────────────
if ($db) {
    $st = $db->prepare('SELECT name, code FROM colleges WHERE id = ? AND status = "active" LIMIT 1');
    $st->execute([$collegeId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $collegeName = $row['name'];
        $collegeCode = $row['code'];
    }
}

// ── Post-redirect success message ─────────────────────────────────────────
$formError   = '';
$formSuccess = '';
if (!empty($_GET['success'])) {
    $appNo       = htmlspecialchars($_GET['success']);
    $formSuccess = "Application <strong>$appNo</strong> submitted successfully!";
}
// ── Handle Add Fee Entry POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fee_entry') {
    $feeError   = '';
    $feeSuccess = '';
    if ($db) {
        $studentId    = (int)trim($_POST['fee_student_id']   ?? 0);
        $semester     = (int)trim($_POST['fee_semester']     ?? 0);
        $academicYear = trim($_POST['fee_academic_year']     ?? '');
        $feeType      = trim($_POST['fee_type']              ?? '');
        $totalFee     = (float)trim($_POST['fee_total']      ?? 0);
        $discount     = (float)trim($_POST['fee_discount']   ?? 0);
        $lateFee      = (float)trim($_POST['fee_late']       ?? 0);
        $amountPaid   = (float)trim($_POST['fee_amount_paid']?? 0);
        $payMode      = trim($_POST['fee_payment_mode']      ?? 'Cash');
        $txnId        = trim($_POST['fee_transaction_id']    ?? '');
        $payDate      = trim($_POST['fee_payment_date']      ?? date('Y-m-d'));
        $dueDate      = trim($_POST['fee_due_date']          ?? '');

        // Validate student belongs to this college
        $vSt = $db->prepare('SELECT id, full_name FROM users WHERE id = ? AND college_id = ? AND role = "student" LIMIT 1');
        $vSt->execute([$studentId, $collegeId]);
        $studentRow = $vSt->fetch(PDO::FETCH_ASSOC);

        if (!$studentId || !$studentRow) {
            $feeError = 'Invalid student selected.';
        } elseif (!$feeType || !$totalFee || !$academicYear) {
            $feeError = 'Please fill in all required fields.';
        } else {
            $netPayable   = max(0, $totalFee - $discount + $lateFee);
            $balance      = max(0, $netPayable - $amountPaid);
            $feeStatus    = 'Pending';
            if ($amountPaid >= $netPayable && $netPayable > 0) $feeStatus = 'Paid';
            elseif ($amountPaid > 0) $feeStatus = 'Partially Paid';

            try {
                $db->beginTransaction();

                // 1. Insert / update student_fees row
                $insFee = $db->prepare('
                    INSERT INTO student_fees
                      (college_id, student_id, semester, academic_year,
                       fee_type, total_fee, discount_amount, paid_amount,
                       balance_amount, late_fee_amount, due_date, fee_status)
                    VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?)
                ');
                $insFee->execute([
                    $collegeId, $studentId, $semester ?: 1, $academicYear,
                    $feeType, $totalFee, $discount, $amountPaid,
                    $balance, $lateFee, $dueDate ?: null, $feeStatus,
                ]);
                $feeId = (int)$db->lastInsertId();

                // 2. Record a payment entry if amount was paid
                if ($amountPaid > 0) {
                    $receiptNo = 'RCP-' . strtoupper($collegeCode ?: 'FEE') . '-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $insPay = $db->prepare('
                        INSERT INTO fee_payments
                          (college_id, student_id, fee_id, receipt_no,
                           amount_paid, payment_mode, transaction_id,
                           payment_date, payment_status, collected_by)
                        VALUES (?,?,?,?, ?,?,?, ?,?,?)
                    ');
                    $insPay->execute([
                        $collegeId, $studentId, $feeId, $receiptNo,
                        $amountPaid, $payMode, $txnId ?: null,
                        $payDate . ' ' . date('H:i:s'), 'Success', $userId,
                    ]);
                }

                $db->commit();
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=fees&fee_added=1');
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
                $feeError = 'Database error: ' . $e->getMessage();
            }
        }
    } else {
        $feeError = 'No database connection.';
    }
}


// ── Handle Collect Payment POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    if ($db) {
        $cpFeeId    = (int)($_POST['cp_fee_id']       ?? 0);
        $cpStudId   = (int)($_POST['cp_student_id']   ?? 0);
        $cpAmount   = (float)($_POST['cp_amount']     ?? 0);
        $cpMode     = trim($_POST['cp_payment_mode']  ?? 'Cash');
        $cpTxn      = trim($_POST['cp_transaction_id']?? '');
        $cpDate     = trim($_POST['cp_payment_date']  ?? date('Y-m-d'));
        $cpReceipt  = trim($_POST['cp_receipt_no']    ?? '');

        // Verify fee record belongs to this college
        $vFee = $db->prepare('SELECT id, balance_amount, paid_amount, total_fee, discount_amount, late_fee_amount FROM student_fees WHERE id = ? AND college_id = ? LIMIT 1');
        $vFee->execute([$cpFeeId, $collegeId]);
        $feeRow = $vFee->fetch(PDO::FETCH_ASSOC);

        if (!$cpFeeId || !$feeRow || $cpAmount <= 0) {
            $feeError = 'Invalid payment details.';
        } else {
            try {
                $db->beginTransaction();

                // Insert payment record
                if (!$cpReceipt) {
                    $cpReceipt = 'RCP-' . strtoupper($collegeCode ?: 'FEE') . '-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                }
                $insPay = $db->prepare('
                    INSERT INTO fee_payments
                      (college_id, student_id, fee_id, receipt_no,
                       amount_paid, payment_mode, transaction_id,
                       payment_date, payment_status, collected_by)
                    VALUES (?,?,?,?, ?,?,?, ?,?,?)
                ');
                $insPay->execute([
                    $collegeId, $cpStudId, $cpFeeId, $cpReceipt,
                    $cpAmount, $cpMode, $cpTxn ?: null,
                    $cpDate . ' ' . date('H:i:s'), 'Success', $userId,
                ]);

                // Update student_fees totals
                $newPaid    = (float)$feeRow['paid_amount']    + $cpAmount;
                $netPayable = max(0, (float)$feeRow['total_fee'] - (float)$feeRow['discount_amount'] + (float)$feeRow['late_fee_amount']);
                $newBalance = max(0, $netPayable - $newPaid);
                $newStatus  = 'Partially Paid';
                if ($newPaid >= $netPayable && $netPayable > 0) $newStatus = 'Paid';
                elseif ($newPaid <= 0) $newStatus = 'Pending';

                $updFee = $db->prepare('UPDATE student_fees SET paid_amount = ?, balance_amount = ?, fee_status = ? WHERE id = ? AND college_id = ?');
                $updFee->execute([$newPaid, $newBalance, $newStatus, $cpFeeId, $collegeId]);

                $db->commit();
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=fees&fee_added=1');
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
                $feeError = 'Database error: ' . $e->getMessage();
            }
        }
    } else {
        $feeError = 'No database connection.';
    }
}

if (!empty($_GET['fee_added'])) $formSuccess = 'Fee entry added successfully.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_application') {
    if ($db) {
        $fn             = trim($_POST['full_name']          ?? '');
        $email          = trim($_POST['email']              ?? '');
        $mobile         = trim($_POST['mobile']             ?? '');
        $dob            = trim($_POST['dob']                ?? '');
        $gender         = trim($_POST['gender']             ?? '');
        $addr           = trim($_POST['address']            ?? '');
        $rawCid         = trim($_POST['course_id']          ?? '');
        $payS           = trim($_POST['payment_status']     ?? 'Pending');
        // Step 1 extras
        $nationality    = trim($_POST['nationality']        ?? '');
        $bloodGroup     = trim($_POST['blood_group']        ?? '');
        $category       = trim($_POST['category']           ?? '');
        $aadhaar        = trim($_POST['aadhaar']            ?? '');
        $city           = trim($_POST['city']               ?? '');
        $state          = trim($_POST['state']              ?? '');
        $pincode        = trim($_POST['pincode']            ?? '');
        // Step 2
        $admissionType  = trim($_POST['admission_type']     ?? 'Merit');
        $entranceExam   = trim($_POST['entrance_exam']      ?? '');
        $entranceScore  = trim($_POST['entrance_score']     ?? '');
        $scholarship    = trim($_POST['scholarship']        ?? '');
        $qual10Board    = trim($_POST['qual_10_board']      ?? '');
        $qual10School   = trim($_POST['qual_10_school']     ?? '');
        $qual10Year     = trim($_POST['qual_10_year']       ?? '');
        $qual10Pct      = trim($_POST['qual_10_percent']    ?? '');
        $qual12Board    = trim($_POST['qual_12_board']      ?? '');
        $qual12School   = trim($_POST['qual_12_school']     ?? '');
        $qual12Year     = trim($_POST['qual_12_year']       ?? '');
        $qual12Pct      = trim($_POST['qual_12_percent']    ?? '');
        $prevDegree     = trim($_POST['prev_degree']        ?? '');
        $prevUniv       = trim($_POST['prev_university']    ?? '');
        $prevCgpa       = trim($_POST['prev_cgpa']          ?? '');
        // Step 3
        $fatherName     = trim($_POST['father_name']        ?? '');
        $motherName     = trim($_POST['mother_name']        ?? '');
        $guardianMobile = trim($_POST['guardian_mobile']    ?? '');
        $annualIncome   = trim($_POST['annual_income']      ?? '');
        $fatherOcc      = trim($_POST['father_occupation']  ?? '');
        // Step 5
        $txnId          = trim($_POST['transaction_id']     ?? '');

        // ── Server-side validation ───────────────────────────────────────────
        $validationErrors = [];
        if (!$fn)                                                          $validationErrors[] = 'Full Name is required.';
        elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/u', $fn))           $validationErrors[] = 'Full Name must contain only letters, spaces, hyphens or dots.';
        if (!$email)                                                       $validationErrors[] = 'Email Address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))               $validationErrors[] = 'Enter a valid email address.';
        if ($mobile && !preg_match('/^\d{5,15}$/', $mobile))           $validationErrors[] = 'Mobile must be a valid number (5–15 digits).';
        if ($aadhaar && !preg_match('/^\d{4}\s?\d{4}\s?\d{4}$/', $aadhaar))
                                                                           $validationErrors[] = 'Aadhaar must be 12 digits.';
        if ($pincode && !preg_match('/^\d{6}$/', $pincode))              $validationErrors[] = 'PIN Code must be exactly 6 digits.';
        if ($qual10Pct !== '' && ((float)$qual10Pct < 0 || (float)$qual10Pct > 100))
                                                                           $validationErrors[] = '10th Percentage must be between 0 and 100.';
        if ($qual12Pct !== '' && ((float)$qual12Pct < 0 || (float)$qual12Pct > 100))
                                                                           $validationErrors[] = '12th Percentage must be between 0 and 100.';
        if ($prevCgpa !== '' && ((float)$prevCgpa < 0 || (float)$prevCgpa > 10))
                                                                           $validationErrors[] = 'Previous CGPA must be between 0.00 and 10.00.';
        if ($fatherName && !preg_match('/^[A-Za-z\s\.\-\']+$/u', $fatherName))
                                                                           $validationErrors[] = "Father's Name must contain only letters.";
        if ($motherName && !preg_match('/^[A-Za-z\s\.\-\']+$/u', $motherName))
                                                                           $validationErrors[] = "Mother's Name must contain only letters.";
        if ($guardianMobile && !preg_match('/^[6-9]\d{9}$/', $guardianMobile))
                                                                           $validationErrors[] = 'Guardian Mobile must be a valid number (5–15 digits).';
        if (!$rawCid)                                                      $validationErrors[] = 'Course selection is required.';

        if ($validationErrors) {
            $formError = implode(' ', $validationErrors);
        } elseif (!$fn || !$email || !$rawCid) {
            $formError = 'Please fill in all required fields.';
        } else {
            $cid = 0;

            // ── Resolve course_id to a real integer ──────────────────────────
            $isFallback = preg_match('/^F\d+$/i', $rawCid);

            if ($isFallback) {
                // Find the course name/code from our fallback list
                $fallbackMap = [
                    'F01'=>['BE-CSE','B.E. Computer Science & Engineering'],
                    'F02'=>['BE-ECE','B.E. Electronics & Communication Engineering'],
                    'F03'=>['BE-ME','B.E. Mechanical Engineering'],
                    'F04'=>['BE-CE','B.E. Civil Engineering'],
                    'F05'=>['BE-EEE','B.E. Electrical & Electronics Engineering'],
                    'F06'=>['BE-ISE','B.E. Information Science & Engineering'],
                    'F07'=>['BE-CHE','B.E. Chemical Engineering'],
                    'F08'=>['BE-AERO','B.E. Aeronautical Engineering'],
                    'F09'=>['BE-BT','B.E. Biotechnology'],
                    'F10'=>['BTECH-AI','B.Tech. Artificial Intelligence & ML'],
                    'F11'=>['BTECH-DS','B.Tech. Data Science'],
                    'F12'=>['BTECH-CY','B.Tech. Cyber Security'],
                    'F13'=>['ME-CSE','M.E. Computer Science & Engineering'],
                    'F14'=>['ME-VLSI','M.E. VLSI Design'],
                    'F15'=>['ME-SE','M.E. Structural Engineering'],
                    'F16'=>['MTECH-AI','M.Tech. Artificial Intelligence'],
                    'F17'=>['BSC-CS','B.Sc. Computer Science'],
                    'F18'=>['BSC-PCM','B.Sc. Physics, Chemistry & Maths'],
                    'F19'=>['BSC-BZC','B.Sc. Biology, Zoology & Chemistry'],
                    'F20'=>['BSC-STAT','B.Sc. Statistics'],
                    'F21'=>['BCOM','B.Com. General'],
                    'F22'=>['BCOM-H','B.Com. Honours'],
                    'F23'=>['BCOM-CA','B.Com. Computer Applications'],
                    'F24'=>['MCOM','M.Com.'],
                    'F25'=>['BBA','B.B.A. Business Administration'],
                    'F26'=>['MBA','M.B.A. Business Administration'],
                    'F27'=>['MBA-FM','M.B.A. Finance Management'],
                    'F28'=>['MBA-HR','M.B.A. Human Resource Management'],
                    'F29'=>['MBA-MK','M.B.A. Marketing Management'],
                    'F30'=>['BA-ENG','B.A. English Literature'],
                    'F31'=>['BA-ECO','B.A. Economics'],
                    'F32'=>['BA-SOC','B.A. Sociology'],
                    'F33'=>['MA-ENG','M.A. English'],
                    'F34'=>['MA-ECO','M.A. Economics'],
                    'F35'=>['BPHARM','B.Pharm. Pharmacy'],
                    'F36'=>['MPHARM','M.Pharm. Pharmacy'],
                    'F37'=>['DPHARM','D.Pharm. Diploma in Pharmacy'],
                    'F38'=>['MBBS','M.B.B.S.'],
                    'F39'=>['BDS','B.D.S. Dental Surgery'],
                    'F40'=>['BNYS','B.N.Y.S. Naturopathy & Yogic Sciences'],
                    'F41'=>['BAMS','B.A.M.S. Ayurvedic Medicine'],
                    'F42'=>['BSC-NUR','B.Sc. Nursing'],
                    'F43'=>['BSC-MLT','B.Sc. Medical Lab Technology'],
                    'F44'=>['LLB','LL.B. Bachelor of Laws'],
                    'F45'=>['BALLB','B.A. LL.B. (Integrated 5-year)'],
                    'F46'=>['LLM','LL.M. Master of Laws'],
                    'F47'=>['BARCH','B.Arch. Architecture'],
                    'F48'=>['BDES','B.Des. Design'],
                    'F49'=>['BED','B.Ed. Education'],
                    'F50'=>['MED','M.Ed. Education'],
                    'F51'=>['DIP-ME','Diploma – Mechanical Engineering'],
                    'F52'=>['DIP-CE','Diploma – Civil Engineering'],
                    'F53'=>['DIP-ECE','Diploma – Electronics & Communication'],
                    'F54'=>['DIP-CSE','Diploma – Computer Science'],
                    'F55'=>['LAT-BE','Lateral Entry – B.E. (Diploma Holders)'],
                ];

                $fCode = $fallbackMap[$rawCid][0] ?? 'GEN-001';
                $fName = $fallbackMap[$rawCid][1] ?? 'General Course';

                // Check if this course already exists for this college
                $chkF = $db->prepare('SELECT id FROM courses_master WHERE college_id = ? AND course_code = ? LIMIT 1');
                $chkF->execute([$collegeId, $fCode]);
                $existingRow = $chkF->fetch(PDO::FETCH_ASSOC);

                if ($existingRow) {
                    $cid = (int)$existingRow['id'];
                } else {
                    // Insert it so we get a real FK-safe integer ID
                    $ins2 = $db->prepare('INSERT INTO courses_master (college_id, course_name, course_code, status) VALUES (?, ?, ?, "active")');
                    $ins2->execute([$collegeId, $fName, $fCode]);
                    $cid = (int)$db->lastInsertId();
                }

            } else {
                $cid = (int)$rawCid;
                // Check if selected ID comes from departments table
                $deptChk = $db->prepare('SELECT id, name, code FROM departments WHERE id = ? AND college_id = ? LIMIT 1');
                $deptChk->execute([$cid, $collegeId]);
                $deptRow = $deptChk->fetch(PDO::FETCH_ASSOC);

                if ($deptRow) {
                    // Selected from departments — find or create a matching courses_master entry
                    $cmChk = $db->prepare('SELECT id FROM courses_master WHERE college_id = ? AND course_code = ? LIMIT 1');
                    $cmChk->execute([$collegeId, $deptRow['code']]);
                    $cmRow = $cmChk->fetch(PDO::FETCH_ASSOC);
                    if ($cmRow) {
                        $cid = (int)$cmRow['id'];
                    } else {
                        $cmIns = $db->prepare('INSERT INTO courses_master (college_id, course_name, course_code, status) VALUES (?, ?, ?, "active")');
                        $cmIns->execute([$collegeId, $deptRow['name'], $deptRow['code']]);
                        $cid = (int)$db->lastInsertId();
                    }
                } else {
                    // Real courses_master entry — verify it belongs to this college
                    $chk = $db->prepare('SELECT id FROM courses_master WHERE id = ? AND college_id = ? LIMIT 1');
                    $chk->execute([$cid, $collegeId]);
                    if (!$chk->fetch()) {
                        $formError = 'Invalid course selected.';
                        $cid = 0;
                    }
                }
            }

            if ($cid && !$formError) {
                $appNo = 'APP-' . strtoupper($collegeCode ?: 'APP') . '-' . date('Y') . '-' . str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
                $ins = $db->prepare('
                    INSERT INTO applications
                      (college_id, course_id, application_no,
                       full_name, email, mobile, dob, gender,
                       nationality, blood_group, category, aadhaar,
                       address, city, state, pincode,
                       admission_type, entrance_exam, entrance_score, scholarship,
                       qual_10_board, qual_10_school, qual_10_year, qual_10_percent,
                       qual_12_board, qual_12_school, qual_12_year, qual_12_percent,
                       prev_degree, prev_university, prev_cgpa,
                       father_name, mother_name, guardian_mobile, annual_income, father_occupation,
                       application_status, payment_status, transaction_id)
                    VALUES
                      (?,?,?,
                       ?,?,?,?,?,
                       ?,?,?,?,
                       ?,?,?,?,
                       ?,?,?,?,
                       ?,?,?,?,
                       ?,?,?,?,
                       ?,?,?,
                       ?,?,?,?,?,
                       "Submitted",?,?)
                ');
                $ins->execute([
                    $collegeId, $cid, $appNo,
                    $fn, $email, $mobile ?: null, $dob ?: null, $gender ?: null,
                    $nationality ?: null, $bloodGroup ?: null, $category ?: null, $aadhaar ?: null,
                    $addr ?: null, $city ?: null, $state ?: null, $pincode ?: null,
                    $admissionType ?: 'Merit', $entranceExam ?: null, $entranceScore ?: null, $scholarship ?: null,
                    $qual10Board ?: null, $qual10School ?: null, $qual10Year ?: null, $qual10Pct ?: null,
                    $qual12Board ?: null, $qual12School ?: null, $qual12Year ?: null, $qual12Pct ?: null,
                    $prevDegree ?: null, $prevUniv ?: null, $prevCgpa ?: null,
                    $fatherName ?: null, $motherName ?: null, $guardianMobile ?: null, $annualIncome ?: null, $fatherOcc ?: null,
                    $payS, $txnId ?: null,
                ]);
                $newAppId = (int)$db->lastInsertId();

                // ── Save uploaded documents ───────────────────────────────
                $docKeys = ['doc_photo','doc_signature','doc_marks10','doc_marks12','doc_tc','doc_aadhaar','doc_caste'];
                $docLabels = [
                    'doc_photo'      => 'Passport Size Photo',
                    'doc_signature'  => 'Signature',
                    'doc_marks10'    => '10th Marks Card',
                    'doc_marks12'    => '12th Marks Card',
                    'doc_tc'         => 'Transfer Certificate',
                    'doc_aadhaar'    => 'Aadhaar Card',
                    'doc_caste'      => 'Caste / Income Certificate',
                ];
                // Upload directory: <project_root>/uploads/application_docs/<college_id>/<app_id>/
                $uploadBase = __DIR__ . '/../uploads/application_docs/' . $collegeId . '/' . $newAppId . '/';
                if (!is_dir($uploadBase)) @mkdir($uploadBase, 0755, true);

                // Guard: if total POST size exceeded post_max_size, $_FILES will be empty/partial
                $postMaxBytes = (int)ini_get('post_max_size') * 1024 * 1024;
                if (!empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $postMaxBytes) {
                    // Log but continue — save whatever PHP did receive
                    error_log("accounts.php: POST size " . $_SERVER['CONTENT_LENGTH'] . " exceeded post_max_size {$postMaxBytes}");
                }

                $insDoc = $db->prepare('
                    INSERT INTO application_documents
                      (application_id, college_id, doc_key, doc_label, file_name, file_path, file_size, mime_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      file_name=VALUES(file_name), file_path=VALUES(file_path),
                      file_size=VALUES(file_size), mime_type=VALUES(mime_type),
                      uploaded_at=CURRENT_TIMESTAMP
                ');

                $allowedMime = ['image/jpeg','image/png','image/gif','application/pdf'];
                $maxBytes    = 5 * 1024 * 1024; // 5 MB per file (raised from 2 MB)

                foreach ($docKeys as $key) {
                    // Skip if not uploaded or had a PHP-level upload error
                    if (!isset($_FILES[$key])) continue;
                    $file = $_FILES[$key];
                    if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;    // not selected — OK
                    if ($file['error'] !== UPLOAD_ERR_OK) {                 // real error
                        error_log("accounts.php: upload error {$file['error']} for key $key");
                        continue;
                    }
                    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) continue;

                    // Validate MIME from actual file content (not browser header)
                    $mime = mime_content_type($file['tmp_name']);
                    if (!in_array($mime, $allowedMime)) {
                        error_log("accounts.php: rejected MIME $mime for key $key");
                        continue;
                    }

                    // Size check — use actual on-disk size, not the browser-reported value
                    $actualSize = filesize($file['tmp_name']);
                    if ($actualSize > $maxBytes) {
                        error_log("accounts.php: file too large ({$actualSize} bytes) for key $key");
                        continue;
                    }

                    // Unique filename using microseconds so simultaneous uploads never collide
                    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin');
                    $safeName = $key . '_' . str_replace('.', '', microtime(true)) . '_' . mt_rand(100, 999) . '.' . $ext;
                    $dest     = $uploadBase . $safeName;

                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $relPath = 'uploads/application_docs/' . $collegeId . '/' . $newAppId . '/' . $safeName;
                        try {
                            $insDoc->execute([
                                $newAppId, $collegeId, $key, $docLabels[$key],
                                $file['name'], $relPath, $actualSize, $mime
                            ]);
                        } catch (PDOException $e) {
                            error_log("accounts.php: doc insert failed for key $key — " . $e->getMessage());
                        }
                    } else {
                        error_log("accounts.php: move_uploaded_file failed for key $key → $dest");
                    }
                }

                // PRG pattern: redirect after POST so page refresh never re-submits the form
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=applications&success=' . urlencode($appNo));
                exit;
            } elseif (!$formError) {
                $formError = 'Could not resolve course. Please try again.';
            }
        }
    } else {
        // No DB connection — demo mode
        $rawCid = trim($_POST['course_id'] ?? '');
        $fn     = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email']     ?? '');
        if (!$fn || !$email || !$rawCid) {
            $formError = 'Please fill in all required fields.';
        } else {
            $appNo = 'APP-DEMO-' . date('Y') . '-' . str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
            // PRG pattern: redirect after POST so page refresh never re-submits
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=applications&success=' . urlencode($appNo));
            exit;
        }
    }
}

// ── Handle edit application POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_application') {
    $editId = (int)($_POST['edit_id'] ?? 0);
    if ($db && $editId) {
        $chk = $db->prepare('SELECT id FROM applications WHERE id = ? AND college_id = ? LIMIT 1');
        $chk->execute([$editId, $collegeId]);
        if ($chk->fetch()) {
            // Collect every field
            $fn             = trim($_POST['full_name']          ?? '');
            $email          = trim($_POST['email']              ?? '');
            $mobile         = trim($_POST['mobile']             ?? '');
            $dob            = trim($_POST['dob']                ?? '');
            $gender         = trim($_POST['gender']             ?? '');
            $nationality    = trim($_POST['nationality']        ?? '');
            $bloodGroup     = trim($_POST['blood_group']        ?? '');
            $category       = trim($_POST['category']           ?? '');
            $aadhaar        = trim($_POST['aadhaar']            ?? '');
            $addr           = trim($_POST['address']            ?? '');
            $city           = trim($_POST['city']               ?? '');
            $state          = trim($_POST['state']              ?? '');
            $pincode        = trim($_POST['pincode']            ?? '');
            $rawCid         = trim($_POST['course_id']          ?? '');
            $admissionType  = trim($_POST['admission_type']     ?? 'Merit');
            $entranceExam   = trim($_POST['entrance_exam']      ?? '');
            $entranceScore  = trim($_POST['entrance_score']     ?? '');
            $scholarship    = trim($_POST['scholarship']        ?? '');
            $qual10Board    = trim($_POST['qual_10_board']      ?? '');
            $qual10School   = trim($_POST['qual_10_school']     ?? '');
            $qual10Year     = trim($_POST['qual_10_year']       ?? '');
            $qual10Pct      = trim($_POST['qual_10_percent']    ?? '');
            $qual12Board    = trim($_POST['qual_12_board']      ?? '');
            $qual12School   = trim($_POST['qual_12_school']     ?? '');
            $qual12Year     = trim($_POST['qual_12_year']       ?? '');
            $qual12Pct      = trim($_POST['qual_12_percent']    ?? '');
            $prevDegree     = trim($_POST['prev_degree']        ?? '');
            $prevUniv       = trim($_POST['prev_university']    ?? '');
            $prevCgpa       = trim($_POST['prev_cgpa']          ?? '');
            $fatherName     = trim($_POST['father_name']        ?? '');
            $motherName     = trim($_POST['mother_name']        ?? '');
            $guardianMobile = trim($_POST['guardian_mobile']    ?? '');
            $annualIncome   = trim($_POST['annual_income']      ?? '');
            $fatherOcc      = trim($_POST['father_occupation']  ?? '');
            $appSt          = trim($_POST['application_status'] ?? 'Submitted');
            $payS           = trim($_POST['payment_status']     ?? 'Pending');
            $txnId          = trim($_POST['transaction_id']     ?? '');

            // ── Server-side validation for edit ─────────────────────────────
            $validationErrors = [];
            if (!$fn)                                                          $validationErrors[] = 'Full Name is required.';
            elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/u', $fn))           $validationErrors[] = 'Full Name must contain only letters, spaces, hyphens or dots.';
            if (!$email)                                                       $validationErrors[] = 'Email Address is required.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))               $validationErrors[] = 'Enter a valid email address.';
            if ($mobile && !preg_match('/^\d{5,15}$/', $mobile))           $validationErrors[] = 'Mobile must be a valid number (5–15 digits).';
            if ($aadhaar && !preg_match('/^\d{4}\s?\d{4}\s?\d{4}$/', $aadhaar))
                                                                               $validationErrors[] = 'Aadhaar must be 12 digits.';
            if ($pincode && !preg_match('/^\d{6}$/', $pincode))              $validationErrors[] = 'PIN Code must be exactly 6 digits.';
            if ($qual10Pct !== '' && ((float)$qual10Pct < 0 || (float)$qual10Pct > 100))
                                                                               $validationErrors[] = '10th Percentage must be between 0 and 100.';
            if ($qual12Pct !== '' && ((float)$qual12Pct < 0 || (float)$qual12Pct > 100))
                                                                               $validationErrors[] = '12th Percentage must be between 0 and 100.';
            if ($prevCgpa !== '' && ((float)$prevCgpa < 0 || (float)$prevCgpa > 10))
                                                                               $validationErrors[] = 'Previous CGPA must be between 0.00 and 10.00.';
            if ($fatherName && !preg_match('/^[A-Za-z\s\.\-\']+$/u', $fatherName))
                                                                               $validationErrors[] = "Father's Name must contain only letters.";
            if ($motherName && !preg_match('/^[A-Za-z\s\.\-\']+$/u', $motherName))
                                                                               $validationErrors[] = "Mother's Name must contain only letters.";
            if ($guardianMobile && !preg_match('/^[6-9]\d{9}$/', $guardianMobile))
                                                                               $validationErrors[] = 'Guardian Mobile must be a valid number (5–15 digits).';
            if (!$rawCid)                                                      $validationErrors[] = 'Course selection is required.';

            if ($validationErrors) {
                $formError = implode(' ', $validationErrors);
            } elseif (!$fn || !$email || !$rawCid) {
                $formError = 'Please fill in all required fields.';
            } else {
                $cid = (int)$rawCid;
                // Resolve: might be a departments.id or a courses_master.id
                $deptChkE = $db->prepare('SELECT id, name, code FROM departments WHERE id = ? AND college_id = ? LIMIT 1');
                $deptChkE->execute([$cid, $collegeId]);
                $deptRowE = $deptChkE->fetch(PDO::FETCH_ASSOC);
                if ($deptRowE) {
                    $cmChkE = $db->prepare('SELECT id FROM courses_master WHERE college_id = ? AND course_code = ? LIMIT 1');
                    $cmChkE->execute([$collegeId, $deptRowE['code']]);
                    $cmRowE = $cmChkE->fetch(PDO::FETCH_ASSOC);
                    if ($cmRowE) { $cid = (int)$cmRowE['id']; }
                    else {
                        $cmInsE = $db->prepare('INSERT INTO courses_master (college_id, course_name, course_code, status) VALUES (?, ?, ?, "active")');
                        $cmInsE->execute([$collegeId, $deptRowE['name'], $deptRowE['code']]);
                        $cid = (int)$db->lastInsertId();
                    }
                } else {
                    $cChk = $db->prepare('SELECT id FROM courses_master WHERE id = ? AND college_id = ? LIMIT 1');
                    $cChk->execute([$cid, $collegeId]);
                    if (!$cChk->fetch()) { $formError = 'Invalid course selected.'; $cid = 0; }
                }


                if ($cid && !$formError) {
                    $upd = $db->prepare('
                        UPDATE applications SET
                          course_id=?, full_name=?, email=?, mobile=?, dob=?, gender=?,
                          nationality=?, blood_group=?, category=?, aadhaar=?,
                          address=?, city=?, state=?, pincode=?,
                          admission_type=?, entrance_exam=?, entrance_score=?, scholarship=?,
                          qual_10_board=?, qual_10_school=?, qual_10_year=?, qual_10_percent=?,
                          qual_12_board=?, qual_12_school=?, qual_12_year=?, qual_12_percent=?,
                          prev_degree=?, prev_university=?, prev_cgpa=?,
                          father_name=?, mother_name=?, guardian_mobile=?, annual_income=?, father_occupation=?,
                          application_status=?, payment_status=?, transaction_id=?
                        WHERE id=? AND college_id=?
                    ');
                    $upd->execute([
                        $cid, $fn, $email, $mobile ?: null, $dob ?: null, $gender ?: null,
                        $nationality ?: null, $bloodGroup ?: null, $category ?: null, $aadhaar ?: null,
                        $addr ?: null, $city ?: null, $state ?: null, $pincode ?: null,
                        $admissionType ?: 'Merit', $entranceExam ?: null, $entranceScore ?: null, $scholarship ?: null,
                        $qual10Board ?: null, $qual10School ?: null, $qual10Year ?: null, $qual10Pct ?: null,
                        $qual12Board ?: null, $qual12School ?: null, $qual12Year ?: null, $qual12Pct ?: null,
                        $prevDegree ?: null, $prevUniv ?: null, $prevCgpa ?: null,
                        $fatherName ?: null, $motherName ?: null, $guardianMobile ?: null, $annualIncome ?: null, $fatherOcc ?: null,
                        $appSt, $payS, $txnId ?: null,
                        $editId, $collegeId,
                    ]);
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=applications&edited=' . $editId);
                    exit;
                }
            }
        } else {
            $formError = 'Application not found or access denied.';
        }
    }
}

// ── Handle delete application POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_application') {
    $delId = (int)($_POST['delete_id'] ?? 0);
    if ($db && $delId) {
        $del = $db->prepare('DELETE FROM applications WHERE id = ? AND college_id = ?');
        $del->execute([$delId, $collegeId]);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=applications&deleted=1');
    exit;
}

// ── Handle Forward to Principal POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forward_to_principal') {
    $fwdId     = (int)($_POST['fwd_app_id'] ?? 0);
    $fwdResult = ['ok' => false, 'msg' => 'Invalid request'];

    if ($db && $fwdId) {
        // Verify app belongs to this college AND payment is Paid
        $fwdSt = $db->prepare('
            SELECT a.id, a.application_no, a.full_name, a.email, a.payment_status,
                   a.application_status, cm.course_name, cm.course_code
            FROM applications a
            JOIN courses_master cm ON a.course_id = cm.id
            WHERE a.id = ? AND a.college_id = ? LIMIT 1
        ');
        $fwdSt->execute([$fwdId, $collegeId]);
        $fwdApp = $fwdSt->fetch(PDO::FETCH_ASSOC);

        if (!$fwdApp) {
            $fwdResult = ['ok' => false, 'msg' => 'Application not found.'];
        } elseif ($fwdApp['payment_status'] !== 'Paid') {
            $fwdResult = ['ok' => false, 'msg' => 'Application fee not paid. Cannot forward to Principal.'];
        } elseif ($fwdApp['application_status'] === 'Approved') {
            $fwdResult = ['ok' => false, 'msg' => 'Application is already approved/forwarded.'];
        } else {
            // Mark as Under Review (= forwarded to principal)
            $fwdUpd = $db->prepare('UPDATE applications SET application_status = "Under Review", updated_at = NOW() WHERE id = ? AND college_id = ?');
            $fwdUpd->execute([$fwdId, $collegeId]);
            $fwdResult = [
                'ok'     => true,
                'msg'    => 'Application <strong>' . htmlspecialchars($fwdApp['application_no']) . '</strong> forwarded to Principal successfully.',
                'app_no' => $fwdApp['application_no'],
            ];
        }
    }

    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($fwdResult);
        exit;
    }

    // Non-AJAX fallback: redirect with flash message
    $qs = $fwdResult['ok'] ? '?tab=applications&forwarded=' . urlencode($fwdApp['application_no'] ?? '') : '?tab=applications&fwd_error=' . urlencode($fwdResult['msg']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $qs);
    exit;
}

// ── Post-redirect messages for edit/delete/forward ────────────────────────
if (!empty($_GET['edited']))    $formSuccess = 'Application updated successfully.';
if (!empty($_GET['deleted']))   $formSuccess = 'Application deleted successfully.';
if (!empty($_GET['forwarded'])) $formSuccess = 'Application <strong>' . htmlspecialchars($_GET['forwarded']) . '</strong> forwarded to Principal successfully.';
if (!empty($_GET['fwd_error'])) $formError   = htmlspecialchars($_GET['fwd_error']);

// ── Handle Send Fee Reminder (single student) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminder') {
    $remStudentId = (int)($_POST['reminder_student_id'] ?? 0);
    $remResult    = ['ok' => false, 'msg' => 'Invalid request'];

    if ($db && $remStudentId) {
        // Fetch student + fee details — scoped to this college
        $rSt = $db->prepare('
            SELECT u.full_name, u.email, u.roll_number,
                   COALESCE(a.mobile, \'—\') AS mobile,
                   sf.balance_amount, sf.due_date,
                   sf.late_fee_amount, sf.academic_year, sf.semester,
                   DATEDIFF(CURDATE(), sf.due_date) AS days_overdue
            FROM student_fees sf
            JOIN users u ON sf.student_id = u.id
            LEFT JOIN (
                SELECT email, mobile FROM applications
                WHERE college_id = ? ORDER BY id DESC
            ) a ON a.email = u.email
            WHERE sf.student_id = ? AND sf.college_id = ?
              AND sf.fee_status = \'Overdue\'
              AND sf.balance_amount > 0
            ORDER BY sf.due_date ASC
            LIMIT 1
        ');
        $rSt->execute([$collegeId, $remStudentId, $collegeId]);
        $rDef = $rSt->fetch(PDO::FETCH_ASSOC);

        if ($rDef && $rDef['email']) {
            $toEmail   = $rDef['email'];
            $toName    = $rDef['full_name'];
            $balance   = '₹' . number_format((float)$rDef['balance_amount'], 2);
            $dueDate   = !empty($rDef['due_date']) ? date('d M Y', strtotime($rDef['due_date'])) : 'N/A';
            $daysOver  = (int)$rDef['days_overdue'];
            $lateFee   = (float)$rDef['late_fee_amount'] > 0 ? '₹' . number_format((float)$rDef['late_fee_amount'], 2) : 'None';
            $sem       = $rDef['semester'] ? 'Semester ' . $rDef['semester'] : '';
            $yr        = $rDef['academic_year'] ?? '';

            $subject = "Fee Payment Reminder – {$collegeName}";
            $body = "Dear {$toName},

"
                  . "This is a reminder from {$collegeName} regarding your outstanding fee payment.

"
                  . "  • Roll Number   : {$rDef['roll_number']}
"
                  . ($sem   ? "  • Semester      : {$sem}
"    : '')
                  . ($yr    ? "  • Academic Year : {$yr}
"     : '')
                  . "  • Balance Due   : {$balance}
"
                  . "  • Due Date      : {$dueDate}
"
                  . "  • Days Overdue  : {$daysOver} days
"
                  . "  • Late Fee      : {$lateFee}

"
                  . "Please clear the outstanding dues at the earliest to avoid further penalties.

"
                  . "For any queries contact the Accounts Office.

"
                  . "Regards,
{$collegeName} — Accounts Office";

            $headers  = "From: accounts@" . strtolower(preg_replace('/[^a-z0-9]/i', '', $collegeName)) . ".edu.in
";
            $headers .= "Reply-To: accounts@" . strtolower(preg_replace('/[^a-z0-9]/i', '', $collegeName)) . ".edu.in
";
            $headers .= "X-Mailer: PHP/" . phpversion() . "
";
            $headers .= "Content-Type: text/plain; charset=UTF-8
";

            $sent = @mail($toEmail, $subject, $body, $headers);
            if ($sent) {
                $remResult = ['ok' => true,  'msg' => "Reminder sent to {$toName} ({$toEmail})"];
            } else {
                $remResult = ['ok' => false, 'msg' => "mail() failed — check your server SMTP settings."];
            }
        } else {
            $remResult = ['ok' => false, 'msg' => 'No overdue fees or email not found for this student.'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($remResult);
    exit;
}

// ── Handle Send Reminders to ALL defaulters ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_all_reminders') {
    $sent = 0; $failed = 0; $skipped = 0;
    if ($db) {
        $rSt = $db->prepare('
            SELECT DISTINCT u.id, u.full_name, u.email, u.roll_number,
                   COALESCE(a.mobile, \'—\') AS mobile,
                   sf.balance_amount, sf.due_date,
                   sf.late_fee_amount, sf.academic_year, sf.semester,
                   DATEDIFF(CURDATE(), sf.due_date) AS days_overdue
            FROM student_fees sf
            JOIN users u ON sf.student_id = u.id
            LEFT JOIN (
                SELECT email, mobile FROM applications
                WHERE college_id = ? ORDER BY id DESC
            ) a ON a.email = u.email
            WHERE sf.college_id = ?
              AND sf.fee_status = \'Overdue\'
              AND sf.balance_amount > 0
            ORDER BY days_overdue DESC
            LIMIT 100
        ');
        $rSt->execute([$collegeId, $collegeId]);
        $allDef = $rSt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allDef as $rDef) {
            if (empty($rDef['email'])) { $skipped++; continue; }
            $toEmail   = $rDef['email'];
            $toName    = $rDef['full_name'];
            $balance   = '₹' . number_format((float)$rDef['balance_amount'], 2);
            $dueDate   = !empty($rDef['due_date']) ? date('d M Y', strtotime($rDef['due_date'])) : 'N/A';
            $daysOver  = (int)$rDef['days_overdue'];
            $lateFee   = (float)$rDef['late_fee_amount'] > 0 ? '₹' . number_format((float)$rDef['late_fee_amount'], 2) : 'None';
            $sem       = $rDef['semester'] ? 'Semester ' . $rDef['semester'] : '';
            $yr        = $rDef['academic_year'] ?? '';

            $subject = "Fee Payment Reminder – {$collegeName}";
            $body = "Dear {$toName},

"
                  . "This is a reminder from {$collegeName} regarding your outstanding fee payment.

"
                  . "  • Roll Number   : {$rDef['roll_number']}
"
                  . ($sem ? "  • Semester      : {$sem}
" : '')
                  . ($yr  ? "  • Academic Year : {$yr}
"  : '')
                  . "  • Balance Due   : {$balance}
"
                  . "  • Due Date      : {$dueDate}
"
                  . "  • Days Overdue  : {$daysOver} days
"
                  . "  • Late Fee      : {$lateFee}

"
                  . "Please clear the outstanding dues at the earliest to avoid further penalties.

"
                  . "For any queries contact the Accounts Office.

"
                  . "Regards,
{$collegeName} — Accounts Office";

            $headers  = "From: accounts@" . strtolower(preg_replace('/[^a-z0-9]/i', '', $collegeName)) . ".edu.in
";
            $headers .= "Reply-To: accounts@" . strtolower(preg_replace('/[^a-z0-9]/i', '', $collegeName)) . ".edu.in
";
            $headers .= "X-Mailer: PHP/" . phpversion() . "
";
            $headers .= "Content-Type: text/plain; charset=UTF-8
";

            if (@mail($toEmail, $subject, $body, $headers)) $sent++;
            else $failed++;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);
    exit;
}

// ── Load departments for modal dropdown (scoped to this college) ──────────
$courseOptions = [];
if ($db) {
    // First try departments table – show department names as course options
    $st = $db->prepare('SELECT id, name AS course_name, code AS course_code FROM departments WHERE college_id = ? AND status = "active" ORDER BY name');
    $st->execute([$collegeId]);
    $deptRows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($deptRows)) {
        $courseOptions = $deptRows;
        // Mark as department-sourced so we know how to save course_id below
        $courseOptionsSource = 'departments';
    } else {
        // Fall back to courses_master if no departments exist yet
        $st2 = $db->prepare('SELECT id, course_name, course_code FROM courses_master WHERE college_id = ? AND status = "active" ORDER BY course_name');
        $st2->execute([$collegeId]);
        $courseOptions = $st2->fetchAll(PDO::FETCH_ASSOC);
        $courseOptionsSource = 'courses_master';
    }
}

// ── Fallback courses if DB has none (common Indian programmes) ─────────────
$courseOptionsSource = $courseOptionsSource ?? 'fallback';
if (empty($courseOptions)) {
    $courseOptionsSource = 'fallback';
    $courseOptions = [
        // Engineering – UG
        ['id'=>'F01','course_code'=>'BE-CSE',   'course_name'=>'B.E. Computer Science & Engineering'],
        ['id'=>'F02','course_code'=>'BE-ECE',   'course_name'=>'B.E. Electronics & Communication Engineering'],
        ['id'=>'F03','course_code'=>'BE-ME',    'course_name'=>'B.E. Mechanical Engineering'],
        ['id'=>'F04','course_code'=>'BE-CE',    'course_name'=>'B.E. Civil Engineering'],
        ['id'=>'F05','course_code'=>'BE-EEE',   'course_name'=>'B.E. Electrical & Electronics Engineering'],
        ['id'=>'F06','course_code'=>'BE-ISE',   'course_name'=>'B.E. Information Science & Engineering'],
        ['id'=>'F07','course_code'=>'BE-CHE',   'course_name'=>'B.E. Chemical Engineering'],
        ['id'=>'F08','course_code'=>'BE-AERO',  'course_name'=>'B.E. Aeronautical Engineering'],
        ['id'=>'F09','course_code'=>'BE-BT',    'course_name'=>'B.E. Biotechnology'],
        ['id'=>'F10','course_code'=>'BTECH-AI', 'course_name'=>'B.Tech. Artificial Intelligence & ML'],
        ['id'=>'F11','course_code'=>'BTECH-DS', 'course_name'=>'B.Tech. Data Science'],
        ['id'=>'F12','course_code'=>'BTECH-CY', 'course_name'=>'B.Tech. Cyber Security'],
        // Engineering – PG
        ['id'=>'F13','course_code'=>'ME-CSE',   'course_name'=>'M.E. Computer Science & Engineering'],
        ['id'=>'F14','course_code'=>'ME-VLSI',  'course_name'=>'M.E. VLSI Design'],
        ['id'=>'F15','course_code'=>'ME-SE',    'course_name'=>'M.E. Structural Engineering'],
        ['id'=>'F16','course_code'=>'MTECH-AI', 'course_name'=>'M.Tech. Artificial Intelligence'],
        // Science – UG
        ['id'=>'F17','course_code'=>'BSC-CS',   'course_name'=>'B.Sc. Computer Science'],
        ['id'=>'F18','course_code'=>'BSC-PCM',  'course_name'=>'B.Sc. Physics, Chemistry & Maths'],
        ['id'=>'F19','course_code'=>'BSC-BZC',  'course_name'=>'B.Sc. Biology, Zoology & Chemistry'],
        ['id'=>'F20','course_code'=>'BSC-STAT', 'course_name'=>'B.Sc. Statistics'],
        // Commerce
        ['id'=>'F21','course_code'=>'BCOM',     'course_name'=>'B.Com. General'],
        ['id'=>'F22','course_code'=>'BCOM-H',   'course_name'=>'B.Com. Honours'],
        ['id'=>'F23','course_code'=>'BCOM-CA',  'course_name'=>'B.Com. Computer Applications'],
        ['id'=>'F24','course_code'=>'MCOM',     'course_name'=>'M.Com.'],
        // Management
        ['id'=>'F25','course_code'=>'BBA',      'course_name'=>'B.B.A. Business Administration'],
        ['id'=>'F26','course_code'=>'MBA',      'course_name'=>'M.B.A. Business Administration'],
        ['id'=>'F27','course_code'=>'MBA-FM',   'course_name'=>'M.B.A. Finance Management'],
        ['id'=>'F28','course_code'=>'MBA-HR',   'course_name'=>'M.B.A. Human Resource Management'],
        ['id'=>'F29','course_code'=>'MBA-MK',   'course_name'=>'M.B.A. Marketing Management'],
        // Arts & Humanities
        ['id'=>'F30','course_code'=>'BA-ENG',   'course_name'=>'B.A. English Literature'],
        ['id'=>'F31','course_code'=>'BA-ECO',   'course_name'=>'B.A. Economics'],
        ['id'=>'F32','course_code'=>'BA-SOC',   'course_name'=>'B.A. Sociology'],
        ['id'=>'F33','course_code'=>'MA-ENG',   'course_name'=>'M.A. English'],
        ['id'=>'F34','course_code'=>'MA-ECO',   'course_name'=>'M.A. Economics'],
        // Pharmacy
        ['id'=>'F35','course_code'=>'BPHARM',   'course_name'=>'B.Pharm. Pharmacy'],
        ['id'=>'F36','course_code'=>'MPHARM',   'course_name'=>'M.Pharm. Pharmacy'],
        ['id'=>'F37','course_code'=>'DPHARM',   'course_name'=>'D.Pharm. Diploma in Pharmacy'],
        // Medical / Allied Health
        ['id'=>'F38','course_code'=>'MBBS',     'course_name'=>'M.B.B.S.'],
        ['id'=>'F39','course_code'=>'BDS',      'course_name'=>'B.D.S. Dental Surgery'],
        ['id'=>'F40','course_code'=>'BNYS',     'course_name'=>'B.N.Y.S. Naturopathy & Yogic Sciences'],
        ['id'=>'F41','course_code'=>'BAMS',     'course_name'=>'B.A.M.S. Ayurvedic Medicine'],
        ['id'=>'F42','course_code'=>'BSC-NUR',  'course_name'=>'B.Sc. Nursing'],
        ['id'=>'F43','course_code'=>'BSC-MLT',  'course_name'=>'B.Sc. Medical Lab Technology'],
        // Law
        ['id'=>'F44','course_code'=>'LLB',      'course_name'=>'LL.B. Bachelor of Laws'],
        ['id'=>'F45','course_code'=>'BALLB',    'course_name'=>'B.A. LL.B. (Integrated 5-year)'],
        ['id'=>'F46','course_code'=>'LLM',      'course_name'=>'LL.M. Master of Laws'],
        // Architecture & Design
        ['id'=>'F47','course_code'=>'BARCH',    'course_name'=>'B.Arch. Architecture'],
        ['id'=>'F48','course_code'=>'BDES',     'course_name'=>'B.Des. Design'],
        // Education
        ['id'=>'F49','course_code'=>'BED',      'course_name'=>'B.Ed. Education'],
        ['id'=>'F50','course_code'=>'MED',      'course_name'=>'M.Ed. Education'],
        // Diploma / Lateral Entry
        ['id'=>'F51','course_code'=>'DIP-ME',   'course_name'=>'Diploma – Mechanical Engineering'],
        ['id'=>'F52','course_code'=>'DIP-CE',   'course_name'=>'Diploma – Civil Engineering'],
        ['id'=>'F53','course_code'=>'DIP-ECE',  'course_name'=>'Diploma – Electronics & Communication'],
        ['id'=>'F54','course_code'=>'DIP-CSE',  'course_name'=>'Diploma – Computer Science'],
        ['id'=>'F55','course_code'=>'LAT-BE',   'course_name'=>'Lateral Entry – B.E. (Diploma Holders)'],
    ];
}

// ── Defaults (rest) ────────────────────────────────────────────────────────
$totalApplications    = 0;
$pendingApplications  = 0;
$approvedApplications = 0;
$totalStudents        = 0;
$totalFeesPending     = 0.0;
$totalFeesCollected   = 0.0;
$todayCollection      = 0.0;
$overdueStudents      = 0;
$activeTab            = $_GET['tab'] ?? 'overview';

// ── All queries strictly WHERE college_id = $collegeId ─────────────────────
if ($db) {

    // 1. College info already loaded above
    $st = $db->prepare('SELECT COUNT(*) FROM applications WHERE college_id = ?');
    $st->execute([$collegeId]);
    $totalApplications = (int)$st->fetchColumn();

    $st = $db->prepare('SELECT COUNT(*) FROM applications WHERE college_id = ? AND application_status IN ("Submitted","Under Review")');
    $st->execute([$collegeId]);
    $pendingApplications = (int)$st->fetchColumn();

    $st = $db->prepare('SELECT COUNT(*) FROM applications WHERE college_id = ? AND application_status = "Approved"');
    $st->execute([$collegeId]);
    $approvedApplications = (int)$st->fetchColumn();

    // 3. Active students – scoped
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE college_id = ? AND role = "student" AND status = "active"');
    $st->execute([$collegeId]);
    $totalStudents = (int)$st->fetchColumn();

    // 4. Fees – scoped
    $st = $db->prepare('SELECT COALESCE(SUM(balance_amount),0) FROM student_fees WHERE college_id = ? AND fee_status IN ("Pending","Partially Paid","Overdue")');
    $st->execute([$collegeId]);
    $totalFeesPending = (float)$st->fetchColumn();

    $st = $db->prepare('SELECT COALESCE(SUM(paid_amount),0) FROM student_fees WHERE college_id = ?');
    $st->execute([$collegeId]);
    $totalFeesCollected = (float)$st->fetchColumn();

    // 5. Today's collection – scoped
    $st = $db->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE college_id = ? AND DATE(payment_date) = CURDATE() AND payment_status = "Success"');
    $st->execute([$collegeId]);
    $todayCollection = (float)$st->fetchColumn();

    // 6. Overdue students – scoped
    $st = $db->prepare('SELECT COUNT(DISTINCT student_id) FROM student_fees WHERE college_id = ? AND fee_status = "Overdue"');
    $st->execute([$collegeId]);
    $overdueStudents = (int)$st->fetchColumn();
}

// ── Helpers ────────────────────────────────────────────────────────────────
function esc(string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('d M Y', $ts) : '—';
}
function fmt(?float $amount): string {
    return '₹ ' . number_format((float)$amount, 2);
}
function initials(string $name): string {
    $p = explode(' ', trim($name));
    return strtoupper(substr($p[0],0,1) . (isset($p[1]) ? substr($p[1],0,1) : ''));
}
$avatarInitials = initials($fullName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounts · <?= esc($collegeName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Tokens ───────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  /* === DASHBOARD PALETTE (teal/amber/light) === */
  --teal:#0F766E;--teal-dark:#0D5C56;--teal-light:#14B8A6;
  --teal-soft:rgba(20,184,166,.10);--teal-soft2:rgba(20,184,166,.18);
  --teal3:rgba(20,184,166,.10);--teal4:rgba(20,184,166,.18);
  --amber-acc:#F59E0B;--amber-dark:#D97706;
  --amber:#D97706;--amber2:rgba(217,119,6,.14);
  --amber-soft:rgba(245,158,11,.12);--amber-soft2:rgba(245,158,11,.22);
  --page-bg:#F8FAFC;
  --success:#16A34A;--green:#16A34A;--green2:rgba(22,163,74,.12);
  --red:#DC2626;--red2:rgba(220,38,38,.12);
  --purple:#7C3AED;--purple2:rgba(124,58,237,.12);
  --blue:#1D4ED8;--blue2:rgba(29,78,216,.12);
  --white:#FFFFFF;--text:#0F172A;--muted:#475569;
  --border:rgba(15,118,110,.10);--border-accent:rgba(20,184,166,.30);
  --card:#FFFFFF;--card-hover:#F0FDFA;
  --sidebar-w:264px;--top-h:64px;--radius:14px;
  --font:'Plus Jakarta Sans',sans-serif;--mono:'JetBrains Mono',monospace;
  --sb-text:rgba(255,255,255,.78);--sb-muted:rgba(255,255,255,.44);
  --sb-border:rgba(255,255,255,.10);--sb-hover:rgba(255,255,255,.07);
  --sb-active-bg:rgba(245,158,11,.16);--sb-active-border:rgba(245,158,11,.38);
}
html,body{height:100%;font-family:var(--font);background:var(--page-bg);color:var(--text);overflow-x:hidden}

/* ── Ambient BG ───────────────────────────────────────────────────────────── */
.bg{display:none}
.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    radial-gradient(ellipse 70% 40% at 50% -5%,rgba(20,184,166,.07) 0%,transparent 60%),
    linear-gradient(rgba(15,118,110,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(15,118,110,.03) 1px,transparent 1px);
  background-size:auto,48px 48px,48px 48px;
}

/* ── Layout ───────────────────────────────────────────────────────────────── */
.shell{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── Sidebar ──────────────────────────────────────────────────────────────── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(168deg,rgba(13,92,86,.98) 0%,rgba(15,118,110,.95) 40%,rgba(17,140,130,.92) 72%,rgba(13,92,86,.98) 100%);
  backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  -webkit-backdrop-filter:blur(30px) saturate(200%) brightness(1.06);
  border-right:1px solid rgba(255,255,255,.09);
  box-shadow:inset -1px 0 0 rgba(255,255,255,.05),2px 0 50px rgba(15,118,110,.45),8px 0 60px rgba(0,0,0,.14);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;
  transition:transform .3s ease;overflow-y:auto;
}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;z-index:1;
  background:linear-gradient(90deg,transparent 0%,rgba(245,158,11,.55) 30%,rgba(255,255,255,.25) 50%,rgba(245,158,11,.55) 70%,transparent 100%);pointer-events:none}
.sidebar::after{content:'';position:absolute;top:0;right:0;width:1px;bottom:0;
  background:linear-gradient(to bottom,rgba(20,184,166,.22),transparent 45%,rgba(20,184,166,.12) 100%);pointer-events:none}
.sidebar-logo{
  padding:20px 18px 16px;border-bottom:1px solid var(--sb-border);
  display:flex;align-items:center;gap:12px;flex-shrink:0;background:rgba(0,0,0,.12);
}
.logo-mark{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:800;font-size:.9rem;color:#0D5C56;
  box-shadow:0 2px 16px rgba(245,158,11,.40),0 0 0 1px rgba(255,255,255,.15);
}
.logo-text{font-weight:700;font-size:.96rem;color:#fff;line-height:1.15}
.logo-text span{display:block;font-size:.57rem;color:var(--sb-muted);font-weight:400;letter-spacing:.14em;text-transform:uppercase;margin-top:3px}

.scope-chip{
  margin:10px 12px 0;background:rgba(20,184,166,.08);
  border:1px solid rgba(20,184,166,.18);border-radius:10px;padding:10px 12px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);flex-shrink:0;
}
.sc-label{font-size:.54rem;color:var(--teal-light);text-transform:uppercase;letter-spacing:.14em;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:5px}
.sc-college{font-size:.82rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-code{font-size:.68rem;color:var(--sb-muted);margin-top:2px;font-family:var(--mono)}

.sidebar-nav{flex:1;padding:12px 10px 20px;min-height:0}
.sidebar-nav::-webkit-scrollbar{width:3px}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(20,184,166,.22);border-radius:3px}
.nav-label{font-size:.54rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--sb-muted);padding:0 10px;margin:14px 0 4px}
.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 10px;border-radius:9px;margin-bottom:1px;
  color:var(--sb-text);font-size:.82rem;font-weight:500;
  cursor:pointer;text-decoration:none;transition:all .18s;position:relative;border:1px solid transparent;
}
.nav-item i{width:16px;text-align:center;font-size:.82rem;flex-shrink:0}
.nav-item:hover{background:var(--sb-hover);color:#fff;border-color:rgba(255,255,255,.06)}
.nav-item.active{background:var(--sb-active-bg);color:var(--amber-acc);border-color:var(--sb-active-border);box-shadow:inset 0 1px 0 rgba(245,158,11,.12),0 1px 10px rgba(245,158,11,.10)}
.nav-item.active::before{content:'';position:absolute;left:0;top:18%;height:64%;width:3px;background:linear-gradient(to bottom,var(--amber-acc),var(--amber-dark));border-radius:0 3px 3px 0}

.sidebar-user{padding:12px 14px;border-top:1px solid var(--sb-border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(0,0,0,.16);backdrop-filter:blur(10px)}
.user-avatar{
  width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,#F59E0B,#D97706);
  display:grid;place-items:center;font-weight:700;font-size:.82rem;color:#0D5C56;
  box-shadow:0 2px 10px rgba(245,158,11,.28);
}
.user-info{flex:1;min-width:0}
.user-name{font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role-tag{font-size:.65rem;color:var(--teal-light);margin-top:1px;font-family:var(--mono)}
.logout-btn{color:var(--sb-muted);cursor:pointer;padding:5px;border:none;background:none;transition:color .2s;font-size:.9rem;flex-shrink:0}
.logout-btn:hover{color:rgba(252,165,165,.9)}

/* Sidebar overlay (mobile) */
.sidebar-overlay{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.40);backdrop-filter:blur(2px)}
.sidebar-overlay.open{display:block}

/* ── Main ─────────────────────────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0;width:calc(100% - var(--sidebar-w))}

.topbar{
  height:var(--top-h);display:flex;align-items:center;padding:0 24px;gap:12px;
  background:linear-gradient(135deg,#0F766E 0%,#14B8A6 55%,#0F766E 100%);
  border-bottom:1px solid rgba(245,158,11,.18);position:sticky;top:0;z-index:50;
  box-shadow:0 2px 20px rgba(15,118,110,.30),0 1px 0 rgba(245,158,11,.10) inset;
}
.hamburger{display:none}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;flex:1}
.topbar-title span{color:rgba(255,255,255,.52);font-weight:400;font-size:.78rem;margin-left:6px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-btn{
  width:36px;height:36px;border-radius:9px;border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);
  display:grid;place-items:center;cursor:pointer;transition:all .18s;font-size:.82rem;
}
.topbar-btn:hover{border-color:rgba(245,158,11,.50);color:var(--amber-acc);background:rgba(245,158,11,.12)}
.date-chip{font-size:.72rem;color:rgba(255,255,255,.72);background:rgba(255,255,255,.10);border:1px solid rgba(245,158,11,.20);padding:5px 11px;border-radius:7px;font-family:var(--mono)}

/* Topbar avatar dropdown */
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

/* ── Content ──────────────────────────────────────────────────────────────── */
.content{padding:20px 24px;flex:1}

/* Scope banner */
.scope-banner{
  display:flex;align-items:center;flex-wrap:wrap;gap:8px;
  background:var(--teal-soft);border:1px solid var(--border-accent);
  border-radius:10px;padding:10px 16px;margin-bottom:20px;font-size:.79rem;
  animation:slideUp .4s ease both;
}
.scope-banner i{color:var(--teal);flex-shrink:0}
.scope-banner strong{color:var(--text)}
.scope-banner .role-tag{margin-left:auto;font-size:.68rem;font-family:var(--mono);background:var(--teal-soft2);color:var(--teal);padding:3px 8px;border-radius:6px;border:1px solid var(--border-accent)}

/* Page header */
.page-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;animation:slideUp .4s .04s ease both}
.page-title{font-size:1.3rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px}
.page-title i{color:var(--teal)}

/* ── Stats grid ───────────────────────────────────────────────────────────── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{
  background:#fff;border:1px solid var(--border);border-radius:var(--radius);
  padding:18px;display:flex;flex-direction:column;gap:12px;
  animation:slideUp .4s ease both;transition:transform .2s,box-shadow .2s;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.stat:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(15,118,110,.10)}
.stat:nth-child(1){animation-delay:.08s}.stat:nth-child(2){animation-delay:.13s}
.stat:nth-child(3){animation-delay:.18s}.stat:nth-child(4){animation-delay:.23s}
.stat-top{display:flex;align-items:flex-start;justify-content:space-between}
.stat-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:.95rem;flex-shrink:0}
.si-teal{background:var(--teal-soft);color:var(--teal)}
.si-amber{background:var(--amber-soft);color:var(--amber-acc)}
.si-green{background:var(--green2);color:var(--success)}
.si-purple{background:var(--purple2);color:var(--purple)}
.si-red{background:var(--red2);color:var(--red)}
.si-blue{background:var(--blue2);color:var(--blue)}
.tag{font-size:.64rem;padding:3px 7px;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:3px;font-family:var(--mono)}
.tag.up{background:var(--green2);color:var(--success)}
.tag.warn{background:var(--amber-soft);color:var(--amber-acc)}
.tag.alert{background:var(--red2);color:var(--red)}
.tag.info{background:var(--blue2);color:var(--blue)}
.stat-val{font-size:1.7rem;font-weight:800;color:var(--text);line-height:1;font-family:var(--mono)}
.stat-lbl{font-size:.76rem;color:var(--muted);margin-top:2px}
.stat-sub{font-size:.7rem;color:var(--muted);display:flex;align-items:center;gap:5px}
.stat-sub i{font-size:.66rem}

/* ── Tabs ─────────────────────────────────────────────────────────────────── */
.tabs{
  display:flex;gap:4px;border-bottom:2px solid var(--border);
  margin-bottom:20px;animation:slideUp .4s .25s ease both;overflow-x:auto;
}
.tab-link{
  padding:10px 18px;background:none;border:none;border-bottom:2px solid transparent;
  color:var(--muted);font-size:.82rem;font-weight:600;cursor:pointer;
  text-decoration:none;display:flex;align-items:center;gap:7px;
  transition:all .18s;font-family:var(--font);position:relative;top:2px;white-space:nowrap;
}
.tab-link:hover{color:var(--text)}
.tab-link.active{color:var(--teal);border-bottom-color:var(--teal)}
.tab-link i{font-size:.76rem}

/* ── Card ─────────────────────────────────────────────────────────────────── */
.card{
  background:#fff;border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;
  animation:slideUp .45s .28s ease both;
  box-shadow:0 1px 8px rgba(15,118,110,.05);
}
.card-hd{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);background:#F0FDFA;
}
.card-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title i{color:var(--teal);font-size:.8rem}
.card-link{
  font-size:.72rem;color:var(--teal);text-decoration:none;
  border:1px solid var(--border-accent);padding:5px 11px;border-radius:7px;
  transition:all .18s;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;
  background:var(--teal-soft);font-weight:600;
}
.card-link:hover{background:var(--teal-soft2);border-color:var(--teal)}

/* ── Table ────────────────────────────────────────────────────────────────── */
.t-wrap{padding:0 20px 18px;overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:600px}
thead th{
  font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  color:var(--muted);padding:12px 10px;text-align:left;border-bottom:1px solid var(--border);
  white-space:nowrap;background:#F8FAFC;
}
tbody td{padding:11px 10px;font-size:.8rem;color:var(--text);vertical-align:middle}
tbody tr:not(:last-child) td{border-bottom:1px solid var(--border)}
tbody tr:hover td{background:#F0FDFA}
.mono{font-family:var(--mono);font-size:.7rem;color:var(--muted)}
.s-name{font-size:.82rem;font-weight:600;color:var(--text)}
.s-sub{font-size:.68rem;color:var(--muted);margin-top:2px}

/* Pills / badges */
.pill{font-size:.64rem;font-weight:700;padding:3px 8px;border-radius:6px;display:inline-block;white-space:nowrap}
.pill.approved,.pill.success,.pill.paid{background:var(--green2);color:var(--success)}
.pill.pending,.pill.submitted{background:var(--amber-soft);color:var(--amber-acc)}
.pill.review{background:var(--blue2);color:var(--blue)}
.pill.rejected,.pill.overdue,.pill.failed{background:var(--red2);color:var(--red)}
.pill.partial{background:var(--purple2);color:var(--purple)}
.pill.default{background:#F1F5F9;color:var(--muted)}

/* ── Overview cards ───────────────────────────────────────────────────────── */
.ov-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:20px}
.ov-item{
  background:var(--teal-soft);border:1px solid var(--border);border-radius:10px;
  padding:16px;display:flex;align-items:center;gap:14px;transition:border-color .2s,box-shadow .2s;
}
.ov-item:hover{border-color:var(--border-accent);box-shadow:0 4px 16px rgba(15,118,110,.08)}
.ov-icon{width:40px;height:40px;border-radius:9px;display:grid;place-items:center;font-size:.9rem;flex-shrink:0}
.ov-label{font-size:.72rem;color:var(--muted);margin-bottom:3px}
.ov-val{font-size:1.1rem;font-weight:700;color:var(--text);font-family:var(--mono)}
.ov-hint{font-size:.65rem;color:var(--muted);margin-top:3px}

/* ── Empty state ──────────────────────────────────────────────────────────── */
.empty-state{padding:40px;text-align:center;color:var(--muted);font-size:.82rem}
.empty-state i{font-size:2rem;display:block;margin-bottom:10px;opacity:.2}

/* ── Animations ───────────────────────────────────────────────────────────── */
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* ── Scrollbar ────────────────────────────────────────────────────────────── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,118,110,.18);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(20,184,166,.45)}

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media(max-width:1180px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main{margin-left:0;width:100%}
  .content{padding:14px}
  .stats{grid-template-columns:1fr 1fr}
  .hamburger{display:grid}
  .ov-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
  .stats{grid-template-columns:1fr 1fr}
  .tabs{gap:0}
  .tab-link{padding:10px 12px;font-size:.76rem}
  .tab-link span{display:none}
}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="shell">

<?php if (!$_isAccountant): ?>
<!-- ══ SIDEBAR (ERP users only — accountants get no sidebar) ══════════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">EN</div>
    <div class="logo-text">EduNexus <span>ERP Platform</span></div>
  </div>

  <!-- Scoped college chip -->
  <div class="scope-chip">
    <div class="sc-label"><i class="fas fa-shield-halved"></i> Scoped To</div>
    <div class="sc-college"><?= esc($collegeName) ?></div>
    <div class="sc-code"><?= esc($collegeCode) ?> · ID #<?= $collegeId ?></div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a href="dashboard.php" class="nav-item"><i class="fas fa-grid-2"></i> Dashboard</a>
    <a href="students.php"  class="nav-item"><i class="fas fa-user-graduate"></i> Students</a>

    <div class="nav-label">My Teaching</div>
    <a href="accounts.php"    class="nav-item active"><i class="fas fa-money-bill-wave"></i> Accounts</a>
    <a href="attendance.php"  class="nav-item"><i class="fas fa-clipboard-check"></i> Attendance</a>
    <a href="grades_results.php" class="nav-item"><i class="fas fa-chart-bar"></i> Grades &amp; Results</a>
    <a href="examinations.php"   class="nav-item"><i class="fas fa-file-invoice"></i> Examinations</a>
    <a href="timetable.php"  class="nav-item"><i class="fas fa-calendar-days"></i> Timetable</a>

    <div class="nav-label">My Department</div>
    <a href="assignments.php"      class="nav-item"><i class="fas fa-paperclip"></i> Assignments</a>
    <a href="question_bank.php"    class="nav-item"><i class="fas fa-database"></i> Question Bank</a>
    <a href="announcements.php"    class="nav-item"><i class="fas fa-bullhorn"></i> Announcements</a>
    <a href="leave_application.php" class="nav-item"><i class="fas fa-calendar-minus"></i> Leave Application</a>

    <div class="nav-label">Management</div>
    <a href="staff_management.php" class="nav-item"><i class="fas fa-users-gear"></i> Staff Management</a>

    <div class="nav-label">Account</div>
    <a href="profile.php"  class="nav-item"><i class="fas fa-circle-user"></i> My Profile</a>
    <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> Settings</a>
  </nav>

  <div class="sidebar-user">
    <div class="user-avatar"><?= esc($avatarInitials) ?></div>
    <div class="user-info">
      <div class="user-name"><?= esc($fullName) ?></div>
      <div class="user-role-tag"><?= ucfirst(str_replace('_',' ',$role)) ?></div>
    </div>
    <button class="logout-btn" title="Logout" onclick="doLogout()">
      <i class="fas fa-arrow-right-from-bracket"></i>
    </button>
  </div>
</aside>
<?php endif; ?>

<!-- ══ MAIN ════════════════════════════════════════════════════════════════════ -->
<div class="main" <?= $_isAccountant ? 'style="margin-left:0"' : '' ?>>

  <!-- Topbar -->
  <header class="topbar">
    <?php if (!$_isAccountant): ?>
    <button class="topbar-btn hamburger" id="menuToggle" onclick="toggleSidebar()">
      <i class="fas fa-bars"></i>
    </button>
    <?php endif; ?>
    <div class="topbar-title">
      Accounts <span>/ <?= esc($collegeName) ?></span>
    </div>
    <div class="topbar-actions">
      <div class="date-chip" id="topbarDate"></div>
      <?php if ($_isAccountant): ?>
      <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16);border-radius:9px;padding:5px 10px;font-size:.75rem;color:rgba(255,255,255,.75)">
        <div style="width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#F59E0B,#D97706);display:grid;place-items:center;font-weight:700;font-size:.7rem;color:#0D5C56;flex-shrink:0"><?= esc($avatarInitials) ?></div>
        <span style="color:#fff;font-weight:600"><?= esc($fullName) ?></span>
        <span style="color:rgba(255,255,255,.60);font-family:var(--mono);font-size:.65rem">Accountant</span>
      </div>
      <button class="topbar-btn" title="Logout" onclick="doLogout()" style="color:rgba(252,165,165,.9)">
        <i class="fas fa-arrow-right-from-bracket"></i>
      </button>
      <?php else: ?>
      <div class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
      </div>
      <!-- Avatar dropdown -->
      <div class="topbar-avatar-wrap">
        <div class="topbar-avatar" id="topbarAvatar" onclick="toggleAvatarMenu(event)" title="<?= esc($fullName) ?>"><?= esc($avatarInitials) ?></div>
        <div class="avatar-dropdown" id="avatarDropdown">
          <div class="ad-header">
            <div class="ad-name"><?= esc($fullName) ?></div>
            <div class="ad-role"><i class="fas fa-user-shield" style="margin-right:4px"></i><?= esc(ucfirst(str_replace('_',' ',$role))) ?></div>
          </div>
          <a href="profile.php" class="ad-item"><i class="fas fa-circle-user"></i> My Profile</a>
          <a href="settings.php" class="ad-item"><i class="fas fa-gear"></i> Settings</a>
          <div class="ad-sep"></div>
          <button class="ad-item danger" onclick="doLogout()"><i class="fas fa-arrow-right-from-bracket"></i> Logout</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </header>

  <div class="content">

    <!-- ── Scope lock banner ──────────────────────────────────────────────── -->
    <div class="scope-banner">
      <i class="fas fa-shield-halved"></i>
      <span>Data scoped to</span>
      <strong><?= esc($collegeName) ?></strong>
      <?php if($collegeCode): ?>
        <span style="color:var(--muted);font-family:var(--mono);font-size:.7rem">(<?= esc($collegeCode) ?>)</span>
      <?php endif; ?>
      <span style="font-size:.68rem;background:var(--teal-soft2);border:1px solid var(--border-accent);color:var(--teal);padding:2px 7px;border-radius:5px;font-family:var(--mono)">
        <i class="fas fa-lock" style="font-size:.58rem"></i> College ID #<?= $collegeId ?>
      </span>
      <span class="role-tag"><i class="fas fa-user-shield"></i> <?= ucfirst(str_replace('_',' ',$role)) ?></span>
    </div>

    <!-- ── Page header ────────────────────────────────────────────────────── -->
    <div class="page-hd">
      <div class="page-title">
        <i class="fas fa-money-bill-wave"></i>
        Accounts &amp; Fees
      </div>
    </div>

    <!-- ── Stats ─────────────────────────────────────────────────────────── -->
    <div class="stats">
      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-blue"><i class="fas fa-file-alt"></i></div>
          <div class="tag <?= $pendingApplications ? 'warn' : 'up' ?>">
            <i class="fas <?= $pendingApplications ? 'fa-clock' : 'fa-check' ?>"></i>
            <?= $pendingApplications ? $pendingApplications.' pending' : 'All clear' ?>
          </div>
        </div>
        <div>
          <div class="stat-val"><?= number_format($totalApplications) ?></div>
          <div class="stat-lbl">Total Applications</div>
        </div>
        <div class="stat-sub"><i class="fas fa-check-circle" style="color:var(--success)"></i> <?= $approvedApplications ?> approved</div>
      </div>

      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-purple"><i class="fas fa-user-graduate"></i></div>
          <div class="tag info"><i class="fas fa-users"></i> Active</div>
        </div>
        <div>
          <div class="stat-val"><?= number_format($totalStudents) ?></div>
          <div class="stat-lbl">Active Students</div>
        </div>
        <div class="stat-sub"><i class="fas fa-building-columns"></i> <?= esc($collegeName) ?></div>
      </div>

      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-green"><i class="fas fa-money-bill-wave"></i></div>
          <div class="tag up"><i class="fas fa-arrow-up"></i> Collected</div>
        </div>
        <div>
          <div class="stat-val" style="font-size:1.3rem"><?= fmt($totalFeesCollected) ?></div>
          <div class="stat-lbl">Fees Collected</div>
        </div>
        <div class="stat-sub"><i class="fas fa-calendar-day" style="color:var(--teal)"></i> <?= fmt($todayCollection) ?> today</div>
      </div>

      <div class="stat">
        <div class="stat-top">
          <div class="stat-icon si-red"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="tag alert"><i class="fas fa-exclamation-circle"></i> <?= $overdueStudents ?> overdue</div>
        </div>
        <div>
          <div class="stat-val" style="font-size:1.3rem"><?= fmt($totalFeesPending) ?></div>
          <div class="stat-lbl">Pending Dues</div>
        </div>
        <div class="stat-sub"><i class="fas fa-users" style="color:var(--red)"></i> <?= $overdueStudents ?> students overdue</div>
      </div>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────────────────────── -->
    <div class="tabs">
      <a href="?tab=overview"      class="tab-link <?= $activeTab==='overview'      ? 'active':'' ?>"><i class="fas fa-chart-pie"></i> Overview</a>
      <a href="?tab=applications"  class="tab-link <?= $activeTab==='applications'  ? 'active':'' ?>"><i class="fas fa-file-alt"></i> Applications</a>
      <a href="?tab=fees"          class="tab-link <?= $activeTab==='fees'          ? 'active':'' ?>"><i class="fas fa-money-check-alt"></i> Fees</a>
      <a href="?tab=payments"      class="tab-link <?= $activeTab==='payments'      ? 'active':'' ?>"><i class="fas fa-receipt"></i> Payments</a>
      <a href="?tab=defaulters"    class="tab-link <?= $activeTab==='defaulters'    ? 'active':'' ?>"><i class="fas fa-user-times"></i> Defaulters</a>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB CONTENT
    ══════════════════════════════════════════════════════════════════════════ -->

    <?php if ($activeTab === 'overview'): ?>
    <!-- ── OVERVIEW ─────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-chart-line"></i> Financial Snapshot — <?= esc($collegeName) ?></div>
      </div>
      <div class="ov-grid">
        <div class="ov-item">
          <div class="ov-icon si-green"><i class="fas fa-money-bill-wave"></i></div>
          <div>
            <div class="ov-label">Total Fees Collected</div>
            <div class="ov-val"><?= fmt($totalFeesCollected) ?></div>
            <div class="ov-hint"><?= fmt($todayCollection) ?> collected today</div>
          </div>
        </div>
        <div class="ov-item">
          <div class="ov-icon si-red"><i class="fas fa-triangle-exclamation"></i></div>
          <div>
            <div class="ov-label">Total Pending Dues</div>
            <div class="ov-val"><?= fmt($totalFeesPending) ?></div>
            <div class="ov-hint"><?= $overdueStudents ?> students overdue</div>
          </div>
        </div>
        <div class="ov-item">
          <div class="ov-icon si-blue"><i class="fas fa-file-alt"></i></div>
          <div>
            <div class="ov-label">Applications</div>
            <div class="ov-val"><?= number_format($totalApplications) ?></div>
            <div class="ov-hint"><?= $pendingApplications ?> under review · <?= $approvedApplications ?> approved</div>
          </div>
        </div>
        <div class="ov-item">
          <div class="ov-icon si-purple"><i class="fas fa-user-graduate"></i></div>
          <div>
            <div class="ov-label">Active Students</div>
            <div class="ov-val"><?= number_format($totalStudents) ?></div>
            <div class="ov-hint"><?= esc($collegeName) ?></div>
          </div>
        </div>
      </div>
      <div style="padding:0 20px 20px;display:flex;gap:10px">
        <a href="?tab=applications" class="card-link"><i class="fas fa-file-alt"></i> Applications</a>
        <a href="?tab=fees"         class="card-link"><i class="fas fa-money-check-alt"></i> Manage Fees</a>
        <a href="?tab=defaulters"   class="card-link"><i class="fas fa-user-times"></i> Defaulters</a>
      </div>
    </div>

    <?php elseif ($activeTab === 'applications'): ?>
    <!-- ── APPLICATIONS ──────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-file-alt"></i> Student Applications</div>
        <button onclick="openAppModal()" class="card-link" style="cursor:pointer;border:none;font-family:var(--font)"><i class="fas fa-plus"></i> New Application</button>
      </div>

      <?php
      $applications = [];
      if ($db) {
          // college_id = ? ensures only this college's applications
          $st = $db->prepare('
              SELECT a.id, a.application_no, a.full_name, a.email, a.mobile,
                     a.dob, a.gender, a.nationality, a.blood_group, a.category, a.aadhaar,
                     a.address, a.city, a.state, a.pincode,
                     a.admission_type, a.entrance_exam, a.entrance_score, a.scholarship,
                     a.qual_10_board, a.qual_10_school, a.qual_10_year, a.qual_10_percent,
                     a.qual_12_board, a.qual_12_school, a.qual_12_year, a.qual_12_percent,
                     a.prev_degree, a.prev_university, a.prev_cgpa,
                     a.father_name, a.mother_name, a.guardian_mobile, a.annual_income, a.father_occupation,
                     a.application_status, a.payment_status, a.transaction_id,
                     a.submitted_at, a.course_id,
                     cm.course_name, cm.course_code
              FROM applications a
              JOIN courses_master cm ON a.course_id = cm.id
              WHERE a.college_id = ?
              ORDER BY a.submitted_at DESC
              LIMIT 50
          ');
          $st->execute([$collegeId]);
          $applications = $st->fetchAll(PDO::FETCH_ASSOC);

          // ── Fetch documents for all loaded applications ─────────────
          $appDocMap = [];
          if (!empty($applications)) {
              $appIds   = array_column($applications, 'id');
              $inClause = implode(',', array_fill(0, count($appIds), '?'));
              $dSt      = $db->prepare("
                  SELECT application_id, doc_key, doc_label, file_name, file_path, file_size, mime_type
                  FROM application_documents
                  WHERE college_id = ? AND application_id IN ($inClause)
                  ORDER BY uploaded_at DESC
              ");
              $dSt->execute(array_merge([$collegeId], $appIds));
              foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                  $appDocMap[(int)$doc['application_id']][] = $doc;
              }
          }
      }
      ?>

      <?php if (empty($applications)): ?>
        <div class="empty-state"><i class="fas fa-file-circle-xmark"></i>No applications found for <?= esc($collegeName) ?>.</div>
      <?php else: ?>
        <div class="t-wrap">
          <table>
            <thead>
              <tr>
                <th>App No</th>
                <th>Student</th>
                <th>Course</th>
                <th>Contact</th>
                <th>Applied On</th>
                <th>Status</th>
                <th>Payment</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($applications as $app):
                $sc = match($app['application_status']) {
                    'Approved'     => 'approved',
                    'Rejected'     => 'rejected',
                    'Under Review' => 'review',
                    default        => 'submitted'
                };
                $pc = $app['payment_status'] === 'Paid' ? 'paid' : 'pending';
              ?>
              <tr data-app-id="<?= (int)$app['id'] ?>">
                <td><span class="mono"><?= esc($app['application_no']) ?></span></td>
                <td>
                  <div class="s-name"><?= esc($app['full_name']) ?></div>
                  <div class="s-sub"><?= esc($app['email']) ?></div>
                </td>
                <td>
                  <div style="font-size:.78rem;font-weight:600;color:var(--teal)"><?= esc($app['course_code']) ?></div>
                  <div class="s-sub"><?= esc($app['course_name']) ?></div>
                </td>
                <td>
                  <div style="font-size:.78rem"><?= esc($app['email']) ?></div>
                  <div class="s-sub"><?= esc($app['mobile']) ?></div>
                </td>
                <td><span class="mono"><?= fmtDate($app['submitted_at']) ?></span></td>
                <td><span class="pill <?= $sc ?>"><?= esc($app['application_status']) ?></span></td>
                <td><span class="pill <?= $pc ?>"><?= esc($app['payment_status']) ?></span></td>
                <td>
                  <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap">
                    <button onclick="viewApp(<?= (int)$app['id'] ?>)" title="View" style="background:none;border:1px solid var(--border);color:var(--muted);padding:5px 9px;border-radius:7px;cursor:pointer;font-size:.72rem;transition:all .18s" onmouseover="this.style.borderColor='rgba(20,184,166,.3)';this.style.color='var(--teal)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
                      <i class="fas fa-eye"></i>
                    </button>
                    <button onclick="editApp(<?= (int)$app['id'] ?>)" title="Edit" style="background:none;border:1px solid var(--border);color:var(--muted);padding:5px 9px;border-radius:7px;cursor:pointer;font-size:.72rem;transition:all .18s" onmouseover="this.style.borderColor='rgba(59,130,246,.35)';this.style.color='#60a5fa'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
                      <i class="fas fa-pencil"></i>
                    </button>
                    <button onclick="deleteApp(<?= (int)$app['id'] ?>, '<?= esc($app['application_no']) ?>')" title="Delete" style="background:none;border:1px solid var(--border);color:var(--muted);padding:5px 9px;border-radius:7px;cursor:pointer;font-size:.72rem;transition:all .18s" onmouseover="this.style.borderColor='rgba(239,68,68,.3)';this.style.color='var(--red)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
                      <i class="fas fa-trash"></i>
                    </button>
                    <?php if ($app['payment_status'] === 'Paid' && $app['application_status'] !== 'Approved'): ?>
                    <button
                      onclick="forwardToPrincipal(<?= (int)$app['id'] ?>, '<?= esc(addslashes($app['application_no'])) ?>', '<?= esc(addslashes($app['full_name'])) ?>')"
                      title="Forward to Principal"
                      id="fwd-btn-<?= (int)$app['id'] ?>"
                      style="background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.25);color:var(--purple);padding:5px 10px;border-radius:7px;cursor:pointer;font-size:.72rem;font-family:var(--font);font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:all .18s"
                      onmouseover="this.style.background='rgba(124,58,237,.16)';this.style.borderColor='rgba(124,58,237,.45)'"
                      onmouseout="this.style.background='rgba(124,58,237,.08)';this.style.borderColor='rgba(124,58,237,.25)'">
                      <i class="fas fa-paper-plane"></i> Forward
                    </button>
                    <?php elseif ($app['application_status'] === 'Approved'): ?>
                    <span title="Already forwarded" style="font-size:.68rem;color:var(--success);font-weight:600;display:inline-flex;align-items:center;gap:3px;padding:5px 8px">
                      <i class="fas fa-check-circle"></i> Forwarded
                    </span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'fees'): ?>
    <!-- ── FEES ──────────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-money-check-alt"></i> Student Fees</div>
        <button onclick="openFeeModal()" class="card-link" style="cursor:pointer;border:none;font-family:var(--font)"><i class="fas fa-plus"></i> Add Fee Entry</button>
      </div>

      <?php
      $fees = [];
      if ($db) {
          $st = $db->prepare('
              SELECT sf.id, sf.student_id, u.full_name, u.roll_number, sf.semester,
                     sf.academic_year, sf.total_fee, sf.paid_amount,
                     sf.balance_amount, sf.due_date, sf.fee_status
              FROM student_fees sf
              JOIN users u ON sf.student_id = u.id
              WHERE sf.college_id = ?
              ORDER BY sf.due_date ASC, u.full_name ASC
              LIMIT 50
          ');
          $st->execute([$collegeId]);
          $fees = $st->fetchAll(PDO::FETCH_ASSOC);
      }
      ?>

      <?php if (empty($fees)): ?>
        <div class="empty-state"><i class="fas fa-file-invoice-dollar"></i>No fee records found for <?= esc($collegeName) ?>.</div>
      <?php else: ?>
        <div class="t-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th><th>Roll No</th><th>Semester</th><th>Year</th>
                <th>Total Fee</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fees as $fee):
                $fc = match($fee['fee_status']) {
                    'Paid'           => 'paid',
                    'Overdue'        => 'overdue',
                    'Partially Paid' => 'partial',
                    default          => 'pending'
                };
              ?>
              <tr>
                <td><div class="s-name"><?= esc($fee['full_name']) ?></div></td>
                <td><span class="mono"><?= esc($fee['roll_number']) ?></span></td>
                <td><span class="mono">Sem <?= esc($fee['semester']) ?></span></td>
                <td><span class="mono"><?= esc($fee['academic_year']) ?></span></td>
                <td><span class="mono"><?= fmt($fee['total_fee']) ?></span></td>
                <td><span class="mono" style="color:var(--success)"><?= fmt($fee['paid_amount']) ?></span></td>
                <td><span class="mono" style="color:var(--red)"><?= fmt($fee['balance_amount']) ?></span></td>
                <td><span class="mono"><?= fmtDate($fee['due_date']) ?></span></td>
                <td><span class="pill <?= $fc ?>"><?= esc($fee['fee_status']) ?></span></td>
                <td>
                  <?php if ($fee['fee_status'] !== 'Paid'): ?>
                  <button type="button" class="pay-btn" onclick="collectPayment(this)"
                    data-fee-id="<?= (int)$fee['id'] ?>"
                    data-student-id="<?= (int)$fee['student_id'] ?>"
                    data-student-name="<?= esc($fee['full_name']) ?>"
                    data-roll="<?= esc($fee['roll_number'] ?? '') ?>"
                    data-semester="<?= (int)$fee['semester'] ?>"
                    data-year="<?= esc($fee['academic_year']) ?>"
                    data-total="<?= number_format((float)$fee['total_fee'],2,'.','') ?>"
                    data-paid="<?= number_format((float)$fee['paid_amount'],2,'.','') ?>"
                    data-balance="<?= number_format((float)$fee['balance_amount'],2,'.','') ?>">
                    <i class="fas fa-money-bill"></i> Pay
                  </button>
                  <?php else: ?>
                  <span style="color:var(--green);font-size:.72rem;font-weight:600;font-family:var(--font)">
                    <i class="fas fa-circle-check"></i> Paid
                  </span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'payments'): ?>
    <!-- ── PAYMENTS ───────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-receipt"></i> Payment History</div>
      </div>

      <?php
      $payments = [];
      if ($db) {
          $st = $db->prepare('
              SELECT fp.id, fp.receipt_no, fp.payment_date, fp.amount_paid,
                     fp.payment_mode, fp.transaction_id, fp.payment_status,
                     u1.full_name AS student_name, u1.roll_number AS student_roll,
                     u2.full_name AS collector_name,
                     sf.semester, sf.academic_year, sf.fee_type,
                     sf.total_fee, sf.discount_amount, sf.late_fee_amount, sf.balance_amount
              FROM fee_payments fp
              JOIN users u1 ON fp.student_id = u1.id
              LEFT JOIN users u2 ON fp.collected_by = u2.id
              LEFT JOIN student_fees sf ON fp.fee_id = sf.id
              WHERE fp.college_id = ?
              ORDER BY fp.payment_date DESC
              LIMIT 50
          ');
          $st->execute([$collegeId]);
          $payments = $st->fetchAll(PDO::FETCH_ASSOC);
      }
      ?>

      <?php if (empty($payments)): ?>
        <div class="empty-state"><i class="fas fa-receipt"></i>No payment records found for <?= esc($collegeName) ?>.</div>
      <?php else: ?>
        <div class="t-wrap">
          <table>
            <thead>
              <tr><th>Receipt No</th><th>Student</th><th>Date</th><th>Amount</th><th>Mode</th><th>Txn ID</th><th>Status</th><th>Collected By</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $pay):
                $ps = $pay['payment_status'] === 'Success' ? 'success' : ($pay['payment_status'] === 'Failed' ? 'failed' : 'pending');
              ?>
              <tr>
                <td><span class="mono" style="color:var(--purple)"><?= esc($pay['receipt_no']) ?></span></td>
                <td><div class="s-name"><?= esc($pay['student_name']) ?></div></td>
                <td><span class="mono"><?= esc(date('d M Y, h:i A', strtotime($pay['payment_date']))) ?></span></td>
                <td><span class="mono" style="color:var(--green);font-weight:700"><?= fmt($pay['amount_paid']) ?></span></td>
                <td><span class="pill review"><?= esc($pay['payment_mode']) ?></span></td>
                <td><span class="mono" style="font-size:.68rem"><?= esc($pay['transaction_id'] ?? '—') ?></span></td>
                <td><span class="pill <?= $ps ?>"><?= esc($pay['payment_status']) ?></span></td>
                <td><span style="font-size:.78rem"><?= esc($pay['collector_name'] ?? '—') ?></span></td>
                <td>
                  <button type="button" class="print-btn" onclick="printReceipt(this)"
                    data-receipt="<?= esc($pay['receipt_no']) ?>"
                    data-date="<?= esc(date('d M Y, h:i A', strtotime($pay['payment_date']))) ?>"
                    data-student="<?= esc($pay['student_name']) ?>"
                    data-roll="<?= esc($pay['student_roll'] ?? '') ?>"
                    data-amount="<?= number_format((float)$pay['amount_paid'],2,'.','') ?>"
                    data-mode="<?= esc($pay['payment_mode']) ?>"
                    data-txn="<?= esc($pay['transaction_id'] ?? '') ?>"
                    data-status="<?= esc($pay['payment_status']) ?>"
                    data-collector="<?= esc($pay['collector_name'] ?? '') ?>"
                    data-semester="<?= esc($pay['semester'] ?? '') ?>"
                    data-year="<?= esc($pay['academic_year'] ?? '') ?>"
                    data-feetype="<?= esc($pay['fee_type'] ?? 'Tuition Fee') ?>"
                    data-total="<?= number_format((float)($pay['total_fee'] ?? 0),2,'.','') ?>"
                    data-discount="<?= number_format((float)($pay['discount_amount'] ?? 0),2,'.','') ?>"
                    data-latefee="<?= number_format((float)($pay['late_fee_amount'] ?? 0),2,'.','') ?>"
                    data-balance="<?= number_format((float)($pay['balance_amount'] ?? 0),2,'.','') ?>"
                    data-college="<?= esc($collegeName) ?>"
                    data-collegecode="<?= esc($collegeCode) ?>">
                    <i class="fas fa-print"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php elseif ($activeTab === 'defaulters'): ?>
    <!-- ── DEFAULTERS ─────────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-hd">
        <div class="card-title"><i class="fas fa-user-times"></i> Fee Defaulters</div>
        <button onclick="sendReminders()" style="background:var(--amber2);border:1px solid rgba(245,158,11,.3);color:var(--amber);padding:6px 14px;border-radius:7px;cursor:pointer;font-size:.78rem;font-family:var(--font);font-weight:600;display:flex;align-items:center;gap:6px;transition:all .18s" onmouseover="this.style.background='rgba(245,158,11,.2)'" onmouseout="this.style.background='var(--amber2)'">
          <i class="fas fa-envelope"></i> Send Reminders
        </button>
      </div>

      <?php
      $defaulters = [];
      if ($db) {
          $st = $db->prepare('
              SELECT u.id, u.full_name, u.roll_number, u.email,
                     COALESCE(a.mobile, \'—\') AS mobile,
                     sf.id AS fee_id, sf.balance_amount, sf.due_date,
                     sf.late_fee_amount, sf.academic_year, sf.semester,
                     DATEDIFF(CURDATE(), sf.due_date) AS days_overdue
              FROM student_fees sf
              JOIN users u ON sf.student_id = u.id
              LEFT JOIN (
                  SELECT email, mobile
                  FROM applications
                  WHERE college_id = ?
                  ORDER BY id DESC
              ) a ON a.email = u.email
              WHERE sf.college_id = ?
                AND sf.fee_status = \'Overdue\'
                AND sf.balance_amount > 0
              ORDER BY days_overdue DESC
              LIMIT 50
          ');
          $st->execute([$collegeId, $collegeId]);
          $defaulters = $st->fetchAll(PDO::FETCH_ASSOC);
      }
      ?>

      <?php if (empty($defaulters)): ?>
        <div class="empty-state">
          <i class="fas fa-circle-check" style="color:var(--green);opacity:.6"></i>
          No defaulters — all students in <?= esc($collegeName) ?> are up to date!
        </div>
      <?php else: ?>
        <div class="t-wrap">
          <table>
            <thead>
              <tr><th>Student</th><th>Roll No</th><th>Contact</th><th>Balance</th><th>Due Since</th><th>Days Overdue</th><th>Late Fee</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($defaulters as $def): ?>
              <tr style="background:rgba(239,68,68,.03)">
                <td><div class="s-name"><?= esc($def['full_name']) ?></div></td>
                <td><span class="mono"><?= esc($def['roll_number']) ?></span></td>
                <td>
                  <div style="font-size:.78rem"><?= esc($def['email']) ?></div>
                  <div class="s-sub"><?= esc($def['mobile']) ?></div>
                </td>
                <td><span class="mono" style="color:var(--red);font-weight:700"><?= fmt($def['balance_amount']) ?></span></td>
                <td><span class="mono"><?= fmtDate($def['due_date']) ?></span></td>
                <td><span class="pill overdue"><?= (int)$def['days_overdue'] ?> days</span></td>
                <td><span class="mono"><?= fmt($def['late_fee_amount']) ?></span></td>
                <td>
                  <button onclick="sendReminder(<?= (int)$def['id'] ?>, '<?= esc(addslashes($def['full_name'])) ?>', '<?= esc($def['email']) ?>')"
                    id="remind-btn-<?= (int)$def['id'] ?>"
                    style="background:var(--amber2);border:1px solid rgba(245,158,11,.25);color:var(--amber);padding:5px 10px;border-radius:7px;cursor:pointer;font-size:.72rem;font-family:var(--font);font-weight:600;transition:all .18s"
                    onmouseover="this.style.background='rgba(245,158,11,.2)'" onmouseout="this.style.background='var(--amber2)'">
                    <i class="fas fa-envelope"></i> Remind
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /shell -->

<!-- ══ NEW APPLICATION MODAL (5-step) ═════════════════════════════════════════ -->
<div id="appModal" class="modal-overlay" onclick="closeOnOverlay(event)">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="modal-hd">
      <div class="modal-title" id="modalTitle">
        <div class="modal-icon"><i class="fas fa-file-plus"></i></div>
        <div>
          <div style="font-size:.95rem;font-weight:700;color:var(--text)">New Application</div>
          <div style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)"><?= esc($collegeName) ?> · <?= esc($collegeCode) ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="step-chip" id="stepChip">Step 1 of 5</span>
        <button class="modal-close" onclick="closeAppModal()" title="Close"><i class="fas fa-xmark"></i></button>
      </div>
    </div>

    <!-- ── Progress bar ─────────────────────────────────────────────────────── -->
    <div style="height:3px;background:var(--teal-soft);flex-shrink:0">
      <div id="stepProgress" style="height:100%;background:linear-gradient(90deg,var(--teal),var(--teal2));border-radius:2px;width:0%;transition:width .4s ease"></div>
    </div>

    <!-- ── Step indicator ───────────────────────────────────────────────────── -->
    <div class="step-bar" id="stepBar">
      <div class="step-item active" data-step="1"><div class="step-dot">1</div><span>Personal</span></div>
      <div class="step-conn"></div>
      <div class="step-item" data-step="2"><div class="step-dot">2</div><span>Academic</span></div>
      <div class="step-conn"></div>
      <div class="step-item" data-step="3"><div class="step-dot">3</div><span>Parent</span></div>
      <div class="step-conn"></div>
      <div class="step-item" data-step="4"><div class="step-dot">4</div><span>Documents</span></div>
      <div class="step-conn"></div>
      <div class="step-item" data-step="5"><div class="step-dot">5</div><span>Payment</span></div>
    </div>

    <!-- ── Server-side alerts ───────────────────────────────────────────────── -->
    <?php if($formError): ?>
    <div class="modal-alert error"><i class="fas fa-circle-exclamation"></i> <?= esc($formError) ?></div>
    <?php endif; ?>
    <?php if($formSuccess): ?>
    <div class="modal-alert success"><i class="fas fa-circle-check"></i> <?= $formSuccess ?></div>
    <?php endif; ?>

    <!-- ── Form (all 5 steps, POST to same endpoint) ─────────────────────────── -->
    <form method="POST" action="?tab=applications" id="appForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="new_application">

      <!-- ════════════ STEP 1 — PERSONAL ════════════ -->
      <div class="modal-body step-panel active" id="panel-1">

        <div class="mf-section-title"><i class="fas fa-user"></i> Personal Information <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Full Name <span class="req">*</span></label>
            <input type="text" name="full_name" class="mf-input" placeholder="e.g. Rahul Sharma" pattern="[A-Za-z .\-']{2,80}" maxlength="80" title="Letters, spaces, hyphens or dots only" value="<?= esc($_POST['full_name'] ?? '') ?>" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')">
          </div>
          <div class="mf-group">
            <label class="mf-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" class="mf-input" placeholder="student@email.com" value="<?= esc($_POST['email'] ?? '') ?>">
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Mobile Number</label>
            <?php
            $countryCodes = [
              ['AF','+93','Afghanistan'],['AL','+355','Albania'],['DZ','+213','Algeria'],['AD','+376','Andorra'],['AO','+244','Angola'],
              ['AG','+1-268','Antigua & Barbuda'],['AR','+54','Argentina'],['AM','+374','Armenia'],['AU','+61','Australia'],['AT','+43','Austria'],
              ['AZ','+994','Azerbaijan'],['BS','+1-242','Bahamas'],['BH','+973','Bahrain'],['BD','+880','Bangladesh'],['BB','+1-246','Barbados'],
              ['BY','+375','Belarus'],['BE','+32','Belgium'],['BZ','+501','Belize'],['BJ','+229','Benin'],['BT','+975','Bhutan'],
              ['BO','+591','Bolivia'],['BA','+387','Bosnia & Herzegovina'],['BW','+267','Botswana'],['BR','+55','Brazil'],['BN','+673','Brunei'],
              ['BG','+359','Bulgaria'],['BF','+226','Burkina Faso'],['BI','+257','Burundi'],['CV','+238','Cabo Verde'],['KH','+855','Cambodia'],
              ['CM','+237','Cameroon'],['CA','+1','Canada'],['CF','+236','Central African Republic'],['TD','+235','Chad'],['CL','+56','Chile'],
              ['CN','+86','China'],['CO','+57','Colombia'],['KM','+269','Comoros'],['CD','+243','Congo (DRC)'],['CG','+242','Congo (Republic)'],
              ['CR','+506','Costa Rica'],['HR','+385','Croatia'],['CU','+53','Cuba'],['CY','+357','Cyprus'],['CZ','+420','Czech Republic'],
              ['DK','+45','Denmark'],['DJ','+253','Djibouti'],['DM','+1-767','Dominica'],['DO','+1-809','Dominican Republic'],['EC','+593','Ecuador'],
              ['EG','+20','Egypt'],['SV','+503','El Salvador'],['GQ','+240','Equatorial Guinea'],['ER','+291','Eritrea'],['EE','+372','Estonia'],
              ['SZ','+268','Eswatini'],['ET','+251','Ethiopia'],['FJ','+679','Fiji'],['FI','+358','Finland'],['FR','+33','France'],
              ['GA','+241','Gabon'],['GM','+220','Gambia'],['GE','+995','Georgia'],['DE','+49','Germany'],['GH','+233','Ghana'],
              ['GR','+30','Greece'],['GD','+1-473','Grenada'],['GT','+502','Guatemala'],['GN','+224','Guinea'],['GW','+245','Guinea-Bissau'],
              ['GY','+592','Guyana'],['HT','+509','Haiti'],['HN','+504','Honduras'],['HU','+36','Hungary'],['IS','+354','Iceland'],
              ['IN','+91','India'],['ID','+62','Indonesia'],['IR','+98','Iran'],['IQ','+964','Iraq'],['IE','+353','Ireland'],
              ['IL','+972','Israel'],['IT','+39','Italy'],['JM','+1-876','Jamaica'],['JP','+81','Japan'],['JO','+962','Jordan'],
              ['KZ','+7','Kazakhstan'],['KE','+254','Kenya'],['KI','+686','Kiribati'],['KP','+850','Korea (North)'],['KR','+82','Korea (South)'],
              ['KW','+965','Kuwait'],['KG','+996','Kyrgyzstan'],['LA','+856','Laos'],['LV','+371','Latvia'],['LB','+961','Lebanon'],
              ['LS','+266','Lesotho'],['LR','+231','Liberia'],['LY','+218','Libya'],['LI','+423','Liechtenstein'],['LT','+370','Lithuania'],
              ['LU','+352','Luxembourg'],['MG','+261','Madagascar'],['MW','+265','Malawi'],['MY','+60','Malaysia'],['MV','+960','Maldives'],
              ['ML','+223','Mali'],['MT','+356','Malta'],['MH','+692','Marshall Islands'],['MR','+222','Mauritania'],['MU','+230','Mauritius'],
              ['MX','+52','Mexico'],['FM','+691','Micronesia'],['MD','+373','Moldova'],['MC','+377','Monaco'],['MN','+976','Mongolia'],
              ['ME','+382','Montenegro'],['MA','+212','Morocco'],['MZ','+258','Mozambique'],['MM','+95','Myanmar'],['NA','+264','Namibia'],
              ['NR','+674','Nauru'],['NP','+977','Nepal'],['NL','+31','Netherlands'],['NZ','+64','New Zealand'],['NI','+505','Nicaragua'],
              ['NE','+227','Niger'],['NG','+234','Nigeria'],['MK','+389','North Macedonia'],['NO','+47','Norway'],['OM','+968','Oman'],
              ['PK','+92','Pakistan'],['PW','+680','Palau'],['PA','+507','Panama'],['PG','+675','Papua New Guinea'],['PY','+595','Paraguay'],
              ['PE','+51','Peru'],['PH','+63','Philippines'],['PL','+48','Poland'],['PT','+351','Portugal'],['QA','+974','Qatar'],
              ['RO','+40','Romania'],['RU','+7','Russia'],['RW','+250','Rwanda'],['KN','+1-869','Saint Kitts & Nevis'],['LC','+1-758','Saint Lucia'],
              ['VC','+1-784','Saint Vincent & Grenadines'],['WS','+685','Samoa'],['SM','+378','San Marino'],['ST','+239','São Tomé & Príncipe'],
              ['SA','+966','Saudi Arabia'],['SN','+221','Senegal'],['RS','+381','Serbia'],['SC','+248','Seychelles'],['SL','+232','Sierra Leone'],
              ['SG','+65','Singapore'],['SK','+421','Slovakia'],['SI','+386','Slovenia'],['SB','+677','Solomon Islands'],['SO','+252','Somalia'],
              ['ZA','+27','South Africa'],['SS','+211','South Sudan'],['ES','+34','Spain'],['LK','+94','Sri Lanka'],['SD','+249','Sudan'],
              ['SR','+597','Suriname'],['SE','+46','Sweden'],['CH','+41','Switzerland'],['SY','+963','Syria'],['TW','+886','Taiwan'],
              ['TJ','+992','Tajikistan'],['TZ','+255','Tanzania'],['TH','+66','Thailand'],['TL','+670','Timor-Leste'],['TG','+228','Togo'],
              ['TO','+676','Tonga'],['TT','+1-868','Trinidad & Tobago'],['TN','+216','Tunisia'],['TR','+90','Turkey'],['TM','+993','Turkmenistan'],
              ['TV','+688','Tuvalu'],['UG','+256','Uganda'],['UA','+380','Ukraine'],['AE','+971','United Arab Emirates'],['GB','+44','United Kingdom'],
              ['US','+1','United States'],['UY','+598','Uruguay'],['UZ','+998','Uzbekistan'],['VU','+678','Vanuatu'],['VE','+58','Venezuela'],
              ['VN','+84','Vietnam'],['YE','+967','Yemen'],['ZM','+260','Zambia'],['ZW','+263','Zimbabwe'],
            ];
            $savedCode = $_POST['mobile_country_code'] ?? '+91';
            $savedCodeLabel = $savedCode;
            foreach($countryCodes as $cc){ if($cc[1]===$savedCode){ $savedCodeLabel=$cc[1]; break; } }
            ?>
            <div class="phone-wrap" id="phoneWrap_mobile">
              <button type="button" class="phone-code-btn" onclick="togglePhoneDd('mobile')" id="phoneBtn_mobile">
                <?= esc($savedCodeLabel) ?> <i class="fas fa-chevron-down"></i>
              </button>
              <input type="hidden" name="mobile_country_code" id="phoneCode_mobile" value="<?= esc($savedCode) ?>">
              <input type="tel" name="mobile" class="phone-number-input" placeholder="Mobile number" inputmode="numeric" maxlength="15" value="<?= esc($_POST['mobile'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,15)">
              <div class="phone-dd" id="phoneDd_mobile">
                <div class="phone-dd-search"><input type="text" placeholder="Search country…" oninput="filterPhoneDd(this,'mobile')"></div>
                <?php foreach($countryCodes as $cc): ?>
                <div class="phone-dd-item<?= ($cc[1]===$savedCode?' active':'') ?>" onclick="selectPhoneCode('mobile','<?= $cc[1] ?>','<?= $cc[1] ?>')"><?= esc($cc[2]) ?><span><?= esc($cc[1]) ?></span></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mf-group">
            <label class="mf-label">Date of Birth</label>
            <input type="date" name="dob" class="mf-input" value="<?= esc($_POST['dob'] ?? '') ?>">
          </div>
        </div>
        <div class="mf-row3">
          <div class="mf-group">
            <label class="mf-label">Gender <span class="req">*</span></label>
            <select name="gender" class="mf-input">
              <option value="">Select</option>
              <option value="Male"   <?= (($_POST['gender']??'')==='Male'   ?'selected':'') ?>>Male</option>
              <option value="Female" <?= (($_POST['gender']??'')==='Female' ?'selected':'') ?>>Female</option>
              <option value="Other"  <?= (($_POST['gender']??'')==='Other'  ?'selected':'') ?>>Other</option>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Nationality</label>
            <select name="nationality" class="mf-input">
              <?php
              $nationalities = ['Afghanistan','Albania','Algeria','Andorra','Angola','Antigua & Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia & Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo (DRC)','Congo (Republic)','Costa Rica','Croatia','Cuba','Cyprus','Czech Republic','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kiribati','Korea (North)','Korea (South)','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Macedonia','Norway','Oman','Pakistan','Palau','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts & Nevis','Saint Lucia','Saint Vincent & Grenadines','Samoa','San Marino','São Tomé & Príncipe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga','Trinidad & Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
              $savedNat = $_POST['nationality'] ?? 'India';
              foreach($nationalities as $nat):
                $sel = ($savedNat === $nat) ? 'selected' : '';
              ?>
              <option value="<?= $nat ?>" <?= $sel ?>><?= $nat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Blood Group</label>
            <select name="blood_group" class="mf-input">
              <option value="">Select</option>
              <?php foreach(['A+','A−','B+','B−','O+','O−','AB+','AB−'] as $bg): ?>
              <option value="<?= $bg ?>" <?= (($_POST['blood_group']??'')===$bg?'selected':'') ?>><?= $bg ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Category <span class="req">*</span></label>
            <select name="category" class="mf-input">
              <option value="">Select</option>
              <?php foreach(['General','OBC','SC','ST','EWS'] as $cat): ?>
              <option value="<?= $cat ?>" <?= (($_POST['category']??'')===$cat?'selected':'') ?>><?= $cat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Aadhaar Number</label>
            <input type="text" name="aadhaar" class="mf-input" placeholder="XXXX XXXX XXXX" inputmode="numeric" pattern="[0-9 ]{12,14}" maxlength="14" title="12-digit Aadhaar number" value="<?= esc($_POST['aadhaar'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9 ]/g,'').slice(0,14)">
          </div>
        </div>

        <div class="mf-section-title" style="margin-top:4px"><i class="fas fa-location-dot"></i> Address &amp; Contact <div class="mf-section-line"></div></div>
        <div class="mf-group">
          <label class="mf-label">Residential Address</label>
          <textarea name="address" class="mf-input mf-textarea" rows="2" placeholder="Door No, Street, Locality…"><?= esc($_POST['address'] ?? '') ?></textarea>
        </div>
        <div class="mf-row3">
          <div class="mf-group">
            <label class="mf-label">City</label>
            <input type="text" name="city" class="mf-input" placeholder="City" value="<?= esc($_POST['city'] ?? '') ?>">
          </div>
          <div class="mf-group">
            <label class="mf-label">State</label>
            <select name="state" class="mf-input">
              <option value="">Select State</option>
              <?php foreach(['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal'] as $st): ?>
              <option value="<?= $st ?>" <?= (($_POST['state']??'')===$st?'selected':'') ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">PIN Code</label>
            <input type="text" name="pincode" class="mf-input" placeholder="560001" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" title="6-digit PIN code" value="<?= esc($_POST['pincode'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)">
          </div>
        </div>
      </div><!-- /panel-1 -->

      <!-- ════════════ STEP 2 — ACADEMIC ════════════ -->
      <div class="modal-body step-panel" id="panel-2">

        <div class="mf-section-title"><i class="fas fa-graduation-cap"></i> Course Selection <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Course Applying For <span class="req">*</span></label>
            <select name="course_id" class="mf-input" style="max-height:260px">
              <option value="">— Select a course —</option>
              <?php
              $isFallback = !empty($courseOptions) && isset($courseOptions[0]['id']) && str_starts_with((string)$courseOptions[0]['id'], 'F');
              if (!$isFallback):
                // DB-sourced: departments or courses_master — flat list
                foreach ($courseOptions as $c):
                  $cId   = (int)$c['id'];
                  $cCode = $c['course_code'] ?? '';
                  $cName = $c['course_name'] ?? '';
              ?>
              <option value="<?= $cId ?>" <?= (((int)($_POST['course_id']??0))===$cId?'selected':'') ?>>
                <?= $cCode ? esc($cCode) . ' — ' : '' ?><?= esc($cName) ?>
              </option>
              <?php endforeach; else:
              $groups = [
                'Engineering – UG'      => ['F01','F02','F03','F04','F05','F06','F07','F08','F09','F10','F11','F12'],
                'Engineering – PG'      => ['F13','F14','F15','F16'],
                'Science'               => ['F17','F18','F19','F20'],
                'Commerce'              => ['F21','F22','F23','F24'],
                'Management'            => ['F25','F26','F27','F28','F29'],
                'Arts & Humanities'     => ['F30','F31','F32','F33','F34'],
                'Pharmacy'              => ['F35','F36','F37'],
                'Medical & Allied Health' => ['F38','F39','F40','F41','F42','F43'],
                'Law'                   => ['F44','F45','F46'],
                'Architecture & Design' => ['F47','F48'],
                'Education'             => ['F49','F50'],
                'Diploma / Lateral'     => ['F51','F52','F53','F54','F55'],
              ];
              $courseMap = array_column($courseOptions, null, 'id');
              foreach ($groups as $grpLabel => $ids): ?>
              <optgroup label="<?= esc($grpLabel) ?>">
                <?php foreach ($ids as $fid):
                  if (!isset($courseMap[$fid])) continue;
                  $c = $courseMap[$fid]; ?>
                <option value="<?= esc($c['id']) ?>" <?= (($_POST['course_id']??'')===$c['id']?'selected':'') ?>>
                  <?= esc($c['course_code']) ?> — <?= esc($c['course_name']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Admission Type</label>
            <select name="admission_type" class="mf-input">
              <?php foreach(['Merit','Management','Entrance','Lateral'] as $at): ?>
              <option value="<?= $at ?>" <?= (($_POST['admission_type']??'Merit')===$at?'selected':'') ?>><?= $at ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Entrance Exam (if any)</label>
            <select name="entrance_exam" class="mf-input">
              <option value="">None / Not Applicable</option>
              <?php foreach(['KCET','JEE Main','JEE Advanced','NEET','GATE','CAT','MAT','CMAT','XAT'] as $ex): ?>
              <option value="<?= $ex ?>" <?= (($_POST['entrance_exam']??'')===$ex?'selected':'') ?>><?= $ex ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Entrance Score / Rank</label>
            <input type="text" name="entrance_score" class="mf-input" placeholder="Score or Rank" value="<?= esc($_POST['entrance_score'] ?? '') ?>">
          </div>
        </div>
        <div class="mf-group">
          <label class="mf-label">Scholarship Details <span style="color:var(--muted);font-weight:400">(optional)</span></label>
          <input type="text" name="scholarship" class="mf-input" placeholder="Scholarship name, amount, or 'None'" value="<?= esc($_POST['scholarship'] ?? '') ?>">
        </div>

        <div class="mf-section-title"><i class="fas fa-school"></i> 10th Standard <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Board <span class="req">*</span></label>
            <select name="qual_10_board" class="mf-input">
              <option value="">Select Board</option>
              <?php foreach(['CBSE','ICSE','State Board'] as $b): ?>
              <option value="<?= $b ?>" <?= (($_POST['qual_10_board']??'')===$b?'selected':'') ?>><?= $b ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">School Name</label>
            <input type="text" name="qual_10_school" class="mf-input" placeholder="School name" value="<?= esc($_POST['qual_10_school'] ?? '') ?>">
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Year of Passing <span class="req">*</span></label>
            <select name="qual_10_year" class="mf-input">
              <option value="">Select Year</option>
              <?php for($y=date('Y'); $y>=1990; $y--): ?>
              <option value="<?= $y ?>" <?= (($_POST['qual_10_year']??'')==$y?'selected':'') ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Percentage / CGPA <span class="req">*</span></label>
            <input type="text" name="qual_10_percent" class="mf-input" placeholder="e.g. 85.4" inputmode="decimal" pattern="[0-9]{1,3}(\.[0-9]{1,2})?" maxlength="6" title="Percentage 0–100 (numbers only, no % sign)" value="<?= esc($_POST['qual_10_percent'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9.]/g,'')">
          </div>
        </div>

        <div class="mf-section-title"><i class="fas fa-school-flag"></i> 12th / PUC / Diploma <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Board / University <span class="req">*</span></label>
            <select name="qual_12_board" class="mf-input">
              <option value="">Select Board</option>
              <?php foreach(['University','PU Board','Polytechnic'] as $b): ?>
              <option value="<?= $b ?>" <?= (($_POST['qual_12_board']??'')===$b?'selected':'') ?>><?= $b ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">College Name</label>
            <input type="text" name="qual_12_school" class="mf-input" placeholder="College name" value="<?= esc($_POST['qual_12_school'] ?? '') ?>">
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Stream</label>
            <select name="qual_12_stream" class="mf-input">
              <option value="">Select Stream</option>
              <?php foreach(['Science','Commerce','Arts'] as $s): ?>
              <option value="<?= $s ?>" <?= (($_POST['qual_12_stream']??'')===$s?'selected':'') ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">Year of Passing <span class="req">*</span></label>
            <select name="qual_12_year" class="mf-input">
              <option value="">Select Year</option>
              <?php for($y=date('Y'); $y>=1990; $y--): ?>
              <option value="<?= $y ?>" <?= (($_POST['qual_12_year']??'')==$y?'selected':'') ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Percentage / CGPA <span class="req">*</span></label>
            <input type="text" name="qual_12_percent" class="mf-input" placeholder="e.g. 78.2" inputmode="decimal" pattern="[0-9]{1,3}(\.[0-9]{1,2})?" maxlength="6" title="Percentage 0–100 (numbers only, no % sign)" value="<?= esc($_POST['qual_12_percent'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9.]/g,'')">
          </div>
        </div>

        <div class="mf-section-title"><i class="fas fa-university"></i> Degree / Higher <span style="font-size:.65rem;color:var(--muted);font-style:italic;text-transform:none;letter-spacing:0">(if applicable)</span> <div class="mf-section-line"></div></div>
        <div class="mf-row3">
          <div class="mf-group">
            <label class="mf-label">Degree Name</label>
            <select name="prev_degree" class="mf-input">
              <option value="">Select Degree</option>
              <?php foreach(['BSc','BCom','BCA','BA'] as $d): ?>
              <option value="<?= $d ?>" <?= (($_POST['prev_degree']??'')===$d?'selected':'') ?>><?= $d ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group">
            <label class="mf-label">University</label>
            <input type="text" name="prev_university" class="mf-input" placeholder="University name" value="<?= esc($_POST['prev_university'] ?? '') ?>">
          </div>
          <div class="mf-group">
            <label class="mf-label">CGPA / %</label>
            <input type="text" name="prev_cgpa" class="mf-input" placeholder="e.g. 8.5" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" maxlength="5" title="CGPA 0.00–10.00" value="<?= esc($_POST['prev_cgpa'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9.]/g,'')">
          </div>
        </div>
      </div><!-- /panel-2 -->

      <!-- ════════════ STEP 3 — PARENT ════════════ -->
      <div class="modal-body step-panel" id="panel-3">

        <div class="mf-section-title"><i class="fas fa-people-roof"></i> Parent / Guardian Details <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Father's Name <span class="req">*</span></label>
            <input type="text" name="father_name" class="mf-input" placeholder="Father's full name" pattern="[A-Za-z .\-']{2,80}" maxlength="80" title="Letters only" value="<?= esc($_POST['father_name'] ?? '') ?>" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')">
          </div>
          <div class="mf-group">
            <label class="mf-label">Mother's Name <span class="req">*</span></label>
            <input type="text" name="mother_name" class="mf-input" placeholder="Mother's full name" pattern="[A-Za-z .\-']{2,80}" maxlength="80" title="Letters only" value="<?= esc($_POST['mother_name'] ?? '') ?>" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')">
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group">
            <label class="mf-label">Parent / Guardian Mobile <span class="req">*</span></label>
            <div class="phone-wrap" id="phoneWrap_guardian">
              <button type="button" class="phone-code-btn" onclick="togglePhoneDd('guardian')" id="phoneBtn_guardian">
                <?= esc($_POST['guardian_mobile_country_code'] ?? '+91') ?> <i class="fas fa-chevron-down"></i>
              </button>
              <input type="hidden" name="guardian_mobile_country_code" id="phoneCode_guardian" value="<?= esc($_POST['guardian_mobile_country_code'] ?? '+91') ?>">
              <input type="tel" name="guardian_mobile" class="phone-number-input" placeholder="Mobile number" inputmode="numeric" maxlength="15" value="<?= esc($_POST['guardian_mobile'] ?? '') ?>" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,15)">
              <div class="phone-dd" id="phoneDd_guardian">
                <div class="phone-dd-search"><input type="text" placeholder="Search country…" oninput="filterPhoneDd(this,'guardian')"></div>
                <?php foreach($countryCodes as $cc): $a=($_POST['guardian_mobile_country_code']??'+91')===$cc[1]?' active':''; ?>
                <div class="phone-dd-item<?= $a ?>" onclick="selectPhoneCode('guardian','<?= $cc[1] ?>','<?= $cc[1] ?>')"><?= esc($cc[2]) ?><span><?= esc($cc[1]) ?></span></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mf-group">
            <label class="mf-label">Annual Family Income</label>
            <select name="annual_income" class="mf-input">
              <option value="">Select range</option>
              <?php foreach(['Below ₹1 Lakh','₹1 – 2.5 Lakhs','₹2.5 – 5 Lakhs','₹5 – 10 Lakhs','Above ₹10 Lakhs'] as $inc): ?>
              <option value="<?= $inc ?>" <?= (($_POST['annual_income']??'')===$inc?'selected':'') ?>><?= $inc ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mf-group" style="max-width:50%;padding-right:7px">
          <label class="mf-label">Father's Occupation</label>
          <input type="text" name="father_occupation" class="mf-input" placeholder="e.g. Farmer, Engineer, Business" value="<?= esc($_POST['father_occupation'] ?? '') ?>">
        </div>
      </div><!-- /panel-3 -->

      <!-- ════════════ STEP 4 — DOCUMENTS ════════════ -->
      <div class="modal-body step-panel" id="panel-4">

        <div class="mf-section-title"><i class="fas fa-paperclip"></i> Document Uploads <div class="mf-section-line"></div></div>
        <div class="mf-doc-note">
          <i class="fas fa-circle-info"></i>
          Accepted formats: <strong>PDF, JPG, PNG</strong> — Max size: <strong>5 MB</strong> per file
        </div>

        <?php
        $docList = [
          ['key'=>'doc_photo',     'label'=>'Passport Size Photo',       'icon'=>'fa-image',          'req'=>true ],
          ['key'=>'doc_signature', 'label'=>'Signature',                 'icon'=>'fa-pen-nib',        'req'=>true ],
          ['key'=>'doc_marks10',   'label'=>'10th Marks Card',           'icon'=>'fa-file-lines',     'req'=>true ],
          ['key'=>'doc_marks12',   'label'=>'12th Marks Card',           'icon'=>'fa-file-lines',     'req'=>true ],
          ['key'=>'doc_tc',        'label'=>'Transfer Certificate (TC)', 'icon'=>'fa-file-certificate','req'=>true ],
          ['key'=>'doc_aadhaar',   'label'=>'Aadhaar Card',              'icon'=>'fa-id-card',        'req'=>true ],
          ['key'=>'doc_caste',     'label'=>'Caste / Income Certificate','icon'=>'fa-scroll',         'req'=>false],
        ];
        foreach($docList as $doc): ?>
        <div class="mf-doc-row">
          <div style="display:flex;align-items:center;gap:10px">
            <div class="mf-doc-icon"><i class="fas <?= $doc['icon'] ?>"></i></div>
            <div>
              <div style="font-size:.78rem;font-weight:600;color:var(--teal)"><?= $doc['label'] ?><?php if($doc['req']): ?> <span class="req">*</span><?php endif; ?></div>
              <div class="mf-doc-hint" id="hint-<?= $doc['key'] ?>"><?= $doc['req'] ? 'Required' : 'Optional' ?></div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <label class="mf-file-btn" for="<?= $doc['key'] ?>">
              <i class="fas fa-upload" style="font-size:.72rem"></i>
              <span id="lbl-<?= $doc['key'] ?>">Upload</span>
            </label>
            <input type="file" id="<?= $doc['key'] ?>" name="<?= $doc['key'] ?>"
                   class="mf-file-hidden" accept=".pdf,.jpg,.jpeg,.png"
                   onchange="handleFile(this,'<?= $doc['key'] ?>')">
          </div>
        </div>
        <?php endforeach; ?>
      </div><!-- /panel-4 -->

      <!-- ════════════ STEP 5 — PAYMENT ════════════ -->
      <div class="modal-body step-panel" id="panel-5">

        <!-- Auto-generated App ID display -->
        <div class="mf-appid-box">
          <div>
            <div style="font-size:.65rem;color:var(--muted);margin-bottom:3px">Auto-Generated Application ID</div>
            <div id="displayAppNo" style="font-size:.95rem;font-weight:700;color:var(--teal);letter-spacing:.06em;font-family:var(--mono)">—</div>
          </div>
          <span style="font-size:.65rem;font-weight:700;padding:3px 9px;border-radius:20px;background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--teal)">APP-<?= date('Y') ?></span>
        </div>

        <div class="mf-section-title"><i class="fas fa-money-bill-wave"></i> Application Fee Payment <div class="mf-section-line"></div></div>
        <div class="mf-radio-row">
          <label class="mf-radio <?= (($_POST['payment_status']??'Pending')==='Pending'?'checked':'') ?>" id="radioLblPending">
            <input type="radio" name="payment_status" value="Pending"
                   <?= (($_POST['payment_status']??'Pending')==='Pending'?'checked':'') ?>
                   onchange="updateRadio();toggleTxn()">
            <i class="fas fa-clock"></i> Pending
          </label>
          <label class="mf-radio <?= (($_POST['payment_status']??'')==='Paid'?'checked':'') ?>" id="radioLblPaid">
            <input type="radio" name="payment_status" value="Paid"
                   <?= (($_POST['payment_status']??'')==='Paid'?'checked':'') ?>
                   onchange="updateRadio();toggleTxn()">
            <i class="fas fa-check-circle"></i> Paid
          </label>
        </div>

        <!-- Transaction ID — only shown when Paid -->
        <div class="mf-group" id="txnGroup" style="display:<?= (($_POST['payment_status']??'')==='Paid'?'flex':'none') ?>;flex-direction:column;gap:4px">
          <label class="mf-label">Transaction ID / Receipt Number</label>
          <input type="text" name="transaction_id" class="mf-input" placeholder="UTR / Transaction ID" value="<?= esc($_POST['transaction_id'] ?? '') ?>">
        </div>

        <!-- Pending warning -->
        <div id="payPendingNote" style="display:<?= (($_POST['payment_status']??'Pending')==='Pending'?'flex':'none') ?>;align-items:center;gap:7px;padding:8px 12px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);border-radius:8px;font-size:.74rem;color:var(--amber);margin-bottom:10px">
          <i class="fas fa-triangle-exclamation"></i>
          Application fee must be paid within 48 hours of submission to avoid cancellation.
        </div>

        <div class="mf-section-title" style="margin-top:4px"><i class="fas fa-file-signature"></i> Declaration <div class="mf-section-line"></div></div>
        <div style="padding:10px 12px;background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:9px;font-size:.74rem;color:var(--muted);line-height:1.65">
          I hereby declare that all information provided in this application form is true, complete, and accurate
          to the best of my knowledge. I understand that any false information may result in the cancellation
          of my application or admission.
        </div>
        <label style="display:flex;align-items:center;gap:9px;margin-top:10px;cursor:pointer">
          <input type="checkbox" id="declCheck" name="declaration" value="1" style="accent-color:var(--teal);width:14px;height:14px">
          <span style="font-size:.76rem;color:var(--text)">I agree to the above declaration and the institution's terms &amp; conditions.</span>
        </label>
      </div><!-- /panel-5 -->

      <!-- ── Navigation footer ───────────────────────────────────────────────── -->
      <div class="modal-ft">
        <button type="button" id="btnBack" onclick="stepNav(-1)" class="mf-btn mf-btn-cancel" style="display:none">
          <i class="fas fa-arrow-left"></i> Back
        </button>
        <div style="display:flex;gap:10px;margin-left:auto">
          <button type="button" onclick="closeAppModal()" class="mf-btn mf-btn-cancel">
            <i class="fas fa-xmark"></i> Cancel
          </button>
          <button type="button" id="btnNext" onclick="stepNav(1)" class="mf-btn mf-btn-submit">
            Continue <i class="fas fa-arrow-right"></i>
          </button>
          <button type="submit" id="btnSubmit" class="mf-btn mf-btn-submit" style="display:none">
            <i class="fas fa-paper-plane"></i> Submit Application
          </button>
        </div>
      </div>

    </form><!-- /appForm -->
  </div><!-- /modal-box -->
</div><!-- /appModal -->

<!-- ── Applications data for JS (view / edit) ──────────────────────────── -->
<script>
var APP_DATA = <?= json_encode(
    array_map(fn($a) => [
        'id'                 => (int)$a['id'],
        'application_no'     => $a['application_no'],
        'full_name'          => $a['full_name'],
        'email'              => $a['email'],
        'mobile'             => $a['mobile'] ?? '',
        'mobile_country_code'=> $a['mobile_country_code'] ?? '+91',
        'dob'                => $a['dob'] ?? '',
        'gender'             => $a['gender'] ?? '',
        'nationality'        => $a['nationality'] ?? '',
        'blood_group'        => $a['blood_group'] ?? '',
        'category'           => $a['category'] ?? '',
        'aadhaar'            => $a['aadhaar'] ?? '',
        'address'            => $a['address'] ?? '',
        'city'               => $a['city'] ?? '',
        'state'              => $a['state'] ?? '',
        'pincode'            => $a['pincode'] ?? '',
        'course_id'          => (int)$a['course_id'],
        'course_name'        => $a['course_name'],
        'course_code'        => $a['course_code'],
        'admission_type'     => $a['admission_type'] ?? '',
        'entrance_exam'      => $a['entrance_exam'] ?? '',
        'entrance_score'     => $a['entrance_score'] ?? '',
        'scholarship'        => $a['scholarship'] ?? '',
        'qual_10_board'      => $a['qual_10_board'] ?? '',
        'qual_10_school'     => $a['qual_10_school'] ?? '',
        'qual_10_year'       => $a['qual_10_year'] ?? '',
        'qual_10_percent'    => $a['qual_10_percent'] ?? '',
        'qual_12_board'      => $a['qual_12_board'] ?? '',
        'qual_12_school'     => $a['qual_12_school'] ?? '',
        'qual_12_stream'     => $a['qual_12_stream'] ?? '',
        'qual_12_year'       => $a['qual_12_year'] ?? '',
        'qual_12_percent'    => $a['qual_12_percent'] ?? '',
        'prev_degree'        => $a['prev_degree'] ?? '',
        'prev_university'    => $a['prev_university'] ?? '',
        'prev_cgpa'          => $a['prev_cgpa'] ?? '',
        'father_name'        => $a['father_name'] ?? '',
        'mother_name'        => $a['mother_name'] ?? '',
        'guardian_mobile'    => $a['guardian_mobile'] ?? '',
        'guardian_mobile_country_code' => $a['guardian_mobile_country_code'] ?? '+91',
        'annual_income'      => $a['annual_income'] ?? '',
        'father_occupation'  => $a['father_occupation'] ?? '',
        'application_status' => $a['application_status'],
        'payment_status'     => $a['payment_status'],
        'transaction_id'     => $a['transaction_id'] ?? '',
        'submitted_at'       => $a['submitted_at'],
        'documents'          => $appDocMap[(int)$a['id']] ?? [],
    ], $applications ?? []), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT
) ?>;
</script>

<!-- ── VIEW Modal ──────────────────────────────────────────────────────────── -->
<div id="viewModal" class="modal-overlay" onclick="if(event.target===this)closeViewModal()">
  <div class="modal-box" style="max-width:580px" role="dialog" aria-modal="true">
    <div class="modal-hd">
      <div class="modal-title">
        <div class="modal-icon"><i class="fas fa-eye"></i></div>
        <div>
          <div style="font-size:.95rem;font-weight:700;color:var(--text)">Application Details</div>
          <div id="viewAppNo" style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)">—</div>
        </div>
      </div>
      <button class="modal-close" onclick="closeViewModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="viewBody" style="display:flex;flex-direction:column;gap:0;padding:18px 22px;overflow-y:auto"></div>
    <div class="modal-ft">
      <div style="margin-left:auto;display:flex;gap:10px">
        <button type="button" onclick="closeViewModal()" class="mf-btn mf-btn-cancel"><i class="fas fa-xmark"></i> Close</button>
        <button type="button" id="viewToEditBtn" onclick="switchViewToEdit()" class="mf-btn mf-btn-submit"><i class="fas fa-pencil"></i> Edit</button>
      </div>
    </div>
  </div>
</div>

<!-- ── EDIT Modal ──────────────────────────────────────────────────────────── -->
<div id="editModal" class="modal-overlay" onclick="if(event.target===this)closeEditModal()">
  <div class="modal-box" style="max-width:780px;height:min(92vh,860px);max-height:92vh" role="dialog" aria-modal="true">
    <div class="modal-hd">
      <div class="modal-title">
        <div class="modal-icon" style="background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.25)"><i class="fas fa-pencil" style="color:#60a5fa"></i></div>
        <div>
          <div style="font-size:.95rem;font-weight:700;color:var(--text)">Edit Application</div>
          <div id="editAppNo" style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)">—</div>
        </div>
      </div>
      <button class="modal-close" onclick="closeEditModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <form method="POST" action="?tab=applications" id="editForm" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
      <input type="hidden" name="action"  value="edit_application">
      <input type="hidden" name="edit_id" id="editId" value="">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:0;padding:18px 22px 14px;overflow-y:auto;flex:1;min-height:0">

        <!-- Personal -->
        <div class="mf-section-title"><i class="fas fa-user"></i> Personal Information <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" id="e_full_name" class="mf-input" required maxlength="80" title="Letters, spaces, hyphens or dots only" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')"></div>
          <div class="mf-group"><label class="mf-label">Email <span class="req">*</span></label><input type="email" name="email" id="e_email" class="mf-input" required></div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Mobile</label>
            <div class="phone-wrap" id="phoneWrap_emobile">
              <button type="button" class="phone-code-btn" onclick="togglePhoneDd('emobile')" id="phoneBtn_emobile">
                +91 <i class="fas fa-chevron-down"></i>
              </button>
              <input type="hidden" name="mobile_country_code" id="phoneCode_emobile" value="+91">
              <input type="tel" name="mobile" id="e_mobile" class="phone-number-input" inputmode="numeric" maxlength="15" placeholder="Mobile number" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,15)">
              <div class="phone-dd" id="phoneDd_emobile">
                <div class="phone-dd-search"><input type="text" placeholder="Search country…" oninput="filterPhoneDd(this,'emobile')"></div>
                <?php foreach($countryCodes as $cc): ?>
                <div class="phone-dd-item<?= ($cc[1]==='+91'?' active':'') ?>" onclick="selectPhoneCode('emobile','<?= $cc[1] ?>','<?= $cc[1] ?>')"><?= esc($cc[2]) ?><span><?= esc($cc[1]) ?></span></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mf-group"><label class="mf-label">Date of Birth</label><input type="date" name="dob" id="e_dob" class="mf-input"></div>
        </div>
        <div class="mf-row3">
          <div class="mf-group"><label class="mf-label">Gender</label>
            <select name="gender" id="e_gender" class="mf-input">
              <option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Nationality</label>
            <select name="nationality" id="e_nationality" class="mf-input">
              <?php foreach($nationalities as $nat): ?>
              <option value="<?= $nat ?>"><?= $nat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Blood Group</label>
            <select name="blood_group" id="e_blood_group" class="mf-input">
              <option value="">Select</option><?php foreach(['A+','A−','B+','B−','O+','O−','AB+','AB−'] as $bg): ?><option value="<?= $bg ?>"><?= $bg ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Category</label>
            <select name="category" id="e_category" class="mf-input">
              <option value="">Select</option><?php foreach(['General','OBC','SC','ST','EWS'] as $cat): ?><option value="<?= $cat ?>"><?= $cat ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Aadhaar Number</label><input type="text" name="aadhaar" id="e_aadhaar" class="mf-input" placeholder="XXXX XXXX XXXX" inputmode="numeric" maxlength="14" title="12-digit Aadhaar" oninput="this.value=this.value.replace(/[^0-9 ]/g,'').slice(0,14)"></div>
        </div>
        <div class="mf-group"><label class="mf-label">Address</label><textarea name="address" id="e_address" class="mf-input mf-textarea" rows="2"></textarea></div>
        <div class="mf-row3">
          <div class="mf-group"><label class="mf-label">City</label><input type="text" name="city" id="e_city" class="mf-input"></div>
          <div class="mf-group"><label class="mf-label">State</label>
            <select name="state" id="e_state" class="mf-input">
              <option value="">Select</option><?php foreach(['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal'] as $st): ?><option value="<?= $st ?>"><?= $st ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">PIN Code</label><input type="text" name="pincode" id="e_pincode" class="mf-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="560001" title="6-digit PIN code" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)"></div>
        </div>

        <!-- Academic -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-graduation-cap"></i> Academic Details <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Course <span class="req">*</span></label>
            <select name="course_id" id="e_course_id" class="mf-input" required>
              <option value="">— Select course —</option>
              <?php foreach ($courseOptions as $c): ?><option value="<?= (int)$c['id'] ?>"><?= esc($c['course_code']) ?> — <?= esc($c['course_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Admission Type</label>
            <select name="admission_type" id="e_admission_type" class="mf-input">
              <?php foreach(['Merit','Management','Entrance','Lateral'] as $at): ?><option value="<?= $at ?>"><?= $at ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Entrance Exam</label>
            <select name="entrance_exam" id="e_entrance_exam" class="mf-input">
              <option value="">None / Not Applicable</option><?php foreach(['KCET','JEE Main','JEE Advanced','NEET','GATE','CAT','MAT','CMAT','XAT'] as $ex): ?><option value="<?= $ex ?>"><?= $ex ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Entrance Score / Rank</label><input type="text" name="entrance_score" id="e_entrance_score" class="mf-input" inputmode="decimal" maxlength="10" title="Numeric score or rank" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"></div>
        </div>
        <div class="mf-group"><label class="mf-label">Scholarship</label><input type="text" name="scholarship" id="e_scholarship" class="mf-input"></div>

        <!-- 10th -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-school"></i> 10th Standard <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Board</label>
            <select name="qual_10_board" id="e_qual_10_board" class="mf-input">
              <option value="">Select Board</option>
              <option value="CBSE">CBSE</option>
              <option value="ICSE">ICSE</option>
              <option value="State Board">State Board</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">School Name</label><input type="text" name="qual_10_school" id="e_qual_10_school" class="mf-input"></div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Year of Passing</label>
            <select name="qual_10_year" id="e_qual_10_year" class="mf-input">
              <option value="">Select Year</option>
              <?php for($y=date('Y'); $y>=1990; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Percentage (0–100)</label><input type="text" name="qual_10_percent" id="e_qual_10_pct" class="mf-input" inputmode="decimal" placeholder="e.g. 85.4" maxlength="6" title="Percentage 0-100, no % sign" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"></div>
        </div>

        <!-- 12th -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-school-flag"></i> 12th / PUC <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Board / University</label>
            <select name="qual_12_board" id="e_qual_12_board" class="mf-input">
              <option value="">Select Board</option>
              <option value="University">University</option>
              <option value="PU Board">PU Board</option>
              <option value="Polytechnic">Polytechnic</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">College Name</label><input type="text" name="qual_12_school" id="e_qual_12_school" class="mf-input" placeholder="College name"></div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Stream</label>
            <select name="qual_12_stream" id="e_qual_12_stream" class="mf-input">
              <option value="">Select Stream</option>
              <option value="Science">Science</option>
              <option value="Commerce">Commerce</option>
              <option value="Arts">Arts</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Year of Passing</label>
            <select name="qual_12_year" id="e_qual_12_year" class="mf-input">
              <option value="">Select Year</option>
              <?php for($y=date('Y'); $y>=1990; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Percentage (0–100)</label><input type="text" name="qual_12_percent" id="e_qual_12_pct" class="mf-input" inputmode="decimal" placeholder="e.g. 78.2" maxlength="6" title="Percentage 0-100, no % sign" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"></div>
        </div>

        <!-- Previous Degree -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-university"></i> Previous Degree <span style="font-size:.65rem;color:var(--muted)">(if applicable)</span> <div class="mf-section-line"></div></div>
        <div class="mf-row3">
          <div class="mf-group"><label class="mf-label">Degree</label>
            <select name="prev_degree" id="e_prev_degree" class="mf-input">
              <option value="">Select Degree</option>
              <option value="BSc">BSc</option>
              <option value="BCom">BCom</option>
              <option value="BCA">BCA</option>
              <option value="BA">BA</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">University</label><input type="text" name="prev_university" id="e_prev_university" class="mf-input"></div>
          <div class="mf-group"><label class="mf-label">CGPA (0–10)</label><input type="text" name="prev_cgpa" id="e_prev_cgpa" class="mf-input" inputmode="decimal" placeholder="e.g. 8.5" maxlength="5" title="CGPA 0.00-10.00" oninput="this.value=this.value.replace(/[^0-9.]/g,'')"></div>
        </div>

        <!-- Parent -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-people-roof"></i> Parent / Guardian <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Father's Name</label><input type="text" name="father_name" id="e_father_name" class="mf-input" maxlength="80" title="Letters only" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')"></div>
          <div class="mf-group"><label class="mf-label">Mother's Name</label><input type="text" name="mother_name" id="e_mother_name" class="mf-input" maxlength="80" title="Letters only" oninput="this.value=this.value.replace(/[^A-Za-z .\-']/g,'')"></div>
        </div>
        <div class="mf-row3">
          <div class="mf-group"><label class="mf-label">Guardian Mobile</label>
            <div class="phone-wrap" id="phoneWrap_eguardian">
              <button type="button" class="phone-code-btn" onclick="togglePhoneDd('eguardian')" id="phoneBtn_eguardian">
                +91 <i class="fas fa-chevron-down"></i>
              </button>
              <input type="hidden" name="guardian_mobile_country_code" id="phoneCode_eguardian" value="+91">
              <input type="tel" name="guardian_mobile" id="e_guardian_mobile" class="phone-number-input" inputmode="numeric" maxlength="15" placeholder="Mobile number" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,15)">
              <div class="phone-dd" id="phoneDd_eguardian">
                <div class="phone-dd-search"><input type="text" placeholder="Search country…" oninput="filterPhoneDd(this,'eguardian')"></div>
                <?php foreach($countryCodes as $cc): ?>
                <div class="phone-dd-item<?= ($cc[1]==='+91'?' active':'') ?>" onclick="selectPhoneCode('eguardian','<?= $cc[1] ?>','<?= $cc[1] ?>')"><?= esc($cc[2]) ?><span><?= esc($cc[1]) ?></span></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mf-group"><label class="mf-label">Annual Income</label>
            <select name="annual_income" id="e_annual_income" class="mf-input">
              <option value="">Select</option><?php foreach(['Below ₹1 Lakh','₹1 – 2.5 Lakhs','₹2.5 – 5 Lakhs','₹5 – 10 Lakhs','Above ₹10 Lakhs'] as $inc): ?><option value="<?= $inc ?>"><?= $inc ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Father's Occupation</label><input type="text" name="father_occupation" id="e_father_occ" class="mf-input"></div>
        </div>

        <!-- Status -->
        <div class="mf-section-title" style="margin-top:6px"><i class="fas fa-circle-info"></i> Status & Payment <div class="mf-section-line"></div></div>
        <div class="mf-row">
          <div class="mf-group"><label class="mf-label">Application Status</label>
            <select name="application_status" id="e_app_status" class="mf-input">
              <option value="Submitted">Submitted</option><option value="Under Review">Under Review</option><option value="Approved">Approved</option><option value="Rejected">Rejected</option>
            </select>
          </div>
          <div class="mf-group"><label class="mf-label">Payment Status</label>
            <select name="payment_status" id="e_pay_status" class="mf-input">
              <option value="Pending">Pending</option><option value="Paid">Paid</option>
            </select>
          </div>
        </div>
        <div class="mf-group"><label class="mf-label">Transaction ID / Receipt</label><input type="text" name="transaction_id" id="e_txn_id" class="mf-input" placeholder="UTR / Transaction ID"></div>

      </div>
      <div class="modal-ft">
        <div style="margin-left:auto;display:flex;gap:10px">
          <button type="button" onclick="closeEditModal()" class="mf-btn mf-btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
          <button type="submit" class="mf-btn mf-btn-submit"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE hidden form ──────────────────────────────────────────────────── -->
<form method="POST" action="?tab=applications" id="deleteForm" style="display:none">
  <input type="hidden" name="action"    value="delete_application">
  <input type="hidden" name="delete_id" id="deleteId" value="">
</form>

<style>
/* ── Modal overlay ─────────────────────────────────────────────────────────── */
.modal-overlay{
  position:fixed;inset:0;z-index:1000;
  background:rgba(15,23,42,.55);backdrop-filter:blur(8px);
  display:none;align-items:center;justify-content:center;padding:16px;
  animation:fadeIn .2s ease;
}
.modal-overlay.open{display:flex}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp2{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

/* ── Modal box ─────────────────────────────────────────────────────────────── */
.modal-box{
  background:#fff;
  border:1px solid rgba(20,184,166,.25);border-radius:18px;
  width:100%;max-width:700px;
  height:min(86vh,740px);max-height:86vh;min-height:0;
  display:flex;flex-direction:column;
  box-shadow:0 32px 80px rgba(15,118,110,.18),0 0 0 1px rgba(20,184,166,.08);
  animation:slideUp2 .28s cubic-bezier(.22,1,.36,1) both;overflow:hidden;
}

/* ── Header ────────────────────────────────────────────────────────────────── */
.modal-hd{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-bottom:1px solid var(--border);
  background:#F0FDFA;
  border-radius:18px 18px 0 0;flex-shrink:0;
}
.modal-title{display:flex;align-items:center;gap:10px}
.modal-icon{
  width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,var(--teal),var(--teal-light));
  display:grid;place-items:center;color:#fff;font-size:.9rem;flex-shrink:0;
  box-shadow:0 3px 12px rgba(15,118,110,.25);
}
.modal-title-text{font-size:.92rem;font-weight:700;color:var(--text)}
.modal-title-sub{font-size:.68rem;color:var(--muted);margin-top:1px;font-family:var(--mono)}
.modal-close{
  width:32px;height:32px;border-radius:9px;border:1px solid var(--border);
  background:#F8FAFC;color:var(--muted);cursor:pointer;
  display:grid;place-items:center;font-size:.9rem;transition:all .18s;flex-shrink:0;
}
.modal-close:hover{background:var(--red2);border-color:rgba(220,38,38,.28);color:var(--red)}
.step-chip{
  font-size:.68rem;font-weight:700;padding:4px 11px;border-radius:20px;
  background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--teal);
}

/* ── Step bar ──────────────────────────────────────────────────────────────── */
.step-bar{
  display:flex;align-items:center;padding:10px 20px;
  border-bottom:1px solid var(--border);background:#F8FAFC;
  flex-shrink:0;gap:4px;
}
.step-item{display:flex;align-items:center;gap:5px;flex-shrink:0}
.step-dot{
  width:24px;height:24px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.68rem;font-weight:700;
  background:#F1F5F9;border:1px solid rgba(15,118,110,.18);
  color:var(--muted);transition:all .25s;
}
.step-item span{font-size:.70rem;font-weight:600;color:var(--muted);transition:color .25s}
.step-item.active .step-dot{background:var(--teal-soft2);border-color:rgba(15,118,110,.45);color:var(--teal)}
.step-item.active span{color:var(--teal)}
.step-item.done .step-dot{background:linear-gradient(135deg,var(--teal),var(--teal-light));border-color:transparent;color:#fff}
.step-item.done span{color:var(--teal)}
.step-conn{flex:1;height:1px;background:rgba(15,118,110,.12);transition:background .3s;min-width:8px}
.step-conn.done{background:rgba(15,118,110,.35)}

/* ── Alert ─────────────────────────────────────────────────────────────────── */
.modal-alert{
  margin:10px 20px 0;padding:9px 13px;border-radius:9px;
  font-size:.78rem;display:flex;align-items:center;gap:8px;flex-shrink:0;
}
.modal-alert.error{background:var(--red2);border:1px solid rgba(220,38,38,.25);color:#991b1b}
.modal-alert.success{background:var(--green2);border:1px solid rgba(22,163,74,.25);color:#166534}

/* ── Form layout ─────────────────────────────────────────────────────────── */
#appForm{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}
#editForm{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}

.step-panel{display:none}
.step-panel.active{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}

/* ── Scrollable body ─────────────────────────────────────────────────────── */
.modal-body{
  padding:16px 18px 14px 20px;
  overflow-y:scroll;flex:1;min-height:0;
  scrollbar-width:thin;
  scrollbar-color:rgba(15,118,110,.35) rgba(15,118,110,.06);
}
.modal-body::-webkit-scrollbar{width:6px}
.modal-body::-webkit-scrollbar-track{background:rgba(15,118,110,.04);border-radius:4px;margin:4px 0}
.modal-body::-webkit-scrollbar-thumb{background:rgba(15,118,110,.30);border-radius:4px}
.modal-body::-webkit-scrollbar-thumb:hover{background:rgba(15,118,110,.55)}
.step-panel.active.modal-body{display:flex !important;flex-direction:column;overflow-y:scroll !important;flex:1;min-height:0;}

/* ── Form rows & groups ──────────────────────────────────────────────────── */
.mf-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mf-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.mf-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.mf-label{font-size:.72rem;font-weight:700;color:var(--teal);display:flex;align-items:center;gap:4px;text-transform:uppercase;letter-spacing:.06em}
.req{color:var(--red);font-size:.75rem}

/* ── Inputs ──────────────────────────────────────────────────────────────── */
.mf-input{
  width:100%;padding:8px 12px;
  background:#F8FAFC;border:1px solid rgba(15,118,110,.18);
  border-radius:9px;color:var(--text);
  font-size:.82rem;font-family:var(--font);
  transition:all .18s;outline:none;
}
.mf-input:focus{border-color:var(--teal-light);background:#fff;box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.mf-input::placeholder{color:#94A3B8}
.mf-input option{background:#fff;color:var(--text)}
.mf-input optgroup{background:#F0FDFA;color:var(--teal);font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.mf-input optgroup option{background:#fff;color:var(--text);font-size:.8rem;text-transform:none;font-weight:400;padding-left:8px}
.mf-textarea{resize:vertical;min-height:54px}

/* ── Phone field ─────────────────────────────────────────────────────────── */
.phone-wrap{
  position:relative;display:flex;align-items:stretch;
  background:#F8FAFC;border:1px solid rgba(15,118,110,.18);
  border-radius:9px;overflow:visible;transition:border-color .18s,box-shadow .18s;
}
.phone-wrap:focus-within{border-color:var(--teal-light);background:#fff;box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.phone-code-btn{
  flex:0 0 auto;display:flex;align-items:center;gap:5px;
  padding:0 11px;background:var(--teal-soft);
  border:none;border-right:1px solid rgba(15,118,110,.15);
  border-radius:9px 0 0 9px;
  color:var(--teal);font-size:.78rem;font-family:var(--font);font-weight:700;
  cursor:pointer;white-space:nowrap;transition:background .15s;
}
.phone-code-btn:hover{background:var(--teal-soft2)}
.phone-code-btn i{font-size:.6rem;color:var(--muted)}
.phone-number-input{
  flex:1;min-width:0;border:none;outline:none;
  background:transparent;color:var(--text);
  font-size:.82rem;font-family:var(--font);
  padding:8px 12px;border-radius:0 9px 9px 0;
}
.phone-number-input::placeholder{color:#94A3B8}
.phone-code-hidden{display:none}
.phone-dd{
  position:absolute;top:calc(100% + 4px);left:0;
  width:240px;max-height:220px;overflow-y:auto;
  background:#fff;border:1px solid var(--border-accent);
  border-radius:10px;z-index:9999;
  box-shadow:0 8px 32px rgba(15,118,110,.14);
  display:none;scrollbar-width:thin;scrollbar-color:rgba(15,118,110,.3) transparent;
}
.phone-dd.open{display:block}
.phone-dd-search{padding:8px 10px;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:1}
.phone-dd-search input{width:100%;background:#F8FAFC;border:1px solid var(--border);border-radius:7px;padding:5px 9px;color:var(--text);font-size:.75rem;font-family:var(--font);outline:none}
.phone-dd-search input::placeholder{color:#94A3B8}
.phone-dd-item{padding:7px 12px;font-size:.77rem;color:var(--text);cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:background .12s}
.phone-dd-item:hover,.phone-dd-item.active{background:var(--teal-soft);color:var(--teal)}
.phone-dd-item span{color:var(--muted);font-size:.72rem;font-family:var(--mono)}
#editForm .phone-code-btn{font-size:.86rem;padding:0 12px}
#editForm .phone-number-input{font-size:.88rem;padding:9px 13px}

/* ── Section title ─────────────────────────────────────────────────────────── */
.mf-section-title{display:flex;align-items:center;gap:7px;font-size:.65rem;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:var(--teal);margin-bottom:12px}
.mf-section-line{flex:1;height:1px;background:rgba(15,118,110,.14)}

/* ── Radio payment ─────────────────────────────────────────────────────────── */
.mf-radio-row{display:flex;gap:8px;margin-bottom:12px}
.mf-radio{
  flex:1;display:flex;align-items:center;justify-content:center;gap:7px;
  padding:8px;border:1px solid rgba(15,118,110,.18);border-radius:9px;
  cursor:pointer;font-size:.78rem;color:var(--muted);transition:all .18s;
  font-weight:600;background:#F8FAFC;
}
.mf-radio input{display:none}
.mf-radio:hover{border-color:rgba(15,118,110,.35);color:var(--teal);background:var(--teal-soft)}
.mf-radio.checked{border-color:var(--teal-light);background:var(--teal-soft2);color:var(--teal)}

/* ── Document rows ─────────────────────────────────────────────────────────── */
.mf-doc-note{
  display:flex;align-items:center;gap:8px;padding:9px 13px;
  background:var(--teal-soft);border:1px dashed var(--border-accent);
  border-radius:9px;margin-bottom:12px;font-size:.72rem;color:var(--muted);
}
.mf-doc-note strong{color:var(--teal)}
.mf-doc-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:9px 13px;background:#F8FAFC;
  border:1px solid var(--border);border-radius:9px;margin-bottom:7px;
  transition:border-color .18s;
}
.mf-doc-row:hover{border-color:var(--border-accent);background:#F0FDFA}
.mf-doc-row.uploaded{border-color:rgba(22,163,74,.28);background:var(--green2)}
.mf-doc-icon{
  width:30px;height:30px;border-radius:8px;
  background:var(--teal-soft);border:1px solid var(--border-accent);
  display:grid;place-items:center;color:var(--teal);font-size:.75rem;flex-shrink:0;
}
.mf-doc-hint{font-size:.67rem;color:var(--muted);margin-top:1px}
.mf-doc-row.uploaded .mf-doc-hint{color:var(--success)}
.mf-file-btn{
  display:inline-flex;align-items:center;gap:4px;
  padding:5px 11px;border-radius:7px;font-size:.71rem;font-weight:700;
  border:1px solid var(--border-accent);background:var(--teal-soft);
  color:var(--teal);cursor:pointer;transition:all .18s;
}
.mf-file-btn:hover{background:var(--teal-soft2);border-color:var(--teal)}
.mf-file-hidden{display:none}

/* ── App ID box ────────────────────────────────────────────────────────────── */
.mf-appid-box{
  background:var(--teal-soft);border:1px solid var(--border-accent);
  border-radius:10px;padding:10px 15px;margin-bottom:14px;
  display:flex;align-items:center;justify-content:space-between;
}

/* ── Footer ────────────────────────────────────────────────────────────────── */
.modal-ft{
  display:flex;align-items:center;
  padding:12px 20px;border-top:1px solid var(--border);
  background:#F8FAFC;flex-shrink:0;border-radius:0 0 18px 18px;
}
.mf-btn{
  padding:8px 18px;border-radius:9px;font-size:.79rem;font-weight:700;
  cursor:pointer;display:flex;align-items:center;gap:6px;
  font-family:var(--font);transition:all .2s;border:none;
}
.mf-btn-cancel{background:#fff;border:1px solid var(--border);color:var(--muted)}
.mf-btn-cancel:hover{background:var(--card-hover);color:var(--text);border-color:var(--border-accent)}
.mf-btn-submit{background:linear-gradient(135deg,var(--teal),var(--teal-light));color:#fff;box-shadow:0 3px 12px rgba(15,118,110,.25)}
.mf-btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(15,118,110,.30)}
.mf-btn-submit:active{transform:translateY(0)}
.mf-btn-submit:disabled{opacity:.45;cursor:not-allowed;transform:none}
.pay-btn{
  background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--teal);
  padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.73rem;
  font-family:var(--font);font-weight:600;transition:all .18s;
}
.pay-btn:hover{background:var(--teal-soft2);border-color:var(--teal)}
.print-btn{background:#fff;border:1px solid var(--border);color:var(--muted);padding:6px 11px;border-radius:8px;cursor:pointer;font-size:.73rem;transition:all .18s;font-family:var(--font)}
.print-btn:hover{border-color:var(--border-accent);color:var(--teal)}

/* ── Edit modal larger inputs ────────────────────────────────────────────── */
#editForm .mf-input{font-size:.88rem;padding:9px 13px}
#editForm .mf-label{font-size:.78rem}
#editForm .mf-section-title{font-size:.68rem;margin-bottom:12px}
#editForm .mf-group{margin-bottom:12px}
#editForm .mf-row{gap:14px}
#editForm .mf-row3{gap:14px}
#editForm .mf-textarea{min-height:60px}

@media(max-width:640px){
  .mf-row,.mf-row3{grid-template-columns:1fr}
  .modal-box{border-radius:14px;height:96vh;max-height:96vh}
  .modal-overlay{padding:6px}
  .step-item span{display:none}
  #editForm .mf-input{font-size:.82rem;padding:8px 11px}
}
</style>

<script>
/* ── Clock ──────────────────────────────────────────────────────────────────── */
function tick(){
  const now=new Date();
  document.getElementById('topbarDate').textContent=
    now.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
tick(); setInterval(tick,60000);

/* ── Sidebar toggle (mobile) ────────────────────────────────────────────────── */
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  var o=document.getElementById('sidebarOverlay');
  if(o) o.classList.toggle('open');
}
document.addEventListener('click',function(e){
  const sb=document.getElementById('sidebar');
  const ov=document.getElementById('sidebarOverlay');
  if(window.innerWidth<=800 && sb && sb.classList.contains('open') &&
     !sb.contains(e.target) && e.target.id!=='menuToggle' && !e.target.closest('#menuToggle')){
    sb.classList.remove('open');
    if(ov) ov.classList.remove('open');
  }
});

/* ── Avatar dropdown ─────────────────────────────────────────────────────────*/
function toggleAvatarMenu(e){
  e.stopPropagation();
  const dd=document.getElementById('avatarDropdown');
  const av=document.getElementById('topbarAvatar');
  if(!dd) return;
  const isOpen=dd.classList.toggle('open');
  if(av) av.classList.toggle('open',isOpen);
}
document.addEventListener('click',function(e){
  const dd=document.getElementById('avatarDropdown');
  const av=document.getElementById('topbarAvatar');
  if(dd && dd.classList.contains('open') && !dd.contains(e.target) && e.target!==av && !av?.contains(e.target)){
    dd.classList.remove('open');
    if(av) av.classList.remove('open');
  }
});

/* ── Logout ─────────────────────────────────────────────────────────────────── */
async function doLogout(){
  var isAccountant = <?= $_isAccountant ? 'true' : 'false' ?>;
  var handler = isAccountant ? '../auth/accounts_auth_handler.php' : '../auth/auth_handler.php';
  var action  = isAccountant ? 'accounts_logout' : 'logout';
  var fallback = isAccountant ? '../auth/accounts_login.php' : '../login.php';
  try{
    const res=await fetch(handler,{
      method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+action
    });
    const data=await res.json();
    if(data.redirect) window.location.href=data.redirect;
    else window.location.href=fallback;
  }catch{ window.location.href=fallback; }
}

/* ── Action handlers ────────────────────────────────────────────────────────── */
var _currentViewId = null;

function findApp(id){ return APP_DATA.find(function(a){ return a.id===id; }); }

// ── VIEW ──
function viewApp(id){
  var a = findApp(id); if(!a) return;
  _currentViewId = id;
  document.getElementById('viewAppNo').textContent = a.application_no;
  var sc = {Approved:'approved',Rejected:'rejected','Under Review':'review',Submitted:'submitted'}[a.application_status]||'submitted';
  var pc = a.payment_status==='Paid'?'paid':'pending';
  var dob = a.dob ? new Date(a.dob).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
  var sat = a.submitted_at ? new Date(a.submitted_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
  document.getElementById('viewBody').innerHTML =
    vSec('Personal Information') +
    vRow('Full Name',     esc(a.full_name)) +
    vRow('Email',         esc(a.email)) +
    vRow('Mobile',        esc(a.mobile||'—')) +
    vRow('Date of Birth', dob) +
    vRow('Gender',        esc(a.gender||'—')) +
    vRow('Nationality',   esc(a.nationality||'—')) +
    vRow('Blood Group',   esc(a.blood_group||'—')) +
    vRow('Category',      esc(a.category||'—')) +
    vRow('Aadhaar',       esc(a.aadhaar||'—')) +
    vRow('Address',       esc([a.address,a.city,a.state,a.pincode].filter(Boolean).join(', ')||'—')) +
    vSec('Academic Details') +
    vRow('Course',        '<strong style="color:var(--teal)">'+esc(a.course_code)+'</strong> — '+esc(a.course_name)) +
    vRow('Admission Type',esc(a.admission_type||'—')) +
    vRow('Entrance Exam', esc(a.entrance_exam||'—')) +
    vRow('Entrance Score',esc(a.entrance_score||'—')) +
    vRow('Scholarship',   esc(a.scholarship||'—')) +
    vSec('10th Standard') +
    vRow('Board',         esc(a.qual_10_board||'—')) +
    vRow('School',        esc(a.qual_10_school||'—')) +
    vRow('Year',          esc(a.qual_10_year||'—')) +
    vRow('Percentage',    esc(a.qual_10_percent||'—')) +
    vSec('12th / PUC') +
    vRow('Board',         esc(a.qual_12_board||'—')) +
    vRow('College',       esc(a.qual_12_school||'—')) +
    vRow('Stream',        esc(a.qual_12_stream||'—')) +
    vRow('Year',          esc(a.qual_12_year||'—')) +
    vRow('Percentage',    esc(a.qual_12_percent||'—')) +
    (a.prev_degree ? vSec('Previous Degree') +
      vRow('Degree',      esc(a.prev_degree)) +
      vRow('University',  esc(a.prev_university||'—')) +
      vRow('CGPA / %',    esc(a.prev_cgpa||'—')) : '') +
    vSec('Parent / Guardian') +
    vRow("Father's Name",    esc(a.father_name||'—')) +
    vRow("Mother's Name",    esc(a.mother_name||'—')) +
    vRow('Guardian Mobile',  esc(a.guardian_mobile||'—')) +
    vRow('Annual Income',    esc(a.annual_income||'—')) +
    vRow("Father's Occupation",esc(a.father_occupation||'—')) +
    vSec('Application & Payment') +
    vRow('Applied On',       sat) +
    vRow('App Status',       '<span class="pill '+sc+'">'+esc(a.application_status)+'</span>') +
    vRow('Payment',          '<span class="pill '+pc+'">'+esc(a.payment_status)+'</span>') +
    vRow('Transaction ID',   esc(a.transaction_id||'—')) +
    vDocSection(a.documents||[]);
  document.getElementById('viewModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function vSec(label){
  return '<div style="font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--teal);padding:12px 0 4px;border-bottom:1px solid rgba(0,212,187,.15);margin-bottom:2px">'+label+'</div>';
}
function vRow(label, val){
  return '<div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--border);gap:10px">'
       + '<span style="font-size:.73rem;color:var(--muted);min-width:110px;flex-shrink:0">' + label + '</span>'
       + '<span style="font-size:.78rem;color:var(--text);text-align:right">' + val + '</span>'
       + '</div>';
}
function vDocSection(docs){
  if(!docs || !docs.length){
    return vSec('Documents') + '<div style="padding:10px 0;font-size:.76rem;color:var(--muted);font-style:italic">No documents uploaded.</div>';
  }
  var iconMap={
    doc_photo:'fa-image',doc_signature:'fa-pen-nib',doc_marks10:'fa-file-lines',
    doc_marks12:'fa-file-lines',doc_tc:'fa-file-certificate',doc_aadhaar:'fa-id-card',doc_caste:'fa-scroll'
  };
  var html = vSec('Documents');
  docs.forEach(function(d){
    var icon = iconMap[d.doc_key]||'fa-file';
    var ext  = d.file_name ? d.file_name.split('.').pop().toLowerCase() : '';
    var isImg= ['jpg','jpeg','png','gif'].indexOf(ext)>=0;
    var size = d.file_size ? (d.file_size/1024).toFixed(1)+' KB' : '';
    var link = '<a href="../'+esc(d.file_path)+'" target="_blank" '
             + 'style="display:inline-flex;align-items:center;gap:5px;font-size:.74rem;color:var(--teal);text-decoration:none;'
             + 'padding:3px 8px;border:1px solid rgba(0,212,187,.3);border-radius:6px;background:rgba(0,212,187,.06);transition:all .15s">'
             + '<i class="fas '+(isImg?'fa-image':'fa-file-pdf')+'"></i> View / Download</a>';
    html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);gap:10px">'
          + '<div style="display:flex;align-items:center;gap:8px">'
          + '<div style="width:28px;height:28px;border-radius:7px;background:rgba(0,212,187,.08);border:1px solid rgba(0,212,187,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0">'
          + '<i class="fas '+icon+'" style="font-size:.72rem;color:var(--teal)"></i></div>'
          + '<div><div style="font-size:.76rem;color:var(--text);font-weight:600">'+esc(d.doc_label)+'</div>'
          + '<div style="font-size:.67rem;color:var(--muted)">'+esc(d.file_name)+(size?' · '+size:'')+'</div></div>'
          + '</div>'
          + link
          + '</div>';
  });
  return html;
}
function esc(s){ var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML; }
function closeViewModal(){
  document.getElementById('viewModal').classList.remove('open');
  document.body.style.overflow='';
}
function switchViewToEdit(){
  closeViewModal();
  if(_currentViewId) editApp(_currentViewId);
}

// ── EDIT ──
function editApp(id){
  var a = findApp(id); if(!a) return;
  document.getElementById('editId').value           = a.id;
  document.getElementById('editAppNo').textContent  = a.application_no;
  document.getElementById('e_full_name').value      = a.full_name;
  document.getElementById('e_email').value          = a.email;
  document.getElementById('e_mobile').value         = a.mobile||'';
  // Set country code dropdown - default to +91 if not stored
  var ccSel = document.getElementById('e_mobile_country_code');
  if(ccSel) ccSel.value = a.mobile_country_code||'+91';
  document.getElementById('e_dob').value            = a.dob||'';
  document.getElementById('e_gender').value         = a.gender||'';
  document.getElementById('e_nationality').value    = a.nationality||'India';
  document.getElementById('e_blood_group').value    = a.blood_group||'';
  document.getElementById('e_category').value       = a.category||'';
  document.getElementById('e_aadhaar').value        = a.aadhaar||'';
  document.getElementById('e_address').value        = a.address||'';
  document.getElementById('e_city').value           = a.city||'';
  document.getElementById('e_state').value          = a.state||'';
  document.getElementById('e_pincode').value        = a.pincode||'';
  document.getElementById('e_admission_type').value = a.admission_type||'Merit';
  document.getElementById('e_entrance_exam').value  = a.entrance_exam||'';
  document.getElementById('e_entrance_score').value = a.entrance_score||'';
  document.getElementById('e_scholarship').value    = a.scholarship||'';
  document.getElementById('e_qual_10_board').value  = a.qual_10_board||'';
  document.getElementById('e_qual_10_school').value = a.qual_10_school||'';
  document.getElementById('e_qual_10_year').value   = a.qual_10_year||'';
  document.getElementById('e_qual_10_pct').value    = a.qual_10_percent||'';
  document.getElementById('e_qual_12_board').value  = a.qual_12_board||'';
  document.getElementById('e_qual_12_school').value = a.qual_12_school||'';
  if(document.getElementById('e_qual_12_stream')) document.getElementById('e_qual_12_stream').value = a.qual_12_stream||'';
  document.getElementById('e_qual_12_year').value   = a.qual_12_year||'';
  document.getElementById('e_qual_12_pct').value    = a.qual_12_percent||'';
  document.getElementById('e_prev_degree').value    = a.prev_degree||'';
  document.getElementById('e_prev_university').value= a.prev_university||'';
  document.getElementById('e_prev_cgpa').value      = a.prev_cgpa||'';
  document.getElementById('e_father_name').value    = a.father_name||'';
  document.getElementById('e_mother_name').value    = a.mother_name||'';
  document.getElementById('e_guardian_mobile').value= a.guardian_mobile||'';
  document.getElementById('e_annual_income').value  = a.annual_income||'';
  document.getElementById('e_father_occ').value     = a.father_occupation||'';
  document.getElementById('e_app_status').value     = a.application_status;
  document.getElementById('e_pay_status').value     = a.payment_status;
  document.getElementById('e_txn_id').value         = a.transaction_id||'';
  // Set course dropdown
  var sel = document.getElementById('e_course_id');
  sel.value = a.course_id;
  if(!sel.value){
    var opt = document.createElement('option');
    opt.value = a.course_id;
    opt.textContent = a.course_code + ' — ' + a.course_name;
    sel.appendChild(opt);
    sel.value = a.course_id;
  }
  document.getElementById('editModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeEditModal(){
  document.getElementById('editModal').classList.remove('open');
  document.body.style.overflow='';
}

/* ── Edit Form Validation ──────────────────────────────────────────────────── */
function validateEditForm(){
  var errors = [];

  var fn  = (document.getElementById('e_full_name')?.value || '').trim();
  var em  = (document.getElementById('e_email')?.value || '').trim();
  var mob = (document.getElementById('e_mobile')?.value || '').trim();
  var aadh= (document.getElementById('e_aadhaar')?.value || '').trim();
  var pin = (document.getElementById('e_pincode')?.value || '').trim();
  var dob = (document.getElementById('e_dob')?.value || '').trim();
  var cid = (document.getElementById('e_course_id')?.value || '');
  var p10 = (document.getElementById('e_qual_10_pct')?.value || '').trim();
  var y10 = (document.getElementById('e_qual_10_year')?.value || '').trim();
  var p12 = (document.getElementById('e_qual_12_pct')?.value || '').trim();
  var y12 = (document.getElementById('e_qual_12_year')?.value || '').trim();
  var cgpa= (document.getElementById('e_prev_cgpa')?.value || '').trim();
  var fn2 = (document.getElementById('e_father_name')?.value || '').trim();
  var mn  = (document.getElementById('e_mother_name')?.value || '').trim();
  var gm  = (document.getElementById('e_guardian_mobile')?.value || '').trim();

  function eFieldErr(id, msg){
    var el = document.getElementById(id);
    if(el){ el.style.borderColor='rgba(239,68,68,.7)'; el.style.boxShadow='0 0 0 2px rgba(239,68,68,.18)';
      el.addEventListener('input',function once(){ el.style.borderColor=''; el.style.boxShadow=''; el.removeEventListener('input',once); },{once:true}); }
    return msg;
  }

  if(!fn)                                              errors.push(eFieldErr('e_full_name','Full Name is required'));
  else if(!/^[A-Za-z .\-']{2,}$/.test(fn))          errors.push(eFieldErr('e_full_name','Full Name must contain only letters, spaces, hyphens or dots'));
  if(!em)                                              errors.push(eFieldErr('e_email','Email is required'));
  else if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) errors.push(eFieldErr('e_email','Enter a valid email address'));
  if(!cid)                                             errors.push(eFieldErr('e_course_id','Course is required'));
  if(mob && mob.length < 5)               errors.push(eFieldErr('e_mobile','Mobile must be a valid 10-digit Indian number'));
  if(aadh && !/^\d{4}\s?\d{4}\s?\d{4}$/.test(aadh)) errors.push(eFieldErr('e_aadhaar','Aadhaar must be 12 digits'));
  if(pin && !/^\d{6}$/.test(pin))                     errors.push(eFieldErr('e_pincode','PIN Code must be exactly 6 digits'));
  if(dob){ var d=new Date(dob); if(isNaN(d.getTime())) errors.push(eFieldErr('e_dob','Enter a valid date of birth')); else if(d>new Date()) errors.push(eFieldErr('e_dob','Date of Birth cannot be in the future')); }
  if(p10!=='' && (isNaN(parseFloat(p10))||parseFloat(p10)<0||parseFloat(p10)>100)) errors.push(eFieldErr('e_qual_10_pct','10th Percentage must be 0–100'));
  if(y10!=='' && (isNaN(parseInt(y10))||parseInt(y10)<1980||parseInt(y10)>new Date().getFullYear())) errors.push(eFieldErr('e_qual_10_year','10th Year must be between 1980 and '+new Date().getFullYear()));
  if(p12!=='' && (isNaN(parseFloat(p12))||parseFloat(p12)<0||parseFloat(p12)>100)) errors.push(eFieldErr('e_qual_12_pct','12th Percentage must be 0–100'));
  if(y12!=='' && (isNaN(parseInt(y12))||parseInt(y12)<1980||parseInt(y12)>new Date().getFullYear())) errors.push(eFieldErr('e_qual_12_year','12th Year must be between 1980 and '+new Date().getFullYear()));
  if(cgpa!=='' && (isNaN(parseFloat(cgpa))||parseFloat(cgpa)<0||parseFloat(cgpa)>10)) errors.push(eFieldErr('e_prev_cgpa','CGPA must be between 0.00 and 10.00'));
  if(fn2 && !/^[A-Za-z .\-']+$/.test(fn2))          errors.push(eFieldErr('e_father_name',"Father's Name must contain only letters"));
  if(mn  && !/^[A-Za-z .\-']+$/.test(mn))           errors.push(eFieldErr('e_mother_name',"Mother's Name must contain only letters"));
  if(gm  && !/^[6-9]\d{9}$/.test(gm))                errors.push(eFieldErr('e_guardian_mobile','Guardian Mobile must be a valid 10-digit Indian number'));

  if(errors.length){
    // Show toast at top of edit modal body
    var old2 = document.getElementById('editToast');
    if(old2) old2.remove();
    var t = document.createElement('div');
    t.id = 'editToast';
    t.style.cssText='position:sticky;top:0;z-index:10;margin:0 0 12px;padding:10px 14px;border-radius:9px;font-size:.8rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;line-height:1.6';
    var msg = errors.slice(0,3).join('<br>');
    if(errors.length>3) msg += '<br>… and '+(errors.length-3)+' more issue(s).';
    t.innerHTML='<div style=\'display:flex;align-items:flex-start;gap:8px\'><i class=\'fas fa-circle-exclamation\' style=\'margin-top:2px;flex-shrink:0\'></i><div>'+msg+'</div></div>';
    var body = document.querySelector('#editModal .modal-body');
    if(body) body.insertBefore(t, body.firstChild);
    setTimeout(function(){ if(t.parentNode) t.remove(); }, 5000);
    return false;
  }
  return true;
}

document.getElementById('editForm')?.addEventListener('submit', function(e){
  if(!validateEditForm()) e.preventDefault();
});

// ── DELETE ──
function deleteApp(id, appNo){
  if(!confirm('Delete application ' + appNo + '?\n\nThis action cannot be undone.')) return;
  document.getElementById('deleteId').value = id;
  document.getElementById('deleteForm').submit();
}

function cpGenReceipt() {
  var code = '<?= strtoupper(substr(preg_replace("/[^A-Z]/i","",strtoupper($collegeCode)),0,3)) ?: "FEE" ?>';
  var yr   = '<?= date("Y") ?>';
  var rand = String(Math.floor(Math.random() * 9000) + 1000);
  return 'RCP-' + code + '-' + yr + '-' + rand;
}

function collectPayment(btn) {
  var d = btn.dataset;
  document.getElementById('cpStudentName').textContent  = d.studentName;
  document.getElementById('cpRoll').textContent         = d.roll || '\u2014';
  document.getElementById('cpSem').textContent          = 'Sem ' + d.semester + ' \u00b7 ' + d.year;
  document.getElementById('cpTotalFee').textContent     = '\u20b9' + parseFloat(d.total).toFixed(2);
  document.getElementById('cpAlreadyPaid').textContent  = '\u20b9' + parseFloat(d.paid).toFixed(2);
  document.getElementById('cpBalance').textContent      = '\u20b9' + parseFloat(d.balance).toFixed(2);
  document.getElementById('cpFeeId').value              = d.feeId;
  document.getElementById('cpStudentId').value          = d.studentId;
  document.getElementById('cpAmountPaid').value         = parseFloat(d.balance) > 0 ? parseFloat(d.balance).toFixed(2) : '';
  document.getElementById('cpPayMode').value            = 'Cash';
  document.getElementById('cpTxnId').value              = '';
  document.getElementById('cpPayDate').value            = new Date().toISOString().split('T')[0];
  document.getElementById('cpTxnGroup').style.opacity   = '0.4';
  document.getElementById('cpReceiptNo').value          = cpGenReceipt();
  document.getElementById('cpModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closePayModal() {
  document.getElementById('cpModal').classList.remove('open');
  document.body.style.overflow = '';
}
function cpModeChange(sel) {
  document.getElementById('cpTxnGroup').style.opacity = sel.value !== 'Cash' ? '1' : '0.4';
}
function printReceipt(btn) {
  var d = btn.dataset;
  var netPayable = (parseFloat(d.total) - parseFloat(d.discount) + parseFloat(d.latefee)).toFixed(2);
  var balAfter   = parseFloat(d.balance).toFixed(2);
  var html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt ${d.receipt}</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;color:#1a1a2e;padding:0}
  .page{width:100%;max-width:680px;margin:0 auto;padding:32px 36px}
  /* Header */
  .hd{display:flex;align-items:center;gap:18px;padding-bottom:18px;border-bottom:3px solid #00d4bb}
  .hd-logo{width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#00d4bb,#0099ff);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;font-weight:900;flex-shrink:0}
  .hd-info h1{font-size:1.25rem;font-weight:800;color:#1a1a2e;letter-spacing:.3px}
  .hd-info p{font-size:.75rem;color:#666;margin-top:2px}
  .hd-right{margin-left:auto;text-align:right}
  .hd-right .rtitle{font-size:1rem;font-weight:800;color:#00d4bb;letter-spacing:1px;text-transform:uppercase}
  .hd-right .rno{font-size:.72rem;font-family:monospace;color:#555;margin-top:3px}
  /* Status badge */
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.65rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase}
  .badge-success{background:#d1fae5;color:#065f46}
  .badge-pending{background:#fef3c7;color:#92400e}
  .badge-failed{background:#fee2e2;color:#991b1b}
  /* Info grid */
  .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:20px 0}
  .info-cell{padding:10px 16px;border-bottom:1px solid #e5e7eb;border-right:1px solid #e5e7eb}
  .info-cell:nth-child(even){border-right:none}
  .info-cell:nth-last-child(-n+2){border-bottom:none}
  .info-cell .lbl{font-size:.6rem;color:#999;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
  .info-cell .val{font-size:.82rem;font-weight:600;color:#1a1a2e}
  /* Fee breakdown */
  .fee-table{width:100%;border-collapse:collapse;margin:4px 0 20px}
  .fee-table td{padding:7px 14px;font-size:.82rem;border-bottom:1px solid #f0f0f0}
  .fee-table td:last-child{text-align:right;font-family:monospace}
  .fee-table tr.total-row td{font-weight:800;font-size:.88rem;border-top:2px solid #00d4bb;border-bottom:none;background:#f8fffd}
  .fee-table tr.paid-row td{color:#059669;font-weight:700;font-size:.88rem;border-bottom:none}
  .fee-table tr.bal-row td{color:#dc2626;font-weight:700;font-size:.88rem;border-bottom:none;border-top:1px dashed #e5e7eb}
  .section-title{font-size:.68rem;font-weight:700;color:#00d4bb;text-transform:uppercase;letter-spacing:1px;margin:20px 0 6px}
  /* Footer */
  .footer{margin-top:28px;padding-top:16px;border-top:1px dashed #ccc;display:flex;justify-content:space-between;align-items:flex-end}
  .footer .note{font-size:.65rem;color:#aaa;max-width:280px;line-height:1.5}
  .sig-box{text-align:center}
  .sig-line{width:150px;border-top:1px solid #555;margin-bottom:5px}
  .sig-label{font-size:.65rem;color:#777}
  .watermark{text-align:center;margin-top:18px;font-size:.62rem;color:#ccc;letter-spacing:.5px}
  @media print{
    body{padding:0}
    .page{padding:20px 24px}
    button{display:none}
  }
</style>
</head>
<body>
<div class="page">
  <div class="hd">
    <div class="hd-logo">${d.collegecode ? d.collegecode.charAt(0).toUpperCase() : 'C'}</div>
    <div class="hd-info">
      <h1>${d.college}</h1>
      <p>Official Fee Payment Receipt</p>
    </div>
    <div class="hd-right">
      <div class="rtitle">Receipt</div>
      <div class="rno">${d.receipt}</div>
      <div style="margin-top:6px"><span class="badge badge-${d.status === 'Success' ? 'success' : d.status === 'Failed' ? 'failed' : 'pending'}">${d.status}</span></div>
    </div>
  </div>

  <div class="section-title"><i>&#128100;</i> Student Details</div>
  <div class="info-grid">
    <div class="info-cell"><div class="lbl">Student Name</div><div class="val">${d.student}</div></div>
    <div class="info-cell"><div class="lbl">Roll / Admission No</div><div class="val">${d.roll || '—'}</div></div>
    <div class="info-cell"><div class="lbl">Semester</div><div class="val">${d.semester ? 'Semester ' + d.semester : '—'}</div></div>
    <div class="info-cell"><div class="lbl">Academic Year</div><div class="val">${d.year || '—'}</div></div>
  </div>

  <div class="section-title">&#128179; Payment Details</div>
  <div class="info-grid">
    <div class="info-cell"><div class="lbl">Payment Date</div><div class="val">${d.date}</div></div>
    <div class="info-cell"><div class="lbl">Payment Mode</div><div class="val">${d.mode}</div></div>
    <div class="info-cell"><div class="lbl">Transaction / UTR ID</div><div class="val">${d.txn || '—'}</div></div>
    <div class="info-cell"><div class="lbl">Collected By</div><div class="val">${d.collector || '—'}</div></div>
  </div>

  <div class="section-title">&#128203; Fee Breakdown</div>
  <table class="fee-table">
    <tr><td>${d.feetype || 'Tuition Fee'} (${d.year || ''})</td><td>₹ ${parseFloat(d.total).toFixed(2)}</td></tr>
    ${parseFloat(d.discount) > 0 ? `<tr><td style="color:#059669">Scholarship / Discount</td><td style="color:#059669">− ₹ ${parseFloat(d.discount).toFixed(2)}</td></tr>` : ''}
    ${parseFloat(d.latefee) > 0 ? `<tr><td style="color:#dc2626">Late Fee / Fine</td><td style="color:#dc2626">+ ₹ ${parseFloat(d.latefee).toFixed(2)}</td></tr>` : ''}
    <tr class="total-row"><td>Net Payable</td><td>₹ ${netPayable}</td></tr>
    <tr class="paid-row"><td>Amount Paid (This Receipt)</td><td>₹ ${parseFloat(d.amount).toFixed(2)}</td></tr>
    <tr class="bal-row"><td>Balance Remaining</td><td>₹ ${balAfter}</td></tr>
  </table>

  <div class="footer">
    <div class="note">This is a computer-generated receipt and does not require a physical signature. Please retain this for your records.</div>
    <div class="sig-box">
      <div class="sig-line"></div>
      <div class="sig-label">Authorised Signatory</div>
      <div class="sig-label" style="margin-top:2px">${d.collector || 'Accounts Office'}</div>
    </div>
  </div>
  <div class="watermark">${d.college} &bull; ${d.receipt} &bull; Generated on ${new Date().toLocaleString()}</div>
</div>
<script>window.onload=function(){window.print()}<\/script>
</body></html>`;

  var w = window.open('','_blank','width=750,height=900');
  w.document.write(html);
  w.document.close();
}
/* ── Fee Reminder helpers ────────────────────────────────────────────────── */
function showReminderToast(ok, msg){
  var old = document.getElementById('reminderToast');
  if(old) old.remove();
  var t = document.createElement('div');
  t.id = 'reminderToast';
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 18px;border-radius:10px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.5);animation:slideUp2 .25s ease both;max-width:360px;line-height:1.4;'
    + (ok ? 'background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#34d399'
          : 'background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171');
  t.innerHTML = '<i class="fas '+(ok?'fa-circle-check':'fa-circle-exclamation')+'"></i><span>'+msg+'</span>';
  document.body.appendChild(t);
  setTimeout(function(){ if(t.parentNode) t.remove(); }, 5000);
}

function sendReminder(studentId, studentName, studentEmail){
  if(!confirm('Send fee payment reminder email to ' + studentName + ' (' + studentEmail + ')?')) return;

  var btn = document.getElementById('remind-btn-' + studentId);
  if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…'; }

  var fd = new FormData();
  fd.append('action', 'send_reminder');
  fd.append('reminder_student_id', studentId);

  fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(res){
      showReminderToast(res.ok, res.msg || (res.ok ? 'Reminder sent!' : 'Failed to send.'));
      if(btn){
        btn.disabled = false;
        btn.innerHTML = res.ok
          ? '<i class="fas fa-check"></i> Sent'
          : '<i class="fas fa-envelope"></i> Remind';
        if(res.ok){ btn.style.background='rgba(16,185,129,.1)'; btn.style.borderColor='rgba(16,185,129,.3)'; btn.style.color='var(--green)'; }
      }
    })
    .catch(function(err){
      showReminderToast(false, 'Network error: ' + err.message);
      if(btn){ btn.disabled=false; btn.innerHTML='<i class="fas fa-envelope"></i> Remind'; }
    });
}

function sendReminders(){
  var rows = document.querySelectorAll('[id^="remind-btn-"]');
  if(!rows.length){ alert('No defaulters to remind.'); return; }
  if(!confirm('Send fee reminder emails to all ' + rows.length + ' defaulter(s)?')) return;

  var allBtn = document.querySelector('[onclick="sendReminders()"]');
  if(allBtn){ allBtn.disabled=true; allBtn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending…'; }

  var fd = new FormData();
  fd.append('action', 'send_all_reminders');

  fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(res){
      var msg = 'Sent: ' + res.sent + '  |  Failed: ' + res.failed;
      if(res.skipped) msg += '  |  Skipped (no email): ' + res.skipped;
      showReminderToast(res.ok && res.sent > 0, msg);
      if(allBtn){ allBtn.disabled=false; allBtn.innerHTML='<i class="fas fa-envelope"></i> Send Reminders'; }
      // Mark sent buttons
      rows.forEach(function(b){
        b.innerHTML='<i class="fas fa-check"></i> Sent';
        b.style.color='var(--green)';
      });
    })
    .catch(function(err){
      showReminderToast(false, 'Network error: ' + err.message);
      if(allBtn){ allBtn.disabled=false; allBtn.innerHTML='<i class="fas fa-envelope"></i> Send Reminders'; }
    });
}

/* ── Application Modal — 5-step stepper ─────────────────────────────────────── */
var currentStep = 1;
var totalSteps  = 5;

// Generate a random application number when modal opens
function generateAppNo() {
  var code = '<?= strtoupper(substr(preg_replace('/[^A-Z]/i','',strtoupper($collegeCode)),0,3)) ?: 'APP' ?>';
  var rand = String(Math.floor(Math.random()*9999)+1).padStart(4,'0');
  return 'APP-' + code + '-<?= date('Y') ?>-' + rand;
}

function openAppModal(){
  currentStep = 1;
  renderStep();
  document.getElementById('displayAppNo').textContent = generateAppNo();
  document.getElementById('appModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeAppModal(){
  document.getElementById('appModal').classList.remove('open');
  document.body.style.overflow='';
}
function closeOnOverlay(e){
  if(e.target===document.getElementById('appModal')) closeAppModal();
}

// Keyboard ESC to close
document.addEventListener('keydown',function(e){
  if(e.key==='Escape') closeAppModal();
});

function renderStep(){
  // Panels
  document.querySelectorAll('.step-panel').forEach(function(p){
    p.classList.remove('active');
    p.style.display = 'none';
  });
  var panel = document.getElementById('panel-'+currentStep);
  if(panel){
    panel.classList.add('active');
    panel.style.display = 'flex';
    panel.style.flexDirection = 'column';
    panel.style.flex = '1';
    panel.style.minHeight = '0';
    panel.style.overflowY = 'scroll';
    panel.scrollTop = 0;
  }

  // Step indicators
  var items = document.querySelectorAll('.step-item');
  var conns = document.querySelectorAll('.step-conn');
  items.forEach(function(item,i){
    var s = i+1;
    item.classList.remove('active','done');
    if(s < currentStep) item.classList.add('done');
    else if(s === currentStep) item.classList.add('active');
    // Update dot for done steps
    var dot = item.querySelector('.step-dot');
    if(s < currentStep) dot.innerHTML='<i class="fas fa-check" style="font-size:.65rem"></i>';
    else dot.textContent = s;
  });
  conns.forEach(function(c,i){
    c.classList.toggle('done', (i+1) < currentStep);
  });

  // Progress bar
  var pct = ((currentStep-1)/(totalSteps-1))*100;
  document.getElementById('stepProgress').style.width = pct+'%';

  // Step chip
  document.getElementById('stepChip').textContent = 'Step '+currentStep+' of '+totalSteps;

  // Back button
  document.getElementById('btnBack').style.display = currentStep > 1 ? 'flex' : 'none';

  // Next vs Submit
  var btnNext   = document.getElementById('btnNext');
  var btnSubmit = document.getElementById('btnSubmit');
  if(currentStep === totalSteps){
    btnNext.style.display   = 'none';
    btnSubmit.style.display = 'flex';
  } else {
    btnNext.style.display   = 'flex';
    btnSubmit.style.display = 'none';
  }
}

function stepNav(dir){
  // Basic required-field check before advancing
  if(dir === 1 && !validateStep(currentStep)) return;
  currentStep = Math.max(1, Math.min(totalSteps, currentStep + dir));
  renderStep();
}

/* ── Validation helpers ──────────────────────────────────────────────────── */
function isAlphaName(v){ return /^[A-Za-z\s\.\-']+$/.test(v); }
function isValidEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
function isValidMobile(v){ return /^[6-9]\d{9}$/.test(v.replace(/\s/g,'')); }
function isValidAadhaar(v){ return v==='' || /^\d{4}\s?\d{4}\s?\d{4}$/.test(v); }
function isValidPincode(v){ return v==='' || /^\d{6}$/.test(v); }
function isValidYear(v){ var y=parseInt(v); return v==='' || (y>=1980 && y<=new Date().getFullYear()); }
function isValidPct(v){ if(v==='') return true; var n=parseFloat(v); return !isNaN(n) && n>=0 && n<=100; }
function isValidCGPA(v){ if(v==='') return true; var n=parseFloat(v); return !isNaN(n) && n>=0 && n<=10; }
function isValidScore(v){ if(v==='') return true; var n=parseFloat(v); return !isNaN(n) && n>=0; }
function isAlphaNameOptional(v){ return v==='' || isAlphaName(v); }

function fieldErr(name, msg){
  // Highlight the field visually
  var el = document.querySelector('[name='+name+']');
  if(el){
    el.style.borderColor='rgba(239,68,68,.7)';
    el.style.boxShadow='0 0 0 2px rgba(239,68,68,.18)';
    el.addEventListener('input',function once(){
      el.style.borderColor=''; el.style.boxShadow='';
      el.removeEventListener('input',once);
    },{once:true});
  }
  return msg;
}

function validateStep(step){
  var errors = [];

  if(step === 1){
    var fn   = (document.querySelector('[name=full_name]')?.value || '').trim();
    var em   = (document.querySelector('[name=email]')?.value || '').trim();
    var mob  = (document.querySelector('[name=mobile]')?.value || '').trim();
    var gen  = (document.querySelector('[name=gender]')?.value || '');
    var cat  = (document.querySelector('[name=category]')?.value || '');
    var aadh = (document.querySelector('[name=aadhaar]')?.value || '').trim();
    var pin  = (document.querySelector('[name=pincode]')?.value || '').trim();
    var dob  = (document.querySelector('[name=dob]')?.value || '').trim();

    // Required
    if(!fn)                              errors.push(fieldErr('full_name',  'Full Name is required'));
    else if(!isAlphaName(fn))            errors.push(fieldErr('full_name',  'Full Name must contain only letters, spaces, hyphens or dots'));

    if(!em)                              errors.push(fieldErr('email',      'Email Address is required'));
    else if(!isValidEmail(em))           errors.push(fieldErr('email',      'Enter a valid email address'));

    if(!gen)                             errors.push(fieldErr('gender',     'Gender is required'));
    if(!cat)                             errors.push(fieldErr('category',   'Category is required'));

    // Optional but format-checked
    if(mob && !isValidMobile(mob))       errors.push(fieldErr('mobile',     'Mobile must be a valid 10-digit Indian number (starts with 6-9)'));
    if(!isValidAadhaar(aadh))            errors.push(fieldErr('aadhaar',    'Aadhaar must be 12 digits (e.g. XXXX XXXX XXXX)'));
    if(!isValidPincode(pin))             errors.push(fieldErr('pincode',    'PIN Code must be exactly 6 digits'));
    if(dob){
      var d = new Date(dob);
      var today = new Date();
      if(isNaN(d.getTime()))             errors.push(fieldErr('dob',        'Enter a valid date of birth'));
      else if(d > today)                 errors.push(fieldErr('dob',        'Date of Birth cannot be in the future'));
    }

  } else if(step === 2){
    var cid    = (document.querySelector('[name=course_id]')?.value || '');
    var b10    = (document.querySelector('[name=qual_10_board]')?.value || '').trim();
    var pct10  = (document.querySelector('[name=qual_10_percent]')?.value || '').trim();
    var yr10   = (document.querySelector('[name=qual_10_year]')?.value || '').trim();
    var b12    = (document.querySelector('[name=qual_12_board]')?.value || '').trim();
    var pct12  = (document.querySelector('[name=qual_12_percent]')?.value || '').trim();
    var yr12   = (document.querySelector('[name=qual_12_year]')?.value || '').trim();
    var cgpa   = (document.querySelector('[name=prev_cgpa]')?.value || '').trim();
    var entScr = (document.querySelector('[name=entrance_score]')?.value || '').trim();

    if(!cid)                             errors.push(fieldErr('course_id',       'Course selection is required'));
    if(!b10)                             errors.push(fieldErr('qual_10_board',   '10th Board/School name is required'));
    if(!b12)                             errors.push(fieldErr('qual_12_board',   '12th Board/School name is required'));

    // 10th
    if(pct10 !== '' && !isValidPct(pct10))
                                         errors.push(fieldErr('qual_10_percent', '10th Percentage must be between 0 and 100'));
    if(!isValidYear(yr10))               errors.push(fieldErr('qual_10_year',    '10th Passing Year must be between 1980 and current year'));

    // 12th
    if(pct12 !== '' && !isValidPct(pct12))
                                         errors.push(fieldErr('qual_12_percent', '12th Percentage must be between 0 and 100'));
    if(!isValidYear(yr12))               errors.push(fieldErr('qual_12_year',    '12th Passing Year must be between 1980 and current year'));

    // Previous degree CGPA (0–10 scale)
    if(!isValidCGPA(cgpa))               errors.push(fieldErr('prev_cgpa',       'CGPA must be between 0.00 and 10.00'));

    // Entrance score (non-negative number)
    if(!isValidScore(entScr))            errors.push(fieldErr('entrance_score',  'Entrance Score must be a positive number'));

  } else if(step === 3){
    var fn2  = (document.querySelector('[name=father_name]')?.value || '').trim();
    var mn   = (document.querySelector('[name=mother_name]')?.value || '').trim();
    var gm   = (document.querySelector('[name=guardian_mobile]')?.value || '').trim();
    var inc  = (document.querySelector('[name=annual_income]')?.value || '').trim();

    if(!fn2)                             errors.push(fieldErr('father_name',     "Father's Name is required"));
    else if(!isAlphaName(fn2))           errors.push(fieldErr('father_name',     "Father's Name must contain only letters"));

    if(!mn)                              errors.push(fieldErr('mother_name',     "Mother's Name is required"));
    else if(!isAlphaName(mn))            errors.push(fieldErr('mother_name',     "Mother's Name must contain only letters"));

    if(!gm)                              errors.push(fieldErr('guardian_mobile', 'Guardian Mobile is required'));
    else if(!isValidMobile(gm))          errors.push(fieldErr('guardian_mobile', 'Guardian Mobile must be a valid 10-digit Indian number'));

    var validIncomes = ['Below ₹1 Lakh','₹1 – 2.5 Lakhs','₹2.5 – 5 Lakhs','₹5 – 10 Lakhs','Above ₹10 Lakhs'];
    if(inc !== '' && !validIncomes.includes(inc))
                                         errors.push(fieldErr('annual_income',   'Please select a valid income range'));

  } else if(step === 4){
    var requiredDocs = [
      {key:'doc_photo',     label:'Passport Size Photo'},
      {key:'doc_signature', label:'Signature'},
      {key:'doc_marks10',   label:'10th Marks Card'},
      {key:'doc_marks12',   label:'12th Marks Card'},
      {key:'doc_tc',        label:'Transfer Certificate (TC)'},
      {key:'doc_aadhaar',   label:'Aadhaar Card'},
    ];
    var missing = [];
    requiredDocs.forEach(function(doc){
      var inp = document.getElementById(doc.key);
      var row = inp ? inp.closest('.mf-doc-row') : null;
      var uploaded = row && row.classList.contains('uploaded');
      if(!uploaded){
        missing.push(doc.label);
        // highlight the row red
        if(row){
          row.style.borderColor = 'rgba(239,68,68,.5)';
          row.style.background  = 'rgba(239,68,68,.05)';
          var hint = document.getElementById('hint-'+doc.key);
          if(hint){ hint.textContent = '✗ Required — please upload'; hint.style.color='var(--red)'; }
        }
      }
    });
    if(missing.length){
      errors.push('Please upload all required documents: <strong>' + missing.join(', ') + '</strong>');
    }

  } else if(step === 5){
    var chk = document.getElementById('declCheck');
    if(chk && !chk.checked)             errors.push('Please agree to the declaration before submitting.');
  }

  if(errors.length){
    // Show only first 3 errors to avoid overwhelming the user
    var msg = errors.slice(0,3).join('<br>');
    if(errors.length > 3) msg += '<br>… and ' + (errors.length-3) + ' more issue(s).';
    flashError(msg);
    return false;
  }
  return true;
}

function flashError(msg){
  // Show a temporary toast inside the modal (msg may contain <br> for multiple errors)
  var old = document.getElementById('stepToast');
  if(old) old.remove();
  var t = document.createElement('div');
  t.id = 'stepToast';
  t.style.cssText='position:sticky;top:0;z-index:10;margin:0 0 12px;padding:10px 14px;border-radius:9px;font-size:.8rem;background:var(--red2);border:1px solid rgba(239,68,68,.3);color:var(--red);line-height:1.6';
  t.innerHTML='<div style=\'display:flex;align-items:flex-start;gap:8px\'><i class=\'fas fa-circle-exclamation\' style=\'margin-top:2px;flex-shrink:0\'></i><div>'+msg+'</div></div>';
  var panel = document.getElementById('panel-'+currentStep);
  if(panel) panel.insertBefore(t, panel.firstChild);
  setTimeout(function(){ if(t.parentNode) t.remove(); }, 5000);
}

// Payment toggle: show/hide txn field and pending note
function toggleTxn(){
  var paid = document.querySelector('[name=payment_status]:checked')?.value === 'Paid';
  document.getElementById('txnGroup').style.display      = paid ? 'flex' : 'none';
  document.getElementById('payPendingNote').style.display = paid ? 'none' : 'flex';
}

// Sync radio visual state
function updateRadio(){
  document.querySelectorAll('.mf-radio').forEach(function(lbl){
    var inp=lbl.querySelector('input');
    if(inp) lbl.classList.toggle('checked', inp.checked);
  });
}

// File upload handler — shows filename and marks row as uploaded
function handleFile(input, key){
  var row     = input.closest('.mf-doc-row');
  var hint    = document.getElementById('hint-'+key);
  var lbl     = document.getElementById('lbl-'+key);
  var maxBytes = 5 * 1024 * 1024; // 5 MB — must match PHP

  if(input.files && input.files[0]){
    var file = input.files[0];

    // Client-side size guard — gives instant feedback before form submit
    if(file.size > maxBytes){
      if(hint){ hint.textContent = '✗ File too large (' + (file.size/1024/1024).toFixed(1) + ' MB). Max 5 MB.'; hint.style.color='var(--red)'; }
      if(lbl)  lbl.textContent = 'Upload';
      if(row){ row.classList.remove('uploaded'); row.style.borderColor='rgba(239,68,68,.4)'; }
      input.value = ''; // clear so it won't be submitted
      return;
    }

    // Reset any previous error styling
    if(row){ row.style.borderColor = ''; row.style.background = ''; }
    if(hint){ hint.style.color = ''; }

    var sizeMB = (file.size / 1024 / 1024).toFixed(2);
    if(hint) hint.textContent = '✓ ' + file.name + '  (' + sizeMB + ' MB)';
    if(lbl)  lbl.textContent  = 'Change';
    if(row)  row.classList.add('uploaded');
  } else {
    if(hint){ hint.textContent = hint.dataset.orig || 'Required'; hint.style.color = ''; }
    if(lbl)  lbl.textContent = 'Upload';
    if(row){ row.classList.remove('uploaded'); row.style.borderColor = ''; }
  }
}

// Submit: loading state
document.getElementById('appForm')?.addEventListener('submit',function(e){
  if(!validateStep(5)){ e.preventDefault(); return; }
  var btn = document.getElementById('btnSubmit');
  btn.disabled=true;
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting…';
});

// Init on DOMContentLoaded
document.addEventListener('DOMContentLoaded',function(){
  updateRadio();
  renderStep();
  <?php if($formError || $formSuccess): ?>
  openAppModal();
  <?php endif; ?>
  <?php if(!empty($feeError)): ?>
  openFeeModal();
  <?php endif; ?>
});
</script>

<style>
/* ── Fee modal compact overrides ─────────────────────────────────────────── */
#feeModal .mf-input{font-size:.8rem;padding:6px 10px;height:32px}
#feeModal textarea.mf-input{height:auto}
#feeModal .mf-label{font-size:.7rem;margin-bottom:2px}
#feeModal .mf-group{margin-bottom:7px}
#feeModal .mf-section-title{font-size:.6rem;margin-bottom:6px}
#feeModal .modal-ft{padding:8px 18px}
</style>
<!-- ══ ADD FEE ENTRY MODAL ═════════════════════════════════════════════════════ -->
<div id="feeModal" class="modal-overlay" onclick="if(event.target===this)closeFeeModal()">
  <div class="modal-box" style="max-width:680px;height:min(88vh,780px);max-height:88vh;display:flex;flex-direction:column" role="dialog" aria-modal="true">

    <div class="modal-hd">
      <div class="modal-title">
        <div class="modal-icon" style="background:linear-gradient(135deg,var(--teal),var(--teal2))"><i class="fas fa-cash-register"></i></div>
        <div>
          <div style="font-size:.95rem;font-weight:700;color:var(--text)">Add Fee Entry</div>
          <div style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)"><?= esc($collegeName) ?> · <?= esc($collegeCode) ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span id="feeReceiptBadge" style="font-size:.65rem;font-weight:700;padding:3px 9px;border-radius:20px;background:var(--teal-soft);border:1px solid var(--border-accent);color:var(--teal);font-family:var(--mono)">RCP-…</span>
        <button class="modal-close" onclick="closeFeeModal()"><i class="fas fa-xmark"></i></button>
      </div>
    </div>

    <?php if(!empty($feeError)): ?>
    <div class="modal-alert error"><i class="fas fa-circle-exclamation"></i> <?= esc($feeError) ?></div>
    <?php endif; ?>

    <form method="POST" action="?tab=fees" id="feeForm" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
      <input type="hidden" name="action" value="add_fee_entry">

      <div class="modal-body" style="overflow-y:auto;padding:16px 20px 8px;flex:1;min-height:0;scrollbar-width:thin;scrollbar-color:rgba(0,212,187,.55) rgba(255,255,255,.05)">

        <!-- ── STUDENT DETAILS ── -->
        <div class="mf-section-title" style="margin-bottom:7px"><i class="fas fa-user-graduate"></i> Student Details <div class="mf-section-line"></div></div>
        <div class="mf-row" style="margin-bottom:7px">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Student <span class="req">*</span></label>
            <select name="fee_student_id" id="feeStudentSel" class="mf-input" onchange="onStudentChange(this)" required>
              <option value="">— Search / Select student —</option>
              <?php
              if ($db) {
                  $stu = $db->prepare('SELECT id, full_name, roll_number FROM users WHERE college_id = ? AND role = "student" AND status = "active" ORDER BY full_name LIMIT 200');
                  $stu->execute([$collegeId]);
                  foreach ($stu->fetchAll(PDO::FETCH_ASSOC) as $s) {
                      echo '<option value="' . (int)$s['id'] . '" data-roll="' . esc($s['roll_number'] ?? '') . '">'
                           . esc($s['full_name']) . ' — ' . esc($s['roll_number'] ?? 'N/A') . '</option>';
                  }
              }
              ?>
            </select>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Admission No</label>
            <input type="text" id="feeAdmNo" class="mf-input" placeholder="Auto-filled" readonly style="opacity:.6">
          </div>
        </div>
        <div class="mf-row3" style="margin-bottom:0">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Semester <span class="req">*</span></label>
            <select name="fee_semester" class="mf-input" required>
              <option value="">Select</option>
              <?php for($i=1;$i<=8;$i++) echo '<option value="'.$i.'">Sem '.$i.'</option>'; ?>
            </select>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Academic Year <span class="req">*</span></label>
            <input type="text" name="fee_academic_year" class="mf-input" value="<?= date('Y') . '-' . (date('Y')+1) ?>" placeholder="e.g. 2025-26" required>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Due Date</label>
            <input type="date" name="fee_due_date" class="mf-input">
          </div>
        </div>

        <!-- ── FEE DETAILS ── -->
        <div class="mf-section-title" style="margin-top:12px;margin-bottom:7px"><i class="fas fa-receipt"></i> Fee Details <div class="mf-section-line"></div></div>
        <div class="mf-row" style="margin-bottom:7px">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Fee Type <span class="req">*</span></label>
            <select name="fee_type" class="mf-input" required onchange="feeCalc()">
              <option value="">Select type</option>
              <option value="Tuition">Tuition Fee</option>
              <option value="Exam">Exam Fee</option>
              <option value="Hostel">Hostel Fee</option>
              <option value="Bus">Bus / Transport Fee</option>
              <option value="Library">Library Fee</option>
              <option value="Miscellaneous">Miscellaneous</option>
            </select>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Total Fee Amount (₹) <span class="req">*</span></label>
            <input type="number" name="fee_total" id="feeTotal" class="mf-input" placeholder="0.00" min="0" step="0.01" oninput="feeCalc()" required>
          </div>
        </div>
        <div class="mf-row3" style="margin-bottom:0">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Discount / Scholarship (₹)</label>
            <input type="number" name="fee_discount" id="feeDiscount" class="mf-input" placeholder="0.00" min="0" step="0.01" oninput="feeCalc()">
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Fine / Late Fee (₹)</label>
            <input type="number" name="fee_late" id="feeLate" class="mf-input" placeholder="0.00" min="0" step="0.01" oninput="feeCalc()">
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label" style="color:var(--teal)">Net Payable (₹)</label>
            <input type="text" id="feeNet" class="mf-input" value="0.00" readonly style="color:var(--teal);font-weight:700;cursor:default">
          </div>
        </div>

        <!-- ── PAYMENT DETAILS ── -->
        <div class="mf-section-title" style="margin-top:12px;margin-bottom:7px"><i class="fas fa-credit-card"></i> Payment Details <div class="mf-section-line"></div></div>

        <!-- Live balance cards -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px">
          <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:8px;padding:8px 11px;display:flex;align-items:center;justify-content:space-between">
            <div><div style="font-size:.6rem;color:var(--muted);margin-bottom:1px">Amount Paid</div>
            <div id="feeCardPaid" style="font-size:.9rem;font-weight:700;color:var(--green);font-family:var(--mono)">₹0.00</div></div>
            <i class="fas fa-arrow-up" style="color:var(--green);font-size:.82rem"></i>
          </div>
          <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:8px 11px;display:flex;align-items:center;justify-content:space-between">
            <div><div style="font-size:.6rem;color:var(--muted);margin-bottom:1px">Net Payable</div>
            <div id="feeCardNet" style="font-size:.9rem;font-weight:700;color:var(--amber);font-family:var(--mono)">₹0.00</div></div>
            <i class="fas fa-calculator" style="color:var(--amber);font-size:.82rem"></i>
          </div>
          <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:8px 11px;display:flex;align-items:center;justify-content:space-between">
            <div><div style="font-size:.6rem;color:var(--muted);margin-bottom:1px">Balance Due</div>
            <div id="feeCardBal" style="font-size:.9rem;font-weight:700;color:var(--red);font-family:var(--mono)">₹0.00</div></div>
            <i class="fas fa-triangle-exclamation" style="color:var(--red);font-size:.82rem"></i>
          </div>
        </div>

        <div class="mf-row" style="margin-bottom:7px">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Amount Paid (₹) <span class="req">*</span></label>
            <input type="number" name="fee_amount_paid" id="feePaid" class="mf-input" placeholder="0.00" min="0" step="0.01" oninput="feeCalc()" required>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Payment Status</label>
            <input type="text" id="feeStatusDisplay" class="mf-input" value="Pending" readonly style="opacity:.7;cursor:default">
            <input type="hidden" name="fee_payment_status" id="feeStatusHidden" value="Pending">
          </div>
        </div>
        <div class="mf-row3" style="margin-bottom:4px">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Payment Method <span class="req">*</span></label>
            <select name="fee_payment_mode" id="feePayMode" class="mf-input" onchange="feeModeChange(this)" required>
              <option value="Cash">Cash</option>
              <option value="UPI">UPI</option>
              <option value="Card">Card</option>
              <option value="Online">Bank Transfer</option>
              <option value="Cheque">Cheque / DD</option>
            </select>
          </div>
          <div class="mf-group" id="feeTxnGroup" style="margin-bottom:0">
            <label class="mf-label">Transaction / UTR ID</label>
            <input type="text" name="fee_transaction_id" class="mf-input" placeholder="TXN or UTR number">
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Payment Date <span class="req">*</span></label>
            <input type="date" name="fee_payment_date" id="feePayDate" class="mf-input" required>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-ft">
        <span id="feeReceiptFooter" style="font-size:.68rem;color:var(--muted);font-family:var(--mono)">Receipt: —</span>
        <div style="display:flex;gap:8px;margin-left:auto">
          <button type="button" onclick="closeFeeModal()" class="mf-btn mf-btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
          <button type="submit" id="feeSaveBtn" class="mf-btn mf-btn-submit"><i class="fas fa-floppy-disk"></i> Save Fee Entry</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Fee Modal ────────────────────────────────────────────────────────────────── */
function genFeeReceipt(){
  var code = '<?= strtoupper(substr(preg_replace('/[^A-Z]/i','',strtoupper($collegeCode)),0,3)) ?: 'FEE' ?>';
  return 'RCP-' + code + '-<?= date('Y') ?>-' + String(Math.floor(Math.random()*9000)+1000);
}
var _feeReceipt = genFeeReceipt();

function openFeeModal(){
  _feeReceipt = genFeeReceipt();
  document.getElementById('feeReceiptBadge').textContent  = _feeReceipt;
  document.getElementById('feeReceiptFooter').textContent = 'Receipt: ' + _feeReceipt;
  document.getElementById('feePayDate').value = new Date().toISOString().split('T')[0];
  feeCalc();
  document.getElementById('feeModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeFeeModal(){
  document.getElementById('feeModal').classList.remove('open');
  document.body.style.overflow = '';
}

function feeCalc(){
  var total   = parseFloat(document.getElementById('feeTotal').value)    || 0;
  var disc    = parseFloat(document.getElementById('feeDiscount').value)  || 0;
  var late    = parseFloat(document.getElementById('feeLate').value)      || 0;
  var paid    = parseFloat(document.getElementById('feePaid').value)      || 0;
  var net     = Math.max(0, total - disc + late);
  var bal     = Math.max(0, net - paid);
  var fmt     = function(n){ return '₹' + n.toFixed(2); };

  document.getElementById('feeNet').value          = net.toFixed(2);
  document.getElementById('feeCardNet').textContent = fmt(net);
  document.getElementById('feeCardPaid').textContent= fmt(paid);
  document.getElementById('feeCardBal').textContent = fmt(bal);

  var status = 'Pending';
  if(paid >= net && net > 0)      status = 'Paid';
  else if(paid > 0 && paid < net) status = 'Partially Paid';
  document.getElementById('feeStatusDisplay').value = status;
  document.getElementById('feeStatusHidden').value  = status;

  // Color cue on balance card
  var balCard = document.getElementById('feeCardBal').parentElement.parentElement;
  if(bal === 0 && net > 0){
    balCard.style.background = 'rgba(22,163,74,.08)';
    balCard.style.borderColor= 'rgba(22,163,74,.25)';
    document.getElementById('feeCardBal').style.color = 'var(--success)';
  } else {
    balCard.style.background = 'rgba(239,68,68,.07)';
    balCard.style.borderColor= 'rgba(239,68,68,.2)';
    document.getElementById('feeCardBal').style.color = 'var(--red)';
  }
}

function feeModeChange(sel){
  var show = sel.value !== 'Cash';
  document.getElementById('feeTxnGroup').style.opacity = show ? '1' : '0.4';
}

function onStudentChange(sel){
  var opt = sel.options[sel.selectedIndex];
  var roll = opt.dataset.roll || '';
  document.getElementById('feeAdmNo').value = roll || '—';
}

document.getElementById('feeForm').addEventListener('submit', function(e){
  var btn = document.getElementById('feeSaveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
});
</script>

<!-- ── Collect Payment Modal ────────────────────────────────────────────────── -->
<div id="cpModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closePayModal()">
  <div class="modal-box" style="max-width:480px;width:95%">
    <div class="modal-hd">
      <div>
        <div class="modal-title"><i class="fas fa-money-bill-wave" style="color:var(--teal)"></i> Collect Payment</div>
        <div id="cpSem" style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)"></div>
      </div>
      <button class="modal-close" onclick="closePayModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <!-- Student summary strip -->
    <div style="background:var(--teal-soft);border:1px solid var(--border-accent);border-radius:10px;padding:12px 16px;margin:12px 20px 0;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px">
      <div>
        <div style="font-size:.58rem;color:var(--muted);margin-bottom:2px">STUDENT</div>
        <div id="cpStudentName" style="font-size:.8rem;font-weight:700;color:var(--text)"></div>
        <div id="cpRoll" style="font-size:.65rem;color:var(--muted);font-family:var(--mono)"></div>
      </div>
      <div style="text-align:center">
        <div style="font-size:.58rem;color:var(--muted);margin-bottom:2px">ALREADY PAID</div>
        <div id="cpAlreadyPaid" style="font-size:.88rem;font-weight:700;color:var(--green);font-family:var(--mono)"></div>
        <div style="font-size:.58rem;color:var(--muted)">of <span id="cpTotalFee" style="font-family:var(--mono)"></span></div>
      </div>
      <div style="text-align:right">
        <div style="font-size:.58rem;color:var(--muted);margin-bottom:2px">BALANCE DUE</div>
        <div id="cpBalance" style="font-size:.88rem;font-weight:700;color:var(--red);font-family:var(--mono)"></div>
      </div>
    </div>
    <form method="POST" action="?tab=fees" style="display:flex;flex-direction:column;flex:1">
      <input type="hidden" name="action" value="collect_payment">
      <input type="hidden" name="cp_fee_id"     id="cpFeeId">
      <input type="hidden" name="cp_student_id" id="cpStudentId">
      <div class="modal-body" style="padding:16px 20px 8px">
        <div class="mf-row" style="margin-bottom:10px">
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Amount to Collect (₹) <span class="req">*</span></label>
            <input type="number" name="cp_amount" id="cpAmountPaid" class="mf-input" min="0.01" step="0.01" required>
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Payment Method <span class="req">*</span></label>
            <select name="cp_payment_mode" id="cpPayMode" class="mf-input" onchange="cpModeChange(this)" required>
              <option value="Cash">Cash</option>
              <option value="UPI">UPI</option>
              <option value="Card">Card</option>
              <option value="Online">Bank Transfer</option>
              <option value="Cheque">Cheque / DD</option>
            </select>
          </div>
        </div>
        <div class="mf-row" style="margin-bottom:10px">
          <div class="mf-group" id="cpTxnGroup" style="margin-bottom:0">
            <label class="mf-label">Transaction / UTR ID</label>
            <input type="text" name="cp_transaction_id" id="cpTxnId" class="mf-input" placeholder="TXN or UTR number">
          </div>
          <div class="mf-group" style="margin-bottom:0">
            <label class="mf-label">Payment Date <span class="req">*</span></label>
            <input type="date" name="cp_payment_date" id="cpPayDate" class="mf-input" required>
          </div>
        </div>
        <div class="mf-group" style="margin-bottom:0">
          <label class="mf-label">Receipt No (auto-generated)</label>
          <input type="text" name="cp_receipt_no" id="cpReceiptNo" class="mf-input" readonly style="opacity:.6;cursor:default;font-family:var(--mono)">
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" onclick="closePayModal()" class="mf-btn mf-btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
        <button type="submit" class="mf-btn mf-btn-submit"><i class="fas fa-check"></i> Confirm Payment</button>
      </div>
    </form>
  </div>
</div>
<script>
/* Auto-open cpModal after error */
<?php if(!empty($_GET['cp_error'])): ?>
document.addEventListener('DOMContentLoaded',function(){ /* re-open handled server-side */ });
<?php endif; ?>
/* Make the overlay respect display:none initially */
document.getElementById('cpModal').style.display = '';
</script>

<script>
/* ── Custom phone code dropdown ──────────────────────────────────────────── */
function togglePhoneDd(id){
  var dd = document.getElementById('phoneDd_'+id);
  var isOpen = dd.classList.contains('open');
  // close all dropdowns first
  document.querySelectorAll('.phone-dd').forEach(function(d){ d.classList.remove('open'); });
  if(!isOpen){
    dd.classList.add('open');
    // focus the search input
    var s = dd.querySelector('input');
    if(s) setTimeout(function(){ s.focus(); s.value=''; filterPhoneDd(s,id); },50);
  }
}

function selectPhoneCode(id, code, label){
  document.getElementById('phoneCode_'+id).value = code;
  var btn = document.getElementById('phoneBtn_'+id);
  // update button text, preserve chevron icon
  btn.innerHTML = label + ' <i class="fas fa-chevron-down"></i>';
  // mark active
  document.querySelectorAll('#phoneDd_'+id+' .phone-dd-item').forEach(function(el){
    el.classList.toggle('active', el.getAttribute('onclick').indexOf("'"+code+"'")>-1);
  });
  document.getElementById('phoneDd_'+id).classList.remove('open');
}

function filterPhoneDd(input, id){
  var q = input.value.toLowerCase();
  document.querySelectorAll('#phoneDd_'+id+' .phone-dd-item').forEach(function(el){
    el.style.display = el.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
  });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e){
  if(!e.target.closest('.phone-wrap')){
    document.querySelectorAll('.phone-dd').forEach(function(d){ d.classList.remove('open'); });
  }
});

// Set country code in edit modal when editApp is called
var _origEditApp = typeof editApp === 'function' ? editApp : null;
document.addEventListener('DOMContentLoaded', function(){
  // patch editApp to also set phone codes
  var origEditApp = window.editApp;
  window.editApp = function(id){
    origEditApp(id);
    var a = findApp(id); if(!a) return;
    var cc = a.mobile_country_code || '+91';
    selectPhoneCode('emobile', cc, cc);
    var gcc = a.guardian_mobile_country_code || '+91';
    selectPhoneCode('eguardian', gcc, gcc);
  };
});
</script>

<!-- ══ FORWARD TO PRINCIPAL MODAL ══════════════════════════════════════════ -->
<div id="fwdModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeFwdModal()">
  <div style="background:#fff;border-radius:16px;max-width:440px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18);overflow:hidden;animation:slideUp .25s ease both">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,rgba(124,58,237,.10),rgba(124,58,237,.05));border-bottom:1px solid rgba(124,58,237,.15);padding:18px 20px;display:flex;align-items:center;gap:12px">
      <div style="width:40px;height:40px;border-radius:10px;background:rgba(124,58,237,.12);display:grid;place-items:center;flex-shrink:0">
        <i class="fas fa-paper-plane" style="color:var(--purple);font-size:.9rem"></i>
      </div>
      <div>
        <div style="font-size:.92rem;font-weight:700;color:var(--text)">Forward to Principal</div>
        <div id="fwdSubtitle" style="font-size:.68rem;color:var(--muted);margin-top:2px;font-family:var(--mono)"></div>
      </div>
      <button onclick="closeFwdModal()" style="margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:4px"><i class="fas fa-xmark"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:20px">
      <div style="background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.14);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:.82rem;color:var(--text);line-height:1.6">
        <i class="fas fa-circle-info" style="color:var(--purple);margin-right:6px"></i>
        This will change the application status to <strong>Under Review</strong> and forward it to the Principal for approval. This action is for paid applications only.
      </div>
      <div id="fwdMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:12px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button onclick="closeFwdModal()" style="background:none;border:1px solid var(--border);color:var(--muted);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:var(--font);font-weight:600;transition:all .18s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">
          <i class="fas fa-xmark"></i> Cancel
        </button>
        <button id="fwdConfirmBtn" onclick="confirmForward()" style="background:var(--purple);border:none;color:#fff;padding:8px 20px;border-radius:8px;cursor:pointer;font-size:.8rem;font-family:var(--font);font-weight:700;display:flex;align-items:center;gap:7px;transition:background .18s" onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='var(--purple)'">
          <i class="fas fa-paper-plane"></i> Forward to Principal
        </button>
      </div>
    </div>
  </div>
</div>
<script>
var _fwdAppId = 0;

function forwardToPrincipal(id, appNo, name) {
  _fwdAppId = id;
  document.getElementById('fwdSubtitle').textContent = appNo + ' · ' + name;
  document.getElementById('fwdMsg').style.display = 'none';
  document.getElementById('fwdConfirmBtn').disabled = false;
  document.getElementById('fwdConfirmBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Forward to Principal';
  document.getElementById('fwdModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeFwdModal() {
  document.getElementById('fwdModal').style.display = 'none';
  document.body.style.overflow = '';
}

function confirmForward() {
  var btn = document.getElementById('fwdConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Forwarding…';

  var fd = new FormData();
  fd.append('action', 'forward_to_principal');
  fd.append('fwd_app_id', _fwdAppId);
  fd.append('ajax', '1');

  fetch(window.location.pathname + '?tab=applications', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      var msgEl = document.getElementById('fwdMsg');
      msgEl.style.display = 'block';
      if (data.ok) {
        msgEl.style.background = 'rgba(22,163,74,.09)';
        msgEl.style.border = '1px solid rgba(22,163,74,.22)';
        msgEl.style.color = 'var(--success)';
        msgEl.innerHTML = '<i class="fas fa-circle-check"></i> ' + data.msg;
        btn.style.display = 'none';
        // Update the row in the table: swap Forward button → Forwarded badge, update status pill
        var fwdBtn = document.getElementById('fwd-btn-' + _fwdAppId);
        if (fwdBtn) {
          fwdBtn.outerHTML = '<span style="font-size:.68rem;color:var(--success);font-weight:600;display:inline-flex;align-items:center;gap:3px;padding:5px 8px"><i class="fas fa-check-circle"></i> Forwarded</span>';
        }
        // Update status pill if present
        var row = document.querySelector('tr[data-app-id="' + _fwdAppId + '"]');
        if (row) {
          var pill = row.querySelector('.pill.submitted, .pill.default');
          if (pill) { pill.className = 'pill review'; pill.textContent = 'Under Review'; }
        }
        setTimeout(closeFwdModal, 2000);
      } else {
        msgEl.style.background = 'rgba(220,38,38,.07)';
        msgEl.style.border = '1px solid rgba(220,38,38,.2)';
        msgEl.style.color = 'var(--red)';
        msgEl.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + data.msg;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Forward to Principal';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-paper-plane"></i> Forward to Principal';
    });
}
</script>