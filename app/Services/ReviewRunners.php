<?php

namespace App\Services;

use Native\Desktop\DataObjects\QueueConfig;
use Native\Desktop\Facades\QueueWorker;

/**
 * The queue workers that run Claude's work (reviews, questions, reply drafts and code changes), as many as the user
 * chose under Settings → PR review. The first is the "reviews" worker in config/nativephp.php, which NativePHP always
 * runs. The others, "reviews-2" and up, start when the app launches or the setting goes up. When the setting goes
 * down, the ones above it finish what they're doing and then wait, rather than being stopped halfway through a
 * review; the next launch doesn't start them.
 */
class ReviewRunners
{
    /**
     * The name of the first runner's worker, from config/nativephp.php.
     */
    public const FIRST = 'reviews';

    /**
     * Start the runners the setting asks for, beyond the first. Starting one that is already running does nothing.
     */
    public function start(): void
    {
        $first = QueueConfig::fromConfigArray([self::FIRST => config('nativephp.queue_workers.'.self::FIRST)])[0];

        for ($runner = 2; $runner <= $this->settings()->reviewRunners(); $runner++) {
            QueueWorker::up(new QueueConfig(
                alias: self::FIRST."-{$runner}",
                queuesToConsume: $first->queuesToConsume,
                memoryLimit: $first->memoryLimit,
                timeout: $first->timeout,
                sleep: $first->sleep,
            ));
        }
    }

    /**
     * Whether the queue worker with this name is a runner above the number the user chose, so it should take no more
     * jobs for now.
     */
    public function pauses(?string $workerName): bool
    {
        if ($workerName === null || preg_match('/^'.self::FIRST.'-(\d+)$/', $workerName, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] > $this->settings()->reviewRunners();
    }

    /**
     * Fresh settings every time: a queue worker lives for hours, and the user may change the setting meanwhile.
     */
    private function settings(): AppSettings
    {
        return app()->build(AppSettings::class);
    }
}
