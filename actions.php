<?php
// Single POST handler for every form after sign-in. Rules are enforced here, never in the browser.
require __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back();
check_csrf();
$act = (string)($_POST['action'] ?? '');

if ($act === 'logout') {
    if (me()) audit('Signed out');
    session_unset();
    session_destroy();
    session_start();
    back('index.php');
}

$u = require_login();
$r = $u['role'];
$id = (int)($_POST['id'] ?? 0);

function need(string ...$roles): void {
    global $r;
    if (!in_array($r, $roles, true)) { flash('That action is not available for your role.', 'err'); back(); }
}

$pdo = db();
$newProof = null;

try {
    switch ($act) {
        case 'apply_request':
            need('student');
            $pdo->beginTransaction();
            one('SELECT id FROM students WHERE id=? FOR UPDATE', [$u['sid']]);   // serialise this student's actions
            $s = student($u['sid']);
            $type = $s ? req_type($s) : null;
            if (!$type) { flash('No request is needed for your account.', 'err'); break; }
            if (active_req($s['id'], $type)) { flash('You already have an active request.', 'err'); break; }
            if ($type === 'promissory') {
                $amt = money($_POST['amount'] ?? '');
                $why = trim((string)($_POST['reason'] ?? ''));
                if ($amt === null || $why === '') { flash('Enter the amount you can pay first and a reason.', 'err'); break; }
                if ($amt > (float)$s['tuition']) { flash('The amount cannot be more than your tuition of ' . peso($s['tuition']) . '.', 'err'); break; }
                if (mb_strlen($why) > 255) { flash('Please shorten your reason to 255 characters.', 'err'); break; }
                q('INSERT INTO requests (student_id, type, amount, reason) VALUES (?,?,?,?)', [$s['id'], $type, $amt, $why]);
            } else {
                q('INSERT INTO requests (student_id, type) VALUES (?,?)', [$s['id'], $type]);
            }
            $pdo->commit();
            audit("Submitted $type request");
            flash('Request submitted. The Student Assistant will check your documents next.');
            break;

        case 'submit_payment':
            need('student');
            $pdo->beginTransaction();
            one('SELECT id FROM students WHERE id=? FOR UPDATE', [$u['sid']]);
            $s = student($u['sid']);
            $amt = money($_POST['amount'] ?? '');
            $ref = trim((string)($_POST['ref'] ?? ''));
            $method = (string)($_POST['method'] ?? '');
            $tdate = (string)($_POST['txn_date'] ?? '');
            if (!$s || !can_pay($s)) { flash('Payment is not open for your account right now.', 'err'); break; }
            if (!in_array($method, PAY_METHODS, true)) { flash('Choose a payment method from the list.', 'err'); break; }
            if ($amt === null) { flash('Enter a valid amount, for example 1500.00.', 'err'); break; }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.\/ ]{3,39}$/', $ref)) { flash('The reference number must be 4 to 40 letters or numbers.', 'err'); break; }
            if (!valid_date($tdate) || $tdate > date('Y-m-d') || $tdate < date('Y-m-d', strtotime('-90 days'))) { flash('Enter the date you made the payment (within the last 90 days).', 'err'); break; }
            $avail = remaining($s);
            if ($amt > $avail + 0.001) { flash('The amount is more than your open balance of ' . peso($avail) . '.', 'err'); break; }
            [$newProof, $perr] = save_proof($_FILES['proof'] ?? null);
            if ($perr) { flash($perr, 'err'); break; }
            $flag = (int)val("SELECT COUNT(*) FROM payments WHERE ref=? AND status<>'Rejected'", [$ref]) > 0 ? 1 : 0;
            $a = current_assessment($s['id']);
            q('INSERT INTO payments (ref, student_id, amount, method, txn_date, proof_path, flagged, assessment_id) VALUES (?,?,?,?,?,?,?,?)',
              [$ref, $s['id'], $amt, $method, $tdate, $newProof, $flag, $a['id'] ?? null]);
            $pdo->commit();
            $newProof = null;   // saved for good
            audit("Submitted payment $ref (" . peso($amt) . ')');
            flash($flag ? 'Payment submitted. That reference number was already recorded, so staff will check it closely.'
                        : 'Payment submitted and pending staff review.');
            break;

        case 'book_appt':
            need('student');
            $purpose = (string)($_POST['purpose'] ?? '');
            $date = (string)($_POST['date'] ?? '');
            $slot = (string)($_POST['slot'] ?? '');
            if (!isset(PURPOSE[$purpose]) || !in_array($date, appt_dates(), true) || !in_array($slot, SLOTS, true)) {
                flash('Choose a purpose, an available date and a time slot.', 'err'); break;
            }
            $svc = SERVICE[$purpose];
            $lock = 'ccdi:' . md5("$svc|$date|$slot");
            if (!(int)val('SELECT GET_LOCK(?, 5)', [$lock])) { flash('The system is busy. Please try again.', 'err'); break; }
            try {
                if (val("SELECT COUNT(*) FROM appointments WHERE student_id=? AND appt_date=? AND status IN ('Pending','Confirmed')", [$u['sid'], $date])) {
                    flash('You already have an active appointment on that date.', 'err'); break;
                }
                if ((int)val("SELECT COUNT(*) FROM appointments WHERE service=? AND appt_date=? AND slot=? AND status IN ('Pending','Confirmed')", [$svc, $date, $slot]) >= CAP) {
                    flash("That $svc slot is full. Please choose another time.", 'err'); break;
                }
                q('INSERT INTO appointments (student_id, service, purpose, appt_date, slot) VALUES (?,?,?,?,?)', [$u['sid'], $svc, $purpose, $date, $slot]);
            } finally {
                q('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            audit('Booked ' . PURPOSE[$purpose] . " on $date, $slot");
            flash('Appointment requested. Staff will confirm it.');
            break;

        case 'cancel_appt':
            need('student');
            $n = q("UPDATE appointments SET status='Cancelled' WHERE id=? AND student_id=? AND status IN ('Pending','Confirmed')", [$id, $u['sid']])->rowCount();
            if (!$n) { flash('That appointment cannot be cancelled.', 'err'); break; }
            audit("Cancelled appointment #$id");
            flash('Appointment cancelled.');
            break;

        case 'set_appt':
            need('cashier', 'assistant', 'dean');
            $a = one('SELECT * FROM appointments WHERE id=?', [$id]);
            $st = (string)($_POST['status'] ?? '');
            if (!$a) { flash('Appointment not found.', 'err'); break; }
            $office = $r === 'cashier' ? 'Cashier' : "Dean's Secretary";
            if ($r !== 'assistant' && $a['service'] !== $office) { flash('This appointment belongs to another office.', 'err'); break; }
            $ok = ($st === 'Confirmed' && $a['status'] === 'Pending')
               || (in_array($st, ['Completed', 'No-show'], true) && $a['status'] === 'Confirmed');
            if (!$ok) { flash('That status change is not allowed.', 'err'); break; }
            $n = q('UPDATE appointments SET status=? WHERE id=? AND status=?', [$st, $id, $a['status']])->rowCount();
            if (!$n) { flash('Someone else already updated this appointment.', 'err'); break; }
            audit("Set appointment #$id to $st");
            flash("Appointment marked $st.");
            break;

        case 'mark_docs':
            need('assistant');
            $n = q("UPDATE requests SET docs=1 WHERE id=? AND status='Pending' AND docs=0", [$id])->rowCount();
            if (!$n) { flash('That request is not waiting for a document check.', 'err'); break; }
            audit("Documents checked for request #$id");
            flash('Documents marked complete.');
            break;

        case 'decide':
            need('dean');
            $pdo->beginTransaction();
            $rq = one('SELECT * FROM requests WHERE id=? FOR UPDATE', [$id]);
            if (!$rq || $rq['status'] !== 'Pending') { flash('That request is no longer pending.', 'err'); break; }
            if (($_POST['decision'] ?? '') === 'reject') {
                q("UPDATE requests SET status='Rejected', decided_at=NOW() WHERE id=?", [$id]);
                $pdo->commit();
                audit("Rejected {$rq['type']} request #$id");
                flash('Request rejected. The student may apply again.');
                break;
            }
            if (!$rq['docs']) { flash('The Student Assistant must mark the documents complete first.', 'err'); break; }
            if ($rq['type'] === 'scholarship') {
                $c = filter_var($_POST['coverage'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                if ($c === false) { flash('Coverage must be a whole number from 1 to 100.', 'err'); break; }
                q("UPDATE requests SET status='Approved', coverage=?, decided_at=NOW() WHERE id=?", [$c, $id]);
            } else {
                $d = (string)($_POST['deadline'] ?? '');
                if (!valid_date($d) || $d < date('Y-m-d')) { flash('Set a payment deadline that is today or later.', 'err'); break; }
                q("UPDATE requests SET status='Approved', deadline=?, decided_at=NOW() WHERE id=?", [$d, $id]);
            }
            $pdo->commit();
            audit("Approved {$rq['type']} request #$id");
            flash('Request approved.');
            break;

        case 'verify_payment':
            need('cashier');
            $pdo->beginTransaction();
            $p = one('SELECT * FROM payments WHERE id=? FOR UPDATE', [$id]);
            if (!$p || $p['status'] !== 'Pending') { flash('That payment is not waiting for verification.', 'err'); break; }
            one('SELECT id FROM students WHERE id=? FOR UPDATE', [$p['student_id']]);
            $s = student($p['student_id']);
            if ($p['flagged'] && val("SELECT COUNT(*) FROM payments WHERE ref=? AND status='Approved' AND id<>?", [$p['ref'], $id])) {
                flash('This reference number was already approved on another payment. Reject it.', 'err'); break;
            }
            $pay = $s ? payable($s) : null;
            if ($pay === null || round(paid_sum($s['id']) + (float)$p['amount'], 2) > $pay + 0.001) {
                flash('This payment would exceed the payable balance. Reject it instead.', 'err'); break;
            }
            $no = ack_no((int)$p['id']);
            q("UPDATE payments SET status='Approved', receipt_no=?, verified_at=NOW(), verified_by=? WHERE id=?", [$no, $u['id'], $id]);
            $pdo->commit();
            sync_permits($s['id']);
            audit("Approved {$p['ref']}, acknowledgment $no");
            flash("Payment approved. Acknowledgment $no issued.");
            break;

        case 'reject_payment':
            need('cashier');
            $why = trim((string)($_POST['reason'] ?? ''));
            if ($why === '' || mb_strlen($why) > 255) { flash('Enter a reason for the rejection (up to 255 characters).', 'err'); break; }
            $n = q("UPDATE payments SET status='Rejected', reject_reason=?, verified_at=NOW(), verified_by=? WHERE id=? AND status='Pending'", [$why, $u['id'], $id])->rowCount();
            if (!$n) { flash('That payment is not waiting for review.', 'err'); break; }
            audit("Rejected payment #$id: $why");
            flash('Payment rejected. The student can see your reason.');
            break;

        case 'save_assessment':
            need('cashier');
            $e = create_assessment($_POST, (int)$u['id']);
            if ($e) { flash($e, 'err'); back('assessments.php'); }
            flash('Assessment saved for ' . trim((string)$_POST['student_id']) . '.');
            back('assessments.php');

        case 'import_assessments':
            need('cashier');
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) { flash('Choose a CSV file up to 1 MB.', 'err'); back('assessments.php'); }
            $h = fopen($f['tmp_name'], 'r');
            $head = $h ? fgetcsv($h) : false;
            if (!$head) { flash('The CSV file is empty.', 'err'); back('assessments.php'); }
            $head = array_map(fn($x) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$x))), $head);
            $need = ['student_id', 'school_year', 'term', 'course', 'units', 'tuition_fee'];
            if (array_diff($need, $head)) { flash('The CSV needs these columns: ' . implode(', ', $need) . '.', 'err'); back('assessments.php'); }
            $ok = 0; $bad = []; $line = 1;
            while (($cells = fgetcsv($h)) !== false && $line < 501) {
                $line++;
                if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
                $d = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
                $err = create_assessment($d, (int)$u['id']);
                if ($err) $bad[] = "Row $line: $err"; else $ok++;
            }
            fclose($h);
            flash("Imported $ok assessment(s)." . ($bad ? ' ' . count($bad) . ' row(s) skipped. ' . implode(' ', array_slice($bad, 0, 3)) : ''), $bad ? 'err' : 'ok');
            back('assessments.php');

        case 'set_threshold':
            need('dean');
            $pct = filter_var($_POST['pct'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($pct === false || !val('SELECT COUNT(*) FROM exam_periods WHERE id=?', [$id])) { flash('Enter a whole percentage from 1 to 100.', 'err'); break; }
            q('UPDATE exam_periods SET threshold_pct=? WHERE id=?', [$pct, $id]);
            sync_all_permits();
            audit("Set exam period #$id requirement to $pct%");
            flash('Permit requirement updated. Eligible students now have their permits.');
            break;

        case 'approve_student':
        case 'revoke_student':
        case 'decline_student':
            need('dean');
            $t = one("SELECT id, full_name, student_id FROM users WHERE id=? AND role='student'", [$id]);
            if (!$t) { flash('Student account not found.', 'err'); break; }
            if ($act === 'approve_student') { $to = 'approved'; $from = "'pending','revoked','declined'"; $msg = 'approved. They can now sign in.'; }
            elseif ($act === 'revoke_student') { $to = 'revoked'; $from = "'approved'"; $msg = 'revoked. They can no longer sign in.'; }
            else { $to = 'declined'; $from = "'pending'"; $msg = 'declined.'; }
            $n = q("UPDATE users SET status=?, decided_at=NOW(), decided_by=? WHERE id=? AND status IN ($from)", [$to, $u['id'], $id])->rowCount();
            if (!$n) { flash('That account is already in that state or cannot be changed this way.', 'err'); break; }
            audit(ucfirst($to) . " student account {$t['student_id']} ({$t['full_name']})");
            flash($t['full_name'] . ' ' . $msg);
            break;

        default:
            flash('Unknown action.', 'err');
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('actions.php [' . $act . ']: ' . $ex->getMessage());
    $dup = $ex instanceof PDOException && (($ex->errorInfo[1] ?? 0) === 1062);
    flash($dup ? 'That reference number was already submitted.' : 'Something went wrong and nothing was saved. Please try again.', 'err');
}

// A saved proof image whose payment row was never committed is removed.
if (!empty($newProof)) @unlink(proof_file($newProof));
// Any path that "break"s out of the switch with a transaction still open (validation failure) ends here.
if ($pdo->inTransaction()) $pdo->rollBack();
back();