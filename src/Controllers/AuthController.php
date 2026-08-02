<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class AuthController
{
    private static function userPayload(array $u): array
    {
        return [
            'id' => $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'phone' => $u['phone'] ?? null,
            'address' => $u['address'] ?? null,
            'paymentMethod' => $u['payment_method'] ?? null,
            'permissions' => Auth::CAN[$u['role']] ?? [],
            'home' => Auth::HOME[$u['role']] ?? 'dashboard',
        ];
    }

    public static function login(): void
    {
        $body = Request::json();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '') {
            Response::error('Email and password are required', 422);
            return;
        }
        $user = Auth::login($email, $password);
        if (!$user) {
            Response::error('Invalid email/password, or this account is disabled.', 401);
            return;
        }
        Response::ok(self::userPayload($user));
    }

    public static function register(): void
    {
        $body = Request::json();
        $name = trim((string) ($body['name'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $address = trim((string) ($body['address'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($name === '' || $phone === '' || $email === '' || $address === '' || $password === '') {
            Response::error('Please fill every field — address is needed for delivery.', 422);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Enter a valid email address.', 422);
            return;
        }
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters.', 422);
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int) $stmt->fetchColumn() > 0) {
            Response::error('An account with this email already exists.', 409);
            return;
        }

        $pdo->prepare(
            'INSERT INTO users (name,email,password_hash,role,active,phone,address,payment_method) VALUES (?,?,?,\'customer\',1,?,?,\'Cash on delivery\')'
        )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $phone, $address]);

        $user = Auth::login($email, $password);
        Response::ok(self::userPayload($user));
    }

    public static function logout(): void
    {
        Auth::logout();
        Response::ok(null);
    }

    public static function me(): void
    {
        $u = Auth::user();
        if (!$u) {
            Response::error('Not authenticated', 401);
            return;
        }
        Response::ok(self::userPayload($u));
    }
}
