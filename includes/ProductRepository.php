<?php
/**
 * Lynch Store - leitura de produtos (fase 3: Catalogo publico)
 *
 * Regras que valem para TODO metodo deste arquivo:
 *  - so devolve produtos com active=1 (soft-delete do admin some do site);
 *  - todo valor vindo do usuario entra por prepared statement (nunca concatenado);
 *  - a listagem ja traz a imagem de capa numa unica consulta (subquery
 *    correlacionada apoiada no indice idx_product_images_primary), entao
 *    renderizar 40 cards continua sendo 1 ida ao banco, nao 41.
 *
 * Uso:
 *     require_once __DIR__ . '/ProductRepository.php';
 *     $produtos = ProductRepository::listActive(['availability' => 'pronta_entrega', 'limit' => 8]);
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ProductRepository
{
    /** Ordem canonica das imagens de um produto: capa primeiro. */
    private const IMAGE_ORDER = 'pi.is_primary DESC, pi.sort_order ASC, pi.id ASC';
    private const DETAIL_COLUMNS = 'p.id,p.category_id,p.name,p.slug,p.short_description,p.description,p.price_cents,p.price_on_request,p.availability,p.active,p.featured,p.sort_order,p.created_at,p.updated_at';

    /** Valores aceitos pela coluna ENUM availability. */
    public const AVAILABILITIES = ['sob_encomenda', 'pronta_entrega'];

    // -----------------------------------------------------------------------
    // Listagem
    // -----------------------------------------------------------------------

    /**
     * Lista produtos ativos com filtro e paginacao.
     *
     * Opcoes aceitas:
     *   availability   string   'sob_encomenda' | 'pronta_entrega'
     *   category_id    int
     *   category_slug  string
     *   featured       bool
     *   search         string   busca por nome
     *   limit          int      padrao 12, teto 60
     *   offset         int      padrao 0
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>
     */
    public static function listActive(array $opts = []): array
    {
        [$where, $params] = self::buildFilters($opts);

        $limit  = self::clampLimit($opts['limit'] ?? 12);
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $sql = 'SELECT ' . self::listColumns() . '
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE ' . $where . '
                 ORDER BY p.sort_order ASC, p.id ASC
                 LIMIT :limit OFFSET :offset';

        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Quantos produtos ativos batem com o mesmo filtro de listActive()
     * (ignora limit/offset). Usado para paginacao e para os contadores.
     *
     * @param array<string,mixed> $opts
     */
    public static function countActive(array $opts = []): int
    {
        [$where, $params] = self::buildFilters($opts);

        $sql = 'SELECT COUNT(*)
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE ' . $where;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Produto individual
    // -----------------------------------------------------------------------

    /**
     * Um produto ativo pelo slug, com categoria e TODAS as imagens
     * (capa primeiro) em $produto['images'].
     *
     * @return array<string,mixed>|null  null quando o slug nao existe ou o
     *                                   produto esta inativo (o chamador
     *                                   responde 404)
     */
    public static function findBySlug(string $slug): ?array
    {
        return self::findOneBy('p.slug = :slug', [':slug' => $slug]);
    }

    /**
     * Um produto ativo pelo id (usado pelo carrinho na fase 4).
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return self::findOneBy('p.id = :id', [':id' => $id]);
    }

    public static function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) { return []; }
        $stmt = db()->prepare('SELECT ' . self::listColumns() . ' FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.active=1 AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll() as $row) { $result[(int) $row['id']] = self::hydrate($row); }
        return $result;
    }

    /**
     * Imagens de um produto, capa primeiro.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function images(int $productId): array
    {
        $stmt = db()->prepare(
            'SELECT id, product_id, file_path, source_path, focal_y, alt_text, is_primary, sort_order
               FROM product_images
              WHERE product_id = :id
              ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute([':id' => $productId]);

        return $stmt->fetchAll();
    }

    /**
     * Produtos da MESMA categoria, excluindo o atual.
     *
     * Se a categoria nao render o suficiente (ou o produto nao tiver
     * categoria), completa com outros produtos ativos - a secao "voce tambem
     * pode gostar" nunca fica pela metade.
     *
     * @param array<string,mixed> $product
     * @return array<int,array<string,mixed>>
     */
    public static function related(array $product, int $limit = 4): array
    {
        $limit      = self::clampLimit($limit);
        $productId  = (int) ($product['id'] ?? 0);
        $categoryId = isset($product['category_id']) ? (int) $product['category_id'] : 0;
        $related    = [];

        if ($categoryId > 0) {
            $sql = 'SELECT ' . self::listColumns() . '
                      FROM products p
                      LEFT JOIN categories c ON c.id = p.category_id
                     WHERE p.active = 1
                       AND p.category_id = :category_id
                       AND p.id <> :product_id
                     ORDER BY p.sort_order ASC, p.id ASC
                     LIMIT :limit';

            $stmt = db()->prepare($sql);
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $related = array_map([self::class, 'hydrate'], $stmt->fetchAll());
        }

        $missing = $limit - count($related);
        if ($missing <= 0) {
            return $related;
        }

        // Preenche o resto com produtos de outras categorias, sem repetir.
        $exclude = array_map(static fn (array $r): int => (int) $r['id'], $related);
        $exclude[] = $productId;

        $placeholders = [];
        $params       = [];
        foreach (array_values($exclude) as $i => $id) {
            $placeholders[]        = ':ex' . $i;
            $params[':ex' . $i]    = $id;
        }

        $sql = 'SELECT ' . self::listColumns() . '
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.active = 1
                   AND p.id NOT IN (' . implode(',', $placeholders) . ')
                 ORDER BY p.sort_order ASC, p.id ASC
                 LIMIT :limit';

        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $missing, PDO::PARAM_INT);
        $stmt->execute();

        return array_merge($related, array_map([self::class, 'hydrate'], $stmt->fetchAll()));
    }

    // -----------------------------------------------------------------------
    // ADMIN - leitura (fase 6)
    //
    // Diferenca fundamental para os metodos acima: aqui NAO existe o filtro
    // p.active = 1. O painel precisa ver produto desativado (soft-delete) para
    // poder reativar; a vitrine publica nunca ve.
    // -----------------------------------------------------------------------

    /**
     * Lista TODOS os produtos (ativos e inativos) para a tabela do admin.
     *
     * Filtros aceitos:
     *   search        string  busca por nome ou slug
     *   category_id   int     0/'' = todas; -1 = "sem categoria"
     *   availability  string  'sob_encomenda' | 'pronta_entrega'
     *   status        string  'ativo' | 'inativo' (vazio = todos)
     *   limit / offset int
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(array $filters = []): array
    {
        [$where, $params] = self::buildAdminFilters($filters);

        $limit  = max(1, min(500, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = 'SELECT ' . self::listColumns() . '
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE ' . $where . '
                 ORDER BY p.sort_order ASC, p.id ASC
                 LIMIT :limit OFFSET :offset';

        $stmt = db()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * Quantos produtos batem com o mesmo filtro de listAll().
     *
     * @param array<string,mixed> $filters
     */
    public static function countAll(array $filters = []): int
    {
        [$where, $params] = self::buildAdminFilters($filters);

        $stmt = db()->prepare(
            'SELECT COUNT(*)
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Um produto pelo id INCLUSIVE se estiver inativo, com todas as imagens.
     * E o que o formulario de edicao do admin usa (findById() nao serve: ele
     * filtra active=1 e um produto excluido nunca poderia ser reeditado).
     *
     * @return array<string,mixed>|null
     */
    public static function findAnyById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT ' . self::DETAIL_COLUMNS . ', c.name AS category_name, c.slug AS category_slug, c.active AS category_active
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row                      = self::hydrate($row);
        $row['images']            = self::images((int) $row['id']);
        $row['primary_image']     = $row['images'][0]['file_path'] ?? null;
        $row['primary_image_alt'] = $row['images'][0]['alt_text'] ?? null;

        return $row;
    }

    // -----------------------------------------------------------------------
    // ADMIN - escrita (fase 6)
    // -----------------------------------------------------------------------

    /**
     * Insere um produto e devolve o id novo.
     *
     * Espera $data ja NORMALIZADO por normalizeWritable() (o handler
     * produto-salvar.php chama sanitize() antes). Todo valor entra por
     * prepared statement.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $d = self::normalizeWritable($data);

        $stmt = db()->prepare(
            'INSERT INTO products
                 (category_id, name, slug, short_description, description,
                  price_cents, price_on_request, availability, active, featured, sort_order)
             VALUES
                 (:category_id, :name, :slug, :short_description, :description,
                  :price_cents, :price_on_request, :availability, :active, :featured, :sort_order)'
        );
        $stmt->execute($d);

        return (int) db()->lastInsertId();
    }

    /**
     * Atualiza um produto existente.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $d       = self::normalizeWritable($data);
        $d[':id'] = $id;

        $stmt = db()->prepare(
            'UPDATE products
                SET category_id       = :category_id,
                    name              = :name,
                    slug              = :slug,
                    short_description = :short_description,
                    description       = :description,
                    price_cents       = :price_cents,
                    price_on_request  = :price_on_request,
                    availability      = :availability,
                    active            = :active,
                    featured          = :featured,
                    sort_order        = :sort_order
              WHERE id = :id'
        );
        $stmt->execute($d);
    }

    /**
     * "Excluir" do admin = active=0 (soft-delete).
     *
     * NUNCA um DELETE real: checkout_logs guarda snapshots historicos que
     * citam esses produtos, e as fotos em uploads/ continuam validas se a Ray
     * quiser reativar o item depois.
     */
    public static function softDelete(int $id): void
    {
        $stmt = db()->prepare('UPDATE products SET active = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Desfaz o softDelete().
     */
    public static function restore(int $id): void
    {
        $stmt = db()->prepare('UPDATE products SET active = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * true se o slug ja pertence a algum produto (opcionalmente ignorando um id,
     * para o formulario de edicao nao acusar conflito consigo mesmo).
     */
    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId === null) {
            $stmt = db()->prepare('SELECT 1 FROM products WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = db()->prepare('SELECT 1 FROM products WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Gera um slug livre a partir de um desejado, acrescentando -2, -3, ...
     * ate nao colidir. Usado quando o admin deixa o campo vazio ou digita um
     * slug ja usado: em vez de travar o cadastro, o handler ajusta e avisa.
     */
    public static function uniqueSlug(string $desired, ?int $excludeId = null): string
    {
        $base = $desired !== '' ? $desired : 'produto';
        $slug = $base;
        $i    = 2;

        while (self::slugExists($slug, $excludeId)) {
            $suffix = '-' . $i;
            // VARCHAR(200) no schema: corta a base antes de somar o sufixo.
            $slug   = substr($base, 0, 200 - strlen($suffix)) . $suffix;
            $i++;
            if ($i > 500) {                       // trava de seguranca
                return substr($base, 0, 180) . '-' . bin2hex(random_bytes(6));
            }
        }

        return $slug;
    }

    // -----------------------------------------------------------------------
    // ADMIN - imagens (fase 6)
    // -----------------------------------------------------------------------

    /**
     * Insere uma imagem. Se for a PRIMEIRA foto do produto, vira capa
     * automaticamente (produto sem capa renderiza placeholder na vitrine).
     *
     * @return int id da linha em product_images
     */
    public static function addImage(
        int $productId,
        string $filePath,
        ?string $altText = null,
        ?string $sourcePath = null,
        int $focalY = 15
    ): int {
        $pdo = db();

        // Trava a linha do produto: dois envios simultaneos da primeira foto
        // nao podem ambos virar capa.
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$productId]);

            $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
            $stmt->execute([$productId]);
            [$count, $maxSort] = array_values($stmt->fetch(PDO::FETCH_NUM));

            $isPrimary = ((int) $count === 0) ? 1 : 0;
            $sortOrder = (int) $maxSort + 1;

            $ins = $pdo->prepare(
                'INSERT INTO product_images (product_id, file_path, source_path, focal_y, alt_text, is_primary, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $productId,
                $filePath,
                $sourcePath,
                max(0, min(100, $focalY)),
                $altText,
                $isPrimary,
                $sortOrder,
            ]);

            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $newId;
    }

    /**
     * Grava o novo ponto de recorte de uma foto.
     *
     * Os ARQUIVOS derivados sao regravados pelo chamador (ImageProcessor) com o
     * mesmo nome de sempre, entao file_path nao muda - so este numero, que serve
     * para a tela do admin mostrar qual opcao esta ativa e para um
     * reprocessamento futuro reproduzir o mesmo enquadramento.
     */
    public static function setImageFocal(int $imageId, int $productId, int $focalY): bool
    {
        $stmt = db()->prepare(
            'UPDATE product_images SET focal_y = ? WHERE id = ? AND product_id = ?'
        );
        $stmt->execute([max(0, min(100, $focalY)), $imageId, $productId]);

        // rowCount() e 0 tambem quando o valor nao mudou: so a existencia da foto decide.
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $exists = db()->prepare('SELECT 1 FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
        $exists->execute([$imageId, $productId]);

        return $exists->fetchColumn() !== false;
    }

    /**
     * Uma linha de product_images (usada antes de apagar, para saber o arquivo
     * fisico a remover do disco).
     *
     * @return array<string,mixed>|null
     */
    public static function findImage(int $imageId, int $productId): ?array
    {
        $stmt = db()->prepare(
            'SELECT id, product_id, file_path, source_path, focal_y, alt_text, is_primary, sort_order
               FROM product_images
              WHERE id = ? AND product_id = ?
              LIMIT 1'
        );
        $stmt->execute([$imageId, $productId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Define a capa do produto. Transacao obrigatoria: o schema nao consegue
     * expressar "so uma is_primary=1 por produto" (MySQL nao tem indice unico
     * parcial), entao a garantia e o par zera-tudo + marca-uma acontecer junto.
     */
    public static function setPrimaryImage(int $productId, int $imageId): bool
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $check = $pdo->prepare('SELECT 1 FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
            $check->execute([$imageId, $productId]);
            if ($check->fetchColumn() === false) {
                $pdo->rollBack();
                return false;
            }

            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')
                ->execute([$productId]);
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')
                ->execute([$imageId, $productId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Apaga a linha da imagem. Se ela era a capa, promove a proxima da ordem -
     * senao o produto ficaria com fotos mas nenhuma marcada como capa.
     * O arquivo fisico e responsabilidade do chamador (admin/produto-imagens.php).
     */
    public static function deleteImage(int $imageId, int $productId): bool
    {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $row = self::findImage($imageId, $productId);
            if ($row === null) {
                $pdo->rollBack();
                return false;
            }

            $pdo->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')
                ->execute([$imageId, $productId]);

            if ((int) $row['is_primary'] === 1) {
                $next = $pdo->prepare(
                    'SELECT id FROM product_images
                      WHERE product_id = ?
                      ORDER BY sort_order ASC, id ASC
                      LIMIT 1'
                );
                $next->execute([$productId]);
                $nextId = $next->fetchColumn();

                if ($nextId !== false) {
                    $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')
                        ->execute([(int) $nextId]);
                }
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reordena varias imagens de uma vez (um UPDATE por linha, tudo dentro de
     * uma transacao - ou a ordem inteira entra, ou nada entra).
     *
     * @param array<int,int> $orderById  imageId => sort_order
     * @return int quantas linhas foram efetivamente atualizadas
     */
    public static function reorderImages(int $productId, array $orderById): int
    {
        if ($orderById === []) {
            return 0;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt    = $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
            $touched = 0;

            foreach ($orderById as $imageId => $sortOrder) {
                $stmt->execute([(int) $sortOrder, (int) $imageId, $productId]);
                $touched += $stmt->rowCount();
            }

            $pdo->commit();

            return $touched;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------------

    /**
     * Converte o array vindo do formulario nos parametros nomeados do
     * INSERT/UPDATE, com os tipos certos e os invariantes do schema:
     *   - price_on_request=1  =>  price_cents obrigatoriamente NULL
     *   - price_cents ausente =>  price_on_request volta a 1
     *   - availability so pode ser um dos dois valores do ENUM
     *   - textos cortados no tamanho da coluna (VARCHAR nao truncaria em
     *     strict mode: daria erro 1406 e derrubaria o cadastro)
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeWritable(array $data): array
    {
        $availability = (string) ($data['availability'] ?? 'sob_encomenda');
        if (!in_array($availability, self::AVAILABILITIES, true)) {
            $availability = 'sob_encomenda';
        }

        $onRequest = !empty($data['price_on_request']);
        $cents     = $data['price_cents'] ?? null;
        $cents     = ($cents === null || $cents === '') ? null : max(0, (int) $cents);

        if ($onRequest) {
            $cents = null;
        } elseif ($cents === null) {
            // "Consultar valor" desmarcado mas sem preco digitado: nao inventa
            // R$ 0,00 - volta a ser preco sob consulta.
            $onRequest = true;
        }

        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : 0;

        $short = trim((string) ($data['short_description'] ?? ''));
        $long  = trim((string) ($data['description'] ?? ''));

        return [
            ':category_id'       => $categoryId > 0 ? $categoryId : null,
            ':name'              => mb_substr(trim((string) ($data['name'] ?? '')), 0, 180),
            ':slug'              => mb_substr(trim((string) ($data['slug'] ?? '')), 0, 200),
            ':short_description' => $short === '' ? null : mb_substr($short, 0, 255),
            ':description'       => $long === '' ? null : $long,
            ':price_cents'       => $cents,
            ':price_on_request'  => $onRequest ? 1 : 0,
            ':availability'      => $availability,
            ':active'            => !empty($data['active']) ? 1 : 0,
            ':featured'          => !empty($data['featured']) ? 1 : 0,
            ':sort_order'        => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * WHERE compartilhado por listAll()/countAll() (admin: sem active=1 fixo).
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildAdminFilters(array $filters): array
    {
        $where  = ['1=1'];
        $params = [];

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            // DOIS placeholders para o MESMO valor: db.php desliga
            // ATTR_EMULATE_PREPARES, e num prepare REAL do MySQL cada ":nome"
            // so pode aparecer uma vez na consulta - repetir :search levanta
            // "Invalid parameter number".
            $like              = '%' . addcslashes($search, '%_\\') . '%';
            $where[]           = '(p.name LIKE :search_name OR p.slug LIKE :search_slug)';
            $params[':search_name'] = $like;
            $params[':search_slug'] = $like;
        }

        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : 0;
        if ($categoryId === -1) {
            $where[] = 'p.category_id IS NULL';
        } elseif ($categoryId > 0) {
            $where[]                = 'p.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        $availability = isset($filters['availability']) ? (string) $filters['availability'] : '';
        if (in_array($availability, self::AVAILABILITIES, true)) {
            $where[]                 = 'p.availability = :availability';
            $params[':availability'] = $availability;
        }

        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        if ($status === 'ativo') {
            $where[] = 'p.active = 1';
        } elseif ($status === 'inativo') {
            $where[] = 'p.active = 0';
        }

        if (!empty($filters['cursor']) && is_string($filters['cursor'])) {
            $cursor = json_decode(base64_decode($filters['cursor'], true) ?: '', true);
            if (is_array($cursor) && count($cursor) === 2 && is_int($cursor[0]) && is_int($cursor[1]) && $cursor[1] > 0) {
                $where[] = '(p.sort_order > :cursor_sort OR (p.sort_order = :cursor_same_sort AND p.id > :cursor_id))';
                $params[':cursor_sort'] = $cursor[0];
                $params[':cursor_same_sort'] = $cursor[0];
                $params[':cursor_id'] = $cursor[1];
            }
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Colunas da listagem + capa resolvida por subquery (sem N+1).
     */
    private static function listColumns(): string
    {
        return "
            p.id,
            p.category_id,
            p.name,
            p.slug,
            p.short_description,
            p.price_cents,
            p.price_on_request,
            p.availability,
            p.active,
            p.featured,
            p.sort_order,
            c.name AS category_name,
            c.slug AS category_slug,
            (
                SELECT pi.file_path
                  FROM product_images pi
                 WHERE pi.product_id = p.id
                 ORDER BY " . self::IMAGE_ORDER . "
                 LIMIT 1
            ) AS primary_image,
            (
                SELECT pi.alt_text
                  FROM product_images pi
                 WHERE pi.product_id = p.id
                 ORDER BY " . self::IMAGE_ORDER . "
                 LIMIT 1
            ) AS primary_image_alt
        ";
    }

    /**
     * Monta o WHERE compartilhado por listActive() e countActive().
     *
     * @param array<string,mixed> $opts
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function buildFilters(array $opts): array
    {
        $where  = ['p.active = 1'];
        $params = [];

        $availability = isset($opts['availability']) ? (string) $opts['availability'] : '';
        if ($availability !== '' && in_array($availability, self::AVAILABILITIES, true)) {
            $where[]                 = 'p.availability = :availability';
            $params[':availability'] = $availability;
        }

        if (!empty($opts['category_id'])) {
            $where[]                = 'p.category_id = :category_id';
            $params[':category_id'] = (int) $opts['category_id'];
        }

        if (!empty($opts['category_slug'])) {
            $where[]                  = 'c.slug = :category_slug AND c.active = 1';
            $params[':category_slug'] = (string) $opts['category_slug'];
        }

        if (!empty($opts['featured'])) {
            $where[] = 'p.featured = 1';
        }

        if (!empty($opts['cursor']) && is_string($opts['cursor'])) {
            $cursor = json_decode(base64_decode($opts['cursor'], true) ?: '', true);
            if (is_array($cursor) && count($cursor) === 2 && is_int($cursor[0]) && is_int($cursor[1]) && $cursor[1] > 0) {
                $where[] = '(p.sort_order > :cursor_sort OR (p.sort_order = :cursor_same_sort AND p.id > :cursor_id))';
                $params[':cursor_sort'] = $cursor[0];
                $params[':cursor_same_sort'] = $cursor[0];
                $params[':cursor_id'] = $cursor[1];
            }
        }

        $search = isset($opts['search']) ? trim((string) $opts['search']) : '';
        if ($search !== '') {
            $where[]           = 'p.name LIKE :search';
            // Escapa os curingas do LIKE para que "100%" seja buscado literalmente.
            $params[':search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private static function findOneBy(string $condition, array $params): ?array
    {
        $sql = 'SELECT ' . self::DETAIL_COLUMNS . ',
                    c.name AS category_name,
                    c.slug AS category_slug
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.active = 1
                   AND ' . $condition . '
                 LIMIT 1';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row              = self::hydrate($row);
        $row['images']    = self::images((int) $row['id']);
        $row['primary_image'] = $row['images'][0]['file_path'] ?? null;
        $row['primary_image_alt'] = $row['images'][0]['alt_text'] ?? null;

        return $row;
    }

    /**
     * Normaliza tipos vindos do PDO (evita "1"/"0" string em ifs de template).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['id']               = (int) $row['id'];
        $row['category_id']      = isset($row['category_id']) ? (int) $row['category_id'] : null;
        $row['price_cents']      = isset($row['price_cents']) ? (int) $row['price_cents'] : null;
        $row['price_on_request'] = (bool) ($row['price_on_request'] ?? true);
        $row['featured']         = (bool) ($row['featured'] ?? false);
        // active so aparece nas consultas do admin (a vitrine ja filtra active=1
        // no WHERE); quando ausente, o registro so pode ter vindo de la = ativo.
        $row['active']           = (bool) ($row['active'] ?? true);
        $row['sort_order']       = (int) ($row['sort_order'] ?? 0);

        return $row;
    }

    private static function clampLimit(mixed $limit): int
    {
        $limit = (int) $limit;

        // Teto de 96: a home usa "Carregar mais" cumulativo (limit = pagina * 8),
        // entao o limite precisa acomodar varias paginas somadas sem virar um
        // "SELECT * " acidental se alguem digitar ?page=9999 na URL.
        return max(1, min(96, $limit === 0 ? 12 : $limit));
    }
}
