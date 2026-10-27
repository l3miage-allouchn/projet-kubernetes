<?php

class MessageManager
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // tous les messages envoyés OU reçus par un utilisateur, du plus récent au plus ancien
    public function findByUserId(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM messages
             WHERE sender_id = :sender_id OR receiver_id = :receiver_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute([
            'sender_id' => $userId,
            'receiver_id' => $userId,
        ]);

        $messages = [];
        foreach ($statement->fetchAll() as $row) {
            $messages[] = $this->hydrate($row);
        }

        return $messages;
    }

    // les messages échangés entre deux utilisateurs, dans les deux sens, du plus ancien au plus récent
    public function findConversation(int $userId, int $otherUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM messages
             WHERE (sender_id = :user_id1 AND receiver_id = :other_id1)
                OR (sender_id = :other_id2 AND receiver_id = :user_id2)
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([
            'user_id1' => $userId,
            'other_id1' => $otherUserId,
            'other_id2' => $otherUserId,
            'user_id2' => $userId,
        ]);

        $messages = [];
        foreach ($statement->fetchAll() as $row) {
            $messages[] = $this->hydrate($row);
        }

        return $messages;
    }

    public function create(Message $message): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (sender_id, receiver_id, content)
             VALUES (:sender_id, :receiver_id, :content)'
        );
        $statement->execute([
            'sender_id' => $message->getSenderId(),
            'receiver_id' => $message->getReceiverId(),
            'content' => $message->getContent(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // passe à "lu" tous les messages envoyés par $senderId à $receiverId
    public function markAsRead(int $senderId, int $receiverId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET is_read = 1
             WHERE sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0'
        );
        $statement->execute([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
        ]);
    }

    // nombre de messages reçus par cet utilisateur et pas encore lus
    public function countUnread(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM messages WHERE receiver_id = :receiver_id AND is_read = 0'
        );
        $statement->execute(['receiver_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function hydrate(array $row): Message
    {
        $message = new Message();
        $message->setId($row['id']);
        $message->setSenderId($row['sender_id']);
        $message->setReceiverId($row['receiver_id']);
        $message->setContent($row['content']);
        $message->setIsRead((bool) $row['is_read']);
        $message->setCreatedAt(new DateTime($row['created_at']));

        return $message;
    }
}
