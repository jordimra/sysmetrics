<?php
/**
 * Histórico de Sistema General
 * GET /api/history/system.php?range=24h&agg=avg
 *
 * fields: uptime_sec, uptime_human, load_1m, users_logged, procs_total
 *
 * uptime_human es un campo calculado (no guardado en BD):
 *   convierte uptime_sec a "Xd Xh Xm" — solo disponible con agg=raw.
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['uptime_sec','uptime_human','load_1m','users_logged','procs_total'];
$p = parse_common_params($FIELDS);
$db = get_db();

// uptime_human es virtual — se elimina de la query SQL
$sql_fields = array_diff($p['fields_raw'] ?? $FIELDS, ['uptime_human']);
$want_human = ($p['fields_raw'] === null) || in_array('uptime_human', $p['fields_raw'] ?? []);
$cols       = implode(', ', $sql_fields ?: ['uptime_sec','load_1m','users_logged','procs_total']);
$limit_clause = ($p['limit'] > 0) ? "LIMIT " . $p['limit'] : "LIMIT " . RAW_LIMIT;

if ($p['agg'] === 'raw') {
    $sql = "SELECT ts, $cols FROM system_info
            WHERE ts BETWEEN :from AND :to
            ORDER BY ts ASC $limit_clause";
} else {
    $fn     = strtoupper($p['agg']);
    $bucket = time_bucket_expr($p['interval_sec']);
    $parts  = [];
    foreach (($sql_fields ?: ['uptime_sec','load_1m','users_logged','procs_total']) as $c) {
        $parts[] = "ROUND($fn($c), 2) AS $c";
    }
    $sql = "SELECT $bucket AS ts, " . implode(', ', $parts) . "
            FROM system_info
            WHERE ts BETWEEN :from AND :to
            GROUP BY $bucket ORDER BY ts ASC $limit_clause";
}

$stmt = $db->prepare($sql);
$stmt->execute([':from' => $p['from_ts'], ':to' => $p['to_ts']]);

$uptime_human = function (int $sec): string {
    $d = intdiv($sec, 86400); $sec %= 86400;
    $h = intdiv($sec, 3600);  $sec %= 3600;
    $m = intdiv($sec, 60);
    return ($d > 0 ? "{$d}d " : '') . ($h > 0 ? "{$h}h " : '') . "{$m}m";
};

$int_fields = ['uptime_sec','users_logged','procs_total'];

$rows = array_map(function (array $r) use ($int_fields, $want_human, $uptime_human)
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
		$out[$k] = ($v === null) ? null : (in_array($k, $int_fields) ? (int)$v : (float)$v);
	}
    if ($want_human && isset($out['uptime_sec'])) {
        $out['uptime_human'] = $uptime_human((int)$out['uptime_sec']);
    }
    return $out;
}, $stmt->fetchAll());

output([
    'status'       => 'ok',
    'metric'       => 'system',
    'range'        => $_GET['range'] ?? '1h',
    'from'         => ts_to_local($p['from_ts']),
    'to'           => ts_to_local($p['to_ts']),
    'agg'          => $p['agg'],
    'interval_sec' => $p['interval_sec'],
    'count'        => count($rows),
    'data'         => $rows,
]);
