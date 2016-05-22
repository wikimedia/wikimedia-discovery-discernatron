<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use WikiMedia\RelevanceScoring\Import\ImportedResult;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class ScoresRepositoryTest extends BaseRepositoryTest
{
    public static function storeScoreProvider()
    {
        return [
            'integer score' => [2],
            'null score' => [null],
        ];
    }

    /**
     * @dataProvider storeScoreProvider
     */
    public function testStoreScore($score)
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'something');
        $resultIds = $this->genResult($user, $queryId, [
            new ImportedResult('unittest', 'Some Title', '...', 0),
        ]);
        $resultId = reset($resultIds);

        $repo = new ScoresRepository($this->db);
        $repo->storeQueryScore($user, $queryId, $resultId, null);
        $scores = $repo->getScoresForQuery($queryId);

        $this->assertCount(1, $scores);
        $score = reset($scores);
        $this->assertArrayHasKey('score', $score);
        $this->assertEquals(null, $score['score']);
    }

    public function testGetNumberOfScores()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'something');
        $resultIds = $this->genResult($user, $queryId, [
            new ImportedResult('unittest', 'Some Title', '...', 0),
        ]);
        $resultId = reset($resultIds);

        $repo = new ScoresRepository($this->db);
        $repo->storeQueryScore($user, $queryId, $resultId, null);

        $scoreCount = $repo->getNumberOfScores([$queryId]);
        $this->assertArrayHasKey($queryId, $scoreCount);
        $this->assertEquals(1, $scoreCount[$queryId]);

        $user2 = $this->genTestUser();
        $repo->storeQueryScore($user2, $queryId, $resultId, null);
        $scoreCount = $repo->getNumberOfScores([$queryId]);
        $this->assertEquals(2, $scoreCount[$queryId]);
    }
}
