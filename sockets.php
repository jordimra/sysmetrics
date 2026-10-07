<?php
/**
 * Histórico de Sockets TCP
 * GET /api/history/sockets.php?range=1h&agg=avg
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['tcp_established', 'tcp_time_wait', 'tcp_listen'];
$p = parse_common_params($FIELDS);
$db = get_db();

$cols = implode(', ', $p['fields_raw'] ?? $FIELDS);
$limit_clause = ($p['limit'] > 0) ? "LIMIT " . $p['limit'] : "LIMIT " . RAW_LIMIT;

if ($p['agg'] === 'raw')
{
	$sql = "SELECT ts, $cols FROM sockets
	        WHERE ts BETWEEN :from AND :to
	        ORDER BY ts ASC $limit_clause";
}
else
{
	$bucket = time_bucket_expr($p['interval_sec']);
	$sel    = agg_select($p['agg'], $p['fields_raw'] ?? $FIELDS);
	$sql = "SELECT $bucket AS ts, $sel FROM sockets
	        WHERE ts BETWEEN :from AND :to
	        GROUP BY $bucket ORDER BY ts ASC $limit_clause";
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
	
	$filtered_r = array_filter($r, fn($k) => $k !== 'ts', ARRAY_FILTER_USE_KEY);
	$mapped_r   = array_map(fn($v) => $v !== null ? (int)$v : null, $filtered_r);
	
	return array_merge($out, $mapped_r);
}, $stmt->fetchAll());

output([
	'status'       => 'ok',
	'metric'       => 'sockets',
	'range'        => $_GET['range'] ?? '1h',
	'from'         => ts_to_local($p['from_ts']),
	'to'           => ts_to_local($p['to_ts']),
	'agg'          => $p['agg'],
	'interval_sec' => $p['interval_sec'],
	'count'        => count($rows),
	'data'         => $rows,
]);