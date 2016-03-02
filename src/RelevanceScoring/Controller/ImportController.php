<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Application;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig_Environment;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;

class ImportController
{
    /** @var Application */
    private $app;
    /** @var User */
    private $user;
    /** @var FormFactory */
    private $formFactory;
    /** @var QueriesRepository */
    private $queriesRepo;
    /** @var Twig_Environment */
    private $twig;
    /** @var string[] */
    private $wikis;

    public function __construct(
        Application $app,
        User $user,
        Twig_Environment $twig,
        FormFactory $formFactory,
        QueriesRepository $queriesRepo,
        array $wikis
    ) {
        $this->app = $app;
        $this->user = $user;
        $this->formFactory = $formFactory;
        $this->queriesRepo = $queriesRepo;
        $this->twig = $twig;
        $this->wikis = $wikis;
    }

    public function getRoot()
    {
        return $this->app->redirect($this->app->path('import_query'));
    }

    public function importQuery(Request $request)
    {
        // @todo add validation constraints
        $wikis = array_keys($this->wikis);
        $form = $this->formFactory->createBuilder('form')
            ->add('wiki', 'choice', [
                'choices' => array_combine($wikis, $wikis),
            ])
            ->add('query')
            ->getForm();

        $form->handleRequest($request);

        $failure = false;
        if ($form->isValid()) {
            $data = $form->getData();
            try {
                $this->queriesRepo->createQuery(
                    $this->user,
                    $data['wiki'],
                    $data['query']
                );
            } catch (Exception\DuplicateQueryException $e) {
                $failure = 'Query already exists';
            }

            if (!$failure) {
                return $this->app->redirect($this->app->path('import_query', ['saved' => 1]));
            }
        }

        return $this->twig->render('import_query.twig', [
            'form' => $form->createView(),
            'saved' => $request->query->get('saved'),
            'failure' => $failure,
        ]);
    }
}
