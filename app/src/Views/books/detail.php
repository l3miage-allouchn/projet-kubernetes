<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="book-detail">
    <nav class="breadcrumb" aria-label="Fil d'Ariane">
        <div class="breadcrumb__inner">
            <a href="/tomtroc/public/livres">Nos livres</a>
            &gt;
            <span aria-current="page"><?= htmlspecialchars($book->getTitle()) ?></span>
        </div>
    </nav>

    <div class="book-detail__layout">
        <div class="book-detail__visual">
            <img src="/tomtroc/public/<?= htmlspecialchars($book->getImage() ?? 'images/default-book.svg') ?>" alt="Couverture de <?= htmlspecialchars($book->getTitle()) ?>" class="book-detail__image">
        </div>

        <div class="book-detail__content">
            <h1 class="book-detail__title"><?= htmlspecialchars($book->getTitle()) ?></h1>
            <p class="book-detail__author">par <?= htmlspecialchars($book->getAuthor()) ?></p>

            <?php if ($book->getStatus() !== 'available'): ?>
                <p class="book-detail__status"><span class="badge badge--unavailable">non dispo.</span></p>
            <?php endif; ?>

            <hr class="book-detail__separator">

            <h2 class="label-caps">Description</h2>
            <p class="book-detail__description"><?= nl2br(htmlspecialchars($book->getDescription() ?? '')) ?></p>

            <?php if ($owner !== null): ?>
                <h2 class="label-caps book-detail__owner-label">Propriétaire</h2>
                <a href="/tomtroc/public/profil/<?= $owner->getId() ?>" class="owner-chip">
                    <img src="/tomtroc/public/<?= htmlspecialchars($owner->getAvatar() ?? 'images/default-avatar.svg') ?>" alt="" class="owner-chip__avatar">
                    <?= htmlspecialchars($owner->getPseudo()) ?>
                </a>

                <?php // on ne s'envoie pas de message à soi-même : pas de bouton sur ses propres livres ?>
                <?php if (($_SESSION['user_id'] ?? null) !== $owner->getId()): ?>
                    <a href="/tomtroc/public/messagerie/<?= $owner->getId() ?>" class="button button--block book-detail__contact">Envoyer un message</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
