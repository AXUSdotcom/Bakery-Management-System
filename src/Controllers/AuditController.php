<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\Auth;
use App\Support\Response;

class AuditController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        $rows = Database::pdo()->query('SELECT * FROM audit_log ORDER BY happened_at DESC LIMIT 500')->fetchAll();
        Response::ok($rows);
    }
}
