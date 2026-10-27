<?php require __DIR__ . '/partials/header.php'; ?>

<main>
    <section class="home-hero">
        <div class="home-hero__inner">
            <div class="home-hero__text">
                <h1 class="home-hero__title">Rejoignez nos lecteurs passionnés</h1>
                <p class="home-hero__intro">Donnez une nouvelle vie à vos livres en les échangeant avec d'autres amoureux de la lecture. Nous croyons en la magie du partage de connaissances et d'histoires à travers les livres.</p>
                <a href="/tomtroc/public/livres" class="button">Découvrir</a>
            </div>
            <figure class="home-hero__figure">
                <img src="/tomtroc/public/images/hero.jpg" alt="Un libraire lit, assis au milieu des piles de livres de sa boutique" class="home-hero__image" width="404" height="539">
                <figcaption class="home-hero__credit">Hamza</figcaption>
            </figure>
        </div>
    </section>

    <section class="home-section home-section--light">
        <h2 class="home-section__title">Les derniers livres ajoutés</h2>
        <div class="book-grid home-latest__grid">
            <?php foreach ($latestBooks as $book): ?>
                <?php require __DIR__ . '/partials/book-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <a href="/tomtroc/public/livres" class="button">Voir tous les livres</a>
    </section>

    <section class="home-section">
        <h2 class="home-section__title">Comment ça marche ?</h2>
        <p class="home-steps__intro">Échanger des livres avec TomTroc c'est simple et amusant ! Suivez ces étapes pour commencer :</p>
        <ol class="home-steps">
            <li class="home-steps__item">Inscrivez-vous gratuitement sur notre plateforme.</li>
            <li class="home-steps__item">Ajoutez les livres que vous souhaitez échanger à votre profil.</li>
            <li class="home-steps__item">Parcourez les livres disponibles chez d'autres membres.</li>
            <li class="home-steps__item">Proposez un échange et discutez avec d'autres passionnés de lecture.</li>
        </ol>
        <a href="/tomtroc/public/livres" class="button button--outline">Voir tous les livres</a>
    </section>

    <div class="home-banner"></div>

    <section class="home-values">
        <div class="home-values__inner">
            <h2 class="home-section__title">Nos valeurs</h2>
            <p>Chez Tom Troc, nous mettons l'accent sur le partage, la découverte et la communauté. Nos valeurs sont ancrées dans notre passion pour les livres et notre désir de créer des liens entre les lecteurs. Nous croyons en la puissance des histoires pour rassembler les gens et inspirer des conversations enrichissantes.</p>
            <p>Notre association a été fondée avec une conviction profonde : chaque livre mérite d'être lu et partagé.</p>
            <p>Nous sommes passionnés par la création d'une plateforme conviviale qui permet aux lecteurs de se connecter, de partager leurs découvertes littéraires et d'échanger des livres qui attendent patiemment sur les étagères.</p>
            <div class="home-values__signature">
                <p class="home-values__team">L'équipe Tom Troc</p>
                <img src="/tomtroc/public/images/heart.svg" alt="" width="122" height="104">
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
