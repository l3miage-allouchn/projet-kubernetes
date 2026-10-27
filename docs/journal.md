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

---

## Étape 8 — Supervision : kube-prometheus-stack, Grafana via Traefik

Fichiers : `infra/values/kube-prometheus-stack.yaml`, `infra/monitoring/install.sh`,
métriques ajoutées dans `infra/values/traefik.yaml` et la chart (`mariadb.metrics`).

```bash
./infra/monitoring/install.sh
helm upgrade traefik traefik/traefik --version 42.1.3 -n traefik -f infra/values/traefik.yaml
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml -f helm/tomtroc/values-ab.yaml
```

Avant l'installation, l'environnement de dev a été désinstallé (`helm uninstall` puis
suppression du namespace `tomtroc-dev`) pour libérer de la mémoire.

### Composants (chart 92.14.0, namespace `monitoring`)

| Composant | Rôle | Réglage |
|---|---|---|
| Prometheus | collecte et stocke les métriques | 1 réplica, collecte toutes les 30 s, 2 jours / 1 Go max, PVC `local-path` 2 Gi, limite 768Mi |
| Grafana | graphiques | https://grafana.localhost, mot de passe dans le Secret `grafana-admin` |
| prometheus-operator | transforme les ServiceMonitor en configuration Prometheus | limite 128Mi |
| node-exporter | métriques des nœuds (DaemonSet, 3 pods) | limite 48Mi |
| kube-state-metrics | état des objets Kubernetes | limite 96Mi |
| Alertmanager | envoi d'alertes | **désactivé** (non demandé, économise de la mémoire) |

etcd, scheduler, controller-manager et kube-proxy sont désactivés : kind n'expose pas
leurs métriques, ils apparaîtraient toujours en erreur (`DOWN`).

`serviceMonitorSelectorNilUsesHelmValues: false` : sans cette option, Prometheus ne
prend que les ServiceMonitor de sa propre release, et ignorerait ceux de Traefik et de
MariaDB.

### Grafana via Traefik

Ingress `grafana.localhost` avec l'annotation `cert-manager.io/cluster-issuer: lab-ca` :
cert-manager crée lui-même le `Certificate` (`grafana-tls`). C'est l'autre méthode que
l'objet `Certificate` écrit à la main pour TomTroc. La même CA du labo signe les deux.

```bash
kubectl -n monitoring get secret grafana-admin -o jsonpath='{.data.admin-password}' | base64 -d
```

### Métriques ajoutées

- **Traefik** : Service `traefik-metrics` + ServiceMonitor. Requêtes, codes de réponse et
  durées **par service**, donc par variante A/B ; Flagger les utilisera à l'étape 9.
- **MariaDB** : `metrics.enabled` dans l'objet `MariaDB` ; l'opérateur crée un exporter
  (Deployment séparé `mariadb-galera-metrics`, les pods Galera ne redémarrent pas) et
  un ServiceMonitor.
- L'application PHP n'expose pas de métriques ; celles de Traefik donnent la vue
  « utilisateur » (débit, erreurs, temps de réponse).

### Tableaux de bord

Ceux de la chart (nœuds, pods, namespaces...) et trois importés de grafana.com dans le
dossier « TomTroc » :

| ID | Tableau |
|---|---|
| 17346 | Traefik Official Standalone Dashboard |
| 7362 | MySQL Overview (Percona) |
| 13106 | Galera/MariaDB - Overview |

### Problèmes rencontrés

1. **Cibles Traefik et MariaDB absentes** juste après l'installation : l'opérateur
   recharge la configuration de Prometheus avec un délai. Une minute plus tard : toutes
   les cibles `up` (24).
2. **Le tableau Traefik ne séparait pas les services** : Prometheus ajoute un label
   `service` (le Service collecté, `traefik-metrics`) qui entre en conflit avec le label
   `service` de Traefik ; celui de Traefik est renommé `exported_service`, et la
   variable du tableau ne trouvait que `traefik-metrics`. Correction :
   `honorLabels: true` sur le ServiceMonitor de Traefik.
