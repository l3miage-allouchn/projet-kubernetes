<?php
// carte d'un livre, utilisée sur l'accueil et sur "Nos livres à l'échange"
// variables attendues : $book (le livre) et $owners (les propriétaires, rangés par id)
$owner = $owners[$book->getUserId()] ?? null;
?>
<a href="/tomtroc/public/livre/<?= $book->getId() ?>" class="book-card">
    <div class="book-card__visual">
        <img src="/tomtroc/public/<?= htmlspecialchars($book->getImage() ?? 'images/default-book.svg') ?>" alt="" class="book-card__image">
        <?php if ($book->getStatus() !== 'available'): ?>
            <span class="badge badge--unavailable book-card__badge">non dispo.</span>
        <?php endif; ?>
    </div>
    <div class="book-card__body">
        <h3 class="book-card__title"><?= htmlspecialchars($book->getTitle()) ?></h3>
        <p class="book-card__author"><?= htmlspecialchars($book->getAuthor()) ?></p>
        <?php if ($owner !== null): ?>
            <p class="book-card__owner">Vendu par : <?= htmlspecialchars($owner->getPseudo()) ?></p>
        <?php endif; ?>
    </div>
</a>
