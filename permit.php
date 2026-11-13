<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
$p = one('SELECT pm.*, s.name, e.name AS period_name, e.threshold_pct, a.school_year, a.term, a.course
          FROM permits pm JOIN students s ON s.id=pm.student_id JOIN exam_periods e ON e.id=pm.period_id JOIN assessments a ON a.id=pm.assessment_id
          WHERE pm.id=?', [(int)($_GET['id'] ?? 0)]);
$allowed = $p && ($u['role'] === 'student' ? $p['student_id'] === $u['sid'] : in_array($u['role'], ['cashier', 'dean'], true));
if (!$allowed) { flash('Permit not found.', 'err'); back(); }

head_html('Exam permit');
nav_html();
echo '<main id="main" class="page narrow"><article class="permit">'
   . '<header><p class="inst">Computer Communication Development Institute, Legazpi</p><h2>Examination permit</h2><p class="rno">' . e($p['period_name']) . ' examination</p></header>'
   . '<div class="permit-body"><dl class="kv wide"><dt>Student</dt><dd>' . e($p['name']) . '</dd><dt>Student number</dt><dd>' . e($p['student_id']) . '</dd>'
   . '<dt>Course</dt><dd>' . e($p['course']) . '</dd><dt>Term</dt><dd>' . e($p['school_year']) . ', ' . e($p['term']) . '</dd>'
   . '<dt>Issued</dt><dd>' . e(date('F j, Y', strtotime($p['issued_at']))) . '</dd></dl>'
   . '<div class="qr-box"><div id="qr" aria-label="QR code for this permit"></div><p class="token">' . e(token_fmt($p['token'])) . '</p>'
   . '<small>Show this code to the proctor. If the QR cannot be scanned, the proctor can type the code.</small></div></div>';
if ($p['status'] !== 'Active') echo '<p class="flash err">This permit was revoked.</p>';
echo '<div class="actions"><button class="btn" onclick="window.print()">Print permit</button> <a class="btn ghost" href="dashboard.php">Back to dashboard</a></div></article></main>'
   . '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>'
   . '<script>(function(){var el=document.getElementById("qr");if(window.QRCode){new QRCode(el,{text:' . json_encode($p['token']) . ',width:200,height:200,correctLevel:QRCode.CorrectLevel.M});}else{el.textContent="QR image unavailable offline. Use the code below.";el.className="muted";}})();</script>';
foot_html();
