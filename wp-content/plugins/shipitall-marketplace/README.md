# Shipitall Marketplace (Phase 2: backend, v0.1)

The custom, proprietary multivendor layer for Shipitall, built **on
top of WooCommerce** (per the confirmed architecture decision):
WooCommerce owns products, cart, checkout, payments, tax, shipping and
orders; this plugin owns everything vendor-specific — onboarding,
ownership, order splitting, commission, and payouts. It borrows the
concepts that make Dokan/WCFM/WC Vendors work (vendor role, store
entity, commission engine, payout ledger) without depending on any of
them — this is Shipitall's own code, free to extend however the
business needs later.

## Requires

- WordPress 6.6+
- WooCommerce (active) — the plugin shows an admin notice and no-ops
  if it isn't.
- The `shipitall` theme (or any theme) — this plugin has no theme
  dependency; it works headless of the theme via shortcodes.

## Data model

- **`shipitall_vendor`** role — granted to a user once their store is
  approved. Capabilities are scoped to their own products
  (`edit_products`/`edit_published_products`, no `edit_others_products`)
  and their own store post. No `manage_woocommerce` — vendors never see
  site-wide WooCommerce settings or other vendors' orders.
- **`shipitall_store`** CPT — one per vendor. Application status is the
  post status itself: `draft` = submitted, awaiting approval;
  `publish` = approved, live storefront (`/store/{slug}/`). Meta:
  `_shipitall_owner_id` (user ID), `_shipitall_commission_rate`
  (optional per-store override, falls back to the global default).
- **Products** — standard WooCommerce `product` posts, owned via
  `post_author`. `_shipitall_store_id` meta is a denormalised pointer
  to the vendor's store, set automatically on save.
- **`{$wpdb->prefix}shipitall_commissions`** — one row per vendor
  suborder: gross amount, rate, commission, payable amount, status
  (`pending`/`paid`). A real table rather than post meta because it
  needs `SUM()`/`GROUP BY` aggregate queries for payouts.
- **`{$wpdb->prefix}shipitall_payouts`** — one row per "I paid this
  vendor" action, recording the total and clearing the matching
  commission rows to `paid`.

## How checkout splitting works

A customer's cart can span multiple vendors but they still pay once,
through one WooCommerce order — this plugin never touches payment or
totals. On `woocommerce_checkout_order_processed`, order items are
grouped by the vendor that owns each product. For each vendor with
items in the order, an internal **suborder** (a second, linked
`shop_order`) is created containing only that vendor's line items, at
the price the customer actually paid — which is also what commission
is calculated against, in keeping with the "the price you see is the
price you pay" rule applying end-to-end, not just on the product page.
Items with no linked vendor (e.g. a future platform-owned listing)
stay on the parent order untouched.

Suborders use standard WooCommerce statuses (not a custom status) and
are tagged with `_shipitall_is_suborder`, `_shipitall_parent_order_id`,
and `_shipitall_vendor_id` meta. They don't currently sync status
changes back to the parent order (e.g. a vendor marking their suborder
"completed" doesn't roll up to the customer's order status) — that's
listed under Known gaps below.

## Shortcodes

- `[shipitall_become_a_vendor]` — application form for a logged-in
  user. Creates a `draft` store; nothing else happens until an admin
  approves it.
- `[shipitall_vendor_dashboard]` — for approved vendors: earnings
  overview, product list (deep-links to the wp-admin product editor
  for add/edit — see "Why wp-admin for products" below), order/suborder
  list, and a store name/description form.

Add these to a "Sell on Shipitall" page and a "Vendor Dashboard" page
respectively.

## Admin

- **Stores** (own admin menu, from the CPT) — list of applications and
  live stores. Draft ones get a row action and bulk action to
  "Approve vendor", which publishes the store and grants the
  `shipitall_vendor` role in one step.
- **Shipitall → Settings** — global default commission rate (%).
- **Shipitall → Payouts** — every vendor with a pending balance, with
  a "Mark paid" action. This does **not** move money — there's no
  bank/EFT/payment-provider integration here, deliberately: that's a
  real external integration, not something to fake. Pay the vendor via
  EFT (or however the business settles payouts) first, then mark it
  paid here to log it and clear their pending balance.

## Why wp-admin for the product editor

Vendors get `edit_products` capability and their own scoped view of
**Products** in wp-admin (`pre_get_posts` restricts the list to their
own) rather than a custom frontend product form. WooCommerce's product
editor already handles variations, images, categories, inventory, and
attributes correctly — rebuilding that on the frontend is a large,
separate project with its own edge cases (image uploads, variation
matrices) that would take far longer than it's worth for a v0.1. The
`admin_menu` restriction in `Shipitall_Roles` trims the rest of
wp-admin down (no Plugins, Themes, Users, Tools, Settings, or
WooCommerce Settings) so a vendor's wp-admin is effectively just "my
products."

## Known gaps / not built yet

- **Category taxonomy wiring** — the theme's category sidebar is still
  static; wiring it to live `product_cat` terms (and deciding whether
  vendors can create new terms or only pick existing ones) is next.
- **No-fake-discount enforcement** — there's no automated check that a
  vendor's "was" price is real. A public price-history/verified-badge
  feature was explicitly declined, so this needs a policy + review
  mechanism instead (seller agreement terms, spot-checks, or an
  internal-only audit tool) — not yet built.
- **Suborder ↔ parent order status sync** — vendors can't currently
  mark their suborder shipped/completed in a way that updates the
  customer-facing parent order or notifies the customer.
- **Payout batching by period** — payouts are triggered manually,
  per-vendor, on demand. No scheduled "pay everyone weekly" job yet.
- **Payout/bank details storage** — not built at all yet. When it is,
  bank account details need to be encrypted at rest and capability-
  gated, not just another post meta field.
- **Vendor suspension** — no admin action yet to suspend a vendor
  (unpublish their store + block new orders) short of manually editing
  the store's post status and removing the role.
- **AI search / recommendations / chat, per-product `Product`/`Offer`
  JSON-LD** — need real product data to build against; still pending
  from the Phase 1 AI-readiness scope.
- **Automated tests** — none yet. Given how much of this hinges on
  money changing hands (commission math, payout totals), this should
  not stay untested for long.

## Installing / previewing

1. Copy `wp-content/plugins/shipitall-marketplace` into your
   WordPress install's `wp-content/plugins/` directory.
2. Install and activate WooCommerce first, then activate this plugin.
3. Create a "Sell on Shipitall" page with `[shipitall_become_a_vendor]`
   and a "Vendor Dashboard" page with `[shipitall_vendor_dashboard]`.
4. Apply as a vendor with a test account, approve it from **Stores** in
   wp-admin, then create a product as that vendor and run a test
   checkout to see the suborder and commission row appear.

As with the theme, this hasn't been exercised against a live
WordPress/WooCommerce/MySQL stack in this environment — only
PHP-linted. Please test the full apply → approve → list a product →
checkout → payout flow in a real WP install before relying on it.
