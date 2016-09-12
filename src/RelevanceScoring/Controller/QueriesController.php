<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Application;
use WikiMedia\RelevanceScoring\Assert\MinimumSubmitted;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;
use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;

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
        ScoringQueueRepository $scoringQueueRepo,
        array $wikis
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->twig = $twig;
        $this->formFactory = $formFactory;
        $this->queriesRepo = $queriesRepo;
        $this->resultsRepo = $resultsRepo;
        $this->scoresRepo = $scoresRepo;
        $this->scoringQueueRepo = $scoringQueueRepo;
        $this->wikis = $wikis;
    }

    public function instructions()
    {
        return $this->twig->render('instructions.twig');
    }

    public function nextQuery(Request $request)
    {
        $maybeId = $this->scoringQueueRepo->pop($this->user);
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

    public function skipQueryById(Request $request, $queryId)
    {
        $maybeQuery = $this->queriesRepo->getQuery($queryId);
        if ($maybeQuery->isEmpty()) {
            // @todo 404
            throw new \Exception('Query not found');
        }

        $form = $this->createSkipForm($queryId);
        $form->handleRequest($request);

        // If the form isn't valid just do nothing, not a big deal. Should
        // look into adding session based notifications to make it easier to
        // tell users about this.
        if ($form->isValid()) {
            $this->queriesRepo->markQuerySkipped($this->user, $queryId);
            $this->scoringQueueRepo->unassignUser($this->user);
        }

        return $this->app->redirect($this->app->path('next_query'));
    }

    public function queryById(Request $request, $queryId)
    {
        $maybeQuery = $this->queriesRepo->getQuery($queryId);
        if ($maybeQuery->isEmpty()) {
            // @todo 404
            throw new \Exception('Query not found');
        }

        $maybeResults = $this->resultsRepo->getQueryResults($queryId);
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
        // When encoded to json we will lose the ordering, so
        // add a key to identify the order
        $position = 0;
        foreach ( array_keys( $results ) as $id ) {
            $results[$id]['order'] = $position++;
        }

        $form = $this->createScoringForm($results);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->scoresRepo->storeQueryScores($this->user, $queryId, $form->getData());
            $this->scoringQueueRepo->markScored($this->user, $queryId);

            return $this->app->redirect($this->app->path('next_query', ['saved' => 1]));
        }

        $template = $request->query->get('cards', false)
            ? 'score_query_cards.twig'
            : 'score_query.twig';

        return $this->twig->render($template, [
            'query' => $query,
            'results' => $results,
            'form' => $form->createView(),
            'saved' => (bool) $request->query->get('saved'),
            'skipForm' => $this->createSkipForm($queryId)->createView(),
            'baseWikiUrl' => $this->getBaseUrl($query['wiki']),
        ]);
    }

    /**
     * @param string $wiki
     *
     * @return string
     */
    private function getBaseUrl($wiki)
    {
        if (!isset($this->wikis[$wiki])) {
            throw new \RuntimeException("Unknown wiki: $wiki");
        }
        $domain = parse_url($this->wikis[$wiki], PHP_URL_HOST);

        return "https://$domain/wiki";
    }

    private function createSkipForm($queryId)
    {
        $builder = $this->formFactory->createBuilder('form')
            ->setAction($this->app->path('skip_query_by_id', ['id' => $queryId]))
            ->add('submit', 'submit', [
                'label' => 'Skip this query',
                'attr' => ['class' => 'btn btn-warning'],
            ]);

        return $builder->getForm();
    }

    private function createScoringForm(array $results)
    {
        $builder = $this->formFactory->createBuilder('form', null, array(
            'constraints' => array(new MinimumSubmitted('80%')),
        ));
        foreach ($results as $resultId => $row) {
            $builder->add($resultId, 'choice', [
                'label' => $row['title'],
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Irrelevant',
                    'Maybe',
                    'Probably',
                    'Relevant',
                ],
            ]);
        }

        return $builder->getForm();
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
}
