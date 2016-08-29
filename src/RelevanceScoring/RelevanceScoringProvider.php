<?php

namespace WikiMedia\RelevanceScoring;

use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use WikiMedia\RelevanceScoring\Console\CacheClear;
use WikiMedia\RelevanceScoring\Console\Import;
use WikiMedia\RelevanceScoring\Console\ImportPending;
use WikiMedia\RelevanceScoring\Console\PurgeQuery;
use WikiMedia\RelevanceScoring\Console\ScoringQueueUnassignOld;
use WikiMedia\RelevanceScoring\Console\UpdateScoringQueue;
use WikiMedia\RelevanceScoring\Controller\ImportController;
use WikiMedia\RelevanceScoring\Controller\QueriesController;
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

        $controllers->get('/scores', 'search.controller.scores:scoredQueries')
            ->bind('scores');
        $controllers->get('/scores/all', 'search.controller.scores:scores')
            ->bind('all_scores');
        $controllers->get('/scores/{id}', 'search.controller.scores:scoresByQueryId')
            ->bind('query_scores');

        $controllers->get('/instructions',      'search.controller.queries:instructions')
            ->bind('instructions');
        $controllers->get('/query',             'search.controller.queries:nextQuery')
            ->bind('next_query');
        $controllers->match('/query/id/{id}',   'search.controller.queries:queryById')
            ->bind('query_by_id');
        $controllers->post('/query/skip/{id}',   'search.controller.queries:skipQueryById')
            ->bind('skip_query_by_id');

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

        $app->extend('twig', function (\Twig_Environment $twig) {
            $twig->addExtension(new RelevanceScoringTwigExtension());

            return $twig;
        });
    }

    private function registerRepositories(Application $app)
    {
        $app['search.repository.queries'] = function () use ($app) {
            return new Repository\QueriesRepository($app['db']);
        };
        $app['search.repository.results'] = function () use ($app) {
            $repo = new Repository\ResultsRepository(
                $app['db'],
                $app['search.importer_limit']
            );
            $repo->setLogger($app['search.logger']);

            return $repo;
        };
        $app['search.repository.scores'] = function () use ($app) {
            return new Repository\ScoresRepository($app['db']);
        };
        $app['search.repository.scoring_queue'] = function () use ($app) {
            return new Repository\ScoringQueueRepository(
                $app['db'],
                new Util\Calendar(),
                $app['search.scores_per_query']
            );
        };
        $app['search.repository.users'] = function () use ($app) {
            return new Repository\UsersRepository($app['db']);
        };
    }

    private function registerImporter(Application $app)
    {
        $app['search.importer_limit'] = 15;
        $app['search.importer.bing'] = function () use ($app) {
            return new HtmlResultGetter(
                $app['guzzle'],
                $app['search.wikis'],
                'bing',
                'https://www.bing.com/search',
                [
                    'is_valid' => '#b_results',
                    'results' => '#b_results > .b_algo',
                    'url' => 'h2 a',
                    'snippet' => [
                        // standard caption
                        '.b_caption p',
                        // tabbed article summary
                        '#tab_1 .b_imagePair',
                        // sometimes there is no tab 1
                        '#tab_2 .b_imagePair',
                    ],
                ],
                '<strong>',
                '</strong>',
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
                    'results' => '#rso > .g:not(.g-blk), .srg > .g',
                    'url' => 'h3 a',
                    'snippet' => ['.st'],
                ],
                '<em>',
                '</em>',
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
                    'snippet' => ['.snippet, .result__snippet'],
                ],
                '<b>',
                '</b>'
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
                $app['search.repository.scoring_queue'],
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
        $app['search.console.updateScoringQueue'] = function () use ($app) {
            return new UpdateScoringQueue(
                $app['search.repository.queries'],
                $app['search.repository.scoring_queue'],
                $app['search.repository.scores']
            );
        };
        $app['search.console.scoringQueueUnassignOld'] = function () use ($app) {
            return new ScoringQueueUnassignOld(
                $app['search.repository.scoring_queue']
            );
        };

        $app['search.console'] = [
            'search.console.cache-clear',
            'search.console.import',
            'search.console.importPending',
            'search.console.purgeQuery',
            'search.console.updateScoringQueue',
            'search.console.scoringQueueUnassignOld',
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
                $app['search.repository.scoring_queue'],
                $app['search.wikis']
            );
        };
        $app['search.controller.scores'] = function () use ($app) {
            return new ScoresController(
                $app,
                $app['session']->get('user'),
                $app['twig'],
                $app['search.repository.queries'],
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
