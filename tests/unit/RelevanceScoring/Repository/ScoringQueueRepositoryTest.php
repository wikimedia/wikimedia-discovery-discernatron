<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;

class ScoringQueueRepositoryTest extends BaseRepositoryTest
{
    private $repo;
    private $cal;

    public function setUp()
    {
        parent::setUp();
        // clock always returns a new higher value on each call
        $this->cal = $this->getMock('WikiMedia\RelevanceScoring\Util\Calendar');
        $current = 1;
        $this->cal->expects($this->any())
            ->method('now')
            ->will($this->returnCallback(function () use (&$current) {
                return $current++;
            }));
        $this->repo = new ScoringQueueRepository($this->db, $this->cal, 1);
    }

    public static function invalidQueryIdProvider()
    {
        return [
            [null],
            ['abc'],
            [42],
        ];
    }
    /**
     * @dataProvider invalidQueryIdProvider
     * @expectedException Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException
     */
    public function testInsertOnlyAllowsValidQueryIds($id)
    {
        $this->repo->insert($id, 2);
    }

    public function testInsertsDefaultNumberOfQueryIds()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'foo');

        $repo = new ScoringQueueRepository($this->db, $this->cal, 4);
        $this->assertEquals(4, $repo->insert($queryId));
        $this->assertEquals(4, $this->db->fetchColumn(
            'select count(1) from scoring_queue'
        ));

        $repo = new ScoringQueueRepository($this->db, $this->cal, 6);
        $this->assertEquals(6, $repo->insert($queryId));
        $this->assertEquals(10, $this->db->fetchColumn(
            'select count(1) from scoring_queue'
        ));
    }

    public function testInsertsProvidedNumberOfQueryIds()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'foo');

        $this->assertEquals(5, $this->repo->insert($queryId, 5));
        $this->assertEquals(5, $this->db->fetchColumn(
            'select count(1) from scoring_queue'
        ));

        $this->assertEquals(2, $this->repo->insert($queryId, 2));
        $this->assertEquals(7, $this->db->fetchColumn(
            'select count(1) from scoring_queue'
        ));
    }

    public function testBasicQueryPop()
    {
        $user = $this->genTestUser();
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $query) {
            $queryId = $this->genQuery($user, $query);
            $this->repo->insert($queryId, 1);
            $expected[] = $queryId;
        }
        foreach ($expected as $id) {
            $assigned[] = $this->repo->pop($this->genTestUser())->getOrElse(null);
        }

        sort($expected);
        sort($assigned);
        $this->assertEquals($expected, $assigned);
    }

    public function testPopStillDistributesWhenAllAssigned()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 1);

        $this->assertEquals($queryId, $this->repo->pop($this->genTestUser())->getOrElse(null));
        $this->assertEquals($queryId, $this->repo->pop($this->genTestUser())->getOrElse(null));
        $this->assertEquals($queryId, $this->repo->pop($this->genTestUser())->getOrElse(null));
    }

    public function testUserOnlyAssignedToOneSlotAtATime()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 2);

        $this->repo->pop($user);
        $this->repo->pop($user);

        $this->assertEquals(1, $this->db->fetchColumn(
            'SELECT COUNT(1) from scoring_queue WHERE user_id = ?',
            [$user->uid]
        ));
    }

    public function testBasicPopRespectsUserSkippedQueries()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 5);
        $this->db->insert('queries_skipped', [
            'user_id' => $user->uid,
            'query_id' => $queryId,
        ]);
        // ensure even though there are remaining items for $queryId
        // in queue, they arn't given to this user.
        $this->assertTrue($this->repo->pop($user)->isEmpty());
    }

    public function testPopOnEmptyQueueRespectsUserSkippedQueries()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 1);
        $this->db->insert('queries_skipped', [
            'user_id' => $user->uid,
            'query_id' => $queryId,
        ]);
        // Assign only item in queue to different user
        $this->assertFalse($this->repo->pop($this->genTestUser())->isEmpty());
        // ensure that isn't also assigned to user that skipped it
        $this->assertTrue($this->repo->pop($user)->isEmpty());
    }

    public function testUnassignsOld()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 5);

        $this->repo->pop($this->genTestUser());
        $this->repo->pop($this->genTestUser());
        $this->repo->pop($this->genTestUser());

        $this->assertEquals(3, $this->db->fetchColumn(
            'SELECT COUNT(1) FROM scoring_queue WHERE user_id IS NOT NULL'
        ));

        // A bit of a weak test ... requires pop() to fetch calendar
        // time exactly once for each pop. The three assigned queries
        // should have times 1, 2, 3. The unassign call should delete
        // everything older than 4 - $ageInSeconds.
        $this->repo->unassignOld(2);
        $this->assertEquals(2, $this->db->fetchColumn(
            'SELECT COUNT(1) FROM scoring_queue WHERE user_id IS NOT NULL'
        ));
    }

    public function testMarkScoredRemovesFromQueue()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 2);

        $this->repo->pop($user);
        $this->repo->markScored($user, $queryId);
        $this->assertEquals(1, $this->db->fetchColumn(
            'SELECT COUNT(1) FROM scoring_queue'
        ));
    }

    public function testMarkScoredRemovesFromQueueAfterUnassigned()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 2);
        $this->repo->pop($user);
        $this->repo->unassignOld(0);
        $this->repo->markScored($user, $queryId);
        $this->assertEquals(1, $this->db->fetchColumn(
            'SELECT COUNT(1) FROM scoring_queue'
        ));
    }

    public function testMarkScoredDoesNothingIfReassigned()
    {
        $user = $this->genTestUser();
        $queryId = $this->genQuery($user, 'zzz');
        $this->repo->insert($queryId, 1);
        $this->repo->pop($this->genTestUser());
        $this->repo->markScored($user, $queryId);
        $this->assertEquals(1, $this->db->fetchColumn(
            'SELECT COUNT(1) FROM scoring_queue'
        ));
    }

    public function testGetNumberPending()
    {
        $user = $this->genTestUser();
        $queryIds = [
            $this->genQuery($user, 'zzz'),
            $this->genQuery($user, 'yyy'),
            $this->genQuery($user, 'xxx'),
        ];

        foreach ($queryIds as $queryId) {
            $this->repo->insert($queryId, 2);
        }

        foreach ($queryIds as $queryId) {
            $expected[$queryId] = 2;
        }
        $this->assertEquals($expected, $this->repo->getNumberPending($queryIds));
    }

    public function testPopsItemsInPriorityOrder()
    {
        $user = $this->genTestUser();
        $queryIds = [
            $this->genQuery($user, 'zzz'),
            $this->genQuery($user, 'yyy'),
            $this->genQuery($user, 'xxx'),
        ];

        foreach ($queryIds as $queryId) {
            $this->repo->insert($queryId, 2);
        }

        $order = [];
        for ($i = 0; $i < 6; ++$i) {
            $maybeId = $this->repo->pop($user);
            if ($maybeId->isEmpty()) {
                throw new \RuntimeException('No item retrieved');
            }
            $order[] = $maybeId->get();
            $this->repo->markScored($user, $maybeId->get());
        }

        // each item should be represented in the first 3 and the last 3
        $first = array_slice($order, 0, 3);
        $second = array_slice($order, 3);
        sort($first);
        sort($second);
        sort($queryIds);

        $debug = json_encode($order);
        $this->assertEquals($queryIds, $first, $debug);
        $this->assertEquals($queryIds, $second, $debug);
    }
}
