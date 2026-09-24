<?php
/*
 * EduVision: hosting probe (ใช้ครั้งเดียวแล้วลบทิ้ง)
 *
 * ตรวจว่า hosting รองรับ backend Laravel ตามที่ออกแบบไว้หรือไม่
 *   1. PHP >= 8.3 และมี extension ที่ Laravel/Filament ต้องใช้
 *   2. เรียก HTTPS ออกไปที่ Google (Gemini, FCM) ได้
 *   3. Scheduled Task รันได้ทุก 1 นาที และรันค้างได้ >= 55 วินาทีโดยไม่ถูก kill
 *
 * วิธีใช้
 *   1. อัปโหลดไฟล์นี้ไปที่ document root ของ subdomain ผ่าน Plesk > Files
 *      (ดู path ใน Websites & Domains > teacherhelper.phuwish.com > Hosting Settings
 *       ค่าเริ่มต้นคือโฟลเดอร์ teacherhelper.phuwish.com/ ไม่ใช่ httpdocs/ ซึ่งเป็นของโดเมนหลัก)
 *   2. เปิด https://teacherhelper.phuwish.com/hosting-probe.php?key=<PROBE_KEY>
 *   3. Plesk > Scheduled Tasks > Add Task > "Run a PHP script"
 *        script: <document root ของ subdomain>/hosting-probe.php, รันทุก 1 นาที (cron: * * * * *)
 *        เลือก PHP เวอร์ชันเดียวกับที่จะใช้กับ Laravel
 *      รอประมาณ 5 นาทีแล้วรีเฟรชหน้าเว็บ ดูหัวข้อ "Scheduled Task"
 *   4. (ไม่บังคับ) ใส่ DB_* ด้านล่างเป็น database ทดสอบที่สร้างใน Plesk เพื่อดูเวอร์ชัน MariaDB
 *   5. เสร็จแล้วลบไฟล์นี้, probe-cron.log, Scheduled Task และ database ทดสอบ
 */

const PROBE_KEY = 'CHANGE_ME_BEFORE_UPLOAD';   // ตั้งเป็นค่าสุ่ม (เช่น openssl rand -hex 12) ในสำเนาที่อัปโหลดเท่านั้น
if (PROBE_KEY === 'CHANGE_ME_BEFORE_UPLOAD') { http_response_code(403); exit('set PROBE_KEY first'); }

// ไม่บังคับ: เว้น DB_NAME ว่างไว้ = ข้ามการตรวจ database
const DB_HOST = 'localhost';
const DB_NAME = '';
const DB_USER = '';
const DB_PASS = '';

const MIN_PHP = '8.3.0';
const CRON_HOLD_SECONDS = 55;

date_default_timezone_set('Asia/Bangkok');

function outbound_targets()
{
    return array(
        'Gemini API' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'FCM' => 'https://fcm.googleapis.com/',
        'Google OAuth (ใช้ออก token ให้ FCM)' => 'https://oauth2.googleapis.com/token',
    );
}

function required_extensions()
{
    // Laravel + Filament (intl) + Composer (zip) + สร้าง PDF/ภาพ (gd)
    return array(
        'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'intl', 'mbstring',
        'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'sodium', 'tokenizer', 'xml', 'zip',
    );
}

// Scheduled Task (CLI) กับหน้าเว็บ (FPM) อาจมีสิทธิ์เขียนไม่เท่ากัน จึงลองสองที่
function log_candidates()
{
    return array(dirname(__DIR__) . '/probe-cron.log', __DIR__ . '/probe-cron.log');
}

function log_path_for_write()
{
    $home = dirname(__DIR__);
    return is_writable($home) ? $home . '/probe-cron.log' : __DIR__ . '/probe-cron.log';
}

function probe_outbound($url)
{
    if (!function_exists('curl_init')) {
        return array('fail', 'ไม่มี ext-curl');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ));
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $error = curl_error($ch);

    // ไม่ได้ส่ง API key จึงคาดว่าจะได้ 4xx; ได้ HTTP status ใดๆ กลับมาแปลว่าออกไปหา Google ได้
    if ($code > 0) {
        return array('pass', sprintf('HTTP %d ใน %.2f วินาที', $code, $time));
    }
    return array('fail', 'เชื่อมต่อไม่ได้: ' . $error);
}

