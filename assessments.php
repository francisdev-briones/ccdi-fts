<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
if ($u['role'] !== 'cashier') { flash('Only the cashier can encode assessments.', 'err'); back(); }

head_html('Assessments');
nav_html();
echo '<main id="main" class="page"><div class="page-head"><div><h2>Student assessments</h2>'
   . '<p class="muted">Encode the figures from the student\'s Certificate of Registration. Students see them on their dashboard.</p></div></div>';
flash_html();

$students = all('SELECT id, name FROM students ORDER BY id');
echo '<div class="two"><section class="panel"><h3>Encode one assessment</h3>' . fo('save_assessment')
   . '<label>Student number<input name="student_id" list="stud" required autocomplete="off" placeholder="2026-0001"></label><datalist id="stud">';
foreach ($students as $s) echo '<option value="' . e($s['id']) . '">' . e($s['name']) . '</option>';
echo '</datalist><div class="cols"><label>School year<input name="school_year" value="' . date('Y') . '-' . ((int)date('Y') + 1) . '" pattern="\d{4}-\d{4}" required></label>'
   . '<label>Term<select name="term">';
foreach (TERMS as $t) echo '<option>' . e($t) . '</option>';
echo '</select></label></div><div class="cols"><label>Course and year<input name="course" maxlength="80" required placeholder="BS in Information Technology 3"></label>'
   . '<label>Total units<input type="number" name="units" min="1" max="40" required></label></div>'
   . '<div class="cols"><label>Tuition fee<input name="tuition_fee" inputmode="decimal" required></label><label>Laboratory fee<input name="lab_fee" inputmode="decimal" value="0"></label></div>'
   . '<div class="cols"><label>Miscellaneous and other fees<input name="misc_fee" inputmode="decimal" value="0"></label><label>Insurance, ID and other<input name="other_fee" inputmode="decimal" value="0"></label></div>'
   . '<div class="cols"><label>Discount amount<input name="discount" inputmode="decimal" value="0"></label><label>Discount name<input name="discount_label" maxlength="60" placeholder="CHED Gabay Aral"></label></div>'
   . '<label>Paid upon registration (over the counter)<input name="upon_reg" inputmode="decimal" value="0"></label>'
   . '<button class="btn">Save assessment</button></form><p class="hint">Amount payable is the total fees less the discount. An upon-registration amount is recorded as an approved payment.</p></section>';

echo '<section class="panel"><h3>Import from CSV</h3>' . fo('import_assessments', '', true)
   . '<label>CSV file (up to 500 rows)<input type="file" name="csv" accept=".csv,text/csv" required></label><button class="btn">Import</button></form>'
   . '<p class="hint">First row must be the column names: <b>student_id, school_year, term, course, units, tuition_fee</b>. Optional: lab_fee, misc_fee, other_fee, discount, discount_label, upon_reg. Rows with errors are skipped and reported.</p></section></div>';

$rows = [];
foreach (all('SELECT a.*, s.name FROM assessments a JOIN students s ON s.id=a.student_id ORDER BY a.id DESC LIMIT 40') as $a) {
    $rows[] = row([e($a['name']) . '<br><small>' . e($a['student_id']) . '</small>', e($a['school_year']) . '<br><small>' . e($a['term']) . '</small>',
                   e($a['course']), peso($a['total_payable']) . ($a['discount'] > 0 ? '<br><small>after ' . e($a['discount_label'] ?: 'discount') . '</small>' : '')]);
}
echo '<section class="panel"><h3>Recent assessments</h3>' . table(['Student', 'Term', 'Course', 'Payable'], $rows, 'No assessments encoded yet.') . '</section></main>';
foot_html();
