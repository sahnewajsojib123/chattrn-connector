# chattrn/connector

Serves the Chattrn API from your own Laravel store, so Chattrn reads products, stock and
orders the same way it reads a native Chattrn API or Shopify store. The full endpoint
contract (`openapi.yaml`) lives in the main [chattrn-API](https://github.com/sahsojib114/chattrn-API)
repo, alongside the Chattrn-side backend this package registers with.

## Install

```bash
composer require chattrn/connector
php artisan chattrn:install
```

Paste the `CHATTRN_TOKEN=ct_live_...` line from the **Install package** screen into `.env`,
then map your models either from the terminal:

```bash
php artisan chattrn:map
```

or from a browser, if your host doesn't give you shell/SSH access (Render's free tier, for
example):

```
https://your-store.com/chattrn/setup/map?token=YOUR_CHATTRN_TOKEN
```

Both ask which Eloquent models are your Product, Variant, Order and Customer and which columns
hold price and stock — pre-filled with best guesses from your actual tables (`chattrn:map`
fills in the terminal prompt's default; the browser screen pre-fills the form), so most of the
time it's "confirm the guess," not "type it from memory." The mapping is saved to a
`chattrn_settings` database table (migrated automatically the first time you run
`php artisan migrate`), not to `config/chattrn.php` — so a later change, from either entry
point, takes effect immediately with no git commit or redeploy. Only secrets
(`CHATTRN_ACCESS_TOKEN`, `CHATTRN_TOKEN`, `CHATTRN_BACKEND_URL`) and `APP_URL`/`APP_NAME` stay
in `.env`, same as any other Laravel secret.

The first `chattrn:map` run (or the first save from `/chattrn/setup/map`) also registers with
Chattrn, which replies with the ongoing `CHATTRN_ACCESS_TOKEN` to add to `.env` (remove
`CHATTRN_TOKEN` once you have it; it's single-use).

```bash
php artisan chattrn:check
```

Runs a handful of local contract checks (store profile, catalog, locations, orders, and that a
wrong token is refused) against your own app. If your host blocks shell access, use
`/chattrn/setup/map/preview?token=...` instead — it runs your actual mapping against one real
product and one real order and shows the resulting JSON, so you can sanity-check it from a
browser too.

## What it assumes about your models

This is a best-effort mapping, not a full ORM introspection tool:

- A **Product** model is required for the catalog endpoints. If you also map a **Variant**
  model, `ModelMap` expects a `variants()` relation on Product; without one, each product is
  served as its own single default variant, reading price/stock straight off the product row.
- An **Order** model is required for order endpoints. A `items()` relation supplies line items
  if present, otherwise orders are served with an empty `line_items` array.
- **Customer** is optional — only needed for `customers/search.json` and
  `customers/{id}/orders.json`.
- `draft_orders.json` (cart quote) computes totals in memory from the posted line items; it
  does not persist anything, and `draft_orders/{id}/complete.json` returns 404 until you wire
  up your own checkout flow there (`ChattrnApiController::completeDraftOrder`).

Column names that don't already match the canonical ones (`price`, `stock`, `title`, ...) are
set in `config/chattrn.php`'s `columns` array; `chattrn:map` writes the common ones for you.

## Price/stock that vary by outlet (delivery_mode=outlet)

If your store prices or stocks products differently per physical outlet/location rather than
having one flat `price`/`stock` column on the product (or variant) row, set
`config('chattrn.inventory.model')` to your inventory-style model (one row per
product+location), with `inventory.columns` pointing at its `product_id`/`location_id`/`price`/
`stock` columns — `chattrn:map` asks about this and writes it for you. Also map
`config('chattrn.models.location')` to your Outlet/Location model so `locations.json` returns
real rows instead of a single placeholder, and set `CHATTRN_PRIMARY_LOCATION_ID` in `.env` to
the location whose price/stock should answer `products.json` before Chattrn has asked the
customer which outlet/zone they're in (left unset, the first active location is used).
Per-location figures Chattrn asks for once it knows the customer's outlet are then served for
real from `inventory_levels.json`.

A store with flat price/stock (no outlets, `delivery_mode=zone`) leaves `inventory.model` unset
and none of this applies — behavior is unchanged from before.

## Testing this package

This repo's sandbox has no access to `packagist.org` or the GitHub API (both blocked by its
network egress allowlist — confirmed with `composer diagnose` and a direct `curl` to
`api.github.com`, which returned 403), so `composer require laravel/framework
orchestra/testbench` could not be run here to boot a real Laravel app against this code. Every
file passed `php -l` (syntax only). Before relying on this in production:

```bash
composer require --dev orchestra/testbench phpunit/phpunit
vendor/bin/phpunit
```

in an environment with normal Packagist access, with a fixture app wiring up `tests/` (see
`tests/ChattrnApiTest.php` for a starting Testbench-style suite covering store.json, the
product/order endpoints, pagination and the wrong-token 401).
