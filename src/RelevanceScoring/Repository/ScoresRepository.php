<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

class ScoresRepository
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function storeQueryScore(User $user, $queryId, $resultId, $score)
    {
        $this->db->insert('scores', [
            'user_id' => $user->uid,
            'result_id' => $resultId,
            'query_id' => $queryId,
            'score' => $score,
            'created' => time(),
        ]);
    }

    public function storeQueryScores(User $user, $queryId, array $scores)
    {
        $this->db->transactional(function() use ($user, $queryId, $scores) {
            foreach ($scores as $resultId => $score) {
                if ($score !== null) {
                    $this->storeQueryScore($user, $queryId, $resultId, $score);
                }
            }
        });
    }

    public function getAll()
    {
        $sql = <<<EOD
SELECT AVG(s.score) as score,
       COUNT(1) as num_scores,
       q.wiki as wiki,
       q.query as query,
       r.title as title,
       r.namespace as namespace
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
     * @throws \Exception
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
