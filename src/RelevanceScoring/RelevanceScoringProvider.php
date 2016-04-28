<?php

namespace WikiMedia\RelevanceScoring;

use Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use WikiMedia\RelevanceScoring\Console\CacheClear;
use WikiMedia\RelevanceScoring\Console\Import;
use WikiMedia\RelevanceScoring\Console\ImportPending;
use WikiMedia\RelevanceScoring\Console\PurgeQuery;
use WikiMedia\RelevanceScoring\Controller\ImportController;
use WikiMedia\RelevanceScoring\Controller\QueriesController;
use WikiMedia\RelevanceScoring\Controller\ResultsController;
use WikiMedia\RelevanceScoring\Controller\ScoresController;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;
use WikiMedia\RelevanceScoring\Import\HtmlResultGetter;
use WikiMedia\RelevanceScoring\Import\Importer;
use WikiMedia\RelevanceScoring\Import\MediaWikiResultGetter;

class RelevanceScoringProvider implements ControllerProviderInterface, ServiceProviderInterface
{
    public function boot(\Silex\Application $app)
    {
    }

    public function connect(\Silex\Application $app)
    {
        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        $controllers->match('/import',       'search.controller.import:getRoot')
            ->bind('import');
        $controllers->match('/import/query', 'search.controller.import:importQuery')
            ->bind('import_query');

        $controllers->get('/scores', 'search.controller.scores:scores')
            ->bind('scores');

        $controllers->get('/query',             'search.controller.queries:randomQuery')
            ->bind('random_query');
        $controllers->get('/query/wiki/{wiki}', 'search.controller.queries:randomQuery')
            ->bind('random_query_by_wiki');
        $controllers->match('/query/id/{id}',   'search.controller.queries:queryById')
            ->bind('query_by_id');

        return $controllers;
    }

    public function register(\Silex\Application $app)
    {
        if (!$app instanceof Application) {
            throw new RuntimeException('Expected custom Application instance.');
        }
        $this->registerRepositories($app);
        $this->registerImporter($app);
        $this->registerConsole($app);
        $this->registerControllers($app);
    }

    private function registerRepositories(Application $app)
    {
        $app['search.repository.queries'] = function () use ($app) {
            return new Repository\QueriesRepository($app['db']);
        };
        $app['search.repository.results'] = function () use ($app) {
            return new Repository\ResultsRepository($app['db']);
        };
        $app['search.repository.scores'] = function () use ($app) {
            return new Repository\ScoresRepository($app['db']);
        };
        $app['search.repository.users'] = function () use ($app) {
            return new Repository\UsersRepository($app['db']);
        };
    }

    private function registerImporter(Application $app)
    {
        $app['search.importer_limit'] = 25;
        $app['search.importer.bing'] = function () use ($app) {
            return new HtmlResultGetter(
                $app['guzzle'],
                $app['search.wikis'],
                'bing',
                'https://www.bing.com/search',
                [
                    'is_valid' => '#b_results',
                    'results' => '#b_results .b_algo',
                    'url' => 'h2 a',
                    'snippet' => '.b_caption p',
                ],
                [
                    'count' => $app['search.importer_limit'],
                ]
            );
        };
        $app['search.importer.google'] = function () use ($app) {
            return new HtmlResultGetter(
                $app['guzzle'],
                $app['search.wikis'],
                'google',
                'https://www.google.com/search',
                [
                    'is_valid' => '#ires',
                    'results' => '#ires .g',
                    'url' => 'h3 a',
                    'snippet' => '.st',
                ],
                [
                    // google white lists a specific set of numbers
                    'num' => array_reduce([100, 50, 40, 30, 20, 10], function ($a, $b) use ($app) {
                        if ($b > $app['search.importer_limit']) {
                            return $b;
                        } else {
                            return $a;
                        }
                    }, 100),
                ]
            );
        };
        $app['search.importer.ddg'] = function () use ($app) {
            return new HtmlResultGetter(
                $app['guzzle'],
                $app['search.wikis'],
                'ddg',
                'https://duckduckgo.com/html/',
                [
                    'is_valid' => '#links',
                    'results' => '#links .web-result',
                    'url' => 'a',
                    'snippet' => '.snippet',
                ]
            );
        };
        $app['search.importer.mediawiki'] = function () use ($app) {
            return new MediaWikiResultGetter(
                $app['guzzle'],
                $app['search.wikis'],
                $app['search.importer_limit']
            );
        };
        $app['search.importer'] = function () use ($app) {
            return new Importer(
                $app['db'],
                $app['search.repository.queries'],
                $app['search.repository.results'],
                $app['search.wikis'],
                [
                    'bing' => $app['search.importer.bing'],
                    'google' => $app['search.importer.google'],
                    'ddg' => $app['search.importer.ddg'],
                    'mediawiki' => $app['search.importer.mediawiki'],
                ]
            );
        };
    }

    private function registerConsole(Application $app)
    {
        $app['search.console.cache-clear'] = function () use ($app) {
            return new CacheClear($app['twig']);
        };

        $app['search.console.import'] = function () use ($app) {
            return new Import(
                $app['search.importer'],
                $app['search.repository.users'],
                $app['search.repository.queries']
            );
        };

        $app['search.console.importPending'] = function () use ($app) {
            return new ImportPending(
                $app['search.importer'],
                $app['search.repository.users']
            );
        };

        $app['search.console.purgeQuery'] = function () use ($app) {
            return new PurgeQuery(
                $app['search.repository.queries'],
                $app['search.repository.results'],
                $app['search.repository.scores']
            );
        };

        $app['search.console'] = [
            'search.console.cache-clear',
            'search.console.import',
            'search.console.importPending',
            'search.console.purgeQuery',
        ];
    }

    private function registerControllers(Application $app)
    {
        $app['search.controller.queries'] = function () use ($app) {
            return new QueriesController(
                $app,
                $app['session']->get('user'),
                $app['twig'],
                $app['form.factory'],
                $app['search.repository.queries'],
                $app['search.repository.results'],
                $app['search.repository.scores'],
                $app['search.wikis']
            );
        };
        $app['search.controller.scores'] = function () use ($app) {
            return new ScoresController(
                $app,
                $app['twig'],
                $app['search.repository.scores']
            );
        };
        $app['search.controller.import'] = function () use ($app) {
            return new ImportController(
                $app,
                $app['session']->get('user'),
                $app['twig'],
                $app['form.factory'],
                $app['search.repository.queries'],
                $app['search.wikis']
            );
        };
    }
}
