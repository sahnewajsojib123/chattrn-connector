<?php

namespace Chattrn\Connector\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;

// Builds the "Map your tables" card/row tree that the Chattrn dashboard renders (step 2 of
// the Connect / Map your tables / Verify flow). The dashboard never guesses a schema it
// cannot see: this runs inside the merchant's own app, reads their real Eloquent models and
// columns through Introspector, and hands back guesses the merchant confirms or corrects.
//
// Shape (consumed by flow-tester.html's lmCardHtml):
//   cards[] = { title, hint?, action?, note?, layout?, groups[] }
//   groups[] = { label?, rows[] }
//   rows[]  = { key, title, hint?, tag?, type: text|select, options?, value, status, detail? }
//   status  = matched (already saved) | guessed (found this request) | missing (required,
//             not found) | none (optional, not set)
class MappingSchema
{
    private const MODEL_CANDIDATES = [
        'product' => ['Product'],
        'variant' => ['ProductVariant', 'Variant'],
        'order' => ['Order'],
        'customer' => ['Customer'],
        'location' => ['Outlet', 'Location', 'Branch', 'Store'],
    ];

    private const INVENTORY_CANDIDATES = ['Inventory', 'Stock', 'StockLevel'];
    private const ORDER_ITEM_CANDIDATES = ['OrderItem', 'LineItem', 'OrderLine'];

    private static array $sampleRows = [];
    private static array $draftValues = [];

    public static function preview(array $values): array
    {
        $original = config('chattrn');
        self::$draftValues = $values;
        $available = Introspector::models();
        foreach (array_filter($original['models'] ?? []) as $class) {
            if (is_subclass_of($class, Model::class)) $available[$class] = [];
        }
        try {
            foreach ($values as $key => $value) {
                if (! is_string($value) && $value !== null) continue;
                if (str_starts_with($key, 'models.') || $key === 'inventory.model') {
                    if ($value && ! isset($available[$value])) {
                        throw ValidationException::withMessages([$key => 'Choose an available model.']);
                    }
                    config(['chattrn.' . $key => $value ?: null]);
                } elseif (in_array($key, ['store.currency', 'store.delivery_mode'], true)) {
                    config(['chattrn.' . $key => $value]);
                } elseif (preg_match('/^(columns|inventory\.columns)\./', $key)) {
                    config(['chattrn.' . $key => $value]);
                }
            }
            return self::cards();
        } finally {
            config(['chattrn' => $original]);
            self::$draftValues = [];
        }
    }

