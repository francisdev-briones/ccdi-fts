<?php
// One-time setup. Creates the demo and staff accounts. Only works from the same computer. Delete after use.
require __DIR__ . '/config/bootstrap.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Setup can only be run from the computer hosting the system. Delete setup.php from the server.');
}

const DEMO_PASSWORD = 'password123';
$users = [
    ['proctor',   'Exam Proctor',      'proctor',   null],
    ['cashier',   'Cashier Office',    'cashier',   null],
    ['assistant', 'Student Assistant', 'assistant', null],
    ['dean',      "Dean's Secretary",  'dean',      null],
];

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf('setup.php');
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'create' || $do === 'reset') {
            $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
            $sql = 'INSERT INTO users (username, password_hash, full_name, role, student_id) VALUES (?,?,?,?,?)'
                 . ($do === 'reset' ? ' ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)' : ' ON DUPLICATE KEY UPDATE username = username');
            foreach ($users as [$un, $name, $role, $sid]) q($sql, [$un, $hash, $name, $role, $sid]);
            $msg = $do === 'reset' ? 'Demo and staff passwords were reset to ' . DEMO_PASSWORD . '.' : 'Accounts are ready. Existing accounts were left unchanged.';
        } elseif ($do === 'delete') {
            if (@unlink(__FILE__)) { flash('setup.php was deleted.'); back('index.php'); }
            $err = 'Could not delete the file automatically. Please delete setup.php by hand.';
        }
    } catch (PDOException $ex) {
        error_log('setup.php: ' . $ex->getMessage());
        $err = 'Setup failed. Make sure you imported database/schema.sql in phpMyAdmin first.';
    }
}

head_html('Setup', 'auth');
echo '<div class="auth-wrap">' . auth_panel('System setup.', 'Create the starter accounts once, then remove this page.')
   . '<main id="main" class="auth-main"><div class="auth-box"><h2>Setup</h2>';
if ($msg) echo '<div class="flash ok" role="status">' . e($msg) . '</div>';
if ($err) echo '<div class="flash err" role="alert">' . e($err) . '</div>';
echo '<p>Step 1. Create the accounts. Password for all demo and staff accounts: <b>' . e(DEMO_PASSWORD) . '</b></p>'
   . fo_setup('create', 'Create accounts', 'btn block')
   . '<p class="hint">Locked out of a demo or staff account? This resets only those six passwords.</p>'
   . fo_setup('reset', 'Reset demo and staff passwords', 'btn ghost block')
   . '<p>Step 2. Remove this file so nobody else can run it.</p>'
   . fo_setup('delete', 'Delete setup.php', 'btn no block')
   . '<p class="alt"><a href="index.php">Go to sign in</a></p></div></main></div>';
foot_html();

function fo_setup(string $do, string $label, string $cls): string {
    return '<form method="post" action="setup.php">' . hid('csrf', csrf()) . hid('do', $do) . '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
}