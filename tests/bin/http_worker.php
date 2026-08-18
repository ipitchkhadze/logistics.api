<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);

$inputPath = $argv[1] ?? '';
$outputPath = $argv[2] ?? '';
$barrierPath = $argv[3] ?? '';

if ($inputPath === '' || $outputPath === '' || $barrierPath === '') {
    fwrite(STDERR, "Usage: http_worker.php <input.json> <output.json> <barrier>\n");
    exit(1);
}

$deadline = microtime(true) + 10;

while (! is_file($barrierPath)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Timed out waiting for start barrier\n");
        exit(2);
    }

    usleep(1000);
}

set_time_limit(20);

$payload = json_decode((string) file_get_contents($inputPath), true);

if (! is_array($payload)) {
    fwrite(STDERR, "Invalid request payload\n");
    exit(1);
}

$server = [
    'HTTP_ACCEPT' => 'application/json',
];

foreach (($payload['headers'] ?? []) as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
}

$request = Request::create(
    (string) ($payload['uri'] ?? '/'),
    (string) ($payload['method'] ?? 'GET'),
    [],
    [],
    [],
    $server,
);

$response = $kernel->handle($request);

file_put_contents($outputPath, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_THROW_ON_ERROR));

$kernel->terminate($request, $response);
