<?php

namespace SfphpProject\app\models;

use PDO;
use SfphpProject\src\Database;

class User
{
    public static function getAll()
    {
        $pdo = Database::connect();

        $stmt = $pdo->query("SELECT * FROM users");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getUserById(int $id)
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id and deleted_at is null");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            unset($user['password']);
            return $user;
        }

        return [];
    }

    public static function createUser(array $data)
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("INSERT INTO users (name, surname, email, nick, password) VALUES (:name, :surname, :email, :nick, :password)");
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':surname', $data['surname']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':nick', $data['nick']);
        $bcryptedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt->bindParam(':password', $bcryptedPassword);
        $stmt->execute();
        $lastUserInsertedId = $pdo->lastInsertId();
        return self::getUserById($lastUserInsertedId);
    }

    public static function updateUser(int $id, array $data)
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, password = :password WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        $bcryptedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt->bindParam(':password', $bcryptedPassword);
        $stmt->execute();
        return self::getUserById($id);
    }

    public static function deleteUser(int $id): int
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function login(
        string $email, 
        string $password
    ): User | array {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            if (password_verify($password, $user['password'])) {
                unset($user['password']);
                return $user;
            }
        }
        return [];
    }
}
