<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
if ($u['role'] === 'proctor') back('proctor.php');

head_html('Dashboard');
nav_html();
echo '<main id="main" class="page">';
flash_html();

switch ($u['role']) {
    case 'student':   student_view($u['sid']); break;
    case 'cashier':   cashier_view(); break;
    case 'assistant': assistant_view(); break;
    default:          dean_view();
}
if ($u['role'] === 'dean') audit_view($u);   // students and staff no longer see the activity card
echo '</main>';
foot_html();

/* ------------------------------------------------------------------ */

function student_view(string $sid): void {
    $s = student($sid);
    if (!$s) { echo '<div class="flash err">Your student record could not be found. Please contact the registrar.</div>'; return; }
    $type = req_type($s);
    $pay = payable($s);
    $paid = paid_sum($sid);
    $pend = pending_sum($sid);
    $rem = remaining($s);
    $req = $type ? latest_req($sid, $type) : null;
    $asm = current_assessment($sid);

    echo '<div class="page-head"><div><h2>Welcome, ' . e($s['name']) . '</h2><p class="muted">Student number ' . e($s['id']) . '. '
       . e(CATEGORIES[$s['category']] ?? $s['category']) . '.</p></div></div>';

    echo stats(
        stat_cell('Tuition', peso($s['tuition'])),
        stat_cell('Payable', $pay === null ? 'Pending review' : peso($pay), $pay === null ? 'Set after approval' : ''),
        stat_cell('Verified paid', peso($paid), $pend > 0 ? peso($pend) . ' awaiting verification' : ''),
        stat_cell('Remaining', $rem === null ? 'Not set' : peso($rem))
    );
    if ($pay !== null && $pay > 0) {
        $pct = (int)min(100, round($paid / $pay * 100));
        echo '<div class="meter" role="img" aria-label="' . $pct . ' percent paid"><span style="width:' . $pct . '%"></span></div>';
    }
    echo '<p class="next">' . e(next_step($s, $req, $rem, $pay, $pend)) . '</p>';

    // Request and payment
    echo '<div class="two even"><section class="panel"><h3>' . ($asm ? 'Assessment, ' . e($asm['school_year']) . ' ' . e($asm['term']) : ($type === 'scholarship' ? 'Scholarship application' : ($type ? 'Promissory request' : 'Payment plan'))) . '</h3>';
    if ($asm) {
        assessment_panel($asm);
    } elseif (!$type) {
        echo '<p class="muted">Regular students pay the full balance. No request is needed.</p>';
    } elseif ($req && in_array($req['status'], ['Pending', 'Approved'], true)) {
        echo '<p>Status: ' . pill($req['status']) . '</p><dl class="kv">';
        if ($req['status'] === 'Pending') echo '<dt>Documents</dt><dd>' . ($req['docs'] ? 'Checked, awaiting the Dean\'s Secretary' : 'Awaiting check by the Student Assistant') . '</dd>';
        if ($type === 'promissory') {
            echo '<dt>Amount to pay first</dt><dd>' . peso($req['amount']) . '</dd>';
            if ($req['reason']) echo '<dt>Reason</dt><dd>' . e($req['reason']) . '</dd>';
            if ($req['status'] === 'Approved') {
                $late = $req['deadline'] < date('Y-m-d') && $rem > 0;
                echo '<dt>Pay by</dt><dd>' . e(date('F j, Y', strtotime($req['deadline']))) . ($late ? ' <span class="pill bad">Overdue</span>' : '') . '</dd>';
            }
        } elseif ($req['status'] === 'Approved') {
            echo '<dt>Coverage</dt><dd>' . (int)$req['coverage'] . '% of tuition</dd>';
        }
        echo '</dl>';
    } else {
        if ($req && $req['status'] === 'Rejected') echo '<div class="flash err">Your last request was rejected. You may submit a new one.</div>';
        echo fo('apply_request');
        if ($type === 'promissory') {
            echo '<label>Amount you can pay first (PHP)<input type="number" name="amount" min="1" max="' . e($s['tuition']) . '" step="0.01" inputmode="decimal" required></label>'
               . '<label>Reason<input name="reason" maxlength="255" required></label>';
        } else {
            echo '<p class="muted">Your payable balance is calculated after the Dean\'s Secretary reviews your application.</p>';
        }
        echo '<button class="btn">' . ($type === 'scholarship' ? 'Submit application' : 'Submit request') . '</button></form>';
    }
    echo '</section><section class="panel"><h3>Pay tuition</h3>';
    if (can_pay($s)) {
        echo '<p class="hint">Pay by GCash, Maya or bank deposit first, then submit the details here. Over-the-counter payments are recorded by the cashier.</p>'
           . fo('submit_payment', '', true) . '<div class="cols"><label>Amount (PHP)<input type="number" name="amount" min="1" max="' . e($rem) . '" step="0.01" inputmode="decimal" required></label>'
           . '<label>Payment date<input type="date" name="txn_date" max="' . date('Y-m-d') . '" value="' . date('Y-m-d') . '" required></label></div>'
           . '<div class="cols"><label>Method<select name="method">';
        foreach (PAY_METHODS as $m) echo '<option>' . e($m) . '</option>';
        echo '</select></label><label>Reference number<input name="ref" minlength="4" maxlength="40" required></label></div>'
           . '<label>Receipt screenshot (JPG, PNG or WebP, up to 3 MB)<input type="file" name="proof" accept="image/jpeg,image/png,image/webp" required></label>'
           . '<button class="btn">Submit payment</button></form>'
           . '<p class="hint">You can pay up to ' . e(peso($rem)) . '. Staff approve it after checking the transaction record.</p>';
    } elseif ($pay === null) {
        echo '<p class="muted">Payment opens after your ' . ($type === 'scholarship' ? 'scholarship' : 'promissory request') . ' is approved.</p>';
    } elseif ($pay <= 0.009) {
        echo '<p class="muted">Your tuition is fully covered. There is nothing to pay.</p>';
    } elseif ($pend > 0) {
        echo '<p class="muted">Your submitted payments cover the open balance. They are waiting for staff review.</p>';
    } else {
        echo '<p class="muted">Your tuition is fully paid. Thank you.</p>';
    }
    echo '</section></div>';

    // Payment history
    $rows = [];
    foreach (all('SELECT * FROM payments WHERE student_id=? ORDER BY id DESC LIMIT 30', [$sid]) as $p) {
        $note = $p['status'] === 'Rejected' && $p['reject_reason'] ? '<br><small>Reason: ' . e($p['reject_reason']) . '</small>' : '';
        $note .= $p['flagged'] && $p['status'] === 'Pending' ? '<br><small>Reference already recorded; under review</small>' : '';
        $rec = $p['receipt_no'] ? '<a href="receipt.php?id=' . (int)$p['id'] . '">' . e($p['receipt_no']) . '</a>' : 'None';
        $rows[] = row([e($p['ref']) . '<br><small>' . e($p['txn_date'] ?: '') . '</small>', peso($p['amount']), e($p['method']), pill($p['status']) . $note, $rec]);
    }
    echo '<section class="panel"><h3>Payments and acknowledgments</h3>' . table(['Reference', 'Amount', 'Method', 'Status', 'Acknowledgment'], $rows, 'No payments submitted yet.') . '</section>';

    if ($asm) permits_panel($s, $asm);
}

