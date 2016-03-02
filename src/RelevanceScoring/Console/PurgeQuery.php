<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ResultsRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class PurgeQuery extends Command
{
    private $queriesRepository;
    private $resultsRepository;
    private $scoresRepository;

    public function __construct(
        QueriesRepository $queriesRepository,
        ResultsRepository $resultsRepository,
        ScoresRepository $scoresRepository
    ) {
        parent::__construct('purge-query');
        $this->queriesRepository = $queriesRepository;
        $this->resultsRepository = $resultsRepository;
        $this->scoresRepository = $scoresRepository;
    }

    protected function configure()
    {
        $this->setDescription('Deletes (really!) all information about a query and the related scored results');
        $this->addArgument(
            'wiki',
            InputArgument::REQUIRED,
            'The wiki to query'
        );
        $this->addArgument(
            'query',
            InputArgument::REQUIRED,
            'The query to import'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $maybeQueryId = $this->queriesRepository->findQueryId(
            $input->getArgument('wiki'),
            $input->getArgument('query')
        );

        if ($maybeQueryId->isEmpty()) {
            $output->writeln('No query could be located.');

            return 1;
        }

        $queryId = $maybeQueryId->get();
        $numScores = $this->scoresRepository->deleteScoresByQueryId($queryId);
        $numResults = $this->resultsRepository->deleteResultsByQueryId($queryId);
        $this->queriesRepository->deleteQueryById($queryId);
        $output->writeln("Successfully deleted the query along with $numResults results and $numScores scores");

        return 0;
    }
}
