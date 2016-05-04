<?php

namespace WikiMedia\Test\RelevanceScoring\Repository;

use Doctrine\DBAL\Connection;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\UsersRepository;

abstract class BaseRepositoryTest extends \PHPUnit_Framework_TestCase
{
    /** @var Connection */
    protected $db;

    public function setUp()
    {
        // @todo There should be some way to use an app.test.ini file from app.php
        //       or some such, i'm just not sure how to know.
        $app = require __DIR__.'/../../../../app.php';
        $dbOptions = $app['db.options'];
        $dbOptions['dbname'] = 'relevance_test';
        $app['db.options'] = $dbOptions;

        $tables = ['users', 'queries', 'results', 'results_sources', 'scores'];
        $this->db = $app['db'];
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->exec("TRUNCATE `$table`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param int|null $id
     *
     * @return User
     */
    protected function genTestUser($id = null)
    {
        $user = new User();
        $user->uid = $id ?: 1234;
        $user->name = 'testUser'.$user->uid;
        $user->extra['editCount'] = 0;

        $repo = new UsersRepository($this->db);
        $repo->updateUser($user);

        return $user;
    }
}
