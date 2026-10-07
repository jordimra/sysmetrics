# 🖥️ System Stats REST API — Histórico y Tiempo Real

API REST minimalista en PHP puro para consultar métricas históricas del sistema operativo (almacenadas en SQLite) y procesos en tiempo real. 

No requiere frameworks. Solo PHP, acceso a `/proc` (para procesos) y acceso a la base de datos de métricas generada por el colector.

---

## 📁 Estructura

```text
api/
├── _common.php    → Motor central: parseo de params, agregación y conexión BD.
├── cpu.php        → Histórico de uso, carga y temperatura de CPU.
├── disk.php       → Histórico de particiones (espacio e inodos).
├── memory.php     → Histórico de RAM y Swap.
├── network.php    → Histórico de tráfico por interfaces de red.
├── system.php     → Histórico global (uptime, load, usuarios, procesos totales).
└── processes.php  → Endpoint en TIEMPO REAL (equivalente a `top`).
```

---

## 🚀 Instalación y Configuración

```bash
# Con PHP built-in server (desarrollo)
cd api/
php -S 0.0.0.0:41062

# O colocar en tu DocumentRoot (ej: /www/api/)
```

**Variables de Entorno y Base de Datos:**
El sistema de recolección (`collector.sh`) y la API buscan la base de datos y sus propios directorios basándose en variables de entorno *system-wide*:
* `SYSMETRICS`: Ruta base absoluta de los scripts del sistema (donde también se guardará `metrics.db`).
* `SYSMETRICS_WEB`: Ruta base web.

**1. Hacer las variables permanentes en el sistema:**
Para que sobrevivan a los reinicios y sean la única fuente de verdad, añádelas al archivo `/etc/environment`. 

```bash
sudo nano /etc/environment
```
Añade tus rutas al final del archivo (sin usar la palabra `export`):
```text
SYSMETRICS="/media/novedades/www/api"
SYSMETRICS_WEB="/www/api"
```

**2. Automatización con Cron:**
Como `cron` arranca en un entorno aislado que no lee `/etc/environment` por defecto, hay que forzar la lectura y exportación de las variables en la propia instrucción del crontab. 

Ejecuta `crontab -e` y añade las siguientes tareas:
```text
# Recolección cada 30 segundos forzando la exportación del entorno
* * * * * set -a; . /etc/environment; "$SYSMETRICS/collector.sh"
* * * * * sleep 30 && set -a; . /etc/environment; "$SYSMETRICS/collector.sh"
```

---

## ⚙️ Parámetros Comunes (Histórico)

Todos los endpoints (excepto `processes.php`) heredan los siguientes parámetros GET de `_common.php`:

| Param | Valores Permitidos | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `range` | `5m`, `15m`, `1h`, `12h`, `24h`, `7d`... | `1h` | Ventana de tiempo a consultar. |
| `from` | Epoch Unix o fecha (ej: `2026-04-28`) | *calculado* | Inicio exacto del rango temporal. |
| `to` | Epoch Unix o fecha | `now()` | Fin exacto del rango temporal. |
| `agg` | `avg`, `min`, `max`, `raw` | `avg` | Función de agregación. `raw` devuelve datos sin agrupar (máx 2000). |
| `interval` | `auto`, `30s`, `5m`, `1h`... | `auto` | Tamaño del "bucket" o franja de agrupación temporal. |
| `fields` | *Depende del endpoint* | *Todos* | Lista separada por comas de columnas a extraer. |

---

## 📡 Endpoints Históricos

### `GET /cpu.php`
Histórico de CPU y temperatura.
* **Campos:** `load_percent`, `load_1m`, `load_5m`, `load_15m`, `freq_mhz`, `temperature`

**Ejemplo de Petición:**
```bash
curl "http://localhost:41062/www/api/cpu.php?range=1h&agg=avg&fields=load_percent,temperature"
```

**Respuesta:**
```json
{
	"status": "ok",
	"metric": "cpu",
	"range": "1h",
	"from": "2026-04-29T08:49:52+02:00",
	"to": "2026-04-29T09:49:52+02:00",
	"agg": "avg",
	"interval_sec": 120,
	"count": 1,
	"data": [
		{
			"ts": "2026-04-29T09:40:00+02:00",
			"date": "2026-04-29",
			"time": "09:40:00",
			"load_percent": 23.45,
			"temperature": 58.0
		}
	]
}
```

---

