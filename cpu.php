<?php
/**
 * Histórico de CPU
 * GET /api/history/cpu.php?range=1h&agg=avg&fields=load_percent,temperature
 *
 * fields: load_percent, load_1m, load_5m, load_15m, freq_mhz, temperature
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['load_percent','load_1m','load_5m','load_15m','freq_mhz','temperature'];
$p = parse_common_params($FIELDS);
$db = get_db();

$cols = implode(', ', $p['fields_raw'] ?? $FIELDS);

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, $cols FROM cpu
            WHERE ts BETWEEN :from AND :to
            ORDER BY ts ASC LIMIT " . RAW_LIMIT;
} else {
    $bucket = time_bucket_expr($p['interval_sec']);
    $sel    = agg_select($p['agg'], $p['fields_raw'] ?? $FIELDS);
    $sql = "SELECT $bucket AS ts, $sel FROM cpu
            WHERE ts BETWEEN :from AND :to
            GROUP BY $bucket ORDER BY ts ASC";
}

$stmt = $db->prepare($sql);
$stmt->execute([':from' => $p['from_ts'], ':to' => $p['to_ts']]);

$rows = array_map(function (array $r)
{
	$times = ts_format((int)$r['ts']);
	$out = [
		'ts'   => $times['ts'],
		'date' => $times['date'],
		'time' => $times['time']
	];
	foreach ($r as $k => $v)
	{
		if ($k === 'ts') continue;
		$out[$k] = $v !== null ? (float)$v : null;
	}
	return $out;
}, $stmt->fetchAll());

output([
    'status'       => 'ok',
    'metric'       => 'cpu',
    'range'        => $_GET['range'] ?? '1h',
    'from'         => ts_to_local($p['from_ts']),
    'to'           => ts_to_local($p['to_ts']),
    'agg'          => $p['agg'],
    'interval_sec' => $p['interval_sec'],
    'count'        => count($rows),
    'data'         => $rows,
]);
