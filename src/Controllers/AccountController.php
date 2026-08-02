<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class AccountController
{
    public static function show(): void
    {
        $user = Auth::requireRole(['customer']);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS spent FROM orders WHERE customer_id = ? AND status != 'Cancelled'");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        $spent = (float) $row['spent'];

        Response::ok([
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'address' => $user['address'],
            'paymentMethod' => $user['payment_method'],
            'totalOrders' => (int) $row['n'],
            'totalSpent' => round($spent, 2),
            'loyaltyPoints' => (int) floor($spent / 100),
        ]);
    }

    public static function save(): void
    {
        $user = Auth::requireRole(['customer']);
        $body = Request::json();
        $name = trim((string) ($body['name'] ?? '')) ?: $user['name'];
        $phone = (string) ($body['phone'] ?? '');
        $address = trim((string) ($body['address'] ?? ''));
        $payment = (string) ($body['paymentMethod'] ?? 'Cash on delivery');

        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET name=?, phone=?, address=?, payment_method=? WHERE id=?')
            ->execute([$name, $phone, $address, $payment, $user['id']]);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        Auth::refresh($stmt->fetch());

        Response::ok(null);
    }
}
