# Journal du projet Kubernetes — TomTroc

Ce journal note les décisions, les problèmes rencontrés et les commandes importantes, étape par étape.

## Environnement

- MacBook Air, puce Apple : `uname -m` → `arm64`
- Docker Desktop 29.4.2 (`linux/arm64`), Kubernetes v1.34.3 provisionné par kind :
  `desktop-control-plane`, `desktop-worker`, `desktop-worker2` (contexte `docker-desktop`)
- Helm : seule la release `headlamp` existait au départ (`helm list -A`)

```bash
kubectl config current-context
kubectl get nodes -o wide
helm list -A
```

---

## Étape 1 — Image Docker, docker compose, registry

### Décision : URL de base dans une constante `BASE_URL`

Le code contenait `/tomtroc/public/` écrit en dur 70 fois dans 17 fichiers (redirections
`header('Location: ...')` dans les contrôleurs, liens `href`/`src` dans les vues).
Deux possibilités :

1. copier le code dans `/var/www/html/tomtroc` pour garder ces URL telles quelles ;
2. remplacer le préfixe par une constante.

Choix : **la constante `BASE_URL`**, définie dans `app/public/index.php` à partir du dossier
de `index.php` (`dirname($_SERVER['SCRIPT_NAME'])`). Elle vaut automatiquement :

