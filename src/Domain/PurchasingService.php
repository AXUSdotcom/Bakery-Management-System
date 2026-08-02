<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\IdSequence;
use App\Support\Notify;
use PDO;

class PurchasingService
{
    public static function list(PDO $pdo, ?string $status = null): array
    {
        $sql = "SELECT po.*, s.name AS supplier_name, s.email AS supplier_email, s.lead_days,
                       (SELECT GROUP_CONCAT(CONCAT(l.qty,' ',i.uom,' ',i.name) SEPARATOR ', ')
                        FROM purchase_order_lines l JOIN ingredients i ON i.id = l.ingredient_id
                        WHERE l.po_id = po.id) AS items_summary
                FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id";
        $params = [];
        if ($status && $status !== 'all') {
            $sql .= ' WHERE po.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY po.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function lines(PDO $pdo, string $poId): array
    {
        $stmt = $pdo->prepare(
            'SELECT l.*, i.name AS ingredient_name, i.uom FROM purchase_order_lines l
             JOIN ingredients i ON i.id = l.ingredient_id WHERE l.po_id = ?'
        );
        $stmt->execute([$poId]);
        return $stmt->fetchAll();
    }

    public static function preview(PDO $pdo, string $poId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT po.*, s.name AS supplier_name, s.email AS supplier_email, s.lead_days
             FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?'
        );
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if (!$po) {
            return null;
        }
        $po['lines'] = self::lines($pdo, $poId);
        return $po;
    }

    /**
     * @param array<string,array<int,array{0:string,1:float}>> $bySupplier supplierId => [[ingredientId, qty], ...]
     * @return string[] new PO ids
     */
    public static function createPOs(PDO $pdo, array $bySupplier, bool $isAuto, array $user): array
    {
        $ids = [];
        foreach ($bySupplier as $supplierId => $items) {
            if (!$items) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $total = 0.0;
                $lineCosts = [];
                foreach ($items as [$ingredientId, $qty]) {
                    $cost = (float) InventoryService::ingredient($pdo, $ingredientId)['unit_cost'];
                    $lineCosts[] = [$ingredientId, $qty, $cost];
                    $total += round($qty * $cost, 2);
                }
                $poId = IdSequence::next($pdo, 'po', 'PO', 0);
                $pdo->prepare(
                    'INSERT INTO purchase_orders (id, supplier_id, status, is_auto, total, created_by) VALUES (?,?,\'Draft\',?,?,?)'
                )->execute([$poId, $supplierId, $isAuto ? 1 : 0, round($total, 2), $user['id']]);

                $lineStmt = $pdo->prepare('INSERT INTO purchase_order_lines (po_id, ingredient_id, qty, unit_cost) VALUES (?,?,?,?)');
                foreach ($lineCosts as [$ingredientId, $qty, $cost]) {
                    $lineStmt->execute([$poId, $ingredientId, $qty, $cost]);
                }

                $supplierName = $pdo->prepare('SELECT name FROM suppliers WHERE id = ?');
                $supplierName->execute([$supplierId]);
                $sName = $supplierName->fetchColumn();

                Notify::push('info', '⛿', "Draft {$poId} raised", $sName . ' · Rs. ' . number_format($total, 2), 'purchasing');
                Audit::log($user, 'PO_DRAFT', "{$poId} · {$sName}");

                $pdo->commit();
                $ids[] = $poId;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        return $ids;
    }

    public static function autoPO(PDO $pdo, string $ingredientId, array $user): string
    {
        $i = InventoryService::ingredient($pdo, $ingredientId);
        if (!$i || !$i['supplier_id']) {
            throw new \RuntimeException('Ingredient has no linked supplier');
        }
        $oh = InventoryService::stockOf($pdo, $ingredientId);
        $qty = InventoryService::suggestQty($i, $oh);
        $ids = self::createPOs($pdo, [$i['supplier_id'] => [[$ingredientId, $qty]]], true, $user);
        return $ids[0];
    }

    public static function autoPOAll(PDO $pdo, array $user): array
    {
        $low = InventoryService::lowItems($pdo);
        $bySupplier = [];
        foreach ($low as $i) {
            if (!$i['supplier_id']) {
                continue;
            }
            $qty = InventoryService::suggestQty($i, $i['stock_on_hand']);
            $bySupplier[$i['supplier_id']][] = [$i['id'], $qty];
        }
        return self::createPOs($pdo, $bySupplier, true, $user);
    }

    public static function send(PDO $pdo, string $poId, array $user): void
    {
        $stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name, s.lead_days FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if (!$po || $po['status'] !== 'Draft') {
            throw new \RuntimeException('Only draft POs can be sent');
        }
        $pdo->prepare("UPDATE purchase_orders SET status='Sent', eta_days = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$po['lead_days'], $poId]);

        Notify::push('info', '⛿', "{$poId} sent", "To {$po['supplier_name']} · Rs. " . number_format((float) $po['total'], 2), 'purchasing');
        Audit::log($user, 'PO_SENT', "{$poId} · {$po['supplier_name']}");
    }

    public static function cancel(PDO $pdo, string $poId, array $user): void
    {
        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if (!$po || !in_array($po['status'], ['Draft', 'Sent'], true)) {
            throw new \RuntimeException('Only draft or sent POs can be cancelled');
        }
        $wasSent = $po['status'] === 'Sent';
        $pdo->prepare("UPDATE purchase_orders SET status='Cancelled' WHERE id = ?")->execute([$poId]);

        Notify::push('warn', '⛿', "{$poId} cancelled", $wasSent ? 'Supplier notified' : 'Draft discarded', 'purchasing');
        Audit::log($user, 'PO_CANCELLED', $poId);
    }

    public static function receive(PDO $pdo, string $poId, array $user): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$poId]);
            $po = $stmt->fetch();
            if (!$po || $po['status'] !== 'Sent') {
                throw new \RuntimeException('Only sent POs can be received');
            }

            $lines = self::lines($pdo, $poId);
            foreach ($lines as $line) {
                $expiryDays = $line['uom'] === 'pc' ? 14 : 20;
                InventoryService::receiveStock($pdo, $line['ingredient_id'], (float) $line['qty'], $expiryDays);
            }

            $pdo->prepare("UPDATE purchase_orders SET status='Received', received_at = NOW() WHERE id = ?")->execute([$poId]);

            Notify::push('good', '✓', "{$poId} received", 'Inventory updated automatically', 'inventory');
            Audit::log($user, 'PO_RECEIVED', "{$poId} · " . count($lines) . ' batch(es)');

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
