<?php

namespace Chattrn\Connector\Console\Commands;

use Illuminate\Console\Command;
use Chattrn\Connector\Support\ChattrnRegistrar;
use Chattrn\Connector\Support\Introspector;
use Chattrn\Connector\Support\Settings;

// devspec.txt: "php artisan chattrn:map asks in the terminal which models are Product,
// Variant, Order and Customer, and which columns hold price and stock, then writes
// config/chattrn.php." Also registers with Chattrn on first run, since the register call
// needs to know which models matched (devspec.txt: "package_installs (..., models_matched
// JSON, ...)").
//
// Since v1.1.0 this writes to the chattrn_settings DB table via Settings, not
// config/chattrn.php - the same storage the /chattrn/setup/map browser screen uses
// (ChattrnMapController), so either entry point works and a later edit through the other
// one isn't silently overwritten. Every prompt is pre-filled with a guess from Introspector
// (scans your actual tables), so this is now "confirm the guess" rather than "type it from
// memory" - press enter to accept a guess.
class MapCommand extends Command
{
    protected $signature = 'chattrn:map';
    protected $description = 'Map your Eloquent models to the Chattrn API, then register with Chattrn';

    public function handle(): int
    {
        $models = Introspector::models();
        if (! $models) {
            $this->warn('Could not find any Eloquent models under app/Models - you can still type class names by hand below.');
        }

        $current = array_replace_recursive(
            require __DIR__ . '/../../../config/chattrn.php',
            ['models' => config('chattrn.models'), 'columns' => config('chattrn.columns'), 'inventory' => config('chattrn.inventory'), 'store' => config('chattrn.store')]
        );

        $values = [];

        $values['models.product'] = $this->askModel('Product', $current['models']['product'] ?? Introspector::guessModel($models, ['Product']));
        $values['models.variant'] = $this->askModel('Variant (blank if products have no variants)', $current['models']['variant'] ?? Introspector::guessModel($models, ['ProductVariant', 'Variant']), true);
        $values['models.order'] = $this->askModel('Order', $current['models']['order'] ?? Introspector::guessModel($models, ['Order']));
        $values['models.customer'] = $this->askModel('Customer (blank if orders carry phone/email directly)', $current['models']['customer'] ?? Introspector::guessModel($models, ['Customer']), true);

        $variantCols = $this->columnsOf($models, $values['models.variant'] ?: $values['models.product']);
        $orderCols = $this->columnsOf($models, $values['models.order']);

        if (! $values['models.customer']) {
            $values['columns.order.customer_name'] = $this->ask('Column on the Order model that holds the customer\'s name', $current['columns']['order']['customer_name'] ?? Introspector::guessColumn($orderCols, ['customer_name', 'name', 'full_name']) ?? 'customer_name');
            $values['columns.order.address'] = $this->ask('Column on the Order model that holds the shipping address', $current['columns']['order']['address'] ?? Introspector::guessColumn($orderCols, ['address', 'shipping_address']) ?? 'address');
        }

        $values['columns.variant.price'] = $this->ask('Column that holds price on the ' . ($values['models.variant'] ?: 'Product') . ' model', $current['columns']['variant']['price'] ?? Introspector::guessColumn($variantCols, ['price', 'amount']) ?? 'price');
        $values['columns.variant.stock'] = $this->ask('Column that holds stock on the ' . ($values['models.variant'] ?: 'Product') . ' model', $current['columns']['variant']['stock'] ?? Introspector::guessColumn($variantCols, ['stock', 'quantity']) ?? 'stock');
        $values['columns.order.total'] = $this->ask('Column that holds the order total', $current['columns']['order']['total'] ?? Introspector::guessColumn($orderCols, ['total', 'grand_total']) ?? 'total');

        if ($values['models.variant']) {
            $values['columns.variant.title'] = $this->ask('Column that holds the variant\'s title/name', $current['columns']['variant']['title'] ?? Introspector::guessColumn($variantCols, ['title', 'name']) ?? 'title');
            if ($this->confirm('Are variant options (color/size/...) stored as JSON rather than separate option1/option2/option3 columns?', (bool) ($current['columns']['variant']['options_json'] ?? null))) {
                $values['columns.variant.options_json'] = $this->ask('Column that holds the JSON options', $current['columns']['variant']['options_json'] ?? 'options');
            }
        }

        if ($this->confirm('Does price/stock vary by outlet/location (a separate inventory table), rather than being flat columns above?', (bool) ($current['inventory']['model'] ?? null))) {
            $invGuess = Introspector::guessModel($models, ['Inventory', 'Stock', 'StockLevel']);
            $values['inventory.model'] = $this->askModel('Inventory', $current['inventory']['model'] ?? $invGuess);
            $invCols = $this->columnsOf($models, $values['inventory.model']);

            $values['inventory.columns.product_id'] = $this->ask('Column on the Inventory model that holds the product id', $current['inventory']['columns']['product_id'] ?? Introspector::guessColumn($invCols, ['product_id']) ?? 'product_id');
            if ($values['models.variant'] && $this->confirm('Is inventory also split per-variant (unique per variant+outlet), not just per-product+outlet?', (bool) ($current['inventory']['columns']['variant_id'] ?? null))) {
                $values['inventory.columns.variant_id'] = $this->ask('Column on the Inventory model that holds the variant id', $current['inventory']['columns']['variant_id'] ?? Introspector::guessColumn($invCols, ['variant_id']) ?? 'variant_id');
            }
            $values['inventory.columns.location_id'] = $this->ask('Column on the Inventory model that holds the location/outlet id', $current['inventory']['columns']['location_id'] ?? Introspector::guessColumn($invCols, ['location_id', 'outlet_id', 'store_id']) ?? 'outlet_id');
            $values['inventory.columns.price'] = $this->ask('Column on the Inventory model that holds price', $current['inventory']['columns']['price'] ?? Introspector::guessColumn($invCols, ['price']) ?? 'price');
            $values['inventory.columns.stock'] = $this->ask('Column on the Inventory model that holds stock', $current['inventory']['columns']['stock'] ?? Introspector::guessColumn($invCols, ['stock', 'quantity']) ?? 'stock');
            if ($this->confirm('Does the Inventory model have a compare-at (was/strikethrough) price column?', (bool) ($current['inventory']['columns']['compare_price'] ?? null))) {
                $values['inventory.columns.compare_price'] = $this->ask('Column that holds the compare-at price', $current['inventory']['columns']['compare_price'] ?? 'compare_price');
            }
            $values['models.location'] = $this->askModel('Location/Outlet (served from locations.json)', $current['models']['location'] ?? Introspector::guessModel($models, ['Outlet', 'Location', 'Branch']), true);
            $values['store.primary_location_id'] = $this->ask('Primary location id shown in the catalog before Chattrn knows the customer\'s outlet (blank = first active location)', $current['store']['primary_location_id'] ?? null) ?: null;
        }

        $orderItemGuessModel = Introspector::guessModel($models, ['OrderItem', 'LineItem', 'OrderLine']);
        $orderItemCols = $this->columnsOf($models, $orderItemGuessModel);
        if ($lineItemHint = $this->ask('If orders have line items with a title/price/sku column that differs from "title"/"price"/"sku" (e.g. a "name" column), list them as title:price:sku - blank to accept the guess', null)) {
            [$titleCol, $priceCol, $skuCol] = array_pad(explode(':', $lineItemHint), 3, null);
            $values['columns.order_item.title'] = $titleCol ?: null;
            $values['columns.order_item.price'] = $priceCol ?: null;
            $values['columns.order_item.sku'] = $skuCol ?: null;
        } else {
            $values['columns.order_item.title'] = Introspector::guessColumn($orderItemCols, ['title', 'name']);
            $values['columns.order_item.price'] = Introspector::guessColumn($orderItemCols, ['price', 'unit_price']);
            $values['columns.order_item.sku'] = Introspector::guessColumn($orderItemCols, ['sku', 'code']);
        }

        if ($values['models.variant'] && $this->confirm('Do line items store a variant name/options snapshot (e.g. "Red / Large"), separate from the product title?', (bool) ($current['columns']['order_item']['variant_title'] ?? null))) {
            $values['columns.order_item.variant_title'] = $this->ask('Column that holds the variant title/name on line items', $current['columns']['order_item']['variant_title'] ?? 'variant_name');
        }

        try {
            Settings::setMany($values);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Mapping saved (chattrn_settings table) - live immediately.');

        Settings::applyOverrides();

        $token = config('chattrn.connection_token');
        if (! $token) {
            $this->warn('No CHATTRN_TOKEN found in .env, so I could not register with Chattrn yet. Paste it in and re-run chattrn:map.');
            return self::SUCCESS;
        }
        if (config('chattrn.access_token')) {
            $this->info('Already registered (CHATTRN_ACCESS_TOKEN is set). Delete it from .env and re-run to re-register.');
            return self::SUCCESS;
        }

        $this->line('Registering with Chattrn...');
        $result = ChattrnRegistrar::register($token);
        if (! $result['ok']) {
            $this->error('Register failed: ' . $result['message']);
            return self::FAILURE;
        }

        $this->info('Registered. Add this to .env, then remove the CHATTRN_TOKEN line:');
        $this->line('  CHATTRN_ACCESS_TOKEN=' . $result['access_token']);

        return self::SUCCESS;
    }

    private function askModel(string $label, ?string $current, bool $optional = false): ?string
    {
        $answer = $this->ask("Fully-qualified class for {$label}", $current);
        $answer = $answer ? trim($answer) : null;
        if ($answer && ! class_exists($answer)) {
            $this->warn("{$answer} doesn't exist yet - saving it anyway; fix it with chattrn:map or /chattrn/setup/map if that was a typo.");
        }
        return $answer ?: null;
    }

    private function columnsOf(array $models, ?string $class): array
    {
        return $class && isset($models[$class]) ? $models[$class] : [];
    }
}
