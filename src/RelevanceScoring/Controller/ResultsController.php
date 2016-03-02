<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Application;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class ResultsController
{
    /** @var Application */
    private $app;
    /** @var ResultsRepository */
    private $resultsRepo;
    /** @var ScoresRepository */
    private $scoresRepo;
    /** @var User */
    private $user;
    /** @var Twig_Environment */
    private $twig;
    /** @var FormFactory */
    private $formFactory;

    public function __construct(
        Application $app,
        User $user,
        Twig_Environment $twig,
        FormFactory $formFactory,
        ResultsRepository $resultsRepo,
        ScoresRepository $scoresRepo
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->resultsRepo = $resultsRepo;
        $this->scoresRepo = $scoresRepo;
        $this->twig = $twig;
        $this->formFactory = $formFactory;
    }

    public function randomResult(Request $request, $wiki = null)
    {
        $maybeId = $this->resultsRepo->getRandomId($this->user, $wiki);
        $params = [];
        if ($request->query->get('saved')) {
            $params['saved'] = 1;
        }
        if ($maybeId->isEmpty()) {
            return $this->twig->render('all_scored.twig', $params);
        } else {
            $params['id'] = $maybeId->get();

            return $this->app->redirect($this->app->path('result_by_id', $params));
        }
    }

    public function getById(Request $request, $id)
    {
        $maybeResult = $this->resultsRepo->getQueryResult($id);
        if ($maybeResult->isEmpty()) {
            throw new \Exception('Query not found');
        }

        $builder = $this->formFactory->createBuilder('form')
            ->add('score', 'choice', [
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Irrelevant',
                    'Maybe Relevant',
                    'Probably Relevant',
                    'Relevant',
                ],
            ]);

        $form = $builder->getForm();
        $form->handleRequest($request);
        $result = $maybeResult->get();
        
        if ($form->isValid()) {
            $data = $form->getData();
            $this->scoresRepo->storeQueryScore($this->user, $result['query_id'], $id, $data['score']);

            return $this->app->redirect($this->app->path('random_result', ['saved' => 1]));
        }

        $parts = parse_url($this->app['search.wikis'][$result['wiki']]);
        $baseUrl = $parts['scheme'].'://'.$parts['host'].'/wiki/';

        return $this->twig->render('score_result.twig', [
            'result' => $result,
            'wikiBaseUrl' => $baseUrl,
            'form' => $form->createView(),
            'saved' => (bool) $request->query->get('saved'),
        ]);
    }
}
