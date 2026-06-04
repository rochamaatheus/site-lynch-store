# Lynch Store — Wireframes da Vitrine

Apresentação dos **três wireframes** para a nova vitrine da **Lynch Store**
(moda geek & cosplay), desenvolvidos pela **Agência Kamino**.

> Nesta etapa o foco é validar a **estrutura, o tom e a sensação** de cada
> caminho. Os wireframes são propositalmente em **cinza** — paleta de cores
> (girassol + tons aconchegantes) e tipografia entram na próxima fase.

## 🔗 Ver online

Publicado via GitHub Pages:

**https://agenciakamino.github.io/site-lynch-store/**

A página inicial apresenta os três wireframes. Clique em cada card para abrir o
wireframe completo (com página de produto navegável).

## 📐 Os três wireframes

| # | Wireframe | Direção |
|---|-----------|---------|
| 01 | **Aconchego** — `site-1-aconchego.html` | Loja prática e fofa: banner full-bleed, filtros "prateleira", botão Comprar no card, produto utilitário (estoque/medidas/frete) |
| 02 | **Drop** — `site-2-drop.html` | Lúdico e mobile-first: boas-vindas com mascote, categorias lifestyle, produtos em scroll horizontal |
| 03 | **Orgânico** — `site-3-organico.html` | Editorial: tipografia de impacto, categorias na vertical, muito respiro |

> **A escolha é modular.** É possível escolher um como base e trazer elementos
> dos outros (banner, menu/filtros, card de produto, página de produto, etc).

## 🛠️ Stack

HTML estático monocromático. A capa (`index.html`) usa
[Tailwind CSS](https://tailwindcss.com/) via CDN; os wireframes são
single-file (CSS + JS inline, sem dependências). Sem build — basta abrir os
arquivos `.html` no navegador.

## 📂 Estrutura

```
.
├── index.html              # Capa — seletor dos três wireframes
├── site-1-aconchego.html   # Wireframe 01 · Aconchego (loja/prático)
├── site-2-drop.html        # Wireframe 02 · Drop (lúdico/mobile)
└── site-3-organico.html    # Wireframe 03 · Orgânico (editorial)
```

---

Vitrine digital — as vendas acontecem pelo WhatsApp.
Concepção & design · **Agência Kamino**
