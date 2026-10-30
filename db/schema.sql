-- ---------------------------------------------------------------------------
-- Lynch Store - schema MySQL / MariaDB
-- Fase 1 (Fundacao). Idempotente: pode ser reimportado sem apagar dados.
--
-- Uso local (XAMPP):
--   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS lynchstore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   C:\xampp\mysql\bin\mysql.exe -u root lynchstore < db\schema.sql
--
-- Em cPanel: criar o banco pelo painel e importar este arquivo no phpMyAdmin.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- admin_users - usuarios do painel administrativo
-- Senha sempre em password_hash() (bcrypt/argon2); nunca texto puro.
-- failed_attempts/locked_until servem ao throttling simples de login (fase 5).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) DEFAULT NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME     DEFAULT NULL,
    last_login_at   DATETIME     DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- categories - Cosplay, Fantasia, Camisetas, Novidades (seed na fase 2)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products
-- price_cents: preco em CENTAVOS (int), nunca float. NULL quando price_on_request=1.
-- price_on_request=1 -> vitrine mostra "Consultar valor" (comportamento atual do site).
-- active=0 -> soft-delete: some do catalogo publico, continua no admin.
-- category_id ON DELETE SET NULL: apagar categoria nao apaga produto.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id      INT UNSIGNED DEFAULT NULL,
    name             VARCHAR(180) NOT NULL,
    slug             VARCHAR(200) NOT NULL,
    short_description VARCHAR(255) DEFAULT NULL,
    description      TEXT DEFAULT NULL,
    price_cents      INT UNSIGNED DEFAULT NULL,
    price_on_request TINYINT(1) NOT NULL DEFAULT 1,
    availability     ENUM('sob_encomenda','pronta_entrega') NOT NULL DEFAULT 'sob_encomenda',
    active           TINYINT(1) NOT NULL DEFAULT 1,
    featured         TINYINT(1) NOT NULL DEFAULT 0,
    sort_order       INT NOT NULL DEFAULT 0,
    -- Estoque e promocao (painel: acoes rapidas na lista de produtos)
    -- stock_qty NULL = sem controle de estoque (comum em sob encomenda); 0 = esgotado.
    -- show_stock: mostra "Restam N" na loja. sold_out: marcado como esgotado/vendido
    -- pela Ray (continua visivel, mas nao pode ir para a sacola).
    -- sale_price_cents: preco promocional em centavos; NULL = sem promocao.
    -- sale_ends_on: ultimo dia da promocao (inclusive); NULL = sem data final.
    stock_qty        SMALLINT UNSIGNED DEFAULT NULL,
    show_stock       TINYINT(1) NOT NULL DEFAULT 0,
    sold_out         TINYINT(1) NOT NULL DEFAULT 0,
    sale_price_cents INT UNSIGNED DEFAULT NULL,
    sale_ends_on     DATE DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_category (category_id),
    KEY idx_products_active_sort (active, sort_order),
    KEY idx_products_availability (availability),
    -- Promocao so com preco fechado e sempre abaixo do preco normal.
    CONSTRAINT chk_products_sale_price
        CHECK (sale_price_cents IS NULL OR (price_cents IS NOT NULL AND sale_price_cents < price_cents)),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- product_images
-- file_path: caminho relativo a raiz do site (ex: uploads/products/12/a1b2c3.webp).
--   E o derivado "card" (720x900). Os outros dois derivados vivem ao lado, com o
--   mesmo nome base + sufixo (-thumb.webp 240x300, -detail.webp 1080x1350), entao
--   nao precisam de coluna: functions.php deriva o nome.
-- source_path: o original normalizado (-src.webp, lado maior <= 2000px). Guardado
--   para permitir mudar o ponto de recorte depois SEM pedir reupload. NULL = foto
--   legada, subida antes do pipeline de imagem.
-- focal_y: 0-100, onde fica o centro de interesse na vertical (15 = vies pro topo,
--   padrao de foto de roupa em cabide/manequim; 50 = centro; 85 = base).
-- is_primary: capa. So UMA por produto - garantido em codigo, dentro de transacao
-- (MySQL nao tem indice unico parcial; unique (product_id, is_primary) quebraria
-- as imagens nao-capa, que sao varias com is_primary=0).
-- ON DELETE CASCADE: apagar produto apaga os registros das imagens.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    source_path VARCHAR(255) DEFAULT NULL,
    focal_y     TINYINT UNSIGNED NOT NULL DEFAULT 15,
    alt_text    VARCHAR(255) DEFAULT NULL,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_images_product_sort (product_id, sort_order),
    KEY idx_product_images_primary (product_id, is_primary),
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- site_settings - chave/valor livre (whatsapp_number, textos do hero, banner...)
-- Existe justamente para NAO precisar de migration a cada texto novo editavel.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(64) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- checkout_logs - snapshot do carrinho no clique de "Finalizar pedido".
-- Historico de referencia para a Ray; NAO e sistema de pedidos (nao ha status,
-- pagamento nem cliente cadastrado - a conversa acontece toda no WhatsApp).
-- cart_json em LONGTEXT (e nao JSON) por compatibilidade com MariaDB/MySQL 5.6+
-- em hospedagem compartilhada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS checkout_logs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_json       LONGTEXT NOT NULL,
    item_count      INT UNSIGNED NOT NULL DEFAULT 0,
    total_cents     INT UNSIGNED NOT NULL DEFAULT 0,
    has_price_on_request TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_number VARCHAR(20) DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    status          ENUM('novo','em_atendimento','concluido','cancelado') NOT NULL DEFAULT 'novo',
    notes           VARCHAR(2000) NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_checkout_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Banco que JA existia antes do pipeline de imagem (ago/2026)
-- Este arquivo usa CREATE TABLE IF NOT EXISTS, entao reimportar NAO adiciona
-- coluna em tabela ja criada. Numa instalacao antiga, rodar uma vez:
--
--   ALTER TABLE product_images
--     ADD COLUMN source_path VARCHAR(255) DEFAULT NULL AFTER file_path,
--     ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER source_path;
--
-- e depois `php db/reprocess-images.php` para gerar os derivados das fotos ja
-- cadastradas. Instalacao nova nao precisa de nada disso.
-- ---------------------------------------------------------------------------
