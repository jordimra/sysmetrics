<?php
/**
 * Histórico de contenedores Docker
 * GET /api/history/docker.php?range=1h&container=xampp
 */
require __DIR__ . '/_common.php';
set_headers();

$FIELDS = ['container_name', 'cpu_percent', 'mem_usage_str'];
$p = parse_common_params($FIELDS);
$db = get_db();

$filter_container = $_GET['container'] ?? null;
$where = "ts BETWEEN :from AND :to";
if ($filter_container !== null) $where .= " AND container_name = :container";

$limit_clause = ($p['limit'] > 0) ? "LIMIT " . $p['limit'] : "LIMIT " . RAW_LIMIT;

$want = $p['fields_raw'] ? array_flip($p['fields_raw']) : null;
$add  = fn(string $k, mixed $v) => ($want === null || isset($want[$k])) ? $v : null;

if ($p['agg'] === 'raw')
{
	$sql = "SELECT ts, container_name, cpu_percent, mem_usage_str
	        FROM docker_stats WHERE $where
	        ORDER BY ts ASC, container_name ASC $limit_clause";
}
else
{
	$fn     = strtoupper($p['agg']);
	$bucket = time_bucket_expr($p['interval_sec']);
	
	$sql = "SELECT $bucket AS ts, container_name,
	               ROUND($fn(cpu_percent), 2) AS cpu_percent,
	               MAX(mem_usage_str) AS mem_usage_str
	        FROM docker_stats WHERE $where
	        GROUP BY $bucket, container_name
	        ORDER BY ts ASC, container_name ASC $limit_clause";
}

$stmt = $db->prepare($sql);
$params = [':from' => $p['from_ts'], ':to' => $p['to_ts']];
if ($filter_container !== null) $params[':container'] = $filter_container;
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($filter_container !== null && empty($rows)) error_json(404, "No hay datos para el contenedor '$filter_container'.");

// Agrupar por nombre de contenedor usando reducción funcional
$by_container = array_reduce($rows, function (array $carry, array $r) use ($add)
{
	$times = ts_format((int)$r['ts']);
	$point = array_filter([
		'ts'             => $times['ts'],
		'date'           => $times['date'],
		'time'           => $times['time'],
		'container_name' => $add('container_name', $r['container_name']),
		'cpu_percent'    => $add('cpu_percent',    $r['cpu_percent'] !== null ? (float)$r['cpu_percent'] : null),
		'mem_usage_str'  => $add('mem_usage_str',  $r['mem_usage_str']),
	], fn($v) => $v !== null);
	
	$carry[$r['container_name']][] = $point;
	return $carry;
}, []);

$containers = array_map(fn($name, $series) => [
	'container_name' => $name,
	'count'          => count($series),
	'series'         => $series,
], array_keys($by_container), $by_container);

output([
	'status'       => 'ok',
	'metric'       => 'docker',
	'range'        => $_GET['range'] ?? '1h',
	'from'         => ts_to_local($p['from_ts']),
	'to'           => ts_to_local($p['to_ts']),
	'agg'          => $p['agg'],
	'interval_sec' => $p['interval_sec'],
	'