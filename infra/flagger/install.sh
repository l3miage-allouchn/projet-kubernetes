#!/bin/sh
# Installe Flagger et son générateur de charge (étape 9). Peut être relancé.
set -eu
cd "$(dirname "$0")"

helm repo add flagger https://flagger.app
helm repo update flagger

# Flagger dans le namespace de Traefik, comme le recommande sa documentation
helm upgrade --install flagger flagger/flagger --version 1.45.0 \
  --namespace traefik \
  -f ../values/flagger.yaml --wait

# le générateur de charge dans le namespace de l'application
helm upgrade --install flagger-loadtester flagger/loadtester --version 0.39.0 \
  --namespace tomtroc \
  -f ../values/flagger-loadtester.yaml --wait
