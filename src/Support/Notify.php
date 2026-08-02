<?php
namespace App\Support;

use App\Config\Database;

class Notify
{
    public static function push(string $type, string $icon, string $title, string $message, string $category): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications (type, icon, title, message, category) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$type, $icon, $title, $message, $category]);
    }
}