// ---- โหมด Scheduled Task ----
if (PHP_SAPI === 'cli') {
    $log = log_path_for_write();
    $start = time();
    $targets = outbound_targets();
    list(, $gemini) = probe_outbound($targets['Gemini API']);
    file_put_contents($log, sprintf(
        "%s start pid=%d php=%s gemini=\"%s\"\n",
        date('c', $start), getmypid(), PHP_VERSION, $gemini
    ), FILE_APPEND);

    sleep(CRON_HOLD_SECONDS);

    file_put_contents($log, sprintf(
        "%s end pid=%d elapsed=%d\n",
        date('c'), getmypid(), time() - $start
    ), FILE_APPEND);
    exit(0);
}

// ---- โหมดหน้าเว็บ ----
if (!isset($_GET['key']) || !hash_equals(PROBE_KEY, (string) $_GET['key'])) {
    http_response_code(404);
    exit;
}

$rows = array();

// 1. PHP
$rows[] = array('PHP', 'เวอร์ชัน (หน้าเว็บ)',
    version_compare(PHP_VERSION, MIN_PHP, '>=') ? 'pass' : 'fail',
    PHP_VERSION . ' (ต้องการ >= ' . MIN_PHP . ')');

$missing = array();
foreach (required_extensions() as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
$rows[] = array('PHP', 'Extensions', $missing ? 'fail' : 'pass',
    $missing ? 'ขาด: ' . implode(', ', $missing) : 'ครบ ' . count(required_extensions()) . ' ตัว');

foreach (array('memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size') as $ini) {
    $rows[] = array('PHP', $ini, 'info', (string) ini_get($ini));
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$rows[] = array('PHP', 'proc_open',
    in_array('proc_open', $disabled, true) ? 'warn' : 'pass',
    in_array('proc_open', $disabled, true)
        ? 'ถูกปิด: ใช้ artisan schedule:run ไม่ได้ ต้องให้ Scheduled Task เรียก queue:work ตรงๆ'
        : 'ใช้ได้');
$rows[] = array('PHP', 'disable_functions', 'info', $disabled ? implode(', ', $disabled) : '(ไม่มี)');
$rows[] = array('PHP', 'open_basedir', 'info', ini_get('open_basedir') ?: '(ไม่จำกัด)');

// 2. HTTPS ขาออก
foreach (outbound_targets() as $name => $url) {
    list($status, $detail) = probe_outbound($url);
    $rows[] = array('HTTPS ขาออก', $name, $status, $detail);
}

// 3. Database
if (DB_NAME === '') {
    $rows[] = array('Database', 'เวอร์ชัน', 'info', 'ข้าม (ใส่ DB_* ในไฟล์ หรือดูจากหน้าแรกของ phpMyAdmin)');
} else {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $numeric = preg_replace('/[^0-9.].*$/', '', $version);
        $skipLocked = $isMaria ? version_compare($numeric, '10.6.0', '>=') : version_compare($numeric, '8.0.1', '>=');
        $rows[] = array('Database', 'เวอร์ชัน', 'info', $version);
        $rows[] = array('Database', 'รองรับ SKIP LOCKED', $skipLocked ? 'pass' : 'warn',
            $skipLocked ? 'รองรับ' : 'ไม่รองรับ: queue ของ Laravel ยังทำงานได้ แต่รัน worker พร้อมกันหลายตัวไม่ได้ดี');
    } catch (Exception $e) {
        $rows[] = array('Database', 'เชื่อมต่อ', 'fail', $e->getMessage());
    }
}

// 4. Scheduled Task
$lines = array();
foreach (log_candidates() as $path) {
    if (is_readable($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        break;
    }
}

if (!$lines) {
    $rows[] = array('Scheduled Task', 'ผลการรัน', 'info', 'ยังไม่มีข้อมูล: ตั้ง Scheduled Task ทุก 1 นาทีแล้วรอประมาณ 5 นาที');
} else {
    $starts = array();
    $ends = array();
    foreach ($lines as $line) {
        if (preg_match('/^(\S+) start pid=(\d+) php=(\S+) gemini="([^"]*)"/', $line, $m)) {
            $starts[] = array('ts' => strtotime($m[1]), 'pid' => $m[2], 'php' => $m[3], 'gemini' => $m[4]);
        } elseif (preg_match('/^(\S+) end pid=(\d+) elapsed=(\d+)/', $line, $m)) {
            $ends[$m[2]] = (int) $m[3];
        }
    }

    $now = time();
    $finished = 0;
    $killed = 0;
    foreach ($starts as $s) {
        if (isset($ends[$s['pid']])) {
            $finished++;
        } elseif ($now - $s['ts'] > CRON_HOLD_SECONDS + 30) {
            $killed++;
        }
    }

    $gaps = array();
    for ($i = 1; $i < count($starts); $i++) {
        $gaps[] = $starts[$i]['ts'] - $starts[$i - 1]['ts'];
    }

    if (count($gaps) < 2) {
        $rows[] = array('Scheduled Task', 'ความถี่', 'info', 'รันแล้ว ' . count($starts) . ' ครั้ง: รอให้ครบ 3 ครั้งก่อน');
    } else {
        $rows[] = array('Scheduled Task', 'ความถี่', max($gaps) <= 90 ? 'pass' : 'fail',
            sprintf('รัน %d ครั้ง ห่างกัน %d–%d วินาที', count($starts), min($gaps), max($gaps)));
    }

    if ($killed > 0) {
        $status = $finished > 0 ? 'warn' : 'fail';
    } else {
        $status = $finished > 0 ? 'pass' : 'info';
    }
    $rows[] = array('Scheduled Task', 'รันค้าง ' . CRON_HOLD_SECONDS . ' วินาที', $status,
        sprintf('รันจบ %d ครั้ง, ถูกตัดกลางทาง %d ครั้ง', $finished, $killed));

    $last = end($starts);
    $rows[] = array('Scheduled Task', 'PHP เวอร์ชัน (CLI)',
        version_compare($last['php'], MIN_PHP, '>=') ? 'pass' : 'fail', $last['php']);
    $rows[] = array('Scheduled Task', 'Gemini API จาก CLI',
        strpos($last['gemini'], 'HTTP ') === 0 ? 'pass' : 'fail', $last['gemini']);
}

$labels = array('pass' => 'ผ่าน', 'fail' => 'ไม่ผ่าน', 'warn' => 'ควรดู', 'info' => 'ข้อมูล');
$colors = array('pass' => '#1b7f3b', 'fail' => '#b3261e', 'warn' => '#9a6700', 'info' => '#555');

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hosting Probe</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 16px; color: #1c1b1f; background: #fff; }
  table { border-collapse: collapse; width: 100%; max-width: 960px; }
  th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #ddd; vertical-align: top; }
  td.status { font-weight: 600; white-space: nowrap; }
  pre { background: #f4f4f4; padding: 10px; overflow-x: auto; max-width: 960px; }
</style>
</head>
<body>
<h1>EduVision hosting probe</h1>
<p>ตรวจเมื่อ <?= htmlspecialchars(date('Y-m-d H:i:s')) ?> (Asia/Bangkok)</p>
<table>
  <tr><th>หมวด</th><th>รายการ</th><th>ผล</th><th>รายละเอียด</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r[0]) ?></td>
    <td><?= htmlspecialchars($r[1]) ?></td>
    <td class="status" style="color: <?= $colors[$r[2]] ?>"><?= $labels[$r[2]] ?></td>
    <td><?= htmlspecialchars($r[3]) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($lines): ?>
<h2>probe-cron.log (10 บรรทัดล่าสุด)</h2>
<pre><?= htmlspecialchars(implode("\n", array_slice($lines, -10))) ?></pre>
<?php endif; ?>
</body>
</html>
