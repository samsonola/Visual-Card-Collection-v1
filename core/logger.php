<?php

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

function get_logger(string $channel = 'app'): Logger {
    static $loggers = [];

    if (isset($loggers[$channel])) {
        return $loggers[$channel];
    }

    $logger = new Logger($channel);

    $handler = new RotatingFileHandler(
        __DIR__ . '/../storage/logs' . $channel . '.log',
        30, // keep 30 days
        Logger::DEBUG
    );


    $formatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
        "Y-m-d H:i:s"
    );

    $handler->setFormatter($formatter);
    $logger->pushHandler($handler);

    $loggers[$channel] = $logger;

    return $logger;
}

function log_info(string $channel, string $message, array $context = []) {
    get_logger($channel)->info($message, $context);
}

function log_error(string $channel, string $message, array $context = []) {
    get_logger($channel)->error($message, $context);
}

function log_debug(string $channel, string $message, array $context = []) {
    get_logger($channel)->debug($message, $context);
}

function log_warning(string $channel, string $message, array $context = []) {
    get_logger($channel)->warning($message, $context);
}