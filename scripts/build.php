<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/db.php';
$root = dirname(__DIR__);
if (!class_exists(ZipArchive::class) || !is_file($root . '/vendor/autoload.php')) { throw new RuntimeException('Execute composer install --no-dev antes do build.'); }
$version = date('Ymd-His');
$dir = $root . '/dist/' . $version;
if (!mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Não foi possível criar dist.'); }
$catalog = [];
$tables = [
    'categories' => 'id,name,slug,description,sort_order,active',
    'products' => 'id,category_id,name,slug,short_description,description,price_cents,price_on_request,availability,active,featured,sort_order',
    'product_images' => 'id,product_id,file_path,source_path,focal_y,alt_text,is_primary,sort_order',
    'site_settings' => 'setting_key,setting_value',
];
$uploads = ['uploads/.htaccess' => true, 'uploads/.gitkeep' => true, 'uploads/products/.gitkeep' => true];
if (!in_array('--empty', $argv, true)) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($tables as $table => $columns) { $catalog[$table] = $pdo->query("SELECT $columns FROM $table")->fetchAll(); }
        $publicSettings = ['whatsapp_number','hero_title','hero_subtitle','hero_cta_label','hero_chip','hero_banner_path','whatsapp_message_intro','footer_about','store_policies','contact_email','instagram_url'];
        $catalog['site_settings'] = array_values(array_filter($catalog['site_settings'], static fn($s) => in_array($s['setting_key'], $publicSettings, true)));
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    foreach ($catalog['product_images'] as $row) {
        foreach ([$row['file_path'], $row['source_path']] as $path) { if ($path) { $uploads[$path] = true; } }
        foreach (['-thumb', '-detail'] as $variant) {
            $path = preg_replace('/\.webp$/', $variant . '.webp', $row['file_path']);
            if (is_file($root . '/' . $path)) { $uploads[$path] = true; }
        }
    }
    foreach ($catalog['site_settings'] as $setting) {
        if ($setting['setting_key'] === 'hero_banner_path' && $setting['setting_value']) { $uploads[$setting['setting_value']] = true; }
    }
}
$zip = new ZipArchive();
$zipPath = $dir . '/lynch-hostinger.zip';
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Não foi possível criar ZIP.'); }
$manifest = [];
$add = static function (string $relative) use ($zip, $root, &$manifest): void {
    $relative = str_replace('\\', '/', $relative);
    $file = $root . '/' . $relative;
    if (!is_file($file) || is_link($file)) { return; }
    $zip->addFile($file, 'public_html/' . $relative);
    $manifest[$relative] = hash_file('sha256', $file);
};
foreach (['index.php','produto.php','carrinho.php','finalizar.php','informacoes.php','404.php','robots.php','sitemap.php','instalar.php','.htaccess','db/schema.sql'] as $file) { $add($file); }
foreach (['admin','includes','assets','vendor','uploads'] as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(?:^|/)(?:\.git|node_modules)(?:/|$)|\.(?:log|bak|zip)$~', $relative)) { continue; }
        if (str_starts_with($relative, 'uploads/') && !isset($uploads[$relative])) { continue; }
        $add($relative);
    }
}
if (!in_array('--empty', $argv, true)) {
    foreach ($catalog['product_images'] as $row) {
        if (!isset($manifest[$row['file_path']])) { throw new RuntimeException('Imagem ausente no build: ' . $row['file_path']); }
    }
}
$zip->addFromString('public_html/db/catalog.json', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$key = bin2hex(random_bytes(24));
$zip->addFromString('public_html/install-secret.php', "<?php\nreturn " . var_export(password_hash($key, PASSWORD_DEFAULT), true) . ";\n");
$zip->addEmptyDir('public_html/uploads/products');
$zip->close();
$instructions = "LYNCH STORE | INSTALAÇÃO NA HOSTINGER\n\n1. Configure PHP 8.3 ou superior no hPanel e ative as extensões listadas no README do projeto.\n2. Crie um banco MySQL VAZIO e seu usuário no hPanel. Guarde as credenciais.\n3. Ative HTTPS no domínio. Extraia o conteúdo de public_html/ deste ZIP no public_html do site.\n4. Abra https://SEU-DOMINIO/instalar.php e preencha o formulário.\n5. Chave de instalação deste pacote (não compartilhe):\n$key\n6. Crie uma senha nova para o painel. Nenhuma senha do ambiente local acompanha o pacote.\n7. Entre no painel, configure o WhatsApp e revise catálogo, preços, contatos e condições de atendimento.\n8. Abra sitemap.xml e robots.txt, teste no celular e faça um pedido de teste. Rode Lighthouse no domínio publicado.\n\nNão sobrescreva uma loja existente com este pacote de instalação. Para atualização, faça backup de arquivos e banco, valide em cópia e preserve .env e uploads.\nO catálogo acompanha este build, exceto quando gerado com --empty. Fotos e nomes atuais ainda precisam da aprovação comercial da loja.\nEste pacote não depende de Node, npm, Composer ou terminal na hospedagem. As dependências PHP já estão incluídas.\n";
file_put_contents($dir . '/LEIA-ME-INSTALACAO.txt', $instructions);
file_put_contents($dir . '/manifest.json', json_encode(['created' => date(DATE_ATOM), 'zip_sha256' => hash_file('sha256', $zipPath), 'files' => $manifest, 'catalog_counts' => array_map('count', $catalog)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Build concluído: $zipPath\nInstruções e chave: $dir/LEIA-ME-INSTALACAO.txt\n";
