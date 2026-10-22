<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Holds the Product/Variant/Order/Customer/Inventory model-and-column MAPPING (never
// secrets - access_token/connection_token/backend_url stay in env(), see config/chattrn.php)
// as dot-path rows ('models.product', 'columns.variant.price', ...), each a JSON-encoded
// value. The point of a DB table instead of another config/chattrn.php rewrite: a mapping
// change takes effect on save, not on the next git push + host redeploy - the same reason
// Segment/Fivetran/Zapier-style field-mapping screens store mappings in their own database
// rather than in a file the integrator has to redeploy. See ChattrnServiceProvider, which
// overlays these onto config('chattrn.*') on every boot, and Settings, which reads/writes
// them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chattrn_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chattrn_settings');
    }
};
