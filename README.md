# Lynch Store

Loja em PHP 8.3+ e MySQL/MariaDB, com catálogo, carrinho, checkout pelo WhatsApp e painel administrativo. Páginas renderizadas no servidor com includes, repositórios PDO e consultas parametrizadas. CSS e JavaScript pequenos cuidam da apresentação e das interações. Não há aplicação Node nem compilação de frontend na hospedagem. Esta é a versão 2.0; a versão 1 está em `_old/` (local, fora do Git) e no histórico do Git.

## Publicar na Hostinger

O pacote pronto fica em dist/<build>/lynch-hostinger.zip, acompanhado de LEIA-ME-INSTALACAO.txt com a chave individual de instalação e manifest.json com SHA-256.

1. No hPanel, configure PHP 8.3 ou superior. Ative pdo_mysql, mbstring, gd com WebP, fileinfo, zip, dom, xml, xmlreader, xmlwriter e simplexml. Recomenda-se memory_limit=256M, upload_max_filesize=12M e post_max_size=16M.
2. Crie um banco MySQL vazio e um usuário com acesso a esse banco. Ative HTTPS no domínio.
3. Extraia o conteúdo da pasta public_html/ do ZIP dentro do public_html do domínio, incluindo .htaccess. Não crie public_html/public_html.
4. Abra https://seu-dominio/instalar.php. Informe a chave do arquivo LEIA-ME, domínio, banco e novo login/senha do painel.
5. Após instalar, o instalador fica bloqueado. Acesse /admin/login.php.
6. Em Configurações, preencha WhatsApp, contatos e condições reais da loja. Revise nomes, preços e disponibilidade dos produtos.
7. Teste catálogo, pesquisa, carrinho e envio manual da mensagem. Confira /robots.txt e /sitemap.xml; cadastre o sitemap no Search Console.

Não precisa executar Composer, npm, Node ou comandos SSH na Hostinger. O ZIP já contém as bibliotecas PHP. As credenciais locais, usuários, senhas, pedidos e arquivos de teste não entram no pacote. A senha do primeiro admin é definida durante a instalação.

O WhatsApp e o domínio definitivo ficaram para preenchimento posterior por decisão do usuário. Os 46 produtos do acervo original ainda têm nomes provisórios e preços sob consulta. Nenhuma integração de pagamento foi contratada: o atendimento confirma disponibilidade, pagamento e frete no WhatsApp.

## Gerar novos builds

A pasta vendor não fica no projeto. Rode o composer antes de cada build (é ele que inclui as bibliotecas no ZIP e habilita a importação XLSX local):

~~~powershell
composer install --no-dev --prefer-dist --optimize-autoloader
php scripts/build.php
~~~

Ou execute scripts/build.ps1. O script lê o banco configurado no .env e inclui apenas o catálogo, configurações públicas e as imagens referenciadas. Não altera o banco.

Use **php scripts/build.php --empty** para gerar uma instalação sem catálogo. Cada execução cria outra pasta em dist, preservando os pacotes anteriores. Guarde o LEIA-ME fora do diretório público.

O pacote é destinado a **instalação nova em banco vazio**. Para atualizar uma loja existente, preserve .env e uploads, faça backup de arquivos e banco, valide a atualização em cópia e aplique apenas depois dessa validação. Não execute o instalador sobre uma loja existente.

## Painel

- Produtos: criar, editar, pesquisar, paginar, tirar da loja e voltar para a loja.
- Estoque e promoção direto na lista: estoque com + e −, Destaque, Mostrar estoque, Marcar como esgotado/Reativar venda e Promoção (preço ou %, data final opcional), sem abrir o formulário. Abas com contagem: Todos, Em promoção, Em destaque, Estoque baixo, Esgotados, Estoque visível e oculto. O limite de estoque baixo fica em Configurações.
- Esgotado continua visível na loja com selo, mas o servidor recusa a peça na sacola e no envio do pedido. Estoque em 0 deixa o produto esgotado sozinho; estoque vazio significa sem controle. O envio do pedido não baixa estoque: a loja ajusta quando a venda se confirma.
- Fotos: upload validado por conteúdo, derivados WebP, capa, ordenação e ajuste do ponto de recorte.
- Categorias: cadastro, edição, ativação e organização.
- Importar: CSV ou XLSX, prévia, criação ou atualização por slug, mensagens por linha e transação integral.
- Exportar: catálogo em CSV compatível com o importador; células que poderiam virar fórmulas são neutralizadas.
- Pedidos: snapshot do carrinho e situação de atendimento, com observações internas.
- Configurações: WhatsApp, textos, banner, contatos e condições de atendimento.
- Minha conta: troca de senha com verificação da senha atual e invalidação das outras sessões.

