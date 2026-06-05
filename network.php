<?php
/**
 * Histórico de Red
 * GET /api/history/network.php?range=1h&interface=eth0&unit=mb
 *
 * fields: iface, rx_bytes, tx_bytes, rx_packets, tx_packets,
 *         rx_errors, tx_errors, rx_dropped, tx_dropped
 * unit:   bytes | kb | mb | gb  (default: mb)
 * interface: filtrar por nombre de interfaz
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['iface','rx_bytes','tx_bytes','rx_packets','tx_packets','rx_errors','tx_errors','rx_dropped','tx_dropped'];
$p = parse_common_params($FIELDS);

$unit = strtolower(trim($_GET['unit'] ?? 'mb'));
if (!in_array($unit, ['bytes','kb','mb','gb'])) {
    error_json(400, "Unidad inválida.", ['available_units' => ['bytes','kb','mb','gb']]);
}
$divisor = match($unit) { 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824, default => 1 };
$b = fn(int $v): float|int => $unit === 'bytes' ? $v : round($v / $divisor, 4);

$is_table = ($_GET['mode'] ?? '') === 'table';
$ifaces   = get_filter_array('interface');

$db = get_db();
$BASE_NUM = ['rx_bytes','tx_bytes','rx_packets','tx_packets','rx_errors','tx_errors','rx_dropped','tx_dropped'];

$params = [':from' => $p['from_ts'], ':to' => $p['to_ts']];
$where  = "ts BETWEEN :from AND :to";

if ($ifaces !== null) {
    $placeholders = [];
    foreach ($ifaces as $i => $iface) {
        $key = ":iface$i";
        $placeholders[] = $key;
        $params[$key] = $iface;
    }
    $where .= " AND iface IN (" . implode(',', $placeholders) . ")";
}

$order_limit = sql_order_limit($p['limit'], 'iface ASC');

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, iface, " . implode(',', $BASE_NUM) . " FROM network WHERE $where $order_limit";
} else {
    $fn     = strtoupper($p['agg']);
    $bucket = time_bucket_expr($p['interval_sec']);
    $sel    = implode(', ', array_map(fn($c) => "ROUND($fn($c),0) AS $c", $BASE_NUM));
    $sql    = "SELECT $bucket AS ts, iface, $sel FROM network WHERE $where GROUP BY $bucket, iface $order_limit";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($is_table) {
    // 1. Si no hay filtros, mostramos todas. Si hay, usamos IN.
    $where_iface = "";
    if ($ifaces !== null) {
        $quoted_ifaces = array_map(fn($i) => "'$i'", $ifaces);
        $where_iface = " AND iface IN (" . implode(',', $quoted_ifaces) . ")";
    }

    // 2. Consulta de resumen por interfaz (sin bucket de tiempo)
    $sql = "SELECT iface, 
                   AVG(rx_bytes) as rx_bytes, 
                   AVG(tx_bytes) as tx_bytes, 
                   AVG(rx_packets) as rx_packets, 
                   AVG(tx_packets) as tx_packets
            FROM network 
            WHERE ts BETWEEN :from AND :to $where_iface
            GROUP BY iface";

    $stmt = $db->prepare($sql);
    $stmt->execute([':from' => $p['from_ts'], ':to' => $p['to_ts']]);
    $rows = $stmt->fetchAll();

    $data = array_map(function($r) use ($b) {
        return [
            'iface'      => $r['iface'],
            'rx_bytes'   => $b((int)$r['rx_bytes']),
            'tx_bytes'   => $b((int)$r['tx_bytes']),
            'rx_packets' => (int)round($r['rx_packets']),
            'tx_packets' => (int)round($r['tx_packets'])
        ];
    }, $rows);
    
    output(['status' => 'ok', 'data' => $data]);
    exit;
}

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$add  = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;

$by_iface = [];
foreach ($rows as $r) {
    $times = ts_format((int)$r['ts']);
    $point = array_filter([
        'ts' => $times['ts'], 'date' => $times['date'], 'time' => $times['time'],
        'iface' => $add('iface', $r['iface']),
        'rx_bytes' => $add('rx_bytes', $b((int)$r['rx_bytes'])),
        'tx_bytes' => $add('tx_bytes', $b((int)$r['tx_bytes'])),
        'rx_packets' => $add('rx_packets', (int)$r['rx_packets']),
        'tx_packets' => $add('tx_packets', (int)$r['tx_packets']),
        'rx_errors' => $add('rx_errors', (int)$r['rx_errors']),
        'tx_errors' => $add('tx_errors', (int)$r['tx_errors']),
        'rx_dropped' => $add('rx_dropped', (int)$r['rx_dropped']),
        'tx_dropped' => $add('tx_dropped', (int)$r['tx_dropped']),
    ], fn($v) => $v !== null);
    $by_iface[$r['iface']][] = $point;
}

$interfaces = array_map(fn($iface, $series) => [
    'iface'  => $iface,
    'count'  => count($series),
    'series' => array_reverse($series),
], array_keys($by_iface), $by_iface);

output([
    'status' => 'ok', 'metric' => 'network', 'range' => $_GET['range'] ?? '1h',
    'from' => ts_to_local($p['from_ts']), 'to' => ts_to_local($p['to_ts']),
    'agg' => $p['agg'], 'interval_sec' => $p['interval_sec'], 'unit' => $unit,
    'interfaces' => $interfaces,
]);