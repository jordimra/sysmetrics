<?php
/**
 * Snapshot de procesos en tiempo real — equivalente a "top"
 *
 * GET /api/processes.php
 * GET /api/processes.php?sort=cpu          — ordenar por: cpu|mem|pid|name|threads (default: cpu)
 * GET /api/processes.php?order=desc        — asc | desc (default: desc)
 * GET /api/processes.php?limit=20          — máx procesos a devolver (default: 25, max: 500)
 * GET /api/processes.php?filter=nginx      — filtrar por nombre (substring)
 * GET /api/processes.php?state=R           — filtrar por estado: R|S|D|T|Z
 * GET /api/processes.php?fields=pid,name,cpu_percent,mem_percent
 *
 * Campos disponibles por proceso:
 *   pid, ppid, name, cmdline, state, state_name,
 *   cpu_percent, mem_percent, mem_rss_kb, mem_vsz_kb,
 *   threads, priority, nice, user,
 *   cpu_time_sec, started_sec_ago
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

// ── Helpers ───────────────────────────────────────────────────────────────────

function rf(string $path): string {
    return is_readable($path) ? trim(file_get_contents($path)) : '';
}

function error_out(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_PRETTY_PRINT);
    exit;
}

// Estado del sistema: total CPU jiffies y uptime para calcular % CPU por proceso
function system_cpu_total(): array {
    $line = '';
    $f = fopen('/proc/stat', 'r');
    if ($f) { $line = fgets($f); fclose($f); }
    $parts = array_slice(preg_split('/\s+/', trim($line)), 1);
    return ['total' => array_sum(array_map('intval', $parts))];
}

function system_uptime(): float {
    return (float) explode(' ', rf('/proc/uptime'))[0];
}

function hz(): int {
    // USER_HZ — casi siempre 100 en Linux
    static $hz = null;
    if ($hz === null) {
        $hz = (int)(shell_exec('getconf CLK_TCK 2>/dev/null') ?: 100);
    }
    return $hz;
}

$STATE_NAMES = [
    'R' => 'Running',
    'S' => 'Sleeping',
    'D' => 'Waiting (uninterruptible)',
    'T' => 'Stopped',
    'Z' => 'Zombie',
    'I' => 'Idle',
    'X' => 'Dead',
];

// ── Parámetros ────────────────────────────────────────────────────────────────

$ALLOWED_FIELDS = [
    'pid','ppid','name','cmdline','state','state_name',
    'cpu_percent','mem_percent','mem_rss_kb','mem_vsz_kb',
    'threads','priority','nice','user',
    'cpu_time_sec','started_sec_ago',
];

$SORT_FIELDS = ['cpu','mem','pid','name','threads','mem_rss_kb'];

$sort   = strtolower($_GET['sort']  ?? 'cpu');
$order  = strtolower($_GET['order'] ?? 'desc');
$limit  = min(500, max(1, (int)($_GET['limit'] ?? 25)));
$filter = $_GET['filter'] ?? null;
$state_filter = isset($_GET['state']) ? strtoupper($_GET['state']) : null;

if (!in_array($sort, $SORT_FIELDS)) {
    error_out(400, "sort inválido. Valores: " . implode(', ', $SORT_FIELDS));
}
if (!in_array($order, ['asc','desc'])) {
    error_out(400, "order debe ser 'asc' o 'desc'.");
}

$fields_filter = null;
if (!empty($_GET['fields'])) {
    $req = array_map('trim', explode(',', $_GET['fields']));
    $inv = array_diff($req, $ALLOWED_FIELDS);
    if (!empty($inv)) {
        error_out(400, "Campos inválidos: " . implode(', ', $inv) . ". Disponibles: " . implode(', ', $ALLOWED_FIELDS));
    }
    $fields_filter = array_flip($req);
}

// ── Leer memoria total del sistema ────────────────────────────────────────────

$mem_total_kb = (int) shell_exec("awk '/^MemTotal/{print $2}' /proc/meminfo 2>/dev/null") ?: 1;

// ── Leer /proc/[pid]/stat y /proc/[pid]/status para cada proceso ──────────────

$uptime   = system_uptime();
$hz       = hz();
$boot_sec = time() - (int)$uptime;

$processes = [];

foreach (glob('/proc/[0-9]*') as $proc_dir) {
    $pid = (int) basename($proc_dir);

    // /proc/[pid]/stat — columnas según man proc
    $stat_raw = rf("$proc_dir/stat");
    if ($stat_raw === '') continue;

    // El nombre puede contener espacios y paréntesis — extraer con regex
    if (!preg_match('/^(\d+)\s+\((.+)\)\s+(\S+)\s+(.+)$/', $stat_raw, $m)) continue;
    [, $stat_pid, $stat_name, $stat_state, $stat_rest] = $m;
    $stat = explode(' ', trim($stat_rest));

    $ppid      = (int)($stat[0]  ?? 0);
    $utime     = (int)($stat[11] ?? 0);   // user jiffies
    $stime     = (int)($stat[12] ?? 0);   // system jiffies
    $priority  = (int)($stat[15] ?? 0);
    $nice      = (int)($stat[16] ?? 0);
    $threads   = (int)($stat[17] ?? 1);
    $starttime = (int)($stat[19] ?? 0);   // jiffies desde boot

    $total_jiffies  = $utime + $stime;
    $cpu_time_sec   = round($total_jiffies / $hz, 2);
    $started_at     = $boot_sec + intdiv($starttime, $hz);
    $started_ago    = max(0, time() - $started_at);
    $cpu_percent    = $started_ago > 0
        ? round(($total_jiffies / $hz) / $started_ago * 100, 2)
        : 0.0;

    // /proc/[pid]/status — para mem y usuario
    $status_raw = rf("$proc_dir/status");
    $vm_rss_kb  = 0;
    $vm_vsz_kb  = 0;
    $uid        = 0;
    foreach (explode("\n", $status_raw) as $line) {
        if (str_starts_with($line, 'VmRSS:'))  $vm_rss_kb = (int)preg_replace('/\D/', '', $line);
        if (str_starts_with($line, 'VmSize:')) $vm_vsz_kb = (int)preg_replace('/\D/', '', $line);
        if (str_starts_with($line, 'Uid:'))    $uid       = (int)explode("\t", $line)[1];
    }

    $mem_percent = round($vm_rss_kb / $mem_total_kb * 100, 2);

    // Usuario: intentar resolver UID → nombre
    static $uid_cache = [];
    if (!isset($uid_cache[$uid])) {
        $pw = posix_getpwuid($uid);
        $uid_cache[$uid] = $pw ? $pw['name'] : (string)$uid;
    }
    $user = $uid_cache[$uid];

    // cmdline (argumentos completos)
    $cmdline_raw = rf("$proc_dir/cmdline");
    $cmdline = $cmdline_raw !== ''
        ? implode(' ', explode("\0", rtrim($cmdline_raw, "\0")))
        : "[$stat_name]";  // kernel thread

    $state       = $stat_state;
    $state_name  = $STATE_NAMES[$state] ?? 'Unknown';

    // Filtros
    if ($filter !== null && !str_contains(strtolower($stat_name), strtolower($filter))
                         && !str_contains(strtolower($cmdline),   strtolower($filter))) {
        continue;
    }
    if ($state_filter !== null && $state !== $state_filter) continue;

    $proc = [
        'pid'            => $pid,
        'ppid'           => $ppid,
        'name'           => $stat_name,
        'cmdline'        => $cmdline,
        'state'          => $state,
        'state_name'     => $state_name,
        'cpu_percent'    => $cpu_percent,
        'mem_percent'    => $mem_percent,
        'mem_rss_kb'     => $vm_rss_kb,
        'mem_vsz_kb'     => $vm_vsz_kb,
        'threads'        => $threads,
        'priority'       => $priority,
        'nice'           => $nice,
        'user'           => $user,
        'cpu_time_sec'   => $cpu_time_sec,
        'started_sec_ago'=> $started_ago,
    ];

    if ($fields_filter !== null) {
        $proc = array_intersect_key($proc, $fields_filter);
    }

    $processes[] = $proc;
}

// ── Ordenar ───────────────────────────────────────────────────────────────────

$sort_key = match($sort) {
    'cpu'     => 'cpu_percent',
    'mem'     => 'mem_percent',
    'name'    => 'name',
    default   => $sort,
};

usort($processes, function ($a, $b) use ($sort_key, $order) {
    $va = $a[$sort_key] ?? 0;
    $vb = $b[$sort_key] ?? 0;
    $cmp = is_string($va) ? strcmp($va, $vb) : ($va <=> $vb);
    return $order === 'asc' ? $cmp : -$cmp;
});

$total_count = count($processes);
$processes   = array_slice($processes, 0, $limit);

// ── Resumen global (cabecera estilo top) ──────────────────────────────────────

$states = array_count_values(array_column($processes, 'state') ?: []);
// Contar sobre todos los procesos (antes del slice)
// Releer rápido /proc/[pid]/stat solo para estados
$all_states = ['R'=>0,'S'=>0,'D'=>0,'T'=>0,'Z'=>0,'I'=>0];
foreach (glob('/proc/[0-9]*/stat') as $sf) {
    $s = rf($sf);
    if (preg_match('/^\d+ \(.+\) (\S)/', $s, $m)) {
        $k = $m[1];
        if (isset($all_states[$k])) $all_states[$k]++;
    }
}

$loads = explode(' ', rf('/proc/loadavg'));

echo json_encode([
	'status'    => 'ok',
	'timestamp' => date('Y-m-d\TH:i:sP'),
	'date'      => date('Y-m-d'),
	'time'      => date('H:i:s'),
	'summary'   => [
        'total_processes' => array_sum($all_states),
        'running'         => $all_states['R'],
        'sleeping'        => $all_states['S'],
        'waiting'         => $all_states['D'],
        'stopped'         => $all_states['T'],
        'zombie'          => $all_states['Z'],
        'load_1m'         => (float)($loads[0] ?? 0),
        'load_5m'         => (float)($loads[1] ?? 0),
        'load_15m'        => (float)($loads[2] ?? 0),
    ],
    'query' => [
        'sort'   => $sort,
        'order'  => $order,
        'limit'  => $limit,
        'filter' => $filter,
        'state'  => $state_filter,
        'total_matched' => $total_count,
        'returned'      => count($processes),
    ],
    'processes' => $processes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
