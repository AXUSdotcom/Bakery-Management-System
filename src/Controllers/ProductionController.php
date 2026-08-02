<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\ProductionService;
use App\Domain\ProductionShortfallException;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class ProductionController
{
    private const CONFIRM_ROLES = ['admin', 'manager', 'baker'];
    private const PO_ROLES = ['admin', 'manager', 'store'];

    private static function normalizePlan(array $raw): array
    {
        $plan = [];
        foreach ($raw as $productId => $qty) {
            $qty = max(0, (int) $qty);
            if ($qty > 0) {
                $plan[$productId] = $qty;
            }
        }
        return $plan;
    }

    public static function index(): void
    {
        Auth::requireModule('production');
        $pdo = Database::pdo();
        Response::ok([
            'products' => ProductionService::products($pdo),
            'history' => ProductionService::history($pdo),
        ]);
    }

    public static function suggest(): void
    {
        Auth::requireModule('production');
        Response::ok(['plan' => ProductionService::suggestPlan(Database::pdo())]);
    }

    public static function feasibility(): void
    {
        Auth::requireModule('production');
        $plan = self::normalizePlan(Request::json()['plan'] ?? []);
        Response::ok(ProductionService::feasibility(Database::pdo(), $plan));
    }

    public static function fit(): void
    {
        Auth::requireModule('production');
        $plan = self::normalizePlan(Request::json()['plan'] ?? []);
        Response::ok(['plan' => ProductionService::fitPlan(Database::pdo(), $plan)]);
    }

    public static function poForShortages(): void
    {
        $user = Auth::requireRole(self::PO_ROLES);
        $plan = self::normalizePlan(Request::json()['plan'] ?? []);
        try {
            $ids = ProductionService::poForShortages(Database::pdo(), $plan, $user);
            Response::ok(['poIds' => $ids]);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function confirm(): void
    {
        $user = Auth::requireRole(self::CONFIRM_ROLES);
        $plan = self::normalizePlan(Request::json()['plan'] ?? []);
        if (!$plan) {
            Response::error('Add quantities to the plan before confirming.');
            return;
        }
        try {
            $result = ProductionService::confirmBake(Database::pdo(), $plan, $user);
            Response::ok($result);
        } catch (ProductionShortfallException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }
}
