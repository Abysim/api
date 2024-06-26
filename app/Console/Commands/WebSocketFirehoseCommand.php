<?php

namespace App\Console\Commands;

use Error;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Message\Message;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

class WebSocketFirehoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket-firehose';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connect to firehose';

    /**
     * Execute the console command.
     * @throws Throwable
     */
    public function handle(): void
    {
        $client = new Client("wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos");
        $client
            // Add standard middlewares
            ->addMiddleware(new CloseHandler())
            ->addMiddleware(new PingResponder())
            // Listen to incoming Text messages
            ->onText(function (Client $client, Connection $connection, Message $message) {
                // Act on incoming message
                Log::info("Got message: {$message->getContent()}");

            })
            ->onError(function (Client $client, ?Connection $connection, Message|Exception|Error $message) {
                // Act on incoming message
                Log::info("Got error: {$message->getContent()}");
            })
            ->onBinary(function (Client $client, Connection $connection, Message $message) {
                // Act on incoming message
                Log::info("Got binary: {$message->getContent()}");

            })
            ->start();
    }
}
