<?php

// règles de validation des formulaires, vérifiées côté serveur
// (les attributs HTML comme "required" se contournent facilement)
// chaque méthode renvoie le message de la première erreur trouvée, ou null si tout est bon
class Validator
{
    // statuts qu'un membre peut choisir dans le formulaire d'un livre
    public const BOOK_STATUSES = ['available', 'unavailable'];

    public static function validateUser(string $pseudo, string $email, string $password, bool $isPasswordRequired): ?string
    {
        if ($pseudo === '') {
            return 'Le pseudo est obligatoire.';
        }

        // mb_strlen compte les caractères (et non les octets) : "é" compte pour 1
        if (mb_strlen($pseudo) > 50) {
            return 'Le pseudo ne doit pas dépasser 50 caractères.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            return "L'adresse email n'est pas valide.";
        }

        // dans "Mon compte", le mot de passe est facultatif : vide = on garde l'ancien
        if ($password === '' && !$isPasswordRequired) {
            return null;
        }

        if (mb_strlen($password) < 8) {
            return 'Le mot de passe doit contenir au moins 8 caractères.';
        }

        return null;
    }

    public static function validateBook(string $title, string $author, string $status): ?string
    {
        if ($title === '' || mb_strlen($title) > 255) {
            return 'Le titre est obligatoire (255 caractères maximum).';
        }

        if ($author === '' || mb_strlen($author) > 255) {
            return "L'auteur est obligatoire (255 caractères maximum).";
        }

        // liste blanche : on n'accepte que les valeurs prévues
        if (!in_array($status, self::BOOK_STATUSES, true)) {
            return 'Le statut de disponibilité est invalide.';
        }

        return null;
    }

    public static function validateMessage(string $content): ?string
    {
        if ($content === '') {
            return 'Le message ne peut pas être vide.';
        }

        if (mb_strlen($content) > 1000) {
            return 'Le message ne doit pas dépasser 1000 caractères.';
        }

        return null;
    }
}
