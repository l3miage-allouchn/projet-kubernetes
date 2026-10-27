# TomTroc

Site d'échange de livres entre membres, réalisé en PHP (architecture MVC, programmation orientée objet), sans framework ni librairie PHP.

Fonctionnalités : inscription et connexion, page Mon compte avec la bibliothèque personnelle (ajout, modification, suppression de livres), profil public des membres, liste des livres avec recherche par titre, page de détail d'un livre et messagerie entre membres.

## Prérequis

- PHP 8.1 ou plus
- MySQL ou MariaDB
- Apache 

J'ai développé le projet avec XAMPP, qui contient tout ça.

## Installation

1. Cloner le projet dans le dossier `htdocs` de XAMPP, dans un dossier nommé `tomtroc` :

```
cd C:\xampp\htdocs
git clone https://github.com/l3miage-allouchn/tomtroc.git
```

2. Démarrer Apache et MySQL dans XAMPP.

3. Dans phpMyAdmin, créer une base de données `tomtroc` (interclassement `utf8mb4_unicode_ci`), puis importer le fichier `sql/tomtroc.sql` avec l'onglet Importer. Il crée les tables et ajoute des données de test.

4. Copier le fichier `config.example.php` en `config.php` (à la racine du projet) et y mettre les identifiants de la base. Avec XAMPP, les valeurs par défaut fonctionnent (utilisateur `root`, mot de passe vide).

5. Ouvrir le site : http://localhost/tomtroc/public/

Le fichier `config.php` n'est pas versionné (il est dans le `.gitignore`), pour que les identifiants de la base ne soient pas sur GitHub.

## Comptes de test

Le mot de passe est `password123` pour tous les comptes.

- `nathalie@mail.com` (Nathalire) 
- `alex@mail.com` (Alexlecture)
- `sas634@mail.com` (Sas634)

## Organisation du code

- `public/` : point d'entrée `index.php` (toutes les routes), CSS, JS et images
- `src/Core/` : routeur, connexion à la base, autoload, validation des formulaires, envoi des images
- `src/Controllers/` : les contrôleurs
- `src/Entity/` : les entités (User, Book, Message)
- `src/Manager/` : les managers, qui contiennent les requêtes SQL
- `src/Views/` : les vues (le header et le footer sont dans `partials/`)
- `sql/tomtroc.sql` : la base de données
