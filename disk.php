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

$FIELDS = [
	'mount','device','total','used','free','used_percent','free_percent',
	'inodes_total','inodes_used','inodes_free','inodes_used_percent',
	'read_bytes','write_bytes','read_ops','write_ops'
];
$p = parse_common_params($FIELDS);

$unit = strtolower(trim($_GET['unit'] ?? 'gb'));
if (!in_array($unit, ['bytes','kb','mb','gb'])) error_json(400, "Unidad inválida: '$unit'.", ['available_units' => ['bytes','kb','mb','gb']]);

$div_kb = match($unit) { 'bytes' => 0, 'kb' => 1, 'mb' => 1024, 'gb' => 1048576 };
$div_b  = match($unit) { 'bytes' => 1, 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824 };

$filter_mount = $_GET['mount'] ?? null;

$db = get_db();
$BASE_NUM = ['total','used','free','inodes_total','inodes_used','read_bytes','write_bytes','read_ops','write_ops'];

$where = "ts BETWEEN :from AND :to";
if ($filter_mount !== null) $where .= " AND mount = :mount";
$limit_clause = ($p['limit'] > 0) ? "LIMIT " . $p['limit'] : "LIMIT " . RAW_LIMIT;

if ($p['agg'] === 'raw')
{
	$sql = "SELECT ts, mount, device, " . implode(',', $BASE_NUM) . "
	        FROM disk WHERE $where
	        ORDER BY ts ASC, mount ASC $limit_clause";
}
else
{
	$fn     = strtoupper($p['agg']);
	$bucket = time_bucket_expr($p['interval_sec']);
	$sel    = implode(', ', array_map(fn($c) => "ROUND($fn($c),0) AS $c", $BASE_NUM));
	$sql = "SELECT $bucket AS ts, mount, MAX(device) AS device, $sel
	        FROM disk WHERE $where
	        GROUP BY $bucket, mount ORDER BY ts ASC, mount ASC";
}

$stmt = $db->prepare($sql);
$params = [':from' => $p['from_ts'], ':to' => $p['to_ts']];
if ($filter_mount !== null) $params[':mount'] = $filter_mount;
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($filter_mount !== null && empty($rows)) error_json(404, "No hay datos para mount '$filter_mount' en el rango solicitado.");

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$kb   = fn(int $v): float|int => $div_kb === 0 ? $v * 1024 : ($div_kb === 1 ? $v : round($v / $div_kb, 3));
$b    = fn(int $v): float|int => $div_b === 1 ? $v : round($v / $div_b, 4);
$pct  = fn(int $n, int $d): float => $d > 0 ? round($n / $d * 100, 2) : 0.0;
$add  = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;

$by_mount = [];
foreach ($rows as $r)
{
	$times = ts_format((int)$r['ts']);
	$t  = (int)$r['total'];
	$it = (int)$r['inodes_total'];
	$iu = (int)$r['inodes_used'];
	
	$point = array_filter([
		'ts'                  => $times['ts'],
		'date'                => $times['date'],
		'time'                => $times['time'],
		'mount'               => $add('mount',               $r['mount']),
		'device'              => $add('device',              $r['device']),
		'total'               => $add('total',               $kb($t)),
		'used'                => $add('used',                $kb((int)$r['used'])),
		'free'                => $add('free',                $kb((int)$r['free'])),
		'used_percent'        => $add('used_percent',        $pct((int)$r['used'],  $t)),
		'free_percent'        => $add('free_percent',        $pct((int)$r['free'],  $t)),
		'inodes_total'        => $add('inodes_total',        $it),
		'inodes_used'         => $add('inodes_used',         $iu),
		'inodes_free'         => $add('inodes_free',         $it - $iu),
		'inodes_used_percent' => $add('inodes_used_percent', $pct($iu, $it)),
		'read_bytes'          => $add('read_bytes',          $b((int)$r['read_bytes'])),
		'write_bytes'         => $add('write_bytes',         $b((int)$r['write_bytes'])),
		'read_ops'            => $add('read_ops',            (int)$r['read_ops']),
		'write_ops'           => $add('write_ops',           (int)$r['write_ops']),
	], fn($v) => $v !== null);
	
	$by_mount[$r['mount']][] = $point;
}

$partitions = array_map(fn($mnt, $series) => [
	'mount'  => $mnt,
	'count'  => count($series),
	'series' => $series,
], array_keys($by_mount), $by_mount);

output([
	'status'       => 'ok',
	'metric'       => 'disk',
	'range'        => $_GET['range'] ?? '1h',
	'from'         => ts_to_local($p['from_ts']),
	'to'           => ts_to_local($p['to_ts']),
	'agg'          => $p['agg'],
	'interval_sec' => $p['interval_sec'],
	'unit'         => $unit,
	'partitions'   => $partitions,
]);