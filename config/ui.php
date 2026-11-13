<?php
declare(strict_types=1);

/* HTML helpers shared by every page. All output is escaped at the call site with e(). */

const ROLE_LABEL = ['student' => 'Student', 'cashier' => 'Cashier', 'assistant' => 'Student Assistant', 'dean' => "Dean's Secretary", 'proctor' => 'Exam Proctor'];

function head_html(string $title, string $bodyClass = ''): void {
    $css = 'assets/style.css';
    $v = is_file(__DIR__ . '/../' . $css) ? filemtime(__DIR__ . '/../' . $css) : 1;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' | CCDI Legazpi</title>'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">'
       . '<link rel="stylesheet" href="' . $css . '?v=' . $v . '"></head>'
       . '<body class="' . e($bodyClass) . '"><a class="skip" href="#main">Skip to content</a>';
}

function nav_html(): void {
    $u = me();
    echo '<header class="top"><div class="top-in"><a class="brand" href="dashboard.php"><span class="mark" aria-hidden="true"></span>'
       . '<span><b>CCDI Legazpi</b><small>Student Financial Transactions</small></span></a>';
    if ($u) {
        $links = ['student' => [], 'cashier' => ['assessments.php' => 'Assessments', 'reports.php' => 'Reports'],
                  'assistant' => [], 'dean' => ['reports.php' => 'Reports'], 'proctor' => ['proctor.php' => 'Scanner']][$u['role']] ?? [];
        echo '<nav class="links" aria-label="Main"><a href="dashboard.php">Dashboard</a>';
        foreach ($links as $href => $label) echo '<a href="' . e($href) . '">' . e($label) . '</a>';
        echo '</nav><div class="who"><span><b>' . e($u['name']) . '</b><small>' . e(ROLE_LABEL[$u['role']] ?? $u['role']) . '</small></span>'
           . '<form method="post" action="actions.php"><input type="hidden" name="csrf" value="' . e(csrf()) . '">'
           . '<input type="hidden" name="action" value="logout"><button class="btn ghost sm">Sign out</button></form></div>';
    }
    echo '</div></header>';
}

function foot_html(): void {
    echo '<footer class="foot">CCDI Legazpi Student Financial Transaction System. Capstone prototype.</footer></body></html>';
}

function flash_html(): void {
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $err = $type === 'err';
        echo '<div class="flash ' . ($err ? 'err' : 'ok') . '" role="' . ($err ? 'alert' : 'status') . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

/** Opening tag of a POST form to actions.php with CSRF token and action name. Caller closes it. */
function fo(string $action, string $class = '', bool $upload = false): string {
    return '<form method="post" action="actions.php"' . ($class ? ' class="' . e($class) . '"' : '') . ($upload ? ' enctype="multipart/form-data"' : '') . '>'
         . '<input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="action" value="' . e($action) . '">';
}
function hid(string $name, $value): string {
    return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
}

function pill(string $status): string {
    $map = [
        'Pending' => 'warn', 'Incomplete' => 'warn', 'Not eligible' => 'warn', 'Wrong period' => 'warn',
        'Approved' => 'ok', 'Confirmed' => 'ok', 'Completed' => 'ok', 'Complete' => 'ok', 'Valid' => 'ok', 'Active' => 'ok',
        'Rejected' => 'bad', 'Cancelled' => 'bad', 'No-show' => 'bad', 'Invalid' => 'bad', 'Revoked' => 'bad',
    ];
    return '<span class="pill ' . ($map[$status] ?? 'info') . '">' . e($status) . '</span>';
}

/** One cell of the summary strip. */
function stat_cell(string $label, string $value, string $note = ''): string {
    return '<div class="stat"><span class="stat-l">' . e($label) . '</span><span class="stat-v">' . e($value) . '</span>'
         . ($note !== '' ? '<span class="stat-n">' . e($note) . '</span>' : '') . '</div>';
}
function stats(string ...$cells): string { return '<div class="stats">' . implode('', $cells) . '</div>'; }

/** Cells are trusted HTML (already escaped by the caller). */
function row(array $cells): string { return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>'; }
function table(array $heads, array $rows, string $empty = 'Nothing here yet.'): string {
    if (!$rows) return '<p class="empty">' . e($empty) . '</p>';
    return '<div class="table-wrap"><table><thead><tr><th scope="col">' . implode('</th><th scope="col">', array_map('e', $heads))
         . '</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
}

/** Appointments for one office (or every office when $service is null), with role-appropriate actions. */
function appt_table(?string $service = null): string {
    $sql = 'SELECT a.*, s.name FROM appointments a JOIN students s ON s.id=a.student_id'
         . ($service ? ' WHERE a.service=?' : '') . " ORDER BY (a.status IN ('Pending','Confirmed')) DESC, a.appt_date, a.slot, a.id LIMIT 100";
    $rows = [];
    foreach (all($sql, $service ? [$service] : []) as $a) {
        $btn = '';
        $form = fn(string $st, string $label, string $cls) =>
            fo('set_appt', 'inline') . hid('id', $a['id']) . '<button class="btn sm ' . $cls . '" name="status" value="' . e($st) . '">' . e($label) . '</button></form> ';
        if ($a['status'] === 'Pending') $btn = $form('Confirmed', 'Confirm', 'ok');
        elseif ($a['status'] === 'Confirmed') $btn = $form('Completed', 'Mark completed', 'ok') . $form('No-show', 'No-show', 'no');
        $cols = [e($a['appt_date']) . '<br><small>' . e($a['slot']) . '</small>',
                 e($a['name']) . '<br><small>' . e($a['student_id']) . '</small>',
                 e(PURPOSE[$a['purpose']] ?? $a['purpose']) . ($service ? '' : '<br><small>' . e($a['service']) . '</small>'),
                 pill($a['status']), $btn];
        $rows[] = row($cols);
    }
    return table(['When', 'Student', 'Purpose', 'Status', 'Action'], $rows, 'No appointments have been booked.');
}

/** Decorative volcano used on the sign-in and registration pages. */
function auth_panel(string $headline, string $text): string {
    return '<aside class="auth-side"><div class="auth-copy"><span class="mark big" aria-hidden="true"></span>'
         . '<h1>' . e($headline) . '</h1><p>' . e($text) . '</p></div>'
         . '<svg class="auth-art" viewBox="0 0 480 220" preserveAspectRatio="xMidYMax slice" aria-hidden="true">'
         . '<ellipse cx="240" cy="38" rx="70" ry="14" fill="#fff" opacity=".10"/><ellipse cx="270" cy="22" rx="44" ry="9" fill="#fff" opacity=".07"/>'
         . '<path d="M0 220V176C70 166 128 128 172 86C196 62 216 44 240 36C264 44 284 62 308 86C352 128 410 166 480 176V220Z" fill="#0f3a32"/>'
         . '<path d="M0 220V196C90 188 160 160 208 124C226 110 236 100 240 96C244 100 254 110 272 124C320 160 390 188 480 196V220Z" fill="#0b2d27"/></svg></aside>';
}
