# 🖥️ System Stats REST API — Histórico y Tiempo Real

API REST minimalista en PHP puro para consultar métricas históricas del sistema operativo (almacenadas en SQLite) y procesos en tiempo real.

No requiere frameworks. Solo PHP, acceso a `/proc` (para procesos) y acceso a la base de datos de métricas generada por el colector.

---

## 📁 Estructura

```text
api/
├── _common.php    → Motor central: parseo de params, agregación y conexión BD.
├── cpu.php        → Histórico de uso, carga, temperatura y estados (iowait) de CPU.
├── disk.php       → Histórico de particiones (espacio, inodos y E/S).
├── docker.php     → Histórico de consumo de recursos por contenedor Docker.
├── memory.php     → Histórico de RAM y Swap.
├── network.php    → Histórico de tráfico por interfaces de red.
├── processes.php  → Endpoint en TIEMPO REAL (equivalente a `top`).
├── sockets.php    → Histórico de conexiones y estados de red TCP.
└── system.php     → Histórico global (uptime, load, usuarios, procesos totales).

```

---

## 🚀 Instalación y Configuración

La arquitectura se divide en dos partes: el recolector que corre en el sistema host como servicio y la API que se sirve mediante un contenedor Docker.

**Variables de Entorno:**
El sistema utiliza tres variables clave para funcionar:

- `SYSMETRICS`: Ruta base absoluta en el host donde está el script y la BD.
- `SYSMETRICS_INTERVAL`: Segundos que transcurren entre cada recolección (ej: 10).
- `SYSMETRICS_WEB`: Ruta interna dentro del contenedor Docker donde la API buscará la BD.

### Paso 1: Configurar el Host (Variables globales)

Aunque el servicio tiene sus propias variables, es recomendable que las variables del sistema host sean permanentes editando `/etc/environment` para facilitar tareas de mantenimiento manual.

```bash
sudo nano /etc/environment

```

Añade las rutas y el intervalo al final del archivo:

```text
SYSMETRICS="/opt/sysmetrics"
SYSMETRICS_DB="/opt/sysmetrics/metrics.db"
SYSMETRICS_INTERVAL="10"

```

### Paso 2: Configurar el Recolector como Servicio (systemd)

El recolector se ejecuta ahora como un servicio persistente de systemd, lo que garantiza su inicio automático y reinicio en caso de fallo.

#### 1. Archivo de servicio

Crea el archivo `/etc/systemd/system/metrics-collector.service` con el siguiente contenido:

```ini
[Unit]
Description=Recolector de métricas del sistema (collector.sh)
After=local-fs.target
Wants=local-fs.target

[Service]
Type=simple
User=root
Group=root

# Variables de entorno para el proceso
Environment="SYSMETRICS=/opt/sysmetrics"
Environment="SYSMETRICS_INTERVAL=10"

# Ruta absoluta al script
ExecStart=/opt/sysmetrics/collector.sh

# Política de reinicio
Restart=on-failure
RestartSec=5

# Captura de logs en journalctl
StandardOutput=journal
StandardError=journal
SyslogIdentifier=collector-metricas

[Install]
WantedBy=multi-user.target

```

#### 2. Activar e iniciar el servicio

```bash
# 1. Recargar configuración de systemd
sudo systemctl daemon-reload

# 2. Habilitar el servicio para el arranque
sudo systemctl enable metrics-collector.service

# 3. Iniciar el servicio ahora
sudo systemctl start metrics-collector.service

```

#### 3. Verificación y monitoreo

```bash
# Ver estado del servicio
sudo systemctl status metrics-collector.service

# Seguir los logs en tiempo real
sudo journalctl -u metrics-collector.service -f

```

---

## ⚙️ Parámetros Comunes (Histórico)

Todos los endpoints (excepto `processes.php`) heredan los siguientes parámetros GET de `_common.php`:

| Param      | Valores Permitidos                       | Por defecto | Descripción                                                                     |
| ---------- | ---------------------------------------- | ----------- | ------------------------------------------------------------------------------- |
| `range`    | `5m`, `15m`, `1h`, `12h`, `24h`, `7d`... | `1h`        | Ventana de tiempo a consultar.                                                  |
| `from`     | Epoch Unix o fecha (ej: `2026-04-28`)    | _calculado_ | Inicio exacto del rango temporal.                                               |
| `to`       | Epoch Unix o fecha                       | `now()`     | Fin exacto del rango temporal.                                                  |
| `agg`      | `avg`, `min`, `max`, `raw`               | `avg`       | Función de agregación. `raw` devuelve datos sin agrupar (limitado por `limit`). |
| `interval` | `auto`, `30s`, `5m`, `1h`...             | `auto`      | Tamaño del "bucket" o franja de agrupación temporal.                            |
| `limit`    | Entero positivo                          | `2000`      | Limita el número de registros devueltos.                                        |
| `fields`   | _Depende del endpoint_                   | _Todos_     | Lista separada por comas de columnas a extraer.                                 |

