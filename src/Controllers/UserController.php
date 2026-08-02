<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\UsersService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class UserController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        Response::ok(UsersService::list(Database::pdo()));
    }

    public static function create(): void
    {
        $user = Auth::requireRole(['admin']);
        $body = Request::json();
        try {
            $id = UsersService::create(
                Database::pdo(),
                (string) ($body['name'] ?? ''),
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['role'] ?? 'customer'),
                $user
            );
            Response::ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function toggle(string $id): void
    {
        $user = Auth::requireRole(['admin']);
        try {
            $active = UsersService::toggle(Database::pdo(), (int) $id, $user);
            Response::ok(['active' => $active]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function changeRole(string $id): void
    {
        $user = Auth::requireRole(['admin']);
        $body = Request::json();
        try {
            UsersService::changeRole(Database::pdo(), (int) $id, (string) ($body['role'] ?? ''), $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }
}
