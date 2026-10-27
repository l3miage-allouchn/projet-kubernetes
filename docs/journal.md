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

---

## Étape 4 — Déploiement de l'application

Manifestes YAML dans `k8s/app/` (ils deviendront une chart Helm à l'étape 6), namespace
`tomtroc` :

```bash
kubectl apply -f k8s/app/ --dry-run=server   # validation par l'API, sans rien créer
kubectl apply -f k8s/app/
kubectl -n tomtroc rollout status deploy/tomtroc
```

| Fichier | Contenu |
|---|---|
| `serviceaccount.yaml` | ServiceAccount `tomtroc`, sans droit, jeton non monté |
| `configmap.yaml` | `DB_HOST=mariadb-galera-primary`, `DB_NAME`, `DB_USER`, sessions Redis |
| `redis.yaml` | Redis 7.4 (sessions), 1 pod non-root, sans disque, 64 Mo max |
| `pvc-uploads.yaml` | PVC `nfs-csi` ReadWriteMany 1 Gi pour `public/uploads` |
| `deployment.yaml` | 2 réplicas, probes, ressources, sécurité |
| `service.yaml` | Service ClusterIP `tomtroc` port 80 → 8080 |
| `hpa.yaml` | 2 à 4 réplicas, seuil 70 % CPU |
| `pdb.yaml` | au moins 1 pod disponible pendant une maintenance |

### Image 1.0.1 : endpoint de santé

Ajout de `app/public/health.php`, qui répond `ok` sans toucher à la base ni aux sessions.
Les probes ne doivent pas dépendre de la base : si MariaDB a un problème, la
`livenessProbe` redémarrerait en boucle tous les pods de l'application, ce qui n'arrange
rien et ajoute de la charge. Image : `nesrix/tomtroc:1.0.1` (étiquette par version, jamais
`latest`, pour savoir exactement ce qui tourne et pouvoir revenir en arrière).

### Configuration : ConfigMap + Secret

- ConfigMap `tomtroc-config` chargée entière avec `envFrom`.
- `DB_PASSWORD` lu directement dans le Secret `mariadb-credentials` créé à l'étape 3
  (`secretKeyRef`) : le mot de passe n'est recopié nulle part.
- `DB_HOST=mariadb-galera-primary` : toutes les écritures vont au même nœud Galera.

### Probes

| Probe | Rôle | Réglage |
|---|---|---|
| `startupProbe` | laisser le temps de démarrer | toutes les 2 s, jusqu'à 60 s |
| `readinessProbe` | n'envoyer du trafic qu'aux pods prêts | toutes les 5 s, 3 échecs |
| `livenessProbe` | redémarrer un pod bloqué | toutes les 10 s, 3 échecs |

### Ressources et HPA

- requests 100m / 96Mi, limits 500m / 256Mi. Mesuré au repos : 14 à 43 Mi par pod.
- HPA `autoscaling/v2` : 2 à 4 réplicas, cible 70 % de la request CPU (70m).
- Juste après le déploiement, le HPA affiche `cpu: <unknown>/70%` : il ignore les mesures
  des pods qui viennent de démarrer. Une minute plus tard : `cpu: 4%/70%`.

### Répartition des pods

`topologySpreadConstraints` sur `kubernetes.io/hostname`, avec `nodeTaintsPolicy: Honor`.
Sans cette option, le control-plane (qui refuse les pods de l'appli) compte comme un
emplacement vide : avec `maxSkew: 1`, un 3e pod serait refusé (2 sur un worker contre 0
sur le control-plane). Résultat : un pod sur `desktop-worker`, un sur `desktop-worker2`.

### Sécurité du conteneur

- `runAsNonRoot`, uid/gid 33 (www-data), `seccompProfile: RuntimeDefault`
- `allowPrivilegeEscalation: false`, toutes les capabilities retirées (`CapEff: 0`)
- `readOnlyRootFilesystem: true` : écriture possible seulement dans les volumes `/tmp`,
  `/var/run/apache2`, `/var/lock/apache2` (emptyDir) et `uploads` (NFS)
- `fsGroup: 33` : le volume NFS des uploads est accessible en écriture au groupe www-data

### RBAC minimal

L'application n'appelle jamais l'API Kubernetes : le minimum, c'est **aucun droit**.
ServiceAccount dédié `tomtroc`, lié à aucun Role, avec
`automountServiceAccountToken: false` (le jeton n'existe pas dans le pod : un attaquant
dans le conteneur n'aurait rien pour parler à l'API).

```bash
kubectl auth can-i '*' '*' -n tomtroc --as=system:serviceaccount:tomtroc:tomtroc   # no
```

### Tests

Port-forward vers chacun des deux pods (un par worker) pour prouver le partage :

```bash
kubectl -n tomtroc port-forward svc/tomtroc 18080:80
kubectl -n tomtroc port-forward pod/<pod-A> 18081:8080
kubectl -n tomtroc port-forward pod/<pod-B> 18082:8080
```

| Test | Résultat |
|---|---|
| `/health.php`, accueil, `/livres` via le Service | `ok`, 200, 16 livres |
| connexion sur le pod A, Mon compte sur le pod B avec le même cookie | session reconnue (Redis) |
| image envoyée au pod A, demandée au pod B | 200, 242 130 octets (NFS), fichier `33:33` |
| `touch /var/www/html/public/pirate.php` | `Read-only file system` |
| jeton ServiceAccount dans le pod | absent |
| `kubectl auth can-i` (pods, secrets, configmaps, deployments, `*`) | `no` partout |
| HPA | `cpu: 4%/70%`, 2 réplicas |

---

## Étape 5 — Ingress Traefik et HTTPS avec cert-manager

Fichiers : `infra/values/cert-manager.yaml`, `infra/cert-manager/` (`issuers.yaml`,
`install.sh`), `k8s/app/certificate.yaml`, `k8s/app/ingress.yaml`, redirection HTTP →
HTTPS dans `infra/values/traefik.yaml`.

```bash
./infra/cert-manager/install.sh
helm upgrade traefik traefik/traefik --version 42.1.3 -n traefik -f infra/values/traefik.yaml
kubectl apply -f k8s/app/certificate.yaml -f k8s/app/ingress.yaml
```

### cert-manager

Chart `jetstack/cert-manager` v1.21.3, namespace `cert-manager`, 3 pods (contrôleur,
webhook, cainjector) à 1 réplica. Les CRD sont installées par la chart avec
`crds.keep: true` : désinstaller la chart ne supprime pas les certificats du cluster.

### Décision : une CA du labo plutôt qu'un certificat auto-signé par site

1. `ClusterIssuer` **`selfsigned`** : ne sert qu'à fabriquer le certificat de la CA.
2. `Certificate` **`lab-ca`** (`isCA: true`, « TomTroc Lab CA », ECDSA P-256, 10 ans),
   secret `lab-ca` dans le namespace `cert-manager`.
3. `ClusterIssuer` **`lab-ca`** (type `ca`) : signe les certificats des sites.

Avantage : on fait confiance à **une seule CA** sur le Mac, valable pour tous les sites du
cluster (TomTroc, puis Grafana à l'étape 8). Avec des certificats auto-signés, il faudrait
accepter chaque certificat séparément, et recommencer à chaque renouvellement.

### Certificat de TomTroc

`Certificate` `tomtroc` : `tomtroc.localhost`, émis par `lab-ca`, 90 jours, renouvelé
automatiquement 15 jours avant expiration, stocké dans le Secret `tomtroc-tls`.

Nom de domaine : `*.localhost` pointe vers `127.0.0.1` (vérifié avec `dscacheutil`), donc
aucune modification de `/etc/hosts`.

### Ingress

`ingressClassName: traefik`, section `tls` avec le Secret `tomtroc-tls`, annotation
`traefik.ingress.kubernetes.io/router.entrypoints: websecure` (route seulement sur 443).

### Redirection HTTP → HTTPS

Changement par rapport au plan : au lieu d'un middleware par Ingress, redirection
**globale** sur le point d'entrée `web` de Traefik (`ports.web.http.redirections`).
Une seule ligne, valable pour tous les sites, en `301` (permanente).

### Tests

```bash
CA=infra/cert-manager/lab-ca.crt
curl https://tomtroc.localhost/                       # refusé : CA inconnue
curl --cacert $CA https://tomtroc.localhost/          # 200
curl -I http://tomtroc.localhost/livres               # 301 → https://tomtroc.localhost/livres
echo | openssl s_client -connect tomtroc.localhost:443 -servername tomtroc.localhost \
  -CAfile $CA 2>/dev/null | openssl x509 -noout -issuer -dates -ext subjectAltName
```

| Test | Résultat |
|---|---|
| curl sans la CA | `SSL certificate problem: unable to get local issuer certificate` |
| curl avec la CA | 200 |
| HTTP | `301` vers HTTPS |
| connexion en HTTPS | session OK, redirections de l'appli restent en https (chemins relatifs) |
| certificat | émetteur `TomTroc Lab CA`, `DNS:tomtroc.localhost`, 90 jours, TLS 1.3, `Verify return code: 0 (ok)` |

### Faire confiance à la CA dans le navigateur (optionnel)

```bash
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain infra/cert-manager/lab-ca.crt
```

Pour retirer cette confiance : supprimer « TomTroc Lab CA » dans Trousseaux d'accès.

### Amélioration possible

Le cookie de session n'a pas l'attribut `Secure` (il serait envoyé aussi en HTTP).
La redirection 301 limite le risque ; on pourrait ajouter
`session.cookie_secure = ${SESSION_COOKIE_SECURE}` dans `docker/php.ini` (0 en compose,
1 dans Kubernetes).

---

## Étape 6 — Chart Helm `helm/tomtroc`

### Contenu

```
helm/tomtroc/
├── Chart.yaml            version de la chart 0.1.0, appVersion 1.0.1 (étiquette de l'image)
├── values.yaml           valeurs par défaut commentées (= production)
├── values-prod.yaml      production : HPA 2-4, PDB, Galera 3 nœuds, tomtroc.localhost
├── values-dev.yaml       dev : 1 pod, sans HPA/PDB, MariaDB seul, tomtroc-dev.localhost
├── files/tomtroc.sql     copie de app/sql/tomtroc.sql (Helm ne lit que les fichiers de la chart)
└── templates/            Deployment, Service, ConfigMap, ServiceAccount, PVC, HPA, PDB,
                          Certificate, Ingress, Redis, Secret, MariaDB, SqlJob, NOTES.txt
```

Ce qui reste dans `infra/` : Traefik, cert-manager, mariadb-operator, NFS,
metrics-server. Ce sont des composants partagés par tout le cluster.

```bash
helm lint helm/tomtroc -f helm/tomtroc/values-prod.yaml
helm upgrade --install tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml
helm upgrade --install tomtroc helm/tomtroc -n tomtroc-dev --create-namespace -f helm/tomtroc/values-dev.yaml
```

### Choix dans les templates

- **Secret des mots de passe avec `lookup`** : si le Secret existe, la chart reprend ses
  valeurs ; sinon elle en génère (`randAlphaNum 32`). Sans cela, chaque `helm upgrade`
  changerait les mots de passe. Vérifié : même empreinte avant et après un upgrade.
- **`helm.sh/resource-policy: keep`** sur le Secret, l'objet `MariaDB` et le PVC des
  uploads : `helm uninstall` ne supprime pas les données.
- **`checksum/config`** sur les pods : quand la ConfigMap change, les pods sont recréés pour
  la relire (sinon les variables d'environnement restent les anciennes).
- **`replicas` absent quand le HPA est actif** : sinon chaque `helm upgrade` remettrait le
  nombre de pods à la valeur des values, en écrasant la décision du HPA.
- **`DB_HOST`** calculé : `<nom>-primary` avec Galera, `<nom>` sans Galera.
- **Sélecteurs = `app.kubernetes.io/name` seul** : le sélecteur d'un Deployment ne peut plus
  changer après sa création ; ajouter un label au sélecteur aurait obligé à supprimer le
  Deployment. Les autres labels standard (`instance`, `version`, `part-of`, `managed-by`,
  `helm.sh/chart`) sont sur les objets.
- Une release par namespace : Redis, MariaDB et le Secret ont des noms fixes.

### Reprise de l'existant par Helm (adoption)

Les objets créés aux étapes 3 à 5 avec `kubectl apply` existaient déjà : `helm install`
refuse normalement d'écraser un objet qu'il ne gère pas. Deux options : tout supprimer
puis réinstaller (perte de la base, des uploads et des sessions), ou faire **adopter** les
objets par Helm. Choix : l'adoption.

1. Liste des 15 objets produits par la chart (`helm template`) : tous existent déjà avec
   le même nom.
2. Marquage pour Helm (métadonnées seulement, aucun redémarrage) :
   ```bash
   kubectl -n tomtroc label <type>/<nom> app.kubernetes.io/managed-by=Helm --overwrite
   kubectl -n tomtroc annotate <type>/<nom> meta.helm.sh/release-name=tomtroc \
     meta.helm.sh/release-namespace=tomtroc --overwrite
   ```
3. Comparaison avant installation (`--dry-run=server` pour que `lookup` lise le vrai
   Secret) :
   ```bash
   helm template tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml \
     --dry-run=server | kubectl diff -n tomtroc -f -
   ```
   Résultat : seulement des labels et annotations, sauf pour le Deployment
   (`checksum/config` → rolling update, et `replicas` qui n'est plus fixé par la chart :
   le HPA le maintient à 2). **Aucun changement de spec** pour MariaDB, Redis, le SqlJob.
4. `helm install` : les pods Galera et Redis n'ont pas redémarré (sessions conservées),
   l'application a fait un rolling update sans coupure.

Vérifications après l'adoption : connexion OK, image envoyée à l'étape 4 toujours servie,
mot de passe inchangé après un second `helm upgrade` (révision 2, aucun redémarrage).

### Environnement de dev

Installé dans `tomtroc-dev` à partir de zéro : nouveau Secret, MariaDB seul (`mariadb-0`),
import SQL, certificat `tomtroc-dev.localhost`. Isolé de la prod : base et mots de passe
différents (le livre de test ajouté en prod n'existe pas en dev). Consommation :
~107 Mi pour toute la dev.

```bash
helm list -A | grep tomtroc
curl --cacert infra/cert-manager/lab-ca.crt https://tomtroc-dev.localhost/
```

### Nettoyage après le passage à Helm

Supprimés de git (la chart les remplace ; un `kubectl apply` sur ces fichiers entrerait
en conflit avec Helm) : `k8s/app/` (10 manifestes), `infra/database/mariadb.yaml`,
`infra/database/sqljob.yaml`. `infra/database/install.sh` n'installe plus que
mariadb-operator. Les anciens fichiers restent consultables dans l'historique git
(commits des étapes 3 à 5).

Ordre d'installation complet du projet :

```bash
./infra/install.sh                 # Traefik, local-path, NFS, metrics-server
./infra/database/install.sh        # mariadb-operator
./infra/cert-manager/install.sh    # cert-manager et CA du labo
helm upgrade --install tomtroc helm/tomtroc -n tomtroc --create-namespace -f helm/tomtroc/values-prod.yaml
```

---

## Étape 7 — A/B testing avec Traefik

### Deux versions de l'image

| Image | Variante | Changement |
|---|---|---|
| `nesrix/tomtroc:1.0.2` | A | en-tête `X-App-Version`, cookie de session `Secure` |
| `nesrix/tomtroc:1.1.0` | B | 1.0.2 + bouton d'accueil « Commencer à échanger » au lieu de « Découvrir » |

- `X-App-Version` : version passée au build (`--build-arg APP_VERSION=1.0.2`), mise dans
  une variable d'environnement et renvoyée par Apache (`mod_headers`) :
  `Header always set X-App-Version "${APP_VERSION}"`. Permet de compter les versions avec
  curl.
- Cookie `Secure` (amélioration notée à l'étape 5) : `session.cookie_secure =
  ${SESSION_COOKIE_SECURE}` dans `docker/php.ini`, `0` par défaut (compose en HTTP), `1`
  dans la ConfigMap quand l'Ingress est en TLS.

```bash
docker buildx build --builder multiarch --platform linux/amd64,linux/arm64 \
  --build-arg APP_VERSION=1.1.0 -f docker/Dockerfile -t nesrix/tomtroc:1.1.0 --push .
```

### Dans la chart (version 0.2.0, appVersion 1.0.2)

- `deployment.yaml` et `service.yaml` génèrent un objet **par variante** (boucle `range`
  sur une liste) au lieu de dupliquer le YAML.
- Les pods de B s'appellent `tomtroc-b` (label `app.kubernetes.io/name`) : le Service, le
  HPA et le PDB de A ne les voient pas.
- `ab-testing.yaml` : un **TraefikService pondéré** (`weighted`) entre les Services
  `tomtroc` et `tomtroc-b`, avec un **cookie sticky**, et une **IngressRoute** qui
  l'utilise. Un Ingress standard ne peut viser qu'un Service, pas une répartition
  pondérée : l'Ingress est remplacé par l'IngressRoute pendant le test.
- `values-ab.yaml` : l'expérience (B = 1.1.0, poids 80/20), ajoutée par-dessus la prod.

```bash
# démarrer le test
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml -f helm/tomtroc/values-ab.yaml
# arrêter le test
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml
```

### Pourquoi un cookie sticky

En A/B testing, un visiteur doit toujours voir la même variante : sinon l'interface
change d'une page à l'autre et on ne peut pas attribuer son comportement (clic sur le
bouton, inscription) à une variante. Traefik tire la variante au sort à la première
visite, puis pose le cookie `tomtroc_variant` (`HttpOnly`, `Secure`, `SameSite=Lax`).

### Résultats

| Test | Résultat |
|---|---|
| 300 requêtes sans cookie | 240 × `1.0.2`, 60 × `1.1.0` (exactement 80/20) |
| 5 visiteurs avec cookie, 20 pages chacun | chacun reste sur sa variante (4 sur A, 1 sur B) |
| page d'accueil | A : « Découvrir », B : « Commencer à échanger » |

```bash
CA=infra/cert-manager/lab-ca.crt
for i in $(seq 1 300); do curl -s --cacert $CA -D - -o /dev/null https://tomtroc.localhost/health.php \
  | grep -i x-app-version; done | sort | uniq -c
kubectl -n tomtroc get ingressroute,traefikservice
```

### Problèmes trouvés en mesurant (trafic continu pendant les changements)

1. **502 pendant un rolling update** : à l'arrêt d'un pod, Kubernetes envoie le signal
   d'arrêt et retire le pod de Traefik **en même temps** ; Traefik envoie encore des
   requêtes à un pod qui s'éteint. Correction : `lifecycle.preStop: sleep 5`.
   Mesure (400 requêtes pendant un `rollout restart`) : avant, 2 × 502 + 1 × 504 ;
   après, **400 × 200**.
2. **Les deux pods sur le même worker** : `whenUnsatisfiable: ScheduleAnyway` n'est
   qu'une préférence. Passage à `DoNotSchedule` (obligatoire) ; avec
   `nodeTaintsPolicy: Honor`, un worker en panne (qui reçoit un taint) est exclu du
   calcul, donc la règle ne bloque pas la reprise après panne.
3. **Erreurs au démarrage du test** (300 requêtes : 14 × 404, 19 × 503, environ 3 s) :
   - 503 : l'IngressRoute envoyait déjà 20 % du trafic vers B alors que son pod
     démarrait (aucun pod prêt = 503 pour Traefik) ;
   - 404 : pendant le remplacement de l'Ingress par l'IngressRoute.

   Amélioration : passer toujours par IngressRoute + TraefikService (un test = un
   changement de poids), et démarrer B avec un poids de 0 avant de lui envoyer du
   trafic. C'est ce que fait Flagger à l'étape 9.

`curl -I` (requête `HEAD`) renvoie 404 : le routeur de TomTroc ne déclare que `GET` et
`POST`. Ce n'est pas un problème d'infrastructure ; les tests utilisent `GET`.