3. **Source de données « Alertmanager »** présente alors qu'Alertmanager est désactivé :
   `grafana.sidecar.datasources.alertmanager.enabled: false`, puis redémarrage de
   Grafana (il ne retire les anciennes sources qu'au démarrage ; sans disque, rien
   n'est perdu).
4. **Les pods de l'appli redémarraient à chaque changement de version de la chart** :
   `checksum/config` portait sur toute la ConfigMap, y compris le label
   `helm.sh/chart`. Il ne porte plus que sur `.data` (chart 0.3.0).

### Vérifications

```bash
kubectl -n monitoring port-forward svc/kube-prometheus-stack-prometheus 9090:9090
# http://localhost:9090/targets
```

| Requête PromQL | Résultat |
|---|---|
| `sum by (exported_service) (increase(traefik_service_requests_total{exported_service=~"tomtroc-.*"}[3m]))` | A : 395, B : 99 (≈ 80/20) |
| `histogram_quantile(0.95, sum by (le, exported_service) (rate(traefik_service_request_duration_seconds_bucket[3m])))` | p95 ≈ 95 ms pour A et B |
| `mysql_global_status_wsrep_cluster_size` | 3 sur les 3 nœuds |

Grafana : HTTP → 301 HTTPS, sans mot de passe → 401, certificat vérifié, 3 tableaux dans
le dossier TomTroc, une seule source de données (Prometheus).

### Mémoire

| | Mémoire |
|---|---|
| Prometheus | ~363 Mi |
| Grafana (3 conteneurs) | ~399 Mi |
| Nœuds au total | ~5,1 Gi sur 7,75 Gi |

---

## Étape 9 — Déploiement progressif (canary) avec Flagger

Fichiers : `infra/values/flagger.yaml`, `infra/values/flagger-loadtester.yaml`,
`infra/flagger/install.sh`, `helm/tomtroc/templates/canary.yaml` (chart 0.4.0,
appVersion 1.1.0), `docker/broken/` (image de test défectueuse).

```bash
./infra/flagger/install.sh
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml
kubectl -n tomtroc get canary -w
```

### Principe

Flagger surveille le Deployment `tomtroc`. Quand son modèle de pod change (image,
configuration), il crée une version **canary** qui reçoit un peu de trafic (+10 % toutes
les 30 s, jusqu'à 50 %). À chaque étape, il lit dans Prometheus les métriques Traefik
**du canary seulement** :

- taux de succès (réponses non 5xx) ≥ 99 % ;
- temps de réponse p99 ≤ 500 ms.

Tout va bien → **promotion** (la nouvelle version devient la production). 3 mesures en
échec → **retour en arrière** automatique.

### Installation

- Chart `flagger/flagger` 1.45.0 dans le namespace `traefik`, `meshProvider: traefik`,
  `metricsServer` = le Prometheus de l'étape 8.
- Chart `flagger/loadtester` 0.39.0 dans `tomtroc` : envoie du trafic pendant l'analyse
  (sans trafic, pas de métriques). Le trafic passe par Traefik (`hey -host
  tomtroc.localhost https://traefik.traefik.svc.cluster.local/livres`), sinon Traefik ne
  mesure rien. Vérifié depuis le pod : 20 × 200.

### Ce que Flagger crée (objet `Canary`, `canary.enabled: true` dans `values-prod.yaml`)

| Objet | Rôle |
|---|---|
| Deployment `tomtroc-primary` + HPA | la version en production (2 à 4 pods) |
| Deployment `tomtroc` | devient le canary : 0 pod au repos |
| Services `tomtroc` (vers primary), `tomtroc-primary`, `tomtroc-canary` | |
| TraefikService `tomtroc` | répartition pondérée primary / canary |
| ConfigMap `tomtroc-config-primary`, Secret `mariadb-credentials-primary` | copies (`configTracking`) : un changement de configuration passe aussi par l'analyse |

### Adaptations de la chart

- **Routage** : l'IngressRoute vise le TraefikService `tomtroc` de Flagger. Lancer un
  canary ne fait que changer des poids : plus de remplacement Ingress/IngressRoute (cause
  des erreurs au démarrage de l'A/B testing, étape 7).
- **Service `tomtroc`** : créé et géré par Flagger, la chart ne le génère plus.
- **PDB** : il vise `app.kubernetes.io/name: tomtroc-primary` (Flagger renomme ce label
  dans les pods qu'il crée).
- **Répartition des pods** : sélection par un label stable,
  `app.kubernetes.io/component: web` (Flagger ne le renomme pas), et
  `matchLabelKeys: [pod-template-hash]` (chaque version est répartie séparément).
  Résultat : un pod primary sur chaque worker.

### Passage de l'A/B testing à Flagger sans coupure

Faire tout en une fois aurait fait pointer l'IngressRoute vers un TraefikService que
Flagger n'a pas encore créé (environ 30 s d'erreurs). En deux phases :

1. Canary activé **en gardant l'A/B testing** (`-f values-prod.yaml -f values-ab.yaml`).
   Flagger crée `tomtroc-primary` et son TraefikService et fait pointer le Service
   `tomtroc` vers primary ; le trafic passe encore par le TraefikService de l'A/B, qui
   vise ce Service. Le Service porte `helm.sh/resource-policy: keep` dans cette révision.
2. A/B désactivé (`-f values-prod.yaml`) : l'IngressRoute est simplement modifiée pour
   viser le TraefikService de Flagger, qui existe déjà. Le Service `tomtroc` n'est plus
   dans la chart mais Helm ne le supprime pas (`keep`).

Mesures sous trafic continu : phase 1 **1 200 / 1 200 réponses 200**, phase 2
**600 / 600**.

### Scénario 1 : promotion de la 1.1.0

```bash
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml --set image.tag=1.1.0
```

| Heure | Étape |
|---|---|
| 14:18:48 | nouvelle révision détectée |
| 14:19:18 | canary à 10 % |
| 14:19:48 | pause : « no values found » (pas encore de mesure dans Prometheus pour le canary) |
| 14:20:18 → 14:21:48 | 20 %, 30 %, 40 %, 50 % |
| 14:23:18 | promotion terminée : `tomtroc-primary` en 1.1.0 |

Ensuite, `appVersion` de la chart passée à 1.1.0 : sans cela, un `helm upgrade` sans
`--set` aurait redemandé la 1.0.2. Vérifié : ce `helm upgrade` ne modifie pas le
Deployment (génération inchangée), donc pas de canary.

### Scénario 2 : retour en arrière automatique

Image `nesrix/tomtroc:1.1.1-broken` : la 1.1.0 + un fichier PHP exécuté avant chaque page
(`auto_prepend_file`) qui renvoie 500 une fois sur deux. `/health.php` reste correct :
les pods sont « prêts », c'est l'analyse des métriques qui doit détecter le défaut. Le
code de l'application n'est pas modifié.

```bash
helm upgrade tomtroc helm/tomtroc -n tomtroc -f helm/tomtroc/values-prod.yaml --set image.tag=1.1.1-broken
```

| Heure | Étape |
|---|---|
| 14:31:18 | canary à 10 % |
| 14:32:18 | pause : taux de succès **38,76 % < 99 %** |
| 14:32:48 | pause : taux de succès **36,36 % < 99 %** |
| 14:33:18 | 3 échecs : **retour en arrière**, canary à 0 pod |

Pendant ce temps, la production est restée en 1.1.0. Visiteurs sur `/livres` : 32
requêtes sur 505 envoyées à la version cassée, dont **22 erreurs 500 (~4 %) pendant
2 min 30**. Une mise à jour directe aurait mis **~50 % des requêtes** en erreur pour tous
les visiteurs.

### Rollback Helm

Après l'échec, Helm considère toujours `1.1.1-broken` comme la version voulue
(révision 12). Retour à la révision précédente :

```bash
helm history tomtroc -n tomtroc
helm rollback tomtroc 11 -n tomtroc     # révision 13 : "Rollback to 11"
```

Le Deployment `tomtroc` demande de nouveau la 1.1.0. Flagger ne relance pas d'analyse :
l'empreinte du modèle de pod (`6d8f8cf98f`) est la même que celle de la version déjà en
production (`lastPromotedSpec`). Le statut du Canary reste `Failed` (il décrit la
dernière analyse) jusqu'au prochain vrai changement. Site : 200, `x-app-version: 1.1.0`.

### Amélioration du script cert-manager

`--wait-for-jobs` ajouté au `helm upgrade` de cert-manager : il attend le Job
`startupapicheck`, qui ne se termine que lorsque le webhook accepte les objets. Sur un
cluster neuf, le `kubectl apply` des émetteurs pouvait sinon échouer quelques secondes
trop tôt.

---

## Étape 10 (bonus) — Politiques de sécurité avec Kyverno

Fichiers : `infra/values/kyverno.yaml`, `infra/kyverno/install.sh`,
`infra/kyverno/policies/` (7 politiques), `infra/kyverno/tests/`.

```bash
./infra/kyverno/install.sh
kubectl get clusterpolicy
./infra/kyverno/tests/tester.sh
```

### Principe

Kyverno est un **contrôleur d'admission** : l'API Kubernetes lui soumet chaque objet
créé ou modifié avant de l'enregistrer. Les règles (`ClusterPolicy`) peuvent :

- **valider** (accepter/refuser) ; en mode `Enforce` l'objet est refusé, en mode `Audit`
  il est accepté et la violation est notée dans un **PolicyReport** ;
- **modifier** (mutate) ;
- **générer** d'autres objets (generate).

### Installation (chart `kyverno/kyverno` 3.9.2, Kyverno 1.19.2)

- 1 réplica par contrôleur (admission, background, reports), contrôleur de nettoyage
  désactivé. Consommation mesurée : ~175 Mi au total.
- **Contrôleur d'admission sur le control-plane** (`nodeSelector` + toleration) : il est
  sur le chemin de toutes les créations de pods ; placé sur un worker, l'arrêt de ce
  worker (étape 11) aurait bloqué la recréation des pods.
- Problème : images de `reg.kyverno.io` en échec (`short read: expected 1597 bytes but got
  0: unexpected EOF`), release Helm en `failed` après 10 min. Solution :
  `global.image.registry: ghcr.io` (mêmes images, amd64 + arm64).
- Kyverno 1.19 signale que `kyverno.io/v1 ClusterPolicy` est **déprécié** au profit de
  nouvelles API basées sur CEL (`ValidatingPolicy`, `GeneratingPolicy`…). `ClusterPolicy`
  fonctionne encore et c'est le format le plus documenté : gardé, migration possible.

### Les politiques

| Politique | Type | Portée | Mode |
|---|---|---|---|
| `registres-approuves` | validate | `tomtroc` : `nesrix/`, `redis`, `mariadb`, `prom/`, `ghcr.io/mariadb-operator/`, `ghcr.io/fluxcd/` | Enforce |
| `interdire-privilegie` | validate | tout le cluster sauf `nfs` (serveur NFS) et `kube-system` | Enforce |
| `interdire-acces-hote` | validate | `hostNetwork`/`hostPID`/`hostIPC`/`hostPath`, sauf `monitoring` (node-exporter), `local-path-*`, `kube-system` | Enforce |
| `exiger-probes` | validate | `tomtroc`, pods (sauf Jobs) **et** Deployments/StatefulSets/DaemonSets | Enforce |
| `exiger-non-root` | validate | `tomtroc` | Audit |
| `exiger-labels` | validate | `tomtroc`, label `app.kubernetes.io/name` | Audit |
| `isolation-reseau` | **generate** | NetworkPolicy dans chaque namespace marqué `tomtroc.io/isolation=true` | — |

Avant d'écrire les règles, audit des pods existants : l'opérateur MariaDB et Flagger
utilisent des images `ghcr.io/...`, `mariadb`, `prom/` ; le serveur NFS et le driver CSI
sont privilégiés ; node-exporter utilise `hostNetwork`/`hostPath`. Une règle naïve les
aurait bloqués.

Pour `isolation-reseau`, un ClusterRole avec les labels
`rbac.kyverno.io/aggregate-to-*-controller` donne à Kyverno le droit de créer des
NetworkPolicy. Le réseau de kind (kindnet) applique bien les NetworkPolicy (droits
`list/watch` dans son ClusterRole, et vérifié par le test ci-dessous).

### Problème majeur : saturation de la VM et `failurePolicy`

Juste après l'installation, les trois contrôleurs Kyverno redémarraient en boucle (code
de sortie 0, « Shutdown signal received »). Cause dans les journaux : **impossible de
renouveler le bail de leader** (`Failed to renew lease err="context deadline exceeded"`).
`kube-scheduler` et `kube-controller-manager` avaient redémarré au même moment pour la
même raison : l'API répondait trop lentement.

Mesures dans la VM Docker : 2 Go disponibles sur 7,75 Go, 372 Mo de swap utilisé,
**pression I/O de 20 %** (les processus attendent le disque : swap + écritures d'etcd).
L'ajout de Kyverno a fait déborder une VM déjà pleine.

Conséquence dangereuse : avec `failurePolicy: Fail` (défaut), **aucun pod ne peut être
créé pendant que Kyverno redémarre**, dans tous les namespaces concernés.
Décision : **`failurePolicy: Ignore`** sur toutes les politiques. Si Kyverno ne répond
pas, la création est acceptée (et l'analyse en arrière-plan la signalera dans les
rapports). Compromis assumé : disponibilité > contrôle strict, pour un cluster aux
ressources limitées. En production : 3 réplicas du contrôleur d'admission et `Fail`.

Vérification : les pods passent par le webhook `validate.kyverno.svc-ignore` ; les
webhooks restés en `Fail` ne concernent que les objets de Kyverno lui-même (politiques,
exceptions, objets générés). Après le changement : Kyverno stable, pression I/O < 1 %.

### Autogen et exclusions par label

Kyverno recopie normalement une règle « Pod » sur les Deployments (autogen), pour
refuser directement le Deployment avec un message clair. Mais il ne le fait pas quand la
règle exclut des pods par **sélecteur de labels** (ici les pods des Jobs) : un Deployment
sans probes était accepté, seuls ses pods étaient refusés, sans message à l'`apply`.
Solution : une 2e règle explicite sur `Deployment`/`StatefulSet`/`DaemonSet`
(`spec.template.spec.containers`).

### Tests (`kubectl apply --dry-run=server` : Kyverno examine, rien n'est créé)

Le script attend que Kyverno soit prêt : avec `Ignore`, un test lancé pendant un
redémarrage accepterait tout et ne prouverait rien.

| Objet de test | Résultat |
|---|---|
| pod conforme (notre image, probes, non-root) | accepté |
| image `nginx:1.27` dans `tomtroc` | refusé : `registres-approuves` |
| pod privilégié dans `tomtroc` | refusé : `interdire-privilegie` |
| même pod privilégié dans `nfs` | accepté (exception) |
| `hostNetwork: true` dans `default` | refusé : `interdire-acces-hote` |
| volume `hostPath` dans `default` | refusé : `interdire-acces-hote` |
| Deployment sans probes dans `tomtroc` | refusé : `exiger-probes` |

Règle `generate` (`infra/kyverno/tests/isolation-reseau.yaml`) : namespace
`demo-isolation` marqué → NetworkPolicy `isolation-par-defaut` créée par Kyverno ; client
du même namespace → serveur : **200** ; loadtester du namespace `tomtroc` → serveur :
**bloqué**.

Non-régression : `helm upgrade --dry-run=server` de TomTroc accepté.

### Rapports sur l'existant (`kubectl get policyreport -A`)

- `registres-approuves`, `exiger-probes` : **aucun échec** dans `tomtroc` (TomTroc,
  MariaDB, Redis, Flagger conformes).
- `interdire-privilegie`, `interdire-acces-hote` : échecs uniquement dans `kube-system`
  (kindnet, kube-proxy, etcd, CSI NFS). Pas bloqués à l'admission (kube-system exclu par
  la configuration de Kyverno), mais visibles dans les rapports de l'analyse en
  arrière-plan : `kube-system` ajouté aux exclusions pour des rapports lisibles.
- Audit `exiger-non-root` : loadtester de Flagger, exporter MariaDB, pod du SqlJob
  (images d'autres projets) ; `exiger-labels` : pod du SqlJob. D'où le mode Audit.
