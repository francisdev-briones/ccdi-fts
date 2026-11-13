<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * CCDI Legazpi - Student Financial Transaction System
 * Shared bootstrap: configuration, database, session, business rules.
 * ----------------------------------------------------------------*/

// ---- Configuration (override with environment variables if you like) ----
define('DB_HOST', getenv('CCDI_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('CCDI_DB_NAME') ?: 'ccdi_fts');
define('DB_USER', getenv('CCDI_DB_USER') ?: 'root');
define('DB_PASS', getenv('CCDI_DB_PASS') ?: '');          // XAMPP default is empty
define('DEBUG', false);                                    // true = show errors on screen (local debugging only)
define('DEFAULT_TUITION', 18000.00);                       // tuition given to newly registered students
define('SESSION_IDLE_SECONDS', 1800);                      // auto sign-out after 30 idle minutes

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---- Appointment catalogue ----
const PURPOSE = [
    'tuition'     => 'Tuition payment',
    'receipt'     => 'Receipt or balance inquiry',
    'scholarship' => 'Scholarship concern',
    'promissory'  => 'Promissory note',
];
const SERVICE = [
    'tuition'     => 'Cashier',
    'receipt'     => 'Cashier',
    'scholarship' => "Dean's Secretary",
    'promissory'  => "Dean's Secretary",
];
const SLOTS = ['8:00 AM - 9:00 AM', '9:00 AM - 10:00 AM', '10:00 AM - 11:00 AM', '1:00 PM - 2:00 PM', '2:00 PM - 3:00 PM', '3:00 PM - 4:00 PM'];
const CAP = 3;                                             // max active bookings per service, date and slot
const PAY_METHODS = ['GCash', 'Maya', 'Bank Deposit'];   // online submissions; over-the-counter stays with the cashier
const TERMS = ['1st Sem', '2nd Sem', 'Summer'];
const MAX_PROOF_BYTES = 3 * 1024 * 1024;
const CATEGORIES = ['ordinary' => 'Regular (full payment)', 'scholar' => 'Scholar', 'promissory' => 'Promissory (partial first)'];

/** Next 10 weekdays, starting tomorrow. */
function appt_dates(): array {
    $out = [];
    $d = new DateTimeImmutable('tomorrow');
    while (count($out) < 10) {
        if ((int)$d->format('N') < 6) $out[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $out;
}

// ---- Security headers and session ----
if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}
if (isset($_SESSION['user'])) {
    if (time() - ($_SESSION['last_active'] ?? time()) > SESSION_IDLE_SECONDS) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash'][] = ['err', 'You were signed out after a period of inactivity. Please sign in again.'];
    } else {
        $_SESSION['last_active'] = time();
    }
}

// ---- Database ----
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        error_log('CCDI DB connection failed: ' . $ex->getMessage());
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><title>Service unavailable</title>'
           . '<body style="font-family:sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem">'
           . '<h1>The system cannot reach its database</h1>'
           . '<p>Check that MySQL is running in XAMPP and that <code>config/bootstrap.php</code> has the right credentials, '
           . 'then reload this page.</p></body>');
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

// ---- Small helpers ----
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function peso($n): string { return "\u{20B1}" . number_format((float)$n, 2); }
function back(string $to = 'dashboard.php'): never { header('Location: ' . $to); exit; }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(string $fallback = 'dashboard.php'): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf(), $sent)) {
        flash('Your form expired. Please try again.', 'err');
        back($fallback);
    }
}
function me(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $u = me();
    if (!$u) back('index.php');
    // A student whose account is revoked (or not yet approved) is signed out immediately.
    if ($u['role'] === 'student' && val('SELECT status FROM users WHERE id=?', [$u['id']]) !== 'approved') {
        session_unset();
        flash('Your account is not active. Please contact the Dean\'s Secretary.', 'err');
        back('index.php');
    }
    return $u;
}

function audit(string $action): void {
    $u = me();
    q('INSERT INTO audit_logs (user_id, actor, action) VALUES (?,?,?)',
      [$u['id'] ?? null, $u ? $u['name'] . ' (' . $u['role'] . ')' : 'Guest', mb_substr($action, 0, 255)]);
}

/** Parse a money amount like "1,500.50". Returns null when invalid or out of range. */
function money($v, bool $allowZero = false): ?float {
    $v = str_replace([',', ' ', "\u{20B1}"], '', (string)$v);
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v)) return null;
    $f = round((float)$v, 2);
    return ($f > 0 || ($allowZero && $f == 0.0)) ? $f : null;
}
function valid_date(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

// ---- Business rules ----
function student(string $id): ?array { return one('SELECT * FROM students WHERE id=?', [$id]); }

/** The student's latest encoded assessment (COR-based). When one exists it is the source of truth. */
function current_assessment(string $sid): ?array {
    return one('SELECT * FROM assessments WHERE student_id=? ORDER BY id DESC LIMIT 1', [$sid]);
}
function sum_status(string $sid, string $status): float {
    $a = current_assessment($sid);
    $sql = "SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id=? AND status=? AND " . ($a ? 'assessment_id=?' : 'assessment_id IS NULL');
    return round((float)val($sql, $a ? [$sid, $status, $a['id']] : [$sid, $status]), 2);
}
function paid_sum(string $sid): float { return sum_status($sid, 'Approved'); }
function pending_sum(string $sid): float { return sum_status($sid, 'Pending'); }
/** Latest request of a type that is still Pending or Approved. */
function active_req(string $sid, string $type): ?array {
    return one("SELECT * FROM requests WHERE student_id=? AND type=? AND status IN ('Pending','Approved') ORDER BY id DESC LIMIT 1", [$sid, $type]);
}
/** Latest request of a type in any status (to show a rejection). */
function latest_req(string $sid, string $type): ?array {
    return one('SELECT * FROM requests WHERE student_id=? AND type=? ORDER BY id DESC LIMIT 1', [$sid, $type]);
}
function req_type(array $s): ?string {
    if (current_assessment($s['id'])) return null;   // an encoded assessment already includes any discount
    return $s['category'] === 'scholar' ? 'scholarship' : ($s['category'] === 'promissory' ? 'promissory' : null);
}
/** Amount the student must pay, or null while it is still undecided. */
function payable(array $s): ?float {
    $a = current_assessment($s['id']);
    if ($a) return round((float)$a['total_payable'], 2);
    $t = (float)$s['tuition'];
    if ($s['category'] === 'ordinary') return round($t, 2);
    $type = req_type($s);
    $r = active_req($s['id'], $type);
    if (!$r || $r['status'] !== 'Approved') return null;
    if ($type === 'scholarship') return round($t * (100 - (int)$r['coverage']) / 100, 2);
    return round($t, 2);
}
/** Balance still open for new payments (paid and pending both deducted). */
function remaining(array $s): ?float {
    $p = payable($s);
    return $p === null ? null : max(round($p - paid_sum($s['id']) - pending_sum($s['id']), 2), 0.0);
}
function can_pay(array $s): bool { $r = remaining($s); return $r !== null && $r > 0.009; }

require __DIR__ . '/ui.php';
require __DIR__ . '/assess.php';