<?php
// nombre de messages non lus, pour la pastille du menu
$unreadCount = 0;
if (isset($_SESSION['user_id'])) {
    $messageManager = new MessageManager();
    $unreadCount = $messageManager->countUnread($_SESSION['user_id']);
}

// l'adresse de la page en cours, pour mettre le bon lien du menu en gras
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ajouter ou modifier un livre se fait depuis "Mon compte" : c'est ce lien qui est actif
$isBookFormPage = preg_match('#^/tomtroc/public/livre/(ajouter|\d+/modifier)$#', $currentPath) === 1;
$isBooksPage = str_starts_with($currentPath, '/tomtroc/public/livre') && !$isBookFormPage;
$isAccountPage = str_starts_with($currentPath, '/tomtroc/public/mon-compte') || $isBookFormPage;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TomTroc</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Playfair+Display&display=swap">
    <link rel="stylesheet" href="/tomtroc/public/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="site-header__inner">
            <a href="/tomtroc/public/" class="site-header__logo">
                <img src="/tomtroc/public/images/logo.svg" alt="Tom Troc - Accueil" width="155" height="51">
            </a>

            <nav class="site-header__nav" aria-label="Navigation principale">
                <a href="/tomtroc/public/" class="site-header__link <?= $currentPath === '/tomtroc/public/' ? 'site-header__link--active' : '' ?>">Accueil</a>
                <a href="/tomtroc/public/livres" class="site-header__link <?= $isBooksPage ? 'site-header__link--active' : '' ?>">Nos livres à l'échange</a>
            </nav>

            <nav class="site-header__nav site-header__nav--account" aria-label="Espace membre">
                <a href="/tomtroc/public/messagerie" class="site-header__link <?= str_starts_with($currentPath, '/tomtroc/public/messagerie') ? 'site-header__link--active' : '' ?>">
                    <img src="/tomtroc/public/images/icon-messagerie.svg" alt="" width="15" height="14">
                    Messagerie
                    <?php if ($unreadCount > 0): ?>
                        <span class="unread-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="/tomtroc/public/mon-compte" class="site-header__link <?= $isAccountPage ? 'site-header__link--active' : '' ?>">
                    <img src="/tomtroc/public/images/icon-mon-compte.svg" alt="" width="10" height="13">
                    Mon compte
                </a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="/tomtroc/public/deconnexion" class="site-header__link">Déconnexion</a>
                <?php else: ?>
                    <a href="/tomtroc/public/connexion" class="site-header__link <?= in_array($currentPath, ['/tomtroc/public/connexion', '/tomtroc/public/inscription']) ? 'site-header__link--active' : '' ?>">Connexion</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
