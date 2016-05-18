<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Closure;
use Twig_Environment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Application;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class ScoresController
{
    /** @var Application */
    private $app;
    /** @var User */
    private $user;
    /** @var Twig_Environment */
    private $twig;
    /** @var QueriesRepository */
    private $queriesRepo;
    /** @var ScoresRepository */
    private $scoresRepo;

    public function __construct(
        Application $app,
        User $user,
        Twig_Environment $twig,
        QueriesRepository $queriesRepo,
        ScoresRepository $scoresRepo
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->twig = $twig;
        $this->queriesRepo = $queriesRepo;
        $this->scoresRepo = $scoresRepo;
    }

    // This is a very sad pagination mechanic, only offering forward pagination. 
    // Better solutions get increasingly more complicated though.
    private function paginate(Request $request, Closure $fn, $defaultLimit = 20)
    {
        // Collect data and do basic validation
        $startingAtId = $request->query->get('offset', '0');
        if (!ctype_digit($startingAtId) || $startingAtId < 0) {
            throw new \Exception('Invalid offset: '.$startingAtId);
        }
        $limit = $request->query->get('limit', $defaultLimit);
        if (!ctype_digit($limit)) {
            $limit = $defaultLimit;
        }

        $found = $fn((int) $startingAtId, (int) $limit + 1);
        if (count($found) > $limit) {
            $last = array_pop($found);

            return [$found, ['offset' => $last['id'], 'limit' => $limit]];
        } else {
            return [$found, null];
        }
    }

    public function scoredQueries(Request $request)
    {
        list($queries, $nextQueryString) = $this->paginate($request, function ($after, $limit) {
            return $this->scoresRepo->getScoredQueries($this->user, $after, $limit);
        });

        $params = ['queries' => $queries, 'next_page' => null];
        if ($nextQueryString !== null) {
            $params['next_page'] = $this->app->path('scores', $nextQueryString);
        }

        return $this->twig->render('scored_queries.twig', $params);
    }

    public function scores(Request $request)
    {
        $scores = $this->scoresRepo->getAll();
        $renderer = $this->getRenderer($request, 'scores.twig');

        return $renderer([
            'scores' => $scores,
        ]);
    }

    public function scoresByQueryId(Request $request, $id)
    {
        $maybeQuery = $this->queriesRepo->getQuery($id);
        if ($maybeQuery->isEmpty()) {
            throw new \Exception('Not Found');
        }

        $scores = $this->scoresRepo->getScoresForQuery($id);
        if (!$scores) {
            throw new \Exception('No scores available');
        }
        $renderer = $this->getRenderer($request, 'query_scores.twig');

        return $renderer([
            'query' => $maybeQuery->get(),
            'scores' => $scores,
        ]);
    }

    private function getRenderer(Request $request, $templateName)
    {
        $supported = [
            'html' => function ($params) use ($templateName) {
                return $this->twig->render($templateName, $params);
            },
            'json' => function ($scores) {
                return new Response(
                    json_encode($params['scores']),
                    Response::HTTP_OK,
                    ['Content-Type' => 'application/json']
                );
            },
        ];

        $accepted = $request->getAcceptableContentTypes();
        if (count($accepted) === 1 && $accepted[0] === '*/*') {
            $accepted = [];
        }

        if ($request->query->get('json')) {
            array_unshift($accepted, 'application/json');
        }

        foreach ($accepted as $type) {
            $format = $request->getFormat($type);
            if (isset($supported[$format])) {
                return $supported[$format];
            }
        }

        return $supported['html'];
    }
}
