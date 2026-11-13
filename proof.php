<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
$p = one('SELECT student_id, proof_path FROM payments WHERE id=?', [(int)($_GET['id'] ?? 0)]);
$ok = $p && $p['proof_path'] && ($u['role'] === 'student' ? $p['student_id'] === $u['sid'] : in_array($u['role'], ['cashier', 'dean', 'assistant'], true));
$file = $ok ? proof_file($p['proof_path']) : '';
if (!$ok || !is_file($file)) { http_response_code(404); exit('Not found'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=3600');
readfile($file);
