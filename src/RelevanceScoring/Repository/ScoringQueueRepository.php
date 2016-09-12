<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use PlasmaConduit\option\None;
use PlasmaConduit\option\Option;
use PlasmaConduit\option\Some;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Util\Calendar;

/**
 * Maintains a queue of queries that need to be scored. Is somewhat
 * lazy and allows individual requests to be fulfilled by multiple
 * users, but only if all queries are already assigned.
 *
 * Assigns priority to items in the queue, with 0 being the highest
 * priority.
 */
class ScoringQueueRepository
{
    use LoggerAwareTrait;

    /** @var Connection */
    private $db;
    /** @var Calendar */
    private $cal;
    /** @var int[] */
    private $defaultSlots;

    /**
     * @var Connection
     * @var Calendar   $cal
     * @var int[]      $slots The default scoring slots to create
     */
    public function __construct(Connection $db, Calendar $cal, array $slots)
    {
        $this->db = $db;
        $this->cal = $cal;
        $this->defaultSlots = $slots;
        $this->logger = new NullLogger();
    }

    /**
     * @param int[] $queryIds
     *
     * @return int[] Map from query id to number of pending scores
     */
    public function getNumberPending(array $queryIds)
    {
        $sql = <<<EOD
SELECT query_id, COUNT(1) AS count
  FROM scoring_queue
 WHERE query_id IN (?)
 GROUP BY query_id
EOD;
        $res = $this->db->fetchAll(
            $sql,
            [$queryIds],
            [Connection::PARAM_INT_ARRAY]
        );
        $byQueryId = [];
        foreach ($res as $row) {
            $byQueryId[$row['query_id']] = $row['count'];
        }

        return $byQueryId;
    }

    /**
     * Mark a queryId as needing to be scored $numSlots times.
     *
     * @param int $queryId
     */
    public function insert($queryId)
    {
        $params = ['queryId' => $queryId];
        $rows = [];
        // very simple priority assignment from 1 to $numSlots. Note
        // that 0 is the highest priority, and we create two items with
        // priority 1.
        foreach ($this->defaultSlots as $priority) {
            $rows[] = "(:queryId, :priority$priority)";
            $params["priority$priority"] = $priority;
        }

        $sql = 'INSERT INTO scoring_queue (query_id, priority) VALUES '.
            implode(',', $rows);

        return $this->db->executeUpdate($sql, $params);
    }

    /**
     * Assign a query to a particular user.
     *
     * @param User $user
     *
     * @return Option<int> query id
     */
    public function pop(User $user)
    {
        $this->db->transactional(function () use ($user, &$res) {
            // A user should only be assigned to one query at a time.
            // Since we arn't particularly strict this is more a convenience
            // against assigning a user to the same query twice.
            $this->unassignUser($user);

            $rows = $this->popNull($user);
            if (!$rows) {
                $rows = $this->popLastAssigned($user);
            }
            if ($rows) {
                $row = reset($rows);
                $this->assign($row['id'], $user);
                $res = new Some($row['query_id']);
            } else {
                $res = new None();
            }
        });

        return $res;
    }

    /**
     * Mark a query as scored by a specific user.
     *
     * @param User $user
     * @param int  $queryId
     */
    public function markScored($user, $queryId)
    {
        $conditions = ['query_id' => $queryId, 'user_id' => $user->uid];
        $rowsDeleted = $this->db->delete('scoring_queue', $conditions);

        if ($rowsDeleted > 1) {
            $this->logger->warn(
                'Unexpectedly deleted {numRows} for query {query_id} and user {user_id}',
                $conditions + ['numRows' => $rowsDeleted]
            );
        } elseif ($rowsDeleted === 0) {
            // The query might have been unassigned. try deleting an
            // unassigned entry for the same query. Doesn't matter if
            // this succedes, extra ratings are allowed.
            $sql = <<<EOD
DELETE FROM scoring_queue
WHERE user_id IS NULL and query_id = ?
LIMIT 1
EOD;
            $this->db->executeUpdate($sql, [$queryId]);
        }
    }

    /**
     * Unassign queries that have been assigned longer
     * than $ageInSeconds. This does not prevent the
     * assigned user from grading the query, it only
     * means it will potentially be assigned to a new user.
     *
     * @param int $ageInSeconds
     *
     * @return int Number of rows unassigned
     */
    public function unassignOld($ageInSeconds)
    {
        $sql = <<<EOD
UPDATE scoring_queue
   SET user_id = NULL, last_assigned = NULL
 WHERE last_assigned < ?
EOD;

        return $this->db->executeUpdate($sql, [$this->cal->now() - $ageInSeconds]);
    }

    /**
     * Unassigned the user from all queries.
     *
     * @param User $user
     */
    public function unassignUser(User $user)
    {
        $this->db->update(
            'scoring_queue',
            ['user_id' => null, 'last_assigned' => null],
            ['user_id' => $user->uid]
        );
    }

    private function assign($queueId, User $user)
    {
        $rowsUpdated = $this->db->update(
            'scoring_queue',
            [
                'user_id' => $user->uid,
                'last_assigned' => $this->cal->now(),
            ],
            ['id' => $queueId]
        );

        return $rowsUpdated > 0;
    }

    /**
     * Fetch a row from the queue with the
     * lowest priority.
     *
     * @param User $user
     *
     * @return array
     */
    private function popNull(User $user)
    {
        $sql = <<<EOD
SELECT queue.id, queue.query_id
  FROM scoring_queue queue
  LEFT OUTER JOIN queries_skipped skipped
    ON skipped.user_id = :userId AND skipped.query_id = queue.query_id
  LEFT OUTER JOIN scores scores
    ON scores.user_id = :userId AND scores.query_id = queue.query_id
 WHERE queue.user_id IS NULL
   AND scores.id IS NULL
   AND skipped.id IS NULL
 ORDER BY queue.priority ASC
 LIMIT 1
EOD;

        return $this->db->fetchAll($sql, [
            'userId' => $user->uid,
        ]);
    }

    /**
     * Get an item from the queue based on oldest assignment
     * date. Doesn't take queue priority into account.
     *
     * @param User $user
     *
     * @return array
     */
    private function popLastAssigned(User $user)
    {
        $sql = <<<EOD
SELECT queue.id, queue.query_id
  FROM scoring_queue queue
  LEFT OUTER JOIN queries_skipped skipped
    ON skipped.user_id = :userId AND skipped.query_id = queue.query_id
  LEFT OUTER JOIN scores scores
    ON scores.user_id = :userId AND scores.query_id = queue.query_id
 WHERE skipped.id IS NULL
   AND scores.id IS NULL
 ORDER BY queue.last_assigned ASC
 LIMIT 1
EOD;

        return $this->db->fetchAll($sql, [
            'userId' => $user->uid,
        ]);
    }
}