    public static function cards(): array
    {
        self::$sampleRows = [];
        $models = Introspector::models();
        $saved = config('chattrn.models', []);
        $classes = array_keys($models);

        $resolve = fn (string $concept) => (array_key_exists($concept, $saved) && $saved[$concept] === null && (array_key_exists('models.' . $concept, Settings::all()) || array_key_exists('models.' . $concept, self::$draftValues)))
            ? null : ($saved[$concept] ?? Introspector::guessModel($models, self::MODEL_CANDIDATES[$concept]));

        $product = $resolve('product');
        $variant = $resolve('variant');
        $order = $resolve('order');
        $customer = $resolve('customer');
        $location = $resolve('location');
        $inventory = array_key_exists('inventory.model', self::$draftValues) ? (self::$draftValues['inventory.model'] ?: null) : (array_key_exists('inventory.model', Settings::all()) ? config('chattrn.inventory.model') : (config('chattrn.inventory.model') ?? Introspector::guessModel($models, self::INVENTORY_CANDIDATES)));
        $orderItem = Introspector::guessModel($models, self::ORDER_ITEM_CANDIDATES);

        $cols = fn (?string $class) => $class && isset($models[$class]) ? $models[$class] : [];
        $variantCols = $cols($variant ?: $product);
        $orderCols = $cols($order);
        $itemCols = $cols($orderItem);
        $invCols = $cols($inventory);

        return [
            [
                'title' => 'Models',
                'hint' => 'Which Eloquent model is which concept — guessed from your app\'s tables.',
                'action' => 'Re-scan tables',
                'note' => 'Leave a row blank and Chattrn skips that concept.',
                'groups' => [
                    ['label' => 'Required', 'rows' => [
                        self::modelRow('models.product', 'Product', 'Catalog item', $classes, $product, isset($saved['product']), $models, true),
                    ]],
                    ['label' => 'Optional', 'rows' => [
                        self::modelRow('models.order', 'Order', 'Optional — enable order lookup', $classes, $order, isset($saved['order']), $models),

                        self::modelRow('models.variant', 'Variant', 'Blank if products have no variants', $classes, $variant, isset($saved['variant']), $models),
                        self::modelRow('models.customer', 'Customer', 'Guest checkout if blank', $classes, $customer, isset($saved['customer']), $models),
                        self::modelRow('models.location', 'Location / Outlet', 'Served from locations.json', $classes, $location, isset($saved['location']), $models),
                    ]],
                ],
            ],
            [
                'title' => 'Product & variant columns',
                'hint' => 'Guessed from ' . ($product ?: 'your Product model') . '.',
                'layout' => 'grid',
                'groups' => [
                    ['rows' => [
                        self::colRow('columns.product.title', 'Product name column', 'Your product title or name column', $product, $cols($product), ['title', 'name', 'product_name'], in_array(config('chattrn.columns.product.title'), $cols($product), true) ? config('chattrn.columns.product.title') : null, true, null),
                        self::colRow('columns.product.vendor', 'Brand column', 'Optional', $product, $cols($product), ['vendor', 'brand', 'manufacturer'], config('chattrn.columns.product.vendor'), false, null),
                        self::colRow('columns.product.image_url', 'Image URL column', 'Your product column containing the image URL (for example image)', $product, $cols($product), ['image_url', 'image', 'photo_url', 'photo'], in_array(config('chattrn.columns.product.image_url'), $cols($product), true) ? config('chattrn.columns.product.image_url') : null, false, null),
                        self::colRow('columns.product.handle', 'Product handle / slug', 'Optional', $product, $cols($product), ['handle', 'slug'], config('chattrn.columns.product.handle'), false, null),
                        self::colRow('columns.product.description', 'Description', 'Optional', $product, $cols($product), ['description', 'body_html'], config('chattrn.columns.product.description'), false, null),
                        self::colRow('columns.product.product_type', 'Product category', 'Optional', $product, $cols($product), ['product_type', 'category'], config('chattrn.columns.product.product_type'), false, null),
                        self::colRow('columns.product.tags', 'Tags', 'Optional', $product, $cols($product), ['tags'], config('chattrn.columns.product.tags'), false, null),
                        self::colRow('columns.product.status', 'Product status', 'Optional', $product, $cols($product), ['status'], config('chattrn.columns.product.status'), false, null),
                        self::colRow('columns.variant.price', 'Price column', null, $variant ?: $product, $variantCols, ['price', 'amount', 'unit_price'], config('chattrn.columns.variant.price'), true, null),
                        self::colRow('columns.variant.stock', 'Stock column', null, $variant ?: $product, $variantCols, ['stock', 'quantity', 'qty'], config('chattrn.columns.variant.stock'), true, null),
                        self::colRow('columns.variant.title', 'Variant title column', 'Only if Variant is set', $variant ?: $product, $variantCols, ['title', 'name'], config('chattrn.columns.variant.title'), false, null),
                        self::colRow('columns.variant.sku', 'Variant SKU', 'Optional', $variant ?: $product, $variantCols, ['sku', 'code'], config('chattrn.columns.variant.sku'), false, null),
                        self::colRow('columns.variant.compare_at_price', 'Original price', 'Optional', $variant ?: $product, $variantCols, ['compare_at_price', 'compare_price'], config('chattrn.columns.variant.compare_at_price'), false, null),
                        self::colRow('columns.variant.options_json', 'JSON options column', 'Blank if separate columns', $variant ?: $product, $variantCols, [], config('chattrn.columns.variant.options_json'), false, null),
                    ]],
                ],
            ],
            [
                'title' => 'Order columns',
                'optional' => true,
                'hint' => 'Guessed from ' . ($order ?: 'your Order model') . '.',
                'note' => 'Only needed when you have no Customer model.',
                'groups' => [
                    ['label' => 'Required', 'rows' => [
                        self::colRow('columns.order.total', 'Order total column', 'Order grand total', $order, $orderCols, ['total', 'grand_total', 'amount'], config('chattrn.columns.order.total'), true),
                    ]],
                    ['label' => 'Optional', 'rows' => [
                        self::colRow('columns.order.number', 'Order number', 'Optional', $order, $orderCols, ['number', 'order_number'], config('chattrn.columns.order.number'), false),
                        self::colRow('columns.order.phone', 'Order phone', 'Optional', $order, $orderCols, ['phone', 'customer_phone'], config('chattrn.columns.order.phone'), false),
                        self::colRow('columns.order.currency', 'Order currency', 'Optional', $order, $orderCols, ['currency'], config('chattrn.columns.order.currency'), false),
                        self::colRow('columns.order.financial_status', 'Payment status', 'Optional', $order, $orderCols, ['financial_status', 'status'], config('chattrn.columns.order.financial_status'), false),
                        self::colRow('columns.order.fulfillment_status', 'Fulfillment status', 'Optional', $order, $orderCols, ['fulfillment_status'], config('chattrn.columns.order.fulfillment_status'), false),
                        self::colRow('columns.order.customer_name', 'Customer name column', 'Only if no Customer model', $order, $orderCols, ['customer_name', 'name', 'full_name'], config('chattrn.columns.order.customer_name'), false),
                        self::colRow('columns.order.address', 'Shipping address column', 'Only if no Customer model', $order, $orderCols, ['address', 'shipping_address'], config('chattrn.columns.order.address'), false),
                    ]],
                ],
            ],
            [
                'title' => 'Line item columns',
                'optional' => true,
                'hint' => 'Guessed from ' . ($orderItem ?: 'the order items table') . '.',
                'note' => 'These drive what the AI shows when a customer asks about an order.',
                'groups' => [
                    ['label' => 'Required', 'rows' => [
                        self::colRow('columns.order_item.title', 'Title column', 'Item name on the order', $orderItem, $itemCols, ['title', 'name'], config('chattrn.columns.order_item.title'), true),
                        self::colRow('columns.order_item.price', 'Price column', 'Unit price paid', $orderItem, $itemCols, ['price', 'unit_price'], config('chattrn.columns.order_item.price'), true),
                    ]],
                    ['label' => 'Optional', 'rows' => [
                        self::colRow('columns.order_item.sku', 'SKU column', 'Shown in the inbox', $orderItem, $itemCols, ['sku', 'code'], config('chattrn.columns.order_item.sku'), false),
                        self::colRow('columns.order_item.variant_title', 'Variant snapshot column', 'Blank if none', $orderItem, $itemCols, [], config('chattrn.columns.order_item.variant_title'), false),
                    ]],
                ],
            ],
            [
                'title' => 'Inventory',
                'optional' => true,
                'hint' => 'Only needed if price/stock vary by outlet or location.',
                'note' => 'Leave the model blank to keep stock on the product itself.',
                'groups' => [
                    ['label' => 'Optional: turns on per-location stock', 'rows' => [
                        self::modelRow('inventory.model', 'Inventory model', 'Fully-qualified class', $classes, $inventory, (bool) config('chattrn.inventory.model'), $models),
                        self::colRow('inventory.columns.product_id', 'Product id column', 'Foreign key', $inventory, $invCols, ['product_id'], config('chattrn.inventory.columns.product_id'), false),
                    ]],
                    ['label' => 'Columns', 'rows' => [
                        self::colRow('inventory.columns.variant_id', 'Variant id column', 'Foreign key', $inventory, $invCols, ['variant_id'], config('chattrn.inventory.columns.variant_id'), false),
                        self::colRow('inventory.columns.location_id', 'Location id column', 'Foreign key', $inventory, $invCols, ['location_id', 'outlet_id', 'store_id'], config('chattrn.inventory.columns.location_id'), false),
                        self::colRow('inventory.columns.price', 'Price column', 'Per-location selling price', $inventory, $invCols, ['price', 'unit_price'], config('chattrn.inventory.columns.price'), false),
                        self::colRow('inventory.columns.stock', 'Stock column', 'Quantity on hand', $inventory, $invCols, ['stock', 'quantity'], config('chattrn.inventory.columns.stock'), false),
                    ]],
                ],
            ],
            [
                'title' => 'Customer columns',
                'optional' => true,
                'groups' => [['rows' => [
                    self::colRow('columns.customer.phone', 'Phone', 'Optional', $customer, $cols($customer), ['phone', 'mobile'], config('chattrn.columns.customer.phone'), false),
                    self::colRow('columns.customer.email', 'Email', 'Optional', $customer, $cols($customer), ['email'], config('chattrn.columns.customer.email'), false),
                ]]],
            ],
            [
                'title' => 'Store',
                'hint' => 'Currency and delivery mode Chattrn will use.',
                'layout' => 'grid',
                'groups' => [
                    ['rows' => [
                        self::plainRow('store.delivery_mode', 'Delivery mode', '"zone" or "outlet"', (string) config('chattrn.store.delivery_mode', 'zone')),
                        self::plainRow('store.currency', 'Currency code', null, (string) config('chattrn.store.currency', 'BDT')),
                    ]],
                ],
            ],
        ];
    }

