<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="auth">
    <div class="auth__content">
        <h1 class="auth__title">Connexion</h1>

        <?php if (isset($error)): ?>
            <p class="form-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="post" action="/tomtroc/public/connexion" class="auth__form">
            <div class="form-field">
                <label for="email" class="form-label">Adresse email</label>
                <input type="email" id="email" name="email" class="form-input" value="<?= htmlspecialchars($email) ?>" autocomplete="email" required>
            </div>

            <div class="form-field">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" id="password" name="password" class="form-input" autocomplete="current-password" required>
            </div>

            <button type="submit" class="button button--block">Se connecter</button>
        </form>

        <p class="auth__switch">Pas de compte ? <a href="/tomtroc/public/inscription">Inscrivez-vous</a></p>
    </div>

    <div class="auth__visual"></div>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
