<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\ProcessIdProcessor;

class AddProcessId
{
    public function __invoke(Logger $logger): void
    {
        $formatter = new LineFormatter(
            "[%datetime%] [%extra.process_id%] %channel%.%level_name%: %message% %context%\n",
            null,
            true,
            true,
        );

        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new ProcessIdProcessor());
            $handler->setFormatter($formatter);
        }
    }
}
