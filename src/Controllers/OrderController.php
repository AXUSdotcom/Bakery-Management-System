<?php
namespace App\Controllers;

use App\Config\Database;
use App\Domain\OrdersService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

class OrderController
{
    public static function index(): void
    {
        Auth::requireModule('orders');
        $status = (string) ($_GET['status'] ?? 'all');
        Response::ok(OrdersService::list(Database::pdo(), $status));
    }

    public static function mine(): void
    {
        $user = Auth::requireRole(['customer']);
        Response::ok(OrdersService::mine(Database::pdo(), (int) $user['id']));
    }

    public static function show(string $id): void
    {
        $user = Auth::requireLogin();
        $order = OrdersService::detail(Database::pdo(), $id);
        if (!$order) {
            Response::error('Order not found', 404);
            return;
        }
        $isOwner = $user['role'] === 'customer' && (int) $order['customer_id'] === (int) $user['id'];
        $isStaff = in_array('orders', Auth::CAN[$user['role']] ?? [], true);
        if (!$isOwner && !$isStaff) {
            Response::error('Forbidden', 403);
            return;
        }
        Response::ok($order);
    }

    /** Bundles ordNext/mDispatch/markDelivered — the one status transition endpoint. */
    public static function advance(string $id): void
    {
        $user = Auth::requireModule('orders');
        $body = Request::json();
        $toStatus = (string) ($body['toStatus'] ?? '');
        $pdo = Database::pdo();
        try {
            if ($toStatus === 'Out for delivery') {
                OrdersService::dispatch($pdo, $id, [
                    'driverName' => $body['driverName'] ?? '',
                    'driverPhone' => $body['driverPhone'] ?? '',
                    'vehicleType' => $body['vehicleType'] ?? 'Motorbike',
                    'vehicleNo' => $body['vehicleNo'] ?? '',
                    'eta' => $body['eta'] ?? 'Within 45 min',
                ], $user);
            } elseif ($toStatus === 'Delivered') {
                OrdersService::markDelivered($pdo, $id, $user);
            } elseif (in_array($toStatus, ['Preparing', 'Ready'], true)) {
                OrdersService::advance($pdo, $id, $toStatus, $user);
            } else {
                throw new \RuntimeException('Invalid status transition');
            }
            Response::ok(OrdersService::detail($pdo, $id));
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function staffCancel(string $id): void
    {
        $user = Auth::requireModule('orders');
        try {
            OrdersService::staffCancel(Database::pdo(), $id, $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function customerCancel(string $id): void
    {
        $user = Auth::requireRole(['customer']);
        try {
            OrdersService::customerCancel(Database::pdo(), $id, (int) $user['id'], $user);
            Response::ok(null);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    public static function checkout(): void
    {
        $user = Auth::requireRole(['customer']);
        $body = Request::json();
        $items = array_map(fn($i) => ['productId' => $i['productId'], 'qty' => (int) $i['qty']], $body['items'] ?? []);
        try {
            $order = OrdersService::checkout(
                Database::pdo(),
                $user,
                $items,
                (string) ($body['mode'] ?? 'Delivery'),
                (string) ($body['address'] ?? ''),
                (string) ($body['paymentMethod'] ?? 'Cash on delivery'),
                (string) ($body['note'] ?? ''),
                (bool) ($body['saveAddress'] ?? false)
            );
            Response::ok($order);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }
}
