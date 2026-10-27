<?php

class BookController
{
    public function add(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /tomtroc/public/connexion');
            return;
        }

        $book = new Book();
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? '';

            // on remplit le livre dans tous les cas : en cas d'erreur, le formulaire est réaffiché avec ces valeurs
            $book->setUserId($_SESSION['user_id']);
            $book->setTitle($title);
            $book->setAuthor($author);
            $book->setDescription($description);
            $book->setStatus($status);

            $error = Validator::validateBook($title, $author, $status);

            if ($error === null) {
                try {
                    $imageUploader = new ImageUploader();
                    $book->setImage($imageUploader->upload($_FILES['image'] ?? null, 'books'));

                    $bookManager = new BookManager();
                    $bookManager->create($book);

                    header('Location: /tomtroc/public/mon-compte');
                    return;
                } catch (RuntimeException $exception) {
                    // image refusée : on réaffiche le formulaire avec le message
                    $error = $exception->getMessage();
                }
            }
        }

        require __DIR__ . '/../Views/books/form.php';
    }

    public function edit(int $id): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /tomtroc/public/connexion');
            return;
        }

        $bookManager = new BookManager();
        $book = $bookManager->findById($id);

        if ($book === null || $book->getUserId() !== $_SESSION['user_id']) {
            header('Location: /tomtroc/public/mon-compte');
            return;
        }

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? '';

            $book->setTitle($title);
            $book->setAuthor($author);
            $book->setDescription($description);
            $book->setStatus($status);

            $error = Validator::validateBook($title, $author, $status);

            if ($error === null) {
                try {
                    $imageUploader = new ImageUploader();
                    $newImage = $imageUploader->upload($_FILES['image'] ?? null, 'books');

                    // nouvelle image envoyée : on remplace l'ancienne
                    if ($newImage !== null) {
                        $imageUploader->delete($book->getImage());
                        $book->setImage($newImage);
                    }

                    $bookManager->update($book);

                    header('Location: /tomtroc/public/mon-compte');
                    return;
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        require __DIR__ . '/../Views/books/form.php';
    }

    public function delete(int $id): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /tomtroc/public/connexion');
            return;
        }

        $bookManager = new BookManager();
        $book = $bookManager->findById($id);

        if ($book !== null && $book->getUserId() === $_SESSION['user_id']) {
            // on supprime aussi le fichier image, sinon il resterait sur le disque
            $imageUploader = new ImageUploader();
            $imageUploader->delete($book->getImage());

            $bookManager->delete($id);
        }

        header('Location: /tomtroc/public/mon-compte');
    }

    public function list(): void
    {
        $search = $_GET['q'] ?? null;

        $bookManager = new BookManager();
        $books = $bookManager->findAll($search);

        $userManager = new UserManager();
        $owners = $userManager->findOwnersOf($books);

        require __DIR__ . '/../Views/books/list.php';
    }

    public function detail(int $id): void
    {
        $bookManager = new BookManager();
        $book = $bookManager->findById($id);

        if ($book === null) {
            header('Location: /tomtroc/public/livres');
            return;
        }

        // le livre ne connaît que l'id de son propriétaire : on va chercher le User complet
        $userManager = new UserManager();
        $owner = $userManager->findById($book->getUserId());

        require __DIR__ . '/../Views/books/detail.php';
    }
}