Um pedido registra o clique em finalizar. Não prova que a mensagem foi enviada nem que houve pagamento. O carrinho permanece salvo. Repetir o mesmo checkout em até 30 minutos reutiliza a referência, evitando duplicações acidentais.

## Importar uma planilha

Baixe o modelo em Importar ou exporte o catálogo existente. Máximo de 500 produtos e 3 MB por arquivo. No XLSX, usamos a primeira aba; não aceitamos fórmulas. O limite descompactado é 20 MB.

| Coluna | Uso |
|---|---|
| nome | Obrigatório, até 180 caracteres |
| slug | Identificador estável; se vazio, gerado pelo nome |
| categoria | Nome; categoria nova é criada automaticamente |
| preco | 129,90, 129.90 ou vazio para consultar valor |
| disponibilidade | pronta_entrega ou sob_encomenda |
| descricao_curta | Até 255 caracteres |
| descricao | Até 5.000 caracteres |
| ativo | 1 ou 0; padrão 1 |
| destaque | 1 ou 0; padrão 0 |
| estoque | Opcional. Quantidade; vazio para não controlar |
| mostrar_estoque | Opcional. 1 mostra "Restam N" na loja |
| preco_promocional | Opcional. Menor que o preço; vazio sem promoção |
| promocao_ate | Opcional. Último dia da promoção, 30/11/2026 |
| esgotado | Opcional. 1 marca como esgotado |

Planilhas antigas, sem as colunas opcionais, continuam importando e não mexem em estoque e promoção.

Ao atualizar por slug, a planilha substitui esses campos, inclusive vazios. Fotos e ordem da vitrine são preservadas. Confira a prévia antes de confirmar. Fotos são enviadas na tela de cada produto. A importação não busca URLs externas.

## Desenvolvimento e verificação

O banco original local lynchstore foi preservado. A validação usa o clone lynchstore_teste_completion, configurado em .env.test.local.

~~~powershell
php scripts/dev.php
~~~

Abre o servidor em http://localhost:8000 com bloqueio das pastas internas. Para preparar outro ambiente, leia STATUS.json antes de agir; scripts/prepare-test.php cria o clone apenas se ele ainda não existir.

~~~powershell
php tests/run.php
npm ci
node tests/browser.cjs
node tests/lighthouse.cjs
~~~

Node é usado apenas no QA local. Os testes de importação exigem `composer install`. Os testes de navegador usam Chrome instalado e credenciais de teste geradas por tests/run.php. Não execute contra produção. Dados criados nos testes permanecem no banco de teste para auditoria.

O ensaio de instalação usa tests/prepare-install.php, Apache isolado na porta 8082 e PHP FastCGI na porta 9074. Configuração, diretórios e bancos exatos constam no STATUS.json. O ajuste de caminho FastCGI é exclusivo do Windows, não vai no ZIP.

## Estrutura

- `_old/`: versão 1 completa, sem vendor, node_modules, .env e uploads.
- Arquivos PHP na raiz: rotas públicas (index = vitrine, loja = catálogo, produto, carrinho = sacola, favoritos, busca = sugestões em JSON).
- includes/: configuração, autenticação, repositórios, imagens, importação e partials.
- admin/: rotas protegidas do painel.
- assets/: CSS, JavaScript, fontes locais e imagens da marca.
- uploads/: fotos do catálogo, sem execução de PHP.
- db/schema.sql: schema de instalação nova.
- db/upgrade.php: evolução do schema legado (idempotente; `--rollback` desfaz estoque e promoção em banco de teste). Em banco real exige `--aplicar-em=<banco> --backup=<dump.sql>`, depois de validado em cópia.
- scripts/: build, ambiente local e otimização de assets.
- tests/: testes de PHP, navegador, instalação e Lighthouse.
- docs/: evidências de validação.

## Desempenho e SEO

Fontes locais, imagens responsivas WebP, carregamento tardio fora da primeira tela, compressão e cache de assets. Catálogo e admin usam paginação por cursor. O carrinho carrega todos os produtos em uma consulta.

Titles, descriptions, canonical, Open Graph, dados estruturados com escape seguro, sitemap dinâmico, robots e páginas de erro reais. Admin, carrinho e buscas internas ficam fora da indexação. Ofertas sem preço não inventam valores nos dados estruturados.

As notas reais e o ambiente medido estão em docs/QA.md. O Lighthouse não garante posicionamento no Google; a hospedagem, conteúdo comercial, domínio e condições de rede precisam ser verificados depois da publicação.

## Prévia do GitHub

Os geradores da prévia estática da versão 1 foram guardados em `_old/scripts`. A prévia publicada em https://rochamaatheus.github.io/site-lynch-store ainda mostra a versão 1.

Referências usadas: [configuração PHP da Hostinger](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/), [leitura segura de planilhas](https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-files/), [variação das medições Lighthouse](https://github.com/GoogleChrome/lighthouse/blob/main/docs/variability.md).
