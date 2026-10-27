# La gestion des livres dans TomTroc (CRUD)

## Les fichiers

J'ai découpé la gestion des livres selon le modèle MVC :

- `src/Entity/Book.php` : l'entité. Elle représente un livre (titre, auteur, description, image, statut, propriétaire).
- `src/Manager/BookManager.php` : le manager. Il contient tout le SQL des livres, c'est-à-dire le CRUD.
- `src/Controllers/BookController.php` : le contrôleur. Il vérifie que l'utilisateur est connecté et qu'il est bien le propriétaire du livre.
- `src/Views/books/my-books.php` : la page « Mon compte », avec la liste de mes livres.
- `src/Views/books/form.php` : le formulaire, qui sert à la fois pour l'ajout et pour la modification.
- `src/Views/books/list.php` : la liste publique des livres disponibles, avec une recherche.

## Les routes

| URL | Méthode | Action |
|---|---|---|
| `/mon-compte` | GET | voir mes livres |
| `/livre/ajouter` | GET et POST | afficher le formulaire, puis créer le livre |
| `/livre/{id}/modifier` | GET et POST | afficher le formulaire prérempli, puis enregistrer |
| `/livre/{id}/supprimer` | POST | supprimer le livre |
| `/livres` | GET | voir les livres disponibles et chercher par titre |

Mon routeur gère des paramètres dynamiques : le `{id}` de l'URL est récupéré, puis converti en entier avec `(int)` avant d'être passé au contrôleur.

## Le CRUD dans le manager

- **Create** : `create()` fait un `INSERT` et renvoie l'id généré par MySQL.
- **Read** : j'ai trois méthodes.
  - `findById()` récupère un livre, ou `null` s'il n'existe pas.
  - `findByUserId()` récupère les livres d'un utilisateur.
  - `findAvailable()` récupère les livres disponibles, avec une recherche facultative.
- **Update** : `update()` fait un `UPDATE ... WHERE id = :id`. Le `WHERE` est indispensable, sinon tous les livres seraient modifiés.
- **Delete** : `delete()` fait un `DELETE ... WHERE id = :id`.

Toutes ces requêtes sont préparées, donc protégées contre l'injection SQL.

J'ai aussi une méthode privée `hydrate()`. Elle transforme une ligne de la base (un tableau) en objet `Book`. Comme elle est utilisée par les trois `find`, je n'ai pas à répéter ce code.

Pour la recherche, je construis la requête selon les besoins : s'il y a un mot recherché, j'ajoute `AND title LIKE :search` avec des `%` autour. Chercher « dun » trouve donc « Dune ».

## La sécurité dans le contrôleur

**Il faut être connecté.** Chaque page privée commence par vérifier `$_SESSION['user_id']`. Si l'utilisateur n'est pas connecté, je le redirige vers la page de connexion, puis je fais un `return` pour que la suite du code ne s'exécute pas.

**Le propriétaire vient de la session.** Quand j'ajoute un livre, je prends le `user_id` dans la session, jamais dans le formulaire. Sinon, on pourrait créer un livre au nom de quelqu'un d'autre.

**Je vérifie le propriétaire.** Dans `edit()` et `delete()`, je vérifie que le livre existe et que son `user_id` est bien celui de l'utilisateur connecté. Sinon, je redirige. Cacher le bouton ne suffit pas, parce qu'on peut taper l'URL à la main, par exemple `/livre/3/modifier`.

**La suppression se fait en POST.** Je passe par un formulaire, pas par un simple lien. Un lien GET peut être déclenché sans le vouloir : un préchargement du navigateur, un robot, ou une image piégée.

**Post / Redirect / Get.** Après un ajout, une modification ou une suppression, je redirige vers `/mon-compte`. Comme ça, un F5 ne renvoie pas le formulaire.

## Les vues

J'utilise un seul formulaire pour l'ajout et la modification. En ajout, le contrôleur passe un livre vide, donc l'id vaut `null` et le titre affiché est « Ajouter un livre ». En modification, il passe le livre trouvé en base : les champs sont préremplis et le titre devient « Modifier le livre ». Le formulaire n'a pas d'`action`, donc il se renvoie à la même URL.

Partout où j'affiche une donnée, j'utilise `htmlspecialchars()` pour me protéger du XSS.

La recherche est en GET, parce qu'elle ne modifie rien et qu'on peut partager l'URL, par exemple `/livres?q=dune`.

## Dans la base de données

La table `books` a une clé étrangère `user_id` qui pointe vers `users(id)`, avec `ON DELETE CASCADE` :

- on ne peut pas créer un livre sans utilisateur valide ;
- si un utilisateur est supprimé, ses livres le sont aussi.

Le statut est un `ENUM('available', 'unavailable', 'exchanged')`, donc la base n'accepte que ces trois valeurs.

## Ce que j'améliorerais

- Ajouter une page de détail pour un livre (`/livre/{id}`).
- Ajouter des validations côté serveur : un titre non vide, un statut autorisé.
- Ajouter le statut « échangé » dans le formulaire.
- Remplacer l'URL de l'image par un vrai upload.
- Ajouter un jeton CSRF sur les formulaires.
- Afficher des messages de confirmation, comme « Livre ajouté ».
