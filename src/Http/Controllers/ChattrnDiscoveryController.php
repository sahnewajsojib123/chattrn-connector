<?php

namespace Chattrn\Connector\Http\Controllers;

use Chattrn\Connector\Support\ChattrnRegistrar;
use Illuminate\Http\JsonResponse;

class ChattrnDiscoveryController
{
    // Public installation metadata only: never read credentials, mappings, schema or customer data.
    public function show(): JsonResponse
    {
        return response()->json([
            'package' => 'chattrn/connector',
            'package_version' => ChattrnRegistrar::PACKAGE_VERSION,
            'framework' => 'Laravel',
            'api_version' => config('chattrn.api_version'),
            'api_path' => '/'.trim(config('chattrn.route_prefix'), '/'),
            'features' => ['mapping' => true, 'pairing_retry' => true],
        ])->header('Cache-Control', 'no-store');
    }
}
