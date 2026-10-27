#!/bin/sh
# Installe la supervision (étape 8) : kube-prometheus-stack, Grafana sur
# https://grafana.localhost. Peut être relancé.
set -eu
cd "$(dirname "$0")"

helm repo add prometheus-community https://prometheus-community.github.io/helm-charts
helm repo update prometheus-community

kubectl create namespace monitoring --dry-run=client -o yaml | kubectl apply -f -

# mot de passe admin de Grafana : généré la première fois seulement, jamais dans git
if ! kubectl -n monitoring get secret grafana-admin >/dev/null 2>&1; then
  kubectl -n monitoring create secret generic grafana-admin \
    --from-literal=admin-user=admin \
    --from-literal=admin-password="$(openssl rand -hex 12)"
fi

helm upgrade --install kube-prometheus-stack prometheus-community/kube-prometheus-stack --version 92.14.0 \
  --namespace monitoring \
  -f ../values/kube-prometheus-stack.yaml --wait --timeout 10m

echo "Grafana : https://grafana.localhost  (utilisateur admin)"
echo "Mot de passe : kubectl -n monitoring get secret grafana-admin -o jsonpath='{.data.admin-password}' | base64 -d"
