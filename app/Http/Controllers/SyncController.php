<?php

namespace App\Http\Controllers;

use App\Support\SyncState;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    /**
     * Per-module change versions. Clients compare them with the previous
     * poll and re-fetch only the modules that changed.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => SyncState::versions()]);
    }
}
