<?php

namespace Chattrn\Connector\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Chattrn\Connector\Support\MappingSchema;

// Serves the merchant's own Eloquent schema to the Chattrn dashboard's "Map your tables"
// step, and takes the merchant's corrections back. Lives behind the same
// X-Chattrn-Access-Token gate as the rest of the API contract (VerifyChattrnToken), because
// a store's table and column names are its own business - this is never called from a
// customer's browser, only Chattrn server to package.
//
// The browser-based /chattrn/setup/map screen in this package covers the same ground for a
// merchant who would rather not use the dashboard; both write through Settings, so whichever
// one is used last wins and neither has its own copy of the mapping.
class ChattrnMappingController extends Controller
{
    public function show()
    {
        return response()->json(['cards' => MappingSchema::cards()]);
    }

    public function save(Request $request)
    {
        $values = $request->input('values');
        if (! is_array($values)) {
            return response()->json([
                'error' => ['code' => 'values_required', 'message' => 'Send {"values": {"models.product": "App\\\\Models\\\\Product", ...}}.'],
            ], 422);
        }

        try {
            $saved = MappingSchema::save($values);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'save_failed', 'message' => 'Could not save the mapping: ' . $e->getMessage()],
            ], 500);
        }

        return response()->json(['ok' => true, 'saved' => array_keys($saved), 'cards' => MappingSchema::cards()]);
    }
}
