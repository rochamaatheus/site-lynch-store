# Prompt para o Opus 5.5: motion da Lynch Store

Copie tudo abaixo da linha e envie como primeira mensagem de uma sessão nova aberta em
`C:\Users\Matheus\Documents\Projetos\Trabalho\clientes\ray`.

---

Você vai cuidar do **motion** (animações e microinterações) da loja Lynch Store. Antes de
qualquer coisa, leia `CLAUDE.md` dos diretórios pai, `STATUS.json`, `MELHORIAS-STATUS.json` e
`docs/DESIGN.md`. Não use agentes em paralelo escrevendo no mesmo arquivo. Não faça commit nem push.

## 1. O projeto em uma frase

Loja de moda geek, cosplay e brechó, em PHP 8.3 procedural + MySQL, sem build de frontend (CSS e
JavaScript puros, servidos como estão), que vai para a Hostinger. O comprador usa quase sempre o
celular (Instagram e WhatsApp, rede 4G). Não existe pagamento online: o carrinho termina numa
mensagem de WhatsApp para a dona da loja. A dona (Ray, não técnica) usa o painel em `/admin`.

## 2. Restrições técnicas que não mudam

- Arquivos que você pode editar: `assets/css/style.css`, `assets/js/main.js`, `assets/js/cart.js`,
  `assets/js/gallery.js` e, só se necessário, `assets/css/admin.css`. Markup (PHP) apenas para
  acrescentar classes ou atributos `data-*` em `includes/partials/header.php`,
  `includes/partials/product-card.php`, `carrinho.php`, `produto.php` e `index.php`. Não mude lógica
  PHP, nomes de parâmetros de URL, rotas, SQL nem textos.
- A política de segurança (CSP) é `script-src 'self'`: **nenhum `<script>` inline e nenhum
  `onclick=` ou similar**. Todo JS vai em `assets/js/*.js`. Qualquer violação de CSP no console
  reprova o trabalho.
- Progressive enhancement é regra: o site inteiro (catálogo, filtros, carrinho, finalizar) tem que
  funcionar com JavaScript desligado. Motion é camada por cima, nunca requisito.
- Identidade: papel/kraft (`--paper`, `--cream`, `--kraft`), girassol (`--sun`), ferrugem
  (`--rust`), tinta (`--ink`), Inter. Tom artesanal e acolhedor. Movimento sutil, com cara de
  loja de rua bem cuidada, nada de "site de agência".
- Mobile primeiro. Teste sempre em 390x844 e 320x640 antes do desktop.

## 3. Princípios (o que é um bom motion aqui)

1. Movimento existe para **dar feedback** (a ação foi recebida), **orientar** (de onde veio, para
   onde foi) ou **hierarquizar**. Se não cumpre um dos três, não entra.
2. Só anime `transform` e `opacity`. Proibido animar `width`, `height`, `top/left`, `margin`,
   `max-height` grande, `background-size`, `backdrop-filter`, `filter` ou `box-shadow` com blur
   grande. Quando precisar do efeito de sombra, anime a `opacity` de um pseudo-elemento com a sombra
   já pintada.
3. Duração e easing vêm de **tokens** em `:root`. Sugestão: `--dur-instant:.12s`, `--dur-fast:.16s`,
   `--dur-base:.22s`, `--dur-slow:.32s`, `--ease-out:cubic-bezier(.22,1,.36,1)`,
   `--ease-standard:ease`. Nunca `transition: .22s` (que vira `all`): sempre propriedades
   explícitas.
4. Nada em loop e nenhum parallax ou animação ligada ao scroll. Preço, subtotal, total e
   quantidades nunca fazem tween (count-up): o valor tem que ser exato e imediato.
5. `prefers-reduced-motion: reduce` desliga tudo que se move, **inclusive** os deslocamentos de
   hover e o `:active` com `scale`, e qualquer `animate()` ou `scrollIntoView` em JS.
6. Hover só em `@media (hover:hover) and (pointer:fine)`. No toque, o estado `:active` e
   `:focus-visible` cuidam do feedback. Foco de teclado precisa ter o mesmo destaque que o hover.
7. Não pode piorar o LCP, o CLS (meta: 0) nem a nota de performance mobile da home (hoje 99).
   A imagem do hero que pode ser LCP (`.hero-visual img:nth-child(1)` e `:nth-child(2)`) nunca
   espera animação para aparecer.

## 4. Estado atual do motion (inventário já feito)

Releia o CSS antes de editar, porque os números de linha mudaram. O que existe hoje:

- `style.css`: `@view-transition` e `::view-transition-*` (fade de página), `.flash` com
  `feedback-in`, `.btn:active` com `scale(.97)`, `scroll-behavior:smooth`, `.cta-badge` (seta que
  desliza), `header` e `.head` (encolhem ao rolar, classe `.on` via IntersectionObserver em
  `main.js`), `.pic` e `.card` (lift no hover), `.collection` (lift e zoom no hover), `.thumbs`,
  `.faq` (abre com `max-height`), `.drawer` e `.drawer-panel` (menu mobile desliza).
- `gallery.js`: fade de 180 ms ao trocar a foto principal (já respeita reduced motion).
- `cart.js`: debounce de 550 ms e envio do formulário, **sem nenhum estado visual de pendência**.

### Problemas confirmados na auditoria (corrija todos)

| Id | Problema | Direção da correção |
|---|---|---|
| MO-05 | `@view-transition{navigation:auto}` está aninhado dentro de `@media(prefers-reduced-motion:no-preference)`, onde a regra é inválida e o navegador a ignora. O fade de página provavelmente nunca roda | Mover `@view-transition` para o topo da folha, fora de qualquer `@media`. Manter `::view-transition-old/new` dentro do bloco `no-preference`. Confirme no Chrome (DevTools, regra riscada ou não) |
| MO-01 | `backdrop-filter` está dentro do `transition` do `header` e muda de 18 px para 28 px de blur ao rolar | Blur fixo (ou só opacidade de um `::before` com o blur). Nunca animar `backdrop-filter` |
| MO-02 | `.head`, `.mark`, `.icon-btn` e `.logo small` animam `height`, `width` e `max-height` (empurra o conteúdo ~14 px ao rolar) | Manter dimensões fixas e reduzir só o conteúdo com `transform: scale`, ou aceitar troca sem transição. Os botões `.icon-btn` agora têm **44 px fixos** (alvo de toque): não reduza abaixo disso |
| MO-13 | Sem tokens; pelo menos 9 durações diferentes e 4 easings | Criar tokens (item 3.3) e trocar todos os usos |
| MO-06 | `.btn` não tem `transition`, mas tem `:hover` com `translateY(-1px)` e `:active` com `scale` | `transition: transform var(--dur-fast) var(--ease-out), background-color ..., border-color ..., color ...` |
| MO-07 | `.collection` anima `background-size` (repinta a foto inteira) | Foto num `::before` com `inset:0` e `transform: scale(1.06)` |
| MO-08 | `box-shadow` grande animado em `.pic` e `.collection` | Sombra fixa leve; no hover anime `opacity` de um `::after` com a sombra maior |
| MO-09 | `drop-shadow(0 22px 26px)` em 4 imagens do hero | Sombra de elipse estática em pseudo-elemento, ou assar a sombra nos WebP. Mantém `rotate` (é transform) |
| MO-10 | `filter: grayscale+contrast` e dois `mix-blend-mode` na foto da faixa "brechó" | Aplicar só em `(min-width:900px) and (hover:hover)`, ou pré-processar o duotone em WebP |
| MO-14 | Reduced motion não cancela os `transform` de hover nem o `:active` | Zerar os transforms no mesmo bloco `reduce` |
| MO-16 | FAQ: abre animando `max-height` (teto 360 px) mas fecha seco | `interpolate-size` / `::details-content` com fallback sem animação |
| MO-17 | `border-radius` na `transition` de `.pic` não faz nada | Remover |
| MO-18 | `transition:.22s` (all) em `.drawer` e `.buy` | Propriedades explícitas |
| MO-12 | `gallery.js` zoomMove chama `getBoundingClientRect` a cada mousemove | Guardar o rect no `mouseenter`, atualizar no `resize`, escrever as variáveis dentro de `requestAnimationFrame` |
| MO-15 | Indicador do filtro (`.check i::after`) e `.filter-link` mudam sem transição | `opacity` e `background-color` em `--dur-fast` |

Já resolvido por mim (não refaça): `[hidden]{display:none!important}` global; lifts de hover do
`.btn`, `.card`, `.collection` e `.thumbs` já estão dentro de `@media(hover:hover)`;
`.card:focus-within .pic` faz o lift por teclado; `.search:focus-within` tem anel de foco; `.card`
agora é uma coluna flex (`.card-price` com `margin-top:auto`); alvos de toque mínimos de 44 px.

### Interações sem feedback (adicione, na ordem)

1. **Adicionar ao carrinho** (`.buy` no card, botão principal em `produto.php`): estado pendente no
   clique (opacity .7 e texto "Adicionando" só se não atrapalhar o layout), sem bloquear o submit.
   O feedback real hoje é o `.flash` depois do redirect.
