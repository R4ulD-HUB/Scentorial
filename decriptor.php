<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 0);
ini_set('post_max_size', '500M');
ini_set('upload_max_filesize', '500M');

define('WORDLIST_DIR', __DIR__ . '/wordlists/');
define('MAX_FILE_SIZE', 500 * 1024 * 1024);
define('BRUTEFORCE_CHUNK', 50000);
define('TEMP_DIR', '/tmp/h/i/j/o/d/e/p/e/r/r/a/hashcracker/');

$cpu_count = (int) @shell_exec('nproc 2>/dev/null') ?: 4;
define('NUM_WORKERS', $cpu_count);

foreach ([WORDLIST_DIR, TEMP_DIR] as $dir) {
    if (!file_exists($dir))
        mkdir($dir, 0755, true);
}

// ==================== HASH ALGORITHMS ====================
$HASH_ALGORITHMS = [
    'md5' => ['name' => 'MD5', 'len' => 32, 'cat' => 'Common'],
    'md4' => ['name' => 'MD4', 'len' => 32, 'cat' => 'Common'],
    'sha1' => ['name' => 'SHA-1', 'len' => 40, 'cat' => 'Common'],
    'sha256' => ['name' => 'SHA-256', 'len' => 64, 'cat' => 'Common'],
    'sha384' => ['name' => 'SHA-384', 'len' => 96, 'cat' => 'Common'],
    'sha512' => ['name' => 'SHA-512', 'len' => 128, 'cat' => 'Common'],
    'sha3-256' => ['name' => 'SHA3-256', 'len' => 64, 'cat' => 'SHA3'],
    'sha3-512' => ['name' => 'SHA3-512', 'len' => 128, 'cat' => 'SHA3'],
    'ripemd128' => ['name' => 'RIPEMD-128', 'len' => 32, 'cat' => 'RIPEMD'],
    'ripemd160' => ['name' => 'RIPEMD-160', 'len' => 40, 'cat' => 'RIPEMD'],
    'ripemd256' => ['name' => 'RIPEMD-256', 'len' => 64, 'cat' => 'RIPEMD'],
    'whirlpool' => ['name' => 'Whirlpool', 'len' => 128, 'cat' => 'Modern'],
    'crc32' => ['name' => 'CRC32', 'len' => 8, 'cat' => 'Checksum'],
    'crc32b' => ['name' => 'CRC32b', 'len' => 8, 'cat' => 'Checksum'],
    'adler32' => ['name' => 'Adler-32', 'len' => 8, 'cat' => 'Checksum'],
    'ntlm' => ['name' => 'NTLM', 'len' => 32, 'cat' => 'Windows'],
    'mysql41' => ['name' => 'MySQL 4.1+', 'len' => 40, 'cat' => 'Database', 'prefix' => '*'],
    'bcrypt' => ['name' => 'bcrypt', 'len' => 60, 'cat' => 'Salted', 'prefix' => '$2'],
    'argon2i' => ['name' => 'Argon2i', 'len' => 0, 'cat' => 'Salted', 'prefix' => '$argon2i$'],
    'argon2id' => ['name' => 'Argon2id', 'len' => 0, 'cat' => 'Salted', 'prefix' => '$argon2id$'],
    'md5crypt' => ['name' => 'MD5 Crypt', 'len' => 0, 'cat' => 'Unix', 'prefix' => '$1$'],
    'sha256crypt' => ['name' => 'SHA-256 Crypt', 'len' => 0, 'cat' => 'Unix', 'prefix' => '$5$'],
    'sha512crypt' => ['name' => 'SHA-512 Crypt', 'len' => 0, 'cat' => 'Unix', 'prefix' => '$6$'],
    'wordpress' => ['name' => 'WordPress', 'len' => 34, 'cat' => 'CMS', 'prefix' => '$P$'],
    'phpbb3' => ['name' => 'phpBB3', 'len' => 34, 'cat' => 'CMS', 'prefix' => '$H$'],
];

// ==================== REQUEST ROUTING ====================
if (isset($_GET['stream']) && $_GET['stream'] === 'crack') {
    handleStreamCrack();
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'upload_wordlist':
            handleWordlistUpload();
            break;
        case 'crack_hash':
            handleHashCracking();
            break;
        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
    exit;
}

// ==================== HELPER FUNCTIONS ====================
function formatTime($s)
{
    if ($s < 60)
        return round($s, 1) . 's';
    if ($s < 3600)
        return round($s / 60, 1) . 'm';
    if ($s < 86400)
        return round($s / 3600, 1) . 'h';
    return round($s / 86400, 1) . 'd';
}

function sendSSE($data)
{
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0)
        ob_flush();
    flush();
}

function computeHash($algo, $password)
{
    switch ($algo) {
        case 'md5':
            return md5($password);
        case 'md4':
            return hash('md4', $password);
        case 'sha1':
            return sha1($password);
        case 'sha256':
            return hash('sha256', $password);
        case 'sha384':
            return hash('sha384', $password);
        case 'sha512':
            return hash('sha512', $password);
        case 'ntlm':
            return hash('md4', iconv('UTF-8', 'UTF-16LE', $password));
        case 'mysql41':
            return '*' . strtoupper(sha1(sha1($password, true)));
        default:
            if (in_array($algo, hash_algos()))
                return hash($algo, $password);
            return false;
    }
}

function verifySaltedHash($algo, $target, $password)
{
    switch ($algo) {
        case 'bcrypt':
        case 'wordpress':
        case 'phpbb3':
            return password_verify($password, $target);
        case 'argon2i':
        case 'argon2id':
            return password_verify($password, $target);
        case 'md5crypt':
        case 'sha256crypt':
        case 'sha512crypt':
            return crypt($password, $target) === $target;
        default:
            return false;
    }
}

