# L'authentification dans TomTroc

## Les fichiers

J'ai découpé l'authentification selon le modèle MVC :

- `src/Entity/User.php` : l'entité. Elle représente un utilisateur (pseudo, email, mot de passe) avec des propriétés privées et des getters/setters. Il n'y a aucun SQL dedans.
- `src/Manager/UserManager.php` : le manager. C'est le seul endroit où j'écris le SQL pour les utilisateurs : `findByEmail()` pour chercher un utilisateur et `create()` pour en enregistrer un.
- `src/Controllers/AuthController.php` : le contrôleur. Il contient la logique de l'inscription, de la connexion et de la déconnexion.
- `src/Views/auth/register.php` et `login.php` : les vues, c'est-à-dire les formulaires.
- `public/index.php` : les routes `/inscription`, `/connexion` et `/deconnexion`.

Pour l'inscription et la connexion, la route GET et la route POST appellent la même méthode. En GET, j'affiche le formulaire. En POST, je traite les données envoyées.

## L'inscription

1. Je vérifie que l'email n'est pas déjà utilisé, avec `findByEmail()`. S'il est pris, je réaffiche le formulaire avec un message d'erreur.
2. Je hache le mot de passe avec `password_hash()`.
3. J'enregistre l'utilisateur en base avec `create()`, qui me renvoie l'id généré par MySQL.
4. Je le connecte directement en mettant son id et son pseudo dans `$_SESSION`.
5. Je le redirige vers l'accueil. Comme ça, un F5 ne renvoie pas le formulaire une deuxième fois : c'est le principe Post / Redirect / Get.

L'email est aussi `UNIQUE` dans la base. Ça fait une double sécurité : le PHP affiche un message clair, et la base garantit qu'il n'y aura jamais de doublon.

## La connexion

1. Je cherche l'utilisateur par son email.
2. Je compare le mot de passe tapé avec le hash stocké, grâce à `password_verify()`.
3. Si l'email n'existe pas ou si le mot de passe est faux, j'affiche le même message : « Email ou mot de passe incorrect ». C'est volontaire, pour ne pas révéler quels emails sont inscrits.
4. Si tout est bon, je remplis la session et je redirige.

## La déconnexion

J'appelle `session_destroy()`, puis je redirige vers l'accueil.

## Le mot de passe

Je ne stocke jamais le mot de passe en clair. `password_hash()` utilise l'algorithme bcrypt :

- c'est irréversible : on ne peut pas retrouver le mot de passe à partir du hash ;
- il ajoute un sel aléatoire, donc deux personnes avec le même mot de passe n'ont pas le même hash ;
- il est volontairement lent, ce qui décourage les attaques par force brute.

Je ne peux pas comparer avec `==`, parce que le hash change à chaque fois à cause du sel. C'est pour ça que j'utilise `password_verify()`.

Je n'utilise pas `md5` ni `sha1` : ils sont trop rapides et n'ajoutent pas de sel.

## La session

HTTP ne se souvient de rien entre deux pages, donc j'utilise une session. `session_start()` est appelée dans `index.php` pour chaque page.

Le navigateur garde seulement un cookie avec un identifiant de session. L'`user_id` est stocké sur le serveur, donc l'utilisateur ne peut pas le modifier pour se faire passer pour quelqu'un d'autre.

Pour protéger une page privée, je vérifie simplement :

```php
if (!isset($_SESSION['user_id'])) {
    header('Location: /tomtroc/public/connexion');
    return;
}
```

## La sécurité

- **Injection SQL** : j'utilise des requêtes préparées (`:email`). La valeur est envoyée à part, donc elle ne peut pas être interprétée comme du SQL.
- **XSS** : j'utilise `htmlspecialchars()` dans les vues, avant d'afficher un texte.
- **Mots de passe** : ils sont hachés avec `password_hash()`.

## Ce que j'améliorerais

- Appeler `session_regenerate_id(true)` après la connexion, pour éviter le vol de session.
- Ajouter des validations côté serveur (champs vides, longueur du mot de passe, format de l'email avec `filter_var`), parce que l'attribut `required` se contourne facilement.
- Ajouter un jeton CSRF dans les formulaires.
- Faire la déconnexion en POST plutôt qu'en GET.
