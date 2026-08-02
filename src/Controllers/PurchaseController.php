<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\PurchasingService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class PurchaseController
{
    private const ACT_ROLES = ['admin', 'manager', 'store'];

    public static function index(): void
    {
        Auth::requireModule('purchase');
        $status = (string) ($_GET['status'] ?? 'all');
        Response::ok(PurchasingService::list(Database::pdo(), $status));
    }

    public static function auto(): void
    {
        $user = Auth::requireRole(self::ACT_ROLES);
        $body = Request::json();
        try {
            $id = PurchasingService::autoPO(Database::pdo(), (string) $body['ingredientId'], $user);
            Response::ok(['poId' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function autoAll(): void
    {
        $user = Auth::requireRole(self::ACT_ROLES);
        $ids = PurchasingService::autoPOAll(Database::pdo(), $user);
        Response::ok(['poIds' => $ids]);
    }

    public static function preview(string $id): void
    {
        Auth::requireModule('purchase');
        $po = PurchasingService::preview(Database::pdo(), $id);
        if (!$po) {
            Response::error('Purchase order not found', 404);
            return;
        }
        Response::ok($po);
    }

    public static function send(string $id): void
    {
        $user = Auth::requireRole(self::ACT_ROLES);
        try {
            PurchasingService::send(Database::pdo(), $id, $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function cancel(string $id): void
    {
        $user = Auth::requireRole(self::ACT_ROLES);
        try {
            PurchasingService::cancel(Database::pdo(), $id, $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function receive(string $id): void
    {
        $user = Auth::requireRole(self::ACT_ROLES);
        try {
            PurchasingService::receive(Database::pdo(), $id, $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }
}
