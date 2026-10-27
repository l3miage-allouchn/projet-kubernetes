<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="profile-page">
    <h1 class="visually-hidden">Profil de <?= htmlspecialchars($user->getPseudo()) ?></h1>

    <?php
    $member = $user;
    $isOwnAccount = false;
    require __DIR__ . '/../partials/member-card.php';

    $showActions = false;
    require __DIR__ . '/../partials/library-table.php';
    ?>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