function next_step(array $s, ?array $req, ?float $rem, ?float $pay, float $pend): string {
    $type = req_type($s);
    if ($type && (!$req || $req['status'] === 'Rejected')) return $type === 'scholarship' ? 'Next step: submit your scholarship application.' : 'Next step: submit your promissory request.';
    if ($type && $req && $req['status'] === 'Pending') return 'Your request is being reviewed. Payment opens once it is approved.';
    if ($rem !== null && $rem > 0.009) return 'Next step: pay your remaining balance of ' . peso($rem) . '.';
    if ($pend > 0) return 'Your payments are waiting for cashier verification.';
    if ($pay !== null && $pay <= 0.009) return 'Your tuition is fully covered.';
    return 'Your tuition is fully paid.';
}

function cashier_view(): void {
    $queue = (int)val("SELECT COUNT(*) FROM payments WHERE status='Pending'");
    $today = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Approved' AND DATE(verified_at)=CURDATE()");
    $total = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Approved'");
    echo '<div class="page-head"><h2>Cashier</h2></div>' . stats(stat_cell('Pending review', (string)$queue), stat_cell('Collected today', peso($today)), stat_cell('Total collected', peso($total)));

    $rows = [];
    foreach (all("SELECT p.*, s.name FROM payments p JOIN students s ON s.id=p.student_id WHERE p.status='Pending' ORDER BY p.id") as $p) {
        $flag = $p['flagged'] ? '<br><span class="pill bad">Reference already recorded</span>' : '';
        $proof = $p['proof_path'] ? '<a href="proof.php?id=' . (int)$p['id'] . '" target="_blank" rel="noopener">View screenshot</a>' : 'None';
        $rows[] = row([e($p['ref']) . $flag, e($p['name']) . '<br><small>' . e($p['student_id']) . '</small>', peso($p['amount']),
            e($p['method']) . '<br><small>' . e($p['txn_date'] ?: '') . '</small>', $proof,
            fo('verify_payment', 'inline') . hid('id', $p['id']) . '<button class="btn sm ok">Approve</button></form>'
          . fo('reject_payment', 'stack') . hid('id', $p['id']) . '<input name="reason" placeholder="Reason for rejection" maxlength="255" required aria-label="Reason for rejection"><button class="btn sm no">Reject</button></form>']);
    }
    echo '<section class="panel"><h3>Pending payments</h3>' . table(['Reference', 'Student', 'Amount', 'Method and date', 'Proof', 'Decision'], $rows, 'No payments are waiting.') . '</section>';

    $rows = [];
    foreach (all('SELECT p.*, s.name FROM payments p JOIN students s ON s.id=p.student_id WHERE p.receipt_no IS NOT NULL ORDER BY p.id DESC LIMIT 50') as $p) {
        $rows[] = row(['<a href="receipt.php?id=' . (int)$p['id'] . '">' . e($p['receipt_no']) . '</a>', e($p['name']), peso($p['amount']), e($p['ref'])]);
    }
    echo '<div class="two"><section class="panel"><h3>Cashier appointments</h3>' . appt_table('Cashier') . '</section>'
       . '<section class="panel"><h3>Approved payments (latest 50)</h3>' . table(['Acknowledgment', 'Student', 'Amount', 'Reference'], $rows, 'No payments approved yet.') . '</section></div>';
}

