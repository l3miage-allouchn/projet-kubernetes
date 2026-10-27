#!/bin/sh
# Installe mariadb-operator, puis le cluster Galera de TomTroc (étape 3).
# Peut être relancé : rien n'est recréé ni écrasé.
set -eu
cd "$(dirname "$0")"

helm repo add mariadb-operator https://helm.mariadb.com/mariadb-operator
helm repo update mariadb-operator

# 1. L'opérateur : d'abord ses CRD, puis le contrôleur
helm upgrade --install mariadb-operator-crds mariadb-operator/mariadb-operator-crds --version 26.10.2 \
  --namespace mariadb-operator --create-namespace --wait
helm upgrade --install mariadb-operator mariadb-operator/mariadb-operator --version 26.10.2 \
  --namespace mariadb-operator \
  -f ../values/mariadb-operator.yaml --wait

kubectl create namespace tomtroc --dry-run=client -o yaml | kubectl apply -f -

# 2. Mots de passe : générés au hasard la première fois seulement, jamais écrits dans git
if ! kubectl -n tomtroc get secret mariadb-credentials >/dev/null 2>&1; then
  kubectl -n tomtroc create secret generic mariadb-credentials \
    --from-literal=root-password="$(openssl rand -hex 16)" \
    --from-literal=password="$(openssl rand -hex 16)"
fi

# 3. Le cluster Galera (3 nœuds)
kubectl apply -f mariadb.yaml
kubectl -n tomtroc wait mariadb/mariadb-galera --for=condition=Ready --timeout=600s

# 4. Import de tomtroc.sql
kubectl -n tomtroc create configmap tomtroc-sql --from-file=tomtroc.sql=../../app/sql/tomtroc.sql \
  --dry-run=client -o yaml | kubectl apply -f -
kubectl apply -f sqljob.yaml
kubectl -n tomtroc wait sqljob/tomtroc-init --for=condition=Complete --timeout=300s
