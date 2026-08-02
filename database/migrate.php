<?php
/**
 * One-shot setup runner: creates the schema (if missing), seeds the 4 staff
 * demo accounts + 1 demo customer with real password_hash()'d passwords,
 * then loads database/seed.sql for everything else.
 *
 * Usage: php database/migrate.php
 * Safe to run repeatedly — it no-ops if the `users` table already exists.
 */

require __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;
use PDO;

$pdo = Database::pdo();

function runSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
)->fetchColumn();

if ($exists) {
    fwrite(STDOUT, "Schema already present — skipping. Drop the database first to reseed from scratch.\n");
    exit(0);
}

fwrite(STDOUT, "Creating schema...\n");
runSqlFile($pdo, __DIR__ . '/schema.sql');

fwrite(STDOUT, "Seeding demo accounts...\n");
$demoUsers = [
    ['Pasindu R.',  'admin@sweetbakers.lk',   'admin123',    'admin',    '071-1000100', null, null],
    ['Nadeesha K.', 'manager@sweetbakers.lk', 'manager123',  'manager',  '071-1000200', null, null],
    ['Sunil B.',    'baker@sweetbakers.lk',   'baker123',    'baker',    '071-1000300', null, null],
    ['Kasun P.',    'store@sweetbakers.lk',   'store123',    'store',    '071-1000400', null, null],
    ['Amaya S.',    'amaya@gmail.com',        'customer123', 'customer', '077-5551234', '12, Galle Road, Colombo 03', 'Cash on delivery'],
];
$stmt = $pdo->prepare(
    'INSERT INTO users (name,email,password_hash,role,active,phone,address,payment_method) VALUES (?,?,?,?,1,?,?,?)'
);
foreach ($demoUsers as [$name, $email, $plain, $role, $phone, $address, $payment]) {
    $stmt->execute([$name, $email, password_hash($plain, PASSWORD_DEFAULT), $role, $phone, $address, $payment]);
}

fwrite(STDOUT, "Seeding reference data (suppliers, ingredients, batches, products, orders...)...\n");
runSqlFile($pdo, __DIR__ . '/seed.sql');

fwrite(STDOUT, "Done. Demo logins are documented in README.md.\n");
