<?php
require __DIR__ . '/config/bootstrap.php';
if (me()) back('dashboard.php');

const MAX_TRIES = 5;
const LOCK_SECONDS = 60;

$err = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf('index.php');
    $username = trim((string)($_POST['username'] ?? ''));
    $wait = ($_SESSION['lock_until'] ?? 0) - time();

    if ($wait > 0) {
        $err = "Too many failed attempts. Try again in $wait seconds.";
    } else {
        $u = one('SELECT * FROM users WHERE username = ?', [$username]);
        // Verify against a dummy hash when the user is unknown so timing does not reveal valid usernames.
        $hash = $u['password_hash'] ?? password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $okPw = password_verify((string)($_POST['password'] ?? ''), $hash) && $u;
        $state = $u['status'] ?? 'approved';

        if ($okPw && $u['role'] === 'student' && $state !== 'approved') {
            // Correct password, but the Dean's Secretary has not approved (or has revoked) this account.
            $err = [
                'pending'  => 'Your account is waiting for approval by the Dean\'s Secretary. You can sign in once it is approved.',
                'declined' => 'Your registration was not approved. Please contact the Dean\'s Secretary.',
                'revoked'  => 'Your account access has been revoked. Please contact the Dean\'s Secretary.',
            ][$state] ?? 'Your account is not active.';
        } elseif ($okPw) {
            session_regenerate_id(true);
            unset($_SESSION['tries'], $_SESSION['lock_until']);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role'], 'sid' => $u['student_id']];
            $_SESSION['last_active'] = time();
            audit('Signed in');
            back('dashboard.php');
        } else {
            $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
            if ($_SESSION['tries'] >= MAX_TRIES) {
                $_SESSION['lock_until'] = time() + LOCK_SECONDS;
                $_SESSION['tries'] = 0;
            }
            $err = 'The username or password is incorrect.';
        }
    }
}

head_html('Sign in', 'auth');
echo '<style>:root{--school-bg:url("assets/school-bg.jpg")}</style>';
echo '<div class="auth">' . auth_panel('Tuition and requests in one place.',
        'Pay your balance and apply for a scholarship or promissory note without lining up.')
   . '<main id="main" class="auth-main"><div class="auth-box"><h2>Sign in</h2>';
flash_html();
if ($err) echo '<div class="flash err" role="alert">' . e($err) . '</div>';
echo '<form method="post" action="index.php" autocomplete="on">' . hid('csrf', csrf())
   . '<label>Username or email<input name="username" value="' . e($username) . '" autocomplete="username" required autofocus></label>'
   . '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
   . '<button class="btn block">Sign in</button></form>'
   . '<p class="alt">New student? <a href="register.php">Create your account</a></p>'
   . '</div></main></div>';
foot_html();