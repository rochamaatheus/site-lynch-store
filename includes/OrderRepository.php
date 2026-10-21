<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

final class OrderRepository
{
    public const STATUSES = ['novo' => 'Novo', 'em_atendimento' => 'Em atendimento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'];

    public static function list(int $before = 0): array
    {
        $stmt = db()->prepare('SELECT id,item_count,total_cents,has_price_on_request,created_at,status FROM checkout_logs ' . ($before > 0 ? 'WHERE id < ? ' : '') . 'ORDER BY id DESC LIMIT 31');
        $stmt->execute($before > 0 ? [$before] : []);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT id,cart_json,item_count,total_cents,has_price_on_request,created_at,status,notes FROM checkout_logs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function save(int $id, string $status, string $notes): void
    {
        if (!isset(self::STATUSES[$status]) || mb_strlen($notes) > 2000) { throw new InvalidArgumentException('Status inválido ou observação maior que 2.000 caracteres.'); }
        db()->prepare('UPDATE checkout_logs SET status=?, notes=? WHERE id=?')->execute([$status, $notes, $id]);
    }
}
