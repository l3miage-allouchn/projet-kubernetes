<?php

class UserManager
{
    private PDO $pdo;

    public function __construct()
    {
        // on récupère la connexion déjà ouverte (le singleton), pas besoin d'en recréer une
        $this->pdo = Database::getInstance();
    }

    public function findByEmail(string $email): ?User
    {
        // requête préparée avec :email => protège contre les injections SQL
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        // si aucun utilisateur avec cet email, fetch() renvoie false
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    // les propriétaires d'une liste de livres, rangés par id : [id => User]
    // un même membre peut avoir plusieurs livres : on ne le cherche qu'une fois
    public function findOwnersOf(array $books): array
    {
        $owners = [];
        foreach ($books as $book) {
            $ownerId = $book->getUserId();
            if (!isset($owners[$ownerId])) {
                $owners[$ownerId] = $this->findById($ownerId);
            }
        }

        return $owners;
    }

    public function create(User $user): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (pseudo, email, password) VALUES (:pseudo, :email, :password)'
        );

        // on va chercher les valeurs via les getters, jamais un accès direct à la propriété
        $statement->execute([
            'pseudo' => $user->getPseudo(),
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
        ]);

        // lastInsertId() donne l'id auto-généré par MySQL pour la ligne qu'on vient de créer
        return (int) $this->pdo->lastInsertId();
    }

    public function update(User $user): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET pseudo = :pseudo, email = :email, password = :password, avatar = :avatar, bio = :bio
             WHERE id = :id'
        );

        $statement->execute([
            'pseudo' => $user->getPseudo(),
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
            'avatar' => $user->getAvatar(),
            'bio' => $user->getBio(),
            'id' => $user->getId(),
        ]);
    }

    // on transforme le tableau brut renvoyé par la BDD en un vrai objet User
    private function hydrate(array $row): User
    {
        $user = new User();
        $user->setId($row['id']);
        $user->setPseudo($row['pseudo']);
        $user->setEmail($row['email']);
        $user->setPassword($row['password']);
        $user->setAvatar($row['avatar']);
        $user->setBio($row['bio']);
        $user->setCreatedAt(new DateTime($row['created_at']));

        return $user;
    }
}
