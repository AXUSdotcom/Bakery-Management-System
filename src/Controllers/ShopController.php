<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\Auth;
use App\Support\Response;

/** Customer-safe product listing — deliberately excludes recipe/cost/margin, which stay staff-only under ProductController. */
class ShopController
{
    public static function products(): void
    {
        Auth::requireModule('shop');
        $rows = Database::pdo()->query(
            'SELECT id, name, emoji, price, shelf_stock, description FROM products ORDER BY name'
        )->fetchAll();
        Response::ok($rows);
    }
}
