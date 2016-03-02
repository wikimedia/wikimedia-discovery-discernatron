<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Import\ImportedResult;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;

class ResultsRepositoryTest extends BaseRepositoryTest
{
    public function testStoreResults()
    {
        $user = $this->genTestUser();
        $repo = new ResultsRepository($this->db);
        $this->db->transactional(function () use ($repo, $user, &$queryId) {
            $queryId = $this->genQuery($user, 'JFK');
            $repo->storeResults( $user, $queryId, [
                new ImportedResult('unitTest', 'JFK', 1),
                new ImportedResult('other', 'John F. Kennedy', 1),
                new ImportedResult('other', 'JFK', 2)
            ]);
        });
        
        $found = $repo->getQueryResults($queryId)->getOrElse([]);
        $this->assertCount(2, $found);
        $titles = array_map(function($query) {
            return $query['title'];
        }, $found);
        $this->assertContains('JFK', $titles);
        $this->assertContains('John F. Kennedy', $titles);
    }

    /**
     * @param User $user
     * @param $query
     *
     * @return int Id of generated query
     */
    private function genQuery(User $user, $query)
    {
        $repo = new QueriesRepository($this->db);
        return $repo->createQuery($user, 'unitTest', $query, true);
    }
}