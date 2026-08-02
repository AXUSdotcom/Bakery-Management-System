<?php
namespace App\Support;

use App\Config\Database;

class Audit
{
    public static function log(?array $user, string $action, string $detail): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, user_name, action, detail) VALUES (?,?,?,?)'
        );
        $stmt->execute([$user['id'] ?? null, $user['name'] ?? 'System', $action, $detail]);
    }
}