    // Applies the dashboard's edits. Keys are the same dot-paths used above, so this is just
    // a filtered pass-through to Settings - no second mapping table to keep in step.
    public static function save(array $values): array
    {
        $allowed = [];
        foreach (self::cards() as $card) {
            foreach ($card['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $allowed[$row['key']] = true;
                }
            }
        }

        $clean = [];
        $errors = [];
        foreach ($values as $key => $value) {
            if (! isset($allowed[$key]) || (! is_string($value) && $value !== null)) {
                $errors[$key] = 'Choose a supported mapping field.';
                continue;
            }
            $clean[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $effective = config('chattrn');
        foreach ($clean as $key => $value) {
            Arr::set($effective, $key, $value);
        }
        $available = Introspector::models();
        // Include owner-configured models outside app/Models.
        foreach (array_filter(config('chattrn.models', [])) as $class) {
            if (is_subclass_of($class, Model::class)) {
                $model = new $class;
                $available[$class] = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            }
        }
        foreach ($clean as $key => $value) {
            if (str_starts_with($key, 'models.') || $key === 'inventory.model') {
                if ($value && ! isset($available[$value])) {
                    $errors[$key] = 'Choose a model with an existing database table.';
                }
            }
            if ((str_starts_with($key, 'columns.') || str_starts_with($key, 'inventory.columns.')) && $value) {
                $parts = explode('.', $key);
                $resource = $parts[0] === 'inventory' ? 'inventory' : $parts[1];
                $class = $resource === 'inventory' ? Arr::get($effective, 'inventory.model') : Arr::get($effective, "models.$resource");
                if ($resource === 'variant') $class = $class ?: Arr::get($effective, 'models.product');
                if ($resource === 'order_item') $class = Introspector::guessModel($available, self::ORDER_ITEM_CANDIDATES);
                if (! $class || ! in_array($value, $available[$class] ?? [], true)) {
                    $errors[$key] = 'Choose a column on the selected model.';
                }
            }
        }
        if (! Arr::get($effective, 'models.product')) $errors['models.product'] = 'Choose a product model.';
        if (! Arr::get($effective, 'columns.product.title')) $errors['columns.product.title'] = 'Choose a product name column.';
        $priceKey = Arr::get($effective, 'inventory.model') ? 'inventory.columns.price' : 'columns.variant.price';
        $stockKey = Arr::get($effective, 'inventory.model') ? 'inventory.columns.stock' : 'columns.variant.stock';
        foreach ([$priceKey, $stockKey] as $required) {
            if (! Arr::get($effective, $required)) $errors[$required] = 'Choose a price/stock column for the selected inventory source.';
        }
        if (! preg_match('/^[A-Z]{3}$/', (string) Arr::get($effective, 'store.currency'))) $errors['store.currency'] = 'Enter a three-letter currency code, such as BDT.';
        if (! in_array(Arr::get($effective, 'store.delivery_mode'), ['zone', 'outlet'], true)) $errors['store.delivery_mode'] = 'Choose zone or outlet.';
        if ($errors) throw ValidationException::withMessages($errors);

        Settings::setMany($clean);
        Settings::applyOverrides();

        return $clean;
    }

    private static function modelRow(string $key, string $title, ?string $hint, array $classes, ?string $value, bool $wasSaved, array $models, bool $required = false): array
    {
        $options = [['value' => '', 'label' => $required ? '— choose —' : '— none —']];
        foreach (array_unique($classes) as $class) {
            $options[] = ['value' => $class, 'label' => $class];
        }
        if ($value && ! in_array($value, $classes, true)) {
            $options[] = ['value' => $value, 'label' => $value . ' (not found — check it exists)'];
        }

        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'tag' => 'MODEL',
            'type' => 'select',
            'options' => $options,
            'value' => $value ?? '',
            'status' => $value ? ($wasSaved ? 'matched' : 'guessed') : ($required ? 'missing' : 'none'),
            'detail' => $value
                ? (isset($models[$value]) ? count($models[$value]) . ' columns found' : null)
                : ($required ? 'pick a model' : 'optional'),
        ];
    }

    private static function colRow(string $key, string $title, ?string $hint, ?string $class, array $columns, array $candidates, ?string $savedValue, bool $required, ?string $tag = 'COL'): array
    {
        if (! $columns && $class && is_subclass_of($class, Model::class)) {
            try {
                $model = new $class;
                $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            } catch (\Throwable $e) {
                $columns = [];
            }
        }
        $savedValue = in_array($savedValue, $columns, true) ? $savedValue : null;
        $value = $savedValue ?? ($candidates ? Introspector::guessColumn($columns, $candidates) : null);

        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'tag' => $tag,
            'type' => 'select',
            'options' => array_merge([['value' => '', 'label' => $required ? '— choose a column —' : '— not mapped —']], $columns),
            'value' => $value ?? '',
            'status' => $value ? ($savedValue ? 'matched' : 'guessed') : ($required ? 'missing' : 'none'),
            'detail' => $value ? self::columnType($class, $value) : ($required ? 'no match found' : 'optional'),
            'sample' => self::sampleValue($key, $class, $value),
        ];
    }

