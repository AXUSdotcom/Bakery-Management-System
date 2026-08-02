<?php
namespace App\Domain;

use PDO;

class DashboardService
{
    public static function payload(PDO $pdo): array
    {
        $wasteMonth = (float) $pdo->query("SELECT COALESCE(SUM(cost),0) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $wastePrev = (float) $pdo->query(
            "SELECT COALESCE(SUM(cost),0) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND logged_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();
        $wasteChangePct = $wastePrev > 0 ? (($wasteMonth - $wastePrev) / $wastePrev * 100) : 0.0;

        $invValue = InventoryService::invValue($pdo);
        $activeBatches = InventoryService::activeBatchCount($pdo);
        $low = InventoryService::lowItems($pdo);

        $salesToday = (float) ($pdo->query("SELECT total FROM sales_daily WHERE sale_date = CURDATE()")->fetchColumn() ?: 0);
        $openOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('Delivered','Cancelled')")->fetchColumn();
        $pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn();

        $topSellers = $pdo->query(
            "SELECT p.name, p.emoji, SUM(l.qty * l.unit_price) AS revenue
             FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN products p ON p.id = l.product_id
             WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND o.status != 'Cancelled'
             GROUP BY p.id ORDER BY revenue DESC LIMIT 5"
        )->fetchAll();

        $ingredients = $pdo->query('SELECT * FROM ingredients')->fetchAll();
        $stock = InventoryService::stockOfAll($pdo);
        $healthy = $warn = $bad = 0;
        foreach ($ingredients as $i) {
            $oh = $stock[$i['id']] ?? 0.0;
            [, $cls] = InventoryService::stockStatus($i, $oh);
            if ($cls === 'b-good') {
                $healthy++;
            } elseif ($cls === 'b-warn') {
                $warn++;
            } else {
                $bad++;
            }
        }

        $expiringSoon = $pdo->query(
            "SELECT b.*, i.name AS ingredient_name, i.uom FROM batches b JOIN ingredients i ON i.id = b.ingredient_id
             WHERE b.qty_on_hand > 0 AND b.expiry_date >= CURDATE() AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY b.expiry_date ASC"
        )->fetchAll();

        $wasteByReason = $pdo->query(
            "SELECT reason, SUM(cost) AS cost FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY reason"
        )->fetchAll();

        $wasteTrend = [];
        $bucketStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(cost),0) FROM wastage WHERE logged_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND logged_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        for ($i = 6; $i >= 1; $i--) {
            $bucketStmt->execute([$i * 7, ($i - 1) * 7]);
            $wasteTrend[] = ['label' => 'W-' . $i, 'cost' => round((float) $bucketStmt->fetchColumn(), 2)];
        }

        $sales7 = $pdo->query(
            "SELECT sale_date, total FROM sales_daily WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY sale_date ASC"
        )->fetchAll();

        return [
            'wasteCost30d' => round($wasteMonth, 2),
            'wasteChangePct' => round($wasteChangePct, 1),
            'inventoryValue' => $invValue,
            'activeBatches' => $activeBatches,
            'lowStockCount' => count($low),
            'salesToday' => $salesToday,
            'openOrders' => $openOrders,
            'pendingOrders' => $pendingOrders,
            'topSellers' => $topSellers,
            'stockHealth' => ['healthy' => $healthy, 'warn' => $warn, 'bad' => $bad],
            'expiringSoon' => $expiringSoon,
            'wasteByReason' => $wasteByReason,
            'wasteTrend6w' => $wasteTrend,
            'sales7d' => $sales7,
            'lowItems' => $low,
        ];
    }
}
