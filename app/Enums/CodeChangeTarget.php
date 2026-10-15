<?php

namespace App\Enums;

/**
 * Where Claude's changes go once the user approves them.
 */
enum CodeChangeTarget: string
{
    /** Push the commits to the pull request's own branch. */
    case PullRequestBranch = 'pull_request_branch';

    /** Push to a new branch and open a pull request into the pull request's branch. */
    case NewPullRequest = 'new_pull_request';
}
