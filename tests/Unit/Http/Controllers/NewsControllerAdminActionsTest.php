<?php

namespace Tests\Unit\Http\Controllers;

use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
use App\Jobs\CleanNewsContentJob;
use Illuminate\Support\Facades\Queue;
use Longman\TelegramBot\Entities\ServerResponse;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class NewsControllerAdminActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mockTelegramRequest();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockTelegramRequest(): void
    {
        $okResponse = Mockery::mock(ServerResponse::class);
        $okResponse->shouldReceive('isOk')->andReturn(true);
        $okResponse->shouldReceive('getResult')->andReturn(null);

        $mock = Mockery::mock('alias:Longman\TelegramBot\Request');
        $mock->shouldReceive('editMessageReplyMarkup')->andReturn($okResponse);
        $mock->shouldReceive('deleteMessage')->andReturn($okResponse);
        $mock->shouldReceive('sendMessage')->andReturn($okResponse);
    }

    private function makeNewsObject(array $attributes = []): object
    {
        $defaults = [
            'id' => 1,
            'status' => NewsStatus::PENDING_REVIEW,
            'platform' => 'FreeNews',
            'language' => 'uk',
            'is_content_cleaned' => false,
            'filename' => 'test.jpg',
            'message_id' => 12345,
            'media' => 'https://example.com/photo.jpg',
        ];

        $obj = new class extends \stdClass {
            public function save(): bool { return true; }
            public function deleteFile(): void { $this->filename = null; }
            public function loadMediaFile(): void {}
            public function getInlineKeyboard(): object { return new \Longman\TelegramBot\Entities\InlineKeyboard([]); }
            public function refresh(): static { return $this; }
        };

        foreach (array_merge($defaults, $attributes) as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    public function test_approve_with_null_message_sets_approved_status(): void
    {
        $model = $this->makeNewsObject(['is_content_cleaned' => true]);
        $controller = new NewsController();

        $controller->approve($model);

        $this->assertEquals(NewsStatus::APPROVED, $model->status);
    }

    public function test_approve_dispatches_clean_job_for_freenews_uk(): void
    {
        $model = $this->makeNewsObject([
            'platform' => 'FreeNews',
            'language' => 'uk',
            'is_content_cleaned' => false,
        ]);
        $controller = new NewsController();

        $controller->approve($model);

        Queue::assertPushed(CleanNewsContentJob::class, function ($job) {
            return true;
        });
    }

    public function test_approve_does_not_dispatch_clean_job_for_non_freenews(): void
    {
        $model = $this->makeNewsObject([
            'platform' => 'article',
            'language' => 'uk',
            'is_content_cleaned' => false,
        ]);
        $controller = new NewsController();

        $controller->approve($model);

        Queue::assertNotPushed(CleanNewsContentJob::class);
    }

    public function test_decline_with_null_message_sets_rejected_status(): void
    {
        $model = $this->makeNewsObject();
        $controller = new NewsController();

        $controller->decline($model);

        $this->assertEquals(NewsStatus::REJECTED_MANUALLY, $model->status);
    }

    public function test_offtopic_with_null_message_sets_offtopic_status(): void
    {
        $model = $this->makeNewsObject();
        $controller = new NewsController();

        $controller->offtopic($model);

        $this->assertEquals(NewsStatus::REJECTED_AS_OFF_TOPIC, $model->status);
    }

    public function test_cancel_with_null_message_sets_pending_review(): void
    {
        $model = $this->makeNewsObject(['status' => NewsStatus::APPROVED]);
        $controller = new NewsController();

        $controller->cancel($model);

        $this->assertEquals(NewsStatus::PENDING_REVIEW, $model->status);
    }
}
