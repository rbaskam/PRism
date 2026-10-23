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
          additions
          deletions
          changedFiles
          headRefName
          baseRefName
          headRefOid
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
