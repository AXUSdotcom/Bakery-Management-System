<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\IdSequence;
use App\Support\Notify;
use PDO;

class OrdersService
{
    public const STEPS = ['Pending', 'Preparing', 'Ready', 'Out for delivery', 'Delivered'];

    public static function list(PDO $pdo, ?string $status = null): array
    {
        $sql = "SELECT o.*,
                       (SELECT GROUP_CONCAT(CONCAT(p.emoji,' ',p.name,' ×',l.qty) SEPARATOR '<br>')
                        FROM order_lines l JOIN products p ON p.id = l.product_id WHERE l.order_id = o.id) AS items_summary
                FROM orders o";
        $params = [];
        if ($status && $status !== 'all') {
            $sql .= ' WHERE o.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function mine(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            "SELECT o.*,
                    (SELECT GROUP_CONCAT(CONCAT(p.emoji,' ',p.name,' ×',l.qty) SEPARATOR ' · ')
                     FROM order_lines l JOIN products p ON p.id = l.product_id WHERE l.order_id = o.id) AS items_summary
             FROM orders o WHERE o.customer_id = ? ORDER BY o.created_at DESC"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    public static function detail(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) {
            return null;
        }
        $lines = $pdo->prepare(
            'SELECT l.*, p.name, p.emoji FROM order_lines l JOIN products p ON p.id = l.product_id WHERE l.order_id = ?'
        );
        $lines->execute([$id]);
        $o['lines'] = $lines->fetchAll();

        $tl = $pdo->prepare('SELECT event, happened_at FROM order_timeline WHERE order_id = ? ORDER BY happened_at ASC, id ASC');
        $tl->execute([$id]);
        $o['timeline'] = $tl->fetchAll();
        return $o;
    }

    private static function addTimeline(PDO $pdo, string $orderId, string $event): void
    {
        $pdo->prepare('INSERT INTO order_timeline (order_id, event) VALUES (?,?)')->execute([$orderId, $event]);
    }