### `GET /disk.php`
Histórico de capacidad e inodos por partición.
* **Campos:** `mount`, `device`, `total`, `used`, `free`, `used_percent`, `free_percent`, `inodes_total`...
* **Params Extra:** `mount` (ej. `/`), `unit` (`kb`, `mb`, `gb` - defecto: `gb`).

**Ejemplo de Petición:**
```bash
curl "http://localhost:41062/www/api/disk.php?unit=gb&mount=/"
```

**Respuesta:**
```json
{
	"status": "ok",
	"metric": "disk",
	"range": "1h",
	"from": "2026-04-29T08:49:52+02:00",
	"to": "2026-04-29T09:49:52+02:00",
	"agg": "avg",
	"interval_sec": 120,
	"unit": "gb",
	"partitions": [
		{
			"mount": "/",
			"count": 1,
			"series": [
				{
					"ts": "2026-04-29T09:40:00+02:00",
					"date": "2026-04-29",
					"time": "09:40:00",
					"device": "/dev/sda1",
					"total": 117.42,
					"used": 52.18,
					"used_percent": 44.44
				}
			]
		}
	]
}
```

---

### `GET /memory.php`
Histórico de RAM y Swap.
* **Campos:** `total`, `used`, `free`, `available`, `cached`, `swap_total`, `swap_used`, `used_percent`, `swap_used_percent`
* **Params Extra:** `unit` (`kb`, `mb`, `gb` - defecto: `mb`).

---

### `GET /network.php`
Histórico de tráfico de red. Note que `rx_bytes` y `tx_bytes` son acumulativos desde el arranque.
* **Campos:** `iface`, `rx_bytes`, `tx_bytes`, `rx_packets`, `tx_packets`, `rx_errors`, `rx_dropped`...
* **Params Extra:** `interface` (ej. `eth0`), `unit` (`bytes`, `kb`, `mb`, `gb` - defecto: `mb`).

---

### `GET /system.php`
Histórico de métricas generales del OS.
* **Campos:** `uptime_sec`, `load_1m`, `users_logged`, `procs_total`, `uptime_human` (solo accesible con `agg=raw`).

---

## ⚡ Endpoint en Tiempo Real

### `GET /processes.php`
Instantánea del estado de los procesos (estilo `top`). Lee directamente de `/proc` en el momento de la petición. No usa la base de datos histórica.

| Param | Valores Permitidos | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `sort` | `cpu`, `mem`, `pid`, `name`, `threads`... | `cpu` | Criterio de ordenación. |
| `order` | `asc`, `desc` | `desc` | Dirección del orden. |
| `limit` | Entero (max: 500) | `25` | Número máximo de procesos a devolver. |
| `filter` | Texto (substring) | `null` | Busca por nombre de proceso o cmdline. |
| `state` | `R`, `S`, `D`, `T`, `Z` | `null` | Filtra por estado exacto del proceso. |
| `fields` | `pid`, `name`, `cpu_percent`... | *Todos* | Proyecta solo las columnas deseadas. |

**Ejemplo de Petición:**
```bash
curl "http://localhost:41062/www/api/processes.php?sort=mem&limit=2"
```

**Respuesta:**
```json
{
	"status": "ok",
	"timestamp": "2026-04-29T09:49:52+02:00",
	"date": "2026-04-29",
	"time": "09:49:52",
	"summary": {
		"total_processes": 312,
		"running": 2,
		"sleeping": 310,
		"waiting": 0,
		"load_1m": 1.43
	},
	"query": {
		"sort": "mem",
		"order": "desc",
		"limit": 2,
		"filter": null,
		"state": null
	},
	"processes": [
		{
			"pid": 1124,
			"name": "mysqld",
			"state": "S",
			"cpu_percent": 0.5,
			"mem_percent": 15.3,
			"user": "mysql"
		},
		{
			"pid": 890,
			"name": "php-fpm",
			"state": "S",
			"cpu_percent": 2.1,
			"mem_percent": 8.4,
			"user": "www-data"
		}
	]
}
```

---

## ⚠️ Manejo de Errores

Si se solicita un campo inválido, un rango ilógico o falta la BD, el API retornará un JSON indicando el error y modificando el código HTTP:

```json
{
	"error": "Campos inválidos: foo, bar.",
	"available_fields": [
		"load_percent",
		"temperature"
	]
}
```

| HTTP Code | Causa |
| :--- | :--- |
| `200 OK` | Petición completada con éxito. |
| `400 Bad Request` | Parámetro erróneo (rango, agregación, campos, unidad). |
| `404 Not Found` | Recurso no hallado en histórico (ej. mount inventado). |
| `503 Service Unavailable` | Base de datos SQLite no encontrada. |
