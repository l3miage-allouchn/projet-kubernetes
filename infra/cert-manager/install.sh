#!/bin/sh
# Installe cert-manager et la CA du labo (étape 5). Peut être relancé.
set -eu
cd "$(dirname "$0")"

helm repo add jetstack https://charts.jetstack.io
helm repo update jetstack

helm upgrade --install cert-manager jetstack/cert-manager --version v1.21.3 \
  --namespace cert-manager --create-namespace \
  -f ../values/cert-manager.yaml --wait

kubectl apply -f issuers.yaml
kubectl -n cert-manager wait certificate/lab-ca --for=condition=Ready --timeout=120s
kubectl wait clusterissuer/lab-ca --for=condition=Ready --timeout=60s

# copie locale du certificat de la CA (public), pour curl --cacert ou le trousseau macOS
kubectl -n cert-manager get secret lab-ca -o jsonpath='{.data.ca\.crt}' | base64 -d > lab-ca.crt
echo "CA enregistrée dans infra/cert-manager/lab-ca.crt"
