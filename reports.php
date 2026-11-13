<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
if (!in_array($u['role'], ['cashier', 'dean'], true)) { flash('Reports are for the cashier and the Dean\'s Secretary.', 'err'); back(); }

$types = ['collection' => 'Collection', 'pending' => 'Pending payments', 'permits' => 'Exam permits', 'scans' => 'Scan log'];
$type = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'collection';
$from = valid_date((string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = valid_date((string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');

switch ($type) {
    case 'collection':
        $heads = ['Acknowledgment', 'Approved on', 'Student number', 'Student', 'Reference', 'Method', 'Amount'];
        $data = array_map(fn($r) => [$r['receipt_no'], $r['verified_at'], $r['student_id'], $r['name'], $r['ref'], $r['method'], number_format((float)$r['amount'], 2, '.', '')],
            all("SELECT p.*, s.name FROM payments p JOIN students s ON s.id=p.student_id WHERE p.status='Approved' AND DATE(p.verified_at) BETWEEN ? AND ? ORDER BY p.verified_at", [$from, $to]));
        $total = array_sum(array_map(fn($r) => (float)$r[6], $data));
        break;
    case 'pending':
        $heads = ['Submitted', 'Student number', 'Student', 'Reference', 'Method', 'Payment date', 'Amount', 'Flag'];
        $data = array_map(fn($r) => [$r['created_at'], $r['student_id'], $r['name'], $r['ref'], $r['method'], $r['txn_date'], number_format((float)$r['amount'], 2, '.', ''), $r['flagged'] ? 'Reference already recorded' : ''],
            all("SELECT p.*, s.name FROM payments p JOIN students s ON s.id=p.student_id WHERE p.status='Pending' ORDER BY p.created_at"));
        $total = array_sum(array_map(fn($r) => (float)$r[6], $data));
        break;
    case 'permits':
        $heads = ['Issued', 'Student number', 'Student', 'Exam', 'Term', 'Status'];
        $data = array_map(fn($r) => [$r['issued_at'], $r['student_id'], $r['name'], $r['period_name'], $r['school_year'] . ' ' . $r['term'], $r['status']],
            all('SELECT pm.*, s.name, e.name AS period_name, a.school_year, a.term FROM permits pm JOIN students s ON s.id=pm.student_id
                 JOIN exam_periods e ON e.id=pm.period_id JOIN assessments a ON a.id=pm.assessment_id WHERE DATE(pm.issued_at) BETWEEN ? AND ? ORDER BY pm.id', [$from, $to]));
        $total = null;
        break;
    default:
        $heads = ['Time', 'Student', 'Code entered', 'Exam', 'Result', 'Detail', 'Proctor'];
        $data = array_map(fn($r) => [$r['created_at'], $r['name'] ?? 'Unknown', $r['token'], $r['period_name'] ?? '', $r['result'], $r['detail'], $r['proctor'] ?? ''],
            all('SELECT l.*, s.name, e.name AS period_name, u.full_name AS proctor FROM scan_logs l LEFT JOIN permits p ON p.id=l.permit_id LEFT JOIN students s ON s.id=p.student_id
                 LEFT JOIN exam_periods e ON e.id=l.period_id LEFT JOIN users u ON u.id=l.proctor_id WHERE DATE(l.created_at) BETWEEN ? AND ? ORDER BY l.id DESC', [$from, $to]));
        $total = null;
}

if (isset($_GET['csv'])) {
    audit("Exported $type report");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ccdi-' . $type . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $heads);
    foreach ($data as $r) fputcsv($out, array_map('csv_cell', $r));
    exit;
}

head_html('Reports');
nav_html();
echo '<main id="main" class="page"><div class="page-head"><h2>Reports</h2></div>';
flash_html();
echo '<section class="panel"><form method="get" class="filters"><label>Report<select name="type">';
foreach ($types as $k => $v) echo '<option value="' . e($k) . '"' . ($k === $type ? ' selected' : '') . '>' . e($v) . '</option>';
echo '</select></label>';
if ($type !== 'pending') echo '<label>From<input type="date" name="from" value="' . e($from) . '"></label><label>To<input type="date" name="to" value="' . e($to) . '"></label>';
$qs = http_build_query(['type' => $type, 'from' => $from, 'to' => $to, 'csv' => 1]);
echo '<div class="btn-row"><button class="btn">Show report</button><a class="btn ghost" href="reports.php?' . e($qs) . '">Download CSV</a></div></form></section>';
$rows = array_map(fn($r) => row(array_map('e', $r)), $data);
echo '<section class="panel"><h3>' . e($types[$type]) . '</h3>' . ($total !== null ? '<p><b>' . count($data) . '</b> payment(s), total <b>' . e(peso($total)) . '</b></p>' : '<p><b>' . count($data) . '</b> record(s)</p>')
   . table($heads, $rows, 'No records for this period.') . '</section></main>';
foot_html();
