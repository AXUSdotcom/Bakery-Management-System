<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\CatalogueService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class ProductController
{
    private const EDIT_ROLES = ['admin', 'manager'];

    public static function index(): void
    {
        Auth::requireModule('products');
        Response::ok(CatalogueService::list(Database::pdo()));
    }

    public static function save(): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        $body = Request::json();
        $recipe = array_map(fn($r) => ['ingredientId' => $r['ingredientId'], 'qtyPerUnit' => (float) $r['qtyPerUnit']], $body['recipe'] ?? []);
        try {
            $id = CatalogueService::save(Database::pdo(), $body['id'] ?? null, [
                'name' => $body['name'] ?? '',
                'emoji' => $body['emoji'] ?? '',
                'price' => $body['price'] ?? 0,
                'shelfStock' => $body['shelfStock'] ?? 0,
                'description' => $body['description'] ?? '',
            ], $recipe, $user);
            Response::ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function remove(string $id): void
    {
        $user = Auth::requireRole(self::EDIT_ROLES);
        CatalogueService::remove(Database::pdo(), $id, $user);
        Response::ok(null);
    }
}
