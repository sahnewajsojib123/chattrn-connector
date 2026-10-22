<?php

// Published by `php artisan chattrn:install`, filled in by `php artisan chattrn:map`.
// Full contract: ../../public/openapi.yaml in the chattrn-backend repo this package ships
// alongside; devspec.txt "Slice 3: Laravel package installer" is the narrative spec.
return [
    // Served under this prefix, e.g. https://your-store.com/chattrn/api/2026-10/store.json.
    'route_prefix' => '/chattrn/api/2026-10',
    'api_version' => '2026-10',

    // The access key Chattrn returns from POST /package/register (see ChattrnRegistrar).
    // Stored in .env, never committed.
    'access_token' => env('CHATTRN_ACCESS_TOKEN'),

    // Where the Chattrn backend lives, so this package can call POST /package/register.
    'backend_url' => env('CHATTRN_BACKEND_URL', 'https://api.chattrn.com'),

    // The one-time setup token pasted into .env from the Install package screen. Only read
    // once, by `php artisan chattrn:map` (or on boot if access_token is still empty), then
    // it should be removed from .env.
    'connection_token' => env('CHATTRN_TOKEN'),

    'store' => [
        'name' => env('APP_NAME', 'My Store'),
        'domain' => env('APP_URL'),
        'currency' => env('CHATTRN_CURRENCY', 'BDT'),
        'country_code' => env('CHATTRN_COUNTRY', 'BD'),
        'delivery_mode' => env('CHATTRN_DELIVERY_MODE', 'zone'), // zone | outlet
        'product_url_template' => null, // e.g. https://your-store.com/product/{handle}
        'cart_url_template' => null,

        // Only meaningful for delivery_mode=outlet stores whose price/stock vary by location
        // (see 'inventory' below). The location shown in products.json/products search before
        // Chattrn has asked the customer which outlet/zone they're in. Unset -> the first
        // active row from the 'location' model is used instead.
        'primary_location_id' => env('CHATTRN_PRIMARY_LOCATION_ID'),
    ],

    // Which Eloquent model answers each canonical resource. Filled in by chattrn:map.
    'models' => [
        'product' => null,
        'variant' => null,
        'order' => null,
        'customer' => null,
        'location' => null,
    ],

    // Optional: only set this if price and/or stock vary by location (delivery_mode=outlet
    // stores with a separate per-outlet inventory table). Leave 'model' null for a store where
    // price/stock are flat columns on the Product/Variant row - columns.variant.price/stock
    // below are used instead, exactly as before, and inventory_levels.json answers with the
    // single default location. When 'model' is set, ModelMap reads price/stock for the catalog
    // from the row matching store.primary_location_id (or the first active location), and
    // inventoryLevels() answers per-location from the real rows.
    'inventory' => [
        'model' => env('CHATTRN_INVENTORY_MODEL'), // e.g. App\Models\Inventory
        'columns' => [
            'product_id' => 'product_id',
            // Only set this if inventory is also split per-variant (unique per variant+
            // outlet), not just per-product+outlet. Leave null when a store has no Variant
            // model, or variants share the same stock/price. When set, inventory_levels.json
            // and the catalog price/stock are matched by variant id instead of product id.
            'variant_id' => null,
            'location_id' => 'outlet_id',
            'price' => 'price',
            'stock' => 'quantity',
            'compare_price' => null, // e.g. 'compare_price', blank if no compare-at price
        ],
    ],

    // Column overrides, only needed where a model's column name isn't already the canonical
    // name (id, title, price, ...). chattrn:map writes the ones it can't guess.
    'columns' => [
        'product' => [
            'title' => 'title',
            'handle' => 'slug',
            'vendor' => null,
            'status' => null,
            'image_url' => 'image_url',
        ],
        'variant' => [
            'title' => 'title',
            'price' => 'price',
            'compare_at_price' => null,
            'sku' => 'sku',
            'stock' => 'stock',
            // Blank if the Variant model has separate option1/option2/option3-style columns
            // (read directly, unchanged). Set this to a column holding options as JSON (an
            // object like {"color":"Red","size":"M"} or a plain array) to have it decoded
            // into option1/option2/option3 instead.
            'options_json' => null,
        ],
        'order' => [
            'number' => 'number',
            'phone' => 'phone',
            'total' => 'total',
            'currency' => null,
            'financial_status' => 'status',
            'fulfillment_status' => null,
        ],
        'customer' => [
            'phone' => 'phone',
            'email' => 'email',
        ],
        // Only used when an Order model has an items()/lineItems() relation. 'title'/'sku'
        // fall back to the line item's related product (via a product() relation on that
        // model) when the column below is null or the line item table has no such column.
        'order_item' => [
            'title' => 'title',
            'price' => 'price',
            'quantity' => 'quantity',
            'sku' => 'sku',
        ],
    ],
];
