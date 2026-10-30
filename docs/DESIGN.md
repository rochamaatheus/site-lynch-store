# Direção visual da Lynch Store 2.0

A paleta e o clima artesanal são os aprovados pela cliente. O conceito é a arara do ateliê: tudo vem do que já existe na loja (logo de girassol com tábua de madeira, peças em manequim, o lema "sob medida").

## Assinaturas

- **Arara:** a abertura é uma barra de madeira com peças em cabides. O arraste move a arara e as peças balançam com a velocidade do dedo (mola amortecida, `assets/js/loja.js`).
- **Etiqueta kraft com barbante:** indica pronta entrega ou sob encomenda em todo card e balança ao adicionar.
- **Sacola-girassol:** cada peça acende uma pétala. Ao adicionar, a foto voa até a flor.
- **Fita métrica:** divisor entre abertura e conteúdo, anda com a rolagem.
- **Foto em multiply:** o fundo branco das camisetas vira papel, unificando fotos de origens diferentes.

## Tokens

Tinta #171210, papel #F7EFE2, creme #FFF7EA, folha #F1E2C9, kraft #B98958, argila #D7A878, madeira #8D572A, cacau #4A2815, ferrugem #A64E2E, girassol #DDAE35, musgo #4B5D41. Títulos em Shrikhand, texto em Inter, ambas locais.

## Compra no celular

Barra inferior com Início, Loja, Buscar, Favoritos e Sacola. Sacola em gaveta sem recarregar a página, busca instantânea, filtros em trilho fixo, produto com galeria deslizante e botão de compra fixo no alcance do polegar. Tudo parte de links e formulários reais: sem JavaScript o fluxo completo continua funcionando.

## Movimento

Só `transform` e `opacity` nas animações principais. A foto do card se transforma na foto do produto (view transition entre páginas, como melhoria progressiva). `prefers-reduced-motion` desliga tudo. Texto nunca fica transparente durante animação de rolagem.

## Evitar

Rótulos em caixa alta acima de títulos, palavra destacada em cor no título, chamadas inventadas (avaliações, frete grátis, promoções) e cartões idênticos com sombra cinza. O painel `/admin` mantém o próprio estilo em `assets/css/admin.css`.
