<?php

namespace App\Services\Github;

/**
 * GraphQL documents used to read pull requests from GitHub.
 */
final class GraphqlQueries
{
    private const PULL_REQUEST_FIELDS = <<<'GRAPHQL'
        fragment PullRequestFields on PullRequest {
          id
          number
          title
          url
          state
          isDraft
          createdAt
          updatedAt
          closedAt
          mergedAt
          additions
          deletions
          changedFiles
          headRefName
          baseRefName
          headRefOid
          isCrossRepository
          headRepository { nameWithOwner }
          maintainerCanModify
          mergeable
          reviewDecision
          author { login avatarUrl }
          assignees(first: 10) { nodes { login } }
          labels(first: 10) { nodes { name color } }
          comments { totalCount }
          reviewRequests(first: 30) {
            nodes {
              requestedReviewer {
                __typename
                ... on User { login }
                ... on Team { slug name organization { login } }
              }
            }
          }
          reviewRequestedEvents: timelineItems(last: 20, itemTypes: [REVIEW_REQUESTED_EVENT]) {
            nodes {
              ... on ReviewRequestedEvent {
                createdAt
                requestedReviewer {
                  __typename
                  ... on User { login }
                  ... on Team { slug name organization { login } }
                }
              }
            }
          }
          reviewRequestRemovedEvents: timelineItems(last: 20, itemTypes: [REVIEW_REQUEST_REMOVED_EVENT]) {
            nodes {
              ... on ReviewRequestRemovedEvent {
                createdAt
                requestedReviewer {
                  __typename
                  ... on User { login }
                  ... on Team { slug name organization { login } }
                }
              }
            }
          }
          latestOpinionatedReviews(first: 50) { nodes { author { login } state submittedAt commit { oid } } }
          latestReviews(first: 50) { nodes { author { login } state submittedAt commit { oid } } }
          commits(last: 1) { nodes { commit { statusCheckRollup { state } } } }
        }
        GRAPHQL;

    public const OPEN_PULL_REQUESTS = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $cursor: String) {
          repository(owner: $owner, name: $name) {
            pullRequests(states: OPEN, first: 50, after: $cursor, orderBy: {field: UPDATED_AT, direction: DESC}) {
              pageInfo { hasNextPage endCursor }
              nodes { ...PullRequestFields }
            }
          }
        }
        GRAPHQL."\n".self::PULL_REQUEST_FIELDS;

    public const PULL_REQUEST = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $number: Int!) {
          repository(owner: $owner, name: $name) {
            pullRequest(number: $number) {
              ...PullRequestFields
              bodyHTML
              reviewThreads(first: 100) {
                nodes {
                  id
                  isResolved
                  isOutdated
                  path
                  diffSide
                  line
                  originalLine
                  comments(first: 50) {
                    nodes {
                      id
                      databaseId
                      body
                      bodyHTML
                      createdAt
                      url
                      diffHunk
                      author { login avatarUrl }
                    }
                  }
                }
              }
              conversation: comments(last: 100) {
                nodes { id databaseId body bodyHTML createdAt url author { login avatarUrl } }
              }
              reviews(last: 50) {
                nodes { id databaseId state body bodyHTML submittedAt url author { login avatarUrl } }
              }
            }
          }
        }
        GRAPHQL."\n".self::PULL_REQUEST_FIELDS;

    /**
     * Whether pull requests, looked up by node id, are still open, merged or closed. GitHub accepts up to 100 ids.
     */
    public const PULL_REQUEST_STATES = <<<'GRAPHQL'
        query ($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on PullRequest { id state closedAt mergedAt }
          }
        }
        GRAPHQL;

    /**
     * Open pull requests from a branch, to find the one PRism opened before instead of opening another.
     */
    public const OPEN_PULL_REQUESTS_FROM_BRANCH = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $branch: String!) {
          repository(owner: $owner, name: $name) {
            pullRequests(headRefName: $branch, states: OPEN, first: 10) {
              nodes { url headRepository { nameWithOwner } }
            }
          }
        }
        GRAPHQL;

    public const FILE_VIEWED_STATES = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $number: Int!, $cursor: String) {
          repository(owner: $owner, name: $name) {
            pullRequest(number: $number) {
              files(first: 100, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                nodes { path viewerViewedState }
              }
            }
          }
        }
        GRAPHQL;

    public const MARK_FILE_AS_VIEWED = <<<'GRAPHQL'
        mutation ($pullRequestId: ID!, $path: String!) {
          markFileAsViewed(input: {pullRequestId: $pullRequestId, path: $path}) { clientMutationId }
        }
        GRAPHQL;

    public const UNMARK_FILE_AS_VIEWED = <<<'GRAPHQL'
        mutation ($pullRequestId: ID!, $path: String!) {
          unmarkFileAsViewed(input: {pullRequestId: $pullRequestId, path: $path}) { clientMutationId }
        }
        GRAPHQL;

    public const RESOLVE_REVIEW_THREAD = <<<'GRAPHQL'
        mutation ($threadId: ID!) {
          resolveReviewThread(input: {threadId: $threadId}) { thread { id isResolved } }
        }
        GRAPHQL;
}
