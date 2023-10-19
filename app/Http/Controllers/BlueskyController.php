<?php

namespace App\Http\Controllers;

use App\Bluesky;
use App\Models\BlueskyConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;

class BlueskyController extends Controller
{

    /**
     * @throws JsonException
     */
    public function index(Request $request)
    {
        Log::info('Request:' . json_encode($request->all()));

        $connection = BlueskyConnection::where('handle', $request->handle)
            ->where('secret', $request->secret)
            ->first();

        if ($connection) {
            $bluesky = new Bluesky($connection);
            $response = $bluesky->post($request);

            Log::info('Response:' . json_encode($response));

            return $response;
        }

        return [];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
