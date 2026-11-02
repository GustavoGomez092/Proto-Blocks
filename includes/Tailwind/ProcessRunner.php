<?php
/**
 * Process Runner - Runs a shell command with a wall-clock deadline.
 *
 * exec() has no timeout: a hung CLI or a slow network install holds a PHP
 * worker until the SAPI kills it (and FPM's limit is CPU-time based, so a
 * blocked child can hold it indefinitely). This runner prefers proc_open()
 * with stream_select() and a deadline, falls back to coreutils `timeout`
 * when proc_open is disabled, and only as a last resort runs exec() bare.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

final class ProcessRunner
{
    /**
     * Exit code reported when the deadline is hit (mirrors coreutils `timeout`).
     */
    public const EXIT_TIMEOUT = 124;

    /**
     * Run a command and capture stdout + stderr.
     *
     * @param string      $cmd            Fully escaped command line.
     * @param int         $timeoutSeconds Wall-clock limit; <= 0 means no limit.
     * @param string|null $cwd            Working directory for the child.
     * @return array{exit: int, output: string, timed_out: bool}
     */
    public static function run(string $cmd, int $timeoutSeconds, ?string $cwd = null): array
    {
        if (self::isCallable('proc_open')) {
            return self::runWithProcOpen($cmd, $timeoutSeconds, $cwd);
        }

        return self::runWithExec($cmd, $timeoutSeconds, $cwd);
    }

    /**
     * Whether a PHP function exists and is not listed in disable_functions.
     */
    public static function isCallable(string $function): bool
    {
        if (!function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array($function, $disabled, true);
    }

    /**
     * @return array{exit: int, output: string, timed_out: bool}
     */
    private static function runWithProcOpen(string $cmd, int $timeoutSeconds, ?string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd ?: null);
        if (!is_resource($proc)) {
            return ['exit' => 127, 'output' => 'Could not start process.', 'timed_out' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        $output = '';
        $timedOut = false;

        while (true) {
            $read = [];
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                if (!feof($pipe)) {
                    $read[] = $pipe;
                }
            }
            if ($read === []) {
                break;
            }

            $wait = 1.0;
            if ($deadline !== null) {
                $wait = $deadline - microtime(true);
                if ($wait <= 0) {
                    $timedOut = true;
                    break;
                }
                $wait = min($wait, 1.0);
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) floor($wait), (int) (($wait - floor($wait)) * 1_000_000));
            if ($ready === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk)) {
                    $output .= $chunk;
                }
            }
        }

        if ($timedOut) {
            @proc_terminate($proc, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($timedOut) {
            $exit = self::EXIT_TIMEOUT;
            $output .= "\n" . sprintf('Process killed after %d seconds.', $timeoutSeconds);
        }

        return ['exit' => $exit, 'output' => trim($output), 'timed_out' => $timedOut];
    }

    /**
     * @return array{exit: int, output: string, timed_out: bool}
     */
    private static function runWithExec(string $cmd, int $timeoutSeconds, ?string $cwd): array
    {
        if (!self::isCallable('exec')) {
            return ['exit' => 127, 'output' => 'exec() is not available.', 'timed_out' => false];
        }

        $timeoutBin = $timeoutSeconds > 0 ? self::findTimeoutBinary() : null;
        if ($timeoutBin !== null) {
            $cmd = escapeshellarg($timeoutBin) . ' -s KILL ' . $timeoutSeconds . ' ' . $cmd;
        }

        if ($cwd !== null && $cwd !== '') {
            $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        }

        $lines = [];
        $exit = 0;
        @exec($cmd . ' 2>&1', $lines, $exit);

        return [
            'exit' => $exit,
            'output' => trim(implode("\n", $lines)),
            'timed_out' => $timeoutBin !== null && $exit === self::EXIT_TIMEOUT,
        ];
    }

    private static function findTimeoutBinary(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }
        $resolved = true;

        foreach (['timeout', 'gtimeout'] as $candidate) {
            $lines = [];
            $exit = 1;
            @exec('command -v ' . $candidate . ' 2>/dev/null', $lines, $exit);
            if ($exit === 0 && !empty($lines[0])) {
                $path = trim($lines[0]);
                break;
            }
        }

        return $path;
    }
}
