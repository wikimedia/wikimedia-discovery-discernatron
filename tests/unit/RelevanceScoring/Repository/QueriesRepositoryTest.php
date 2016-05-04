<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;

class QueriesRepositoryTest extends BaseRepositoryTest
{
    public function testGetRandomUngradedQueryDoesNotBlowUp()
    {
        // User doesn't need to exist, just magic up something with an id
        $user = new User();
        $user->uid = 1234;

        $repo = new QueriesRepository($this->db);
        $repo->getRandomUngradedQuery($user);
        // Didn't throw an exception trying to build the query
        $this->assertTrue(true);
    }
}
