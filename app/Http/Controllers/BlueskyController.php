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
     * @param Request $request
     *
     * @return mixed
     * @throws JsonException
     */
    public function index(Request $request): mixed
    {
        Log::info('Request:' . json_encode($request->all()));

        $connection = BlueskyConnection::query()
            ->where('handle', '=', $request->handle)
            ->where('secret', '=', $request->secret)
            ->first();

        if ($connection) {
            $bluesky = new Bluesky($connection);
            $response = $bluesky->post($request->text,  $request->image ? ['url' => $request->image] : []);

            Log::info('Response:' . json_encode($response));

            return $response;
        }

        return $request->all();
    }
}
