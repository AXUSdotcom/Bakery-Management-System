<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\IdSequence;
use App\Support\Notify;
use PDO;

class WastageService
{
    public static function log(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT w.*, i.name AS ingredient_name, i.uom
             FROM wastage w JOIN ingredients i ON i.id = w.ingredient_id
             ORDER BY w.logged_at DESC"
        )->fetchAll();
    }

    public static function totals(PDO $pdo): array
    {
        $t7 = (float) $pdo->query("SELECT COALESCE(SUM(cost),0) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $t30 = (float) $pdo->query("SELECT COALESCE(SUM(cost),0) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $n7 = (int) $pdo->query("SELECT COUNT(*) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $auto = (int) $pdo->query("SELECT COUNT(*) FROM wastage WHERE is_auto = 1")->fetchColumn();
        return ['total7d' => round($t7, 2), 'total30d' => round($t30, 2), 'events7d' => $n7, 'autoLogged' => $auto];
    }

    /** Ingredient-level waste: FEFO-deducted across batches, costed at the ingredient's flat unit cost. */
    public static function wasteIngredient(PDO $pdo, string $ingredientId, float $qty, string $reason, array $user): string
    {
        $i = InventoryService::ingredient($pdo, $ingredientId);
        if (!$i) {
            throw new \RuntimeException('Ingredient not found');
        }

        $pdo->beginTransaction();
        try {
            $onHand = InventoryService::stockOf($pdo, $ingredientId);
            if ($qty <= 0 || $qty > $onHand) {
                throw new \RuntimeException('Quantity exceeds available stock');
            }
            InventoryService::fefoDeduct($pdo, $ingredientId, $qty);

            $cost = round($qty * (float) $i['unit_cost'], 2);
            $id = IdSequence::next($pdo, 'waste', 'W', 3);
            $pdo->prepare(
                'INSERT INTO wastage (id,ingredient_id,batch_id,qty,reason,cost,is_auto) VALUES (?,?,NULL,?,?,?,0)'
            )->execute([$id, $ingredientId, $qty, $reason, $cost]);

            InventoryService::recomputeLowStockFlag($pdo, $ingredientId);
            Notify::push('warn', '♺', "Wastage: {$i['name']}", "{$qty} {$i['uom']} · {$reason} · loss Rs. " . number_format($cost, 2), 'inventory');
            Audit::log($user, 'WASTE', "{$qty} {$i['uom']} {$i['name']} · {$reason}");

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Batch-level waste: deducted from one specific batch, costed at that batch's own cost. */
    public static function wasteBatch(PDO $pdo, string $batchId, float $qty, string $reason, array $user): string
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM batches WHERE id = ? FOR UPDATE');
            $stmt->execute([$batchId]);
            $b = $stmt->fetch();
            if (!$b) {
                throw new \RuntimeException('Batch not found');
            }
            if ($qty <= 0 || $qty > (float) $b['qty_on_hand']) {
                throw new \RuntimeException('Quantity exceeds batch on-hand');
            }
            $i = InventoryService::ingredient($pdo, $b['ingredient_id']);

            $newQty = round((float) $b['qty_on_hand'] - $qty, 3);
            $pdo->prepare('UPDATE batches SET qty_on_hand = ? WHERE id = ?')->execute([$newQty, $batchId]);

            $cost = round($qty * (float) $b['unit_cost'], 2);
            $id = IdSequence::next($pdo, 'waste', 'W', 3);
            $pdo->prepare(
                'INSERT INTO wastage (id,ingredient_id,batch_id,qty,reason,cost,is_auto) VALUES (?,?,?,?,?,?,0)'
            )->execute([$id, $b['ingredient_id'], $batchId, $qty, $reason, $cost]);

            InventoryService::recomputeLowStockFlag($pdo, $b['ingredient_id']);
            Notify::push('warn', '♺', "Wastage: {$i['name']}", "{$qty} {$i['uom']} · {$reason} · loss Rs. " . number_format($cost, 2), 'inventory');
            Audit::log($user, 'WASTE', "{$qty} {$i['uom']} {$i['name']} ({$batchId}) · {$reason}");

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Zeroes out every expired batch that still has stock, logging one waste row each. */
    public static function runExpiryJob(PDO $pdo, array $user): int
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT b.*, i.name AS ingredient_name FROM batches b JOIN ingredients i ON i.id = b.ingredient_id
                 WHERE b.qty_on_hand > 0 AND b.expiry_date < CURDATE() FOR UPDATE"
            );
            $stmt->execute();
            $expired = $stmt->fetchAll();

            foreach ($expired as $b) {
                $cost = round((float) $b['qty_on_hand'] * (float) $b['unit_cost'], 2);
                $id = IdSequence::next($pdo, 'waste', 'W', 3);
                $pdo->prepare(
                    'INSERT INTO wastage (id,ingredient_id,batch_id,qty,reason,cost,is_auto) VALUES (?,?,?,?,\'Expired\',?,1)'
                )->execute([$id, $b['ingredient_id'], $b['id'], $b['qty_on_hand'], $cost]);
                $pdo->prepare('UPDATE batches SET qty_on_hand = 0 WHERE id = ?')->execute([$b['id']]);
                Audit::log($user, 'EXPIRY_JOB', "{$b['id']} {$b['ingredient_name']} auto-wasted");
                InventoryService::recomputeLowStockFlag($pdo, $b['ingredient_id']);
            }

            if (count($expired)) {
                Notify::push('warn', '♺', 'Expiry job ran', count($expired) . ' expired batch(es) auto-logged as waste', 'inventory');
            }

            $pdo->commit();
            return count($expired);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
