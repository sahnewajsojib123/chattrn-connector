# chattrn/connector

Serves the Chattrn API from your own Laravel store, so Chattrn reads products, stock and
orders the same way it reads a native Chattrn API or Shopify store. The full endpoint
contract (`openapi.yaml`) lives in the main [chattrn-API](https://github.com/sahsojib114/chattrn-API)
repo, alongside the Chattrn-side backend this package registers with.

## Connect from the Chattrn dashboard

1. Install with `composer require chattrn/connector` and deploy the package.
2. Generate a setup token in Chattrn when ready to deploy. Set `CHATTRN_TOKEN` and
   `CHATTRN_BACKEND_URL` in your hosting environment. The backend URL must be reachable
   from your store; `localhost` on your computer is not reachable from a hosted app.
3. After `php artisan migrate --force`, run `php artisan chattrn:register --no-interaction`.
4. In Chattrn, review **Map your tables**, save, then verify the live product preview.

Registration does not require models to be mapped. It exchanges the short-lived setup token
and encrypts the ongoing access token with Laravel's `APP_KEY` in `chattrn_settings`.
Keep `APP_KEY` stable and use a persistent database. No access token is printed or copied
into `.env`, and no second deployment is required. Existing `CHATTRN_ACCESS_TOKEN`
environment configurations continue to work. An encrypted database credential takes
precedence over the environment token once paired.

### Hosting without a shell

Add this after migrations and before starting the web server in your startup script:

```sh
if [ -n "${CHATTRN_TOKEN:-}" ]; then
    if ! php artisan chattrn:register --no-interaction; then
        echo 'Chattrn pairing failed; check the setup token and backend URL.' >&2
    fi
fi
```

Deploy once. Registration is idempotent after a successful exchange: future starts reuse the
saved credential rather than exchanging the single-use token again. An expired setup token
does not prevent the storefront from starting. The database migration must run before pairing.

### Mapping and validation

Mappings are saved in the database and take effect without redeploying. The dashboard lists
actual columns, including product names, images, brand, variant fields, order/customer
fields, and per-location inventory. Optional settings are collapsed. Invalid columns,
unknown models, missing price/stock sources, blank currency, and invalid delivery modes
are rejected before writing. Product is required; Order and Customer are optional.

Column choices refresh when a model changes. Catalog examples appear beside the chosen
columns, while order/customer values are omitted. Previewing a draft does not write settings;
only Save mapping persists the reviewed draft. If the host is waking up, retry loading tables
before saving. The package must be updated for the dashboard's preview endpoint to work.

The existing terminal `chattrn:map` command and store-hosted setup screens remain available.
The dashboard is the recommended mapping path.

## What it assumes about your models

This is a best-effort mapping, not a full ORM introspection tool:

- A **Product** model is required for the catalog endpoints. If you also map a **Variant**
  model, `ModelMap` expects a `variants()` relation on Product; without one, each product is
  served as its own single default variant, reading price/stock straight off the product row.
- An **Order** model is required for order endpoints. A `items()` relation supplies line items
  if present, otherwise orders are served with an empty `line_items` array.
- **Customer** is optional — only needed for `customers/search.json` and
  `customers/{id}/orders.json`.
- `draft_orders.json` and draft completion are disabled (HTTP 501) until you wire
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

## Correctness and reconnect behavior

The generic package advertises cancellation, draft orders and checkout completion as unsupported.
Those endpoints return HTTP 501 until a merchant adapter implements authoritative pricing and
business rules. Mapping an Order model alone does not enable write actions.

To reconnect, use **Reconnect package** in Chattrn. Set the returned setup token as `CHATTRN_TOKEN`
on the Laravel host and redeploy with `php artisan chattrn:register` in the startup sequence.
Do not replace `CHATTRN_ACCESS_TOKEN`: the package stores its active access key encrypted in the database.
Generating a reconnect setup token keeps the existing connection active until the exchange succeeds.
The package persists an exchange ID before calling the backend, so an interrupted exchange can be retried
with the same setup token before its 30-minute expiry. A new setup token explicitly starts a new exchange.
Deploy the corresponding CN-API backend update before this package update.
