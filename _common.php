<?php
/**
 * _common.php — Funciones compartidas por todos los endpoints de histórico.
 */

$sys_web = getenv('SYSMETRICS_WEB');
if (!$sys_web) error_json(500, 'La variable de entorno SYSMETRICS_WEB no está definida en el sistema.');

define('DB_PATH', $sys_web . '/metrics.db');

const RANGES = [
    '5m'  =>    5 * 60,
    '15m' =>   15 * 60,
    '30m' =>   30 * 60,
    '1h'  =>    3600,
    '3h'  =>  3 * 3600,
    '6h'  =>  6 * 3600,
    '12h' => 12 * 3600,
    '24h' => 24 * 3600,
    '2d'  =>  2 * 86400,
    '3d'  =>  3 * 86400,
    '7d'  =>  7 * 86400,
];

const AGG_FUNCS = ['avg', 'min', 'max', 'raw'];
const RAW_LIMIT = 2000;

// ── Headers ───────────────────────────────────────────────────────────────────

function set_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
}

// ── BD ────────────────────────────────────────────────────────────────────────

function get_db(): PDO {
    if (!file_exists(DB_PATH)) {
        error_json(503, 'Base de datos no encontrada en ' . DB_PATH . '. ¿Está corriendo el collector?');
    }
    $pdo = new PDO('sqlite:' . DB_PATH, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA query_only=ON;');
    return $pdo;
}

// ── Timestamp ─────────────────────────────────────────────────────────────────

/**
 * Devuelve un array con el timestamp ISO, la fecha y la hora.
 */
function ts_format(int $epoch): array
{
	$dt = (new DateTimeImmutable('@' . $epoch))
		->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));

	return [
		'ts'   => $dt->format('Y-m-d\TH:i:sP'),
		'date' => $dt->format('Y-m-d'),
		'time' => $dt->format('H:i:s')
	];
}

function ts_to_local(int $epoch): string
{
	return ts_format($epoch)['ts'];
}

// ── Parámetros comunes ────────────────────────────────────────────────────────

function parse_common_params(array $allowed_fields): array {
    $range_key = $_GET['range'] ?? '1h';
    if (!isset(RANGES[$range_key])) {
        error_json(400, "Rango inválido: '$range_key'.", ['available_ranges' => array_keys(RANGES)]);
    }
    $range_sec = RANGES[$range_key];

    $now     = time();
    $from_ts = parse_time($_GET['from'] ?? null) ?? ($now - $range_sec);
    $to_ts   = parse_time($_GET['to']   ?? null) ?? $now;

    if ($from_ts >= $to_ts) {
        error_json(400, "'from' debe ser anterior a 'to'.");
    }

    $agg = strtolower(trim($_GET['agg'] ?? 'avg'));
    if (!in_array($agg, AGG_FUNCS)) {
        error_json(400, "Función de agregación inválida: '$agg'.", ['available' => AGG_FUNCS]);
    }

    $span         = $to_ts - $from_ts;
    $interval_sec = parse_interval($_GET['interval'] ?? 'auto', $span);

    $fields_raw = null;
    if (!empty($_GET['fields'])) {
        $requested = array_map('trim', explode(',', $_GET['fields']));
        $invalid   = array_diff($requested, $allowed_fields);
        if (!empty($invalid)) {
            error_json(400, 'Campos inválidos: ' . implode(', ', $invalid) . '.', ['available_fields' => $allowed_fields]);
        }
        $fields_raw = $requested;
    }

    $limit = (int)($_GET['limit'] ?? 0);
    if ($limit < 0) error_json(400, "El 'limit' debe ser un número positivo.");

    return [
        'range_sec'    => $range_sec,
        'agg'          => $agg,
        'interval_sec' => $interval_sec,
        'from_ts'      => $from_ts,
        'to_ts'        => $to_ts,
        'fields_raw'   => $fields_raw,
        'limit'        => $limit,
    ];
}

// ── Helpers internos ──────────────────────────────────────────────────────────

function parse_time(?string $val): ?int {
    if ($val === null) return null;
    if (ctype_digit($val)) return (int) $val;
    $ts = strtotime($val);
    return $ts !== false ? $ts : null;
}

function parse_interval(string $raw, int $span): int {
    if ($raw === 'auto') {
        return match(true) {
            $span <=    5 * 60  =>  30,
            $span <=   30 * 60  =>  60,
            $span <=    3600    => 120,
            $span <=  6 * 3600  => 300,
            $span <= 24 * 3600  => 900,
            $span <=  3 * 86400 => 1800,
            default             => 3600,
        };
    }
    if (preg_match('/^(\d+)(s|m|h|d)?$/i', trim($raw), $m)) {
        $mult = match(strtolower($m[2] ?? 's')) { 'm' => 60, 'h' => 3600, 'd' => 86400, default => 1 };
        return max(1, (int)$m[1] * $mult);
    }
    error_json(400, "Intervalo inválido: '$raw'. Usa 'auto', '30s', '5m', '1h'...");
}

/**
 * Bucket temporal en SQL. La BD guarda epoch INTEGER, así que la
 * aritmética es directa — sin conversiones de string.
 * Devuelve el epoch del inicio del bucket (PHP luego llama ts_to_local).
 */
function time_bucket_expr(int $interval_sec): string {
    return "(ts / {$interval_sec}) * {$interval_sec}";
}

function agg_select(string $agg, array $numeric_cols, array $text_cols = []): string {
    $fn = strtoupper($agg === 'raw' ? 'AVG' : $agg);
    $parts = [];
    foreach ($numeric_cols as $col) {
        $parts[] = "ROUND($fn($col), 3) AS $col";
    }
    foreach ($text_cols as $col) {
        $parts[] = "MAX($col) AS $col";
    }
    return implode(', ', $parts);
}

// ── Error / Output ────────────────────────────────────────────────────────────

function error_json(int $code, string $msg, array $extra = []): never {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function output(array $payload): never {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
