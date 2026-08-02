<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\DashboardService;
use App\Support\Auth;
use App\Support\Response;

class DashboardController
{
    public static function show(): void
    {
        Auth::requireModule('dashboard');
        Response::ok(DashboardService::payload(Database::pdo()));
    }
}
