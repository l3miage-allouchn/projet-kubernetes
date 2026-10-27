#!/bin/sh
# Installe Kyverno et les politiques du projet (étape 10). Peut être relancé.
set -eu
cd "$(dirname "$0")"

helm repo add kyverno https://kyverno.github.io/kyverno/
helm repo update kyverno

helm upgrade --install kyverno kyverno/kyverno --version 3.9.2 \
  --namespace kyverno --create-namespace \
  -f ../values/kyverno.yaml --wait --timeout 10m

kubectl apply -f policies/
kubectl wait clusterpolicy --all --for=condition=Ready --timeout=120s
