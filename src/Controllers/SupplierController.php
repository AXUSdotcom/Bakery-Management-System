<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\SupplierService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class SupplierController
{
    private const EDIT_ROLES = ['admin', 'manager', 'store'];

    public static function index(): void
    {
        Auth::requireModule('suppliers');
        Response::ok(SupplierService::list(Database::pdo()));
    }

    public static function save(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        try {
            $id = SupplierService::save(Database::pdo(), $body['id'] ?? null, [
                'name' => $body['name'] ?? '',
                'contact' => $body['contact'] ?? '',
                'email' => $body['email'] ?? '',
                'leadDays' => $body['leadDays'] ?? 1,
                'suppliesSummary' => $body['suppliesSummary'] ?? '',
            ], $user);
            Response::ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function remove(string $id): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        try {
            SupplierService::remove(Database::pdo(), $id, $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }
}
