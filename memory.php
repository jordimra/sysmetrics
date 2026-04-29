<?php
/**
 * Histórico de Memoria
 * GET /api/history/memory.php?range=6h&agg=avg&unit=mb
 *
 * fields: total, used, free, available, cached,
 *         swap_total, swap_used, used_percent, swap_used_percent
 * unit:   kb | mb | gb  (default: mb)
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['total','used','free','available','cached','swap_total','swap_used','used_percent','swap_used_percent'];
$p = parse_common_params($FIELDS);

$unit = strtolower(trim($_GET['unit'] ?? 'mb'));
if (!in_array($unit, ['kb','mb','gb'])) {
    error_json(400, "Unidad inválida: '$unit'.", ['available_units' => ['kb','mb','gb']]);
}
$divisor = match($unit) { 'mb' => 1024, 'gb' => 1048576, default => 1 };

$db = get_db();
$BASE = ['total','used','free','available','cached','swap_total','swap_used'];

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, " . implode(',', $BASE) . " FROM memory
            WHERE ts BETWEEN :from AND :to
            ORDER BY ts ASC LIMIT " . RAW_LIMIT;
} else {
    $fn     = strtoupper($p['agg']);
    $bucket = time_bucket_expr($p['interval_sec']);
    $sel    = implode(', ', array_map(fn($c) => "ROUND($fn($c),0) AS $c", $BASE));
    $sql = "SELECT $bucket AS ts, $sel FROM memory
            WHERE ts BETWEEN :from AND :to
            GROUP BY $bucket ORDER BY ts ASC";
}

$stmt = $db->prepare($sql);
$stmt->execute([':from' => $p['from_ts'], ':to' => $p['to_ts']]);

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$kb   = fn(int $v): float|int => $divisor === 1 ? $v : round($v / $divisor, 3);
$pct  = fn(int $n, int $d): float => $d > 0 ? round($n / $d * 100, 2) : 0.0;

$rows = array_map(function (array $r) use ($want, $kb, $pct)
{
	$add = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;
	
	$times = ts_format((int)$r['ts']);
	$t = (int)$r['total']; $st = (int)$r['swap_total'];
	return array_filter([
		'ts'                 => $times['ts'],
		'date'               => $times['date'],
		'time'               => $times['time'],
		'total'              => $add('total',              $kb($t)),
        'used'               => $add('used',               $kb((int)$r['used'])),
        'free'               => $add('free',               $kb((int)$r['free'])),
        'available'          => $add('available',          $kb((int)$r['available'])),
        'cached'             => $add('cached',             $kb((int)$r['cached'])),
        'swap_total'         => $add('swap_total',         $kb($st)),
        'swap_used'          => $add('swap_used',          $kb((int)$r['swap_used'])),
        'used_percent'       => $add('used_percent',       $pct((int)$r['used'],      $t)),
        'swap_used_percent'  => $add('swap_used_percent',  $pct((int)$r['swap_used'], $st)),
    ], fn($v) => $v !== null);
}, $stmt->fetchAll());

output([
    'status'       => 'ok',
    'metric'       => 'memory',
    'range'        => $_GET['range'] ?? '1h',
    'from'         => ts_to_local($p['from_ts']),
    'to'           => ts_to_local($p['to_ts']),
    'agg'          => $p['agg'],
    'interval_sec' => $p['interval_sec'],
    'unit'         => $unit,
    'count'        => count($rows),
    'data'         => $rows,
]);
