<?php

namespace Chattrn\Connector\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

// Turns the merchant's own Eloquent models into the canonical Chattrn shapes (the same
// Shopify-shaped resources the Node mock in chattrn-backend/src/mock-chattrn-store.js
// returns), using the model classes and column overrides `php artisan chattrn:map` wrote
// into config/chattrn.php. Deliberately plain array building, not API Resources per model,
// so one place holds the "what does a Product look like" contract.
class ModelMap
{
    public static function productModelClass(): ?string
    {
        return config('chattrn.models.product');
    }

    public static function orderModelClass(): ?string
    {
        return config('chattrn.models.order');
    }

    public static function customerModelClass(): ?string
    {
        return config('chattrn.models.customer');
    }

    public static function variantModelClass(): ?string
    {
        return config('chattrn.models.variant');
    }

    public static function requireModel(?string $class, string $label): string
    {
        if (! $class || ! class_exists($class)) {
            throw new RuntimeException("No {$label} model is configured. Run `php artisan chattrn:map`.");
        }

        return $class;
    }

    private static function col(string $resource, string $field, string $default): string
    {
        return config("chattrn.columns.{$resource}.{$field}") ?: $default;
    }

    private static function get(Model $model, string $column, $default = null)
    {
        return $column && isset($model->{$column}) ? $model->{$column} : $default;
    }

    // ---- Product / Variant ----

    public static function toProduct(Model $product, string $host): array
    {
        $variants = self::variantsOf($product);

        return [
            'id' => $product->getKey(),
            'title' => self::get($product, self::col('product', 'title', 'title')),
            'handle' => self::get($product, self::col('product', 'handle', 'slug')),
            'body_html' => self::get($product, self::col('product', 'description', 'description')),
            'vendor' => self::get($product, self::col('product', 'vendor', 'vendor')),
            'product_type' => self::get($product, self::col('product', 'product_type', 'category')),
            'status' => self::get($product, self::col('product', 'status', 'status'), 'active'),
            'tags' => self::get($product, self::col('product', 'tags', 'tags'), ''),
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
            'options' => [],
            'variants' => $variants->map(fn ($v) => self::toVariant($v, $product))->values()->all(),
            'images' => self::imagesOf($product, $host),
        ];
    }

    public static function toVariant(Model $variant, ?Model $product = null, $locationId = null): array
    {
        $priceCol = self::col('variant', 'price', 'price');
        $stockCol = self::col('variant', 'stock', 'stock');
        $invCols = config('chattrn.inventory.columns', []);

        // A store whose price/stock vary by location (config('chattrn.inventory.model') set)
        // reads the catalog figure from the inventory row at the primary/given location,
        // matched by variant id too when inventory is split per-variant (inventory.columns.
        // variant_id set), instead of a flat column on the variant row. See inventoryLevels()
        // for the per-location figures Chattrn asks for once it knows the customer's outlet.
        $inv = self::inventoryRow($product ?? $variant, $variant, $locationId);
        if ($inv) {
            $price = self::get($inv, $invCols['price'] ?? 'price');
            $stock = self::get($inv, $invCols['stock'] ?? 'stock');
            $compareAt = ($invCols['compare_price'] ?? null) ? self::get($inv, $invCols['compare_price']) : null;
        } else {
            $price = self::get($variant, $priceCol);
            $stock = self::get($variant, $stockCol);
            $compareAt = self::get($variant, self::col('variant', 'compare_at_price', 'compare_at_price'));
        }

        [$option1, $option2, $option3] = self::variantOptions($variant);

        return [
            'id' => $variant->getKey(),
            'product_id' => $product?->getKey() ?? self::get($variant, 'product_id'),
            'title' => self::get($variant, self::col('variant', 'title', 'title'), 'Default'),
            'option1' => $option1,
            'option2' => $option2,
            'option3' => $option3,
            'price' => self::money($price),
            'compare_at_price' => self::moneyOrNull($compareAt),
            'sku' => self::get($variant, self::col('variant', 'sku', 'sku')),
            'barcode' => self::get($variant, 'barcode'),
            'inventory_item_id' => $variant->getKey(),
            'inventory_quantity' => $stock,
            'inventory_management' => $stock === null ? null : 'chattrn',
            'inventory_policy' => 'deny',
        ];
    }

