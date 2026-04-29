#!/usr/bin/env bash
# =============================================================
# collector.sh — Recolector de métricas del sistema
#
# Cron inicial único:
#   * * * * * /$SYSMETRICS/collector.sh
# =============================================================

set -euo pipefail

if [[ -z "${SYSMETRICS:-}" ]]
then
	echo "ERROR: La variable de entorno SYSMETRICS no está definida. Debes indicarla para ejecutar el script." >&2
	exit 1
fi

IV="${SYSMETRICS_INTERVAL:-10}"
DB="${SYSMETRICS}/metrics.db";
TS=$(date +%s)   # Unix epoch — PHP convierte a hora local

if [[ ! -f "$DB" ]]; then
    echo "ERROR: BD no encontrada: $DB" >&2
    echo "Ejecuta: sqlite3 \"$DB\" < init_db.sql" >&2
    exit 1
fi

sql() { sqlite3 "$DB" "$1"; }

# Arrays asociativos para para RED
declare -A net_last_rx_b net_last_tx_b net_last_rx_p net_last_tx_p net_last_rx_e net_last_tx_e net_last_rx_d net_last_tx_d

# Arrays asociativos para para DISCO
declare -A disk_last_r_sect disk_last_w_sect disk_last_r_io disk_last_w_io

# Bucle infinito: se ejecuta cada 10 segundos
while true; do
    # Guardamos el tiempo de inicio para calcular el drift
    start_time=$(date +%s)
        
    # =============================================================
    # CPU
    # =============================================================

    read -r load1 load5 load15 procs_frac _ < /proc/loadavg

    read_stat() { awk '/^cpu / {print $2,$3,$4,$5,$6,$7,$8}' /proc/stat; }
    stat1=$(read_stat); sleep 0.5; stat2=$(read_stat)

    load_percent=$(awk -v a="$stat1" -v b="$stat2" 'BEGIN {
        split(a,x); split(b,y)
        for(i=1;i<=7;i++){ta+=x[i];tb+=y[i]}
        dt=tb-ta; di=y[4]-x[4]
        printf "%.2f", (dt==0)?0:(1-di/dt)*100
    }')

    freq_mhz="NULL"
    if [[ -r /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq ]]; then
        khz=$(cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq)
        freq_mhz=$(awk "BEGIN{printf \"%.1f\",$khz/1000}")
    else
        f=$(awk '/^cpu MHz/{printf "%.1f",$4;exit}' /proc/cpuinfo)
        [[ -n "$f" ]] && freq_mhz="$f"
    fi

    temperature="NULL"
    for zone in /sys/class/thermal/thermal_zone*/temp; do
        [[ -r "$zone" ]] || continue
        val=$(cat "$zone")
        if (( val > 0 )); then
            temperature=$(awk "BEGIN{printf \"%.1f\",$val/1000}")
            break
        fi
    done

    sql "INSERT INTO cpu(ts,load_percent,load_1m,load_5m,load_15m,freq_mhz,temperature)
        VALUES($TS,$load_percent,$load1,$load5,$load15,$freq_mhz,$temperature);"

    # =============================================================
    # MEMORIA
    # =============================================================

    eval "$(awk '
        /^MemTotal:/     {total=$2}
        /^MemFree:/      {free=$2}
        /^MemAvailable:/ {avail=$2}
        /^Buffers:/      {buffers=$2}
        /^Cached:/       {cached=$2}
        /^SReclaimable:/ {srec=$2}
        /^SwapTotal:/    {stotal=$2}
        /^SwapFree:/     {sfree=$2}
        END {
            c=cached+srec; used=total-free-buffers-c
            if(used<0)used=0
            printf "MT=%d MU=%d MF=%d MA=%d MC=%d ST=%d SU=%d\n",
                total,used,free,avail,c,stotal,stotal-sfree
        }' /proc/meminfo)"

    sql "INSERT INTO memory(ts,total,used,free,available,cached,swap_total,swap_used)
        VALUES($TS,$MT,$MU,$MF,$MA,$MC,$ST,$SU);"

    # =============================================================
	# DISCO
	# =============================================================

	while IFS= read -r line
	do
		read -r device total_kb used_kb avail_kb mount <<< "$line"
		free_kb=$(( total_kb - used_kb ))

		inode_line=$(df -Pi "$mount" 2>/dev/null | tail -1)
		read -r _ itotal iused _ _ _ <<< "$inode_line"
		itotal=${itotal:-0}; iused=${iused:-0}

		mount_esc="${mount//\'/\'\'}"
		device_esc="${device//\'/\'\'}"

		# Extraer el nombre base (ej: /dev/sda1 -> sda1) para buscarlo en diskstats
		dev_base="${device##*/}"
		
		# Leer de diskstats: lecturas completadas (4), sectores leídos (6), escrituras completadas (8), sectores escritos (10)
		stats=$(awk -v dev="$dev_base" '$3 == dev {print $4, $6, $8, $10}' /proc/diskstats 2>/dev/null)

		d_r_bytes=0; d_w_bytes=0; d_r_io=0; d_w_io=0

		if [[ -n "$stats" ]]
		then
			read -r rio rsect wio wsect <<< "$stats"

			if [[ -n "${disk_last_r_sect[$dev_base]:-}" ]]
			then
				d_r_sect=$(( rsect - disk_last_r_sect[$dev_base] ))
				d_w_sect=$(( wsect - disk_last_w_sect[$dev_base] ))
				d_r_io=$(( rio - disk_last_r_io[$dev_base] ))
				d_w_io=$(( wio - disk_last_w_io[$dev_base] ))

				# Prevenir negativos
				if (( d_r_sect < 0 )); then d_r_sect=0; fi
				if (( d_w_sect < 0 )); then d_w_sect=0; fi
				if (( d_r_io < 0 )); then d_r_io=0; fi
				if (( d_w_io < 0 )); then d_w_io=0; fi

				# Convertir sectores a bytes (en /proc/diskstats el sector siempre son 512 bytes)
				d_r_bytes=$(( d_r_sect * 512 ))
				d_w_bytes=$(( d_w_sect * 512 ))
			fi

			disk_last_r_sect[$dev_base]=$rsect
			disk_last_w_sect[$dev_base]=$wsect
			disk_last_r_io[$dev_base]=$rio
			disk_last_w_io[$dev_base]=$wio
		fi

		sql "INSERT INTO disk(ts,mount,device,total,used,free,inodes_total,inodes_used,read_bytes,write_bytes,read_ops,write_ops)
			VALUES($TS,'$mount_esc','$device_esc',$total_kb,$used_kb,$free_kb,$itotal,$iused,$d_r_bytes,$d_w_bytes,$d_r_io,$d_w_io);"

	done < <(df -Pk 2>/dev/null | tail -n +2 | awk '
		{device=$1; total=$2; used=$3; avail=$4; mount=$NF}
		device ~ /^(tmpfs|devtmpfs|udev|none|overlay|shm)/ {next}
		mount  ~ /^\/(sys|proc|dev|run\/user)/              {next}
		{print device, total, used, avail, mount}
	')

    # =============================================================
	# RED
	# =============================================================

	while read -r iface rx_b tx_b rx_p tx_p rx_e tx_e rx_d tx_d
	do
		iface_esc="${iface//\'/\'\'}"

		# Si ya existe una lectura anterior para esta interfaz, calculamos la diferencia
		if [[ -n "${net_last_rx_b[$iface]:-}" ]]
		then
			d_rx_b=$(( rx_b - net_last_rx_b[$iface] ))
			d_tx_b=$(( tx_b - net_last_tx_b[$iface] ))
			d_rx_p=$(( rx_p - net_last_rx_p[$iface] ))
			d_tx_p=$(( tx_p - net_last_tx_p[$iface] ))
			d_rx_e=$(( rx_e - net_last_rx_e[$iface] ))
			d_tx_e=$(( tx_e - net_last_tx_e[$iface] ))
			d_rx_d=$(( rx_d - net_last_rx_d[$iface] ))
			d_tx_d=$(( tx_d - net_last_tx_d[$iface] ))

			# Prevenir valores negativos si la interfaz se reinicia o los contadores dan la vuelta
			if (( d_rx_b < 0 )); then d_rx_b=0; fi
			if (( d_tx_b < 0 )); then d_tx_b=0; fi
			if (( d_rx_p < 0 )); then d_rx_p=0; fi
			if (( d_tx_p < 0 )); then d_tx_p=0; fi

			sql "INSERT INTO network(ts,iface,rx_bytes,tx_bytes,rx_packets,tx_packets,rx_errors,tx_errors,rx_dropped,tx_dropped)
				VALUES($TS,'$iface_esc',$d_rx_b,$d_tx_b,$d_rx_p,$d_tx_p,$d_rx_e,$d_tx_e,$d_rx_d,$d_tx_d);"
		fi

		# Guardar la lectura actual para el próximo ciclo
		net_last_rx_b[$iface]=$rx_b
		net_last_tx_b[$iface]=$tx_b
		net_last_rx_p[$iface]=$rx_p
		net_last_tx_p[$iface]=$tx_p
		net_last_rx_e[$iface]=$rx_e
		net_last_tx_e[$iface]=$tx_e
		net_last_rx_d[$iface]=$rx_d
		net_last_tx_d[$iface]=$tx_d

	done < <(awk 'NR>2{
		gsub(/:/," ")
		iface=$1; if(iface==""||iface=="lo")next
		printf "%s %s %s %s %s %s %s %s %s\n",
			iface,$2,$10,$3,$11,$4,$12,$5,$13
	}' /proc/net/dev)

    # =============================================================
    # PROCESOS
    # =============================================================

    eval "$(awk '
        /^State:/ {
            s=substr($2,1,1)
            if(s=="R")r++
            else if(s=="S"||s=="D")sl++
            else if(s=="T")st++
            else if(s=="Z")z++
            total++
        }
        /^Threads:/ {threads+=$2}
        END {
            printf "PROC_TOTAL=%d PROC_RUN=%d PROC_SLEEP=%d PROC_STOP=%d PROC_ZOM=%d PROC_THR=%d\n",
                total,r+0,sl+0,st+0,z+0,threads
        }
    ' /proc/[0-9]*/status 2>/dev/null)"

    # Top CPU: usa ps (más fiable que parsear /proc manualmente para %)
    cpu_top=$(ps -eo pid,pcpu --no-headers --sort=-pcpu 2>/dev/null | awk 'NR==1{print $1,$2}')
    cpu_top_pid=$(awk '{print $1}' <<< "$cpu_top")
    cpu_top_pct=$(awk '{print $2}' <<< "$cpu_top")
    cpu_top_pid=${cpu_top_pid:-NULL}; cpu_top_pct=${cpu_top_pct:-NULL}

    # Top MEM
    mem_top=$(ps -eo pid,pmem --no-headers --sort=-pmem 2>/dev/null | awk 'NR==1{print $1,$2}')
    mem_top_pid=$(awk '{print $1}' <<< "$mem_top")
    mem_top_pct=$(awk '{print $2}' <<< "$mem_top")
    mem_top_pid=${mem_top_pid:-NULL}; mem_top_pct=${mem_top_pct:-NULL}

    sql "INSERT INTO processes(ts,total,running,sleeping,stopped,zombie,threads,cpu_top_pid,cpu_top_pct,mem_top_pid,mem_top_pct)
        VALUES($TS,$PROC_TOTAL,$PROC_RUN,$PROC_SLEEP,$PROC_STOP,$PROC_ZOM,$PROC_THR,$cpu_top_pid,$cpu_top_pct,$mem_top_pid,$mem_top_pct);"

    # =============================================================
    # SISTEMA GENERAL
    # =============================================================

    uptime_sec=$(awk '{printf "%d",$1}' /proc/uptime)
    users_logged=$(who 2>/dev/null | wc -l)
    read -r _l1 _ _ procs_str _ < /proc/loadavg
    procs_total=$(echo "$procs_str" | cut -d/ -f2)

    sql "INSERT INTO system_info(ts,uptime_sec,load_1m,users_logged,procs_total)
        VALUES($TS,$uptime_sec,$load1,$users_logged,$procs_total);"

    # =============================================================
    # Retención (7 días, ~1/2880 ejecuciones)
    # =============================================================

    if (( RANDOM % 2880 == 0 )); then
        RETAIN=$(( TS - 7*86400 ))
        sql "DELETE FROM cpu         WHERE ts < $RETAIN;
            DELETE FROM memory      WHERE ts < $RETAIN;
            DELETE FROM disk        WHERE ts < $RETAIN;
            DELETE FROM network     WHERE ts < $RETAIN;
            DELETE FROM processes   WHERE ts < $RETAIN;
            DELETE FROM system_info WHERE ts < $RETAIN;
            VACUUM;"
    fi

    elapsed=$(( $(date +%s) - start_time ))
	sleep_time=$(( IV - elapsed ))
	
	[[ $sleep_time -gt 0 ]] && sleep $sleep_time
done