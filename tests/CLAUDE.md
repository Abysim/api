# Testing Conventions

## Running Tests
- All unit tests: `p vendor/bin/phpunit --testsuite=Unit --no-coverage`
- Single file: `p vendor/bin/phpunit tests/Unit/Jobs/CleanFreeNewsContentJobTest.php --no-coverage`
- Single test: `p vendor/bin/phpunit --filter test_handle_cleans_content --no-coverage`
- Live API tests (skipped by default): `SKIP_LIVE_API_TESTS=false p vendor/bin/phpunit tests/Feature/Services/News/LiveApiTest.php --no-coverage`

## Test Structure
- `tests/Unit/` — No DB, no HTTP; mock everything with `Http::fake()`, Mockery, `OpenAI::fake()`
- `tests/Feature/` — May hit real APIs (guarded by env flags) or use Laravel test helpers
- Tests using `Mockery::mock('alias:')` MUST use `#[RunTestsInSeparateProcesses]` and `#[PreserveGlobalState(false)]` attributes

## OpenAI Mocking
- Mock successful response:
  ```php
  use OpenAI\Laravel\Facades\OpenAI;
  use OpenAI\Responses\Chat\CreateResponse;

  OpenAI::fake([
      CreateResponse::fake(['choices' => [['message' => ['content' => 'cleaned text']]]]),
  ]);
  ```
- Mock exception: `OpenAI::fake([new \RuntimeException('error')])`
- Do NOT use `OpenAI::shouldReceive()` — `ChatTestResource` is `final` and cannot be mocked by Mockery

## Model Mocking with save()
- When testing code that calls `$model->save()`, do NOT use plain `(object)` stdClass — `save()` will throw inside `catch(\Throwable)` blocks, making tests pass for wrong reasons
- Use anonymous classes:
  ```php
  $obj = new class extends \stdClass {
      public function save(): bool { return true; }
  };
  ```
- Plain stdClass is fine for early-return test paths where `save()` is never reached
