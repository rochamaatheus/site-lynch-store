<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Seo.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
// Fora do Google: painel, sacola, favoritos, envio do pedido, instalador, a busca
// instantanea (JSON) e a busca da loja com termo. A loja sem termo (?busca=) fica
// liberada: a regra Allow com $ e mais especifica que o Disallow.
echo "User-agent: *\n"
    . "Allow: /\n"
    . "Disallow: /admin/\n"
    . "Disallow: /instalar.php\n"
    . "Disallow: /carrinho.php\n"
    . "Disallow: /favoritos.php\n"
    . "Disallow: /finalizar.php\n"
    . "Disallow: /busca.php\n"
    . "Allow: /loja.php?busca=$\n"
    . "Disallow: /loja.php?busca=\n"
    . "Disallow: /loja.php?*&busca=\n"
    . "Disallow: /includes/\n"
    . "Disallow: /db/\n"
    . "Disallow: /vendor/\n\n";
echo 'Sitemap: ' . seo_site_url() . "/sitemap.xml\n";
