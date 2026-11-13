<?php
require __DIR__ . '/config/bootstrap.php';
if (me()) back('dashboard.php');

$err = [];
$in = ['name' => '', 'username' => '', 'category' => 'ordinary'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf('register.php');
    $in['name'] = preg_replace('/\s+/', ' ', trim((string)($_POST['name'] ?? '')));
    $in['username'] = strtolower(trim((string)($_POST['username'] ?? '')));
    $in['category'] = (string)($_POST['category'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if (mb_strlen($in['name']) < 3 || mb_strlen($in['name']) > 100) $err[] = 'Enter your full name (3 to 100 characters).';
    if (!preg_match('/^[a-z0-9._@-]{4,50}$/', $in['username'])) $err[] = 'Username must be 4 to 50 characters: letters, numbers, and . _ @ - (an email address works).';
    if (!isset(CATEGORIES[$in['category']])) $err[] = 'Choose how you will pay your tuition.';
    if (strlen($pw) < 8) $err[] = 'Password must be at least 8 characters.';
    if ($pw !== $pw2) $err[] = 'The two passwords do not match.';
    if (!$err && val('SELECT COUNT(*) FROM users WHERE username=?', [$in['username']])) $err[] = 'That username is already taken.';

    if (!$err) {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $pdo = db();
        for ($try = 0; $try < 5; $try++) {
            try {
                $pdo->beginTransaction();
                $y = date('Y');
                $last = val('SELECT MAX(id) FROM students WHERE id LIKE ?', [$y . '-%']);
                $sid = sprintf('%s-%04d', $y, $last ? ((int)substr($last, 5)) + 1 : 1);
                q('INSERT INTO students (id, name, category, tuition) VALUES (?,?,?,?)', [$sid, $in['name'], $in['category'], DEFAULT_TUITION]);
                q('INSERT INTO users (username, password_hash, full_name, role, student_id, status) VALUES (?,?,?,?,?,?)',
                  [$in['username'], $hash, $in['name'], 'student', $sid, 'pending']);
                $uid = (int)$pdo->lastInsertId();
                $pdo->commit();
                q('INSERT INTO audit_logs (user_id, actor, action) VALUES (?,?,?)', [$uid, $in['name'] . ' (student)', 'Registered account ' . $sid . ' (awaiting approval)']);
                flash("Account created. Your student number is $sid. The Dean's Secretary must approve it before you can sign in.");
                back('index.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    if (val('SELECT COUNT(*) FROM users WHERE username=?', [$in['username']])) { $err[] = 'That username is already taken.'; break; }
                    continue; // student number collided with a simultaneous registration; try the next one
                }
                error_log('Registration failed: ' . $ex->getMessage());
                $err[] = 'We could not create your account. Please try again.';
                break;
            }
        }
        if (!$err) $err[] = 'We could not create your account. Please try again.';
    }
}

head_html('Create account', 'auth');
echo '<style>:root{--school-bg:url("assets/school-bg.jpg")}</style>';
echo '<div class="auth">' . auth_panel('Create your student account.',
        'Your student number is assigned automatically. Your account is activated once the Dean\'s Secretary approves it. Scholarship and promissory requests are reviewed after you sign in.')
   . '<main id="main" class="auth-main"><div class="auth-box"><h2>Create account</h2>';
foreach ($err as $m) echo '<div class="flash err" role="alert">' . e($m) . '</div>';
echo '<form method="post" action="register.php">' . hid('csrf', csrf())
   . '<label>Full name<input name="name" value="' . e($in['name']) . '" autocomplete="name" required maxlength="100"></label>'
   . '<label>Username or email<input name="username" value="' . e($in['username']) . '" autocomplete="username" required minlength="4" maxlength="50" pattern="[A-Za-z0-9._@\-]+"></label>'
   . '<label>How will you pay tuition?<select name="category">';
foreach (CATEGORIES as $k => $label) echo '<option value="' . e($k) . '"' . ($in['category'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
echo '</select></label>'
   . '<div class="cols"><label>Password<input type="password" name="password" autocomplete="new-password" required minlength="8"></label>'
   . '<label>Confirm password<input type="password" name="password2" autocomplete="new-password" required minlength="8"></label></div>'
   . '<button class="btn block">Create account</button></form>'
   . '<p class="alt">Already registered? <a href="index.php">Sign in</a></p></div></main></div>';
foot_html();