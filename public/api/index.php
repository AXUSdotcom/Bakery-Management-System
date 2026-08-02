<?php
/**
 * Single front controller for the whole JSON API. Routed via public/.htaccess,
 * which rewrites /api/<path> requests here with the path in $_GET['route'].
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Support\Auth;
use App\Support\Response;
use App\Controllers\{
    AccountController, AuditController, AuthController, DashboardController,
    InventoryController, NotificationController, OrderController, ProductController,
    ProductionController, PurchaseController, ShopController, SupplierController, UserController, WastageController
};

Auth::start();

$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['route'] ?? ''), '/');
$segments = $route === '' ? [] : explode('/', $route);
$module = $segments[0] ?? '';

try {
    switch ($module) {
        case 'auth':
            match (true) {
                $segments[1] === 'login' && $method === 'POST' => AuthController::login(),
                $segments[1] === 'register' && $method === 'POST' => AuthController::register(),
                $segments[1] === 'logout' && $method === 'POST' => AuthController::logout(),
                $segments[1] === 'me' && $method === 'GET' => AuthController::me(),
                default => Response::error('Not found', 404),
            };
            break;

        case 'dashboard':
            DashboardController::show();
            break;

        case 'inventory':
            match (true) {
                count($segments) === 1 && $method === 'GET' => InventoryController::index(),
                ($segments[1] ?? '') === 'batches' && $method === 'GET' => InventoryController::batches(),
                ($segments[1] ?? '') === 'ingredients' && $method === 'POST' => InventoryController::newIngredient(),
                ($segments[1] ?? '') === 'receive' && $method === 'POST' => InventoryController::receive(),
                ($segments[1] ?? '') === 'waste' && $method === 'POST' => InventoryController::wasteIngredient(),
                ($segments[1] ?? '') === 'waste-batch' && $method === 'POST' => InventoryController::wasteBatch(),
                ($segments[1] ?? '') === 'run-expiry-job' && $method === 'POST' => InventoryController::runExpiryJob(),
                default => Response::error('Not found', 404),
            };
            break;

        case 'production':
            match (true) {
                count($segments) === 1 && $method === 'GET' => ProductionController::index(),
                ($segments[1] ?? '') === 'suggest' && $method === 'POST' => ProductionController::suggest(),
                ($segments[1] ?? '') === 'feasibility' && $method === 'POST' => ProductionController::feasibility(),
                ($segments[1] ?? '') === 'fit' && $method === 'POST' => ProductionController::fit(),
                ($segments[1] ?? '') === 'po-for-shortages' && $method === 'POST' => ProductionController::poForShortages(),
                ($segments[1] ?? '') === 'confirm' && $method === 'POST' => ProductionController::confirm(),
                default => Response::error('Not found', 404),
            };
            break;

        case 'purchase':
            match (true) {
                count($segments) === 1 && $method === 'GET' => PurchaseController::index(),
                ($segments[1] ?? '') === 'auto' && $method === 'POST' => PurchaseController::auto(),
                ($segments[1] ?? '') === 'auto-all' && $method === 'POST' => PurchaseController::autoAll(),
                count($segments) === 3 && $segments[2] === 'preview' && $method === 'GET' => PurchaseController::preview($segments[1]),
                count($segments) === 3 && $segments[2] === 'send' && $method === 'POST' => PurchaseController::send($segments[1]),
                count($segments) === 3 && $segments[2] === 'cancel' && $method === 'POST' => PurchaseController::cancel($segments[1]),
                count($segments) === 3 && $segments[2] === 'receive' && $method === 'POST' => PurchaseController::receive($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'suppliers':
            match (true) {
                count($segments) === 1 && $method === 'GET' => SupplierController::index(),
                count($segments) === 1 && $method === 'POST' => SupplierController::save(),
                count($segments) === 3 && $segments[2] === 'remove' && $method === 'POST' => SupplierController::remove($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'products':
            match (true) {
                count($segments) === 1 && $method === 'GET' => ProductController::index(),
                count($segments) === 1 && $method === 'POST' => ProductController::save(),
                count($segments) === 3 && $segments[2] === 'remove' && $method === 'POST' => ProductController::remove($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'orders':
            match (true) {
                count($segments) === 1 && $method === 'GET' => OrderController::index(),
                ($segments[1] ?? '') === 'mine' && $method === 'GET' => OrderController::mine(),
                ($segments[1] ?? '') === 'checkout' && $method === 'POST' => OrderController::checkout(),
                count($segments) === 2 && $method === 'GET' => OrderController::show($segments[1]),
                count($segments) === 3 && $segments[2] === 'advance' && $method === 'POST' => OrderController::advance($segments[1]),
                count($segments) === 3 && $segments[2] === 'staff-cancel' && $method === 'POST' => OrderController::staffCancel($segments[1]),
                count($segments) === 3 && $segments[2] === 'customer-cancel' && $method === 'POST' => OrderController::customerCancel($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'wastage':
            WastageController::index();
            break;

        case 'shop':
            match (true) {
                ($segments[1] ?? '') === 'products' && $method === 'GET' => ShopController::products(),
                default => Response::error('Not found', 404),
            };
            break;

        case 'users':
            match (true) {
                count($segments) === 1 && $method === 'GET' => UserController::index(),
                count($segments) === 1 && $method === 'POST' => UserController::create(),
                count($segments) === 3 && $segments[2] === 'toggle' && $method === 'POST' => UserController::toggle($segments[1]),
                count($segments) === 3 && $segments[2] === 'role' && $method === 'POST' => UserController::changeRole($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'notifications':
            match (true) {
                count($segments) === 1 && $method === 'GET' => NotificationController::index(),
                ($segments[1] ?? '') === 'read-all' && $method === 'POST' => NotificationController::markAllRead(),
                count($segments) === 3 && $segments[2] === 'read' && $method === 'POST' => NotificationController::markRead($segments[1]),
                default => Response::error('Not found', 404),
            };
            break;

        case 'audit':
            AuditController::index();
            break;

        case 'account':
            match (true) {
                $method === 'GET' => AccountController::show(),
                $method === 'POST' => AccountController::save(),
                default => Response::error('Not found', 404),
            };
            break;

        default:
            Response::error('Not found', 404);
    }
} catch (\Throwable $e) {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Something went wrong. Please try again.', 500);
}