function pending_requests(): array {
    return all("SELECT r.*, s.name FROM requests r JOIN students s ON s.id=r.student_id WHERE r.status='Pending' ORDER BY r.id");
}

function assistant_view(): void {
    $rows = [];
    $waiting = 0;
    foreach (pending_requests() as $r) {
        if (!$r['docs']) $waiting++;
        $action = $r['docs'] ? pill('Complete')
            : fo('mark_docs', 'inline') . hid('id', $r['id']) . '<button class="btn sm">Mark documents complete</button></form>';
        $rows[] = row([e($r['name']), $r['type'] === 'scholarship' ? 'Scholarship' : 'Promissory', $action]);
    }
    echo '<div class="page-head"><h2>Student Assistant</h2></div>' . stats(stat_cell('Awaiting document check', (string)$waiting), stat_cell('Pending requests', (string)count($rows)))
       . '<section class="panel"><h3>Requests needing document checks</h3>'
       . '<p class="hint">This role checks completeness only. It cannot verify payments or approve requests.</p>'
       . table(['Student', 'Request', 'Documents'], $rows, 'No pending requests.') . '</section>'
       . '<section class="panel"><h3>Monitor appointments</h3>' . appt_table() . '</section>';
}

function dean_view(): void {
    $rows = [];
    $ready = 0;
    foreach (pending_requests() as $r) {
        if ($r['docs']) $ready++;
        $input = $r['type'] === 'scholarship'
            ? '<label class="inl">Coverage (%) <input type="number" name="coverage" min="1" max="100" value="50"></label>'
            : '<label class="inl">Pay by <input type="date" name="deadline" min="' . date('Y-m-d') . '"></label>';
        $dis = $r['docs'] ? '' : ' disabled title="Documents are not complete yet"';
        $detail = $r['type'] === 'promissory' ? '<br><small>' . peso($r['amount']) . ' first. ' . e($r['reason'] ?: '') . '</small>' : '';
        $decision = fo('decide', 'inline stack') . hid('id', $r['id']) . $input
            . '<span class="btns"><button name="decision" value="approve" class="btn sm ok"' . $dis . '>Approve</button>'
            . '<button name="decision" value="reject" class="btn sm no">Reject</button></span></form>';
        $rows[] = row([e($r['name']) . $detail, $r['type'] === 'scholarship' ? 'Scholarship' : 'Promissory',
                       $r['docs'] ? pill('Complete') : pill('Incomplete'), $decision]);
    }
    $accts = student_accounts();
    $awaiting = count(array_filter($accts, fn($a) => $a['status'] === 'pending'));
    echo '<div class="page-head"><h2>Dean\'s Secretary</h2></div>'
       . stats(stat_cell('Accounts awaiting approval', (string)$awaiting), stat_cell('Requests ready to decide', (string)$ready), stat_cell('Waiting on documents', (string)(count($rows) - $ready)))
       . accounts_panel($accts)
       . '<section class="panel"><h3>Pending scholarship and promissory requests</h3>'
       . table(['Student', 'Request', 'Documents', 'Decision'], $rows, 'No pending requests.') . '</section>'
       . '<section class="panel"><h3>Exam permit requirements</h3><p class="hint">Share of the amount payable that approved payments must reach before a student receives the permit.</p>' . thresholds_table() . '</section>'
       . '<section class="panel"><h3>Dean\'s Secretary appointments</h3>' . appt_table("Dean's Secretary") . '</section>';
}

