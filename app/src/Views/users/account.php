<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="account-page">
    <h1 class="account-page__title">Mon compte</h1>

    <?php // un seul formulaire pour les deux cartes : la photo (à gauche) et les informations (à droite) ?>
    <form method="post" enctype="multipart/form-data" class="account-page__cards">
        <?php
        $member = $user;
        $isOwnAccount = true;
        require __DIR__ . '/../partials/member-card.php';
        ?>

        <section class="panel account-form">
            <h2 class="account-form__title">Vos informations personnelles</h2>

            <?php if ($error !== null): ?>
                <p class="form-error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <div class="form-field">
                <label for="email" class="form-label">Adresse email</label>
                <input type="email" id="email" name="email" class="form-input form-input--grey" value="<?= htmlspecialchars($user->getEmail()) ?>" autocomplete="email" required>
            </div>

            <div class="form-field">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" id="password" name="password" class="form-input form-input--grey" placeholder="••••••••" minlength="8" autocomplete="new-password" aria-describedby="password-help">
                <p id="password-help" class="form-hint">8 caractères minimum. Laissez vide pour garder votre mot de passe actuel.</p>
            </div>

            <div class="form-field">
                <label for="pseudo" class="form-label">Pseudo</label>
                <input type="text" id="pseudo" name="pseudo" class="form-input form-input--grey" value="<?= htmlspecialchars($user->getPseudo()) ?>" autocomplete="username" required>
            </div>

            <button type="submit" class="button button--outline">Enregistrer</button>
        </section>
    </form>

    <?php
    $showActions = true;
    require __DIR__ . '/../partials/library-table.php';
    ?>
</main>

<script src="<?= BASE_URL ?>/js/avatar-upload.js"></script>
<script src="<?= BASE_URL ?>/js/confirm-delete.js"></script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
