<?php
namespace App\Support;

use App\Config\Database;

/** Session-backed auth + RBAC, ported verbatim from the prototype's CAN/HOME maps. */
class Auth
{
    public const CAN = [
        'admin'    => ['dashboard', 'inventory', 'production', 'orders', 'purchase', 'suppliers', 'products', 'wastage', 'users', 'notifications', 'audit'],
        'manager'  => ['dashboard', 'inventory', 'production', 'orders', 'purchase', 'suppliers', 'products', 'wastage', 'notifications'],
        'baker'    => ['production', 'inventory', 'products', 'orders', 'notifications'],
        'store'    => ['inventory', 'purchase', 'suppliers', 'wastage', 'notifications'],
        'customer' => ['shop', 'myorders', 'account', 'notifications'],
    ];

    public const HOME = [
        'admin' => 'dashboard', 'manager' => 'dashboard', 'baker' => 'production',
        'store' => 'inventory', 'customer' => 'shop',
    ];

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => getenv('APP_ENV') === 'production',
            ]);
            session_start();
        }
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            Response::error('Not authenticated', 401);
            exit;
        }
        return $u;
    }

    /** Guards a whole nav section, mirroring the prototype's `allowed(v)`. */
    public static function requireModule(string $module): array
    {
        $u = self::requireLogin();
        if (!in_array($module, self::CAN[$u['role']] ?? [], true)) {
            Response::error('Forbidden — your role does not have access to this section', 403);
            exit;
        }
        return $u;
    }

    /** Guards a finer-grained action (e.g. inventory edit = admin/manager/store only). */
    public static function requireRole(array $roles): array
    {
        $u = self::requireLogin();
        if (!in_array($u['role'], $roles, true)) {
            Response::error('Forbidden — insufficient permissions', 403);
            exit;
        }
        return $u;
    }

    public static function login(string $email, string $password): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        if (!$row['active']) {
            return null;
        }
        unset($row['password_hash']);
        $_SESSION['user'] = $row;
        return $row;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Refreshes the session copy of the user (e.g. after account edits). */
    public static function refresh(array $row): void
    {
        unset($row['password_hash']);
        $_SESSION['user'] = $row;
    }
}
