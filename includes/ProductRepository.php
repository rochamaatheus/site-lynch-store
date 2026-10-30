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
require_once __DIR__ . '/functions.php';

final class ProductRepository
{
    /** Ordem canonica das imagens de um produto: capa primeiro. */
    private const IMAGE_ORDER = 'pi.is_primary DESC, pi.sort_order ASC, pi.id ASC';
    private const BASE_DETAIL_COLUMNS = 'p.id,p.category_id,p.name,p.slug,p.short_description,p.description,p.price_cents,p.price_on_request,p.availability,p.active,p.featured,p.sort_order,p.created_at,p.updated_at';

    /** Valores aceitos pela coluna ENUM availability. */
    public const AVAILABILITIES = ['sob_encomenda', 'pronta_entrega'];

    /** Limite padrao de "estoque baixo" (Configuracoes pode trocar). */
    public const LOW_STOCK_DEFAULT = 3;

    /** Teto da coluna SMALLINT UNSIGNED stock_qty. */
    public const STOCK_MAX = 65535;

    /** Abas da lista do painel. */
    public const ADMIN_TABS = ['promocao', 'destaque', 'baixo', 'esgotado', 'visivel', 'oculto'];

    private static ?bool $stockReady = null;

    /**
     * true quando o banco ja tem as colunas de estoque e promocao (db/upgrade.php).
     * Enquanto o upgrade nao for aplicado, a loja continua funcionando como antes:
     * as leituras devolvem "sem estoque controlado, sem promocao" e as acoes novas
     * do painel avisam que falta atualizar o banco. Uma consulta por requisicao.
     */
    public static function stockReady(): bool
    {
        if (self::$stockReady === null) {
            self::$stockReady = db()->query("SHOW COLUMNS FROM products LIKE 'sale_ends_on'")->fetch() !== false;
        }
        return self::$stockReady;
    }

    /** Colunas de estoque e promocao, ou literais neutros se o banco for antigo. */
    private static function stockColumns(): string
    {
        return self::stockReady()
            ? 'p.stock_qty, p.show_stock, p.sold_out, p.sale_price_cents, p.sale_ends_on'
            : 'NULL AS stock_qty, 0 AS show_stock, 0 AS sold_out, NULL AS sale_price_cents, NULL AS sale_ends_on';
    }

    private static function detailColumns(): string
    {
        return self::BASE_DETAIL_COLUMNS . ', ' . self::stockColumns();
    }

    private static function today(): string
    {
        return date('Y-m-d');
    }

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
     *   sort           string   'preco_asc' | 'preco_desc' | vazio = ordem da loja
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>
     */
    public static function listActive(array $opts = []): array
    {
        [$where, $params] = self::buildFilters($opts);

        $limit  = self::clampLimit($opts['limit'] ?? 12);
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        // Lista fechada: o valor recebido nunca entra no SQL. Sem preco vai para o fim.
        $order = match ($opts['sort'] ?? '') {
            'preco_asc'  => 'p.price_cents IS NULL, p.price_cents ASC, p.id ASC',
            'preco_desc' => 'p.price_cents IS NULL, p.price_cents DESC, p.id ASC',
            default      => 'p.sort_order ASC, p.id ASC',
        };

        $sql = 'SELECT ' . self::listColumns() . '
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                 WHERE ' . $where . '
                 ORDER BY ' . $order . '
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
            'SELECT ' . self::detailColumns() . ', c.name AS category_name, c.slug AS category_slug, c.active AS category_active
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
        $stockCols = self::stockReady() ? ', stock_qty, show_stock, sold_out, sale_price_cents, sale_ends_on' : '';
        $stockVals = self::stockReady() ? ', :stock_qty, :show_stock, :sold_out, :sale_price_cents, :sale_ends_on' : '';
        if (!self::stockReady()) { $d = array_diff_key($d, array_flip([':stock_qty', ':show_stock', ':sold_out', ':sale_price_cents', ':sale_ends_on'])); }

        $stmt = db()->prepare(
            'INSERT INTO products
                 (category_id, name, slug, short_description, description,
                  price_cents, price_on_request, availability, active, featured, sort_order' . $stockCols . ')
             VALUES
                 (:category_id, :name, :slug, :short_description, :description,
                  :price_cents, :price_on_request, :availability, :active, :featured, :sort_order' . $stockVals . ')'
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
        $stockSet = '';
        if (self::stockReady()) {
            $stockSet = ', stock_qty = :stock_qty, show_stock = :show_stock, sold_out = :sold_out,
                    sale_price_cents = :sale_price_cents, sale_ends_on = :sale_ends_on';
        } else {
            $d = array_diff_key($d, array_flip([':stock_qty', ':show_stock', ':sold_out', ':sale_price_cents', ':sale_ends_on']));
        }

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
                    sort_order        = :sort_order' . $stockSet . '
              WHERE id = :id'
        );
        $stmt->execute($d);
    }

