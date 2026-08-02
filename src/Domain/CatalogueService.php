<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\IdSequence;
use App\Support\Notify;
use PDO;

class CatalogueService
{
    public static function recipeCost(PDO $pdo, string $productId): float
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(r.qty_per_unit * i.unit_cost),0) FROM recipe_lines r
             JOIN ingredients i ON i.id = r.ingredient_id WHERE r.product_id = ?'
        );
        $stmt->execute([$productId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    public static function margin(float $price, float $cost): float
    {
        return $price > 0 ? ($price - $cost) / $price * 100 : 0.0;
    }

    public static function recipeLines(PDO $pdo, string $productId): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.ingredient_id, r.qty_per_unit, i.name AS ingredient_name, i.uom
             FROM recipe_lines r JOIN ingredients i ON i.id = r.ingredient_id WHERE r.product_id = ?'
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public static function list(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
        foreach ($rows as &$p) {
            $cost = self::recipeCost($pdo, $p['id']);
            $p['recipeCost'] = $cost;
            $p['margin'] = self::margin((float) $p['price'], $cost);
            $p['maxBakeable'] = ProductionService::maxBakeable($pdo, $p['id']);
            $p['recipe'] = self::recipeLines($pdo, $p['id']);
        }
        return $rows;
    }

    public static function find(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            return null;
        }
        $p['recipe'] = self::recipeLines($pdo, $id);
        return $p;
    }

    /**
     * @param array<int,array{ingredientId:string,qtyPerUnit:float}> $recipe
     * @return string the product id (existing or newly created)
     */
    public static function save(PDO $pdo, ?string $id, array $data, array $recipe, array $user): string
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Product name is required');
        }
        $lines = array_values(array_filter($recipe, fn($r) => (float) $r['qtyPerUnit'] > 0));
        if (!$lines) {
            throw new \RuntimeException('Add at least one recipe ingredient');
        }
        $emoji = $data['emoji'] ?: '🥨';
        $price = (float) ($data['price'] ?? 0);
        $stock = (int) ($data['shelfStock'] ?? 0);
        $desc = $data['description'] ?? '';
        $isNew = $id === null;

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE products SET name=?, emoji=?, price=?, shelf_stock=?, description=? WHERE id=?')
                    ->execute([$name, $emoji, $price, $stock, $desc, $id]);
                $pdo->prepare('DELETE FROM recipe_lines WHERE product_id = ?')->execute([$id]);
            } else {
                $id = IdSequence::next($pdo, 'product', 'P', 2);
                $pdo->prepare('INSERT INTO products (id,name,emoji,price,shelf_stock,description,avg_weekly_sales) VALUES (?,?,?,?,?,?,10)')
                    ->execute([$id, $name, $emoji, $price, $stock, $desc]);
            }
            $lineStmt = $pdo->prepare('INSERT INTO recipe_lines (product_id, ingredient_id, qty_per_unit) VALUES (?,?,?)');
            foreach ($lines as $l) {
                $lineStmt->execute([$id, $l['ingredientId'], $l['qtyPerUnit']]);
            }

            if ($isNew) {
                Notify::push('info', '❏', 'New product added', "{$name} · Rs. " . number_format($price, 2), 'catalogue');
                Audit::log($user, 'PRODUCT_ADD', $name);
            } else {
                Audit::log($user, 'PRODUCT_EDIT', $name);
            }

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function remove(PDO $pdo, string $id, array $user): void
    {
        $p = self::find($pdo, $id);
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        Audit::log($user, 'PRODUCT_REMOVE', $p['name'] ?? $id);
    }
}
