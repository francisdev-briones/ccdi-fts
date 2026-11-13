<?php
declare(strict_types=1);

/* COR-based assessments, payment proofs, exam permits and permit validation. */

function ack_no(int $paymentId): string {
    return 'CCDI-ER-' . date('Y') . '-' . str_pad((string)$paymentId, 4, '0', STR_PAD_LEFT);
}

/** Encode (or replace, when no payment is linked yet) a student's assessment. Returns an error message or null. */
function create_assessment(array $d, int $uid): ?string {
    $sid = trim((string)($d['student_id'] ?? ''));
    if (!student($sid)) return "Student number '$sid' was not found.";
    $sy = trim((string)($d['school_year'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{4})$/', $sy, $m) || (int)$m[2] !== (int)$m[1] + 1) return 'School year must look like 2026-2027.';
    $term = trim((string)($d['term'] ?? ''));
    if (!in_array($term, TERMS, true)) return 'Term must be 1st Sem, 2nd Sem or Summer.';
    $course = mb_substr(trim((string)($d['course'] ?? '')), 0, 80);
    $units = (int)($d['units'] ?? 0);
    if ($course === '' || $units < 1 || $units > 40) return 'Enter the course and the total units (1 to 40).';

    $labels = ['tuition_fee' => 'tuition', 'lab_fee' => 'laboratory', 'misc_fee' => 'miscellaneous', 'other_fee' => 'insurance and other', 'discount' => 'discount', 'upon_reg' => 'upon-registration'];
    $v = [];
    foreach ($labels as $k => $label) {
        $raw = trim((string)($d[$k] ?? ''));
        $x = money($raw === '' ? '0' : $raw, true);
        if ($x === null) return "Check the $label amount. Use numbers such as 3510.00.";
        $v[$k] = $x;
    }
    $gross = round($v['tuition_fee'] + $v['lab_fee'] + $v['misc_fee'] + $v['other_fee'], 2);
    if ($gross <= 0) return 'Enter at least one fee.';
    if ($v['discount'] > $gross) return 'The discount cannot be more than the total fees.';
    $total = round($gross - $v['discount'], 2);
    if ($v['upon_reg'] > $total + 0.001) return 'The upon-registration payment is more than the amount payable.';
    $dlabel = mb_substr(trim((string)($d['discount_label'] ?? '')), 0, 60);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        one('SELECT id FROM students WHERE id=? FOR UPDATE', [$sid]);
        $old = one('SELECT id FROM assessments WHERE student_id=? AND school_year=? AND term=?', [$sid, $sy, $term]);
        if ($old) {
            if (val('SELECT COUNT(*) FROM payments WHERE assessment_id=?', [$old['id']])) {
                $pdo->rollBack();
                return "$sid already has payments on the $sy $term assessment, so it cannot be replaced.";
            }
            q('DELETE FROM permits WHERE assessment_id=?', [$old['id']]);
            q('DELETE FROM assessments WHERE id=?', [$old['id']]);
        }
        q('INSERT INTO assessments (student_id, school_year, term, course, units, tuition_fee, lab_fee, misc_fee, other_fee, discount, discount_label, total_payable, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$sid, $sy, $term, $course, $units, $v['tuition_fee'], $v['lab_fee'], $v['misc_fee'], $v['other_fee'], $v['discount'], $dlabel, $total, $uid]);
        $aid = (int)$pdo->lastInsertId();
        if ($v['upon_reg'] > 0) {
            q("INSERT INTO payments (ref, student_id, amount, method, status, txn_date, assessment_id, verified_by, verified_at)
               VALUES (?,?,?,?, 'Approved', CURDATE(), ?, ?, NOW())", ["REG-$sid-$aid", $sid, $v['upon_reg'], 'Over-the-Counter', $aid, $uid]);
            $pid = (int)$pdo->lastInsertId();
            q('UPDATE payments SET receipt_no=? WHERE id=?', [ack_no($pid), $pid]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('create_assessment: ' . $ex->getMessage());
        return 'The assessment could not be saved. Nothing was changed.';
    }
    sync_permits($sid);
    audit("Encoded assessment for $sid, $sy $term, payable " . peso($total));
    return null;
}

/** Save an uploaded payment screenshot. Returns [stored file name|null, error|null]. */
function save_proof(?array $f): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, 'Attach a screenshot of your payment receipt.'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [null, 'The upload failed. Try a smaller image.'];
    if ($f['size'] > MAX_PROOF_BYTES) return [null, 'The screenshot must be 3 MB or smaller.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) return [null, 'Upload the screenshot as a JPG, PNG or WebP image.'];
    $dir = __DIR__ . '/../uploads/proofs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return [null, 'The server could not store the image.'];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return [null, 'The server could not store the image.'];
    return [$name, null];
}
function proof_file(string $name): string { return __DIR__ . '/../uploads/proofs/' . basename($name); }

// ---- Exam permits ----
function periods(): array { return all('SELECT * FROM exam_periods ORDER BY sort, id'); }

/** Has the student's approved total reached the period's threshold? */
function eligible(string $sid, int $thresholdPct): bool {
    $a = current_assessment($sid);
    if (!$a) return false;
    $pay = (float)$a['total_payable'];
    return $pay <= 0 || paid_sum($sid) + 0.005 >= $pay * $thresholdPct / 100;
}
function sync_permits(string $sid): void {
    $a = current_assessment($sid);
    if (!$a) return;
    foreach (periods() as $p) {
        if (!eligible($sid, (int)$p['threshold_pct'])) continue;
        q('INSERT IGNORE INTO permits (student_id, assessment_id, period_id, token) VALUES (?,?,?,?)',
          [$sid, $a['id'], $p['id'], strtoupper(bin2hex(random_bytes(6)))]);
    }
}
function sync_all_permits(): void { foreach (all('SELECT DISTINCT student_id FROM assessments') as $r) sync_permits($r['student_id']); }
function token_fmt(string $t): string { return trim(chunk_split($t, 4, '-'), '-'); }

/** Returns [result, detail, permit row|null, normalised token]. */
function validate_permit(string $raw, ?int $periodId): array {
    $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    $p = $token === '' ? null : one('SELECT pm.*, s.name, e.name AS period_name, e.threshold_pct FROM permits pm
        JOIN students s ON s.id=pm.student_id JOIN exam_periods e ON e.id=pm.period_id WHERE pm.token=?', [$token]);
    if (!$p) return ['Invalid', 'No permit matches this code.', null, $token];
    if ($p['status'] !== 'Active') return ['Revoked', 'This permit was revoked.', $p, $token];
    if ($periodId && (int)$p['period_id'] !== $periodId) return ['Wrong period', 'This permit is for ' . $p['period_name'] . ', not the selected exam.', $p, $token];
    $a = current_assessment($p['student_id']);
    if (!$a || (int)$a['id'] !== (int)$p['assessment_id']) return ['Invalid', 'This permit belongs to an earlier term.', $p, $token];
    if (!eligible($p['student_id'], (int)$p['threshold_pct'])) return ['Not eligible', 'Approved payments no longer meet the requirement for this exam.', $p, $token];
    $n = (int)val("SELECT COUNT(*) FROM scan_logs WHERE permit_id=? AND result='Valid'", [$p['id']]);
    return ['Valid', $n ? "Valid. This permit was already accepted $n time(s) before." : 'Valid permit.', $p, $token];
}

/** Spreadsheet-safe CSV cell. */
function csv_cell($v): string {
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}
