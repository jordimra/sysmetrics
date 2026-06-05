<?php
/**
 * Histórico de Disco
 * GET /api/history/disk.php?range=24h&mount=/&unit=gb
 *
 * fields: mount, device, total, used, free, used_percent, free_percent,
 *         inodes_total, inodes_used, inodes_free, inodes_used_percent,
 *         read_bytes, write_bytes, read_ops, write_ops
 * unit:   bytes | kb | mb | gb  (default: gb)
 * mount:  filtrar por punto de montaje
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['mount','device','total','used','free','used_percent','free_percent',
           'inodes_total','inodes_used','inodes_free','inodes_used_percent',
           'read_bytes','write_bytes','read_ops','write_ops'];
$p = parse_common_params($FIELDS);

$unit = strtolower(trim($_GET['unit'] ?? 'gb'));
if (!in_array($unit, ['bytes','kb','mb','gb'])) {
    error_json(400, "Unidad inválida.", ['available_units' => ['bytes','kb','mb','gb']]);
}

$div_kb = match($unit) { 'bytes' => 0, 'kb' => 1, 'mb' => 1024, 'gb' => 1048576 };
$div_b  = match($unit) { 'bytes' => 1, 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824 };
$kb     = fn(int $v): float|int => $div_kb === 0 ? $v * 1024 : ($div_kb === 1 ? $v : round($v / $div_kb, 3));
$b      = fn(int $v): float|int => $div_b === 1 ? $v : round($v / $div_b, 4);
$pct    = fn(int $n, int $d): float => $d > 0 ? round($n / $d * 100, 2) : 0.0;

$is_table = ($_GET['mode'] ?? '') === 'table';
$mounts   = get_filter_array('mount');

$db = get_db();
$BASE_NUM = ['total','used','free','inodes_total','inodes_used','read_bytes','write_bytes','read_ops','write_ops'];

$params = [':from' => $p['from_ts'], ':to' => $p['to_ts']];
$where  = "ts BETWEEN :from AND :to";

if ($mounts !== null) {
    $placeholders = [];
    foreach ($mounts as $i => $m) {
        $key = ":m$i";
        $placeholders[] = $key;
        $params[$key] = $m;
    }
    $where .= " AND mount IN (" . implode(',', $placeholders) . ")";
}

$order_limit = sql_order_limit($p['limit'], 'mount ASC');

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, mount, device, " . implode(',', $BASE_NUM) . " FROM disk WHERE $where $order_limit";
} else {
    $fn     = strtoupper($p['agg']);
    $bucket = time_bucket_expr($p['interval_sec']);
    $sel    = implode(', ', array_map(fn($c) => "ROUND($fn($c),0) AS $c", $BASE_NUM));
    $sql    = "SELECT $bucket AS ts, mount, MAX(device) AS device, $sel FROM disk WHERE $where GROUP BY $bucket, mount $order_limit";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($is_table) {
    // Usamos DISTINCT ON o GROUP BY para sacar la ÚLTIMA fila de cada mount
    // SQLite: Seleccionamos el último registro para cada mount
    $sql = "SELECT d.* FROM disk d
            INNER JOIN (SELECT mount, MAX(ts) as max_ts FROM disk GROUP BY mount) latest
            ON d.mount = latest.mount AND d.ts = latest.max_ts";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $data = array_map(function($r) use ($kb, $b, $pct) {
        $t = (int)$r['total'];
        $times = ts_format((int)$r['ts']);
        return [
            'ts' => $times['ts'], 'mount' => $r['mount'], 'device' => $r['device'],
            'total' => $kb($t), 'used' => $kb((int)$r['used']), 'used_percent' => $pct((int)$r['used'], $t),
            'read_bytes' => $b((int)$r['read_bytes']), 'write_bytes' => $b((int)$r['write_bytes'])
        ];
    }, $rows);
    
    output(['status' => 'ok', 'data' => $data]);
    exit;
}

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$add  = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;

$by_mount = [];
foreach ($rows as $r) {
    $times = ts_format((int)$r['ts']);
    $t = (int)$r['total']; $it = (int)$r['inodes_total']; $iu = (int)$r['inodes_used'];
    $point = array_filter([
        'ts' => $times['ts'], 'date' => $times['date'], 'time' => $times['time'],
        'mount' => $add('mount', $r['mount']), 'device' => $add('device', $r['device']),
        'total' => $add('total', $kb($t)), 'used' => $add('used', $kb((int)$r['used'])),
        'free' => $add('free', $kb((int)$r['free'])), 'used_percent' => $add('used_percent', $pct((int)$r['used'], $t)),
        'inodes_total' => $add('inodes_total', $it), 'inodes_used' => $add('inodes_used', $iu),
        'read_bytes' => $add('read_bytes', $b((int)$r['read_bytes'])), 'write_bytes' => $add('write_bytes', $b((int)$r['write_bytes']))
    ], fn($v) => $v !== null);
    $by_mount[$r['mount']][] = $point;
}

$partitions = array_map(fn($mnt, $series) => [
    'mount' => $mnt, 'count' => count($series), 'series' => array_reverse($series),
], array_keys($by_mount), $by_mount);

output(['status' => 'ok', 'metric' => 'disk', 'range' => $_GET['range'] ?? '1h', 'partitions' => $partitions]);