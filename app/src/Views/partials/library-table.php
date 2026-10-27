<?php
// tableau des livres d'un membre ("Mon compte" et profil public)
// variables attendues : $books (les livres), $showActions (true sur "Mon compte" : colonnes disponibilité et actions)
?>
<div class="panel library">
    <?php if (empty($books)): ?>
        <p class="library__empty">Aucun livre dans cette bibliothèque pour le moment.</p>
    <?php else: ?>
        <table class="library__table">
            <thead>
                <tr>
                    <th scope="col" class="label-caps">Photo</th>
                    <th scope="col" class="label-caps">Titre</th>
                    <th scope="col" class="label-caps">Auteur</th>
                    <th scope="col" class="label-caps">Description</th>
                    <?php if ($showActions): ?>
                        <th scope="col" class="label-caps">Disponibilité</th>
                        <th scope="col" class="label-caps">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books as $book): ?>
                    <tr>
                        <td>
                            <img src="/tomtroc/public/<?= htmlspecialchars($book->getImage() ?? 'images/default-book.svg') ?>" alt="" class="library__photo" width="78" height="78">
                        </td>
                        <td><a href="/tomtroc/public/livre/<?= $book->getId() ?>" class="library__link"><?= htmlspecialchars($book->getTitle()) ?></a></td>
                        <td><?= htmlspecialchars($book->getAuthor()) ?></td>
                        <td>
                            <?php // description coupée proprement (mb_ : ne casse pas les accents) ?>
                            <p class="library__description"><?= htmlspecialchars(mb_strimwidth($book->getDescription() ?? '', 0, 85, '...')) ?></p>
                        </td>
                        <?php if ($showActions): ?>
                            <td>
                                <?php if ($book->getStatus() === 'available'): ?>
                                    <span class="badge badge--available">disponible</span>
                                <?php else: ?>
                                    <span class="badge badge--unavailable">non dispo.</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="library__actions">
                                    <a href="/tomtroc/public/livre/<?= $book->getId() ?>/modifier" class="library__edit">Éditer</a>
                                    <form method="post" action="/tomtroc/public/livre/<?= $book->getId() ?>/supprimer"
                                          class="js-confirm-delete"
                                          data-confirm="Voulez-vous vraiment supprimer « <?= htmlspecialchars($book->getTitle()) ?> » ? Cette action est définitive.">
                                        <button type="submit" class="library__delete">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
