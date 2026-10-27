#!/bin/sh
# Installe mariadb-operator (étape 3). La base de TomTroc elle-même (objet MariaDB,
# Secret, import SQL) est créée par la chart helm/tomtroc depuis l'étape 6.
set -eu

helm repo add mariadb-operator https://helm.mariadb.com/mariadb-operator
helm repo update mariadb-operator

# d'abord les CRD, puis le contrôleur
helm upgrade --install mariadb-operator-crds mariadb-operator/mariadb-operator-crds --version 26.10.2 \
  --namespace mariadb-operator --create-namespace --wait
helm upgrade --install mariadb-operator mariadb-operator/mariadb-operator --version 26.10.2 \
  --namespace mariadb-operator \
  -f "$(dirname "$0")/../values/mariadb-operator.yaml" --wait
