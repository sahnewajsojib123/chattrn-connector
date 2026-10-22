<?php

namespace Chattrn\Connector\Console\Commands;

use Illuminate\Console\Command;
use Chattrn\Connector\Support\ChattrnRegistrar;
use Chattrn\Connector\Support\ConfigWriter;

// devspec.txt: "php artisan chattrn:map asks in the terminal which models are Product,
// Variant, Order and Customer, and which columns hold price and stock, then writes
// config/chattrn.php." Also registers with Chattrn on first run, since the register call
// needs to know which models matched (devspec.txt: "package_installs (..., models_matched
// JSON, ...)").
class MapCommand extends Command
{
    protected $signature = 'chattrn:map';
    protected $description = 'Map your Eloquent models to the Chattrn API, then register with Chattrn';

    public function handle(): int
    {
        // Deep-merge with the package's own default config so a config/chattrn.php published
        // by an older version of this package (missing newer keys like 'inventory' or
        // columns.order_item) doesn't blow up with undefined array key errors below.
        $defaults = require __DIR__ . '/../../../config/chattrn.php';
        $published = require config_path('chattrn.php');
        $config = array_replace_recursive($defaults, $published);

        $config['models']['product'] = $this->askModel('Product', $config['models']['product']);
        $config['models']['variant'] = $this->askModel('Variant (blank if products have no variants)', $config['models']['variant'], true);
        $config['models']['order'] = $this->askModel('Order', $config['models']['order']);
        $config['models']['customer'] = $this->askModel('Customer (blank if orders carry phone/email directly)', $config['models']['customer'], true);

        $config['columns']['variant']['price'] = $this->ask('Column that holds price on the ' . ($config['models']['variant'] ?: 'Product') . ' model', $config['columns']['variant']['price'] ?? 'price');
        $config['columns']['variant']['stock'] = $this->ask('Column that holds stock on the ' . ($config['models']['variant'] ?: 'Product') . ' model', $config['columns']['variant']['stock'] ?? 'stock');
        $config['columns']['order']['total'] = $this->ask('Column that holds the order total', $config['columns']['order']['total'] ?? 'total');

        if ($config['models']['variant']) {
            $config['columns']['variant']['title'] = $this->ask('Column that holds the variant\'s title/name', $config['columns']['variant']['title'] ?? 'title');
            if ($this->confirm('Are variant options (color/size/...) stored as JSON rather than separate option1/option2/option3 columns?', (bool) $config['columns']['variant']['options_json'])) {
                $config['columns']['variant']['options_json'] = $this->ask('Column that holds the JSON options', $config['columns']['variant']['options_json'] ?: 'options');
            }
        }

        if ($this->confirm('Does price/stock vary by outlet/location (a separate inventory table), rather than being flat columns above?', (bool) $config['inventory']['model'])) {
            $config['inventory']['model'] = $this->askModel('Inventory', $config['inventory']['model']);
            $config['inventory']['columns']['product_id'] = $this->ask('Column on the Inventory model that holds the product id', $config['inventory']['columns']['product_id'] ?? 'product_id');
            if ($config['models']['variant'] && $this->confirm('Is inventory also split per-variant (unique per variant+outlet), not just per-product+outlet?', (bool) $config['inventory']['columns']['variant_id'])) {
                $config['inventory']['columns']['variant_id'] = $this->ask('Column on the Inventory model that holds the variant id', $config['inventory']['columns']['variant_id'] ?: 'variant_id');
            }
            $config['inventory']['columns']['location_id'] = $this->ask('Column on the Inventory model that holds the location/outlet id', $config['inventory']['columns']['location_id'] ?? 'outlet_id');
            $config['inventory']['columns']['price'] = $this->ask('Column on the Inventory model that holds price', $config['inventory']['columns']['price'] ?? 'price');
            $config['inventory']['columns']['stock'] = $this->ask('Column on the Inventory model that holds stock', $config['inventory']['columns']['stock'] ?? 'stock');
            if ($this->confirm('Does the Inventory model have a compare-at (was/strikethrough) price column?', (bool) $config['inventory']['columns']['compare_price'])) {
                $config['inventory']['columns']['compare_price'] = $this->ask('Column that holds the compare-at price', $config['inventory']['columns']['compare_price'] ?: 'compare_price');
            }
            $config['models']['location'] = $this->askModel('Location/Outlet (served from locations.json)', $config['models']['location'], true);
            $config['store']['primary_location_id'] = $this->ask('Primary location id shown in the catalog before Chattrn knows the customer\'s outlet (blank = first active location)', $config['store']['primary_location_id']) ?: null;
        }

        if ($lineItemHint = $this->ask('If orders have line items with a title/price/sku column that differs from "title"/"price"/"sku" (e.g. a "name" column), list them as title:price:sku - blank to skip', null)) {
            [$titleCol, $priceCol, $skuCol] = array_pad(explode(':', $lineItemHint), 3, null);
            $config['columns']['order_item']['title'] = $titleCol ?: $config['columns']['order_item']['title'];
            $config['columns']['order_item']['price'] = $priceCol ?: $config['columns']['order_item']['price'];
            $config['columns']['order_item']['sku'] = $skuCol ?: $config['columns']['order_item']['sku'];
        }

        if (! ConfigWriter::write(config_path('chattrn.php'), $config)) {
            $this->error('Could not write config/chattrn.php - check it is writable.');
            return self::FAILURE;
        }
        $this->info('config/chattrn.php updated.');

        // Pick up the freshly written config for this process before registering.
        config(['chattrn' => $config]);

        $token = $config['connection_token'] ?? env('CHATTRN_TOKEN');
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
        $answer = $this->ask("Fully-qualified class for {$label}" . ($optional ? '' : ''), $current);
        $answer = $answer ? trim($answer) : null;
        if ($answer && ! class_exists($answer)) {
            $this->warn("{$answer} doesn't exist yet - saving it anyway; fix it in config/chattrn.php if that was a typo.");
        }
        return $answer ?: null;
    }
}
