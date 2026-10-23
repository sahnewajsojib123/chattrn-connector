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
//
// Renders step 2 of the same CDS "Connect / Map your tables / Verify" flow as
// ChattrnSetupController (SetupLayout - stepper, row-list cards with a guess-status column),
// matching the approved "Laravel B · Map your tables" Figma frame.
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

        // "Save mapping & continue" means both - on to step 3 (Verify) once it's saved,
        // same as Connect's "Continue: map your tables" moves on rather than re-showing
        // itself. Settings::setMany() already failed above if the save itself failed.
        return redirect('/chattrn/setup/verify?token=' . rawurlencode($request->query('token', '')));
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
        return '<div class="panel-head"><h1>Map your tables</h1></div>'
            . '<div class="panel-body">' . SetupLayout::banner('err', $message) . '</div>';
    }

    private function renderForm(?string $notice = null, ?string $error = null): string
    {
        $models = Introspector::models();
        $saved = config('chattrn.models', []);
        $classOptions = array_keys($models);

        $guessedModel = fn (string $concept) => $saved[$concept]
            ?? Introspector::guessModel($models, self::MODEL_CANDIDATES[$concept]);

        $productModel = $guessedModel('product');
        $variantModel = $guessedModel('variant');
        $orderModel = $guessedModel('order');
        $customerModel = $guessedModel('customer');
        $locationModel = $guessedModel('location');
        $inventoryModel = config('chattrn.inventory.model') ?? Introspector::guessModel($models, self::INVENTORY_CANDIDATES);
        $orderItemGuessModel = Introspector::guessModel($models, self::ORDER_ITEM_CANDIDATES);

        $colsOf = fn (?string $class) => $class && isset($models[$class]) ? $models[$class] : [];

        $variantCols = $colsOf($variantModel ?: $productModel);
        $orderCols = $colsOf($orderModel);
        $invCols = $colsOf($inventoryModel);
        $orderItemCols = $colsOf($orderItemGuessModel);

        $token = e(request()->query('token', ''));

        $html = '<div class="panel-head">';
        $html .= '<h1>Map your tables</h1>';
        $html .= '<p class="lede">Fields are pre-filled with best guesses from your app&rsquo;s actual tables. Fix anything wrong &mdash; changes save straight to the database, no redeploy needed.</p>';
        $html .= SetupLayout::stepper(2, false);
        $html .= '</div>';

        $html .= '<div class="panel-body">';

        if ($notice) {
            $html .= SetupLayout::banner('ok', $notice);
        }
        if ($error) {
            $html .= SetupLayout::banner('err', $error);
        }

        $html .= '<form id="mapForm" method="post" action="?token=' . $token . '">';

        $html .= $this->card('Models', 'Which Eloquent model is which concept.',
            $this->cat('Required')
            . $this->modelRow('models.product', 'Product', 'Catalog item', $classOptions, $productModel, true, $models, isset($saved['product']))
            . $this->modelRow('models.order', 'Order', 'Placed order', $classOptions, $orderModel, true, $models, isset($saved['order']))
            . $this->cat('Optional')
            . $this->modelRow('models.variant', 'Variant', 'Blank if products have no variants', $classOptions, $variantModel, false, $models, isset($saved['variant']))
            . $this->modelRow('models.customer', 'Customer', 'Guest checkout if blank', $classOptions, $customerModel, false, $models, isset($saved['customer']))
            . $this->modelRow('models.location', 'Location / Outlet', 'Served from locations.json', $classOptions, $locationModel, false, $models, isset($saved['location']))
        );

        $html .= $this->card('Product &amp; variant columns', 'Guessed from ' . e($productModel ?: 'your Product model') . '.',
            $this->cat('Required')
            . $this->colRow('columns.variant.price', 'Price column', null, $variantCols, ['price', 'amount', 'unit_price'], config('chattrn.columns.variant.price'), true)
            . $this->colRow('columns.variant.stock', 'Stock column', null, $variantCols, ['stock', 'quantity', 'qty'], config('chattrn.columns.variant.stock'), true)
            . $this->cat('Optional')
            . $this->colRow('columns.variant.title', 'Variant title column', 'Only if Variant is set', $variantCols, ['title', 'name'], config('chattrn.columns.variant.title'), false)
            . $this->colRow('columns.variant.options_json', 'JSON options column', 'Blank if separate columns', $variantCols, [], config('chattrn.columns.variant.options_json'), false)
        );

        $html .= $this->card('Order columns', null,
            $this->cat('Required')
            . $this->colRow('columns.order.total', 'Order total column', null, $orderCols, ['total', 'grand_total', 'amount'], config('chattrn.columns.order.total'), true)
            . $this->cat('Optional')
            . $this->colRow('columns.order.customer_name', 'Customer name column', 'Only if no Customer model', $orderCols, ['customer_name', 'name', 'full_name'], config('chattrn.columns.order.customer_name'), false)
            . $this->colRow('columns.order.address', 'Shipping address column', 'Only if no Customer model', $orderCols, ['address', 'shipping_address'], config('chattrn.columns.order.address'), false)
        );

        $html .= $this->card('Line item columns', 'Guessed from ' . e($orderItemGuessModel ?: 'the order items table') . '.',
            $this->cat('Required')
            . $this->colRow('columns.order_item.title', 'Title column', null, $orderItemCols, ['title', 'name'], config('chattrn.columns.order_item.title'), true)
            . $this->colRow('columns.order_item.price', 'Price column', null, $orderItemCols, ['price', 'unit_price'], config('chattrn.columns.order_item.price'), true)
            . $this->cat('Optional')
            . $this->colRow('columns.order_item.sku', 'SKU column', null, $orderItemCols, ['sku', 'code'], config('chattrn.columns.order_item.sku'), false)
            . $this->colRow('columns.order_item.variant_title', 'Variant snapshot column', 'Blank if none', $orderItemCols, [], config('chattrn.columns.order_item.variant_title'), false)
        );

        $html .= $this->card('Inventory', 'Only needed if price/stock vary by outlet or location.',
            $this->cat('Optional: turns on per-location stock')
            . $this->modelRow('inventory.model', 'Inventory model', 'Fully-qualified class', array_merge($classOptions, $inventoryModel ? [$inventoryModel] : []), $inventoryModel, false, $models, (bool) config('chattrn.inventory.model'))
            . $this->colRow('inventory.columns.product_id', 'Product id column', null, $invCols, ['product_id'], config('chattrn.inventory.columns.product_id'), false)
            . $this->colRow('inventory.columns.variant_id', 'Variant id column', 'Blank if inventory is per-product', $invCols, ['variant_id'], config('chattrn.inventory.columns.variant_id'), false)
            . $this->colRow('inventory.columns.location_id', 'Location id column', null, $invCols, ['location_id', 'outlet_id', 'store_id'], config('chattrn.inventory.columns.location_id'), false)
            . $this->colRow('inventory.columns.price', 'Price column', null, $invCols, ['price'], config('chattrn.inventory.columns.price'), false)
            . $this->colRow('inventory.columns.stock', 'Stock column', null, $invCols, ['stock', 'quantity'], config('chattrn.inventory.columns.stock'), false)
            . $this->colRow('inventory.columns.compare_price', 'Compare-at price column', 'Blank if none', $invCols, [], config('chattrn.inventory.columns.compare_price'), false)
            . $this->colRow('store.primary_location_id', 'Primary location id', 'Blank = first active location', [], [], config('chattrn.store.primary_location_id'), false)
        );

        $html .= $this->card('Store', null,
            $this->cat('Required')
            . $this->colRow('store.currency', 'Currency code', null, [], [], config('chattrn.store.currency', 'BDT'), true)
            . $this->colRow('store.delivery_mode', 'Delivery mode', '"zone" or "outlet"', [], [], config('chattrn.store.delivery_mode', 'zone'), true)
        );

        $html .= '</form>';

        $previewUrl = preg_replace('#/map$#', '/map/preview', request()->path()) . '?token=' . $token;
        $html .= '<section class="card">'
            . '<div class="card-head"><div class="titles"><h2>Preview</h2></div>'
            . '<a href="/' . e($previewUrl) . '" style="font:600 13px/20px Inter,sans-serif;color:var(--accent);" onclick="chattrnPreview(event)">Run against a real product + order &rarr;</a></div>'
            . '<pre id="preview-out" style="display:none;"></pre>'
            . '</section>';

        $html .= '</div>'; // .panel-body

        $html .= '<div class="footer-bar">'
            . '<a class="btn btn-secondary" href="/chattrn/setup/register?token=' . $token . '">Back</a>'
            . '<button type="submit" form="mapForm" class="btn">Save mapping &amp; continue</button>'
            . '</div>';

        $html .= '<script>
function chattrnPreview(e) {
    e.preventDefault();
    fetch(e.currentTarget.href).then(r => r.json()).then(data => {
        const out = document.getElementById("preview-out");
        out.style.display = "block";
        out.textContent = JSON.stringify(data, null, 2);
    });
}
</script>';

        return SetupLayout::page('Map your tables', $html);
    }

    private function card(string $title, ?string $hint, string $rowsHtml): string
    {
        $hintHtml = $hint ? '<p class="section-hint">' . $hint . '</p>' : '';

        return '<section class="card"><div class="card-title"><h2>' . $title . '</h2>' . $hintHtml . '</div>' . $rowsHtml . '</section>';
    }

    private function cat(string $label): string
    {
        return '<p class="cat">' . e($label) . '</p>';
    }

    private function row(string $fieldHtml, string $title, ?string $hint, string $statusText, string $statusClass, ?string $statusDetail = null): string
    {
        $hintHtml = $hint ? '<p class="h">' . e($hint) . '</p>' : '';
        $detailHtml = $statusDetail ? '<p class="d">' . e($statusDetail) . '</p>' : '';

        return '<div class="row">'
            . '<div class="row-label"><p class="t">' . e($title) . '</p>' . $hintHtml . '</div>'
            . '<div class="row-field">' . $fieldHtml . '</div>'
            . '<div class="row-status"><p class="s ' . $statusClass . '">' . $statusText . '</p>' . $detailHtml . '</div>'
            . '</div>';
    }

    // $models is Introspector::models() — used to report a column count once a model is
    // chosen, the same "14 columns found" detail the Figma frame shows. $wasSaved tells apart
    // a value that's already been saved (chattrn_settings or config/chattrn.php) from one
    // Introspector just guessed this request, so the status column can say which.
    private function modelRow(string $name, string $label, ?string $hint, array $options, ?string $selected, bool $required, array $models, bool $wasSaved = false): string
    {
        $opts = $required ? ['' => '&mdash; choose &mdash;'] : ['' => '&mdash; none &mdash;'];
        foreach (array_unique($options) as $class) {
            $opts[$class] = $class;
        }
        if ($selected && ! isset($opts[$selected])) {
            $opts[$selected] = $selected . ' (not found &mdash; check it exists)';
        }

        $select = '<select name="' . e($name) . '">';
        foreach ($opts as $value => $text) {
            $sel = ($value === ($selected ?? '')) ? ' selected' : '';
            $select .= '<option value="' . e($value) . '"' . $sel . '>' . $text . '</option>';
        }
        $select .= '</select>';

        if ($selected) {
            $detail = isset($models[$selected]) ? count($models[$selected]) . ' columns found' : null;
            return $this->row($select, $label, $hint, ($wasSaved ? '&#10003; Matched' : '&#10003; Guessed'), 'ok', $detail);
        }

        return $this->row($select, $label, $hint, $required ? '! Not matched' : 'Not added', $required ? 'warn' : 'muted', $required ? 'pick a model' : null);
    }

    private function colRow(string $name, string $label, ?string $hint, array $columns, array $candidates, ?string $savedValue, bool $required): string
    {
        $guessed = $savedValue ?? ($candidates ? Introspector::guessColumn($columns, $candidates) : null);
        $input = '<input type="text" name="' . e($name) . '" value="' . e($guessed ?? '') . '">';

        if ($guessed) {
            $label2 = $savedValue ? '&#10003; Matched' : '&#10003; Guessed';
            return $this->row($input, $label, $hint, $label2, 'ok');
        }

        return $this->row($input, $label, $hint, $required ? '! Not matched' : 'Not added', $required ? 'warn' : 'muted', $required ? 'no match found' : null);
    }
}
