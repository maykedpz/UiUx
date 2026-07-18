# Honestly Market — WordPress theme (Phase 1: look & feel)

A block (FSE) theme for a South African multivendor marketplace built on
one rule: **the price you see is the price you pay.** No fake "was"
prices, no countdown gimmicks, no auctions.

"Honestly Market" is a **placeholder brand name** — swap it before
launch (see "Renaming the brand" below).

## What's built in this phase

Per the brief, Phase 1 is scoped to look and feel only:

- **Sticky top bar** — logo, search, cart, account. Nothing else. It's a
  template part (`parts/header.html`) so it's reused on every page.
- **Hero section with a category sidebar** — a block pattern
  (`patterns/hero-category-marketplace.php`) with the confirmed
  taxonomy as a static list, headline, subhead, CTAs, and a small
  "receipt" card stating the no-fake-discount / no-auction / real
  South African sellers promise.
- **Design tokens in `theme.json`** — palette, type scale, spacing —
  so the Editor and every block inherit the same system instead of
  ad-hoc CSS. Dark mode is handled by re-pointing the same CSS custom
  properties under `prefers-color-scheme: dark` in `assets/css/theme.css`.

### Design system

| Token | Light | Dark | Use |
|---|---|---|---|
| `ink` | `#14171C` | `#EDEAE0` | text |
| `paper` | `#E9E7DE` | `#14171C` | page background |
| `surface` | `#DFDCCF` | `#1D2129` | cards, search field |
| `seal` | `#C68A2E` | `#E0A94A` | accent (buttons, hover, badges) |
| `ledger` | `#3C6E58` | `#5C9B80` | "real savings" / trust signal color |
| `rule` | `#B9B2A0` | `#3A3F49` | hairlines |

Type: an editorial serif (`Iowan Old Style`/`Palatino`/Georgia system
stack) for headings and the wordmark, a system sans for UI chrome, and
a monospace stack for prices, badges and the receipt card — a
deliberate nod to a till receipt, reinforcing "the price shown is the
price paid." No webfonts are loaded from a CDN, by design — one less
dependency and one less privacy/performance cost.

### AI-readiness implemented in this phase

- **Structured data**: sitewide `Organization` + `WebSite` (with a
  `SearchAction`) JSON-LD on the front page (`functions.php`).
  Per-product `Product`/`Offer` schema is deferred to Phase 2 — there's
  no real price/availability data to describe yet.
- **`/llms.txt`**: served dynamically at `honestlymarket.example/llms.txt`
  (rewrite rule in `functions.php`), so it always reflects the live
  site name/tagline instead of going stale like a static file would.
- **AI crawler access**: `robots.txt` explicitly allows `GPTBot`,
  `ChatGPT-User`, `PerplexityBot`, `ClaudeBot`, `Claude-User`, and
  `Google-Extended`, documented in one place so a future blanket
  `Disallow` doesn't silently take them out with it.
- **Codebase**: block-theme structure (`theme.json` + template parts +
  registered pattern) rather than a page-builder export, so the markup
  stays legible to both humans and AI coding tools.

On-site AI features (search, recommendations, chat) are **not** built
yet — they need real product data to be anything other than a mock, so
they're Phase 2.

## What's intentionally stubbed

- **Cart / Account links** (`/cart/`, `/my-account/`) point to the
  URLs WooCommerce will own once it's installed. They 404 until then.
- **Category list** is a static `<ul>` of the 15 confirmed taxonomy
  categories (see below), not a live taxonomy query — there's no
  `product_cat` data yet.
- **Footer** is a single line. Full footer (vendor CTA, policy links,
  payment method marks) comes with Phase 2 content.
- No `screenshot.png` yet — add a 1200×900 screenshot before treating
  this as launch-ready in `wp-admin`.

## Taxonomy (confirmed)

Electronics & Computers · Home, Kitchen & Appliances · Home
Improvement, Tools & Garden · Fashion · Beauty & Personal Care ·
Health & Wellness · Baby, Kids & Toys · Books, Media & Stationery ·
Sport & Outdoor · Groceries & Household · Pet Supplies · Auto Parts &
Accessories · Arts, Crafts & Hobbies · Luggage & Travel · Gift Cards &
Vouchers.

Excluded by design: alcohol, pornography, firearms, vehicle sales,
auctions.

## Confirmed feature decisions (for Phase 2 scoping)

- Multi-vendor price comparison: **in**.
- Loyalty/rewards program: **not yet**.
- Price-history graph / "verified discount" badge: **out** — the
  no-fake-discount guarantee is enforced as a seller/catalog policy,
  not a customer-facing badge.
- Pre-order / "notify me": **out**.
- Subscribe & Save / membership subscription: **out**.

## Installing / previewing

1. Copy `wp-content/themes/honestly-market` into your WordPress
   install's `wp-content/themes/` directory (this repo's
   `wp-content/themes/honestly-market` path mirrors that already).
2. Activate it under **Appearance → Themes**.
3. Set **Settings → General → Site Title** to your real brand name —
   the top bar wordmark and JSON-LD both read from it, nothing is
   hardcoded.
4. This phase has no dependency on WooCommerce or any plugin — it will
   activate and render on a stock WordPress install.

This repo doesn't include a running WordPress/MySQL stack, so the
theme hasn't been visually verified in a browser — only PHP-linted
(`php -l`) and JSON-validated. To actually see it rendered, run it
through a local WP environment such as `wp-env`, Local, or a Docker
`wordpress` + `mysql` compose stack, with this directory mounted as
the active theme.

## Renaming the brand

Search-and-replace `Honestly Market` / `honestly-market` across:
`style.css` header, `theme.json` (`title`), `functions.php` (text
domain, `const`/function prefixes if you want a different slug), and
copy in `parts/header.html`'s alt text if a logo image is added later.
The wordmark itself is pulled from **Settings → General → Site
Title**, so that part needs no code change.

## Phase 2 (backend) — not started

- WooCommerce install/configuration (products, cart, checkout,
  payments, orders).
- Custom multivendor plugin: vendor registration/dashboard, commission
  and payout logic, per-vendor storefronts, multi-seller cart/order
  splitting, seller product moderation.
- Wire the category rail to real `product_cat` terms.
- Per-product `Product`/`Offer` JSON-LD.
- AI search, AI recommendations, AI chat support — once there's real
  product data to work from.
- Enforcement mechanism for the no-fake-discount policy (e.g. seller
  agreement terms + internal review, since a public price-history
  feature was explicitly declined).
