# Liens de démonstration TomTroc

Tous les liens commencent par `http://localhost/tomtroc/public`.

## Avant de commencer

- Démarrer Apache et MySQL dans XAMPP.
- Ouvrir les liens à l'avance dans des onglets, dans l'ordre de la démo.

Comptes de test (mot de passe `password123`) :

| Compte | Email | Livres |
|---|---|---|
| Alice | alice@tomtroc.fr | 1 et 2 |
| Bob | bob@tomtroc.fr | 3 et 4 |
| Chloé | chloe@tomtroc.fr | 5 |

## 1. Page publique

| Lien | Ce qu'on voit |
|---|---|
| http://localhost/tomtroc/public/ | L'accueil, « Bienvenue sur TomTroc » |

## 2. Authentification

| Lien | À faire | Ce qu'on montre |
|---|---|---|
| http://localhost/tomtroc/public/inscription | Créer `Demo` / `demo@tomtroc.fr` / `azerty` | L'inscription, puis le hash dans phpMyAdmin |
| http://localhost/tomtroc/public/inscription | Recommencer avec `alice@tomtroc.fr` | L'erreur « Cet email est déjà utilisé » |
| http://localhost/tomtroc/public/deconnexion | Juste ouvrir le lien | La déconnexion, avec retour à l'accueil |
| http://localhost/tomtroc/public/connexion | `alice@tomtroc.fr` avec `mauvais` | L'erreur « Email ou mot de passe incorrect » |
| http://localhost/tomtroc/public/connexion | `alice@tomtroc.fr` avec `password123` | La connexion réussie |

## 3. Le CRUD des livres (connecté en tant qu'Alice)

| Lien | À faire | Opération |
|---|---|---|
| http://localhost/tomtroc/public/mon-compte | Regarder | **Read** : les livres d'Alice (1 et 2) |
| http://localhost/tomtroc/public/livre/ajouter | Ajouter `Le Petit Prince` de `Saint-Exupéry` | **Create** |
| http://localhost/tomtroc/public/livre/1/modifier | Changer le statut en « Non disponible » | **Update** : le formulaire est prérempli |
| http://localhost/tomtroc/public/mon-compte | Cliquer sur « Supprimer » à côté du Petit Prince | **Delete**, en POST |

## 4. Sécurité

Connecté en tant qu'Alice :

| Lien | Résultat attendu | Ce qu'on prouve |
|---|---|---|
| http://localhost/tomtroc/public/livre/3/modifier | Redirection vers « Mon compte » | Alice ne peut pas modifier Dune, le livre de Bob |
| http://localhost/tomtroc/public/livre/999/modifier | Redirection vers « Mon compte » | Un livre inexistant est bien géré |

Après s'être déconnecté (http://localhost/tomtroc/public/deconnexion) :

| Lien | Résultat attendu | Ce qu'on prouve |
|---|---|---|
| http://localhost/tomtroc/public/mon-compte | Redirection vers `/connexion` | La page privée est protégée |
| http://localhost/tomtroc/public/livre/ajouter | Redirection vers `/connexion` | Impossible d'ajouter sans être connecté |

## 5. Liste publique et recherche

| Lien | Résultat attendu |
|---|---|
| http://localhost/tomtroc/public/livres | Les 4 livres disponibles. Fondation n'apparaît pas, car il est « unavailable » |
| http://localhost/tomtroc/public/livres?q=dune | Uniquement Dune |
| http://localhost/tomtroc/public/livres?q=dun | Dune aussi : le `LIKE` trouve un bout de mot |
| http://localhost/tomtroc/public/livres?q=harry | « Aucun livre trouvé » |

## 6. Erreur 404

| Lien | Résultat attendu |
|---|---|
| http://localhost/tomtroc/public/nimporte-quoi | « Page non trouvée. » avec un code 404 |
| http://localhost/tomtroc/public/livre/abc/supprimer | Une 404, parce que la suppression n'accepte que le POST |

## 7. Hors du site

| Lien | Ce qu'on montre |
|---|---|
| http://localhost/phpmyadmin | Les tables `users`, `books` et `messages`, les mots de passe hachés et la clé étrangère de `books.user_id` (onglet Structure, puis Vue relationnelle) |
| https://github.com/l3miage-allouchn/tomtroc | Les commits, et l'absence de `config.php` |

## À éviter pendant la démo

- Ne pas cliquer sur un titre dans `/livres` : la page de détail `/livre/1` n'existe pas encore, donc on tomberait sur une 404.
- Si on modifie le livre 1, le remettre en « Disponible » ensuite. Sinon, il disparaît de `/livres`.
