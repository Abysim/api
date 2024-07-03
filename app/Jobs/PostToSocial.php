<?php

namespace App\Jobs;

use App\Models\Forward;
use App\Models\Post;
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
    protected array $textData = [];

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
    public function __construct(int $messageId, Forward $forward, array $textData = [], array $media = [])
    {
        $this->messageId = $messageId;
        $this->forward = $forward;
        $this->textData = $textData;
        $this->media = $media;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $postData = [
                'connection' => $this->forward->from_connection,
                'connection_id' => $this->forward->from_id,
                'post_id' => $this->messageId,
                'parent_post_id' => $this->messageId,
                'root_post_id' => $this->messageId,
            ];
            /** @var Post $post */
            $post = Post::query()->updateOrCreate($postData);
            $parentId = $this->messageId;
            $this->textData['post_id'] = $post->id;
            foreach ($this->media as $messageId => $media) {
                if ($messageId == $this->messageId) {
                    $this->media[$messageId]['post_id'] = $this->textData['post_id'];

                    continue;
                }

                $postData['post_id'] = $messageId;
                $postData['parent_post_id'] = $parentId;
                $postData['root_post_id'] = $this->messageId;
                /** @var Post $post */
                $post = Post::query()->updateOrCreate($postData);
                $this->media[$messageId]['post_id'] = $post->id;
                $parentId = $messageId;
            }

            $socialClass = Forward::CONNECTIONS[$this->forward->to_connection];
            /** @var Social $social */
            $social = new $socialClass($this->forward->to_id);
            $resultResponse = $social->post($this->textData, $this->media);
            Log::info($this->messageId . ': ' . json_encode($resultResponse, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            Log::error($this->messageId . ': ' . $e->getMessage());
        }
    }
}
