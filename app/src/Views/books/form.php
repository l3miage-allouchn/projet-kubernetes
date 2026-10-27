<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="book-form-page">
    <a href="/tomtroc/public/mon-compte" class="back-link">← retour</a>

    <h1 class="book-form-page__title"><?= $book->getId() === null ? 'Ajouter un livre' : 'Modifier les informations' ?></h1>

    <form method="post" enctype="multipart/form-data" class="panel book-form">
        <div class="book-form__photo">
            <p class="form-label">Photo</p>
            <img src="/tomtroc/public/<?= htmlspecialchars($book->getImage() ?? 'images/default-book.svg') ?>" alt="Couverture actuelle du livre" id="image-preview" class="book-form__image" width="488" height="488">
            <label for="image" class="book-form__photo-link">Modifier la photo</label>
            <input type="file" id="image" name="image" class="visually-hidden" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="book-form__fields">
            <?php if ($error !== null): ?>
                <p class="form-error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <div class="form-field">
                <label for="title" class="form-label">Titre</label>
                <input type="text" id="title" name="title" class="form-input form-input--grey" value="<?= htmlspecialchars($book->getTitle()) ?>" required>
            </div>

            <div class="form-field">
                <label for="author" class="form-label">Auteur</label>
                <input type="text" id="author" name="author" class="form-input form-input--grey" value="<?= htmlspecialchars($book->getAuthor()) ?>" required>
            </div>

            <div class="form-field">
                <label for="description" class="form-label">Commentaire</label>
                <textarea id="description" name="description" class="form-input form-input--grey form-textarea"><?= htmlspecialchars($book->getDescription() ?? '') ?></textarea>
            </div>

            <div class="form-field">
                <label for="status" class="form-label">Disponibilité</label>
                <select id="status" name="status" class="form-input form-input--grey form-select">
                    <option value="available" <?= $book->getStatus() === 'available' ? 'selected' : '' ?>>disponible</option>
                    <option value="unavailable" <?= $book->getStatus() !== 'available' ? 'selected' : '' ?>>non disponible</option>
                </select>
            </div>

            <button type="submit" class="button book-form__submit">Valider</button>
        </div>
    </form>
</main>

<script src="/tomtroc/public/js/image-preview.js"></script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
