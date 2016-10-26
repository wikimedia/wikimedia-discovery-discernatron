<?php

namespace WikiMedia\RelevanceScoring;

use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

/**
 * Measure the level of agreement between multiple graders of relevance
 * for a query.
 */
class Reliability
{
    const DEFAULT_THRESHOLD = 0.45;
    const REDUCED_THRESHOLD = 0.40;

    /** @var ScoresRepository */
    private $scoresRepo;

    /** @var array memoization cache for self::getScoresForReliabilityCalc */
    private $scores = [];

    /**
     * @param ScoresRepository $scoresRepo
     */
    public function __construct(ScoresRepository $scoresRepo)
    {
        $this->scoresRepo = $scoresRepo;
    }

    /**
     * @param int $queryId
     *
     * @return int Number of users that have scored the query
     */
    public function countScores($queryId)
    {
        $scores = $this->getScoresForReliabilityCalc($queryId);

        return count($scores);
    }

    /**
     * @param int $queryId
     * @param int $threshold The threshold below which a set of scores is unreliable
     *
     * @return array Three value tuple, first containing a boolean for if this
     *               passes the reliability threshold, second the alpha value obtained,
     *               and third containig either the integer user id excluded to get
     *               that value, or null if that was not necessary
     */
    public function check($queryId, $threshold)
    {
        $scores = $this->getScoresForReliabilityCalc($queryId);

        if (count($scores) < 2) {
            return [false, 0, null];
        }

        $alpha = KrippendorffAlpha::ordinal($scores, 0, 3);
        if ($alpha >= $threshold) {
            return [true, $alpha, null];
        }

        $oddOneOut = null;
        $maxAlpha = $alpha;
        if (count($scores) > 2) {
            foreach (array_keys($scores) as $userId) {
                $tmp = $scores;
                unset($tmp[$userId]);
                $alpha = KrippendorffAlpha::ordinal($tmp, 0, 3);
                if ($alpha > $threshold && $alpha > $maxAlpha) {
                    $oddOneOut = $userId;
                    $maxAlpha = $alpha;
                }
            }
            if ($oddOneOut !== null) {
                return [true, $maxAlpha, $oddOneOut];
            }
        }

        return [false, $maxAlpha, $oddOneOut];
    }

    private function getScoresForReliabilityCalc($queryId)
    {
        if (isset($this->scores[$queryId])) {
            return $this->scores[$queryId];
        }

        $scores = $this->scoresRepo->getRawScoresForQuery($queryId);

        // While all new data stores null for ungraded queries, there is some
        // historical data that does not. Fill in nulls for each userId for
        // resultId's that arn't represented.
        $resultIds = [];
        foreach ($scores as $grades) {
            $resultIds = array_merge($resultIds, array_keys($grades));
        }

        $resultIds = array_unique($resultIds);
        $defaults = array_combine($resultIds, array_fill(0, count($resultIds), null));
        foreach (array_keys($scores) as $userId) {
            $scores[$userId] = $scores[$userId] + $defaults;
            // order scores such that they are all in the same order
            ksort($scores[$userId]);
        }

        $this->scores[$queryId] = $scores;

        return $scores;
    }
}
