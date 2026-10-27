<?php

class BookManager
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findByUserId(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        $books = [];
        foreach ($statement->fetchAll() as $row) {
            $books[] = $this->hydrate($row);
        }

        return $books;
    }

    public function findById(int $id): ?Book
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function create(Book $book): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO books (user_id, title, author, description, image, status)
             VALUES (:user_id, :title, :author, :description, :image, :status)'
        );

        $statement->execute([
            'user_id' => $book->getUserId(),
            'title' => $book->getTitle(),
            'author' => $book->getAuthor(),
            'description' => $book->getDescription(),
            'image' => $book->getImage(),
            'status' => $book->getStatus(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(Book $book): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE books
             SET title = :title, author = :author, description = :description, image = :image, status = :status
             WHERE id = :id'
        );

        $statement->execute([
            'title' => $book->getTitle(),
            'author' => $book->getAuthor(),
            'description' => $book->getDescription(),
            'image' => $book->getImage(),
            'status' => $book->getStatus(),
            'id' => $book->getId(),
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM books WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function hydrate(array $row): Book
    {
        $book = new Book();
        $book->setId($row['id']);
        $book->setUserId($row['user_id']);
        $book->setTitle($row['title']);
        $book->setAuthor($row['author']);
        $book->setDescription($row['description']);
        $book->setImage($row['image']);
        $book->setStatus($row['status']);

        return $book;
    }

    // tous les livres, du plus récent au plus ancien
    // avec une recherche facultative sur le titre
    public function findAll(?string $search = null): array
    {
        $sql = 'SELECT * FROM books';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' WHERE title LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $books = [];
        foreach ($statement->fetchAll() as $row) {
            $books[] = $this->hydrate($row);
        }

        return $books;
    }

    // les derniers livres ajoutés (pour la page d'accueil)
    public function findLatest(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM books ORDER BY created_at DESC, id DESC LIMIT :limit');
        // LIMIT attend un vrai nombre : on précise le type à PDO
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $books = [];
        foreach ($statement->fetchAll() as $row) {
            $books[] = $this->hydrate($row);
        }

        return $books;
    }
}
