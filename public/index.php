<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use MaxBot\Feedback\Bot\FeedbackBot;

try {
    $bot = new FeedbackBot();
    $bot->run();
} catch (Throwable $e) {
    // Prevent leaking internals to the webhook caller
    http_response_code(500);
    error_log('[MaxBot Feedback] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
