# Serveur NFSv4 minimal pour le stockage partagé (ReadWriteMany) du cluster.
# Le serveur NFS est dans le noyau (nfsd) : l'image ne contient que les outils
# pour le configurer. Le pod doit être privilégié pour monter /proc/fs/nfsd.
#   docker buildx build --platform linux/amd64,linux/arm64 -t nesrix/nfs-server:1.0.0 --push docker/nfs-server
FROM alpine:3.20

RUN apk add --no-cache nfs-utils

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 2049
ENTRYPOINT ["/entrypoint.sh"]
