<?php

namespace WikiMedia\RelevanceScoring\Controller;

use Twig_Environment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WikiMedia\RelevanceScoring\Application;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class ScoresController
{
    /** @var Application */
    private $app;
    /** @var Twig_Environment */
    private $twig;
    /** @var ScoresRepository */
    private $scoresRepo;

    public function __construct(
        Application $app,
        Twig_Environment $twig,
        ScoresRepository $scoresRepo
    ) {
        $this->app = $app;
        $this->twig = $twig;
        $this->scoresRepo = $scoresRepo;
    }

    public function scores(Request $request)
    {
        $supported = [
            'html' => function ($scores) {
                return $this->twig->render('scores.twig', [
                    'scores' => $scores,
                ]);
            },
            'json' => function ($scores) {
                return new Response(
                    json_encode($scores),
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

        $scores = $this->scoresRepo->getAll();
        foreach ($accepted as $type) {
            $format = $request->getFormat($type);
            if (isset($supported[$format])) {
                return $supported[$format]($scores);
            }
        }

        return $supported['html']($scores);
    }
}
