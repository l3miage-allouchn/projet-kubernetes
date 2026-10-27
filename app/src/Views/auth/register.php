<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="auth">
    <div class="auth__content">
        <h1 class="auth__title">Inscription</h1>

        <?php if (isset($error)): ?>
            <p class="form-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/inscription" class="auth__form">
            <div class="form-field">
                <label for="pseudo" class="form-label">Pseudo</label>
                <input type="text" id="pseudo" name="pseudo" class="form-input" value="<?= htmlspecialchars($pseudo) ?>" autocomplete="username" required>
            </div>

            <div class="form-field">
                <label for="email" class="form-label">Adresse email</label>
                <input type="email" id="email" name="email" class="form-input" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
            </div>

            <div class="form-field">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" id="password" name="password" class="form-input"
                       minlength="8" autocomplete="new-password" aria-describedby="password-hint" required>
                <p id="password-hint" class="form-hint">8 caractères minimum</p>
            </div>

            <button type="submit" class="button button--block">S'inscrire</button>
        </form>

        <p class="auth__switch">Déjà inscrit ? <a href="<?= BASE_URL ?>/connexion">Connectez-vous</a></p>
    </div>

    <div class="auth__visual"></div>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
