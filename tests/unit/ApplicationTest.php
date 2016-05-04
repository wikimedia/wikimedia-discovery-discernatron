<?php

use WikiMedia\RelevanceScoring\Application;

/**
 * Created by PhpStorm.
 * User: ebernhardson
 * Date: 4/20/16
 * Time: 3:23 PM
 */
class ApplicationTest extends \PHPUnit_Framework_TestCase
{
    public function instantiateProvider()
    {
        $app = require __DIR__.'/../../app.php';

        $session = $this->getMock(\Symfony\Component\HttpFoundation\Session\Session::CLASS);
        $session->expects($this->any())
            ->method('get')
            ->with('user')
            ->will($this->returnValue(new \WikiMedia\OAuth\User()));
        $app['session'] = $session;

        $tests = [];
        foreach ($app->keys() as $serviceId) {
            if (strpos($serviceId, 'search.') === 0) {
                $tests[] = [$app, $serviceId];
            }
        }
        return $tests;
    }

    /**
     * @dataProvider instantiateProvider
     * @param Application $app
     * @param string $serviceId
     */
    public function testInstantiate(Application $app, $serviceId)
    {
            $app[$serviceId];
    }
}
