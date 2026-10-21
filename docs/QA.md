# Verificação da entrega

Ambiente: Windows, PHP 8.4.23, MariaDB 10.4.32 e Apache 2.4.58 com FastCGI. Os testes ocorreram exclusivamente em bancos locais de teste. A hospedagem Hostinger ainda não foi configurada.

## Funcionalidade

- 36 verificações PHP aprovadas: valores monetários, CSRF, redirects, validação de planilhas, XLSX real, criação e atualização, concorrência entre prévia e confirmação, rollback de lotes, paginação de 110 produtos, carrinho em uma consulta e mensagem de checkout.
- 17 cenários de navegador aprovados: login, categorias, produto com foto, carrinho, importação CSV/XLSX, exportação, pedido, status, troca de senha, invalidação de sessão e logout.
- Nove páginas sem overflow em 320, 390, 768 e 1440 pixels. Análise axe WCAG A/AA sem violações nas nove páginas e nas três verificações adicionais de login, informações e produto inexistente.
- O checkout foi verificado pelo destino HTTP e pelo registro no banco. Nenhuma mensagem foi enviada a terceiros.
- Composer e npm sem vulnerabilidades conhecidas nas versões instaladas.

## Instalação e pacote

O ZIP foi extraído em outro diretório e instalado por formulário em banco vazio. O ensaio confirmou criação do catálogo e admin, bloqueio permanente do instalador, autenticação, importador e fotos.

O Apache confirmou bloqueio de .env, schema, snapshot do catálogo, bibliotecas, includes, partials e chave do instalador. Compressão gzip, cache de assets, robots e sitemap funcionaram. URL inexistente retorna 404; parâmetros malformados retornam 400.

O pacote contém 46 produtos, quatro categorias e 184 arquivos de imagem de produto. Não inclui senhas locais, administradores existentes, histórico de pedidos, testes ou node_modules. O novo login é criado na instalação.

O banco original manteve as contagens iniciais: 46 produtos, 46 imagens, quatro categorias, um admin, cinco configurações e cinco registros de checkout. Nenhuma escrita foi executada nele.

## Consultas e banco

O carrinho de oito produtos foi medido com uma consulta. Antes, cada produto exigia consulta individual e outra de imagens. O plano do lote usa range na chave primária, estimando oito linhas; a busca individual usava const, uma linha por consulta.

A paginação pública usa idx_products_active_sort com cobertura para a consulta representativa de cursor. O importador usa uq_products_slug com range; o histórico de pedidos usa PRIMARY com range, sem OFFSET. Os planos estão em explain.json.

Uma falha forçada no segundo lote de importação desfez integralmente o primeiro lote. A evolução de schema foi validada no clone e em instalação nova. O arquivo db/upgrade.php não é um atualizador de produção autorizado; atualização de uma loja existente continua exigindo backup e ensaio específico.

## Lighthouse

| Página | Performance | Acessibilidade | Boas práticas | SEO |
|---|---:|---:|---:|---:|
| Home | 99 | 100 | 100 | 100 |
| Produto | 100 | 100 | 100 | 100 |

Medição final após as transições: 22/09/2026, 23:29, horário de São Paulo. LCP aproximado de 2,1 segundos na home e 1,8 segundo no produto. A meta de 100 em performance da home não foi atingida; o resultado entregue é 99.

Relatórios completos e URLs medidas: lighthouse/home.html, lighthouse/produto.html e lighthouse/summary.json. Auditoria mobile padrão do Lighthouse, com throttling simulado, servida pelo Apache local e sem extensões.

As notas finais constam também no STATUS.json. Os relatórios não representam uma medição remota na Hostinger. O domínio publicado precisa ser medido depois de configurar HTTPS e o conteúdo comercial.

## O que depende da loja

- WhatsApp e domínio: o usuário decidiu preencher depois.
- Banco e credenciais da Hostinger: informados durante a instalação.
- Produtos, preços, textos comerciais, disponibilidade e condições de atendimento: revisão pela loja.
- Pagamento e frete: confirmados no atendimento pelo WhatsApp, conforme a decisão registrada do projeto.

O catálogo herdado ainda contém nomes provisórios. As notas de SEO não substituem essa revisão nem garantem posicionamento no Google.

## Rastreabilidade