2. **Contador do carrinho** no header (`#cart-count`, `.cart-count[data-count]`): um pulso único
   (`scale` 1 para 1.12 para 1, 220 ms) quando a página abre logo depois de um `.flash.success`.
   O servidor já marca o estado; use uma classe definida no PHP ou leia o flash no `main.js`.
3. **Stepper do carrinho** (`cart.js`): durante os 550 ms de debounce e o envio, a linha
   `.cart-row` ganha `.is-pending` (opacity .55, transição de 160 ms) para o subtotal antigo não
   parecer definitivo. Os botões `-` e `+` já são desativados nos limites; dê a eles um estado
   `:disabled` visível.
4. **Remover item e esvaziar**: fade-out de 180 ms (`opacity` e `translateY(8px)`) na `.cart-row`
   antes de enviar o formulário; sem JS ou com reduced motion, envia direto.
5. **Imagens dos cards** (lazy): fade de 240 ms quando a imagem dispara `load`, em cima do fundo
   kraft que já existe. Classe `.is-loaded` via `main.js`. Imagem já em cache não deve "piscar".
6. **Galeria do produto**: borda ativa da miniatura com transição de 160 ms; enquanto
   `[data-gallery][aria-busy=true]`, foto atual com `opacity:.5` também no mobile.
7. **Entrada do hero** na primeira carga: só nas imagens 3 e 4 (as `loading="lazy"`), `opacity` e
   `translateY(12px)`, 420 ms, com 90 ms de intervalo entre elas, só em `no-preference`. As
   imagens 1 e 2 ficam estáticas (podem ser o LCP).
8. **Filtros**: indicador de seleção com fade (MO-15) e, se o `@view-transition` funcionar, o
   crossfade de página já cobre a troca.
9. **Compartilhar**: fade de 160 ms no `[data-share-status]` ("copiado"). Baixa prioridade.
10. **Painel admin**: **nenhum movimento decorativo**. Apenas o estado de botão ocupado, que já
    existe em `assets/js/admin.js` (`data-busy`). Não mexa no admin além disso.

### Onde NÃO animar

Imagem LCP do hero; totais, subtotais, preços, quantidades; reflow do header; `backdrop-filter`;
sombras grandes; loops de qualquer tipo (inclusive pulso em botão de WhatsApp ou de finalizar);
parallax; trilho de coleções no mobile (nenhum transform no hover, para não brigar com o
scroll-snap); estados vazios; mensagens flash (a entrada atual basta, sem auto-dismiss animado);
links de navegação de texto (só cor).

## 5. Como rodar o ambiente e verificar

Banco de teste (os testes de navegador GRAVAM no banco, então nunca aponte para o banco `lynchstore`):

```powershell
# 1) MariaDB do XAMPP (se ainda não estiver na porta 3306)
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
# 2) servidor PHP apontando para o clone de teste (caminho ABSOLUTO do .env, senão /admin quebra)
$env:LYNCH_ENV_FILE='C:\Users\Matheus\Documents\Projetos\Trabalho\clientes\ray\.env.test.local'
php -S localhost:8000 scripts/serve.php
```

Verificações obrigatórias, uma vez só no final (não rode a suíte a cada edição):

1. `php tests/run.php` (36 verificações PHP) e `php -l` nos PHP que você tocar.
2. `node tests/browser.cjs` (17 cenários, inclui axe WCAG A/AA e overflow em 320, 390, 768 e 1440 px).
   O `node_modules` do projeto não está instalado: rode `npm ci` ou use `NODE_PATH` para um
   diretório com `playwright` e `axe-core`.
3. Console do navegador sem erro de JS e **sem violação de CSP**.
4. Teste manual com `prefers-reduced-motion: reduce` emulado (DevTools, Rendering): nada se move.
5. Performance no celular simulado (CPU 4x, 4G): rolar a home e a página de produto sem quedas de
   quadro visíveis; Lighthouse mobile da home continua com performance 99 ou mais e CLS 0.
6. Sem JavaScript (desligue no DevTools): catálogo, filtros, carrinho e finalizar continuam
   funcionando e nenhum botão "morto" aparece.

## 6. Como trabalhar e o que devolver

- Faça um plano curto antes de editar. Mantenha um registro de estado em disco
  (`MOTION-STATUS.json` ao lado de `STATUS.json`) com o que já foi feito, o que falta e como
  retomar, e atualize a cada passo.
- Código sem comentário desnecessário, sem abstração além do pedido, no estilo do arquivo
  existente. Não crie biblioteca nem dependência nova.
- Não escreva travessão (o caractere de pausa longa) em nenhum texto, comentário ou documento.
- Devolva: lista do que mudou por arquivo, o que ficou de fora e por quê, o resultado de cada
  verificação do item 5 (com o número, não "passou"), e qualquer ponto em que você discordou
  deste prompt, com a razão.
