# Image volontairement défectueuse pour tester le retour en arrière automatique de
# Flagger (étape 9) : une requête sur deux renvoie une erreur 500. Construite à partir
# de la 1.1.0, sans toucher au code de l'application.
#   docker buildx build --platform linux/amd64,linux/arm64 -t nesrix/tomtroc:1.1.1-broken --push docker/broken
FROM nesrix/tomtroc:1.1.0

ENV APP_VERSION=1.1.1-broken

COPY fail.php /usr/local/lib/php/fail.php
COPY broken.ini /usr/local/etc/php/conf.d/zz-broken.ini