/** Every student login account, pending ones first. */
function student_accounts(): array {
    return all("SELECT u.id, u.username, u.full_name, u.student_id, u.status, u.created_at, s.category
                FROM users u LEFT JOIN students s ON s.id = u.student_id
                WHERE u.role = 'student'
                ORDER BY FIELD(u.status, 'pending', 'revoked', 'declined', 'approved'), u.id DESC LIMIT 200");
}

function accounts_panel(array $accts): string {
    $label = ['pending' => ['warn', 'Pending'], 'approved' => ['ok', 'Approved'], 'revoked' => ['bad', 'Revoked'], 'declined' => ['bad', 'Declined']];
    $btn = fn(string $act, string $text, string $cls, int $id) => fo($act, 'inline') . hid('id', $id) . '<button class="btn sm ' . $cls . '">' . $text . '</button></form>';
    $rows = [];
    foreach ($accts as $a) {
        [$cls, $txt] = $label[$a['status']] ?? ['info', ucfirst($a['status'])];
        $actions = match ($a['status']) {
            'pending'  => $btn('approve_student', 'Approve', 'ok', (int)$a['id']) . $btn('decline_student', 'Decline', 'no', (int)$a['id']),
            'approved' => $btn('revoke_student', 'Revoke access', 'no', (int)$a['id']),
            default    => $btn('approve_student', 'Approve', 'ok', (int)$a['id']),
        };
        $rows[] = row([
            e($a['full_name']) . '<br><small>' . e($a['student_id']) . '</small>',
            e($a['username']),
            e(CATEGORIES[$a['category']] ?? '') ,
            e(date('M j, Y g:i A', strtotime($a['created_at']))),
            '<span class="pill ' . $cls . '">' . e($txt) . '</span>',
            $actions,
        ]);
    }
    return '<section class="panel"><h3>Student account approvals</h3>'
         . '<p class="hint">New registrations cannot sign in until you approve them. Revoking an approved account signs the student out right away.</p>'
         . table(['Student', 'Username', 'Pays tuition as', 'Registered', 'Status', 'Action'], $rows, 'No student accounts yet.') . '</section>';
}

/** The Dean's Secretary sees the full system log; everyone else sees only their own activity. */
function audit_view(array $u): void {
    $full = $u['role'] === 'dean';
    $rows = [];
    $list = $full ? all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 50')
                  : all('SELECT * FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT 15', [$u['id']]);
    foreach ($list as $a) $rows[] = row([e($a['created_at']), e($a['actor']), e($a['action'])]);
    echo '<section class="panel"><h3>' . ($full ? 'Audit log (latest 50)' : 'My recent activity') . '</h3>' . table(['Time', 'Actor', 'Action'], $rows, 'No activity yet.') . '</section>';
}

function assessment_panel(array $a): void {
    $line = fn(string $l, $v) => '<tr><td>' . e($l) . '</td><td class="num">' . peso($v) . '</td></tr>';
    echo '<p class="muted">' . e($a['course']) . ', ' . (int)$a['units'] . ' units</p><div class="table-wrap"><table class="fees"><tbody>'
       . $line('Tuition', $a['tuition_fee']) . $line('Laboratory', $a['lab_fee']) . $line('Miscellaneous', $a['misc_fee']) . $line('Insurance, ID and other', $a['other_fee'])
       . '<tr class="sub"><td>Total fees</td><td class="num">' . peso($a['tuition_fee'] + $a['lab_fee'] + $a['misc_fee'] + $a['other_fee']) . '</td></tr>'
       . ($a['discount'] > 0 ? '<tr><td>Less: ' . e($a['discount_label'] ?: 'Discount') . '</td><td class="num">-' . peso($a['discount']) . '</td></tr>' : '')
       . '<tr class="sub"><td>Amount payable</td><td class="num">' . peso($a['total_payable']) . '</td></tr></tbody></table></div>';
}

function permits_panel(array $s, array $a): void {
    $paid = paid_sum($s['id']);
    $permits = [];
    foreach (all('SELECT * FROM permits WHERE student_id=? AND assessment_id=?', [$s['id'], $a['id']]) as $p) $permits[$p['period_id']] = $p;
    $rows = [];
    foreach (periods() as $p) {
        $need = round((float)$a['total_payable'] * $p['threshold_pct'] / 100, 2);
        if (isset($permits[$p['id']])) {
            $rows[] = row([e($p['name']), e($p['threshold_pct']) . '% (' . peso($need) . ')', pill('Approved') . ' Permit ready', '<a class="btn sm" href="permit.php?id=' . (int)$permits[$p['id']]['id'] . '">Open permit</a>']);
        } else {
            $rows[] = row([e($p['name']), e($p['threshold_pct']) . '% (' . peso($need) . ')', '<span class="muted">Pay ' . e(peso(max($need - $paid, 0))) . ' more</span>', '']);
        }
    }
    echo '<section class="panel"><h3>Exam permits</h3><p class="hint">A permit with a QR code is issued automatically when your approved payments reach the requirement for that exam.</p>'
       . table(['Exam', 'Required paid', 'Status', ''], $rows) . '</section>';
}

function thresholds_table(): string {
    $rows = [];
    foreach (periods() as $p) {
        $rows[] = row([e($p['name']), fo('set_threshold', 'inline') . hid('id', $p['id'])
            . '<label class="inl"><input type="number" name="pct" min="1" max="100" value="' . (int)$p['threshold_pct'] . '" aria-label="Percent for ' . e($p['name']) . '">%</label>'
            . '<button class="btn sm">Save</button></form>']);
    }
    return table(['Exam', 'Required paid'], $rows);
}