- `/tomtroc/public` avec XAMPP (le projet d'origine marche toujours) ;
- `""` dans le conteneur, où le site est servi à la racine (`http://hote/livres`).

C'est plus propre pour l'Ingress (pas de `/tomtroc/public` dans les URL publiques) et
aucune variable d'environnement n'est nécessaire. Remplacements effectués :

- contrôleurs : `'Location: /tomtroc/public/x'` → `'Location: ' . BASE_URL . '/x'`
- vues : `"/tomtroc/public/x"` → `"<?= BASE_URL ?>/x"`
- `header.php` : `$currentPath` est calculé sans `BASE_URL`, les comparaisons du menu
  utilisent donc `'/livre'`, `'/mon-compte'`, etc.

### Décision : `config.php` lu depuis les variables d'environnement

`docker/config.php` remplace le `config.php` local dans l'image. Il lit `DB_HOST`,
`DB_NAME`, `DB_USER` et `DB_PASSWORD` avec `getenv()`. Dans Kubernetes, ces variables
viendront d'une ConfigMap (hôte, nom de base) et d'un Secret (utilisateur, mot de passe).
Le code de l'application (`Database.php`) n'a pas changé.

### Décision : sessions dans Redis

Avec plusieurs réplicas, une session stockée en fichier n'existe que dans le pod qui l'a
créée. Deux solutions :

| | Sticky sessions (Traefik) | Redis |
|---|---|---|
| Principe | un cookie colle l'utilisateur à un pod | toutes les sessions dans un Redis partagé |
| Pod supprimé / HPA scale-down / canary | l'utilisateur est **déconnecté** | rien ne se passe |
| Répartition de charge | déséquilibrée | égale |
| Coût | aucun composant en plus | un pod Redis |

Choix : **Redis**. Les tests de haute dispo, le HPA, l'A/B testing et le canary Flagger
déplacent les utilisateurs entre pods : avec des sticky sessions ils seraient déconnectés.

Mise en œuvre sans toucher au code : extension PHP `redis` dans l'image et, dans
`docker/php.ini`, `session.save_handler = ${SESSION_SAVE_HANDLER}` et
`session.save_path = "${SESSION_SAVE_PATH}"`. PHP remplace `${...}` par les variables
d'environnement. Valeurs par défaut de l'image : `files` + `/tmp` ; pour Redis :
`redis` + `tcp://redis:6379`.

### Le Dockerfile (`docker/Dockerfile`)

- base `php:8.3-apache-bookworm`, extensions `pdo_mysql` et `redis`
- `mod_rewrite` activé, `AllowOverride All` pour que le `.htaccess` soit lu
- `DocumentRoot` = `/var/www/html/public` : `src/` et `config.php` ne sont pas accessibles
  depuis le web (vérifié : 404)
- PHP désactivé dans `public/uploads/` (`php_admin_flag engine off`) : un fichier `.php`
  envoyé ne peut pas être exécuté
- `php.ini-production` + `upload_max_filesize = 4M` : le code refuse les images > 2 Mo avec
  un message clair, PHP doit donc accepter un peu plus pour que ce message s'affiche
- **non-root** : `USER www-data` (uid 33). Un utilisateur non-root ne peut pas ouvrir un
  port < 1024, Apache écoute donc sur **8080**. Le code appartient à root (lecture seule pour
  www-data) ; seul `public/uploads/` est accessible en écriture.
- `.dockerignore` en liste blanche : seuls `docker/`, `app/src/` et `app/public/` sont envoyés
  au build (pas de `.git`, de `config.php` local ni d'uploads)

### Problème rencontré : `docker-credential-desktop` introuvable

```
error getting credentials - err: exec: "docker-credential-desktop": executable file not found in $PATH
```

`~/.docker/config.json` contient `"credsStore": "desktop"`, mais le dossier de Docker Desktop
n'était pas dans le `PATH`. Solution (à mettre dans `~/.zshrc`) :

```bash
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
```

### Test avec docker compose (`compose.yaml`)

Trois services : `db` (`mariadb:11.4`, importe `app/sql/tomtroc.sql` via
`/docker-entrypoint-initdb.d/` au premier démarrage), `redis` (`redis:7.4-alpine`) et `app`.

```bash
docker compose up -d --build
docker compose ps
# http://localhost:8080  —  nathalie@mail.com / password123
```

Résultats des tests :

| Test | Résultat |
|---|---|
| accueil, CSS, page inconnue | 200, 200, 404 |
| connexion → Mon compte → Messagerie | session conservée |
| contenu de la session dans Redis | `user_id\|i:1;user_pseudo\|s:9:"Nathalire";` |
| ajout d'un livre avec image | fichier dans `uploads/books/`, servi en 200 |
| fichier `.php` dans uploads | renvoyé en texte, non exécuté |
| utilisateur du conteneur | `uid=33(www-data)` |

```bash
docker compose exec redis redis-cli --scan --pattern 'PHPREDIS_SESSION:*'
docker compose exec app id
```

### Build multi-architecture

Le driver `docker` par défaut ne produit qu'une architecture. On crée un builder
`docker-container` ; l'architecture amd64 est émulée par QEMU (build complet : ~1 min 40).

```bash
docker buildx create --name multiarch --driver docker-container --bootstrap
docker buildx build --builder multiarch --platform linux/amd64,linux/arm64 \
  -f docker/Dockerfile -t <utilisateur>/tomtroc:1.0.0 --push .
docker buildx imagetools inspect <utilisateur>/tomtroc:1.0.0
```

### Publication sur Docker Hub

Pourquoi un registry : avec le Kubernetes « kind » de Docker Desktop, chaque nœud a son
propre stock d'images (containerd). Une image construite avec `docker build` n'y est pas
visible (vérifié avec `kubectl get node desktop-worker -o jsonpath='{.status.images}'`).
Les nœuds doivent donc la télécharger depuis un registry.

Connexion faite dans Docker Desktop (compte `nesrix`). Image publiée :
**`nesrix/tomtroc:1.0.0`**, dépôt public (pas besoin d'`imagePullSecret`).

```bash
docker buildx build --builder multiarch --platform linux/amd64,linux/arm64 \
  -f docker/Dockerfile -t nesrix/tomtroc:1.0.0 --push .
docker buildx imagetools inspect nesrix/tomtroc:1.0.0
```

L'index contient un manifeste `linux/amd64`, un manifeste `linux/arm64` et leurs
attestations (provenance du build). Chaque nœud télécharge automatiquement la version
de son architecture.

---

## Étape 2 — Infrastructure du cluster (Helm)

Tout est dans `infra/` : un fichier de valeurs commenté par chart (`infra/values/`) et un
script rejouable, `infra/install.sh` (`helm upgrade --install` installe ou met à jour).

| Composant | Chart | Namespace | Rôle |
|---|---|---|---|
| Traefik v3.7 | `traefik/traefik` 42.1.3 | `traefik` | IngressController (Ingress + CRD Traefik) |
| local-path-provisioner | `infra/charts/local-path-provisioner` (v0.0.37) | `local-path-helm` | volumes locaux, StorageClass par défaut `local-path` |
| serveur NFS | manifeste `infra/nfs-server.yaml` | `nfs` | stockage partagé dans le cluster |
| NFS CSI driver | `csi-driver-nfs/csi-driver-nfs` 4.13.6 | `kube-system` | StorageClass `nfs-csi` (ReadWriteMany) |
| metrics-server | `metrics-server/metrics-server` 3.14.0 | `kube-system` | `kubectl top`, HPA |

```bash
./infra/install.sh
helm list -A
```

### Ressources disponibles

Chaque nœud affiche 10 CPU et 8 Go, mais les trois nœuds kind sont des conteneurs qui
**partagent la même VM Docker (~7,75 Go)**. D'où 1 réplica par composant d'infra et des
`requests`/`limits` faibles. La stack `docker compose` de l'étape 1 a été arrêtée
(`docker compose stop`, sans suppression) pour libérer de la mémoire.

### Traefik

- Service `LoadBalancer`, 1 réplica, non-root (valeurs par défaut de la chart)
- deux providers actifs : `KubernetesIngress` (Ingress standard) et `KubernetesCRD`
  (`IngressRoute`, `TraefikService` : pondération pour l'A/B testing et Flagger)
- classe d'Ingress par défaut, journaux d'accès activés
- tableau de bord sur le point d'entrée interne `traefik` (8080), non exposé par le
  Service :

```bash
kubectl -n traefik port-forward deploy/traefik 9000:8080
# http://localhost:9000/dashboard/
```

Problème : la chart valide ses valeurs avec un schéma JSON ; `logs.access` a été refusé
(« additional properties 'logs' not allowed »), la bonne clé est `accessLog.enabled`.

### Pourquoi MetalLB est inutile avec Docker Desktop

MetalLB donne une IP aux Services `LoadBalancer` sur un cluster sans cloud, en l'annonçant
sur le réseau local (ARP/BGP). Ici :

1. **Docker Desktop fournit déjà ce service** : le Service Traefik a reçu l'IP
   `172.21.0.7` et Docker Desktop publie ses ports sur le Mac (`lsof` montre le processus
   Docker Desktop en écoute sur `*:80`). `curl http://localhost/` → `404 page not found`,
   la réponse de Traefik (aucune route encore).
2. **Une IP MetalLB serait injoignable** : `curl http://172.21.0.7/` depuis le Mac ne
   répond pas. Les nœuds kind sont sur un réseau Docker interne à la VM, non routé vers
   macOS ; une IP annoncée par MetalLB sur ce réseau resterait invisible.

### local-path-provisioner

kind fournit déjà un local-path-provisioner (StorageClass `standard`, provisioner
`rancher.io/local-path`), mais pas via Helm. Celui de Helm est **ajouté à côté** :
StorageClass `local-path`, provisioner `cluster.local/local-path-provisioner`, namespace
`local-path-helm`. La chart n'est pas publiée dans un dépôt Helm : elle est copiée depuis
le dépôt GitHub (tag v0.0.37) dans `infra/charts/`.

`local-path` devient la StorageClass par défaut :

```bash
kubectl annotate storageclass standard storageclass.kubernetes.io/is-default-class=false --overwrite
```

Les volumes sont dans `/var/local-path-helm` sur les nœuds (voir NFS ci-dessous).

### Stockage partagé NFS

Un volume `local-path` est attaché à un seul nœud (ReadWriteOnce). Les réplicas de
TomTroc, répartis sur deux workers, doivent partager `uploads/` : il faut du
**ReadWriteMany**, fourni ici par NFS.

1. **Le noyau le permet** : le noyau de la VM (`6.12.76-linuxkit`), partagé par les
   nœuds kind, contient `nfs`, `nfs4` et `nfsd` :
   ```bash
   docker run --rm --privileged alpine:3.20 grep nfs /proc/filesystems
   ```
2. **Image du serveur** : les images connues (`itsthenetwork/nfs-server-alpine`,
   `erichough/nfs-server`) n'existent qu'en amd64 et ne sont plus maintenues ; la seule
   image multi-arch trouvée vient d'un éditeur inconnu, à éviter pour un pod privilégié.
   On construit donc la nôtre : `docker/nfs-server/` (Alpine + `nfs-utils` + un script),
   publiée en **`nesrix/nfs-server:1.0.0`** (amd64 + arm64). NFSv4 uniquement, en TCP :
   un seul port, 2049. Testée d'abord avec deux conteneurs Docker.
3. **Piège overlayfs** : le serveur NFS du noyau ne peut pas partager un dossier sur
   overlayfs (le système de fichiers des conteneurs). Dans un nœud kind, `/var` est un vrai
   volume ext4 : les volumes local-path sont donc créés sous `/var/local-path-helm`.
4. **Serveur dans le cluster** (`infra/nfs-server.yaml`) : Deployment à 1 réplica
   (stratégie `Recreate`), pod privilégié (nécessaire pour piloter `nfsd`), données sur un
   PVC `local-path` de 5 Gi, Service `nfs-server.nfs.svc.cluster.local:2049`.
5. **Driver CSI** : StorageClass `nfs-csi` (`server: nfs-server.nfs.svc.cluster.local`,
   `share: /`, NFS 4.1). Chaque PVC devient un sous-dossier `pvc-...` sur le serveur.

Test (`infra/tests/nfs-rwx-test.yaml`) : un PVC `nfs-csi` en ReadWriteMany monté par un
pod sur `desktop-worker` (écrit) et un pod sur `desktop-worker2` (lit, puis écrit à son
tour) : chaque pod voit les écritures de l'autre.

```bash
kubectl apply -f infra/tests/nfs-rwx-test.yaml
kubectl exec rwx-reader -- cat /data/test.txt
kubectl exec -n nfs deploy/nfs-server -- ls /exports
```

Limite à noter pour le rapport : le serveur NFS est un point unique de défaillance. Son
volume `local-path` est lié au nœud où il a été créé : si ce worker tombe, le stockage
partagé est indisponible jusqu'à son retour (à observer à l'étape 11).

### metrics-server

Option `--kubelet-insecure-tls` : les kubelets de kind ont des certificats auto-signés.

```bash
kubectl top nodes
kubectl top pods -A --sort-by=memory
```

Mesure après installation : control-plane 1200 Mi, workers 587 Mi et 794 Mi.

---

## Étape 3 — Base de données répliquée (mariadb-operator + Galera)

Fichiers : `infra/values/mariadb-operator.yaml`, `infra/database/` (`mariadb.yaml`,
`sqljob.yaml`, `install.sh`).

```bash
./infra/database/install.sh
```

### L'opérateur

Un **opérateur** est un contrôleur qui ajoute des types d'objets à Kubernetes (des CRD) et
sait les gérer : on décrit « un cluster MariaDB Galera de 3 nœuds », il crée le
StatefulSet, les Services, les volumes, le PodDisruptionBudget, initialise le cluster et
le répare après une panne.

Deux charts Helm (version 26.10.2), dans le namespace `mariadb-operator` :

- `mariadb-operator-crds` : les CRD (`MariaDB`, `SqlJob`, `User`, `Database`, `Grant`,
  `Backup`…). Séparées pour mettre à jour l'opérateur sans toucher aux définitions.
- `mariadb-operator` : trois pods (opérateur, webhook de validation, cert-controller
  pour les certificats du webhook).

### Galera

Réplication **synchrone multi-maître** : chaque nœud a toutes les données et une écriture
n'est validée que si la **majorité** des nœuds l'accepte (quorum). Avec 3 nœuds, le
cluster survit à la perte d'un nœud (2/3 > 50 %), pas de deux. D'où un nombre impair.

### Décision : un pod par nœud, y compris le control-plane

Le control-plane a un taint `node-role.kubernetes.io/control-plane:NoSchedule` et il n'y a
que 2 workers. Avec 3 pods sur 2 workers, un worker en porterait 2 : l'arrêter (étape 11)
laisserait 1 nœud sur 3, sans quorum, et la base s'arrêterait.

Solution : `affinity.antiAffinityEnabled: true` (anti-affinité obligatoire, un pod
MariaDB par nœud) + une **toleration** pour le taint du control-plane. Compromis de
laboratoire : en production on aurait 3 workers et on laisserait le control-plane libre.

| Pod | Nœud |
|---|---|
| `mariadb-galera-0` | `desktop-worker` |
| `mariadb-galera-1` | `desktop-control-plane` |
| `mariadb-galera-2` | `desktop-worker2` |

### Ressources

`innodb_buffer_pool_size=64M`, `gcache.size=64M`, `max_connections=50`,
`performance_schema=OFF`, requests 100m / 256Mi, limite 512Mi. Mesuré : 133 à 169 Mi par
pod.

### Mots de passe

Générés au hasard par `install.sh` (`openssl rand -hex 16`) dans le Secret
`mariadb-credentials` (clés `root-password` et `password`), seulement s'il n'existe pas.
Ils ne sont jamais écrits dans git.

```bash
kubectl -n tomtroc get secret mariadb-credentials -o jsonpath='{.data.password}' | base64 -d
```

### Initialisation avec tomtroc.sql

- `username: tomtroc`, `database: tomtroc` dans l'objet `MariaDB` : l'opérateur crée la
  base et l'utilisateur avec tous les droits sur cette base.
- `tomtroc.sql` est mis dans la ConfigMap `tomtroc-sql`, puis exécuté par un **SqlJob**
  (un Job géré par l'opérateur, lancé quand la base est prête).
- Attention : le script commence par des `DROP TABLE`. Supprimer puis recréer le SqlJob
  remettrait la base à zéro.

### Ce que l'opérateur a créé

- StatefulSet `mariadb-galera` : 2 conteneurs par pod (`mariadb` + l'agent Galera de
  l'opérateur) et 2 volumes `local-path` (`storage-…` 1 Gi pour les données, `galera-…`
  pour la configuration Galera du nœud)
- Services : `mariadb-galera` (tous les nœuds), `mariadb-galera-primary` (le nœud
  principal), `mariadb-galera-secondary`, `mariadb-galera-internal` (headless, réplication)
- PodDisruptionBudget `minAvailable: 50%` : une maintenance ne peut arrêter qu'un pod à
  la fois

Pour l'application (étape 4), on utilisera **`mariadb-galera-primary`** : toutes les
écritures vont vers un seul nœud, ce qui évite les conflits de certification Galera
(deux nœuds qui modifient la même ligne en même temps). Si ce nœud tombe, l'opérateur
désigne un autre principal et le Service le suit.

### Vérifications

```bash
kubectl get mariadb,sqljob,pods,pvc -n tomtroc -o wide
P=$(kubectl -n tomtroc get secret mariadb-credentials -o jsonpath='{.data.password}' | base64 -d)
kubectl -n tomtroc exec mariadb-galera-0 -c mariadb -- mariadb -utomtroc -p"$P" tomtroc \
  -e "SHOW STATUS LIKE 'wsrep_cluster_size'; SELECT COUNT(*) FROM users;"
```

Résultats :

- sur les 3 nœuds : `wsrep_cluster_size=3`, `wsrep_cluster_status=Primary`,
  `wsrep_local_state_comment=Synced` ; 10 users, 16 books, 6 messages partout
- écriture sur `galera-1` (control-plane) → lue immédiatement sur `galera-2`
