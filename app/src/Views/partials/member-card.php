<?php
// carte d'un membre (colonne de gauche de "Mon compte" et du profil public)
// variables attendues : $member (le User), $books (ses livres), $isOwnAccount (true sur "Mon compte")

// ancienneté : "1 an", "3 ans", ou "moins d'un an"
$years = $member->getCreatedAt()->diff(new DateTime())->y;
$seniority = $years === 0 ? "moins d'un an" : $years . ' an' . ($years > 1 ? 's' : '');

$bookCount = count($books);
?>
<section class="panel member-card">
    <div class="member-card__photo">
        <img src="/tomtroc/public/<?= htmlspecialchars($member->getAvatar() ?? 'images/default-avatar.svg') ?>" alt="Photo de profil de <?= htmlspecialchars($member->getPseudo()) ?>" class="member-card__avatar" width="135" height="135">

        <?php if ($isOwnAccount): ?>
            <label for="avatar" class="member-card__edit">modifier</label>
            <input type="file" id="avatar" name="avatar" class="visually-hidden" accept="image/jpeg,image/png,image/webp">
        <?php endif; ?>
    </div>

    <hr class="member-card__separator">

    <h2 class="member-card__pseudo"><?= htmlspecialchars($member->getPseudo()) ?></h2>
    <p class="member-card__since">Membre depuis <?= $seniority ?></p>

    <p class="label-caps member-card__library-label">Bibliothèque</p>
    <p class="member-card__count">
        <img src="/tomtroc/public/images/icon-library.svg" alt="" width="11" height="14">
        <?= $bookCount ?> livre<?= $bookCount > 1 ? 's' : '' ?>
    </p>

    <?php if ($isOwnAccount): ?>
        <a href="/tomtroc/public/livre/ajouter" class="button button--outline member-card__action">Ajouter un livre</a>
    <?php else: ?>
        <a href="/tomtroc/public/messagerie/<?= $member->getId() ?>" class="button button--outline member-card__action">Écrire un message</a>
    <?php endif; ?>
</section>
