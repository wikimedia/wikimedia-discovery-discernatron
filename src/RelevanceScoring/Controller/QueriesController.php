<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Application;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class QueriesController
{
    /** @var Application */
    private $app;
    /** @var User */
    private $user;
    /** @var Twig_Environment */
    private $twig;
    /** @var FormFactory */
    private $formFactory;
    /** @var QueriesRepository */
    private $queriesRepo;
    /** @var ResultsRepository */
    private $resultsRepo;
    /** @var ScoresRepository */
    private $scoresRepo;
    /** @var string[] */
    private $wikis;

    public function __construct(
        Application $app,
        User $user,
        Twig_Environment $twig,
        FormFactory $formFactory,
        QueriesRepository $queriesRepo,
        ResultsRepository $resultsRepo,
        ScoresRepository $scoresRepo,
        array $wikis
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->twig = $twig;
        $this->formFactory = $formFactory;
        $this->queriesRepo = $queriesRepo;
        $this->resultsRepo = $resultsRepo;
        $this->scoresRepo = $scoresRepo;
        $this->wikis = $wikis;
    }

    public function randomQuery(Request $request, $wiki = null)
    {
        $maybeId = $this->queriesRepo->getRandomUngradedQuery($this->user, $wiki);
        $params = [];
        if ($request->query->get('saved')) {
            $params['saved'] = 1;
        }
        if ($maybeId->isEmpty()) {
            return $this->twig->render('all_scored.twig', $params);
        } else {
            $params['id'] = $maybeId->get();

            return $this->app->redirect($this->app->path('query_by_id', $params));
        }
    }

    public function queryById(Request $request, $id)
    {
        $maybeQuery = $this->queriesRepo->getQuery($id);
        if ($maybeQuery->isEmpty()) {
            // @todo 404
            throw new \Exception('Query not found');
        }

        $maybeResults = $this->resultsRepo->getQueryResults($id);
        if ($maybeResults->isEmpty()) {
            throw new \Exception('No results found for query');
        }

        $query = $maybeQuery->get();
        $results = $maybeResults->get();

        $builder = $this->formFactory->createBuilder('form');
        foreach ($results as $resultId => $title) {
            $builder->add($resultId, 'choice', [
                'label' => $title,
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Relevant',
                    'Probably',
                    'Maybe',
                    'Irrelevant',
                ],
            ]);
        }

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isValid()) {
            $data = $form->getData();
            $this->scoresRepo->storeQueryScores($this->user, $id, $data);

            return $this->app->redirect($this->app->path('random_query', ['saved' => 1]));
        }

        $wiki = reset($results)['wiki'];
        $parts = parse_url($this->wikis[$wiki]);
        $baseUrl = $parts['scheme'].'://'.$parts['host'].'/wiki/';

        return $this->twig->render('score_query.twig', [
            'query' => $query,
            'results' => $results,
            'wikiBaseUrl' => $baseUrl,
            'form' => $form->createView(),
            'saved' => (bool) $request->query->get('saved'),
        ]);
    }
}