---

## 📡 Endpoints Históricos

### `GET /cpu.php`

Histórico de CPU, temperatura y desglose de estados (iowait).

- **Campos:** `load_percent`, `load_1m`, `load_5m`, `load_15m`, `freq_mhz`, `temperature`, `iowait_percent`, `sys_percent`, `user_percent`.

**Ejemplo de Petición:**

```bash
curl "http://localhost:41062/www/api/cpu.php?range=1h&agg=avg&fields=load_percent,iowait_percent"

```

---

### `GET /disk.php`

Histórico de capacidad, inodos y operaciones de lectura/escritura (I/O) por partición.

- **Campos:** `mount`, `device`, `total`, `used`, `free`, `used_percent`, `free_percent`, `inodes_total`, `read_bytes`, `write_bytes`, `read_ops`, `write_ops`...
- **Params Extra:** `mount` (ej. `/`), `unit` (`bytes`, `kb`, `mb`, `gb` - defecto: `gb`).

---

### `GET /docker.php`

Histórico de consumo de recursos por contenedor Docker individual.

- **Campos:** `container_name`, `cpu_percent`, `mem_bytes`.
- **Params Extra:** `container` (Filtra por nombre específico de contenedor), `unit` (`bytes`, `kb`, `mb`, `gb` - defecto: `mb`).

---

### `GET /memory.php`

Histórico de RAM y Swap.

- **Campos:** `total`, `used`, `free`, `available`, `cached`, `swap_total`, `swap_used`, `used_percent`, `swap_used_percent`.
- **Params Extra:** `unit` (`kb`, `mb`, `gb` - defecto: `mb`).

---

### `GET /network.php`

Histórico de tráfico de red. Los contadores representan el tráfico real generado _durante_ el intervalo (deltas).

- **Campos:** `iface`, `rx_bytes`, `tx_bytes`, `rx_packets`, `tx_packets`, `rx_errors`, `tx_errors`, `rx_dropped`, `tx_dropped`.
- **Params Extra:** `interface` (ej. `eth0`), `unit` (`bytes`, `kb`, `mb`, `gb` - defecto: `mb`).

---

### `GET /sockets.php`

Histórico de volumen de conexiones de red y estados TCP (para detectar cuellos de botella).

- **Campos:** `tcp_established`, `tcp_time_wait`, `tcp_listen`.

---

### `GET /system.php`

Histórico de métricas generales del OS.

- **Campos:** `uptime_sec`, `load_1m`, `users_logged`, `procs_total`, `uptime_human` (solo accesible con `agg=raw`).

---

## ⚡ Endpoint en Tiempo Real

### `GET /processes.php`

Instantánea del estado de los procesos (estilo `top`). Lee directamente de `/proc` en el momento de la petición. No usa la base de datos histórica.

| Param    | Valores Permitidos                        | Por defecto | Descripción                            |
| -------- | ----------------------------------------- | ----------- | -------------------------------------- |
| `sort`   | `cpu`, `mem`, `pid`, `name`, `threads`... | `cpu`       | Criterio de ordenación.                |
| `order`  | `asc`, `desc`                             | `desc`      | Dirección del orden.                   |
| `limit`  | Entero (max: 500)                         | `25`        | Número máximo de procesos a devolver.  |
| `filter` | Texto (substring)                         | `null`      | Busca por nombre de proceso o cmdline. |
| `state`  | `R`, `S`, `D`, `T`, `Z`                   | `null`      | Filtra por estado exacto del proceso.  |
| `fields` | `pid`, `name`, `cpu_percent`...           | _Todos_     | Proyecta solo las columnas deseadas.   |

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
  "available_fields": ["load_percent", "temperature"]
}
```

| HTTP Code                 | Causa                                                  |
| ------------------------- | ------------------------------------------------------ |
| `200 OK`                  | Petición completada con éxito.                         |
| `400 Bad Request`         | Parámetro erróneo (rango, agregación, campos, unidad). |
| `404 Not Found`           | Recurso no hallado en histórico (ej. mount inventado). |
| `503 Service Unavailable` | Base de datos SQLite no encontrada.                    |

```

```
