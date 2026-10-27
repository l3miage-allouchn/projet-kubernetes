<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="messaging-page">
    <div class="messaging">
        <aside class="messaging__sidebar">
            <h1 class="messaging__title">Messagerie</h1>

            <?php if (empty($conversations)): ?>
                <p class="messaging__empty">Vous n'avez aucun message pour le moment.</p>
            <?php else: ?>
                <ul class="conversation-list">
                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $contact = $conversation['user'];
                        $lastMessage = $conversation['lastMessage'];
                        // aujourd'hui : on affiche l'heure ; sinon : le jour et le mois
                        $isToday = $lastMessage->getCreatedAt()->format('Y-m-d') === date('Y-m-d');
                        $isCurrent = $otherUser !== null && $otherUser->getId() === $contact->getId();
                        ?>
                        <li>
                            <a href="<?= BASE_URL ?>/messagerie/<?= $contact->getId() ?>"
                               class="conversation-list__item <?= $isCurrent ? 'conversation-list__item--current' : '' ?> <?= $conversation['unreadCount'] > 0 ? 'conversation-list__item--unread' : '' ?>"
                               <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($contact->getAvatar() ?? 'images/default-avatar.svg') ?>" alt="" class="avatar avatar--48">
                                <span class="conversation-list__text">
                                    <span class="conversation-list__top">
                                        <span class="conversation-list__name"><?= htmlspecialchars($contact->getPseudo()) ?></span>
                                        <span class="conversation-list__date"><?= $lastMessage->getCreatedAt()->format($isToday ? 'H:i' : 'd.m') ?></span>
                                    </span>
                                    <span class="conversation-list__preview"><?= htmlspecialchars($lastMessage->getContent()) ?></span>
                                    <?php if ($conversation['unreadCount'] > 0): ?>
                                        <span class="visually-hidden"><?= $conversation['unreadCount'] ?> message(s) non lu(s)</span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </aside>

        <section class="thread">
            <?php if ($otherUser === null): ?>
                <h2 class="visually-hidden">Conversation</h2>
                <p class="thread__placeholder">Sélectionnez une conversation pour afficher les messages.</p>
            <?php else: ?>
                <h2 class="thread__header">
                    <a href="<?= BASE_URL ?>/profil/<?= $otherUser->getId() ?>" class="thread__contact">
                        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($otherUser->getAvatar() ?? 'images/default-avatar.svg') ?>" alt="" class="avatar avatar--48">
                        <?= htmlspecialchars($otherUser->getPseudo()) ?>
                    </a>
                </h2>

                <?php // column-reverse : la zone défile en partant du bas, là où sont les messages les plus récents ?>
                <div class="thread__scroll">
                    <?php if (empty($messages)): ?>
                        <p class="thread__placeholder">Aucun message pour le moment. Écrivez le premier !</p>
                    <?php else: ?>
                        <ol class="thread__messages">
                            <?php foreach ($messages as $message): ?>
                                <?php $isMine = $message->getSenderId() === $currentUserId; ?>
                                <li class="message <?= $isMine ? 'message--mine' : 'message--theirs' ?>">
                                    <p class="message__meta">
                                        <?php if (!$isMine): ?>
                                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars($otherUser->getAvatar() ?? 'images/default-avatar.svg') ?>" alt="" class="avatar avatar--24">
                                        <?php endif; ?>
                                        <span class="visually-hidden"><?= $isMine ? 'Vous' : htmlspecialchars($otherUser->getPseudo()) ?>, le</span>
                                        <?= $message->getCreatedAt()->format('d.m H:i') ?>
                                    </p>
                                    <p class="message__bubble"><?= nl2br(htmlspecialchars($message->getContent())) ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>

                <form method="post" class="thread__form">
                    <label for="content" class="visually-hidden">Votre message</label>
                    <input type="text" id="content" name="content" class="form-input thread__input" placeholder="Tapez votre message ici" maxlength="1000" autocomplete="off" required>
                    <button type="submit" class="button thread__send">Envoyer</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
