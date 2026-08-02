<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\Auth;
use App\Support\Response;

class NotificationController
{
    public static function index(): void
    {
        Auth::requireLogin();
        $rows = Database::pdo()->query('SELECT * FROM notifications ORDER BY created_at DESC LIMIT 200')->fetchAll();
        Response::ok($rows);
    }

    public static function markRead(string $id): void
    {
        Auth::requireLogin();
        Database::pdo()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([(int) $id]);
        Response::ok(null);
    }

    public static function markAllRead(): void
    {
        Auth::requireLogin();
        Database::pdo()->exec('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
        Response::ok(null);
    }
}