    // -----------------------------------------------------------------------
    // ADMIN - acoes rapidas da lista (estoque, promocao, destaque, esgotado)
    //
    // Cada uma e um UPDATE de uma linha, parametrizado, e devolve o produto
    // atualizado. Erros que a Ray precisa entender saem como DomainException
    // com a mensagem pronta para a tela.
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed> produto atualizado
     */
    public static function quickAction(int $id, string $op, array $input = []): array
    {
        $product = self::findAnyById($id);
        if ($product === null) {
            throw new DomainException('Produto não encontrado. Atualize a lista.');
        }
        if (!self::stockReady() && $op !== 'featured') {
            throw new DomainException('Falta atualizar o banco da loja para usar estoque e promoção. Avise o suporte.');
        }

        $pdo = db();
        switch ($op) {
            case 'featured':
                $pdo->prepare('UPDATE products SET featured = ? WHERE id = ?')->execute([!empty($input['value']) ? 1 : 0, $id]);
                break;

            case 'show_stock':
                $pdo->prepare('UPDATE products SET show_stock = ? WHERE id = ?')->execute([!empty($input['value']) ? 1 : 0, $id]);
                break;

            case 'stock_set':
                $stock = self::parseStock($input['stock'] ?? '');
                // Repor estoque de um produto zerado o devolve a venda; marcar 0 o
                // deixa esgotado sozinho (is_sold_out e derivado de stock_qty = 0).
                $pdo->prepare('UPDATE products SET sold_out = IF(stock_qty = 0 AND ? > 0, 0, sold_out), stock_qty = ? WHERE id = ?')
                    ->execute([$stock ?? 0, $stock, $id]);
                break;

            case 'stock_inc':
                $pdo->prepare('UPDATE products SET sold_out = IF(stock_qty = 0, 0, sold_out), stock_qty = COALESCE(stock_qty, 0) + 1 WHERE id = ? AND (stock_qty IS NULL OR stock_qty < ' . self::STOCK_MAX . ')')
                    ->execute([$id]);
                break;

            case 'stock_dec':
                $pdo->prepare('UPDATE products SET stock_qty = stock_qty - 1 WHERE id = ? AND stock_qty > 0')->execute([$id]);
                break;

            case 'sold_out':
                $pdo->prepare('UPDATE products SET sold_out = 1 WHERE id = ?')->execute([$id]);
                break;

            case 'reactivate':
                // Peca unica que tinha zerado volta com 1; a Ray ajusta se tiver mais.
                $pdo->prepare('UPDATE products SET sold_out = 0, stock_qty = IF(stock_qty = 0, 1, stock_qty) WHERE id = ?')->execute([$id]);
                break;

            case 'sale_set':
                [$cents, $ends] = self::parseSale($product, (string) ($input['sale_price'] ?? ''), (string) ($input['sale_percent'] ?? ''), (string) ($input['sale_ends_on'] ?? ''));
                if ($cents === null) {
                    throw new DomainException('Informe o preço promocional ou a porcentagem de desconto.');
                }
                $pdo->prepare('UPDATE products SET sale_price_cents = ?, sale_ends_on = ? WHERE id = ?')->execute([$cents, $ends, $id]);
                break;

            case 'sale_off':
                $pdo->prepare('UPDATE products SET sale_price_cents = NULL, sale_ends_on = NULL WHERE id = ?')->execute([$id]);
                break;

            default:
                throw new DomainException('Ação desconhecida.');
        }

        return self::findAnyById($id) ?? $product;
    }

