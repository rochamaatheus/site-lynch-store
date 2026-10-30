<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ProductRepository.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class ProductImport
{
    public const HEADERS = ['nome', 'slug', 'categoria', 'preco', 'disponibilidade', 'descricao_curta', 'descricao', 'ativo', 'destaque', 'estoque', 'mostrar_estoque', 'preco_promocional', 'promocao_ate', 'esgotado'];
    /** Colunas opcionais de estoque e promocao: planilha antiga sem elas nao mexe nesses campos. */
    public const STOCK_HEADERS = ['estoque' => 'stock_qty', 'mostrar_estoque' => 'show_stock', 'preco_promocional' => 'sale_price_cents', 'promocao_ate' => 'sale_ends_on', 'esgotado' => 'sold_out'];
    public const MAX_ROWS = 500;

    public static function read(string $path, string $extension): array
    {
        if (!is_file($path) || filesize($path) > 3 * 1024 * 1024) {
            throw new InvalidArgumentException('Envie um arquivo de até 3 MB.');
        }
        if ($extension === 'csv') {
            $text = file_get_contents($path);
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $text);
            rewind($stream);
            $first = strtok($text, "\n") ?: '';
            $delimiter = count(str_getcsv($first, ';', '"', '')) >= count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
            $rows = [];
            while (($row = fgetcsv($stream, 65536, $delimiter, '"', '')) !== false) {
                if (count($rows) > self::MAX_ROWS) { throw new InvalidArgumentException('Limite de 500 produtos por importação.'); }
                $rows[] = $row;
            }
            fclose($stream);
            return self::normalize($rows);
        }
        if ($extension !== 'xlsx') {
            throw new InvalidArgumentException('Formato inválido. Use CSV ou XLSX sem senha.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { throw new InvalidArgumentException('Planilha XLSX inválida.'); }
        $size = 0;
        try {
            if ($zip->numFiles > 1000) { throw new InvalidArgumentException('Planilha muito complexa. Use o modelo da loja.'); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += (int) $zip->statIndex($i)['size'];
                if ($size > 20 * 1024 * 1024) { throw new InvalidArgumentException('Planilha descompactada excede 20 MB.'); }
            }
        } finally { $zip->close(); }
        $reader = new Xlsx();
        $info = $reader->listWorksheetInfo($path);
        if (!$info || $info[0]['totalRows'] > self::MAX_ROWS + 1 || $info[0]['totalColumns'] > 20) {
            throw new InvalidArgumentException('Use no máximo 500 produtos e 20 colunas na primeira aba.');
        }
        $reader->setLoadSheetsOnly($info[0]['worksheetName']);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            $rows = $sheet->toArray(null, false, false, false);
            return self::normalize($rows);
        } finally { $book->disconnectWorksheets(); }
    }

    public static function normalize(array $rows): array
    {
        $headers = array_map(static fn($v) => mb_strtolower(trim((string) $v)), array_shift($rows) ?? []);
        if (!in_array('nome', $headers, true) || count(array_unique($headers)) !== count($headers)) {
            throw new InvalidArgumentException('A primeira linha deve conter os cabeçalhos do modelo, incluindo nome, sem colunas repetidas.');
        }
        if (array_diff($headers, self::HEADERS)) { throw new InvalidArgumentException('Coluna desconhecida. Use os cabeçalhos do modelo.'); }
        $products = []; $errors = []; $seen = [];
        foreach ($rows as $index => $values) {
            if (!array_filter($values, static fn($v) => trim((string) $v) !== '')) { continue; }
            if (count($products) >= self::MAX_ROWS) { throw new InvalidArgumentException('Limite de 500 produtos por importação.'); }
            $line = $index + 2;
            try {
                $data = [];
                foreach ($headers as $i => $key) {
                    $value = trim((string) ($values[$i] ?? ''));
                    if (str_starts_with($value, '=')) { throw new InvalidArgumentException('Substitua fórmulas por valores.'); }
                    // A exportacao poe um apostrofo antes de =, +, - e @ (contra fórmula no
                    // Excel); a reimportacao tira, para o texto voltar igual ao original.
                    if (preg_match("/^'[=+\\-@]/", $value) === 1) { $value = substr($value, 1); }
                    $data[$key] = $value;
                }
                $name = $data['nome'];
                if ($name === '' || mb_strlen($name) > 180) { throw new InvalidArgumentException('Nome obrigatório, com até 180 caracteres.'); }
                $slug = $data['slug'] ?? '';
                if ($slug === '') { $slug = slugify($name); }
                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 200) {
                    throw new InvalidArgumentException('Slug inválido. Use letras minúsculas, números e hífens.');
                }
                if (isset($seen[$slug])) { throw new InvalidArgumentException('Slug repetido nesta planilha: ' . $slug); }
                $seen[$slug] = true;
                $category = $data['categoria'] ?? '';
                if (mb_strlen($category) > 100 || ($category !== '' && slugify($category, 120) === '')) {
                    throw new InvalidArgumentException('Categoria inválida, use até 100 caracteres.');
                }
                $price = $data['preco'] ?? '';
                $cents = $price === '' ? null : price_to_cents($price);
                if ($price !== '' && ($cents === null || $cents > 99999999)) { throw new InvalidArgumentException('Preço inválido. Exemplo: 129,90.'); }
                $availability = $data['disponibilidade'] ?? '';
                if ($availability === '') { $availability = 'sob_encomenda'; }
                if (!in_array($availability, ['sob_encomenda', 'pronta_entrega'], true)) {
                    throw new InvalidArgumentException('Disponibilidade deve ser sob_encomenda ou pronta_entrega.');
                }
                foreach (['descricao_curta' => 255, 'descricao' => 5000] as $field => $limit) {
                    if (mb_strlen($data[$field] ?? '') > $limit) { throw new InvalidArgumentException("$field excede $limit caracteres."); }
                }
                $product = [
                    'name' => $name, 'slug' => $slug, 'category' => $category,
                    'price_cents' => $cents, 'price_on_request' => $cents === null ? 1 : 0,
                    'availability' => $availability, 'short_description' => $data['descricao_curta'] ?? '',
                    'description' => $data['descricao'] ?? '',
                    'active' => self::flag($data['ativo'] ?? '', 1),
                    'featured' => self::flag($data['destaque'] ?? '', 0),
                ];
                if (array_key_exists('estoque', $data)) {
                    $stock = $data['estoque'];
                    if ($stock !== '' && (!preg_match('/^\d{1,5}$/', $stock) || (int) $stock > 65535)) {
                        throw new InvalidArgumentException('Estoque deve ser um número inteiro, ou vazio para não controlar.');
                    }
                    $product['stock_qty'] = $stock === '' ? null : (int) $stock;
                }
                if (array_key_exists('mostrar_estoque', $data)) { $product['show_stock'] = self::flag($data['mostrar_estoque'], 0); }
                if (array_key_exists('esgotado', $data)) { $product['sold_out'] = self::flag($data['esgotado'], 0); }
                if (array_key_exists('preco_promocional', $data)) {
                    $sale = $data['preco_promocional'];
                    $saleCents = $sale === '' ? null : price_to_cents($sale);
                    if ($sale !== '' && $saleCents === null) { throw new InvalidArgumentException('Preço promocional inválido. Exemplo: 79,90.'); }
                    if ($saleCents !== null && $cents === null) { throw new InvalidArgumentException('Produto sem preço (consultar valor) não pode ter preço promocional.'); }
                    if ($saleCents !== null && $saleCents >= $cents) { throw new InvalidArgumentException('O preço promocional precisa ser menor que o preço.'); }
                    $product['sale_price_cents'] = $saleCents;
                }
                if (array_key_exists('promocao_ate', $data)) {
                    $ends = self::date($data['promocao_ate']);
                    if ($ends !== null && ($product['sale_price_cents'] ?? null) === null) { throw new InvalidArgumentException('promocao_ate só vale junto com preco_promocional.'); }
                    $product['sale_ends_on'] = $ends;
                }
                $products[] = $product;
            } catch (InvalidArgumentException $e) { $errors[] = "Linha $line: " . $e->getMessage(); }
        }
        if (!$products && !$errors) { $errors[] = 'A planilha não contém produtos.'; }
        return ['products' => $products, 'errors' => $errors];
    }

    private static function flag(string $value, int $default): int
    {
        if ($value === '') { return $default; }
        $value = mb_strtolower($value);
        if (in_array($value, ['1', 'sim'], true)) { return 1; }
        if (in_array($value, ['0', 'nao', 'não'], true)) { return 0; }
        throw new InvalidArgumentException('Ativo, destaque, mostrar_estoque e esgotado devem ser 1 (sim) ou 0 (não).');
    }

    /** Data da planilha: dia/mês/ano, ano-mês-dia ou número de data do Excel. */
    private static function date(string $value): ?string
    {
        if ($value === '') { return null; }
        if (preg_match('/^\d{5}(?:\.0+)?$/', $value)) {
            // Serial do Excel (dias desde 30/12/1899).
            $value = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }
        return parse_date_br($value) ?? throw new InvalidArgumentException('Data de promoção inválida. Use dia/mês/ano, por exemplo 30/11/2026.');
    }

    /**
     * Colunas de estoque e promocao presentes nesta importacao (iguais em todas as linhas).
     *
     * @return array<int,string>
     */
    public static function stockColumns(array $products): array
    {
        return array_values(array_filter(self::STOCK_HEADERS, static fn (string $col): bool => array_key_exists($col, $products[0] ?? [])));
    }

    public static function existing(array $products, bool $lock = false): array
    {
        $slugs = array_column($products, 'slug');
        if (!$slugs) { return []; }
        $extra = ProductRepository::stockReady() ? ', stock_qty, show_stock, sold_out, sale_price_cents, sale_ends_on' : '';
        $stmt = db()->prepare('SELECT slug, name, category_id, price_cents, price_on_request, availability, short_description, description, active, featured' . $extra . ', updated_at FROM products WHERE slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute($slugs);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) { $rows[$row['slug']] = hash('sha256', json_encode($row)); }
        ksort($rows);
        return $rows;
    }

    public static function apply(array $products, string $mode, array $expected): array
    {
        if (!$products || count($products) > self::MAX_ROWS || !in_array($mode, ['create', 'upsert'], true)) { throw new InvalidArgumentException('Importação inválida.'); }
        $extraCols = self::stockColumns($products);
        if ($extraCols !== [] && !ProductRepository::stockReady()) {
            throw new RuntimeException('As colunas de estoque e promoção precisam do banco atualizado. Remova essas colunas da planilha ou atualize o banco.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $existing = self::existing($products, true);
            if ($existing !== $expected) { throw new RuntimeException('O catálogo mudou após a prévia. Envie a planilha novamente.'); }
            if ($mode === 'create' && $existing) { throw new RuntimeException('Há slugs já cadastrados. Use atualizar por slug ou corrija a planilha.'); }
            $categories = [];
            foreach ($pdo->query('SELECT id, slug FROM categories')->fetchAll() as $row) { $categories[$row['slug']] = (int) $row['id']; }
            $newCategories = [];
            foreach ($products as $product) {
                if ($product['category'] !== '') {
                    $slug = slugify($product['category'], 120);
                    if (!isset($categories[$slug])) { $newCategories[$slug] = $product['category']; }
                }
            }
            if ($newCategories) {
                $sql = 'INSERT INTO categories (slug, name) VALUES ' . implode(',', array_fill(0, count($newCategories), '(?,?)')) . ' ON DUPLICATE KEY UPDATE slug=VALUES(slug)';
                $values = [];
                foreach ($newCategories as $slug => $name) { array_push($values, $slug, $name); }
                $pdo->prepare($sql)->execute($values);
                foreach ($pdo->query('SELECT id, slug FROM categories')->fetchAll() as $row) { $categories[$row['slug']] = (int) $row['id']; }
            }
            foreach (array_chunk($products, 50) as $chunk) {
                // Colunas fixas + as de estoque/promocao que vieram na planilha (lista fechada).
                $cols = array_merge(['name', 'slug', 'category_id', 'price_cents', 'price_on_request', 'availability', 'short_description', 'description', 'active', 'featured'], $extraCols);
                $row = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $row));
                if ($mode === 'upsert') {
                    $updates = array_map(static fn (string $c): string => $c . '=VALUES(' . $c . ')', array_values(array_diff($cols, ['slug'])));
                    // Planilha antiga, sem preco_promocional: se o preco novo ficar abaixo
                    // da promocao atual, a promocao sai (o CHECK do banco recusaria).
                    // Vem antes de price_cents porque o MySQL aplica as atribuicoes em ordem.
                    if (!in_array('sale_price_cents', $extraCols, true) && ProductRepository::stockReady()) {
                        array_unshift($updates, 'sale_price_cents=IF(VALUES(price_cents) IS NULL OR sale_price_cents >= VALUES(price_cents), NULL, sale_price_cents)');
                    }
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
                }
                $values = [];
                foreach ($chunk as $p) {
                    array_push($values, $p['name'], $p['slug'], $categories[slugify($p['category'], 120)] ?? null, $p['price_cents'], $p['price_on_request'], $p['availability'], $p['short_description'], $p['description'], $p['active'], $p['featured']);
                    foreach ($extraCols as $col) { $values[] = $p[$col]; }
                }
                $pdo->prepare($sql)->execute($values);
            }
            $pdo->commit();
            return ['created' => count($products) - count($existing), 'updated' => count($existing)];
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}
