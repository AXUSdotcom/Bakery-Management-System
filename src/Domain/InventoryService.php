<?php
namespace App\Domain;

use PDO;

/**
 * Ingredient/batch stock math — ported from the prototype's stockOf/activeBatches/
 * daysCover/stockStatus/invValue/suggestQty/fefoDeduct free functions.
 */
class InventoryService
{
    public static function ingredient(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Active batches for one ingredient, FEFO order (soonest expiry first). Non-expired only. */
    public static function activeBatches(PDO $pdo, string $ingredientId, bool $forUpdate = false): array
    {
        $sql = 'SELECT * FROM batches WHERE ingredient_id = ? AND qty_on_hand > 0 AND expiry_date >= CURDATE() ORDER BY expiry_date ASC';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ingredientId]);
        return $stmt->fetchAll();
    }

    public static function stockOf(PDO $pdo, string $ingredientId): float
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(qty_on_hand),0) FROM batches WHERE ingredient_id = ? AND qty_on_hand > 0 AND expiry_date >= CURDATE()'
        );
        $stmt->execute([$ingredientId]);
        return round((float) $stmt->fetchColumn(), 3);
    }

    /** @return array<string,float> ingredient_id => stock (single query, used by list views) */
    public static function stockOfAll(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT ingredient_id, COALESCE(SUM(qty_on_hand),0) AS oh FROM batches WHERE qty_on_hand > 0 AND expiry_date >= CURDATE() GROUP BY ingredient_id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['ingredient_id']] = round((float) $r['oh'], 3);
        }
        return $out;
    }

    public static function invValue(PDO $pdo): float
    {
        $v = $pdo->query(
            'SELECT COALESCE(SUM(qty_on_hand * unit_cost),0) FROM batches WHERE qty_on_hand > 0 AND expiry_date >= CURDATE()'
        )->fetchColumn();
        return round((float) $v, 2);
    }

    public static function activeBatchCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM batches WHERE qty_on_hand > 0 AND expiry_date >= CURDATE()')->fetchColumn();
    }

    public static function daysCover(array $ingredient, float $stockOnHand): float
    {
        $per = ((float) $ingredient['used_last_7d']) / 7;
        if ($per <= 0) {
            return 99.0;
        }
        return $stockOnHand / $per;
    }

    /** @return array{0:string,1:string} [label, badge class] */
    public static function stockStatus(array $ingredient, float $stockOnHand): array
    {
        $reorder = (float) $ingredient['reorder_level'];
        if ($stockOnHand <= 0) {
            return ['Out of stock', 'b-bad'];
        }
        if ($stockOnHand < $reorder) {
            return ['Low — reorder', 'b-bad'];
        }
        if ($stockOnHand < $reorder * 1.4) {
            return ['Getting low', 'b-warn'];
        }
        return ['Healthy', 'b-good'];
    }

    public static function suggestQty(array $ingredient, float $stockOnHand): float
    {
        $reorder = (float) $ingredient['reorder_level'];
        return max(1, ceil($reorder * 2 - $stockOnHand));
    }

    /** @return array<int,array> ingredients currently below reorder level */
    public static function lowItems(PDO $pdo): array
    {
        $stock = self::stockOfAll($pdo);
        $rows = $pdo->query('SELECT * FROM ingredients')->fetchAll();
        $out = [];
        foreach ($rows as $i) {
            $oh = $stock[$i['id']] ?? 0.0;
            if ($oh < (float) $i['reorder_level']) {
                $i['stock_on_hand'] = $oh;
                $out[] = $i;
            }
        }
        return $out;
    }

    public static function list(PDO $pdo, string $search = '', string $filter = 'all'): array
    {
        $stock = self::stockOfAll($pdo);
        $rows = $pdo->query(
            'SELECT i.*, s.name AS supplier_name FROM ingredients i LEFT JOIN suppliers s ON s.id = i.supplier_id ORDER BY i.name'
        )->fetchAll();
        $out = [];
        foreach ($rows as $i) {
            if ($search !== '' && stripos($i['name'], $search) === false) {
                continue;
            }
            $oh = $stock[$i['id']] ?? 0.0;
            $reorder = (float) $i['reorder_level'];
            if ($filter === 'low' && $oh >= $reorder) {
                continue;
            }
            if ($filter === 'ok' && $oh < $reorder) {
                continue;
            }
            [$label, $cls] = self::stockStatus($i, $oh);
            $out[] = [
                'id' => $i['id'],
                'name' => $i['name'],
                'uom' => $i['uom'],
                'unitCost' => (float) $i['unit_cost'],
                'reorderLevel' => $reorder,
                'supplierId' => $i['supplier_id'],
                'supplierName' => $i['supplier_name'],
                'stockOnHand' => $oh,
                'daysCover' => self::daysCover($i, $oh),
                'value' => round($oh * (float) $i['unit_cost'], 2),
                'statusLabel' => $label,
                'statusClass' => $cls,
            ];
        }
        return $out;
    }

    public static function allActiveBatches(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT b.*, i.name AS ingredient_name, i.uom, s.name AS supplier_name
             FROM batches b
             JOIN ingredients i ON i.id = b.ingredient_id
             LEFT JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.qty_on_hand > 0
             ORDER BY b.expiry_date ASC"
        )->fetchAll();
    }

    /**
     * FEFO deduction: draws from active (non-expired) batches soonest-expiry-first.
     * Caller MUST run this inside a transaction; uses row locks so concurrent
     * production/waste requests can't double-spend the same batch.
     * @return bool true if the full quantity could be satisfied
     */
    public static function fefoDeduct(PDO $pdo, string $ingredientId, float $qty): bool
    {
        $remaining = round($qty, 3);
        $batches = self::activeBatches($pdo, $ingredientId, true);
        foreach ($batches as $b) {
            if ($remaining <= 0.0001) {
                break;
            }
            $take = min((float) $b['qty_on_hand'], $remaining);
            $newQty = round((float) $b['qty_on_hand'] - $take, 3);
            $pdo->prepare('UPDATE batches SET qty_on_hand = ? WHERE id = ?')->execute([$newQty, $b['id']]);
            $remaining = round($remaining - $take, 3);
        }
        return $remaining <= 0.0001;
    }

    /**
     * Recompute low-stock flag for one ingredient and emit a notification on
     * a fresh crossing below reorder level (or clear the flag on recovery).
     */
    public static function recomputeLowStockFlag(PDO $pdo, string $ingredientId): void
    {
        $i = self::ingredient($pdo, $ingredientId);
        if (!$i) {
            return;
        }
        $oh = self::stockOf($pdo, $ingredientId);
        $reorder = (float) $i['reorder_level'];
        $wasFlagged = (bool) $i['low_stock_notified'];

        if ($oh < $reorder && !$wasFlagged) {
            \App\Support\Notify::push(
                'bad', '⚑', "{$i['name']} below reorder level",
                "{$oh} {$i['uom']} on hand · reorder at {$reorder} {$i['uom']}", 'inventory'
            );
            $pdo->prepare('UPDATE ingredients SET low_stock_notified = 1 WHERE id = ?')->execute([$ingredientId]);
        } elseif ($oh >= $reorder && $wasFlagged) {
            $pdo->prepare('UPDATE ingredients SET low_stock_notified = 0 WHERE id = ?')->execute([$ingredientId]);
        }
    }

    public static function newIngredient(PDO $pdo, array $data): string
    {
        $id = \App\Support\IdSequence::next($pdo, 'ingredient', 'IG', 2);
        $stmt = $pdo->prepare(
            'INSERT INTO ingredients (id,name,uom,unit_cost,reorder_level,supplier_id,used_last_7d) VALUES (?,?,?,?,?,?,0)'
        );
        $stmt->execute([$id, $data['name'], $data['uom'], $data['unitCost'], $data['reorderLevel'], $data['supplierId'] ?: null]);

        if (($data['openingStock'] ?? 0) > 0) {
            $batchId = \App\Support\IdSequence::next($pdo, 'batch', 'B', 3);
            $pdo->prepare(
                'INSERT INTO batches (id,ingredient_id,supplier_id,received_qty,qty_on_hand,unit_cost,expiry_date) VALUES (?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 20 DAY))'
            )->execute([$batchId, $id, $data['supplierId'] ?: null, $data['openingStock'], $data['openingStock'], $data['unitCost']]);
        }
        self::recomputeLowStockFlag($pdo, $id);
        return $id;
    }

    public static function receiveStock(PDO $pdo, string $ingredientId, float $qty, int $expiryDays): string
    {
        $i = self::ingredient($pdo, $ingredientId);
        $batchId = \App\Support\IdSequence::next($pdo, 'batch', 'B', 3);
        $pdo->prepare(
            'INSERT INTO batches (id,ingredient_id,supplier_id,received_qty,qty_on_hand,unit_cost,expiry_date) VALUES (?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL ? DAY))'
        )->execute([$batchId, $ingredientId, $i['supplier_id'], $qty, $qty, $i['unit_cost'], $expiryDays]);
        self::recomputeLowStockFlag($pdo, $ingredientId);
        return $batchId;
    }
}
