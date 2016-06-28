<?php

namespace WikiMedia\RelevanceScoring\Import;

use Doctrine\DBAL\Connection;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;

class Importer
{
    private $db;
    private $queriesRepo;
    private $resultsRepo;
    private $scoringQueueRepo;
    private $wikis;
    private $getters;
    private $resultsPerSource = 25;

    /**
     * @var callable
     */
    private $output;

    public function __construct(
        Connection $db,
        QueriesRepository $queriesRepo,
        ResultsRepository $resultsRepo,
        ScoringQueueRepository $scoringQueueRepo,
        array $wikis,
        array $getters
    ) {
        $this->db = $db;
        $this->queriesRepo = $queriesRepo;
        $this->resultsRepo = $resultsRepo;
        $this->scoringQueueRepo = $scoringQueueRepo;
        $this->wikis = $wikis;
        $this->getters = $getters;
        $this->output = function () {};
    }

    public function setOutput($callable)
    {
        $this->output = $callable;
    }

    private function output($line)
    {
        $callable = $this->output;
        $callable($line);
    }

    public function import(User $user, $wiki, $queryString)
    {
        if (!isset($this->wikis[$wiki])) {
            throw new RuntimeException("Unknown wiki: $wiki");
        }

        $maybeQueryId = $this->queriesRepo->findQueryId($wiki, $queryString);
        $queryId = null;
        if ($maybeQueryId->nonEmpty()) {
            $queryId = $maybeQueryId->get();
            $maybeQuery = $this->queriesRepo->getQuery($queryId);
            if ($maybeQuery->isEmpty()) {
                throw new RuntimeException('Found query id but not query?!?!?');
            }
            $query = $maybeQuery->get();
            if ($query['imported']) {
                throw new RuntimeException('Query already imported');
            }
        }
        $results = $this->performSearch($wiki, $queryString);

        $this->db->transactional(function () use ($queryId, $user, $wiki, $queryString, $results) {
            if ($queryId === null) {
                $queryId = $this->queriesRepo->createQuery($user, $wiki, $queryString, 'imported');
            }
            $this->resultsRepo->storeResults($user, $queryId, $results);
            $this->scoringQueueRepo->insert($queryId);
            $this->queriesRepo->markQueryImported($queryId);
        });

        return count($results);
    }

    public function importPending($limit, $userId = null)
    {
        $queries = $this->queriesRepo->getPendingQueries($limit, $userId);
        $imported = 0;
        foreach ($queries as $query) {
            $this->output("Importing {$query['wiki']}: {$query['query']}");
            $results = $this->performSearch($query['wiki'], $query['query']);
            $this->db->transactional(function () use ($query, $results) {
                $this->resultsRepo->storeResults($query['user_id'], $query['id'], $results);
                $this->queriesRepo->markQueryImported($query['id']);
                $this->scoringQueueRepo->insert($query['id']);
            });
            $imported += count($results);
        }

        return [count($queries), $imported];
    }

    /**
     * @param string $wiki
     * @param string $query
     *
     * @return array
     */
    private function performSearch($wiki, $query)
    {
        $promises = [];
        foreach ($this->getters as $key => $getter) {
            $this->output("Making request from $key");
            $promises[$key] = $getter->fetchAsync($wiki, $query);
        }
        $responses = \GuzzleHttp\Promise\unwrap($promises);

        $results = [];
        foreach ($responses as $key => $response) {
            $newResults = $this->getters[$key]->handleResponse($response, $wiki, $query);
            $newResults = array_slice($newResults, 0, $this->resultsPerSource);
            $this->output('Merging '.count($newResults)." results from $key");
            $results = array_merge($results, $newResults);
        }

        return $results;
    }
}
