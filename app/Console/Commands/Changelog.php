<?php

namespace App\Console\Commands;

use App\Services\Changelog as ChangelogFile;
use Illuminate\Console\Command;

/**
 * Release helpers for CHANGELOG.md, used by the GitHub Actions release workflow.
 */
class Changelog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'changelog
        {action : next-version | release | notes}
        {version? : The version to release or print notes for}
        {--date= : Release date (YYYY-MM-DD), defaults to today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Work out the next version, cut a release, or print release notes from CHANGELOG.md';

    public function handle(ChangelogFile $changelog): int
    {
        return match ($this->argument('action')) {
            'next-version' => $this->printNextVersion($changelog),
            'release' => $this->release($changelog),
            'notes' => $this->printNotes($changelog),
            default => $this->invalidAction(),
        };
    }

    /**
     * Prints the next version, or nothing when "Unreleased" has no entries.
     */
    private function printNextVersion(ChangelogFile $changelog): int
    {
        $this->line($changelog->nextVersion() ?? '');

        return self::SUCCESS;
    }

    private function release(ChangelogFile $changelog): int
    {
        $version = $this->argument('version') ?? $changelog->nextVersion();

        if ($version === null) {
            $this->error('Nothing to release: "## [Unreleased]" has no entries.');

            return self::FAILURE;
        }

        $changelog->write($changelog->release($version, $this->option('date') ?? now()->toDateString()));
        $this->line($version);

        return self::SUCCESS;
    }

    private function printNotes(ChangelogFile $changelog): int
    {
        $notes = $changelog->notesFor((string) $this->argument('version'));

        if ($notes === null) {
            $this->error("Version {$this->argument('version')} is not in the changelog.");

            return self::FAILURE;
        }

        $this->line($notes);

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use next-version, release or notes.');

        return self::INVALID;
    }
}
