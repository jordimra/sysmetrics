-- ============================================================
-- Sistema de métricas históricas — init_db.sql
--
-- Ejecutar UNA vez:
--   sqlite3 /media/novedades/www/api/metrics.db < init_db.sql
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA synchronous  = NORMAL;

-- ── CPU ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cpu (
	ts             INTEGER NOT NULL,   -- Unix epoch (segundos)
	load_percent   REAL,
	load_1m        REAL,
	load_5m        REAL,
	load_15m       REAL,
	freq_mhz       REAL,
	temperature    REAL,
	iowait_percent REAL,
	sys_percent    REAL,
	user_percent   REAL
);
CREATE INDEX IF NOT EXISTS idx_cpu_ts ON cpu(ts);

-- ── Memoria ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS memory (
	ts         INTEGER NOT NULL,
	total      INTEGER,
	used       INTEGER,
	free       INTEGER,
	available  INTEGER,
	cached     INTEGER,
	swap_total INTEGER,
	swap_used  INTEGER
);
CREATE INDEX IF NOT EXISTS idx_memory_ts ON memory(ts);

-- ── Disco ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS disk (
	ts           INTEGER NOT NULL,
	mount        TEXT    NOT NULL,
	device       TEXT,
	total        INTEGER,
	used         INTEGER,
	free         INTEGER,
	inodes_total INTEGER,
	inodes_used  INTEGER,
	read_bytes   INTEGER DEFAULT 0,
	write_bytes  INTEGER DEFAULT 0,
	read_ops     INTEGER DEFAULT 0,
	write_ops    INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_disk_ts    ON disk(ts);
CREATE INDEX IF NOT EXISTS idx_disk_mount ON disk(mount);

-- ── Red ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS network (
	ts         INTEGER NOT NULL,
	iface      TEXT    NOT NULL,
	rx_bytes   INTEGER,
	tx_bytes   INTEGER,
	rx_packets INTEGER,
	tx_packets INTEGER,
	rx_errors  INTEGER,
	tx_errors  INTEGER,
	rx_dropped INTEGER,
	tx_dropped INTEGER
);
CREATE INDEX IF NOT EXISTS idx_network_ts    ON network(ts);
CREATE INDEX IF NOT EXISTS idx_network_iface ON network(iface);

-- ── Procesos ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS processes (
	ts           INTEGER NOT NULL,
	total        INTEGER,
	running      INTEGER,
	sleeping     INTEGER,
	stopped      INTEGER,
	zombie       INTEGER,
	threads      INTEGER,
	cpu_top_pid  INTEGER,
	cpu_top_pct  REAL,
	mem_top_pid  INTEGER,
	mem_top_pct  REAL
);
CREATE INDEX IF NOT EXISTS idx_processes_ts ON processes(ts);

-- ── Sistema general ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_info (
	ts           INTEGER NOT NULL,
	uptime_sec   INTEGER,
	load_1m      REAL,
	users_logged INTEGER,
	procs_total  INTEGER
);
CREATE INDEX IF NOT EXISTS idx_system_ts ON system_info(ts);

-- ── Sockets ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sockets (
	ts              INTEGER NOT NULL,
	tcp_established INTEGER,
	tcp_time_wait   INTEGER,
	tcp_listen      INTEGER
);
CREATE INDEX IF NOT EXISTS idx_sockets_ts ON sockets(ts);

-- ── Docker ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS docker_stats (
	ts             INTEGER NOT NULL,
	container_name TEXT    NOT NULL,
	cpu_percent    REAL,
	mem_bytes      INTEGER DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_docker_ts   ON docker_stats(ts);
CREATE INDEX IF NOT EXISTS idx_docker_name ON docker_stats(container_name);

-- ── Limpieza inicial ─────────────────────────────────────────
-- Comentar si no se quieren borrar los datos
DELETE FROM cpu;
DELETE FROM memory;
DELETE FROM disk;
DELETE FROM network;
DELETE FROM processes;
DELETE FROM system_info;
DELETE FROM sockets;
DELETE FROM docker_stats;