function isSaltedAlgo($algo)
{
    return in_array($algo, ['bcrypt', 'argon2i', 'argon2id', 'md5crypt', 'sha256crypt', 'sha512crypt', 'wordpress', 'phpbb3']);
}

function verifyHash($algo, $target, $password)
{
    if (isSaltedAlgo($algo)) {
        return verifySaltedHash($algo, $target, $password);
    }
    $computed = computeHash($algo, $password);
    if ($computed === false)
        return false;
    return strtolower($computed) === strtolower($target);
}

// ==================== WORDLIST UPLOAD ====================
function handleWordlistUpload()
{
    if (!isset($_FILES['wordlist'])) {
        echo json_encode(['error' => 'No se recibió archivo']);
        return;
    }
    $file = $_FILES['wordlist'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Error al subir: código ' . $file['error']]);
        return;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        echo json_encode(['error' => 'Archivo demasiado grande. Máximo: 500MB']);
        return;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt', 'lst', 'dict', 'wordlist'])) {
        echo json_encode(['error' => 'Solo .txt, .lst, .dict, .wordlist']);
        return;
    }
    $filename = 'custom_' . uniqid() . '.txt';
    $filepath = WORDLIST_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['error' => 'Error al guardar']);
        return;
    }
    $lines = 0;
    $h = fopen($filepath, 'r');
    if ($h) {
        while (!feof($h)) {
            $lines += substr_count(fread($h, 8192), "\n");
        }
        fclose($h);
    }
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'filepath' => $filepath,
        'size' => $file['size'],
        'lines' => $lines,
        'message' => 'Diccionario subido (se eliminará tras crackeo)'
    ]);
}

// ==================== CRACK HASH (POST - bruteforce only) ====================
function handleHashCracking()
{
    $hash = trim($_POST['hash'] ?? '');
    $algo = $_POST['algorithm'] ?? 'md5';
    if (empty($hash)) {
        echo json_encode(['error' => 'Hash vacío']);
        return;
    }

    $charset = $_POST['charset'] ?? 'lowercase';
    $minLen = max(1, (int) ($_POST['min_length'] ?? 1));
    $maxLen = min(8, (int) ($_POST['max_length'] ?? 4));
    $mask = trim($_POST['mask_pattern'] ?? '');
    if ($maxLen < $minLen)
        $maxLen = $minLen;

    $charsets = [
        'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
        'uppercase' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'numbers' => '0123456789',
        'symbols' => '!@#$%^&*()-_=+[]{}|;:,.<>?',
        'lowernumbers' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        'alphanumeric' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'all' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+'
    ];

    $maskCharsets = [
        'l' => 'abcdefghijklmnopqrstuvwxyz',
        'u' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'd' => '0123456789',
        's' => '!@#$%^&*()-_=+[]{}|;:,.<>?',
        'a' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+'
    ];

    $start = microtime(true);
    $attempts = 0;
    $found = false;
    $password = '';

    if (!empty($mask)) {
        // Parse mask
        $positions = [];
        $mlen = strlen($mask);
        for ($i = 0; $i < $mlen; $i++) {
            if ($mask[$i] === '?' && $i + 1 < $mlen && isset($maskCharsets[$mask[$i + 1]])) {
                $positions[] = str_split($maskCharsets[$mask[$i + 1]]);
                $i++;
            } else {
                $positions[] = [$mask[$i]];
            }
        }
        $numPos = count($positions);
        $maxIdx = array_map('count', $positions);
        $cur = array_fill(0, $numPos, 0);
        $total = 1;
        foreach ($maxIdx as $m)
            $total *= $m;
        $limit = min($total, 100000000); // 100M max

        for ($c = 0; $c < $limit && !$found; $c++) {
            $candidate = '';
            for ($p = 0; $p < $numPos; $p++)
                $candidate .= $positions[$p][$cur[$p]];
            $attempts++;
            if (verifyHash($algo, $hash, $candidate)) {
                $found = true;
                $password = $candidate;
                break;
            }
            for ($p = $numPos - 1; $p >= 0; $p--) {
                $cur[$p]++;
                if ($cur[$p] < $maxIdx[$p])
                    break;
                $cur[$p] = 0;
            }
        }
    } else {
        $chars = str_split($charsets[$charset] ?? $charsets['lowercase']);
        $numChars = count($chars);
        for ($len = $minLen; $len <= $maxLen && !$found; $len++) {
            $idx = array_fill(0, $len, 0);
            $total = pow($numChars, $len);
            $limit = min($total, 100000000);
            for ($c = 0; $c < $limit && !$found; $c++) {
                $candidate = '';
                for ($p = 0; $p < $len; $p++)
                    $candidate .= $chars[$idx[$p]];
                $attempts++;
                if (verifyHash($algo, $hash, $candidate)) {
                    $found = true;
                    $password = $candidate;
                    break;
                }
                for ($p = $len - 1; $p >= 0; $p--) {
                    $idx[$p]++;
                    if ($idx[$p] < $numChars)
                        break;
                    $idx[$p] = 0;
                }
            }
        }
    }

    $time = microtime(true) - $start;
    echo json_encode([
        'success' => true,
        'found' => $found,
        'password' => $password,
        'attempts' => $attempts,
        'time' => round($time, 2),
        'speed' => round($attempts / max($time, 0.001)),
        'algorithm' => strtoupper($algo)
    ]);
}