    /** Staff transitions: Pending -> Preparing -> Ready -> (Out for delivery, Delivery mode only) -> Delivered. */
    public static function advance(PDO $pdo, string $id, string $toStatus, array $user): void
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) {
            throw new \RuntimeException('Order not found');
        }

        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$toStatus, $id]);

        if ($toStatus === 'Preparing') {
            self::addTimeline($pdo, $id, 'Confirmed — preparing');
            Notify::push('info', '☰', "{$id} confirmed", 'Preparing your order', 'orders');
        } elseif ($toStatus === 'Ready') {
            self::addTimeline($pdo, $id, 'Ready for ' . ($o['mode'] === 'Delivery' ? 'dispatch' : 'pickup'));
        } elseif ($toStatus === 'Delivered') {
            self::addTimeline($pdo, $id, 'Handed over at counter');
            Notify::push('good', '✓', "{$id} delivered", 'Handed over at counter', 'orders');
        }
        Audit::log($user, 'ORDER_' . strtoupper(str_replace(' ', '_', $toStatus)), $id);
    }

    public static function dispatch(PDO $pdo, string $id, array $driver, array $user): void
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o || $o['status'] !== 'Ready' || $o['mode'] !== 'Delivery') {
            throw new \RuntimeException('Order is not ready for dispatch');
        }
        $name = trim($driver['driverName'] ?? '');
        $phone = trim($driver['driverPhone'] ?? '');
        $vehicleNo = trim($driver['vehicleNo'] ?? '');
        if ($name === '' || $phone === '' || $vehicleNo === '') {
            throw new \RuntimeException('Rider name, phone and vehicle number are required');
        }
        $vehicleType = $driver['vehicleType'] ?? 'Motorbike';
        $eta = $driver['eta'] ?? 'Within 45 min';

        $pdo->prepare(
            "UPDATE orders SET status='Out for delivery', driver_name=?, driver_phone=?, vehicle_type=?, vehicle_no=?, eta=? WHERE id=?"
        )->execute([$name, $phone, $vehicleType, $vehicleNo, $eta, $id]);

        self::addTimeline($pdo, $id, "Dispatched · {$name} · {$vehicleType} {$vehicleNo}");
        Notify::push('info', '🛵', "{$id} out for delivery", "{$name} · {$vehicleNo} · ETA {$eta}", 'orders');
        Audit::log($user, 'ORDER_DISPATCHED', "{$id} · {$name}");
    }

    public static function markDelivered(PDO $pdo, string $id, array $user): void
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o || !in_array($o['status'], ['Out for delivery', 'Ready'], true)) {
            throw new \RuntimeException('Order is not out for delivery');
        }
        $pdo->prepare("UPDATE orders SET status='Delivered', delivered_at = NOW() WHERE id = ?")->execute([$id]);
        self::addTimeline($pdo, $id, 'Delivered & received by customer');
        Notify::push('good', '✓', "{$id} delivered", 'Received just now', 'orders');
        Audit::log($user, 'ORDER_DELIVERED', $id);
    }

    private static function restockLines(PDO $pdo, string $orderId): void
    {
        $lines = $pdo->prepare('SELECT product_id, qty FROM order_lines WHERE order_id = ?');
        $lines->execute([$orderId]);
        foreach ($lines->fetchAll() as $l) {
            $pdo->prepare('UPDATE products SET shelf_stock = shelf_stock + ? WHERE id = ?')->execute([$l['qty'], $l['product_id']]);
        }
    }

    public static function staffCancel(PDO $pdo, string $id, array $user): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();
            if ($status !== 'Pending') {
                throw new \RuntimeException('Only pending orders can be cancelled');
            }
            self::restockLines($pdo, $id);
            $pdo->prepare("UPDATE orders SET status='Cancelled' WHERE id = ?")->execute([$id]);
            self::addTimeline($pdo, $id, 'Cancelled by bakery');
            Notify::push('warn', '☰', "{$id} cancelled", 'By bakery staff', 'orders');
            Audit::log($user, 'ORDER_CANCELLED', $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function customerCancel(PDO $pdo, string $id, int $customerId, array $user): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status, customer_id FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $o = $stmt->fetch();
            if (!$o || (int) $o['customer_id'] !== $customerId) {
                throw new \RuntimeException('Order not found');
            }
            if ($o['status'] !== 'Pending') {
                throw new \RuntimeException('Orders can only be cancelled before the bakery confirms them');
            }
            self::restockLines($pdo, $id);
            $pdo->prepare("UPDATE orders SET status='Cancelled' WHERE id = ?")->execute([$id]);
            self::addTimeline($pdo, $id, 'Cancelled by customer');
            Notify::push('warn', '☰', "{$id} cancelled by customer", $user['name'], 'orders');
            Audit::log($user, 'ORDER_CANCELLED', "{$id} (customer)");
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Places a customer order. Deducts shelf_stock at placement time (a deliberate
     * improvement over the prototype, which only deducted stock at production —
     * see README) so two concurrent customers can never oversell the same shelf stock.
     * @param array<int,array{productId:string,qty:int}> $items
     */
    public static function checkout(PDO $pdo, array $user, array $items, string $mode, string $address, string $paymentMethod, string $note, bool $saveAddress): array
    {
        if (!$items) {
            throw new \RuntimeException('Your basket is empty');
        }
        if ($mode === 'Delivery' && trim($address) === '') {
            throw new \RuntimeException('Please enter a delivery address');
        }

        $pdo->beginTransaction();
        try {
            $subtotal = 0.0;
            $lineData = [];
            foreach ($items as $item) {
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$item['productId']]);
                $p = $stmt->fetch();
                if (!$p) {
                    throw new \RuntimeException('Product not found: ' . $item['productId']);
                }
                $qty = max(0, (int) $item['qty']);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > (int) $p['shelf_stock']) {
                    throw new \RuntimeException("Only {$p['shelf_stock']} of {$p['name']} left in stock");
                }
                $lineData[] = ['product' => $p, 'qty' => $qty];
                $subtotal += (float) $p['price'] * $qty;
            }
            if (!$lineData) {
                throw new \RuntimeException('Your basket is empty');
            }

            $deliveryFee = ($mode === 'Delivery') ? ($subtotal >= 2000 ? 0 : 250) : 0;
            $total = round($subtotal + $deliveryFee, 2);

            $orderId = IdSequence::next($pdo, 'ord', 'ORD-', 0);
            $pdo->prepare(
                'INSERT INTO orders (id,customer_id,customer_name,phone,total,status,order_type,mode,address,payment_method,note)
                 VALUES (?,?,?,?,?,\'Pending\',\'Online\',?,?,?,?)'
            )->execute([
                $orderId, $user['id'], $user['name'], $user['phone'] ?? '—', $total,
                $mode, $mode === 'Delivery' ? $address : 'Pickup at store', $paymentMethod, $note,
            ]);

            $lineStmt = $pdo->prepare('INSERT INTO order_lines (order_id, product_id, qty, unit_price) VALUES (?,?,?,?)');
            foreach ($lineData as $ld) {
                $lineStmt->execute([$orderId, $ld['product']['id'], $ld['qty'], $ld['product']['price']]);
                $pdo->prepare('UPDATE products SET shelf_stock = shelf_stock - ? WHERE id = ?')->execute([$ld['qty'], $ld['product']['id']]);
            }
            self::addTimeline($pdo, $orderId, 'Order placed online');

            if ($mode === 'Delivery' && $saveAddress) {
                $pdo->prepare('UPDATE users SET address = ? WHERE id = ?')->execute([$address, $user['id']]);
            }
            $pdo->prepare('UPDATE users SET payment_method = ? WHERE id = ?')->execute([$paymentMethod, $user['id']]);

            $pdo->prepare('INSERT INTO sales_daily (sale_date, total) VALUES (CURDATE(), ?) ON DUPLICATE KEY UPDATE total = total + VALUES(total)')
                ->execute([$total]);

            Notify::push('info', '☰', "New online order {$orderId}", "{$user['name']} · Rs. " . number_format($total, 2) . " · {$mode}", 'orders');
            Audit::log($user, 'ORDER_PLACED', $orderId);

            $pdo->commit();
            return self::detail($pdo, $orderId);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
