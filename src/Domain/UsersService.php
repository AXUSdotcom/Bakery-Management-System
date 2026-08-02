<?php
namespace App\Domain;

use App\Support\Audit;
use PDO;

class UsersService
{
    public static function list(PDO $pdo): array
    {
        return $pdo->query('SELECT id,name,email,role,active,phone,created_at FROM users ORDER BY FIELD(role,\'admin\',\'manager\',\'baker\',\'store\',\'customer\'), name')->fetchAll();
    }

    /** Admin creates a new user with a starting password (shared with them out-of-band). */
    public static function create(PDO $pdo, string $name, string $email, string $password, string $role, array $user): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Name is required');
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid email is required');
        }
        if (strlen($password) < 6) {
            throw new \RuntimeException('Password must be at least 6 characters');
        }
        if (!array_key_exists($role, \App\Support\Auth::CAN)) {
            throw new \RuntimeException('Invalid role');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('An account with this email already exists');
        }

        $pdo->prepare('INSERT INTO users (name,email,password_hash,role,active) VALUES (?,?,?,?,1)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $id = (int) $pdo->lastInsertId();

        Audit::log($user, 'USER_ADD', $name);
        return $id;
    }

    public static function toggle(PDO $pdo, int $id, array $user): bool
    {
        $stmt = $pdo->prepare('SELECT name, active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('User not found');
        }
        $newActive = $row['active'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$newActive, $id]);
        Audit::log($user, $newActive ? 'USER_ENABLED' : 'USER_DISABLED', $row['name']);
        return (bool) $newActive;
    }

    public static function changeRole(PDO $pdo, int $id, string $role, array $user): void
    {
        if (!array_key_exists($role, \App\Support\Auth::CAN)) {
            throw new \RuntimeException('Invalid role');
        }
        $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new \RuntimeException('User not found');
        }
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
        Audit::log($user, 'USER_ROLE', "{$name} → {$role}");
    }
}
