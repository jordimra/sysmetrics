# AGENTS.md — sysmetrics

API REST de métricas del sistema en PHP puro (sin frameworks, sin Composer, sin tests ni CI). Dos piezas:

- `collector.sh`: recolector bash en el host Linux (systemd), escribe cada N segundos en SQLite `metrics.db`.
- Endpoints PHP planos en la raíz del repo que leen esa BD en modo solo-lectura.

## Comandos

- Sintaxis PHP: `php -l <archivo.php>`. No existe suite de tests, linter ni formateador configurados.
- Crear la BD: `sqlite3 metrics.db < init_db.sql` — **borra todos los datos existentes** (bloque `DELETE` al final del fichero).
- Verificar un endpoint en local: crear `metrics.db` con datos, luego `SYSMETRICS_WEB=<dir> php -S localhost:8000` y `curl "http://localhost:8000/cpu.php?range=1h"`.
- Recolector manual: `SYSMETRICS=<dir> SYSMETRICS_INTERVAL=10 ./collector.sh` (requiere bash, `sqlite3` CLI, `ss`, `df`, `awk`; `docker` opcional).

## Variables de entorno (no obvias)

- `SYSMETRICS`: ruta absoluta **en el host** donde viven `collector.sh` y `metrics.db`.
- `SYSMETRICS_WEB`: ruta **dentro del contenedor Docker web** donde la API busca `metrics.db` (la lee `_common.php`; sin ella todo endpoint histórico muere con 500).
- `SYSMETRICS_INTERVAL`: segundos entre ciclos del recolector.
- `SYSMETRICS_DB` (aparece en el README) **no la usa ningún código**; el recolector deriva la ruta de `SYSMETRICS`.

## Reglas del código

- Patrón de todo endpoint histórico: `require _common.php` → definir `$FIELDS` → `parse_common_params($FIELDS)` → `get_db()` → SQL → `output()`. Tanto `error_json()` como `output()` terminan con `exit`.
- La BD guarda `ts` como epoch INTEGER: la agregación temporal es aritmética directa en SQL (`(ts/N)*N`), nunca strings de fecha.
- Orden: SQL ordena `ts DESC` y luego `array_reverse()` devuelve los datos en orden cronológico. No romper ese par.
- `get_db()` activa `PRAGMA query_only=ON`: la API es solo lectura; ningún write desde PHP.
- `processes.php` es la excepción: tiempo real leyendo `/proc`, no usa BD, `_common.php` ni `parse_common_params`.
- `init_db.sql` es la fuente de verdad del esquema (tablas: `cpu`, `memory`, `disk`, `network`, `processes`, `system_info`, `sockets`, `docker_stats`). La tabla `processes` la escribe el recolector pero **no tiene endpoint histórico**.
- Conversiones de unidad (`unit=kb|mb|gb`) se hacen en PHP, no en SQL; los valores numéricos de salida se castean a `float`.
- Retención: el recolector borra datos >7 días y hace `VACUUM` (~1 vez cada 2880 ciclos). No asumir histórico disponible más allá de 7 días.

## Convenciones

- Código, errores JSON y documentación en castellano.
- El README describe la estructura como `api/` y URLs `www/api/...`; los archivos reales están en la **raíz del repo**.
- El recolector calcula deltas entre ciclos: el primer ciclo registra 0 de I/O de disco y red (no es un bug).
