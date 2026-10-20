<?php

namespace App\Actions;

use App\Models\Repository;
use App\Services\Git\Git;
use Symfony\Component\Finder\Finder;

/**
 * Looks through a folder (such as ~/Sites) for git checkouts and links them to tracked repositories,
 * so Claude can read the code and make changes in worktrees.
 */
class LinkLocalCheckouts
{
    private const MAX_DEPTH = 3;

    public function __construct(private readonly Git $git) {}

    /**
     * @return array{linked: list<array{full_name: string, path: string}>, already_linked: list<array{full_name: string, path: string}>, untracked: list<array{full_name: string, path: string}>}
     */
    public function handle(string $folder): array
    {
        $result = ['linked' => [], 'already_linked' => [], 'untracked' => []];
        $tracked = Repository::query()->tracked()->get()->keyBy(fn (Repository $repository): string => mb_strtolower($repository->full_name));

        foreach ($this->checkoutsIn($folder) as $path) {
            $fullName = $this->git->githubRepositoryAt($path);

            if ($fullName === null) {
                continue;
            }

            $repository = $tracked->get(mb_strtolower($fullName));
            $entry = ['full_name' => $fullName, 'path' => $path];

            if ($repository === null) {
                $result['untracked'][] = $entry;
            } elseif ($repository->localCheckoutPath() !== null) {
                $result['already_linked'][] = ['full_name' => $repository->full_name, 'path' => $repository->local_path];
            } else {
                $repository->update(['local_path' => $path]);
                $result['linked'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Every directory up to three levels deep that contains a .git folder (not worktrees or submodules).
     *
     * @return list<string>
     */
    private function checkoutsIn(string $folder): array
    {
        $finder = Finder::create()
            ->directories()
            ->in($folder)
            ->depth('< '.self::MAX_DEPTH)
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs()
            ->exclude(['node_modules', 'vendor']);

        $checkouts = is_dir($folder.DIRECTORY_SEPARATOR.'.git') ? [$folder] : [];

        foreach ($finder as $directory) {
            if (is_dir($directory->getPathname().DIRECTORY_SEPARATOR.'.git')) {
                $checkouts[] = $directory->getPathname();
            }
        }

        return $checkouts;
    }
}
