<?php

namespace App\Jobs;

use App\Models\Forward;
use App\Social;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostToSocial implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int
     */
    protected int $messageId;

    /**
     * @var Forward
     */
    protected Forward $forward;

    /**
     * @var string
     */
    protected string $text = '';

    /**
     * @var array
     */
    protected array $media = [];

    /**
     * @var int
     */
    public int $timeout = 180;

    /**
     * Create a new job instance.
     */
    public function __construct(int $messageId, Forward $forward, string $text = '', array $media = [])
    {
        $this->messageId = $messageId;
        $this->forward = $forward;
        $this->text = $text;
        $this->media = $media;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $socialClass = Forward::CONNECTIONS[$this->forward->to_connection];
            /** @var Social $social */
            $social = new $socialClass($this->forward->to_id);
            $resultResponse = $social->post($this->text ?? '', $this->media);
            Log::info($this->messageId . ': ' . json_encode($resultResponse, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            Log::error($this->messageId . ': ' . $e->getMessage());
        }
    }
}
