<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Chattrn\Connector\Support\Introspector;
use Chattrn\Connector\Support\ModelMap;
use Chattrn\Connector\Support\Settings;
use Chattrn\Connector\Support\SetupLayout;

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
        return SetupLayout::page('Map your store', '<h1>Map your store</h1>' . SetupLayout::banner('err', $message));
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

        $html = '<h1>Map your store</h1>';
        $html .= '<p class="lede">Fields are pre-filled with best guesses from your app\'s actual tables. Fix anything wrong, then Save — changes are live immediately, no redeploy needed.</p>';

        if ($notice) {
            $html .= SetupLayout::banner('ok', $notice);
        }
        if ($error) {
            $html .= SetupLayout::banner('err', $error);
        }

        $html .= '<form method="post" action="?token=' . e(request()->query('token', '')) . '">';

        $html .= $this->card('Models', null,
            $this->modelSelect('models.product', 'Product (required)', null, $classOptions, $productModel)
            . $this->modelSelect('models.variant', 'Variant', 'Blank if products have no variants', $classOptions, $variantModel, true)
            . $this->modelSelect('models.order', 'Order (required)', null, $classOptions, $orderModel)
            . $this->modelSelect('models.customer', 'Customer', 'Blank if orders carry phone/email directly — guest checkout', $classOptions, $customerModel, true)
            . $this->modelSelect('models.location', 'Location / Outlet', 'Served from locations.json', $classOptions, $locationModel, true)
        );

        $html .= $this->card('Product &amp; variant columns', null,
            $this->textField('columns.variant.price', 'Price column', null, $guessCol($variantCols, ['price', 'amount', 'unit_price'], config('chattrn.columns.variant.price')))
            . $this->textField('columns.variant.stock', 'Stock column', null, $guessCol($variantCols, ['stock', 'quantity', 'qty'], config('chattrn.columns.variant.stock')))
            . $this->textField('columns.variant.title', 'Variant title column', 'Only if a Variant model is set', $guessCol($variantCols, ['title', 'name'], config('chattrn.columns.variant.title')))
            . $this->textField('columns.variant.options_json', 'JSON options column', 'Blank if option1/2/3 are separate columns', config('chattrn.columns.variant.options_json'))
        );

        $html .= $this->card('Order columns', null,
            $this->textField('columns.order.total', 'Order total column', null, $guessCol($orderCols, ['total', 'grand_total', 'amount'], config('chattrn.columns.order.total')))
            . $this->textField('columns.order.customer_name', 'Customer name column on Order', 'Only if no Customer model', $guessCol($orderCols, ['customer_name', 'name', 'full_name'], config('chattrn.columns.order.customer_name')))
            . $this->textField('columns.order.address', 'Shipping address column on Order', 'Only if no Customer model', $guessCol($orderCols, ['address', 'shipping_address'], config('chattrn.columns.order.address')))
        );

        $html .= $this->card('Line item columns', 'Guessed from ' . ($orderItemGuessModel ?: 'the order items table') . ' — fix if it differs.',
            $this->textField('columns.order_item.title', 'Title column', null, $guessCol($orderItemCols, ['title', 'name'], config('chattrn.columns.order_item.title')))
            . $this->textField('columns.order_item.price', 'Price column', null, $guessCol($orderItemCols, ['price', 'unit_price'], config('chattrn.columns.order_item.price')))
            . $this->textField('columns.order_item.sku', 'SKU column', null, $guessCol($orderItemCols, ['sku', 'code'], config('chattrn.columns.order_item.sku')))
            . $this->textField('columns.order_item.variant_title', 'Variant title / options snapshot column', 'Blank if none', config('chattrn.columns.order_item.variant_title'))
        );

        $html .= $this->card('Inventory', 'Only needed if price/stock vary by outlet or location.',
            $this->textField('inventory.model', 'Inventory model', 'Fully-qualified class — blank if price/stock are flat columns above', $inventoryModel)
            . $this->textField('inventory.columns.product_id', 'Product id column', null, $guessCol($invCols, ['product_id'], config('chattrn.inventory.columns.product_id')))
            . $this->textField('inventory.columns.variant_id', 'Variant id column', 'Blank if inventory is per-product, not per-variant', $guessCol($invCols, ['variant_id'], config('chattrn.inventory.columns.variant_id')))
            . $this->textField('inventory.columns.location_id', 'Location/outlet id column', null, $guessCol($invCols, ['location_id', 'outlet_id', 'store_id'], config('chattrn.inventory.columns.location_id')))
            . $this->textField('inventory.columns.price', 'Price column', null, $guessCol($invCols, ['price'], config('chattrn.inventory.columns.price')))
            . $this->textField('inventory.columns.stock', 'Stock column', null, $guessCol($invCols, ['stock', 'quantity'], config('chattrn.inventory.columns.stock')))
            . $this->textField('inventory.columns.compare_price', 'Compare-at price column', 'Blank if none', config('chattrn.inventory.columns.compare_price'))
            . $this->textField('store.primary_location_id', 'Primary location id', 'Blank = first active location', config('chattrn.store.primary_location_id'))
        );

        $html .= $this->card('Store', null,
            $this->textField('store.currency', 'Currency code', null, config('chattrn.store.currency', 'BDT'))
            . $this->textField('store.delivery_mode', 'Delivery mode', '"zone" or "outlet"', config('chattrn.store.delivery_mode', 'zone'))
        );

        $previewUrl = preg_replace('#/map$#', '/map/preview', request()->path()) . '?token=' . e(request()->query('token', ''));
        $html .= '<div class="actions"><button type="submit">Save mapping</button>'
            . '<a href="/' . e($previewUrl) . '" class="btn btn-secondary" onclick="previewMapping(event)">Preview a real product + order →</a></div>';
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

        return SetupLayout::page('Map your store', $html);
    }

    private function card(string $title, ?string $hint, string $inner): string
    {
        $hintHtml = $hint ? '<p class="section-hint">' . e($hint) . '</p>' : '';

        return '<div class="card"><h2>' . $title . '</h2>' . $hintHtml . $inner . '</div>';
    }

    private function modelSelect(string $name, string $label, ?string $hint, array $options, ?string $selected, bool $optional = false): string
    {
        $opts = $optional ? ['' => '— none —'] : ['' => '— choose —'];
        foreach ($options as $class) {
            $opts[$class] = $class;
        }
        if ($selected && ! isset($opts[$selected])) {
            $opts[$selected] = $selected . ' (not found — check it exists)';
        }

        $hintHtml = $hint ? ' <span class="hint">— ' . e($hint) . '</span>' : '';

        $html = '<div class="field"><label>' . e($label) . $hintHtml . '</label><select name="' . e($name) . '">';
        foreach ($opts as $value => $text) {
            $sel = ($value === ($selected ?? '')) ? ' selected' : '';
            $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($text) . '</option>';
        }
        $html .= '</select></div>';

        return $html;
    }

    private function textField(string $name, string $label, ?string $hint, ?string $value): string
    {
        $hintHtml = $hint ? ' <span class="hint">— ' . e($hint) . '</span>' : '';

        return '<div class="field"><label>' . e($label) . $hintHtml . '</label>'
            . '<input type="text" name="' . e($name) . '" value="' . e($value ?? '') . '"></div>';
    }
}
