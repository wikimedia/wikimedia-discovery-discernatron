<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

class ScoresRepository
{
    /** @var Connection */
    private $db;
    /** @var int */
    private $maxPosition;

    public function __construct(Connection $db, $maxPosition)
    {
        $this->db = $db;
        $this->maxPosition = $maxPosition;
    }

    /**
     * @param User     $user
     * @param int      $queryId
     * @param int      $resultId
     * @param int|null $score
     *
     * @return int
     *
     * @throws RuntimeException
     */
    public function storeQueryScore(User $user, $queryId, $resultId, $score)
    {
        $row = [
            'user_id' => $user->uid,
            'result_id' => $resultId,
            'query_id' => $queryId,
            'score' => $score,
            'created' => time(),
        ];
        $affected = $this->db->insert('scores', $row);
        if ($affected !== 1) {
            throw new RuntimeException('Failed inserting row');
        }

        return $this->db->lastInsertId();
    }

    public function getNumberOfScores(array $queryIds)
    {
        $sql = <<<EOD
SELECT query_id, count(distinct user_id) as count
  FROM scores s
 WHERE query_id IN (:ids)
 GROUP BY query_id
EOD;

        $res = $this->db->fetchAll(
            $sql,
            ['ids' => $queryIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );
        if ($res === false) {
            throw new RuntimeException('Query Failure');
        }

        $byQueryId = [];
        foreach ($res as $row) {
            $byQueryId[$row['query_id']] = $row['count'];
        }

        return $byQueryId;
    }

    /**
     * @param User $user
     * @param int  $startingAtId
     * @param int  $limit
     *
     * @return array
     */
    public function getScoredQueries(User $user, $startingAtId = 0, $limit = 20)
    {

        // @todo calculate the mediawiki dcg?
        $qb = $this->db->createQueryBuilder()
            ->select('DISTINCT q.id, q.wiki, q.query')
            ->from('queries', 'q')
            ->join('q', 'scores', 's', 's.query_id = q.id AND s.user_id = :userId')
            ->setParameter('userId', $user->uid)
            ->groupBy('q.id', 'q.wiki', 'q.query')
            ->orderBy('q.id', 'ASC')
            ->setMaxResults($limit);

        if ($startingAtId > 0) {
            $qb->where('q.id >= :startingAtId')
                ->setParameter('startingAtId', $startingAtId);
        }

        return $qb->execute()->fetchAll() ?: [];
    }

    /**
     * @param User $user
     * @param int  $queryId
     *
     * @return array
     *
     * @throws RuntimeException
     */
    public function getScoresForQuery(User $user, $queryId)
    {
        $sql = <<<EOD
SELECT r.title,
	   AVG(s.score) as score,
       u_s.score as user_score,
       SUM(IF(s.score IS NULL, 0, 1)) as num_scores,
        s.reliable
  FROM ( SELECT r.id, r.title
           FROM results r
           JOIN results_sources r_s ON r.id = r_s.results_id
            AND r_s.position <= :maxPosition
          GROUP BY r.id
       ) r
  JOIN scores s ON s.result_id = r.id
  LEFT OUTER JOIN scores u_s ON u_s.result_id = r.id
   AND u_s.user_id = :userId
 WHERE s.query_id = :queryId
 GROUP BY s.result_id
 ORDER BY AVG(s.score) DESC
EOD;

        $res = $this->db->fetchAll($sql, [
            'maxPosition' => $this->maxPosition,
            'userId' => $user->uid,
            'queryId' => $queryId,
        ]);
        if ($res === false) {
            throw new RuntimeException('Query Failure');
        }

        return $res;
    }

    /**
     * @param User  $user
     * @param int   $queryId
     * @param array $scores
     */
    public function storeQueryScores(User $user, $queryId, array $scores)
    {
        $this->db->transactional(function () use ($user, $queryId, $scores) {
            foreach ($scores as $resultId => $score) {
                $this->storeQueryScore($user, $queryId, $resultId, $score);
            }
        });
    }

    /**
     * @return array
     */
    public function getAll()
    {
        $sql = <<<EOD
SELECT AVG(s.score) as score,
       SUM(IF(s.score IS NULL, 0, 1)) as num_scores,
       q.wiki as wiki,
       q.query as query,
       r.title as title
  FROM scores s
  JOIN results r ON r.id = s.result_id
  JOIN queries q ON q.id = r.query_id
 GROUP BY r.id
 ORDER BY q.wiki, q.id, AVG(s.score) DESC
EOD;

        return $this->db->fetchAll($sql);
    }

    /**
     * @param string      $wiki
     * @param string|null $query
     *
     * @return array
     *
     * @throws RuntimeException
     *
     * @todo make not suck
     */
    public function getScoresForWiki($wiki, $query = null)
    {
        $qb = $this->db->createQueryBuilder()
            ->select('wiki', 'query', 'created', 'score')
            ->from('scores', 's')
            ->innerJoin('s', 'results', 'r', 'r.id = s.result_id')
            ->innerJoin('r', 'queries', 'q', 'q.id = r.query_id')
            ->where('q.wiki = ?')
            ->setParameter(0, $wiki);

        if ($query !== null) {
            $qb->andWhere('q.query = ?')
                ->setParameter(1, $query);
        }

        $res = $qb->execute()->fetchAll();
        if ($res === false) {
            throw new RuntimeException('Query Failure');
        }

        return $res;
    }

    /**
     * @param int $queryId
     *
     * @return int Number of scores deleted
     */
    public function deleteScoresByQueryId($queryId)
    {
        $sql = 'DELETE FROM scores USING scores, results WHERE scores.result_id = results.id AND results.query_id = ?';

        return $this->db->executeUpdate($sql, [$queryId]);
    }
}
