<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\Notify;
use PDO;

class ProductionService
{
    private static function recipeLines(PDO $pdo, string $productId): array
    {
        $stmt = $pdo->prepare('SELECT ingredient_id, qty_per_unit FROM recipe_lines WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public static function maxBakeable(PDO $pdo, string $productId): int
    {
        $lines = self::recipeLines($pdo, $productId);
        if (!$lines) {
            return 0;
        }
        $min = INF;
        foreach ($lines as $l) {
            $have = InventoryService::stockOf($pdo, $l['ingredient_id']);
            $min = min($min, $have / (float) $l['qty_per_unit']);
        }
        return (int) floor($min);
    }

    public static function products(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
        foreach ($rows as &$p) {
            $p['maxBakeable'] = self::maxBakeable($pdo, $p['id']);
        }
        return $rows;
    }

    public static function history(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT pr.id, pr.run_at, pr.status, u.name AS run_by_name,
                    GROUP_CONCAT(CONCAT(p.name, ' ×', l.qty) SEPARATOR ', ') AS lines
             FROM production_runs pr
             LEFT JOIN users u ON u.id = pr.run_by
             JOIN production_run_lines l ON l.run_id = pr.id
             JOIN products p ON p.id = l.product_id
             GROUP BY pr.id
             ORDER BY pr.run_at DESC"
        )->fetchAll();
    }

    /** @param array<string,int> $plan productId => qty */
    public static function planNeeds(PDO $pdo, array $plan): array
    {
        $need = [];
        foreach ($plan as $productId => $qty) {
            if ($qty <= 0) {
                continue;
            }
            foreach (self::recipeLines($pdo, $productId) as $l) {
                $need[$l['ingredient_id']] = ($need[$l['ingredient_id']] ?? 0) + (float) $l['qty_per_unit'] * $qty;
            }
        }
        foreach ($need as $k => $v) {
            $need[$k] = round($v, 3);
        }
        return $need;
    }

    /** Full per-ingredient feasibility rows + overall ok flag. */
    public static function feasibility(PDO $pdo, array $plan): array
    {
        $need = self::planNeeds($pdo, $plan);
        $rows = [];
        $ok = true;
        foreach ($need as $ingredientId => $n) {
            $i = InventoryService::ingredient($pdo, $ingredientId);
            $have = InventoryService::stockOf($pdo, $ingredientId);
            $short = $n > $have;
            if ($short) {
                $ok = false;
            }
            $rows[] = [
                'ingredientId' => $ingredientId,
                'name' => $i['name'],
                'uom' => $i['uom'],
                'need' => $n,
                'have' => $have,
                'shortage' => $short ? round($n - $have, 3) : 0,
            ];
        }
        return ['rows' => $rows, 'ok' => $ok, 'need' => $need];
    }

    /** 7-day-average-minus-shelf suggestion. */
    public static function suggestPlan(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, avg_weekly_sales, shelf_stock FROM products')->fetchAll();
        $plan = [];
        foreach ($rows as $p) {
            $plan[$p['id']] = max(0, (int) round((float) $p['avg_weekly_sales'] - (int) $p['shelf_stock']));
        }
        return $plan;
    }

    /** Iteratively trims the plan until it fits available stock (mirrors prototype's fitPlan). */
    public static function fitPlan(PDO $pdo, array $plan): array
    {
        $guard = 400;
        while ($guard-- > 0) {
            $need = self::planNeeds($pdo, $plan);
            $overId = null;
            foreach ($need as $ingredientId => $n) {
                if ($n > InventoryService::stockOf($pdo, $ingredientId)) {
                    $overId = $ingredientId;
                    break;
                }
            }
            if ($overId === null) {
                break;
            }
            $worst = null;
            $worstVal = 0;
            foreach ($plan as $productId => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                foreach (self::recipeLines($pdo, $productId) as $l) {
                    if ($l['ingredient_id'] === $overId) {
                        $v = (float) $l['qty_per_unit'] * $qty;
                        if ($v > $worstVal) {
                            $worstVal = $v;
                            $worst = $productId;
                        }
                    }
                }
            }
            if ($worst === null) {
                break;
            }
            $plan[$worst] = max(0, $plan[$worst] - 1);
        }
        return $plan;
    }

    /** Raises draft POs (grouped by supplier) for whatever the plan is short on. */
    public static function poForShortages(PDO $pdo, array $plan, array $user): array
    {
        $feas = self::feasibility($pdo, $plan);
        $bySupplier = [];
        foreach ($feas['rows'] as $r) {
            if ($r['shortage'] <= 0) {
                continue;
            }
            $i = InventoryService::ingredient($pdo, $r['ingredientId']);
            if (!$i['supplier_id']) {
                continue;
            }
            $bySupplier[$i['supplier_id']][] = [$r['ingredientId'], (float) ceil($r['shortage'])];
        }
        $ids = PurchasingService::createPOs($pdo, $bySupplier, true, $user);
        Notify::push('info', '⛿', 'Shortage POs drafted', "From today's production plan", 'purchasing');
        return $ids;
    }

    /**
     * Atomic bake confirmation: validate stock, FEFO-deduct every ingredient,
     * add finished goods, log the run. Rolls back entirely on any shortage.
     * @param array<string,int> $plan
     */
    public static function confirmBake(PDO $pdo, array $plan, array $user): array
    {
        $pdo->beginTransaction();
        try {
            $feas = self::feasibility($pdo, $plan);
            if (!$feas['ok']) {
                throw new ProductionShortfallException('Not enough ingredients to bake this plan', $feas['rows']);
            }

            foreach ($feas['need'] as $ingredientId => $qty) {
                InventoryService::fefoDeduct($pdo, $ingredientId, $qty);
                InventoryService::recomputeLowStockFlag($pdo, $ingredientId);
            }

            $pdo->prepare('INSERT INTO production_runs (run_by, status) VALUES (?, \'Completed\')')->execute([$user['id']]);
            $runId = (int) $pdo->lastInsertId();

            $nameStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
            $lineDescriptions = [];
            foreach ($plan as $productId => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                $pdo->prepare('UPDATE products SET shelf_stock = shelf_stock + ? WHERE id = ?')->execute([$qty, $productId]);
                $pdo->prepare('INSERT INTO production_run_lines (run_id, product_id, qty) VALUES (?,?,?)')->execute([$runId, $productId, $qty]);
                $nameStmt->execute([$productId]);
                $name = $nameStmt->fetchColumn();
                $lineDescriptions[] = "{$name} ×{$qty}";
            }
            $summary = implode(', ', $lineDescriptions);

            Notify::push('good', '⚖', 'Production completed', $summary, 'production');
            Audit::log($user, 'PRODUCTION', $summary);

            $pdo->commit();
            return ['runId' => $runId, 'summary' => $summary];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

class ProductionShortfallException extends \RuntimeException
{
    public array $shortageRows;

    public function __construct(string $message, array $shortageRows)
    {
        parent::__construct($message);
        $this->shortageRows = $shortageRows;
    }
}
