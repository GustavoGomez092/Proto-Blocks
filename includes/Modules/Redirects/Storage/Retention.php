<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * Daily log pruning (spec §5). Each run deletes at most one batch per log
 * of rows older than `log.redirects` / `log.notFound` days (UTC, so no DST
 * drift); `0` keeps forever, `-1` means nothing was written. A full batch
 * queues one single follow-up run ten minutes later.
 */
final class Retention
{
    public const HOOK = 'proto_blocks_redirects_prune';
    public const BATCH = 10000;
    public const FOLLOW_UP_DELAY = 600;
    private const FIRST_RUN_DELAY = 3600;

    /** Args of the follow-up event, so it is never mistaken for the daily one. */
    public const FOLLOW_UP_ARGS = ['continue'];

    private readonly RedirectLog $redirects;
    private readonly NotFoundLog $notFound;

    /** @param array<string, mixed> $settings full module settings */
    public function __construct(array $settings = [], ?RedirectLog $redirects = null, ?NotFoundLog $notFound = null)
    {
        $this->redirects = $redirects ?? new RedirectLog($settings);
        $this->notFound = $notFound ?? new NotFoundLog($settings);
    }

    /** Hooks run() to the cron event. */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run'], 10, 0);
    }

    /** Adds the daily event unless one exists. */
    public function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(self::now() + self::FIRST_RUN_DELAY, 'daily', self::HOOK);
        }
    }

    /** Removes the daily event and any pending follow-up. */
    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::HOOK, self::FOLLOW_UP_ARGS);
    }

    /** @return array{redirects: int, notFound: int} rows deleted per log */
    public function run(): array
    {
        $deleted = [
            'redirects' => $this->prune($this->redirects),
            'notFound' => $this->prune($this->notFound),
        ];
        if (max($deleted) >= self::BATCH && wp_next_scheduled(self::HOOK, self::FOLLOW_UP_ARGS) === false) {
            wp_schedule_single_event(self::now() + self::FOLLOW_UP_DELAY, self::HOOK, self::FOLLOW_UP_ARGS);
        }

        return $deleted;
    }

    private function prune(LogTable $table): int
    {
        $days = $table->retentionDays();
        if ($days <= 0) {
            return 0;
        }

        return $table->pruneBefore(gmdate('Y-m-d H:i:s', self::now() - $days * 86400), self::BATCH);
    }

    private static function now(): int
    {
        return (int) current_time('timestamp', true);
    }
}
