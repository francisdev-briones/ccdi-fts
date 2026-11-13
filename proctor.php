<?php
require __DIR__ . '/config/bootstrap.php';
$u = require_login();
if (!in_array($u['role'], ['proctor', 'dean'], true)) { flash('The scanner is for exam proctors.', 'err'); back(); }

$periods = periods();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf('proctor.php');
    $pid = (int)($_POST['period'] ?? 0);
    $valid = array_filter($periods, fn($p) => (int)$p['id'] === $pid);
    if (!$valid) { flash('Choose the exam you are proctoring.', 'err'); back('proctor.php'); }
    $_SESSION['period'] = $pid;
    [$result, $detail, $permit, $token] = validate_permit((string)($_POST['token'] ?? ''), $pid);
    q('INSERT INTO scan_logs (permit_id, token, proctor_id, period_id, result, detail) VALUES (?,?,?,?,?,?)',
      [$permit['id'] ?? null, mb_substr($token ?: trim((string)($_POST['token'] ?? '')), 0, 40), $u['id'], $pid, $result, mb_substr($detail, 0, 160)]);
    $_SESSION['scan'] = ['result' => $result, 'detail' => $detail, 'name' => $permit['name'] ?? null, 'sid' => $permit['student_id'] ?? null, 'period' => $permit['period_name'] ?? null];
    back('proctor.php');
}
$scan = $_SESSION['scan'] ?? null;
unset($_SESSION['scan']);
$sel = (int)($_SESSION['period'] ?? ($periods[0]['id'] ?? 0));

head_html('Permit scanner');
nav_html();
echo '<main id="main" class="page narrow"><div class="page-head"><div><h2>Permit scanner</h2><p class="muted">Scan the student\'s QR code or type the permit code.</p></div></div>';
flash_html();
if ($scan) {
    $ok = $scan['result'] === 'Valid';
    echo '<section class="scan ' . ($ok ? 'good' : 'stop') . '" role="alert"><h3>' . e($ok ? 'Valid permit' : $scan['result']) . '</h3>'
       . ($scan['name'] ? '<p class="who-line"><b>' . e($scan['name']) . '</b> ' . e($scan['sid']) . ($scan['period'] ? ', ' . e($scan['period']) . ' exam' : '') . '</p>' : '')
       . '<p>' . e($scan['detail']) . '</p></section>';
}
echo '<section class="panel"><form method="post" action="proctor.php" id="scanform">' . hid('csrf', csrf())
   . '<label>Exam<select name="period">';
foreach ($periods as $p) echo '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $sel ? ' selected' : '') . '>' . e($p['name']) . '</option>';
echo '</select></label><label>Permit code<input name="token" id="token" autocomplete="off" autocapitalize="characters" maxlength="20" required autofocus placeholder="ABCD-EF12-3456"></label>'
   . '<div class="btn-row"><button class="btn">Check permit</button><button type="button" class="btn ghost" id="cam" hidden>Scan with camera</button></div></form>'
   . '<video id="video" class="video" playsinline muted hidden></video></section>';

$rows = [];
foreach (all('SELECT l.*, s.name FROM scan_logs l LEFT JOIN permits p ON p.id=l.permit_id LEFT JOIN students s ON s.id=p.student_id WHERE l.proctor_id=? ORDER BY l.id DESC LIMIT 15', [$u['id']]) as $l) {
    $rows[] = row([e(date('g:i:s A', strtotime($l['created_at']))), e($l['name'] ?? 'Unknown') . '<br><small>' . e($l['token']) . '</small>', pill($l['result'])]);
}
echo '<section class="panel"><h3>My recent scans</h3>' . table(['Time', 'Student', 'Result'], $rows, 'No scans yet.') . '</section></main>';
?>
<script>
(function () {
  var cam = document.getElementById('cam'), video = document.getElementById('video'), input = document.getElementById('token'), stream = null;
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices) return;
  cam.hidden = false;
  cam.addEventListener('click', async function () {
    if (stream) { stop(); return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    } catch (e) { alert('The camera could not be opened. Type the code instead.'); return; }
    video.srcObject = stream; video.hidden = false; await video.play(); cam.textContent = 'Stop camera';
    var det = new BarcodeDetector({ formats: ['qr_code'] });
    (async function loop() {
      if (!stream) return;
      try {
        var codes = await det.detect(video);
        if (codes.length) { input.value = codes[0].rawValue; stop(); document.getElementById('scanform').submit(); return; }
      } catch (e) {}
      setTimeout(loop, 250);
    })();
  });
  function stop() {
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; video.hidden = true; cam.textContent = 'Scan with camera';
  }
})();
</script>
<?php foot_html();
