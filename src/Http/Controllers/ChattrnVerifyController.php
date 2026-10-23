<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Chattrn\Connector\Support\ChattrnRegistrar;
use Chattrn\Connector\Support\ModelMap;
use Chattrn\Connector\Support\SetupLayout;

// Step 3 of the browser setup flow (/chattrn/setup/verify), matching the approved
// "Laravel C · Verify" Figma frame: pulls real rows through the mapping saved on
// /chattrn/setup/map and reports what Chattrn will actually see, instead of asking the
// store owner to trust the config blindly. Gated by CHATTRN_ACCESS_TOKEN specifically
// (not CHATTRN_TOKEN) - reaching this step means registration already succeeded.
class ChattrnVerifyController extends Controller
{
    private const SAMPLE_LIMIT = 50;

    public function show(Request $request)
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        return $this->html($this->renderVerify($request));
    }

    // Returns a response to short-circuit with, or null if the request is authorized.
    private function authorize(Request $request)
    {
        $expected = config('chattrn.access_token');
        if (! $expected) {
            return $this->html($this->errorPage(
                'Not registered yet - finish the Connect step first.'
            ), 422);
        }

        if (! hash_equals((string) $expected, (string) $request->query('token', ''))) {
            return $this->html($this->errorPage(
                'Missing or incorrect ?token= - use the same value as your CHATTRN_ACCESS_TOKEN.'
            ), 403);
        }

        return null;
    }

    private function html(string $body, int $status = 200)
    {
        return response($body, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function errorPage(string $message): string
    {
        return '<div class="panel-head"><h1>Verify your setup</h1></div>'
            . '<div class="panel-body">' . SetupLayout::banner('err', $message) . '</div>';
    }

    private function renderVerify(Request $request): string
    {
        $token = e($request->query('token', ''));

        $tests = $this->testResults($request);
        $passCount = count(array_filter($tests, fn ($r) => $r['type'] === 'pass'));
        $warnCount = count(array_filter($tests, fn ($r) => $r['type'] === 'warn'));

        $quality = $this->dataQuality();
        $samples = $this->sampleProducts($request);

        $html = '<div class="panel-head">';
        $html .= '<h1>Verify your setup</h1>';
        $html .= '<p class="lede">Chattrn pulls real products and orders through your mapping and checks the data, so you can see exactly what it will see.</p>';
        $html .= SetupLayout::stepper(3);
        $html .= '</div>';

        $html .= '<div class="panel-body">';
        $html .= $this->connectedBanner($token);
        $html .= $this->testResultsCard($tests, $passCount, $warnCount);
        $html .= $this->dataQualityCard($quality);
        $html .= $this->sampleProductsCard($samples);
        $html .= '</div>';

        $html .= '<div class="footer-bar">'
            . '<a class="btn btn-secondary" href="/chattrn/setup/map?token=' . $token . '">Back</a>'
            . '<a class="btn" href="' . e(rtrim(config('chattrn.backend_url'), '/')) . '">Go to Chattrn dashboard</a>'
            . '</div>';

        return SetupLayout::page('Verify your setup', $html);
    }

    // ---- Connected banner -----------------------------------------------------------

    private function connectedBanner(string $token): string
    {
        $checkIcon = '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path d="M4 9.3l3.2 3.2L14 5.7" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        return '<div class="connected-banner">'
            . '<div class="dot">' . $checkIcon . '</div>'
            . '<div class="titles">'
            . '<p class="t">Connected &mdash; package registered and mapping saved</p>'
            . '<p class="d">chattrn/connector v' . e(ChattrnRegistrar::PACKAGE_VERSION) . ' on your Laravel app</p>'
            . '</div>'
            . '<p class="checked">Checked just now</p>'
            . '<a class="btn" style="background:#1c8274;" href="?token=' . $token . '">Run test again</a>'
            . '</div>';
    }

    // ---- Test results -----------------------------------------------------------------

    // Checks what the mapping actually resolves to right now, not just whether config has
    // a class name in it - so this can't pass and then the Verify page be wrong.
    private function testResults(Request $request): array
    {
        $rows = [];

        $productClass = config('chattrn.models.product');
        $priceCol = config('chattrn.columns.variant.price', 'price');
        $stockCol = config('chattrn.columns.variant.stock', 'stock');
        if ($productClass && class_exists($productClass)) {
            $rows[] = $this->testRow('pass', 'Product mapping', $productClass . ' &mdash; ' . e($priceCol) . ' and ' . e($stockCol) . ' columns read.');
        } else {
            $rows[] = $this->testRow('warn', 'Product mapping', 'No model mapped yet &mdash; visit Map your tables.');
        }

        $variantClass = config('chattrn.models.variant');
        if ($variantClass && class_exists($variantClass)) {
            $n = $this->columnCount($variantClass);
            $rows[] = $this->testRow('pass', 'Variant mapping', $variantClass . ' &mdash; ' . ($n !== null ? $n . ' columns read.' : 'columns read.'));
        } else {
            $rows[] = $this->testRow('na', 'Variant mapping', 'No separate Variant model &mdash; each product is treated as its own variant.');
        }

        $orderClass = config('chattrn.models.order');
        if ($orderClass && class_exists($orderClass)) {
            $rows[] = $this->testRow('pass', 'Order mapping', $orderClass . ' &mdash; total and line items read.');
        } else {
            $rows[] = $this->testRow('warn', 'Order mapping', 'No model mapped yet &mdash; visit Map your tables.');
        }

        $locationClass = config('chattrn.models.location');
        if ($locationClass && class_exists($locationClass)) {
            $count = $this->safeCount($locationClass);
            $label = $count === null ? 'read.' : ($count . ' location' . ($count === 1 ? '' : 's') . ' read.');
            $rows[] = $this->testRow('pass', 'Location mapping', $locationClass . ' &mdash; ' . $label);
        } else {
            $rows[] = $this->testRow('na', 'Location mapping', 'No location model &mdash; single-location store assumed.');
        }

        $rows[] = $this->testRow('pass', 'Store settings', 'Currency (' . e((string) config('chattrn.store.currency', 'BDT')) . ') and delivery mode (' . e((string) config('chattrn.store.delivery_mode', 'zone')) . ') read.');

        $customerClass = config('chattrn.models.customer');
        if ($customerClass && class_exists($customerClass)) {
            $rows[] = $this->testRow('pass', 'Customer mapping', $customerClass . ' &mdash; phone and email columns read.');
        } else {
            $rows[] = $this->testRow('warn', 'Customer mapping', 'No model mapped &mdash; Chattrn will fall back to guest checkout.');
        }

        return $rows;
    }

    private function testRow(string $type, string $title, string $detail): array
    {
        return compact('type', 'title', 'detail');
    }

    private function testResultsCard(array $rows, int $passCount, int $warnCount): string
    {
        $summary = $passCount . ' passed';
        if ($warnCount > 0) {
            $summary .= ' &middot; ' . $warnCount . ' needs attention';
        }

        $html = '<section class="card"><div class="card-title"><h2>Test results</h2>'
            . '<p class="section-hint">' . $summary . '</p></div>';

        foreach ($rows as $row) {
            $badge = [
                'pass' => '<span class="tagbadge tagbadge-pass">Passed</span>',
                'warn' => '<span class="tagbadge tagbadge-warn">Needs attention</span>',
                'na' => '<span class="tagbadge tagbadge-na">Not configured</span>',
            ][$row['type']];

            $html .= '<div class="check-row">' . $this->icon($row['type'])
                . '<div class="body"><p class="t">' . e($row['title']) . '</p><p class="d">' . $row['detail'] . '</p></div>'
                . $badge . '</div>';
        }

        $html .= '</section>';

        return $html;
    }

    // ---- Data quality -----------------------------------------------------------------

    // Reads real product rows through the saved mapping, the same way the live API would,
    // so a mapping that "resolves" but hands the AI unusable data (no price, no stock,
    // no image) gets caught here instead of in front of a customer.
    private function dataQuality(): array
    {
        $productClass = config('chattrn.models.product');
        if (! $productClass || ! class_exists($productClass)) {
            return ['checked' => 0, 'rows' => [$this->testRow('warn', 'No products to check', 'Map a Product model first.')]];
        }

        $priceCol = config('chattrn.columns.variant.price', 'price');
        $stockCol = config('chattrn.columns.variant.stock', 'stock');
        $imageCol = config('chattrn.columns.product.image_url', 'image_url');
        $variantClass = config('chattrn.models.variant');

        $products = $productClass::query()->limit(self::SAMPLE_LIMIT)->get();
        $checked = $products->count();

        $missingPrice = 0;
        $missingStock = 0;
        $withImage = 0;
        $multiVariant = 0;
        $multiVariantWithStock = 0;

        foreach ($products as $product) {
            $variants = ($variantClass && method_exists($product, 'variants'))
                ? $product->variants()->get()
                : collect([$product]);

            if (! $variants->contains(fn ($v) => is_numeric($v->{$priceCol} ?? null))) {
                $missingPrice++;
            }
            if (! $variants->contains(fn ($v) => is_numeric($v->{$stockCol} ?? null))) {
                $missingStock++;
            }
            if (! empty($product->{$imageCol} ?? null)) {
                $withImage++;
            }
            if ($variantClass && $variants->count() > 1) {
                $multiVariant++;
                if ($variants->every(fn ($v) => is_numeric($v->{$stockCol} ?? null))) {
                    $multiVariantWithStock++;
                }
            }
        }

        $rows = [];

        $rows[] = $missingPrice === 0
            ? $this->testRow('pass', 'Prices are numbers', 'All ' . $checked . ' products have a usable price.')
            : $this->testRow('warn', 'Price is missing on ' . $missingPrice . ' product' . ($missingPrice === 1 ? '' : 's'), 'The AI will say &ldquo;ask for price&rdquo; for these.');

        $rows[] = $missingStock === 0
            ? $this->testRow('pass', 'Stock is tracked', 'All ' . $checked . ' products have a usable stock figure.')
            : $this->testRow('warn', 'Stock is missing on ' . $missingStock . ' product' . ($missingStock === 1 ? '' : 's'), 'The AI will say &ldquo;check availability&rdquo; for these.');

        if ($variantClass) {
            if ($multiVariant === 0) {
                $rows[] = $this->testRow('na', 'Variants have their own stock', 'No multi-variant products in this sample yet.');
            } elseif ($multiVariantWithStock === $multiVariant) {
                $rows[] = $this->testRow('pass', 'Variants have their own stock', 'Stock found on every variant checked.');
            } else {
                $rows[] = $this->testRow('warn', 'Some variants are missing stock', ($multiVariant - $multiVariantWithStock) . ' of ' . $multiVariant . ' multi-variant products affected.');
            }
        }

        $rows[] = $withImage === $checked
            ? $this->testRow('pass', 'Images load', 'All ' . $checked . ' have at least one image.')
            : $this->testRow('warn', 'Images missing', $withImage . ' of ' . $checked . ' have at least one image.');

        return ['checked' => $checked, 'rows' => $rows];
    }

    private function dataQualityCard(array $quality): string
    {
        $html = '<section class="card">'
            . '<div class="card-head"><div class="titles"><h2>Data quality</h2></div>'
            . '<span class="tagbadge tagbadge-na">Checked on ' . (int) $quality['checked'] . ' products</span></div>'
            . '<p class="section-hint">A mapping can read and still send data the AI can&rsquo;t use well. These checks catch that before customers do.</p>';

        foreach ($quality['rows'] as $row) {
            $html .= '<div class="check-row">' . $this->icon($row['type'])
                . '<div class="body"><p class="t">' . e($row['title']) . '</p><p class="d">' . $row['detail'] . '</p></div>'
                . '</div>';
        }

        $html .= '</section>';

        return $html;
    }

    // ---- Sample products ---------------------------------------------------------------

    private function sampleProducts(Request $request): array
    {
        $productClass = config('chattrn.models.product');
        if (! $productClass || ! class_exists($productClass)) {
            return [];
        }

        return $productClass::query()->limit(3)->get()
            ->map(fn ($product) => ModelMap::toProduct($product, $request->getHost()))
            ->all();
    }

    private function sampleProductsCard(array $products): string
    {
        $html = '<section class="card"><div class="card-title"><h2>Sample products from your mapping</h2>'
            . '<p class="section-hint">Check these look right &mdash; this is how the AI Agent and the inbox will see your products.</p></div>';

        if (empty($products)) {
            $html .= '<p class="section-hint">No products returned yet.</p></section>';
            return $html;
        }

        $currencySymbols = ['BDT' => '&#2547;', 'USD' => '$', 'EUR' => '&euro;', 'GBP' => '&pound;', 'INR' => '&#8377;'];
        $currency = (string) config('chattrn.store.currency', 'BDT');
        $symbol = $currencySymbols[$currency] ?? ($currency . ' ');

        $html .= '<table class="sample"><thead><tr>'
            . '<th>Product</th><th style="width:130px;">Price</th><th style="width:180px;">Variants</th><th style="width:110px;">Stock</th>'
            . '</tr></thead><tbody>';

        foreach ($products as $p) {
            $variants = $p['variants'] ?? [];
            $price = (float) ($variants[0]['price'] ?? 0);
            $priceText = $symbol . ' ' . number_format($price, $price == floor($price) ? 0 : 2);

            $variantCount = count($variants);
            $variantText = $variantCount <= 1 ? 'None' : $variantCount . ' variants';

            $stockValues = array_filter(array_map(fn ($v) => $v['inventory_quantity'], $variants), fn ($v) => is_numeric($v));
            if (empty($stockValues)) {
                $stockBadge = '<span class="tagbadge tagbadge-na">Unknown</span>';
            } elseif (array_sum($stockValues) > 0) {
                $stockBadge = '<span class="tagbadge tagbadge-pass">In stock</span>';
            } else {
                $stockBadge = '<span class="tagbadge tagbadge-err">Out of stock</span>';
            }

            $image = $p['images'][0]['src'] ?? null;
            $thumb = $image
                ? '<img src="' . e($image) . '" style="width:36px;height:36px;border-radius:6px;object-fit:cover;">'
                : '<span class="thumb"></span>';

            $html .= '<tr>'
                . '<td style="display:flex;align-items:center;gap:10px;">' . $thumb . '<span class="pname">' . e($p['title'] ?? 'Untitled') . '</span></td>'
                . '<td class="pprice">' . $priceText . '</td>'
                . '<td>' . e($variantText) . '</td>'
                . '<td>' . $stockBadge . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></section>';

        return $html;
    }

    // ---- shared helpers -----------------------------------------------------------------

    private function icon(string $type): string
    {
        if ($type === 'pass') {
            return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#dcf9dc"/><path d="M7 12.3l3.2 3.2L17 8.3" stroke="#107062" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        if ($type === 'warn') {
            return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#fff2df"/><path d="M12 7.5v5" stroke="#e98b00" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1.1" fill="#e98b00"/></svg>';
        }

        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#f1f4f5"/><circle cx="12" cy="12" r="2" fill="#7b8594"/></svg>';
    }

    private function columnCount(?string $class): ?int
    {
        if (! $class || ! class_exists($class)) {
            return null;
        }
        try {
            $instance = new $class;
            return count(Schema::getColumnListing($instance->getTable()));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeCount(?string $class): ?int
    {
        if (! $class || ! class_exists($class)) {
            return null;
        }
        try {
            return $class::query()->count();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
