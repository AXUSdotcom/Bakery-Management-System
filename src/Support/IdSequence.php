<?php
namespace App\Support;

use PDO;

/** Mirrors the prototype's DB.seq counters, backed by the id_sequences table. */
class IdSequence
{
    public static function next(PDO $pdo, string $name, string $prefix, int $pad = 0): string
    {
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare('SELECT next_value FROM id_sequences WHERE name = ? FOR UPDATE');
        $stmt->execute([$name]);
        $value = (int) $stmt->fetchColumn();
        $pdo->prepare('UPDATE id_sequences SET next_value = next_value + 1 WHERE name = ?')->execute([$name]);
        if ($ownTransaction) {
            $pdo->commit();
        }
        $num = $pad > 0 ? str_pad((string) $value, $pad, '0', STR_PAD_LEFT) : (string) $value;
        return $prefix . $num;
    }
}
