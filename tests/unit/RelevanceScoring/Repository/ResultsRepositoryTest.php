<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use WikiMedia\RelevanceScoring\Import\ImportedResult;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;

class ResultsRepositoryTest extends BaseRepositoryTest
{
    public function testStoreResults()
    {
        $user = $this->genTestUser();
        $repo = new ResultsRepository($this->db);
        $this->db->transactional(function () use ($repo, $user, &$queryId) {
            $queryId = $this->genQuery($user, 'JFK');
            $repo->storeResults($user, $queryId, [
                new ImportedResult('unitTest', 'JFK', '', 1),
                new ImportedResult('other', 'John F. Kennedy', '', 1),
                new ImportedResult('other', 'JFK', '', 2),
            ]);
        });

        $found = $repo->getQueryResults($queryId)->getOrElse([]);
        $this->assertCount(2, $found);
        $titles = array_map(function ($query) {
            return $query['title'];
        }, $found);
        $this->assertContains('JFK', $titles);
        $this->assertContains('John F. Kennedy', $titles);
    }

    public function testRoundTripUtf8()
    {
        $title = 'Katsuhisa Hōki';
        $snippet = 'Katsuhisa Hōki is a Japanese voice actor and actor from Nagasaki Prefecture. He is affiliated .... (2004) (Great Devil King); Pocket Monsters Advanced Generation the Movie: The Pokémon Ranger and Prince of the ... The Specialist (Joe Leon); Stargate SG-1 (George Hammond); Starsky and Hutch (Captain Harold Dobey) ...';

        $user = $this->genTestUser();
        $repo = new ResultsRepository($this->db);
        $this->db->transactional(function () use ($repo, $user, $title, $snippet, &$queryId) {
            $queryId = $this->genQuery($user, 'starksy and hutch devil');
            $repo->storeResults($user, $queryId, [
                new ImportedResult('unittest', $title, $snippet, 0),
            ]);
        });

        $found = $repo->getQueryResults($queryId)->getOrElse([]);
        $this->assertCount(1, $found);
        $result = reset($found);
        $this->assertEquals($title, $result['title']);
        $this->assertEquals($snippet, $result['snippet']);
    }
}
