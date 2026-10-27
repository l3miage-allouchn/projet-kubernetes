#!/bin/sh
# Partage $SHARE_DIR (par défaut /exports) en NFSv4, en lecture-écriture
set -eu

SHARE_DIR="${SHARE_DIR:-/exports}"
mkdir -p "$SHARE_DIR" /var/lib/nfs/v4recovery

# fsid=0 : ce dossier devient la racine NFSv4, les clients montent "serveur:/"
# no_root_squash : le driver CSI crée un sous-dossier par volume en tant que root
echo "$SHARE_DIR *(rw,fsid=0,sync,no_subtree_check,no_root_squash,insecure)" > /etc/exports

stop() {
    echo "Arrêt du serveur NFS"
    rpc.nfsd 0
    exportfs -ua
    exit 0
}
trap stop TERM INT

mount -t nfsd nfsd /proc/fs/nfsd
rpcbind -w
exportfs -ar

# NFSv4 uniquement, en TCP : un seul port à exposer (2049)
rpc.nfsd -N 3 -V 4 -U 8
rpc.mountd -N 2 -N 3 -V 4 -u -F &

echo "Serveur NFS prêt, partage : $SHARE_DIR"
exportfs -v
wait $!
