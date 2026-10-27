#!/bin/sh
# Envoie chaque objet de pods-de-test.yaml en dry-run côté serveur (Kyverno l'examine,
# rien n'est créé) et affiche la décision.
set -eu
cd "$(dirname "$0")"

# les politiques sont en failurePolicy: Ignore : si Kyverno ne répondait pas, tout serait
# accepté et le test ne prouverait rien. On attend donc qu'il soit prêt.
kubectl -n kyverno wait pod -l app.kubernetes.io/component=admission-controller \
  --for=condition=Ready --timeout=120s >/dev/null

TMP=$(mktemp -d)
awk -v d="$TMP" 'BEGIN{n=0} /^---$/{n++; next} {print > (d "/" n ".yaml")}' pods-de-test.yaml
for f in $(ls "$TMP" | sort -n); do
  nom=$(awk '/^  name:/{print $2; exit}' "$TMP/$f")
  [ -z "$nom" ] && continue
  if sortie=$(kubectl apply --dry-run=server -f "$TMP/$f" 2>&1); then
    echo "ACCEPTÉ  $nom"
  else
    # message de la politique qui a refusé
    echo "REFUSÉ   $nom -> $(echo "$sortie" | grep -oE '[a-z-]+:$|[a-z-]+: [A-Z][^.]*\.' | tr '\n' ' ')"
  fi
done
rm -rf "$TMP"