    /**
     * Estoque digitado: '' = sem controle (NULL), senao inteiro 0..65535.
     */
    public static function parseStock(mixed $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{1,5}$/', $raw) || (int) $raw > self::STOCK_MAX) {
            throw new DomainException('Estoque deve ser um número inteiro de 0 a ' . self::STOCK_MAX . ', ou vazio para não controlar.');
        }
        return (int) $raw;
    }

    /**
     * Valida e calcula a promocao. Aceita o preco promocional OU a porcentagem
     * (o preco tem prioridade). Devolve [centavos|null, data|null].
     *
     * @param array<string,mixed> $product precisa de price_cents e price_on_request
     * @return array{0:?int,1:?string}
     */
    public static function parseSale(array $product, string $priceText, string $percentText, string $endsText): array
    {
        $priceText = trim($priceText);
        $percentText = trim(str_replace('%', '', $percentText));
        $endsText = trim($endsText);
        if ($priceText === '' && $percentText === '') {
            return [null, null];
        }

        $price = !empty($product['price_on_request']) ? null : ($product['price_cents'] ?? null);
        if ($price === null) {
            throw new DomainException('Produto com "Consultar valor" não pode entrar em promoção. Cadastre o preço normal primeiro.');
        }
        $price = (int) $price;

        if ($priceText !== '') {
            $cents = price_to_cents($priceText);
            if ($cents === null) {
                throw new DomainException('Preço promocional inválido. Exemplo: 79,90.');
            }
        } else {
            $percent = str_replace(',', '.', $percentText);
            if (!is_numeric($percent) || (float) $percent <= 0 || (float) $percent >= 100) {
                throw new DomainException('O desconto deve ser uma porcentagem entre 1 e 99.');
            }
            $cents = (int) round($price * (100 - (float) $percent) / 100);
        }
        if ($cents < 1 || $cents >= $price) {
            throw new DomainException('O preço promocional precisa ser menor que o preço normal (' . format_price($price) . ').');
        }

        $ends = null;
        if ($endsText !== '') {
            $ends = parse_date_br($endsText);
            if ($ends === null) {
                throw new DomainException('Data final inválida. Use o calendário ou o formato dia/mês/ano.');
            }
            if ($ends < self::today()) {
                throw new DomainException('A data final da promoção já passou. Escolha hoje ou uma data futura, ou deixe vazio.');
            }
        }

        return [$cents, $ends];
    }

    /**
     * Filtros da lista do painel a partir da query string (normalizados).
     *
     * @param array<string,mixed> $q
     * @return array{search:string,category_id:int,availability:string,status:string,tab:string}
     */
    public static function adminFiltersFromQuery(array $q): array
    {
        $availability = is_string($q['disponibilidade'] ?? null) ? $q['disponibilidade'] : '';
        $status = is_string($q['status'] ?? null) ? $q['status'] : '';
        $tab = is_string($q['aba'] ?? null) ? $q['aba'] : '';

        return [
            'search'       => is_string($q['busca'] ?? null) ? mb_substr(trim($q['busca']), 0, 100) : '',
            'category_id'  => is_scalar($q['categoria'] ?? null) && $q['categoria'] !== '' ? (int) $q['categoria'] : 0,
            'availability' => in_array($availability, self::AVAILABILITIES, true) ? $availability : '',
            'status'       => in_array($status, ['ativo', 'inativo'], true) ? $status : '',
            'tab'          => in_array($tab, self::ADMIN_TABS, true) ? $tab : '',
        ];
    }

    /**
     * Contagem das abas da lista (uma consulta so), respeitando busca e filtros.
     *
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public static function adminTabCounts(array $filters, int $low): array
    {
        $empty = ['todos' => 0] + array_fill_keys(self::ADMIN_TABS, 0);
        unset($filters['tab'], $filters['cursor']);
        [$where, $params] = self::buildAdminFilters($filters);

        if (!self::stockReady()) {
            $stmt = db()->prepare('SELECT COUNT(*) AS todos, COALESCE(SUM(p.featured = 1), 0) AS destaque FROM products p WHERE ' . $where);
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];
            return array_map('intval', array_merge($empty, $row));
        }

        $params[':today_count'] = self::today();
        $params[':low_count']   = $low;
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS todos,
                    COALESCE(SUM(p.sale_price_cents IS NOT NULL AND (p.sale_ends_on IS NULL OR p.sale_ends_on >= :today_count)), 0) AS promocao,
                    COALESCE(SUM(p.featured = 1), 0) AS destaque,
                    COALESCE(SUM(p.sold_out = 0 AND p.stock_qty BETWEEN 1 AND :low_count), 0) AS baixo,
                    COALESCE(SUM(p.sold_out = 1 OR p.stock_qty = 0), 0) AS esgotado,
                    COALESCE(SUM(p.show_stock = 1 AND p.stock_qty IS NOT NULL), 0) AS visivel,
                    COALESCE(SUM(p.show_stock = 0 AND p.stock_qty IS NOT NULL), 0) AS oculto
               FROM products p
              WHERE ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return array_map('intval', array_merge($empty, $row));
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

        $stock = $data['stock_qty'] ?? null;
        $stock = ($stock === null || $stock === '') ? null : max(0, min(self::STOCK_MAX, (int) $stock));

        // Promocao so com preco fechado e abaixo do normal (o CHECK do banco
        // recusaria o resto; quem chama ja avisou a Ray antes de chegar aqui).
        $sale = $data['sale_price_cents'] ?? null;
        $sale = ($sale === null || $sale === '') ? null : (int) $sale;
        if ($onRequest || $cents === null || $sale === null || $sale < 1 || $sale >= $cents) {
            $sale = null;
        }
        $ends = $sale === null ? null : (($data['sale_ends_on'] ?? '') !== '' ? (string) $data['sale_ends_on'] : null);

        return [
            ':stock_qty'         => $stock,
            ':show_stock'        => !empty($data['show_stock']) ? 1 : 0,
            ':sold_out'          => !empty($data['sold_out']) ? 1 : 0,
            ':sale_price_cents'  => $sale,
            ':sale_ends_on'      => $ends,
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

        // Abas da lista. Lista fechada: o valor da URL nunca entra no SQL.
        $tab = isset($filters['tab']) ? (string) $filters['tab'] : '';
        if ($tab === 'destaque') {
            $where[] = 'p.featured = 1';
        } elseif (in_array($tab, self::ADMIN_TABS, true)) {
            if (!self::stockReady()) {
                $where[] = '1=0';
            } elseif ($tab === 'promocao') {
                $where[] = 'p.sale_price_cents IS NOT NULL AND (p.sale_ends_on IS NULL OR p.sale_ends_on >= :today_tab)';
                $params[':today_tab'] = self::today();
            } elseif ($tab === 'baixo') {
                $where[] = 'p.sold_out = 0 AND p.stock_qty BETWEEN 1 AND :low_tab';
                $params[':low_tab'] = max(1, (int) ($filters['low'] ?? self::LOW_STOCK_DEFAULT));
            } elseif ($tab === 'esgotado') {
                $where[] = '(p.sold_out = 1 OR p.stock_qty = 0)';
            } elseif ($tab === 'visivel') {
                $where[] = 'p.show_stock = 1 AND p.stock_qty IS NOT NULL';
            } elseif ($tab === 'oculto') {
                $where[] = 'p.show_stock = 0 AND p.stock_qty IS NOT NULL';
            }
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
            " . self::stockColumns() . ",
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
        $sql = 'SELECT ' . self::detailColumns() . ',
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

        // Estoque e promocao. Os campos derivados (sale_active, current_price_cents,
        // is_sold_out) sao a UNICA fonte de verdade para vitrine, sacola e checkout.
        $row['stock_qty']        = isset($row['stock_qty']) ? (int) $row['stock_qty'] : null;
        $row['show_stock']       = !empty($row['show_stock']);
        $row['sold_out']         = !empty($row['sold_out']);
        $row['sale_price_cents'] = isset($row['sale_price_cents']) ? (int) $row['sale_price_cents'] : null;
        $row['sale_ends_on']     = isset($row['sale_ends_on']) && $row['sale_ends_on'] !== '' ? (string) $row['sale_ends_on'] : null;

        $hasPrice = !$row['price_on_request'] && $row['price_cents'] !== null;
        $row['sale_expired'] = $row['sale_price_cents'] !== null && $row['sale_ends_on'] !== null && $row['sale_ends_on'] < self::today();
        $row['sale_active']  = $hasPrice
            && $row['sale_price_cents'] !== null
            && $row['sale_price_cents'] < $row['price_cents']
            && !$row['sale_expired'];
        $row['current_price_cents'] = $row['sale_active'] ? $row['sale_price_cents'] : ($hasPrice ? $row['price_cents'] : null);
        $row['sale_percent'] = $row['sale_active'] ? sale_percent((int) $row['price_cents'], (int) $row['sale_price_cents']) : null;
        $row['is_sold_out']  = $row['sold_out'] || $row['stock_qty'] === 0;

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
