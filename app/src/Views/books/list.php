<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="books-page">
    <div class="books-page__header">
        <h1 class="books-page__title">Nos livres à l'échange</h1>

        <form method="get" class="search" role="search">
            <label for="q" class="visually-hidden">Rechercher un livre par son titre</label>
            <input type="search" id="q" name="q" class="form-input search__input" placeholder="Rechercher un livre" value="<?= htmlspecialchars($search ?? '') ?>">
        </form>
    </div>

    <?php if (empty($books)): ?>
        <p class="books-page__empty">Aucun livre ne correspond à votre recherche.</p>
    <?php else: ?>
        <h2 class="visually-hidden">Tous les livres</h2>
        <div class="book-grid">
            <?php foreach ($books as $book): ?>
                <?php require __DIR__ . '/../partials/book-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
