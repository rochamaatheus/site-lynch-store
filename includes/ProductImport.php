<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class ProductImport
{
    public const HEADERS = ['nome', 'slug', 'categoria', 'preco', 'disponibilidade', 'descricao_curta', 'descricao', 'ativo', 'destaque'];
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
                $products[] = [
                    'name' => $name, 'slug' => $slug, 'category' => $category,
                    'price_cents' => $cents, 'price_on_request' => $cents === null ? 1 : 0,
                    'availability' => $availability, 'short_description' => $data['descricao_curta'] ?? '',
                    'description' => $data['descricao'] ?? '',
                    'active' => self::flag($data['ativo'] ?? '', 1),
                    'featured' => self::flag($data['destaque'] ?? '', 0),
                ];
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
        throw new InvalidArgumentException('Ativo e destaque devem ser 1 ou 0.');
    }

    public static function existing(array $products, bool $lock = false): array
    {
        $slugs = array_column($products, 'slug');
        if (!$slugs) { return []; }
        $stmt = db()->prepare('SELECT slug, name, category_id, price_cents, price_on_request, availability, short_description, description, active, featured, updated_at FROM products WHERE slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute($slugs);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) { $rows[$row['slug']] = hash('sha256', json_encode($row)); }
        ksort($rows);
        return $rows;
    }

    public static function apply(array $products, string $mode, array $expected): array
    {
        if (!$products || count($products) > self::MAX_ROWS || !in_array($mode, ['create', 'upsert'], true)) { throw new InvalidArgumentException('Importação inválida.'); }
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
                $sql = 'INSERT INTO products (name,slug,category_id,price_cents,price_on_request,availability,short_description,description,active,featured) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
                if ($mode === 'upsert') {
                    $sql .= ' ON DUPLICATE KEY UPDATE name=VALUES(name),category_id=VALUES(category_id),price_cents=VALUES(price_cents),price_on_request=VALUES(price_on_request),availability=VALUES(availability),short_description=VALUES(short_description),description=VALUES(description),active=VALUES(active),featured=VALUES(featured)';
                }
                $values = [];
                foreach ($chunk as $p) {
                    array_push($values, $p['name'], $p['slug'], $categories[slugify($p['category'], 120)] ?? null, $p['price_cents'], $p['price_on_request'], $p['availability'], $p['short_description'], $p['description'], $p['active'], $p['featured']);
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