Execução por um único agente, conforme a regra do usuário. A prévia estática é publicada em codex/ray-preview por um repositório isolado dentro de .cache. A árvore de trabalho original, as exclusões anteriores de backups e as alterações locais foram preservadas.

## Prévia para a Ray e transições

O índice de aprovação em /telas.html reúne sete entradas da loja e treze telas ou estados do painel. A exportação atual contém 97 páginas, incluindo 46 produtos. As telas administrativas são estáticas e não salvam alterações. O ensaio em tests/client-preview.cjs conferiu todos os links e recursos locais, ausência de credenciais, CSRF, POST e endereços locais, navegação, responsividade em 390 e 1440px e axe WCAG A/AA no índice, painel e edição. Resultado em docs/client-preview-tests.json. O detalhe de pedido usa conteúdo ilustrativo derivado do banco local de QA.

A atualização da prévia foi publicada no commit dfa5ca6c0acefcc6ba5157af6d37a422b3cee7c0. O workflow 36565026610 concluiu com sucesso. O ensaio online confirmou HTTP 200 nas 97 páginas, navegação pelo índice, detalhe de pedido, segunda página de produtos do painel e fluxo de compra demonstrativo no celular, sem recursos quebrados. Evidências em docs/live-client-preview-tests.json e docs/live-preview-tests.json.

Na etapa anterior, a exportação pública isolada foi verificada assim:

- 83 páginas geradas, incluindo 46 produtos e as combinações de coleções, disponibilidade e paginação.
- 187 destinos locais de links e recursos conferidos.
- Busca, adição, alteração de quantidade, persistência, remoção e finalização demonstrativa verificados no navegador.
- Seis telas em 320, 390, 768 e 1440px sem overflow; axe WCAG A/AA sem violações.
- Coleções e produtos navegáveis sem JavaScript.
- Troca de foto, aria-pressed e preferência de movimento reduzido verificadas.
- Nenhum erro JavaScript ou recurso HTTP quebrado durante os fluxos.
- Os 17 cenários da loja PHP foram repetidos após as mudanças de UI e passaram.

O teste revelou contraste insuficiente no contador do filtro selecionado no desktop, corrigido para branco opaco. O carrinho mantém agora o espaçamento lateral do container também no mobile.

O relatório está em preview-tests.json. A prévia é deliberadamente noindex, e seu carrinho não representa processamento real de pedidos. As notas Lighthouse acima se referem à loja PHP no Apache local.

Publicação confirmada em https://rochamaatheus.github.io/site-lynch-store/ pelo workflow 35810863163, commit 017e78a17f70421a1e5acf4ba09b02a54b9632e8. A conferência online validou HTTP 200 nas 83 páginas e o fluxo mobile de busca, produto, carrinho e finalização demonstrativa, sem erros JavaScript ou recursos quebrados. Evidências em live-preview-tests.json.

Pacote Hostinger atualizado: dist/20260922-232752/lynch-hostinger.zip, SHA-256 460329649da2c7d5c333e3d8cfae9d2ce1c5ac9beb643860e3fa24eb641d2025. As instruções e a chave exclusiva estão no LEIA-ME-INSTALACAO.txt dessa mesma pasta. Nenhuma credencial foi publicada.

O estado atual está em STATUS.json. A memória do Claude no caminho esperado não existia; ela não foi criada nem alterada. Os estados BUILD-STATUS.json e IMAGENS-STATUS.json são históricos anteriores, não a fonte atual de retomada.

## Rodada de melhorias (21/10/2026)

Auditoria por oito leituras independentes (segurança, deploy, fluxo da compradora, painel, CSS, motion, SEO e acessibilidade, banco) e correção dos achados confirmados.

- 36 verificações PHP e 17 cenários de navegador repetidos depois das correções: aprovados, incluindo axe WCAG A/AA sem violações e nenhuma violação da política de segurança.
- Verificações novas do painel (sessão expirada volta ao formulário de origem, envio acima do limite do servidor, campos preservados em erro, nome de categoria com aspas no confirm sem executar script, mensagem única de login, ping de sessão): aprovadas.
- Pacote final dist/20261021-095856: 1031 arquivos conferidos por hash, catálogo original preservado (46 produtos, 4 categorias), instalação em banco vazio pelo Apache com .htaccess aprovada, redirect http para https confirmado fora de localhost.
- Motion não foi alterado: o prompt para a próxima etapa está em docs/PROMPT-MOTION-OPUS.md.
