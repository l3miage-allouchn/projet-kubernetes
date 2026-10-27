#!/bin/sh
# Installe l'infrastructure du cluster (étape 2). Peut être relancé sans risque :
# "helm upgrade --install" installe la première fois, puis met à jour.
set -eu
cd "$(dirname "$0")"

helm repo add traefik https://traefik.github.io/charts
helm repo add csi-driver-nfs https://raw.githubusercontent.com/kubernetes-csi/csi-driver-nfs/master/charts
helm repo add metrics-server https://kubernetes-sigs.github.io/metrics-server/
helm repo update

# 1. Traefik
helm upgrade --install traefik traefik/traefik --version 42.1.3 \
  --namespace traefik --create-namespace \
  -f values/traefik.yaml --wait

# 2. local-path-provisioner (devient la StorageClass par défaut à la place de "standard")
helm upgrade --install local-path-provisioner charts/local-path-provisioner \
  --namespace local-path-helm --create-namespace \
  -f values/local-path.yaml --wait
kubectl annotate storageclass standard storageclass.kubernetes.io/is-default-class=false --overwrite

# 3. Serveur NFS dans le cluster, puis driver CSI NFS
kubectl apply -f nfs-server.yaml
kubectl -n nfs rollout status deployment/nfs-server --timeout=180s
helm upgrade --install csi-driver-nfs csi-driver-nfs/csi-driver-nfs --version 4.13.6 \
  --namespace kube-system \
  -f values/csi-driver-nfs.yaml --wait

# 4. metrics-server
helm upgrade --install metrics-server metrics-server/metrics-server --version 3.14.0 \
  --namespace kube-system \
  -f values/metrics-server.yaml --wait
