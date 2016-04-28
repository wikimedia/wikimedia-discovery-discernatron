<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Application;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Assert\MinimumSubmitted;
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
        $results = $this->shufflePreserveKeys(
            $maybeResults->get(),
            // user id is used to give each user a different order,
            // but the same user gets same order each time.
            $this->user->uid
        );

        $builder = $this->formFactory->createBuilder('form', null, array(
            'constraints' => array(new MinimumSubmitted('80%')),
        ));
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


    /**
     * PHP's shuffle function loses the keys. So sort the keys
     * and make a new array based on the order of sorted keys.
     * Additionally php's shuffle is automatically seeded so we
     * can't get the same order across requests. Fix that by using
     * a local fisher yates implementation.
     *
     * @param array $array
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
     *  from 0 with no gaps.
     * @param int $seed
     * @return array
     */
    private function fisherYatesShuffle(array $array, $seed)
    {
        mt_srand($seed);
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $array[$i];
            $array[$i] = $array[$j];
            $array[$j] = $tmp;
        }

        return $array;
    }
}
