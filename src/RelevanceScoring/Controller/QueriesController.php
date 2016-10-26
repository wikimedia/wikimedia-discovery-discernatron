<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Application;
use WikiMedia\RelevanceScoring\Assert\MinimumSubmitted;
use WikiMedia\RelevanceScoring\QueriesManager;

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
    /** @var QueriesManager */
    private $queriesManager;
    /** @var string[] */
    private $wikis;

    public function __construct(
        Application $app,
        User $user,
        Twig_Environment $twig,
        FormFactory $formFactory,
        QueriesManager $queriesManager,
        array $wikis
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->twig = $twig;
        $this->formFactory = $formFactory;
        $this->queriesManager = $queriesManager;
        $this->wikis = $wikis;
    }

    public function instructions()
    {
        return $this->twig->render('instructions.twig');
    }

    public function nextQuery(Request $request)
    {
        $maybeId = $this->queriesManager->nextQueryId();
        $params = [];
        if ($request->query->get('saved')) {
            $params['saved'] = 1;
        }
        if ($maybeId->isEmpty()) {
            return $this->twig->render('all_scored.twig', $params);
        } else {
            $params['queryId'] = $maybeId->get();

            return $this->app->redirect($this->app->path('query_by_id', $params));
        }
    }

    public function skipQueryById(Request $request, $queryId)
    {
        $maybeQuery = $this->queriesManager->getQuery($queryId);
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
            $this->queriesManager->skipQuery($queryId);
        }

        return $this->app->redirect($this->app->path('next_query'));
    }

    public function queryById(Request $request, $queryId)
    {
        $maybeQuery = $this->queriesManager->getQuery($queryId);
        if ($maybeQuery->isEmpty()) {
            // @todo 404
            throw new \Exception('Query not found');
        }
        $query = $maybeQuery->get();
        if (!$query['imported']) {
            return $this->twig->render('query_not_imported.twig', [
                'query' => $query,
            ]);
        }

        $maybeResults = $this->queriesManager->getQueryResults($queryId);
        if ($maybeResults->isEmpty()) {
            throw new \Exception('No results found for query');
        }

        // When encoded to json we will lose the ordering, so
        // add a key to identify the order
        $position = 0;
        $results = $maybeResults->get();
        foreach (array_keys($results) as $id) {
            $results[$id]['order'] = $position++;
        }

        $form = $this->createScoringForm($results);
        $form->handleRequest($request);

        if ($form->isValid() && !$request->request->has('cards')) {
            $this->queriesManager->saveScores($queryId, $form->getData());

            return $this->app->redirect($this->app->path('next_query', ['saved' => 1]));
        }

        $template = $this->chooseScoringTemplate($request);

        return $this->twig->render($template, [
            'query' => $query,
            'results' => $results,
            'resultsList' => array_values($results),
            'form' => $form->createView(),
            'saved' => (bool) $request->query->get('saved'),
            'skipForm' => $this->createSkipForm($queryId)->createView(),
            'baseWikiUrl' => $this->getBaseUrl($query['wiki']),
            'showErrors' => !$request->request->has('cards'),
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
            ->setAction($this->app->path('skip_query_by_id', ['queryId' => $queryId]))
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

    private function chooseScoringTemplate(Request $request)
    {
        $fromQuery = $request->query->get('cards', null);
        if ($fromQuery === null) {
            $fromQuery = $request->request->get('cards', null);
        }

        if ($fromQuery !== null) {
            // override requested
            $interface = (bool) $fromQuery
                ? 'solitaire'
                : 'classic';
            if ($interface !== $this->user->extra['scoringInterface']) {
                $this->user->extra['scoringInterface'] = $interface;
                $this->queriesManager->updateUserStorage();
            }
        }

        switch ($this->user->extra['scoringInterface']) {
        case 'solitaire':
            return 'score_query_cards.twig';
        case 'classic':
        default:
            return 'score_query.twig';
        }
    }
}