// ==================== SSE STREAM CRACK (dictionary + bruteforce) ====================
function handleStreamCrack()
{
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', 0);
    if (ob_get_level())
        ob_end_clean();

    $hash = trim($_GET['hash'] ?? '');
    $algo = $_GET['algorithm'] ?? 'md5';
    $mode = $_GET['mode'] ?? 'dictionary';

    if (empty($hash)) {
        sendSSE(['error' => 'Hash vacío']);
        return;
    }

    if ($mode === 'dictionary') {
        $wordlistPath = $_GET['wordlist'] ?? '';
        $source = $_GET['source'] ?? 'rockyou';
        if (!file_exists($wordlistPath)) {
            sendSSE(['error' => 'Wordlist no encontrado']);
            return;
        }

        // Count lines
        $totalLines = 0;
        $h = fopen($wordlistPath, 'r');
        if ($h) {
            while (!feof($h)) {
                $totalLines += substr_count(fread($h, 65536), "\n");
            }
            fclose($h);
        }

        sendSSE(['status' => 'starting', 'total' => $totalLines, 'algorithm' => strtoupper($algo)]);

        $startTime = microtime(true);
        $attempts = 0;
        $found = false;
        $password = '';
        $lastUpdate = $startTime;

        $h = fopen($wordlistPath, 'r');
        if (!$h) {
            sendSSE(['error' => 'No se pudo abrir wordlist']);
            return;
        }

        while (($line = fgets($h)) !== false) {
            $pwd = rtrim($line, "\r\n");
            if ($pwd === '')
                continue;
            $attempts++;

            if (verifyHash($algo, $hash, $pwd)) {
                $found = true;
                $password = $pwd;
                break;
            }

            $now = microtime(true);
            if ($now - $lastUpdate >= 0.3) {
                $elapsed = $now - $startTime;
                $speed = $attempts / max($elapsed, 0.001);
                $pct = ($attempts / max($totalLines, 1)) * 100;
                $rem = ($totalLines - $attempts) / max($speed, 1);
                sendSSE([
                    'status' => 'running',
                    'progress' => round($pct, 1),
                    'attempts' => $attempts,
                    'speed' => round($speed),
                    'elapsed' => round($elapsed, 1),
                    'remaining' => formatTime($rem),
                    'current' => substr($pwd, 0, 20)
                ]);
                $lastUpdate = $now;
            }
        }
        fclose($h);

        // Delete uploaded wordlist after cracking
        if ($source !== 'rockyou' && file_exists($wordlistPath) && strpos(basename($wordlistPath), 'custom_') === 0) {
            @unlink($wordlistPath);
        }

        $totalTime = microtime(true) - $startTime;
        sendSSE([
            'status' => 'finished',
            'found' => $found,
            'password' => $password,
            'attempts' => $attempts,
            'time' => round($totalTime, 2),
            'speed' => round($attempts / max($totalTime, 0.001))
        ]);

    } elseif ($mode === 'bruteforce') {
        $charset = $_GET['charset'] ?? 'lowercase';
        $minLen = max(1, (int) ($_GET['min_length'] ?? 1));
        $maxLen = min(8, (int) ($_GET['max_length'] ?? 4));
        $mask = trim($_GET['mask'] ?? '');

        $charsets = [
            'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
            'uppercase' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'numbers' => '0123456789',
            'symbols' => '!@#$%^&*()-_=+[]{}|;:,.<>?',
            'lowernumbers' => 'abcdefghijklmnopqrstuvwxyz0123456789',
            'alphanumeric' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            'all' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+'
        ];
        $maskCharsets = [
            'l' => 'abcdefghijklmnopqrstuvwxyz',
            'u' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'd' => '0123456789',
            's' => '!@#$%^&*()-_=+[]{}|;:,.<>?',
            'a' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+'
        ];

        $startTime = microtime(true);
        $attempts = 0;
        $found = false;
        $password = '';
        $lastUpdate = $startTime;

        if (!empty($mask)) {
            $positions = [];
            $mlen = strlen($mask);
            for ($i = 0; $i < $mlen; $i++) {
                if ($mask[$i] === '?' && $i + 1 < $mlen && isset($maskCharsets[$mask[$i + 1]])) {
                    $positions[] = str_split($maskCharsets[$mask[$i + 1]]);
                    $i++;
                } else {
                    $positions[] = [$mask[$i]];
                }
            }
            $numPos = count($positions);
            $maxIdx = array_map('count', $positions);
            $cur = array_fill(0, $numPos, 0);
            $total = 1;
            foreach ($maxIdx as $m)
                $total *= $m;
            $limit = min($total, 500000000);

            sendSSE(['status' => 'starting', 'total' => $limit, 'algorithm' => strtoupper($algo), 'mode' => 'bruteforce']);

            for ($c = 0; $c < $limit && !$found; $c++) {
                $candidate = '';
                for ($p = 0; $p < $numPos; $p++)
                    $candidate .= $positions[$p][$cur[$p]];
                $attempts++;
                if (verifyHash($algo, $hash, $candidate)) {
                    $found = true;
                    $password = $candidate;
                    break;
                }
                for ($p = $numPos - 1; $p >= 0; $p--) {
                    $cur[$p]++;
                    if ($cur[$p] < $maxIdx[$p])
                        break;
                    $cur[$p] = 0;
                }
                $now = microtime(true);
                if ($now - $lastUpdate >= 0.4) {
                    $elapsed = $now - $startTime;
                    $speed = $attempts / max($elapsed, 0.001);
                    $pct = ($attempts / max($limit, 1)) * 100;
                    $rem = ($limit - $attempts) / max($speed, 1);
                    sendSSE([
                        'status' => 'running',
                        'progress' => round($pct, 1),
                        'attempts' => $attempts,
                        'speed' => round($speed),
                        'elapsed' => round($elapsed, 1),
                        'remaining' => formatTime($rem),
                        'current' => substr($candidate, 0, 20)
                    ]);
                    $lastUpdate = $now;
                    if (connection_aborted())
                        return;
                }
            }
        } else {
            $chars = str_split($charsets[$charset] ?? $charsets['lowercase']);
            $numChars = count($chars);
            $totalAll = 0;
            for ($l = $minLen; $l <= $maxLen; $l++)
                $totalAll += pow($numChars, $l);
            $limit = min($totalAll, 500000000);
            sendSSE(['status' => 'starting', 'total' => $limit, 'algorithm' => strtoupper($algo), 'mode' => 'bruteforce']);

            for ($len = $minLen; $len <= $maxLen && !$found; $len++) {
                $idx = array_fill(0, $len, 0);
                $total = pow($numChars, $len);
                for ($c = 0; $c < $total && !$found; $c++) {
                    $candidate = '';
                    for ($p = 0; $p < $len; $p++)
                        $candidate .= $chars[$idx[$p]];
                    $attempts++;
                    if (verifyHash($algo, $hash, $candidate)) {
                        $found = true;
                        $password = $candidate;
                        break;
                    }
                    for ($p = $len - 1; $p >= 0; $p--) {
                        $idx[$p]++;
                        if ($idx[$p] < $numChars)
                            break;
                        $idx[$p] = 0;
                    }
                    $now = microtime(true);
                    if ($now - $lastUpdate >= 0.4) {
                        $elapsed = $now - $startTime;
                        $speed = $attempts / max($elapsed, 0.001);
                        $pct = ($attempts / max($limit, 1)) * 100;
                        $rem = ($limit - $attempts) / max($speed, 1);
                        sendSSE([
                            'status' => 'running',
                            'progress' => round($pct, 1),
                            'attempts' => $attempts,
                            'speed' => round($speed),
                            'elapsed' => round($elapsed, 1),
                            'remaining' => formatTime($rem),
                            'current' => substr($candidate, 0, 20)
                        ]);
                        $lastUpdate = $now;
                        if (connection_aborted())
                            return;
                    }
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        sendSSE([
            'status' => 'finished',
            'found' => $found,
            'password' => $password,
            'attempts' => $attempts,
            'time' => round($totalTime, 2),
            'speed' => round($attempts / max($totalTime, 0.001))
        ]);
    }
}

// ==================== AUTO-DETECT ALGORITHM ====================
function detectAlgorithm($hash)
{
    $hash = trim($hash);
    if (preg_match('/^\$2[aby]?\$/', $hash))
        return 'bcrypt';
    if (strpos($hash, '$argon2id$') === 0)
        return 'argon2id';
    if (strpos($hash, '$argon2i$') === 0)
        return 'argon2i';
    if (strpos($hash, '$1$') === 0)
        return 'md5crypt';
    if (strpos($hash, '$5$') === 0)
        return 'sha256crypt';
    if (strpos($hash, '$6$') === 0)
        return 'sha512crypt';
    if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0)
        return 'wordpress';
    if (preg_match('/^\*[A-F0-9]{40}$/i', $hash))
        return 'mysql41';
    $len = strlen($hash);
    if ($len === 32 && ctype_xdigit($hash))
        return 'md5';
    if ($len === 40 && ctype_xdigit($hash))
        return 'sha1';
    if ($len === 64 && ctype_xdigit($hash))
        return 'sha256';
    if ($len === 96 && ctype_xdigit($hash))
        return 'sha384';
    if ($len === 128 && ctype_xdigit($hash))
        return 'sha512';
    return '';
}

// Generate algorithm options for HTML
function getAlgorithmOptions()
{
    global $HASH_ALGORITHMS;
    $cats = [];
    foreach ($HASH_ALGORITHMS as $key => $info) {
        $cats[$info['cat']][$key] = $info['name'];
    }
    $html = '<option value="">-- Auto-detectar --</option>';
    foreach ($cats as $cat => $algos) {
        $html .= "<optgroup label=\"$cat\">";
        foreach ($algos as $key => $name) {
            $html .= "<option value=\"$key\">$name</option>";
        }
        $html .= "</optgroup>";
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeonCracker // Hash Breaker</title>
    <link
        href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Orbitron:wght@400;700;900&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        :root {
            --neon: #00ff41;
            --neon2: #0ff;
            --neon3: #ff00ff;
            --bg: #0a0a0f;
            --bg2: #0d0d18;
            --panel: #111122;
            --border: #1a1a3a;
            --text: #c0c0d0;
            --danger: #ff3355;
            --warn: #ffaa00;
            --success: #00ff41
        }

        body {
            font-family: 'JetBrains Mono', monospace;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden
        }

        canvas#matrix {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 0;
            opacity: .06;
            pointer-events: none
        }

        .app {
            position: relative;
            z-index: 1;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px
        }

        header {
            text-align: center;
            padding: 30px 0 20px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 25px
        }

        header h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.2em;
            font-weight: 900;
            background: linear-gradient(135deg, var(--neon), var(--neon2), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 40px rgba(0, 255, 65, .3);
            letter-spacing: 3px
        }

        header p {
            color: #556;
            font-size: .85em;
            margin-top: 8px;
            letter-spacing: 1px
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px
        }

        @media(max-width:900px) {
            .grid {
                grid-template-columns: 1fr
            }
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            position: relative;
            overflow: hidden
        }

        .panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--neon), var(--neon2), var(--neon3))
        }

        .panel h2 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1em;
            color: var(--neon);
            margin-bottom: 18px;
            letter-spacing: 1px
        }

        .form-group {
            margin-bottom: 14px
        }

        .form-group label {
            display: block;
            font-size: .8em;
            color: var(--neon2);
            margin-bottom: 5px;
            letter-spacing: .5px
        }

        textarea,
        select,
        input[type=text],
        input[type=number] {
            width: 100%;
            padding: 10px 12px;
            background: #0a0a18;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--neon);
            font-family: inherit;
            font-size: .85em;
            outline: none;
            transition: border .3s, box-shadow .3s
        }

        textarea:focus,
        select:focus,
        input:focus {
            border-color: var(--neon);
            box-shadow: 0 0 10px rgba(0, 255, 65, .15)
        }

        textarea {
            resize: vertical;
            min-height: 70px
        }

        select {
            cursor: pointer;
            -webkit-appearance: none
        }

        select option {
            background: #111;
            color: #ccc
        }

        select optgroup {
            color: var(--neon2);
            font-weight: 700
        }

        .radio-group {
            display: flex;
            gap: 16px;
            flex-wrap: wrap
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: .8em;
            transition: all .3s
        }

        .radio-label:hover {
            border-color: var(--neon)
        }

        .radio-label input {
            accent-color: var(--neon);
            cursor: pointer
        }

        .radio-label.active {
            border-color: var(--neon);
            background: rgba(0, 255, 65, .05)
        }

        .file-drop {
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all .3s;
            position: relative
        }

        .file-drop:hover,
        .file-drop.dragover {
            border-color: var(--neon2);
            background: rgba(0, 255, 255, .03)
        }

        .file-drop input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer
        }

        .file-drop .icon {
            font-size: 1.8em;
            margin-bottom: 6px
        }

        .file-drop .text {
            font-size: .75em;
            color: #667
        }

        .file-info {
            font-size: .75em;
            color: var(--neon2);
            padding: 8px;
            background: rgba(0, 255, 255, .05);
            border-radius: 4px;
            margin-top: 8px;
            display: none
        }

        .file-info.active {
            display: block
        }

        .inline-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px
        }

        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-family: 'Orbitron', sans-serif;
            font-size: .85em;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 1px;
            transition: all .3s;
            position: relative;
            overflow: hidden
        }

        .btn-primary {
            background: linear-gradient(135deg, #003311, #005522);
            color: var(--neon);
            border: 1px solid var(--neon)
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #004422, #006633);
            box-shadow: 0 0 20px rgba(0, 255, 65, .2)
        }

        .btn-danger {
            background: linear-gradient(135deg, #330011, #550022);
            color: var(--danger);
            border: 1px solid var(--danger);
            margin-top: 8px
        }

        .btn-danger:hover:not(:disabled) {
            box-shadow: 0 0 20px rgba(255, 51, 85, .2)
        }

        .btn:disabled {
            opacity: .4;
            cursor: not-allowed
        }

        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 14px
        }

        .console {
            background: #050510;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            font-size: .75em;
            line-height: 1.8
        }

        .console::-webkit-scrollbar {
            width: 4px
        }

        .console::-webkit-scrollbar-track {
            background: #050510
        }

        .console::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 2px
        }

        .log-line {
            padding: 1px 0;
            word-break: break-all
        }

        .log-line.info {
            color: var(--neon2)
        }

        .log-line.success {
            color: var(--success)
        }

        .log-line.error {
            color: var(--danger)
        }

        .log-line.warning {
            color: var(--warn)
        }

        .progress-wrap {
            margin-top: 15px;
            display: none
        }

        .progress-wrap.active {
            display: block
        }

        .progress-bar {
            height: 22px;
            background: #0a0a18;
            border: 1px solid var(--border);
            border-radius: 11px;
            overflow: hidden;
            position: relative
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--neon), var(--neon2));
            border-radius: 11px;
            transition: width .3s;
            width: 0
        }

        .progress-text {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7em;
            font-weight: 700;
            color: #fff;
            text-shadow: 0 0 4px #000
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 10px;
            font-size: .7em
        }

        .stat-box {
            background: rgba(0, 255, 255, .03);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px;
            text-align: center
        }

        .stat-box .val {
            font-size: 1.2em;
            color: var(--neon);
            font-weight: 700;
            margin-top: 2px
        }

        .stat-box .lbl {
            color: #556;
            font-size: .85em
        }

        .result-box {
            margin-top: 15px;
            display: none;
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            animation: glow 2s infinite alternate
        }

        .result-box.found {
            display: block;
            background: rgba(0, 255, 65, .05);
            border: 2px solid var(--neon)
        }

        .result-box.notfound {
            display: block;
            background: rgba(255, 51, 85, .05);
            border: 2px solid var(--danger)
        }

        .result-box .pwd {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.6em;
            color: var(--neon);
            margin: 10px 0;
            word-break: break-all;
            text-shadow: 0 0 15px rgba(0, 255, 65, .5)
        }

        .result-box .lbl {
            font-size: .8em;
            color: #778
        }

        .copy-btn {
            background: none;
            border: 1px solid var(--neon2);
            color: var(--neon2);
            padding: 4px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-size: .75em;
            margin-top: 6px;
            transition: all .3s
        }

        .copy-btn:hover {
            background: rgba(0, 255, 255, .1)
        }

        .warn-box {
            border: 1px solid var(--warn);
            background: rgba(255, 170, 0, .05);
            padding: 12px;
            border-radius: 6px;
            color: var(--warn);
            font-size: .8em;
            margin-top: 10px;
            display: none
        }

        .warn-box .warn-btns {
            display: flex;
            gap: 8px;
            margin-top: 8px
        }

        .warn-box .warn-btns button {
            padding: 6px 16px;
            border-radius: 4px;
            border: none;
            font-family: inherit;
            cursor: pointer;
            font-size: .8em
        }

        .warn-yes {
            background: var(--warn);
            color: #000
        }

        .warn-no {
            background: var(--danger);
            color: #fff
        }

        @keyframes glow {
            0% {
                box-shadow: 0 0 5px rgba(0, 255, 65, .1)
            }

            100% {
                box-shadow: 0 0 25px rgba(0, 255, 65, .2)
            }
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .5
            }
        }

        .spinner {
            display: none;
            text-align: center;
            padding: 10px;
            color: var(--neon2);
            animation: pulse 1s infinite
        }

        .spinner.active {
            display: block
        }

        footer {
            text-align: center;
            padding: 20px 0;
            color: #334;
            font-size: .7em;
            border-top: 1px solid var(--border);
            margin-top: 25px
        }
    </style>