    private static function sampleValue(string $key, ?string $class, ?string $column): ?string
    {
        // Preview catalog values only. Never include order/customer records in schema cards.
        if (! preg_match('/^(columns\.(product|variant)\.|inventory\.columns\.(price|stock)$)/', $key) || ! $class || ! $column) return null;
        if (in_array($class, [config('chattrn.models.order'), config('chattrn.models.customer')], true) || preg_match('/(Customer|Order|User|Payment)/i', class_basename($class))) return null;
        try {
            if (! array_key_exists($class, self::$sampleRows)) {
                self::$sampleRows[$class] = $class::query()->first();
            }
            $value = self::$sampleRows[$class]?->getAttribute($column);
            if (! is_scalar($value)) return null;
            return mb_substr(strip_tags((string) $value), 0, 120);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function plainRow(string $key, string $title, ?string $hint, string $value): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'tag' => null,
            'type' => 'text',
            'value' => $value,
            'status' => $value !== '' ? 'matched' : 'none',
            'detail' => null,
        ];
    }

    // The "decimal" / "string" / "text" hint under a guessed column, read from the real
    // schema so a mistyped mapping (a price pointing at a varchar) is visible here.
    private static function columnType(?string $class, string $column): ?string
    {
        if (! $class || ! class_exists($class)) {
            return null;
        }
        try {
            return Schema::getColumnType((new $class)->getTable(), $column);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
