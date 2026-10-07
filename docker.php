<?php
/**
 * Histórico de contenedores Docker
 * GET /api/history/docker.php?range=1h&container=xampp&unit=mb
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['container_name', 'cpu_percent', 'mem_bytes'];
$p = parse_common_params($FIELDS);
$db = get_db();

$unit = strtolower(trim($_GET['unit'] ?? 'mb'));
if (!in_array($unit, ['bytes','kb','mb','gb'])) error_json(400, "Unidad inválida: '$unit'.");
$div_b = match($unit) { 'bytes' => 1, 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824 };

$containers = get_filter_array('container');
$is_table   = ($_GET['mode'] ?? '') === 'table';

$params = [':from' => $p['from_ts'], ':to' => $p['to_ts']];
$where  = "ts BETWEEN :from AND :to";

if ($containers !== null) {
    $placeholders = [];
    foreach ($containers as $i => $c) {
        $key = ":c$i";
        $placeholders[] = $key;
        $params[$key] = $c;
    }
    $where .= " AND container_name IN (" . implode(',', $placeholders) . ")";
}

$order_limit = sql_order_limit($p['limit'], 'container_name ASC');

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, container_name, cpu_percent, mem_bytes
            FROM docker_stats WHERE $where $order_limit";
} else {
    $fn     = strtoupper($p['agg']);
    $bucket = time_bucket_expr($p['interval_sec']);
    $sql = "SELECT $bucket AS ts, container_name,
                   ROUND($fn(cpu_percent), 2) AS cpu_percent,
                   ROUND($fn(mem_bytes), 0) AS mem_bytes
            FROM docker_stats WHERE $where
            GROUP BY $bucket, container_name $order_limit";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$b = fn(int $v): float|int => $unit === 'bytes' ? $v : round($v / $div_b, 4);

if ($is_table) {
    // Igual, obtenemos el estado más reciente de cada contenedor
    $sql = "SELECT d.* FROM docker_stats d
            INNER JOIN (SELECT container_name, MAX(ts) as max_ts FROM docker_stats GROUP BY container_name) latest
            ON d.container_name = latest.container_name AND d.ts = latest.max_ts";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $data = array_map(function($r) use ($b) {
        $times = ts_format((int)$r['ts']);
        return [
            'ts' => $times['ts'],
            'container_name' => $r['container_name'],
            'cpu_percent'    => (float)$r['cpu_percent'],
            'mem_bytes'      => $b((int)$r['mem_bytes'])
        ];
    }, $rows);
    
    output(['status' => 'ok', 'data' => $data]);
    exit;
}

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$add  = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;

$by_container = array_reduce($rows, function (array $carry, array $r) use ($add, $b) {
    $times = ts_format((int)$r['ts']);
    $point = array_filter([
        'ts' => $times['ts'], 'date' => $times['date'], 'time' => $times['time'],
        'container_name' => $add('container_name', $r['container_name']),
        'cpu_percent'    => $add('cpu_percent', $r['cpu_percent'] !== null ? (float)$r['cpu_percent'] : null),
        'mem_bytes'      => $add('mem_bytes', $r['mem_bytes'] !== null ? $b((int)$r['mem_bytes']) : null),
    ], fn($v) => $v !== null);
    
    $carry[$r['container_name']][] = $point;
    return $carry;
}, []);

$containers_out = array_map(fn($name, $series) => [
    'container_name' => $name,
    'count'          => count($series),
    'series'         => array_reverse($series),
], array_keys($by_container), $by_container);

output([
    'status' => 'ok', 'metric' => 'docker', 'range' => $_GET['range'] ?? '1h',
    'containers' => $containers_out
]);