</head>

<body>
    <canvas id="matrix"></canvas>
    <div class="app">
        <header>
            <h1>⚡ NEONCRACKER</h1>
            <p>// HASH BREAKER TOOL — dictionary & brute-force engine</p>
        </header>
        <div class="grid">
            <!-- LEFT PANEL -->
            <div class="panel">
                <h2>⌬ CONFIG</h2>
                <form id="crackForm" autocomplete="off">
                    <div class="form-group">
                        <label>TARGET HASH</label>
                        <textarea id="hashInput" placeholder="Paste your hash here..." spellcheck="false"></textarea>
                    </div>
                    <div class="form-group">
                        <label>ALGORITHM</label>
                        <select id="algorithm">
                            <?php echo getAlgorithmOptions(); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ATTACK MODE</label>
                        <select id="attackMode">
                            <option value="dictionary">📖 Dictionary</option>
                            <option value="bruteforce">💥 Brute Force</option>
                        </select>
                    </div>
                    <!-- DICTIONARY CONTROLS -->
                    <div id="dictControls">
                        <div class="form-group">
                            <label>WORDLIST SOURCE</label>
                            <div class="radio-group">
                                <label class="radio-label active"><input type="radio" name="wlSrc" value="rockyou"
                                        checked> rockyou.txt</label>
                                <label class="radio-label"><input type="radio" name="wlSrc" value="upload"> Upload
                                    custom</label>
                            </div>
                        </div>
                        <div id="uploadArea" style="display:none">
                            <div class="file-drop" id="fileDrop">
                                <input type="file" id="wordlistFile" accept=".txt,.lst,.dict,.wordlist">
                                <div class="icon">📂</div>
                                <div class="text">Click or drag .txt wordlist</div>
                            </div>
                            <div class="file-info" id="fileInfo"></div>
                        </div>
                        <div id="rockyouStatus" style="font-size:.75em;color:var(--neon);margin-top:6px">
                            <?php echo file_exists(WORDLIST_DIR . 'rockyou.txt') ? '✔ rockyou.txt ready (' . number_format(filesize(WORDLIST_DIR . 'rockyou.txt') / 1048576, 1) . ' MB)' : '⚠ rockyou.txt NOT FOUND in ./wordlists/'; ?>
                        </div>
                    </div>
                    <!-- BRUTEFORCE CONTROLS -->
                    <div id="bfControls" style="display:none">
                        <div class="form-group">
                            <label>CHARSET</label>
                            <select id="charset">
                                <option value="lowercase">a-z (26)</option>
                                <option value="uppercase">A-Z (26)</option>
                                <option value="numbers">0-9 (10)</option>
                                <option value="symbols">Symbols (26)</option>
                                <option value="lowernumbers">a-z + 0-9 (36)</option>
                                <option value="alphanumeric">a-z A-Z 0-9 (62)</option>
                                <option value="all">All printable (72)</option>
                            </select>
                        </div>
                        <div class="inline-row">
                            <div class="form-group"><label>MIN LENGTH</label><input type="number" id="minLen" value="1"
                                    min="1" max="8"></div>
                            <div class="form-group"><label>MAX LENGTH</label><input type="number" id="maxLen" value="4"
                                    min="1" max="8"></div>
                        </div>
                        <div class="form-group">
                            <label>MASK (optional) — ?l=lower ?u=upper ?d=digit ?s=symbol ?a=all</label>
                            <input type="text" id="maskPattern" placeholder="e.g. ?u?l?l?d?d">
                        </div>
                        <div class="warn-box" id="bfWarn">
                            ⚠️ Search space is very large (<span id="comboCount"></span> combinations). This may take a
                            long time.
                            <div class="warn-btns">
                                <button type="button" class="warn-yes" id="bfYes">Continue</button>
                                <button type="button" class="warn-no" id="bfNo">Cancel</button>
                            </div>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary" id="crackBtn">⚡ CRACK</button>
                        <button type="button" class="btn btn-danger" id="stopBtn" disabled>■ ABORT</button>
                    </div>
                </form>
            </div>
            <!-- RIGHT PANEL -->
            <div class="panel">
                <h2>⌬ OUTPUT</h2>
                <div class="console" id="console">
                    <div class="log-line info">> NeonCracker v2.0 ready</div>
                </div>
                <div class="spinner" id="spinner">⟳ Processing...</div>
                <div class="progress-wrap" id="progressWrap">
                    <div class="progress-bar">
                        <div class="progress-fill" id="pFill"></div>
                        <div class="progress-text" id="pText">0%</div>
                    </div>
                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="lbl">Attempts</div>
                            <div class="val" id="sAttempts">0</div>
                        </div>
                        <div class="stat-box">
                            <div class="lbl">Speed</div>
                            <div class="val" id="sSpeed">0 H/s</div>
                        </div>
                        <div class="stat-box">
                            <div class="lbl">Elapsed</div>
                            <div class="val" id="sElapsed">0s</div>
                        </div>
                        <div class="stat-box">
                            <div class="lbl">ETA</div>
                            <div class="val" id="sEta">—</div>
                        </div>
                    </div>
                </div>
                <div class="result-box" id="resultBox">
                    <div class="lbl" id="resultLabel"></div>
                    <div class="pwd" id="resultPwd"></div>
                    <button class="copy-btn" id="copyBtn" style="display:none"
                        onclick="navigator.clipboard.writeText(document.getElementById('resultPwd').textContent)">📋
                        Copy</button>
                </div>
                <div class="stats-row" id="finalStats" style="display:none;margin-top:12px">
                    <div class="stat-box">
                        <div class="lbl">Total Time</div>
                        <div class="val" id="fTime">—</div>
                    </div>
                    <div class="stat-box">
                        <div class="lbl">Attempts</div>
                        <div class="val" id="fAttempts">—</div>
                    </div>
                    <div class="stat-box">
                        <div class="lbl">Avg Speed</div>
                        <div class="val" id="fSpeed">—</div>
                    </div>
                    <div class="stat-box">
                        <div class="lbl">Algorithm</div>
                        <div class="val" id="fAlgo">—</div>
                    </div>
                </div>
            </div>
        </div>
        <footer>NeonCracker // Educational purposes only</footer>
    </div>
