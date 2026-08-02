<?php
namespace App\Domain;

use App\Support\Audit;
use App\Support\IdSequence;
use PDO;

class SupplierService
{
    public static function list(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT s.*, (SELECT COUNT(*) FROM purchase_orders p WHERE p.supplier_id = s.id) AS po_count
             FROM suppliers s ORDER BY s.name"
        )->fetchAll();
    }

    public static function find(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return string the supplier id (existing or newly created) */
    public static function save(PDO $pdo, ?string $id, array $data, array $user): string
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Company name is required');
        }
        $contact = $data['contact'] ?? '';
        $email = $data['email'] ?? '';
        $lead = max(1, (int) ($data['leadDays'] ?? 1));
        $items = $data['suppliesSummary'] ?? '';

        if ($id) {
            $pdo->prepare('UPDATE suppliers SET name=?, contact=?, email=?, lead_days=?, supplies_summary=? WHERE id=?')
                ->execute([$name, $contact, $email, $lead, $items, $id]);
            Audit::log($user, 'SUPPLIER_EDIT', $name);
            return $id;
        }

        $newId = IdSequence::next($pdo, 'supplier', 'S', 2);
        $pdo->prepare('INSERT INTO suppliers (id,name,contact,email,lead_days,supplies_summary) VALUES (?,?,?,?,?,?)')
            ->execute([$newId, $name, $contact, $email, $lead, $items]);
        Audit::log($user, 'SUPPLIER_ADD', $name);
        return $newId;
    }

    public static function remove(PDO $pdo, string $id, array $user): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ingredients WHERE supplier_id = ?');
        $stmt->execute([$id]);
        $linked = (int) $stmt->fetchColumn();
        if ($linked > 0) {
            throw new \RuntimeException("Cannot remove — {$linked} ingredient(s) linked. Re-assign them first.");
        }
        $s = self::find($pdo, $id);
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        Audit::log($user, 'SUPPLIER_REMOVE', $s['name'] ?? $id);
    }
}
