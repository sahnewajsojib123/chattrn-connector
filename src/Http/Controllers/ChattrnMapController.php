<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Chattrn\Connector\Support\Introspector;
use Chattrn\Connector\Support\ModelMap;
use Chattrn\Connector\Support\Settings;

// A browser-based equivalent of `php artisan chattrn:map`, for hosts with no shell/SSH
// access (same reasoning as ChattrnSetupController). Two things set it apart from the CLI
// wizard: it pre-fills every field with a guess from Introspector instead of asking blind,
// and it saves into the chattrn_settings DB table via Settings instead of rewriting
// config/chattrn.php - so a mapping change is live the moment you click Save, no git commit
// or redeploy needed. Gated by whichever of CHATTRN_TOKEN (before registration) or
// CHATTRN_ACCESS_TOKEN (after) is currently set - no new secret, same as the register
// screen.
class ChattrnMapController extends Controller
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

    public function show(Request $request)
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        return $this->html($this->renderForm());
    }

    public function save(Request $request)
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $values = $this->collectFromRequest($request);

        try {
            Settings::setMany($values);
        } catch (\Throwable $e) {
            return $this->html($this->renderForm(null, 'Could not save: ' . $e->getMessage()), 500);
        }

        Settings::applyOverrides();

        return $this->html($this->renderForm('Saved - changes are live now, no redeploy needed.'));
    }

    public function preview(Request $request)
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $out = [];

        $productClass = config('chattrn.models.product');
        if ($productClass && class_exists($productClass)) {
            $product = $productClass::query()->first();
            $out['product'] = $product ? ModelMap::toProduct($product, $request->getHost()) : 'No rows in ' . $productClass . ' yet.';
        } else {
            $out['product'] = 'Product model not mapped.';
        }

        $orderClass = config('chattrn.models.order');
        if ($orderClass && class_exists($orderClass)) {
            $order = $orderClass::query()->latest()->first();
            $out['order'] = $order ? ModelMap::toOrder($order) : 'No rows in ' . $orderClass . ' yet.';
        } else {
            $out['order'] = 'Order model not mapped.';
        }

        return response()->json(['ok' => true, 'preview' => $out]);
    }

    // Returns a response to short-circuit with, or null if the request is authorized.
    private function authorize(Request $request)
    {
        $expected = config('chattrn.access_token') ?: config('chattrn.connection_token');
        if (! $expected) {
            return $this->html($this->errorPage(
                'No CHATTRN_TOKEN or CHATTRN_ACCESS_TOKEN set in this environment yet - paste the token from the Install package screen into .env first.'
            ), 422);
        }

        if (! hash_equals((string) $expected, (string) $request->query('token', ''))) {
            return $this->html($this->errorPage(
                'Missing or incorrect ?token= - use the same value as CHATTRN_TOKEN (or CHATTRN_ACCESS_TOKEN once registered).'
            ), 403);
        }

        return null;
    }

    private function collectFromRequest(Request $request): array
    {
        $values = [];

        foreach (array_keys(self::MODEL_CANDIDATES) as $concept) {
            $values['models.' . $concept] = $request->input('models.' . $concept) ?: null;
        }

        $hasCustomer = (bool) $values['models.customer'];
        $hasVariant = (bool) $values['models.variant'];

        $textFields = [
            'columns.variant.price', 'columns.variant.stock', 'columns.variant.title', 'columns.variant.options_json',
            'columns.order.total', 'columns.order.customer_name', 'columns.order.address',
            'columns.order_item.title', 'columns.order_item.price', 'columns.order_item.sku', 'columns.order_item.variant_title',
            'inventory.model', 'inventory.columns.product_id', 'inventory.columns.variant_id', 'inventory.columns.location_id',
            'inventory.columns.price', 'inventory.columns.stock', 'inventory.columns.compare_price',
            'store.primary_location_id', 'store.currency', 'store.delivery_mode',
        ];
        foreach ($textFields as $field) {
            $value = trim((string) $request->input($field, ''));
            $values[$field] = $value !== '' ? $value : null;
        }

        if (! $hasCustomer) {
            // keep customer_name/address - already collected above
        } else {
            $values['columns.order.customer_name'] = null;
            $values['columns.order.address'] = null;
        }
        if (! $hasVariant) {
            $values['columns.variant.title'] = null;
            $values['columns.variant.options_json'] = null;
            $values['inventory.columns.variant_id'] = null;
        }

        return $values;
    }

    // ---- rendering -----------------------------------------------------------------

    private function html(string $body, int $status = 200)
    {
        return response($body, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function errorPage(string $message): string
    {
        return $this->layout('<p class="err">' . e($message) . '</p>');
    }

    private function renderForm(?string $notice = null, ?string $error = null): string
    {
        $models = Introspector::models();
        $saved = config('chattrn.models', []);
        $classOptions = array_keys($models);

        $guessedModel = fn (string $concept, bool $optional) => $saved[$concept]
            ?? Introspector::guessModel($models, self::MODEL_CANDIDATES[$concept])
            ?? ($optional ? null : null);

        $productModel = $guessedModel('product', false);
        $variantModel = $guessedModel('variant', true);
        $orderModel = $guessedModel('order', true);
        $customerModel = $guessedModel('customer', true);
        $locationModel = $guessedModel('location', true);
        $inventoryModel = config('chattrn.inventory.model') ?? Introspector::guessModel($models, self::INVENTORY_CANDIDATES);
        $orderItemGuessModel = Introspector::guessModel($models, self::ORDER_ITEM_CANDIDATES);

        $colsOf = fn (?string $class) => $class && isset($models[$class]) ? $models[$class] : [];

        $guessCol = fn (array $cols, array $candidates, ?string $saved) => $saved ?? Introspector::guessColumn($cols, $candidates);

        $variantCols = $colsOf($variantModel ?: $productModel);
        $orderCols = $colsOf($orderModel);
        $invCols = $colsOf($inventoryModel);
        $orderItemCols = $colsOf($orderItemGuessModel);

        $html = '';
        if ($notice) {
            $html .= '<p class="ok">' . e($notice) . '</p>';
        }
        if ($error) {
            $html .= '<p class="err">' . e($error) . '</p>';
        }

        $html .= '<form method="post" action="?token=' . e(request()->query('token', '')) . '">';

        $html .= '<h2>Models</h2>';
        $html .= $this->modelSelect('models.product', 'Product (required)', $classOptions, $productModel);
        $html .= $this->modelSelect('models.variant', 'Variant (blank if products have no variants)', $classOptions, $variantModel, true);
        $html .= $this->modelSelect('models.order', 'Order (required)', $classOptions, $orderModel);
        $html .= $this->modelSelect('models.customer', 'Customer (blank if orders carry phone/email directly - guest checkout)', $classOptions, $customerModel, true);
        $html .= $this->modelSelect('models.location', 'Location/Outlet (served from locations.json)', $classOptions, $locationModel, true);

        $html .= '<h2>Product / variant columns</h2>';
        $html .= $this->textField('columns.variant.price', 'Price column', $guessCol($variantCols, ['price', 'amount', 'unit_price'], config('chattrn.columns.variant.price')));
        $html .= $this->textField('columns.variant.stock', 'Stock column', $guessCol($variantCols, ['stock', 'quantity', 'qty'], config('chattrn.columns.variant.stock')));
        $html .= $this->textField('columns.variant.title', 'Variant title column (only if a Variant model is set)', $guessCol($variantCols, ['title', 'name'], config('chattrn.columns.variant.title')));
        $html .= $this->textField('columns.variant.options_json', 'JSON options column (blank if option1/2/3 are separate columns)', config('chattrn.columns.variant.options_json'));

        $html .= '<h2>Order columns</h2>';
        $html .= $this->textField('columns.order.total', 'Order total column', $guessCol($orderCols, ['total', 'grand_total', 'amount'], config('chattrn.columns.order.total')));
        $html .= $this->textField('columns.order.customer_name', "Customer name column on Order (only if no Customer model)", $guessCol($orderCols, ['customer_name', 'name', 'full_name'], config('chattrn.columns.order.customer_name')));
        $html .= $this->textField('columns.order.address', 'Shipping address column on Order (only if no Customer model)', $guessCol($orderCols, ['address', 'shipping_address'], config('chattrn.columns.order.address')));

        $html .= '<h2>Line item columns</h2><p class="hint">Guessed from ' . e($orderItemGuessModel ?: 'the order items table') . ' - fix if it differs.</p>';
        $html .= $this->textField('columns.order_item.title', 'Title column', $guessCol($orderItemCols, ['title', 'name'], config('chattrn.columns.order_item.title')));
        $html .= $this->textField('columns.order_item.price', 'Price column', $guessCol($orderItemCols, ['price', 'unit_price'], config('chattrn.columns.order_item.price')));
        $html .= $this->textField('columns.order_item.sku', 'SKU column', $guessCol($orderItemCols, ['sku', 'code'], config('chattrn.columns.order_item.sku')));
        $html .= $this->textField('columns.order_item.variant_title', 'Variant title/options snapshot column (blank if none)', config('chattrn.columns.order_item.variant_title'));

        $html .= '<h2>Inventory (only if price/stock vary by outlet)</h2>';
        $html .= $this->textField('inventory.model', 'Inventory model (fully-qualified class, blank if price/stock are flat columns above)', $inventoryModel);
        $html .= $this->textField('inventory.columns.product_id', 'Product id column', $guessCol($invCols, ['product_id'], config('chattrn.inventory.columns.product_id')));
        $html .= $this->textField('inventory.columns.variant_id', 'Variant id column (blank if inventory is per-product, not per-variant)', $guessCol($invCols, ['variant_id'], config('chattrn.inventory.columns.variant_id')));
        $html .= $this->textField('inventory.columns.location_id', 'Location/outlet id column', $guessCol($invCols, ['location_id', 'outlet_id', 'store_id'], config('chattrn.inventory.columns.location_id')));
        $html .= $this->textField('inventory.columns.price', 'Price column', $guessCol($invCols, ['price'], config('chattrn.inventory.columns.price')));
        $html .= $this->textField('inventory.columns.stock', 'Stock column', $guessCol($invCols, ['stock', 'quantity'], config('chattrn.inventory.columns.stock')));
        $html .= $this->textField('inventory.columns.compare_price', 'Compare-at price column (blank if none)', config('chattrn.inventory.columns.compare_price'));
        $html .= $this->textField('store.primary_location_id', 'Primary location id (blank = first active location)', config('chattrn.store.primary_location_id'));

        $html .= '<h2>Store</h2>';
        $html .= $this->textField('store.currency', 'Currency code', config('chattrn.store.currency', 'BDT'));
        $html .= $this->textField('store.delivery_mode', 'Delivery mode (zone or outlet)', config('chattrn.store.delivery_mode', 'zone'));

        $previewUrl = preg_replace('#/map$#', '/map/preview', request()->path()) . '?token=' . e(request()->query('token', ''));
        $html .= '<p><button type="submit">Save</button> <a href="/' . e($previewUrl) . '" class="btn-secondary" onclick="previewMapping(event)">Preview a real product + order</a></p>';
        $html .= '<pre id="preview-out" style="display:none"></pre>';
        $html .= '</form>';

        $html .= '<script>
function previewMapping(e) {
    e.preventDefault();
    fetch(e.currentTarget.href).then(r => r.json()).then(data => {
        const out = document.getElementById("preview-out");
        out.style.display = "block";
        out.textContent = JSON.stringify(data, null, 2);
    });
}
</script>';

        return $this->layout($html);
    }

    private function modelSelect(string $name, string $label, array $options, ?string $selected, bool $optional = false): string
    {
        $opts = $optional ? ['' => '— none —'] : ['' => '— choose —'];
        foreach ($options as $class) {
            $opts[$class] = $class;
        }
        if ($selected && ! isset($opts[$selected])) {
            $opts[$selected] = $selected . ' (not found - check it exists)';
        }

        $html = '<label>' . e($label) . '<select name="' . e($name) . '">';
        foreach ($opts as $value => $text) {
            $sel = ($value === ($selected ?? '')) ? ' selected' : '';
            $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($text) . '</option>';
        }
        $html .= '</select></label>';

        return $html;
    }

    private function textField(string $name, string $label, ?string $value): string
    {
        return '<label>' . e($label) . '<input type="text" name="' . e($name) . '" value="' . e($value ?? '') . '"></label>';
    }

    private function layout(string $body): string
    {
        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Chattrn - Map your store</title>
<style>
body { font-family: -apple-system, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
h2 { margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: .25rem; }
label { display: block; margin: .75rem 0; font-size: .9rem; color: #444; }
input, select { display: block; width: 100%; margin-top: .25rem; padding: .4rem; font-size: 1rem; box-sizing: border-box; }
button { padding: .6rem 1.2rem; font-size: 1rem; cursor: pointer; }
.btn-secondary { margin-left: 1rem; }
.ok { background: #e6f4ea; padding: .75rem; border-radius: 4px; }
.err { background: #fce8e6; padding: .75rem; border-radius: 4px; }
.hint { color: #777; font-size: .85rem; }
pre { background: #f5f5f5; padding: 1rem; overflow: auto; }
</style>
</head>
<body>
<h1>Map your store to Chattrn</h1>
<p class="hint">Fields are pre-filled with best guesses from your app's actual tables. Fix anything wrong, then Save - changes are live immediately, no redeploy needed.</p>
{$body}
</body>
</html>
HTML;
    }
}
