#!/bin/sh

function _echo() {
	printf "%s %s %s %s\n" "`date '+%Y-%m-%d %H:%M:%S'`" "[$$]" "$@"
}

function die() {
	_echo >&2 "$@"
	exit 127
}

function usage() {
	me=`basename "$0"`
	cat >&2 <<EOD
$me: backup up MS installation

Usage: $me <namespace> <output dir>
Notes:
	- you need kubectl tool available in \$PATH and configured to work with k8s cluster where MS is running
	- you may pass kubectl config file via KUBECONFIG env var (e.g. \`KUBECONFIG=/tmp/kube.conf sh $me')
	- output dir will be removed if it's not a directory. If it exists and is a directory, it won't be cleaned
EOD
	exit 1
}

# Validate command-line args
ns="$1"; [ -z "$ns" ] && usage
dir="$2"; [ -z "$dir" ] && usage
[ -d "$dir" ] || { rm -rf "$dir"; mkdir -p "$dir"; [ -d "$dir" ] || die "Failed to create dir $dir"; }
cd "$dir" || die "Failed to chdir $dir"

_echo "Backup started"

# Make sure kubectl works and we see at least mysql pod
mysql_pod_name=`timeout 30 kubectl get pods -n "$ns" -o name | cut -d/ -f2 | grep -- -mysql-`
[ -z "$mysql_pod_name" ] && die "No MySQL pod found in namespace $ns"

_echo "Getting list of stateful sets..."
sts=`timeout 30 kubectl get sts -n "$ns" -o name | cut -d/ -f2 | grep -- -pipeline`
[ $? -ne 0 ] && die "Failed to get sts list in $ns namespace"
sts_plain=`echo "$sts" | paste -sd,`
_echo "Stateful sets to back up: $sts_plain"

function remove_probe_lock() {
	# usage: remove_probe_lock <namespace> <pod> <container>
	for try in 1 2 3; do
		# Remove probe lock
		timeout 10 kubectl -n "$1" exec "$2" -c "$3" -- rm -f /tmp/probe.lock
		[ $? -eq 0 ] && return || sleep 5
	done
	die "$1: CRITICAL: FAILED TO REMOVE PROBE LOCK for $2/$3, exiting backup"
}


err=0
for s in $sts; do
	_echo "Creating backup for ${s}..."

	workerName=`timeout 30 kubectl -n manticore-streams get po -l app.kubernetes.io/component=worker -o name | head -n 1`
  searchdVersion=`timeout 30 kubectl -n manticore-streams exec $workerName -- searchd --version | head -n 2 | head -n 1 | sed 's/@/ /'|cut -d " " -f 2,4| sed 's/ /-/'`

	[ -z "$searchdVersion" ] && { _echo "$s: Failed to get version of resources backup"; err=`expr $err + 1`; continue; }
	# Get first (-0) replica's container name with Manticore inside. Other replicas are not included in the backup
	pod="${s}-0"
	container=`timeout 30 kubectl get -n "$ns" "pod/$pod" -o jsonpath='{.spec.containers[0].name}'`
	[ -z "$container" ] && { _echo "$s: Failed to find searchd container, skipping backup for this pod"; err=`expr $err + 1`; continue; }

	# Backup procedure:
	# 1. SET GLOBAL maintenance=1; inside $searchd_container
	# 2. FLUSH RTINDEX pq; FLUSH RTINDEX tests;
	# 3. tar -C / -f- var/lib/manticore
	# 4. SET GLOBAL maintenance=0;

	# Touch /tmp/probe.lock so pod won't be killed by health probe in maintenance mode
	timeout 15 kubectl -n "$ns" exec "$pod" -c "$container" -- touch /tmp/probe.lock
	[ $? -ne 0 ] && { _echo "$s: Failed to create probe lock for $pod/$container, skipping backup for this pod"; err=`expr $err + 1`; continue; }

  timeout 1200 kubectl -n "$ns" exec "$pod" -c "$container" -- manticore-backup --config=/etc/manticoresearch/manticore.conf --backup-dir=/tmp/
  timeout 1200 kubectl -n "$ns" exec "$pod" -c "$container" -- tar -cf - -C $(dirname /tmp/$(ls | grep backup- | head -n1)) $(basename /tmp/$(ls | grep backup- | head -n1)) > "indexes-${s}-${searchdVersion}.tar.gz"
  timeout 60 kubectl -n "$ns" exec "$pod" -c "$container" -- rm -rf /tmp/$(ls | grep backup- | head -n1)
	ret=$?
	[ $ret -ne 0 ] && { _echo "$s: Failed to dump manticore indexes for $pod/$container"; rm -f "indexes-${s}-${searchdVersion}.tar.gz"; err=`expr $err + 1`; } # NOTE: NO continue here


	# Remove hook
	trap - INT TERM HUP QUIT

	# If we reach this we're fine
	[ $ret -eq 0 ] && _echo "$s: backup successful for $pod/$container (saved as indexes-${s}-${searchdVersion}.tar.gz)"
done

_echo "Dumping MySQL database..."
# MYSQL_* env vars are supposed to be defined inside the pod
timeout 240 kubectl -n "$ns" exec "$mysql_pod_name" -- \
	sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysqldump --add-drop-database --no-tablespaces --add-drop-table -u$MYSQL_USER $MYSQL_DATABASE' > database.sql
[ $? -ne 0 ] && die 'CRITICAL: MySQL DUMP FAILED, exiting backup'

_echo "Script finished, $err errors encountered"
exit $err
