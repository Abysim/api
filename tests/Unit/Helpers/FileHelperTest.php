<?php

namespace Tests\Unit\Helpers;

use App\Helpers\FileHelper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FileHelperTest extends TestCase
{
    public function test_get_url_throws_for_file_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only http/https URLs are supported');

        FileHelper::getUrl('file:///etc/passwd');
    }

    public function test_get_url_throws_for_ftp_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileHelper::getUrl('ftp://host/path');
    }

    public function test_get_url_throws_for_bare_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileHelper::getUrl('/var/www/secret');
    }

    public function test_get_url_throws_for_data_uri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileHelper::getUrl('data:text/plain;base64,SGVsbG8=');
    }

    public function test_get_url_accepts_http_scheme(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);

        $result = FileHelper::getUrl('http://example.com/page');

        $this->assertSame('OK', $result);
    }

    public function test_get_url_accepts_https_scheme(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);

        $result = FileHelper::getUrl('https://example.com/page');

        $this->assertSame('OK', $result);
    }
}
