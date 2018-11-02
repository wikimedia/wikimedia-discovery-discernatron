<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use PhpOption\None;
use PhpOption\Option;
use PhpOption\Some;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;
use WikiMedia\RelevanceScoring\Import\ImportedResult;

class ResultsRepository
{
    use LoggerAwareTrait;

    /** @var Connection */
    private $db;

    /** @var int The maximum position returned for scoring */
    private $maxPosition;

    /**
     * @param Connection $db
     * @param int        $maxPosition The maximum position returned for scoring
     */
    public function __construct(Connection $db, $maxPosition)
    {
        $this->db = $db;
        $this->maxPosition = $maxPosition;
        $this->logger = new NullLogger();
    }

    /**
     * @param User        $user
     * @param string|null $wiki
     *
     * @return Option<int>
     */
    public function getRandomId(User $user, $wiki = null)
    {
        $qb = $this->db->createQueryBuilder()
            ->select('MAX(id)')
            ->from('results');
        if ($wiki !== null) {
            $qb->where('wiki = ?');
            $qb->setParameter(0, $wiki);
        }

        $maxId = $qb->execute()->fetchColumn();
        if ($maxId === false) {
            return None::create();
        }

        $rand = mt_rand(0, $maxId);
        $sql = <<<EOD
SELECT r.id
  FROM results r
  LEFT OUTER JOIN scores s
    ON r.id = s.result_id AND s.user_id = ?
 WHERE r.id > ?
   AND s.id IS NULL
 ORDER BY r.id ASC
EOD;
        $id = $this->db->fetchColumn($sql, [$user->uid, $rand]);
        if ($id === false) {
            $sql = str_replace('>', '<=', $sql);
            $id = $this->db->fetchColumn($sql, [$user->uid, $rand]);
            if ($id === false) {
                return None::create();
            }
        }

        return new Some($id);
    }

    /**
     * @param int $queryId
     *
     * @return int Number of results deleted
     */
    public function deleteResultsByQueryId($queryId)
    {
        return $this->db->delete(
            'results',
            ['query_id' => $queryId]
        );
    }

    /**
     * @param int $resultId Result id
     *
     * @return Option<array>
     */
    public function getQueryResult($resultId)
    {
        $sql = <<<EOD
SELECT wiki, query, query_id, namespace, title
  FROM results r
  JOIN queries q
    ON q.id = r.query_id
 WHERE r.id = ?
EOD;
        $result = $this->db->fetchAssoc($sql, [$resultId]);
        if ($result === false) {
            return None::create();
        }

        return new Some($result);
    }

    /**
     * @param int $queryId Query id
     *
     * @return Option<array>
     */
    public function getQueryResults($queryId)
    {
        $sql = <<<EOD
SELECT r.id, r.title, r_s.snippet
  FROM results r
  JOIN (SELECT results_id, MAX(snippet_score) as snippet_score
          FROM results_sources
         WHERE query_id = :queryId
           AND position <= :maxPosition
         GROUP BY results_id
       ) r_s_max
    ON r.id = r_s_max.results_id
  JOIN results_sources r_s
    ON r_s.results_id = r_s_max.results_id
   AND r_s.snippet_score = r_s_max.snippet_score
   AND r_s.query_id = :queryId
   AND r_s.position <= :maxPosition
 WHERE r.query_id = :queryId
 GROUP BY r.id
 ORDER BY r.id DESC
EOD;
        $results = $this->db->fetchAll($sql, [
            'queryId' => $queryId,
            'maxPosition' => $this->maxPosition,
        ]);
        if ($results === false) {
            return None::create();
        }

        $titles = [];
        foreach ($results as $row) {
            $titles[$row['id']] = $row;
        }

        return new Some($titles);
    }

    /**
     * Stores results into mysql. MUST be run in a transactional context.
     * Expects that no results have been previously stored for $queryId.
     *
     * @param User|int         $user    User requesting the import
     * @param int              $queryId Query id to attach results to
     * @param ImportedResult[] $results Individual results to store
     *
     * @return int[] Map from title string to result id inserted
     */
    public function storeResults($user, $queryId, array $results)
    {
        $userId = $user instanceof User ? $user->uid : $user;
        $now = time();
        $resultIds = [];
        foreach ($results as $result) {
            $title = $result->getTitle();
            if (!isset($resultIds[$title])) {
                $affected = $this->db->insert('results', [
                    'query_id' => $queryId,
                    'title' => $title,
                    'title_hash' => md5($title),
                    'created' => $now,
                    'redirect_to' => $result->getRedirectsToTitle(),
                ]);
                if ($affected !== 1) {
                    throw new RuntimeException('Expected 1 inserted row but got: '.$affected);
                }
                $resultIds[$title] = $this->db->lastInsertId();
                $this->logger->debug("Created $title as {$resultIds[$title]}");
            }
        }
        foreach ($results as $result) {
            $this->logger->debug("Inserting {$result->getSource()}: {$resultIds[$result->getTitle()]} {$result->getTitle()}");
            $affected = $this->db->insert('results_sources', [
                'query_id' => $queryId,
                'results_id' => $resultIds[$result->getTitle()],
                'user_id' => $userId,
                'source' => $result->getSource(),
                'snippet' => $result->getSnippet(),
                'snippet_score' => $result->getSnippetScore(),
                'position' => $result->getPosition(),
                'created' => $now,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('Expected 1 inserted row but got: '.$affected);
            }
        }

        return $resultIds;
    }
}
