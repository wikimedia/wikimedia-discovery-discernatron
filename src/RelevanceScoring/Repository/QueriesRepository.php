<?php

namespace WikiMedia\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PDO;
use PhpOption\None;
use PhpOption\Option;
use PhpOption\Some;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception\DuplicateQueryException;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

class QueriesRepository
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @param User        $user
     * @param string|null $wiki
     *
     * @return Option<int>
     */
    public function getRandomUngradedQuery(User $user, $wiki = null)
    {
        $qb = $this->db->createQueryBuilder()
            ->select('MAX(id)')
            ->from('queries')
            ->where('imported = 1');
        if ($wiki !== null) {
            $qb->andWhere('wiki = ?');
            $qb->setParameter(0, $wiki);
        }

        $maxId = $qb->execute()->fetchColumn();
        if ($maxId === false) {
            return None::create();
        }

        $rand = mt_rand(0, $maxId);
        $qb = $this->db->createQueryBuilder()
            ->select('q.id')
            ->from('queries', 'q')
            ->add('join', [
                'q' => [
                    [
                        'joinType' => 'left outer',
                        'joinTable' => 'scores',
                        'joinAlias' => 's',
                        'joinCondition' => 'q.id = s.query_id AND s.user_id = ?',
                    ],
                    [
                        'joinType' => 'left outer',
                        'joinTable' => 'queries_skipped',
                        'joinAlias' => 'q_s',
                        'joinCondition' => 'q.id = q_s.query_id AND q_s.user_id = ?',
                    ],
                ],
            ])
            ->setParameter(0, $user->uid)
            ->setParameter(1, $user->uid)
            ->where('q.id > ?')
            ->setParameter(2, $rand)
            ->andWhere('s.id IS NULL')
            ->andWhere('q_s.id IS NULL')
            ->andWhere('q.imported = 1')
            ->orderBy('q.id', 'ASC');
        if ($wiki !== null) {
            $qb->andWhere('q.wiki = ?')
                ->setParameter(3, $wiki);
        }

        $id = $qb->execute()->fetchColumn();

        if ($id === false) {
            $qb->where('q.id <= ?')
                ->andWhere('s.id IS NULL')
                ->andWhere('q_s.id IS NULL')
                ->andWhere('q.imported = 1')
                ->orderBy('q.id', 'DESC');
            if ($wiki !== null) {
                $qb->andWhere('q.wiki = ?');
            }

            $id = $qb->execute()->fetchColumn();

            if ($id === false) {
                return None::create();
            }
        }

        return new Some($id);
    }

    /**
     * @param string $wiki
     * @param string $query
     *
     * @return Option<int>
     */
    public function findQueryId($wiki, $query)
    {
        $result = $this->db->fetchColumn(
            'SELECT id FROM queries WHERE wiki = ? AND query = ?',
            [$wiki, $query]
        );

        if ($result) {
            return new Some($result);
        } else {
            return None::create();
        }
    }

    /**
     * @param string $wiki
     *
     * @return int[]
     */
    public function findQueryIdsForWiki($wiki)
    {
        return $this->db->project(
            'SELECT id FROM queries WHERE wiki = ?',
            [$wiki],
            function ($row) { return $row['id']; }
        );
    }

    /**
     * @param string $wiki
     *
     * @return int[]
     */
    public function findImportedQueryIdsForWiki($wiki)
    {
        return $this->db->project(
            'SELECT id FROM queries WHERE wiki = ? AND imported = 1',
            [$wiki],
            function ($row) { return $row['id']; }
        );
    }
    /**
     * @return int[]
     */
    public function findAllQueryIds()
    {
        return $this->db->project(
            'SELECT id FROM queries',
            [],
            function ($row) { return $row['id']; }
        );
    }

    /**
     * @return int[]
     */
    public function findAllImportedQueryIds()
    {
        return $this->db->project(
            'SELECT id FROM queries WHERE imported = 1',
            [],
            function ($row) { return $row['id']; }
        );
    }

    /**
     * @param int $id
     *
     * @return Option<array>
     */
    public function getQuery($id)
    {
        $result = $this->db->fetchAll(
            'SELECT id, user_id, wiki, query, created, imported FROM queries WHERE id = ?',
            [$id]
        );

        if ($result) {
            return new Some(reset($result));
        } else {
            return None::create();
        }
    }

    /**
     * @param User   $user
     * @param string $wiki
     * @param string $wiki
     * @param string $query
     * @param bool   $imported
     *
     * @return int
     *
     * @throws DuplicateQueryException
     * @throws RuntimeException
     */
    public function createQuery(User $user, $wiki, $query, $imported = false)
    {
        try {
            $inserted = $this->db->insert('queries', [
                'user_id' => $user->uid,
                'wiki' => $wiki,
                'query' => $query,
                'query_hash' => md5($query),
                'created' => time(),
                'imported' => $imported === 'imported',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateQueryException('Query already exists', 0, $e);
        }
        if (!$inserted) {
            throw new RuntimeException('Failed insert new query');
        }

        return $this->db->lastInsertId();
    }

    /**
     * @param int $queryId
     *
     * @return bool
     */
    public function markQueryImported($queryId)
    {
        $affected = $this->db->update(
            'queries',
            ['imported' => true],
            ['id' => $queryId]
        );

        return $affected === 1;
    }

    /**
     * @param int $queryId
     *
     * @return bool
     */
    public function deleteQueryById($queryId)
    {
        $affected = $this->db->delete(
            'queries',
            ['id' => $queryId]
        );

        return $affected === 1;
    }

    /**
     * @param int      $limit
     * @param int|null $userId
     *
     * @return array
     */
    public function getPendingQueries($limit, $userId = null)
    {
        $sql = 'SELECT * FROM queries WHERE imported = 0';
        $params = [];
        $types = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
            $types[] = PDO::PARAM_INT;
        }
        $sql .= ' LIMIT ?';
        $params[] = $limit;
        $types[] = PDO::PARAM_INT;

        return $this->db->fetchAll($sql, $params, $types);
    }

    public function markQuerySkipped(User $user, $queryId)
    {
        $this->db->insert('queries_skipped', [
            'user_id' => $user->uid,
            'query_id' => $queryId,
        ]);
    }
}
