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
     * @var Post|null
     */
    protected ?Post $reply = null;

    /**
     * @var Post|null
     */
    protected ?Post $quote = null;

    /**
     * @var int
     */
    public int $timeout = 180;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $messageId,
        Forward $forward,
        array $textData = [],
        array $media = [],
        ?Post $reply = null,
        ?Post $quote = null
    ) {
        $this->messageId = $messageId;
        $this->forward = $forward;
        $this->textData = $textData;
        $this->media = $media;
        $this->reply = $reply;
        $this->quote = $quote;
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
                'parent_post_id' => $this->reply->post_id ?? $this->messageId,
                'root_post_id' => $this->reply->root_post_id ?? $this->messageId,
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
                /** @var Post $post */
                $post = Post::query()->updateOrCreate($postData);
                $this->media[$messageId]['post_id'] = $post->id;
                $parentId = $messageId;
            }

            $socialClass = Forward::CONNECTIONS[$this->forward->to_connection];
            /** @var Social $social */
            $social = new $socialClass($this->forward->to_id);

            $reply = null;
            $root = null;
            $quote = null;
            if (!empty($this->reply)) {
                /** @var Post $replyPost */
                $replyPost = $this->reply->forwards($this->forward->to_connection)->first();
                if (!empty($replyPost)) {
                    if ($this->forward->to_connection == 'bluesky') {
                        $reply = json_decode($replyPost->post_id);
                        $root = json_decode($replyPost->root_post_id);
                    } else {
                        $reply = $replyPost->post_id;
                        $root = $replyPost->root_post_id;
                    }
                }
            }
            if (!empty($this->quote)) {
                /** @var Post $quotePost */
                $quotePost = $this->quote->forwards($this->forward->to_connection, true)->first();
                if (!empty($quotePost)) {
                    if ($this->forward->to_connection == 'bluesky') {
                        $quote = json_decode($quotePost->post_id);
                    } else {
                        $quote = $quotePost->post_id;
                    }
                }
            }

            $resultResponse = $social->post($this->textData, $this->media, $reply, $root, $quote);
            Log::info($this->messageId . ': ' . json_encode($resultResponse, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            Log::error($this->messageId . ': ' . $e->getMessage());
        }
    }
}
