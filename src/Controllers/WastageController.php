<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\WastageService;
use App\Support\Auth;
use App\Support\Response;

class WastageController
{
    public static function index(): void
    {
        Auth::requireModule('wastage');
        $pdo = Database::pdo();
        Response::ok([
            'log' => WastageService::log($pdo),
            'totals' => WastageService::totals($pdo),
        ]);
    }
}
