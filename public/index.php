<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Run composer install first.']);
    exit(1);
}
require $autoload;

\Tbank\Invest\App::kernel()->run();