</body>

</html>
<script>
    // ==================== MATRIX RAIN ====================
    (function () {
        const c = document.getElementById('matrix'), ctx = c.getContext('2d'); function resize() { c.width = window.innerWidth; c.height = window.innerHeight }
        resize(); window.addEventListener('resize', resize); const chars = '01アイウエオカキクケコサシスセソタチツテト'.split(''); const sz = 14; let cols; let drops; function init() { cols = Math.floor(c.width / sz); drops = Array(cols).fill(1) } init();
        window.addEventListener('resize', init); function draw() {
            ctx.fillStyle = 'rgba(10,10,15,0.05)'; ctx.fillRect(0, 0, c.width, c.height); ctx.fillStyle = '#00ff41'; ctx.font = sz + 'px monospace';
            for (let i = 0; i < cols; i++) { const t = chars[Math.floor(Math.random() * chars.length)]; ctx.fillText(t, i * sz, drops[i] * sz); if (drops[i] * sz > c.height && Math.random() > .975) drops[i] = 0; drops[i]++ }
        }
        setInterval(draw, 45)
    })();

    // ==================== APP STATE ====================
    let uploadedPath = null, cracking = false, evtSrc = null;
    const $ = id => document.getElementById(id);
    const form = $('crackForm'), atkMode = $('attackMode'), dictC = $('dictControls'), bfC = $('bfControls');
    const wlRadios = document.querySelectorAll('input[name="wlSrc"]'), uploadArea = $('uploadArea'), rockyouSt = $('rockyouStatus');
    const wlFile = $('wordlistFile'), fileInfo = $('fileInfo'), con = $('console');
    const crackBtn = $('crackBtn'), stopBtn = $('stopBtn'), spinner = $('spinner');
    const progWrap = $('progressWrap'), pFill = $('pFill'), pText = $('pText');
    const resultBox = $('resultBox');

    // ==================== UI TOGGLES ====================
    atkMode.addEventListener('change', e => {
        dictC.style.display = e.target.value === 'dictionary' ? '' : 'none';
        bfC.style.display = e.target.value === 'bruteforce' ? '' : 'none';
    });
    document.querySelectorAll('.radio-label').forEach(l => {
        l.querySelector('input')?.addEventListener('change', () => {
            document.querySelectorAll('.radio-label').forEach(x => x.classList.remove('active'));
            l.classList.add('active');
            uploadArea.style.display = l.querySelector('input').value === 'upload' ? '' : 'none';
            rockyouSt.style.display = l.querySelector('input').value === 'rockyou' ? '' : 'none';
        });
    });

    // File drop visual
    const fileDrop = $('fileDrop');
    if (fileDrop) {
        fileDrop.addEventListener('dragover', e => { e.preventDefault(); fileDrop.classList.add('dragover') });
        fileDrop.addEventListener('dragleave', () => fileDrop.classList.remove('dragover'));
        fileDrop.addEventListener('drop', e => {
            e.preventDefault(); fileDrop.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleFileUpload(e.dataTransfer.files[0])
        });
    }

    wlFile?.addEventListener('change', e => { if (e.target.files[0]) handleFileUpload(e.target.files[0]) });

    async function handleFileUpload(file) {
        log('> Uploading wordlist...', 'info');
        spinner.classList.add('active');
        const fd = new FormData(); fd.append('action', 'upload_wordlist'); fd.append('wordlist', file);
        try {
            const r = await fetch(location.href, { method: 'POST', body: fd }); const d = await r.json();
            spinner.classList.remove('active');
            if (d.error) { log('> ERROR: ' + d.error, 'error'); return }
            uploadedPath = d.filepath;
            fileInfo.innerHTML = `<b>${file.name}</b> — ${fmtBytes(d.size)} — ${d.lines.toLocaleString()} lines`;
            fileInfo.classList.add('active');
            log('> Wordlist uploaded (will be deleted after cracking)', 'success');
        } catch (e) { spinner.classList.remove('active'); log('> Connection error', 'error') }
    }

    // ==================== FORM SUBMIT ====================
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (cracking) { log('> Already cracking...', 'warning'); return }
        const hash = $('hashInput').value.trim();
        const algo = $('algorithm').value;
        const mode = atkMode.value;
        if (!hash) { log('> Hash required', 'error'); return }

        if (mode === 'dictionary') {
            const src = document.querySelector('input[name="wlSrc"]:checked').value;
            let wlPath = '';
            if (src === 'rockyou') { wlPath = '<?php echo WORDLIST_DIR; ?>rockyou.txt' }
            else {
                if (!uploadedPath) { log('> Upload a wordlist first', 'error'); return }
                wlPath = uploadedPath;
            }
            startSSE(hash, algo, 'dictionary', { wordlist: wlPath, source: src });
        } else {
            const charset = $('charset').value;
            const minL = parseInt($('minLen').value) || 1;
            const maxL = parseInt($('maxLen').value) || 4;
            const mask = $('maskPattern').value.trim();

            // Estimate combos
            const sets = { l: 26, u: 26, d: 10, s: 26, a: 72 };
            const csets = { lowercase: 26, uppercase: 26, numbers: 10, symbols: 26, lowernumbers: 36, alphanumeric: 62, all: 72 };
            let combos = 0;
            if (mask) { combos = 1; for (let i = 0; i < mask.length; i++) { if (mask[i] === '?' && i + 1 < mask.length && sets[mask[i + 1]]) { combos *= sets[mask[i + 1]]; i++ } } }
            else { const cc = csets[charset] || 26; for (let l = minL; l <= maxL; l++)combos += Math.pow(cc, l) }

            if (combos > 50000000) {
                $('comboCount').textContent = combos.toLocaleString();
                $('bfWarn').style.display = 'block';
                const yes = await new Promise(r => {
                    $('bfYes').onclick = () => { $('bfWarn').style.display = 'none'; r(true) };
                    $('bfNo').onclick = () => { $('bfWarn').style.display = 'none'; r(false) };
                });
                if (!yes) { log('> Cancelled by user', 'warning'); return }
            }
            startSSE(hash, algo, 'bruteforce', { charset, min_length: minL, max_length: maxL, mask });
        }
    });

    function startSSE(hash, algo, mode, params) {
        cracking = true; crackBtn.disabled = true; stopBtn.disabled = false;
        progWrap.classList.add('active');
        resultBox.className = 'result-box'; resultBox.style.display = 'none';
        $('finalStats').style.display = 'none';
        log(`> Starting ${mode} attack [${(algo || 'auto').toUpperCase()}]...`, 'info');

        const url = new URL(location.href);
        url.searchParams.set('stream', 'crack');
        url.searchParams.set('hash', hash);
        url.searchParams.set('algorithm', algo);
        url.searchParams.set('mode', mode);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));

        evtSrc = new EventSource(url.toString());
        evtSrc.onmessage = e => {
            const d = JSON.parse(e.data);
            if (d.error) { log('> ERROR: ' + d.error, 'error'); finish(); return }
            if (d.status === 'starting') {
                log(`> Total: ${d.total?.toLocaleString() || '?'} | Algorithm: ${d.algorithm}`, 'info');
            } else if (d.status === 'running') {
                pFill.style.width = d.progress + '%'; pText.textContent = d.progress + '%';
                $('sAttempts').textContent = d.attempts.toLocaleString();
                $('sSpeed').textContent = d.speed.toLocaleString() + ' H/s';
                $('sElapsed').textContent = d.elapsed + 's';
                $('sEta').textContent = d.remaining;
            } else if (d.status === 'finished') {
                evtSrc.close();
                showResult(d);
                finish();
            }
        };
        evtSrc.onerror = () => { log('> SSE connection error', 'error'); finish() };
    }

    function showResult(d) {
        if (d.found) {
            resultBox.className = 'result-box found';
            $('resultLabel').textContent = '🔓 PASSWORD FOUND';
            $('resultPwd').textContent = d.password;
            $('copyBtn').style.display = 'inline-block';
            log('> ✅ CRACKED: ' + d.password, 'success');
        } else {
            resultBox.className = 'result-box notfound';
            $('resultLabel').textContent = '🔒 NOT FOUND';
            $('resultPwd').textContent = 'Exhausted search space';
            $('copyBtn').style.display = 'none';
            log('> ❌ Password not found', 'warning');
        }
        resultBox.style.display = 'block';
        pFill.style.width = '100%'; pText.textContent = '100%';
        log(`> Attempts: ${d.attempts.toLocaleString()} | Time: ${d.time}s | Speed: ${d.speed.toLocaleString()} H/s`, 'info');
        $('fTime').textContent = d.time + 's'; $('fAttempts').textContent = d.attempts.toLocaleString();
        $('fSpeed').textContent = d.speed.toLocaleString() + ' H/s'; $('fAlgo').textContent = d.algorithm || '—';
        $('finalStats').style.display = 'grid';
    }

    function finish() {
        cracking = false; crackBtn.disabled = false; stopBtn.disabled = true;
        spinner.classList.remove('active');
        if (evtSrc) { evtSrc.close(); evtSrc = null }
    }

    stopBtn.addEventListener('click', () => {
        if (cracking) { log('> Aborted by user', 'warning'); finish() }
    });

    function log(text, type = '') {
        const d = document.createElement('div'); d.className = 'log-line ' + (type || ''); d.textContent = text;
        con.appendChild(d); con.scrollTop = con.scrollHeight;
    }

    function fmtBytes(b) {
        if (!b) return '0 B'; const k = 1024; const s = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(b) / Math.log(k)); return (b / Math.pow(k, i)).toFixed(1) + ' ' + s[i];
    }
</script>
</body>

</html>
