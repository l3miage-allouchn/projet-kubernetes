# Respect des PSR

Les PSR (PHP Standards Recommendations) sont des règles communes pour écrire du PHP, publiées par le PHP-FIG. Le but est que tout le monde écrive le code de la même façon, pour qu'il soit facile à lire et à reprendre.

Pour le style du code, deux PSR sont utilisées dans le projet.

## PSR-1 : les règles de base

- fichiers en UTF-8, qui commencent par `<?php`
- une classe par fichier, et le fichier porte le nom de la classe (`BookController` dans `BookController.php`)
- noms de classes en PascalCase : `BookManager`, `ImageUploader`
- noms de méthodes en camelCase : `findById()`, `countUnread()`
- constantes en majuscules : `MAX_SIZE`, `BOOK_STATUSES`

## PSR-12 : la présentation

- indentation de 4 espaces (pas de tabulations)
- accolade de la classe et des méthodes sur leur propre ligne
- accolade des `if`, `foreach`… sur la même ligne
- visibilité toujours indiquée (`public`, `private`)
- un espace après `if` / `foreach` et autour des opérateurs
- pas d'espaces en fin de ligne et une ligne vide à la fin du fichier

