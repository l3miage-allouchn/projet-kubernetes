<?php

class ImageUploader
{
    // 2 Mo, exprimé en octets
    private const MAX_SIZE = 2 * 1024 * 1024;

    // types d'image acceptés => extension du fichier enregistré
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    // enregistre l'image envoyée dans public/uploads/{dossier}/
    // renvoie son chemin, ou null si aucun fichier n'a été envoyé
    public function upload(?array $file, string $folder): ?string
    {
        // aucun fichier choisi : ce n'est pas une erreur, l'image est facultative
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("L'envoi de l'image a échoué.");
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new RuntimeException("L'image ne doit pas dépasser 2 Mo.");
        }

        // on lit le vrai type du fichier dans son contenu, pas dans son nom
        $type = mime_content_type($file['tmp_name']);

        if (!isset(self::ALLOWED_TYPES[$type])) {
            throw new RuntimeException('Formats acceptés : JPG, PNG ou WebP.');
        }

        // nom aléatoire : évite les écrasements et les noms dangereux
        $fileName = bin2hex(random_bytes(8)) . '.' . self::ALLOWED_TYPES[$type];
        $relativePath = 'uploads/' . $folder . '/' . $fileName;

        $directory = __DIR__ . '/../../public/uploads/' . $folder;
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../../public/' . $relativePath)) {
            throw new RuntimeException("L'image n'a pas pu être enregistrée.");
        }

        return $relativePath;
    }

    // supprime une ancienne image du disque (quand on la remplace ou qu'on supprime le livre)
    public function delete(?string $relativePath): void
    {
        // on ne supprime que nos propres fichiers, rangés dans uploads/
        if ($relativePath === null || !str_starts_with($relativePath, 'uploads/')) {
            return;
        }

        $file = __DIR__ . '/../../public/' . $relativePath;
        if (is_file($file)) {
            unlink($file);
        }
    }
}
