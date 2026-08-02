<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\InventoryService;
use App\Domain\WastageService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class InventoryController
{
    private const EDIT_ROLES = ['admin', 'manager', 'store'];

    public static function index(): void
    {
        Auth::requireModule('inventory');
        $search = (string) ($_GET['search'] ?? '');
        $filter = (string) ($_GET['filter'] ?? 'all');
        Response::ok(InventoryService::list(Database::pdo(), $search, $filter));
    }

    public static function batches(): void
    {
        Auth::requireModule('inventory');
        Response::ok(InventoryService::allActiveBatches(Database::pdo()));
    }

    public static function newIngredient(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Response::error('Name is required');
            return;
        }
        $id = InventoryService::newIngredient(Database::pdo(), [
            'name' => $name,
            'uom' => $body['uom'] ?? 'kg',
            'unitCost' => (float) ($body['unitCost'] ?? 0),
            'reorderLevel' => (float) ($body['reorderLevel'] ?? 0),
            'supplierId' => $body['supplierId'] ?? null,
            'openingStock' => (float) ($body['openingStock'] ?? 0),
        ]);
        \App\Support\Audit::log($user, 'ADD_INGREDIENT', $name);
        Response::ok(['id' => $id]);
    }

    public static function receive(): void
    {
        Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        $qty = (float) ($body['qty'] ?? 0);
        if ($qty <= 0) {
            Response::error('Enter a quantity above zero.');
            return;
        }
        $batchId = InventoryService::receiveStock(Database::pdo(), (string) $body['ingredientId'], $qty, (int) ($body['expiryDays'] ?? 15));
        \App\Support\Audit::log(Auth::user(), 'RECEIVE', "{$qty} {$body['ingredientId']}");
        Response::ok(['batchId' => $batchId]);
    }

    public static function wasteIngredient(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        try {
            $id = WastageService::wasteIngredient(
                Database::pdo(),
                (string) $body['ingredientId'],
                (float) ($body['qty'] ?? 0),
                (string) ($body['reason'] ?? 'Damaged/Spoiled'),
                $user
            );
            Response::ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function wasteBatch(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        try {
            $id = WastageService::wasteBatch(
                Database::pdo(),
                (string) $body['batchId'],
                (float) ($body['qty'] ?? 0),
                (string) ($body['reason'] ?? 'Damaged/Spoiled'),
                $user
            );
            Response::ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function runExpiryJob(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $count = WastageService::runExpiryJob(Database::pdo(), $user);
        Response::ok(['expiredCount' => $count]);
    }
}