    // Reads option1/option2/option3 straight off the variant row by default. If
    // columns.variant.options_json is set, decodes that column (a JSON object like
    // {"color":"Red","size":"M"}, a JSON array, or an already-cast PHP array) into up to
    // three positional option values instead.
    private static function variantOptions(Model $variant): array
    {
        $jsonCol = config('chattrn.columns.variant.options_json');
        if (! $jsonCol) {
            return [self::get($variant, 'option1'), self::get($variant, 'option2'), self::get($variant, 'option3')];
        }
        $raw = self::get($variant, $jsonCol);
        $decoded = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        $values = is_array($decoded) ? array_values($decoded) : [];
        return [$values[0] ?? null, $values[1] ?? null, $values[2] ?? null];
    }

    // ---- Per-location inventory (optional; see config('chattrn.inventory')) ----

    public static function inventoryModelClass(): ?string
    {
        $class = config('chattrn.inventory.model');
        return ($class && class_exists($class)) ? $class : null;
    }

    public static function primaryLocationId()
    {
        if ($id = config('chattrn.store.primary_location_id')) {
            return $id;
        }
        $locationClass = config('chattrn.models.location');
        if ($locationClass && class_exists($locationClass)) {
            $query = $locationClass::query();
            $table = (new $locationClass)->getTable();
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'active')) {
                $query->where('active', true);
            }
            return optional($query->first())->getKey();
        }
        return null;
    }

    // $variant is the real variant row when the store has a separate Variant model; pass the
    // same object as $product (or null) for a store with no variants, where price/stock are
    // keyed by product only.
    private static function inventoryRow(?Model $product, ?Model $variant = null, $locationId = null)
    {
        $class = self::inventoryModelClass();
        if (! $class || ! $product) {
            return null;
        }
        $cols = config('chattrn.inventory.columns', []);
        $productCol = $cols['product_id'] ?? 'product_id';
        $variantCol = $cols['variant_id'] ?? null;
        $locationCol = $cols['location_id'] ?? 'location_id';
        $locationId = $locationId ?? self::primaryLocationId();

        $query = $class::query()->where($productCol, $product->getKey());
        if ($variantCol && $variant && $variant !== $product) {
            $query->where($variantCol, $variant->getKey());
        }
        if ($locationId !== null) {
            $query->where($locationCol, $locationId);
        }
        return $query->first();
    }

    // Products without a configured variant model are treated as their own single default
    // variant, reading price/stock straight off the product row.
    private static function variantsOf(Model $product)
    {
        $variantClass = self::variantModelClass();
        if ($variantClass && method_exists($product, 'variants')) {
            $rel = $product->variants();
            return $rel->get();
        }

        return collect([$product]);
    }

    private static function imagesOf(Model $product, string $host): array
    {
        $url = self::get($product, self::col('product', 'image_url', 'image_url'));
        if (! $url) {
            return [];
        }

        return [[
            'id' => $product->getKey(),
            'src' => $url,
            'position' => 1,
            'variant_ids' => [],
        ]];
    }

    private static function money($value): ?string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) ||
            ! preg_match('/^-?\d+(?:\.\d+)?$/', trim((string) $value)) || ! is_finite((float) $value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }

    private static function moneyOrNull($value): ?string
    {
        return $value === null || $value === '' ? null : self::money($value);
    }

    // ---- Order / Customer ----

    public static function toOrder(Model $order): array
    {
        $lineItems = method_exists($order, 'items') ? $order->items()->get() : collect();

        return [
            'id' => $order->getKey(),
            'name' => '#' . (self::get($order, self::col('order', 'number', 'number')) ?? $order->getKey()),
            'order_number' => self::get($order, self::col('order', 'number', 'number'), $order->getKey()),
            'phone' => self::get($order, self::col('order', 'phone', 'phone')),
            'email' => self::get($order, 'email'),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'currency' => self::get($order, self::col('order', 'currency', 'currency')) ?? config('chattrn.store.currency'),
            'subtotal_price' => self::money(self::get($order, self::col('order', 'total', 'total'))),
            'total_discounts' => '0.00',
            'total_tax' => '0.00',
            'total_price' => self::money(self::get($order, self::col('order', 'total', 'total'))),
            'financial_status' => self::get($order, self::col('order', 'financial_status', 'status')),
            'fulfillment_status' => self::get($order, self::col('order', 'fulfillment_status', 'fulfillment_status')),
            'cancelled_at' => optional($order->cancelled_at ?? null)?->toIso8601String(),
            'cancel_reason' => self::get($order, 'cancel_reason'),
            'payment_gateway_names' => [self::get($order, 'payment_method', 'cod')],
            // Required by the contract even for a guest-checkout store with no Customer
            // model: both are built from the order's own columns in that case (see
            // columns.order.customer_name/address). With a real Customer model configured,
            // override these two via a future relation-based mapping if you need richer data -
            // for now the order's own phone/email/address columns are used either way, which
            // Chattrn needs regardless of whether a separate Customer record also exists.
            'customer' => self::orderCustomer($order),
            'shipping_address' => self::orderShippingAddress($order),
            'line_items' => $lineItems->map(fn ($li) => [
                'id' => $li->getKey(),
                'title' => self::lineItemTitle($li),
                'variant_title' => self::get($li, self::col('order_item', 'variant_title', 'variant_title')),
                'quantity' => self::get($li, self::col('order_item', 'quantity', 'quantity'), 1),
                'price' => self::money(self::get($li, self::col('order_item', 'price', 'price'), 0)),
                'sku' => self::lineItemSku($li),
            ])->values()->all(),
            'shipping_lines' => [],
            'fulfillments' => [],
        ];
    }

    private static function orderCustomer(Model $order): array
    {
        return array_filter([
            'first_name' => self::get($order, self::col('order', 'customer_name', 'customer_name')),
            'phone' => self::get($order, self::col('order', 'phone', 'phone')),
            'email' => self::get($order, 'email'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function orderShippingAddress(Model $order): array
    {
        return array_filter([
            'name' => self::get($order, self::col('order', 'customer_name', 'customer_name')),
            'phone' => self::get($order, self::col('order', 'phone', 'phone')),
            'address1' => self::get($order, self::col('order', 'address', 'address')),
        ], fn ($v) => $v !== null && $v !== '');
    }

    // Falls back to the line item's related product (via a product() relation on the line
    // item model) when the line item table itself has no title/sku column - e.g. order_items
    // storing a 'name' snapshot instead, or no sku at all (only products.sku).
    private static function lineItemTitle(Model $li): string
    {
        $direct = self::get($li, self::col('order_item', 'title', 'title'));
        if ($direct !== null) {
            return $direct;
        }
        $product = method_exists($li, 'product') ? $li->product : null;
        return $product ? (self::get($product, self::col('product', 'title', 'title')) ?? 'Item') : 'Item';
    }

    private static function lineItemSku(Model $li)
    {
        $direct = self::get($li, self::col('order_item', 'sku', 'sku'));
        if ($direct !== null) {
            return $direct;
        }
        $product = method_exists($li, 'product') ? $li->product : null;
        return $product ? self::get($product, self::col('variant', 'sku', 'sku')) : null;
    }

    public static function toCustomer(Model $customer): array
    {
        return [
            'id' => $customer->getKey(),
            'phone' => self::get($customer, self::col('customer', 'phone', 'phone')),
            'email' => self::get($customer, self::col('customer', 'email', 'email')),
            'first_name' => self::get($customer, 'first_name'),
            'last_name' => self::get($customer, 'last_name'),
        ];
    }
}
