<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ConcurrentSlotBookingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_five_concurrent_holds_with_different_keys_reserve_one_seat(): void
    {
        $slot = $this->createSlot(1, 1);

        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $jobs[] = [
                'method' => 'POST',
                'uri' => "/slots/{$slot->id}/hold",
                'headers' => ['Idempotency-Key' => (string) Str::uuid()],
            ];
        }

        $results = $this->concurrentRequests($jobs);
        $counts = array_count_values(array_column($results, 'status'));

        $this->assertSame(1, $counts[201] ?? 0, json_encode($results));
        $this->assertSame(4, $counts[409] ?? 0, json_encode($results));
        $this->assertSame(1, Hold::query()->where('slot_id', $slot->id)->where('status', HoldStatus::Held)->count());
        $this->assertSame(1, $slot->fresh()->remaining);
    }

    public function test_five_concurrent_requests_with_the_same_idempotency_key_create_one_hold(): void
    {
        $slot = $this->createSlot(5, 5);
        $key = (string) Str::uuid();

        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $jobs[] = [
                'method' => 'POST',
                'uri' => "/slots/{$slot->id}/hold",
                'headers' => ['Idempotency-Key' => $key],
            ];
        }

        $results = $this->concurrentRequests($jobs);
        $ids = [];

        foreach ($results as $result) {
            $this->assertContains($result['status'], [200, 201], json_encode($results));
            $this->assertIsArray($result['body']);
            $ids[] = $result['body']['id'] ?? null;
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, Hold::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, Hold::query()->where('slot_id', $slot->id)->count());
    }

    public function test_two_concurrent_confirms_cannot_oversell_remaining(): void
    {
        $slot = $this->createSlot(1, 1);

        $first = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $second = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $results = $this->concurrentRequests([
            ['method' => 'POST', 'uri' => "/holds/{$first->id}/confirm", 'headers' => []],
            ['method' => 'POST', 'uri' => "/holds/{$second->id}/confirm", 'headers' => []],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([200, 409], $statuses, json_encode($results));
        $this->assertSame(0, $slot->fresh()->remaining);
        $this->assertSame(1, Hold::query()->where('slot_id', $slot->id)->where('status', HoldStatus::Confirmed)->count());
        $this->assertSame(1, Hold::query()->where('slot_id', $slot->id)->where('status', HoldStatus::Held)->count());
    }

    public function test_concurrent_cancels_of_confirmed_hold_increment_remaining_once(): void
    {
        $slot = $this->createSlot(1, 1);

        $hold = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => HoldStatus::Confirmed,
            'expires_at' => now()->addMinutes(5),
        ]);

        $slot->update(['remaining' => 0]);

        $results = $this->concurrentRequests([
            ['method' => 'DELETE', 'uri' => "/holds/{$hold->id}", 'headers' => []],
            ['method' => 'DELETE', 'uri' => "/holds/{$hold->id}", 'headers' => []],
        ]);

        foreach ($results as $result) {
            $this->assertSame(200, $result['status'], json_encode($results));
        }

        $this->assertSame(1, $slot->fresh()->remaining);
        $this->assertSame(HoldStatus::Cancelled, $hold->fresh()->status);
    }

    public function test_concurrent_confirm_and_cancel_do_not_corrupt_remaining(): void
    {
        $slot = $this->createSlot(1, 1);

        $hold = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => (string) Str::uuid(),
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $results = $this->concurrentRequests([
            ['method' => 'POST', 'uri' => "/holds/{$hold->id}/confirm", 'headers' => []],
            ['method' => 'DELETE', 'uri' => "/holds/{$hold->id}", 'headers' => []],
        ]);

        $this->assertContains($results[0]['status'], [200, 409], json_encode($results));
        $this->assertContains($results[1]['status'], [200, 409], json_encode($results));

        $slot->refresh();
        $hold->refresh();

        $this->assertGreaterThanOrEqual(0, $slot->remaining);
        $this->assertLessThanOrEqual($slot->capacity, $slot->remaining);

        if ($hold->status === HoldStatus::Cancelled) {
            $this->assertSame(1, $slot->remaining);
        }

        if ($hold->status === HoldStatus::Confirmed) {
            $this->assertSame(0, $slot->remaining);
        }
    }

    /**
     * @param  list<array{method: string, uri: string, headers: array<string, string>}>  $jobs
     * @return list<array{status: int, body: mixed, exit: int, stderr: string}>
     */
    private function concurrentRequests(array $jobs): array
    {
        $barrier = sys_get_temp_dir().'/slot_barrier_'.bin2hex(random_bytes(8));
        $script = base_path('tests/bin/http_worker.php');
        $env = $this->workerEnvironment();
        $handles = [];

        try {
            foreach ($jobs as $index => $job) {
                $input = sys_get_temp_dir()."/slot_in_{$index}_".bin2hex(random_bytes(4)).'.json';
                $output = sys_get_temp_dir()."/slot_out_{$index}_".bin2hex(random_bytes(4)).'.json';
                file_put_contents($input, json_encode($job, JSON_THROW_ON_ERROR));

                $command = sprintf(
                    '%s %s %s %s %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($script),
                    escapeshellarg($input),
                    escapeshellarg($output),
                    escapeshellarg($barrier),
                );

                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path(), $env);

                $this->assertIsResource($process, "Failed to start worker {$index}");

                fclose($pipes[0]);
                $pipes[0] = null;
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $handles[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'input' => $input,
                    'output' => $output,
                    'closed' => false,
                    'stdout' => '',
                    'stderr' => '',
                ];
            }

            usleep(150_000);
            touch($barrier);

            $deadline = microtime(true) + 20;
            $results = [];

            foreach ($handles as $index => &$handle) {
                $this->awaitWorker($handle, $deadline, $index);
                $results[] = $this->collectWorkerResult($handle, $index);
            }
            unset($handle);

            return $results;
        } finally {
            $this->cleanupWorkers($handles, $barrier);
        }
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, input: string, output: string, closed: bool, stdout: string, stderr: string}  $handle
     */
    private function awaitWorker(array &$handle, float $deadline, int $index): void
    {
        while (true) {
            $handle['stdout'] .= $this->readPipe($handle['pipes'][1] ?? null);
            $handle['stderr'] .= $this->readPipe($handle['pipes'][2] ?? null);

            $status = proc_get_status($handle['process']);

            if ($status['running'] === false) {
                $this->reapWorker($handle, $status);

                return;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($handle['process'], 15);
                usleep(200_000);

                $status = proc_get_status($handle['process']);

                if ($status['running']) {
                    proc_terminate($handle['process'], 9);
                    usleep(50_000);
                    $status = proc_get_status($handle['process']);
                }

                $this->reapWorker($handle, $status);

                throw new RuntimeException(
                    "Worker {$index} timed out after 20s. exit={$handle['exit']} stderr={$handle['stderr']} stdout={$handle['stdout']}"
                );
            }

            usleep(10_000);
        }
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource|null>, stdout: string, stderr: string, closed: bool, exit?: int}  $handle
     * @param  array{running: bool, exitcode: int, signaled?: bool, termsig?: int}  $status
     */
    private function reapWorker(array &$handle, array $status): void
    {
        $handle['stdout'] .= $this->readPipe($handle['pipes'][1] ?? null);
        $handle['stderr'] .= $this->readPipe($handle['pipes'][2] ?? null);
        $this->closeWorkerPipes($handle);

        $handle['exit'] = $this->workerExitCode($status);
        $handle['closed'] = true;

        if (is_resource($handle['process'])) {
            proc_close($handle['process']);
        }
    }

    /**
     * @param  array{running?: bool, exitcode?: int, signaled?: bool, termsig?: int}  $status
     */
    private function workerExitCode(array $status): int
    {
        if (($status['signaled'] ?? false) === true) {
            return 128 + (int) ($status['termsig'] ?? 0);
        }

        if (array_key_exists('exitcode', $status) && (int) $status['exitcode'] !== -1) {
            return (int) $status['exitcode'];
        }

        return (int) ($status['exitcode'] ?? -1);
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, input: string, output: string, closed: bool, stdout: string, stderr: string, exit?: int}  $handle
     * @return array{status: int, body: mixed, exit: int, stderr: string}
     */
    private function collectWorkerResult(array $handle, int $index): array
    {
        $exit = $handle['exit'] ?? -1;
        $stderr = $handle['stderr'];

        if ($exit !== 0) {
            throw new RuntimeException(
                "Worker {$index} exited with code {$exit}. stderr={$stderr} stdout={$handle['stdout']}"
            );
        }

        if (! is_file($handle['output'])) {
            throw new RuntimeException(
                "Worker {$index} produced no output file {$handle['output']}. stderr={$stderr}"
            );
        }

        $raw = file_get_contents($handle['output']);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("Worker {$index} wrote an empty output file. stderr={$stderr}");
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                "Worker {$index} returned invalid JSON: {$raw}. stderr={$stderr}",
                previous: $exception
            );
        }

        if (! is_array($payload) || ! array_key_exists('status', $payload)) {
            throw new RuntimeException("Worker {$index} JSON is missing status: {$raw}");
        }

        return [
            'status' => (int) $payload['status'],
            'body' => $payload['body'] ?? null,
            'exit' => $exit,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param  list<array{process?: resource, pipes?: array<int, resource|null>, input?: string, output?: string, closed?: bool}>  $handles
     */
    private function cleanupWorkers(array $handles, string $barrier): void
    {
        foreach ($handles as $handle) {
            $this->closeWorkerPipes($handle);

            if (($handle['closed'] ?? false) !== true && isset($handle['process']) && is_resource($handle['process'])) {
                $status = proc_get_status($handle['process']);

                if ($status['running']) {
                    proc_terminate($handle['process'], 9);
                    usleep(50_000);
                }

                proc_close($handle['process']);
            }

            if (isset($handle['input'])) {
                $this->deleteFileIfExists($handle['input']);
            }

            if (isset($handle['output'])) {
                $this->deleteFileIfExists($handle['output']);
            }
        }

        $this->deleteFileIfExists($barrier);
    }

    /**
     * @param  array{pipes?: array<int, resource|null>}  $handle
     */
    private function closeWorkerPipes(array &$handle): void
    {
        if (! isset($handle['pipes']) || ! is_array($handle['pipes'])) {
            return;
        }

        foreach ($handle['pipes'] as $index => $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }

            $handle['pipes'][$index] = null;
        }
    }

    private function readPipe(mixed $pipe): string
    {
        if (! is_resource($pipe)) {
            return '';
        }

        $chunk = fread($pipe, 8192);

        return $chunk === false ? '' : $chunk;
    }

    private function deleteFileIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $environment = [];

        foreach (array_merge(getenv() ?: [], $_ENV) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }

        $mysql = config('database.connections.mysql');

        $environment['APP_ENV'] = 'testing';
        $environment['APP_KEY'] = (string) config('app.key');
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_HOST'] = (string) ($mysql['host'] ?? '127.0.0.1');
        $environment['DB_PORT'] = (string) ($mysql['port'] ?? '3306');
        $environment['DB_DATABASE'] = 'logistics_testing';
        $environment['DB_USERNAME'] = (string) ($mysql['username'] ?? 'logistics');
        $environment['DB_PASSWORD'] = (string) ($mysql['password'] ?? 'secret');
        $environment['CACHE_STORE'] = 'array';
        $environment['QUEUE_CONNECTION'] = 'sync';
        $environment['SESSION_DRIVER'] = 'array';
        $environment['PATH'] ??= '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return $environment;
    }

    private function createSlot(int $capacity, int $remaining): Slot
    {
        return Slot::query()->create([
            'capacity' => $capacity,
            'remaining' => $remaining,
        ]);
    }
}
