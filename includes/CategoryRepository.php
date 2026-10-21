<?php
/**
 * Lynch Store - leitura de categorias (fase 3: Catalogo publico)
 *
 * Alimenta a banda "Colecoes" da home e os filtros do catalogo.
 * So enxerga categorias com active=1; produtos contados sao so os active=1
 * (soft-delete no admin some do site publico na hora).
 *
 * Uso:
 *     require_once __DIR__ . '/CategoryRepository.php';
 *     $categorias = CategoryRepository::listActiveWithCounts();
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class CategoryRepository
{
    /**
     * Categorias ativas em ordem de exibicao, com a contagem de produtos
     * ativos e uma foto de capa (a imagem principal do primeiro produto da
     * categoria) para a banda "Colecoes".
     *
     * Uma consulta so - as subqueries correlacionadas rodam sobre 4 linhas de
     * categoria, nao ha risco de N+1 relevante aqui.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listActiveWithCounts(): array
    {
        // Cache por requisicao: o menu mobile (header) e a banda "Colecoes"
        // (home) pedem a mesma lista - sem isso seriam 2 consultas identicas.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $sql = "
            SELECT
                c.id,
                c.name,
                c.slug,
                c.description,
                c.sort_order,
                (
                    SELECT COUNT(*)
                      FROM products p
                     WHERE p.category_id = c.id
                       AND p.active = 1
                ) AS product_count,
                (
                    SELECT pi.file_path
                      FROM products p
                      JOIN product_images pi ON pi.product_id = p.id
                     WHERE p.category_id = c.id
                       AND p.active = 1
                     ORDER BY p.sort_order ASC, p.id ASC, pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1
                ) AS cover_image
              FROM categories c
             WHERE c.active = 1
             ORDER BY c.sort_order ASC, c.name ASC
        ";

        $rows = db()->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $row['product_count'] = (int) $row['product_count'];
        }
        unset($row);

        $cache = $rows;

        return $rows;
    }

    /**
     * Uma categoria ativa pelo slug, ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, name, slug, description, sort_order
               FROM categories
              WHERE slug = :slug
                AND active = 1
              LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // -----------------------------------------------------------------------
    // ADMIN (fase 6)
    //
    // Aqui NAO ha filtro active=1: o painel precisa enxergar (e reativar)
    // categoria desativada. A vitrine continua so vendo as ativas.
    // -----------------------------------------------------------------------

    /**
     * TODAS as categorias, com contagem de produtos.
     *
     * product_count conta so produtos ativos (e o numero que a vitrine mostra);
     * product_count_all conta inclusive os soft-deletados - e esse o numero que
     * importa antes de excluir uma categoria, porque products.category_id vira
     * NULL para os inativos tambem.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(): array
    {
        $sql = "
            SELECT
                c.id, c.name, c.slug, c.description, c.sort_order, c.active,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.active = 1) AS product_count,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id)                  AS product_count_all
              FROM categories c
             ORDER BY c.sort_order ASC, c.name ASC
        ";

        $rows = db()->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $row['id']                = (int) $row['id'];
            $row['sort_order']        = (int) $row['sort_order'];
            $row['active']            = (bool) $row['active'];
            $row['product_count']     = (int) $row['product_count'];
            $row['product_count_all'] = (int) $row['product_count_all'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Uma categoria pelo id, ativa ou nao (o findBySlug() publico exige active=1).
     *
     * @return array<string,mixed>|null
     */
    public static function findAnyById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, name, slug, description, sort_order, active
               FROM categories
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Insere uma categoria e devolve o id novo.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $d = self::normalizeWritable($data);

        $stmt = db()->prepare(
            'INSERT INTO categories (name, slug, description, sort_order, active)
             VALUES (:name, :slug, :description, :sort_order, :active)'
        );
        $stmt->execute($d);

        return (int) db()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $d        = self::normalizeWritable($data);
        $d[':id'] = $id;

        $stmt = db()->prepare(
            'UPDATE categories
                SET name        = :name,
                    slug        = :slug,
                    description = :description,
                    sort_order  = :sort_order,
                    active      = :active
              WHERE id = :id'
        );
        $stmt->execute($d);
    }

    /**
     * Liga/desliga a categoria (active). Devolve o estado novo.
     * E a acao "esconder da vitrine sem perder o vinculo dos produtos".
     */
    public static function toggleActive(int $id): bool
    {
        db()->prepare('UPDATE categories SET active = 1 - active WHERE id = ?')->execute([$id]);

        $row = self::findAnyById($id);

        return $row !== null && (bool) $row['active'];
    }

    /**
     * Esconde a categoria sem apagar (equivalente ao softDelete de produto).
     */
    public static function softDelete(int $id): void
    {
        db()->prepare('UPDATE categories SET active = 0 WHERE id = ?')->execute([$id]);
    }

    /**
     * DELETE real. Seguro por causa da FK products.category_id ON DELETE SET
     * NULL: os produtos vinculados sobrevivem, so ficam "sem categoria" e
     * podem ser recategorizados no admin. Nunca apaga produto.
     */
    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }

    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = db()->prepare('SELECT 1 FROM categories WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = db()->prepare('SELECT 1 FROM categories WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Slug livre a partir de um desejado (-2, -3, ...). Mesma politica do
     * ProductRepository: ajustar em vez de travar o cadastro da Ray.
     */
    public static function uniqueSlug(string $desired, ?int $excludeId = null): string
    {
        $base = $desired !== '' ? $desired : 'categoria';
        $slug = $base;
        $i    = 2;

        while (self::slugExists($slug, $excludeId)) {
            $suffix = '-' . $i;
            $slug   = substr($base, 0, 120 - strlen($suffix)) . $suffix;   // VARCHAR(120)
            $i++;
            if ($i > 500) {
                return substr($base, 0, 100) . '-' . bin2hex(random_bytes(6));
            }
        }

        return $slug;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeWritable(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));

        return [
            ':name'        => mb_substr(trim((string) ($data['name'] ?? '')), 0, 100),
            ':slug'        => mb_substr(trim((string) ($data['slug'] ?? '')), 0, 120),
            ':description' => $description === '' ? null : mb_substr($description, 0, 255),
            ':sort_order'  => (int) ($data['sort_order'] ?? 0),
            ':active'      => !empty($data['active']) ? 1 : 0,
        ];
    }
}
