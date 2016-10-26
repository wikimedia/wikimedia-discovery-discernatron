<?php

namespace WikiMedia\RelevanceScoring;

use PlasmaConduit\option\Some;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;
use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\UsersRepository;

/**
 * Handles the data read/write patterns used by the QueriesController.
 */
class QueriesManager
{
    // Return codes for updateReliability

    /** @var string Waiting for more scores to be provided */
    const RELIABILITY_PENDING = 'pending';
    /** @var string Query meets reliability thresholds */
    const RELIABILITY_GOOD = 'good';
    /**
     * @var string Query does not meet reliability thresholds, and no
     *             more scores will be requested.
     */
    const RELIABILITY_BAD = 'bad';

    /** @var User */
    private $user;
    /** @var ResultsRepository */
    private $resultsRepository;
    /** @var ScoresRepository */
    private $scoresRepo;
    /** @var ScoringQueueRepository */
    private $scoringQueueRepo;
    /** @var QueriesRepository */
    private $queriesRepo;
    /** @var UsersRepository */
    private $usersRepo;
    /** @var Reliability */
    private $reliability;
    /** @var int */
    private $maxScoresPerQuery;
    /** @var int */
    private $queuePriority;

    /**
     * @param User                   $user
     * @param QueriesRepository      $queriesRepo
     * @param ResultsRepository      $resultsRepo
     * @param ScoresRepository       $scoresRepo
     * @param ScoringQueueRepository $scoringQueueRepo
     * @param UsersRepository        $usersRepo
     * @param Reliability            $reliability
     * @param int                    $maxScoresPerQuery
     * @param int                    $queuePriority
     */
    public function __construct(
        User $user,
        QueriesRepository $queriesRepo,
        ResultsRepository $resultsRepo,
        ScoresRepository $scoresRepo,
        ScoringQueueRepository $scoringQueueRepo,
        UsersRepository $usersRepo,
        Reliability $reliability,
        $maxScoresPerQuery,
        $queuePriority
    ) {
        $this->user = $user;
        $this->resultsRepo = $resultsRepo;
        $this->scoresRepo = $scoresRepo;
        $this->scoringQueueRepo = $scoringQueueRepo;
        $this->queriesRepo = $queriesRepo;
        $this->usersRepo = $usersRepo;
        $this->reliability = $reliability;
        $this->maxScoresPerQuery = $maxScoresPerQuery;
        $this->queuePriority = $queuePriority;
    }

    public function nextQueryId()
    {
        return $this->scoringQueueRepo->pop($this->user);
    }

    public function getQuery($queryId)
    {
        return $this->queriesRepo->getQuery($queryId);
    }

    public function getQueryResults($queryId)
    {
        $maybeResults = $this->resultsRepo->getQueryResults($queryId);
        if ($maybeResults->isEmpty()) {
            return $maybeResults;
        }

        $results = $this->shufflePreserveKeys(
            $maybeResults->get(),
            $this->user->uid
        );

        return new Some($results);
    }

    public function skipQuery($queryId)
    {
        $this->queriesRepo->markQuerySkipped($this->user, $queryId);
        $this->scoringQueueRepo->unassignUser($this->user);
    }

    public function saveScores($queryId, array $scores)
    {
        $this->scoresRepo->storeQueryScores($this->user, $queryId, $scores);
        $this->scoringQueueRepo->markScored($this->user, $queryId);
        $this->updateReliability($queryId);
    }

    public function updateUserStorage()
    {
        $this->userRepo->updateUser($this->user);
    }
    /**
     * PHP's shuffle function loses the keys. So sort the keys
     * and make a new array based on the order of sorted keys.
     * Additionally php's shuffle is automatically seeded so we
     * can't get the same order across requests. Fix that by using
     * a local fisher yates implementation.
     *
     * @param array $array
     *
     * @return array
     */
    private function shufflePreserveKeys(array $array, $seed)
    {
        $keys = $this->fisherYatesShuffle(array_keys($array), $seed);
        $result = array();
        foreach ($keys as $key) {
            $result[$key] = $array[$key];
        }

        return $result;
    }

    /**
     * @param array $array Must be numerically indexed starting
     *                     from 0 with no gaps.
     * @param int   $seed
     *
     * @return array
     */
    private function fisherYatesShuffle(array $array, $seed)
    {
        mt_srand($seed);
        for ($i = count($array) - 1; $i > 0; --$i) {
            $j = mt_rand(0, $i);
            $tmp = $array[$i];
            $array[$i] = $array[$j];
            $array[$j] = $tmp;
        }

        return $array;
    }

    public function updateReliability($queryId)
    {
        // If there are still pending scores do nothing
        $pendingCount = $this->scoringQueueRepo->getNumberPending([$queryId]);
        if (isset($pendingCount[$queryId]) && $pendingCount[$queryId] > 0) {
            return self::RELIABILITY_PENDING;
        }

        // If we meet the reliability threshold, then woohoo! mark the
        // scores and declare victory.
        list($acceptable, $alpha, $oddOneOut) = $this->reliability->check(
            $queryId,
            Reliability::DEFAULT_THRESHOLD
        );
        if ($acceptable) {
            $this->scoresRepo->markReliable($queryId, $oddOneOut);

            return self::RELIABILITY_GOOD;
        }

        // Request another set of scores, if that is an option
        $numberOfScores = $this->reliability->countScores($queryId);
        if ($numberOfScores < $this->maxScoresPerQuery) {
            $this->scoringQueueRepo->insert($queryId, [$this->queuePriority]);

            return self::RELIABILITY_PENDING;
        }

        // We wont be requesting any more scores, try one more time with a reduced
        // agreement threshold. If not we just give up, and the query will need
        // to be manually reviewed.
        list($acceptable, $alpha, $oddOneOut) = $this->reliability->check(
            $queryId,
            Reliability::REDUCED_THRESHOLD
        );
        if ($acceptable) {
            $this->scoresRepo->markReliable($queryId, $oddOneOut);

            return self::RELIABILITY_GOOD;
        }

        return self::RELIABILITY_BAD;
    }
}
