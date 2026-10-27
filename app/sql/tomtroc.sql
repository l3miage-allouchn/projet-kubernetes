-- Base de données TomTroc
-- À importer dans une base vide nommée "tomtroc" (phpMyAdmin > Importer, ou en ligne de commande) :
--   mysql -u root --default-character-set=utf8mb4 tomtroc < sql/tomtroc.sql

SET NAMES utf8mb4;

-- on repart de zéro si les tables existent déjà (messages et books d'abord, à cause des clés étrangères)
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS users;

-- Structure des tables

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pseudo VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    status ENUM('available', 'unavailable', 'exchanged') DEFAULT 'available',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    content TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données de test, reprises de la maquette
-- Mot de passe en clair pour tous les comptes : password123

INSERT INTO users (id, pseudo, email, password, avatar, created_at) VALUES
(1, 'Nathalire', 'nathalie@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', 'images/demo/avatars/nathalire.jpg', '2025-06-12 10:00:00'),
(2, 'Alexlecture', 'alex@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', 'images/demo/avatars/alexlecture.jpg', '2025-04-03 14:30:00'),
(3, 'Sas634', 'sas634@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', 'images/demo/avatars/sas634.jpg', '2025-09-21 09:15:00'),
(4, 'Hugo1990_12', 'hugo@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2024-11-08 18:00:00'),
(5, 'Christiane75014', 'christiane@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2025-01-19 11:45:00'),
(6, 'Hamzalecture', 'hamza@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2025-03-27 16:20:00'),
(7, 'Lou&Ben50', 'louetben@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2025-07-02 08:30:00'),
(8, 'ML95', 'ml95@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2025-05-14 20:10:00'),
(9, 'Verogo33', 'vero@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2025-02-06 13:00:00'),
(10, 'Lotrfanclub67', 'lotr@mail.com', '$2y$10$W2DncNKoR4Vg41BBD0Z.fOK9PBAMVTCTOuvlaikA/WlvtWul2ZlIa', NULL, '2024-12-24 17:40:00');

-- les dates de création donnent l'ordre d'affichage (les plus récents d'abord), comme sur la maquette
INSERT INTO books (user_id, title, author, description, image, status, created_at) VALUES
(2, 'Esther', 'Alabaster', 'Une édition soignée du livre d''Esther, avec de magnifiques photographies qui accompagnent le texte. Un bel objet à feuilleter autant qu''à lire.', 'images/demo/books/esther.jpg', 'available', '2026-09-20 10:00:00'),
(1, 'The Kinfolk Table', 'Nathan Williams', 'J''ai récemment plongé dans les pages de ''The Kinfolk Table'' et j''ai été enchanté par cette œuvre captivante. Ce livre va bien au-delà d''une simple collection de recettes ; il célèbre l''art de partager des moments authentiques autour de la table.\n\nLes photographies magnifiques et le ton chaleureux captivent dès le départ, transportant le lecteur dans un voyage à travers des recettes et des histoires qui mettent en avant la beauté de la simplicité et de la convivialité.\n\nChaque page est une invitation à ralentir, à savourer et à créer des souvenirs durables avec les êtres chers.\n\n''The Kinfolk Table'' incarne parfaitement l''esprit de la cuisine et de la camaraderie, et il est certain que ce livre trouvera une place spéciale dans le cœur de tout amoureux de la cuisine et des rencontres inspirantes.', 'images/demo/books/the-kinfolk-table.jpg', 'available', '2026-09-19 10:00:00'),
(2, 'Wabi Sabi', 'Beth Kempton', 'Une belle introduction à la philosophie japonaise du wabi sabi : accepter l''imperfection et trouver la beauté dans les choses simples du quotidien.', 'images/demo/books/wabi-sabi.jpg', 'available', '2026-09-18 10:00:00'),
(4, 'Milk & honey', 'Rupi Kaur', 'Un recueil de poèmes courts et puissants sur l''amour, la perte et la guérison. Se lit d''une traite et se relit avec plaisir.', 'images/demo/books/milk-and-honey.jpg', 'available', '2026-09-17 10:00:00'),
(1, 'Delight!', 'Justin Rossow', 'Un petit livre plein d''énergie qui invite à retrouver la joie dans les gestes de tous les jours. Idéal pour se remonter le moral.', 'images/demo/books/delight.jpg', 'unavailable', '2026-09-16 10:00:00'),
(5, 'Milwaukee Mission', 'Elder Cooper Low', 'Un récit sobre et touchant, raconté avec beaucoup de sincérité. La couverture minimaliste cache une histoire riche.', 'images/demo/books/milwaukee-mission.jpg', 'available', '2026-09-15 10:00:00'),
(6, 'Minimalist Graphics', 'Julia Schonlau', 'Un ouvrage de référence sur le design graphique minimaliste, avec de nombreux exemples. Parfait pour les curieux comme pour les graphistes.', 'images/demo/books/minimalist-graphics.jpg', 'available', '2026-09-14 10:00:00'),
(1, 'Hygge', 'Meik Wiking', 'Le secret du bonheur à la danoise : bougies, plaids, bons repas et moments partagés. Un livre doux et réconfortant.', 'images/demo/books/hygge.jpg', 'available', '2026-09-13 10:00:00'),
(7, 'Innovation', 'Matt Ridley', 'Comment naissent les innovations ? Matt Ridley montre qu''elles sont le fruit de la collaboration et de l''essai-erreur plutôt que d''un éclair de génie.', 'images/demo/books/innovation.jpg', 'available', '2026-09-12 10:00:00'),
(2, 'Psalms', 'Alabaster', 'Les Psaumes dans une édition illustrée de photographies contemplatives. Un livre apaisant, à garder à portée de main.', 'images/demo/books/psalms.jpg', 'available', '2026-09-11 10:00:00'),
(3, 'Thinking, Fast & Slow', 'Daniel Kahneman', 'Le célèbre ouvrage sur nos deux modes de pensée, l''un rapide et intuitif, l''autre lent et réfléchi. Passionnant et très éclairant sur nos décisions.', 'images/demo/books/thinking-fast-and-slow.jpg', 'unavailable', '2026-09-10 10:00:00'),
(8, 'A Book Full Of Hope', 'Rupi Kaur', 'Un recueil lumineux, plein de mots doux et d''encouragements. À offrir ou à s''offrir dans les moments difficiles.', 'images/demo/books/a-book-full-of-hope.jpg', 'available', '2026-09-09 10:00:00'),
(9, 'The Subtle Art Of Not Giving A F*ck', 'Mark Manson', 'Un livre de développement personnel qui prend le contre-pied des conseils habituels. Drôle, direct et souvent très juste.', 'images/demo/books/the-subtle-art-of.jpg', 'available', '2026-09-08 10:00:00'),
(1, 'Narnia', 'C.S Lewis', 'Le monde merveilleux de Narnia, ses créatures magiques et ses aventures inoubliables. Un classique à lire à tout âge.', 'images/demo/books/narnia.jpg', 'unavailable', '2026-09-07 10:00:00'),
(2, 'Company Of One', 'Paul Jarvis', 'Et si rester petit était la meilleure façon de réussir ? Une réflexion intéressante sur l''entrepreneuriat à taille humaine.', 'images/demo/books/company-of-one.jpg', 'available', '2026-09-06 10:00:00'),
(10, 'The Two Towers', 'J.R.R Tolkien', 'Le deuxième tome du Seigneur des Anneaux. La communauté est dispersée, et l''aventure devient plus sombre et plus épique.', 'images/demo/books/the-two-towers.jpg', 'available', '2026-09-05 10:00:00');

-- conversations de Nathalire (id 1), comme sur la maquette de la messagerie
-- le dernier message d'Alexlecture n'est pas lu : il fait apparaître la pastille "1" dans le menu
INSERT INTO messages (sender_id, receiver_id, content, is_read, created_at) VALUES
(2, 1, 'Bonjour ! Ton exemplaire de « The Kinfolk Table » est-il toujours disponible ?', 1, '2026-09-21 15:44:00'),
(1, 2, 'Bonjour ! Oui, il est toujours disponible. Tu voudrais l''échanger contre quel livre ?', 1, '2026-09-21 15:48:00'),
(2, 1, 'Je peux te proposer « Wabi Sabi » de Beth Kempton, il est en très bon état.', 0, '2026-09-28 15:43:00'),
(3, 1, 'Salut ! Je cherche « Hygge » depuis un moment, tu l''as encore ?', 1, '2026-08-15 10:12:00'),
(1, 3, 'Oui ! On peut se retrouver samedi pour l''échange si tu veux.', 1, '2026-08-15 11:30:00'),
(4, 1, 'Bonjour, j''ai vu que « Narnia » n''est plus disponible, dommage ! Tu me préviens s''il revient ?', 1, '2026-08-20 18:05:00');
