# Lynch Store — Vitrine Digital

Site estático da **Lynch Store** (moda geek & cosplay), desenvolvido pela
**Agência Kamino**. Vitrine digital com fotos reais da loja — as vendas
acontecem pelo WhatsApp.

## 🔗 Ver online

Publicado via GitHub Pages:

**https://rochamaatheus.github.io/site-lynch-store/**

## 🛠️ Stack

HTML estático single-file. Usa [Tailwind CSS](https://tailwindcss.com/) via
CDN; sem build — basta abrir `index.html` no navegador.

## 📂 Estrutura

```
.
├── index.html               # Site da Lynch Store
├── assets/
│   ├── lynch-logo.webp      # Logo (favicon + header)
│   ├── seo-banner.webp      # Imagem de compartilhamento (og:image)
│   └── products/            # Fotos reais dos produtos (product-01..46.webp)
└── backups/
    └── 2026-07-07-escolha-cliente/
        # Arquivo histórico: os 3 mockups originais e a decisão da cliente
        # que definiu a direção visual usada em index.html. Não editar —
        # é registro, não parte ativa do site.
```

Todas as imagens do site ficam em **WebP** (menor tamanho, mesma qualidade
visual). Ao adicionar novas fotos de produto, converta para `.webp` antes de
colocar em `assets/products/`.

---

Vitrine digital — as vendas acontecem pelo WhatsApp.
Concepção & design · **Agência Kamino**
