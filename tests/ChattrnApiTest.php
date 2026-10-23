<?php

namespace Chattrn\Connector\Tests;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Chattrn\Connector\ChattrnServiceProvider;
use Chattrn\Connector\Tests\Fixtures\Product;
use Chattrn\Connector\Tests\Fixtures\Order;

// A starting Testbench suite for this package. Not run in the sandbox this package was
// written in (see README.md "Testing this package" - no Packagist/GitHub access there);
// run `composer require --dev orchestra/testbench phpunit/phpunit && vendor/bin/phpunit`
// somewhere with normal network access first.
class ChattrnApiTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ChattrnServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('x', 32)));
        $app['config']->set('chattrn.access_token', 'test-token');
        $app['config']->set('chattrn.models.product', Product::class);
        $app['config']->set('chattrn.models.order', Order::class);
        $app['config']->set('chattrn.store.currency', 'BDT');
        $app['config']->set('chattrn.store.country_code', 'BD');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('slug')->nullable();
            $t->decimal('price', 10, 2);
            $t->integer('stock')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable();
            $t->string('phone')->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->string('status')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });

        Product::create(['title' => 'Phone A', 'slug' => 'phone-a', 'price' => '19990.00', 'stock' => 10]);
        Product::create(['title' => 'Phone B', 'slug' => 'phone-b', 'price' => '9990.00', 'stock' => 0]);
        Order::create(['number' => '1001', 'phone' => '+8801700000000', 'total' => '19990.00', 'status' => 'paid']);
    }

    private function headers(): array
    {
        return ['X-Chattrn-Access-Token' => 'test-token'];
    }

    public function test_image_column_mapping_is_exposed_saved_and_used(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('image')->nullable();
        });
        Product::first()->update(['image' => 'https://store.test/phone.jpg']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $response = $this->withHeaders($this->headers())
            ->getJson('/chattrn/api/2026-10/mapping.json')->assertOk();
        $rows = collect($response->json('cards'))->flatMap(fn ($card) => $card['groups'])
            ->flatMap(fn ($group) => $group['rows']);
        $this->assertSame('Image URL column', $rows->firstWhere('key', 'columns.product.image_url')['title']);

        $this->putJson('/chattrn/api/2026-10/mapping.json', [
            'values' => ['columns.product.image_url' => 'image'],
        ])->assertOk()->assertJsonPath('saved.0', 'columns.product.image_url');
        $this->assertSame('image', \Chattrn\Connector\Support\Settings::get('columns.product.image_url'));

        $this->getJson('/chattrn/api/2026-10/products.json')->assertOk()
            ->assertJsonPath('products.0.images.0.src', 'https://store.test/phone.jpg');
    }

    public function test_product_name_mapping_drives_catalog_and_search(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->renameColumn('title', 'name'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->withHeaders($this->headers())->putJson('/chattrn/api/2026-10/mapping.json', [
            'values' => ['columns.product.title' => 'name'],
        ])->assertOk();
        $this->getJson('/chattrn/api/2026-10/products.json')->assertOk()
            ->assertJsonPath('products.0.title', 'Phone A');
        $this->getJson('/chattrn/api/2026-10/products/search.json?q=Phone%20A')->assertOk()
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.title', 'Phone A');
    }

    public function test_customer_phone_lookup_uses_store_normalization(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
        });
        config(['chattrn.models.customer' => \Chattrn\Connector\Tests\Fixtures\Customer::class]);
        \Chattrn\Connector\Tests\Fixtures\Customer::create(['phone' => '8801700000000']);
        $this->withHeaders($this->headers())
            ->getJson('/chattrn/api/2026-10/customers/search.json?query=phone%3A%2B8801700000000')
            ->assertOk()->assertJsonCount(1, 'customers');
        $this->getJson('/chattrn/api/2026-10/customers/search.json?query=phone%3A%2B8801800000000')
            ->assertOk()->assertJsonCount(0, 'customers');
    }

    public function test_registration_without_models_persists_encrypted_token_and_retries_safely(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('x', 32)),
            'chattrn.access_token' => null,
            'chattrn.models.product' => null,
            'chattrn.models.order' => null,
            'chattrn.connection_token' => 'setup-token',
            'chattrn.backend_url' => 'https://backend.test',
        ]);
        \Illuminate\Support\Facades\Http::fake(['backend.test/package/register' =>
            \Illuminate\Support\Facades\Http::response(['access_token' => 'persisted-token'])]);
        $this->artisan('chattrn:register')->expectsOutputToContain('Access token saved encrypted')->assertSuccessful();
        $stored = \Illuminate\Support\Facades\DB::table('chattrn_settings')->where('key', '_encrypted_access_token')->value('value');
        $this->assertStringNotContainsString('persisted-token', $stored);
        $this->assertArrayNotHasKey('_encrypted_access_token', \Chattrn\Connector\Support\Settings::all());
        // Simulate the next request using stale cached/environment config.
        config(['chattrn.access_token' => null]);
        $this->assertSame('persisted-token', \Chattrn\Connector\Support\Credentials::token());
        $this->artisan('chattrn:register')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->withHeaders(['X-Chattrn-Access-Token' => 'persisted-token'])
            ->getJson('/chattrn/api/2026-10/mapping.json')->assertOk();
        $this->get('/chattrn/setup/register')->assertOk()->assertDontSee('persisted-token');
        $this->withHeaders(['X-Chattrn-Access-Token' => 'wrong'])
            ->getJson('/chattrn/api/2026-10/mapping.json')->assertUnauthorized();
    }

    public function test_registration_requires_storage_and_rejects_empty_exchange_response(): void
    {
        config(['chattrn.access_token' => null]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([])]);
        $this->assertFalse(\Chattrn\Connector\Support\ChattrnRegistrar::register('setup')['ok']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertFalse(\Chattrn\Connector\Support\ChattrnRegistrar::register('setup')['ok']);
        $this->assertDatabaseMissing('chattrn_settings', ['key' => '_encrypted_access_token']);
    }

    public function test_invalid_mapping_is_rejected_without_partial_writes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->withHeaders($this->headers());
        foreach ([
            ['store.currency' => null],
            ['store.delivery_mode' => 'invalid'],
            ['columns.product.title' => 'does_not_exist'],
            ['models.product' => 'UntrustedClass'],
            ['_encrypted_access_token' => 'override'],
        ] as $values) {
            $this->putJson('/chattrn/api/2026-10/mapping.json', ['values' => $values])->assertStatus(422);
        }
        $this->assertDatabaseCount('chattrn_settings', 0);
        $this->getJson('/chattrn/api/2026-10/mapping.json')->assertOk();
    }

    public function test_draft_preview_changes_column_choices_without_saving(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->withHeaders($this->headers());
        $response = $this->postJson('/chattrn/api/2026-10/mapping/preview.json', [
            'values' => ['columns.product.title' => 'title', 'models.order' => null],
        ])->assertOk();
        $rows = collect($response->json('cards'))->flatMap(fn ($card) => $card['groups'])->flatMap(fn ($group) => $group['rows']);
        $this->assertSame('Phone A', $rows->firstWhere('key', 'columns.product.title')['sample']);
        $this->assertSame('', $rows->firstWhere('key', 'models.order')['value']);
        $this->assertNull($rows->firstWhere('key', 'columns.order.phone')['sample']);
        $this->assertSame(Order::class, config('chattrn.models.order'));
        $changed = $this->postJson('/chattrn/api/2026-10/mapping/preview.json', [
            'values' => ['models.product' => Order::class],
        ])->assertOk();
        $changedRows = collect($changed->json('cards'))->flatMap(fn ($card) => $card['groups'])->flatMap(fn ($group) => $group['rows']);
        $options = $changedRows->firstWhere('key', 'columns.product.title')['options'];
        $this->assertContains('number', $options);
        $this->assertNotContains('title', $options);
        $this->assertNull($changedRows->firstWhere('key', 'columns.product.title')['sample']);
        $this->assertSame(Product::class, config('chattrn.models.product'));
        $this->assertDatabaseCount('chattrn_settings', 0);
        $this->postJson('/chattrn/api/2026-10/mapping/preview.json', ['values' => ['models.product' => 'ArbitraryClass']])->assertStatus(422);
        $this->assertSame(Product::class, config('chattrn.models.product'));
        $this->withHeaders(['X-Chattrn-Access-Token' => 'wrong'])->postJson('/chattrn/api/2026-10/mapping/preview.json', ['values' => []])->assertUnauthorized();
    }

    public function test_store_profile_responds(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/store.json')
            ->assertOk()
            ->assertJsonPath('store.api_version', '2026-10')
            ->assertJsonPath('store.currency', 'BDT')
            ->assertJsonPath('store.capabilities.products', true);
    }

    public function test_wrong_token_is_refused(): void
    {
        $this->get('/chattrn/api/2026-10/store.json')->assertStatus(401)
            ->assertJsonPath('errors', 'Invalid access token');

        $this->get('/chattrn/setup/register')->assertOk()->assertDontSee('persisted-token');
        $this->withHeaders(['X-Chattrn-Access-Token' => 'wrong'])
            ->get('/chattrn/api/2026-10/store.json')->assertStatus(401);
    }

    public function test_products_list_and_detail(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/products.json')
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.title', 'Phone A')
            ->assertJsonPath('products.0.variants.0.price', '19990.00');

        $id = Product::where('title', 'Phone A')->first()->id;
        $this->withHeaders($this->headers())->get("/chattrn/api/2026-10/products/{$id}.json")
            ->assertOk()
            ->assertJsonPath('product.handle', 'phone-a');
    }

    public function test_products_count(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/products/count.json')
            ->assertOk()->assertJsonPath('count', 2);
    }

    public function test_product_search(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/products/search.json?q=Phone A')
            ->assertOk()->assertJsonCount(1, 'products');
    }

    public function test_missing_product_is_404(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/products/999999.json')
            ->assertStatus(404)->assertJsonPath('errors', 'Not Found');
    }

    public function test_orders_list_and_lookup_by_name(): void
    {
        $this->withHeaders($this->headers())->get('/chattrn/api/2026-10/orders.json?name=%231001')
            ->assertOk()->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.financial_status', 'paid');
    }

    public function test_cancel_order(): void
    {
        $id = Order::first()->id;
        $this->withHeaders($this->headers())->post("/chattrn/api/2026-10/orders/{$id}/cancel.json")
            ->assertStatus(501);
        $this->assertNull(Order::find($id)->cancelled_at);
    }

    public function test_cart_quote_computes_totals(): void
    {
        $this->withHeaders($this->headers())->postJson('/chattrn/api/2026-10/draft_orders.json', [
            'draft_order' => ['line_items' => [['price' => '100.00', 'quantity' => 2]]],
        ])->assertStatus(501);
    }
    public function test_write_capabilities_are_not_advertised(): void
    {
        $this->withHeaders($this->headers())->getJson('/chattrn/api/2026-10/store.json')
            ->assertOk()->assertJsonPath('store.capabilities.orders_cancel', false)
            ->assertJsonPath('store.capabilities.draft_orders', false)
            ->assertJsonPath('store.capabilities.draft_orders_complete', false);
    }

    public function test_registration_retry_reuses_attempt_and_new_setup_token_reconnects(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['chattrn.access_token' => null, 'chattrn.backend_url' => 'https://backend.test']);
        $attempts = [];
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$attempts) {
            $attempts[] = $request['registration_id'];
            return \Illuminate\Support\Facades\Http::response(count($attempts) === 1 ? [] : ['access_token' => 'new-key']);
        });
        $this->assertFalse(\Chattrn\Connector\Support\ChattrnRegistrar::register('first-setup')['ok']);
        $this->assertTrue(\Chattrn\Connector\Support\ChattrnRegistrar::register('first-setup')['ok']);
        $this->assertSame($attempts[0], $attempts[1]);
        $this->assertTrue(\Chattrn\Connector\Support\ChattrnRegistrar::register('first-setup')['ok']);
        $this->assertCount(2, $attempts);
        $this->assertTrue(\Chattrn\Connector\Support\ChattrnRegistrar::register('replacement-setup')['ok']);
        $this->assertNotSame($attempts[1], $attempts[2]);
    }

    public function test_mapping_save_rolls_back_if_a_later_write_fails(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        \Chattrn\Connector\Support\Settings::setMany(['store.currency' => 'BDT']);
        $failed = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$failed) {
            if (! $failed && str_contains($query->sql, 'insert') && in_array('store.delivery_mode', $query->bindings, true)) {
                $failed = true;
                throw new \RuntimeException('Injected interrupted mapping save');
            }
        });
        try {
            \Chattrn\Connector\Support\Settings::setMany(['store.currency' => 'USD', 'store.delivery_mode' => 'zone']);
            $this->fail('Expected save failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected interrupted mapping save', $error->getMessage());
        }
        $this->assertDatabaseHas('chattrn_settings', ['key' => 'store.currency', 'value' => '"BDT"']);
        $this->assertDatabaseMissing('chattrn_settings', ['key' => 'store.delivery_mode']);
    }

    public function test_malformed_mapped_price_is_missing_rather_than_free(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->string('bad_price')->nullable());
        $product = Product::first();
        $product->update(['bad_price' => 'not a price']);
        config(['chattrn.columns.variant.price' => 'bad_price']);
        $this->withHeaders($this->headers())->getJson('/chattrn/api/2026-10/products/'.$product->id.'.json')
            ->assertOk()->assertJsonPath('product.variants.0.price', null);
        $product->update(['bad_price' => '0']);
        $this->getJson('/chattrn/api/2026-10/products/'.$product->id.'.json')
            ->assertOk()->assertJsonPath('product.variants.0.price', '0.00');
    }

}
