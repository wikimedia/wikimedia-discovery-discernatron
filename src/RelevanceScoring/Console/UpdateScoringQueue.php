<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;
use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;

class UpdateScoringQueue extends Command
{
    /** @var QueriesRepository */
    private $queriesRepo;
    /** @var ScoringQueueRepository */
    private $scoringQueueRepo;
    /** @var ScoresRepository */
    private $scoresRepo;

    public function __construct(
        QueriesRepository $queriesRepo,
        ScoringQueueRepository $scoringQueueRepo,
        ScoresRepository $scoresRepo
    ) {
        parent::__construct('scoring-queue:update');
        $this->queriesRepo = $queriesRepo;
        $this->scoringQueueRepo = $scoringQueueRepo;
        $this->scoresRepo = $scoresRepo;
    }

    protected function configure()
    {
        $this->setDescription('Manage the scoring queue');
        $this->addOption(
            'num',
            null,
            InputOption::VALUE_OPTIONAL,
            'Total number of scores desired'
        );
        $this->addArgument(
            'wiki',
            InputArgument::OPTIONAL,
            'Only update the specified wiki'
        );
        $this->addArgument(
            'query',
            InputArgument::OPTIONAL,
            'Only update the specified query. Wiki must be provided.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $num = $input->getOption('num') ?: $this->scoringQueueRepo->getDefaultNumSlots();
        $wiki = $input->getArgument('wiki');
        $query = $input->getArgument('query');

        $queryIds = $this->getQueryIds($output, $wiki, $query);
        if ($queryIds === null) {
            return 1;
        }

        $count = 0;
        $scores = $this->scoresRepo->getNumberOfScores($queryIds);
        $pending = $this->scoringQueueRepo->getNumberPending($queryIds);
        foreach ($queryIds as $queryId) {
            $needed = $num;
            $have = 0;
            if (isset($scores[$queryId])) {
                $have += $scores[$queryId];
            }
            if (isset($pending[$queryId])) {
                $have += $pending[$queryId];
            }
            if ($needed > $have) {
                $count += $this->scoringQueueRepo->insert(
                    $queryId,
                    $needed - $have,
                    // shifts the priority so ungraded queries still have
                    // higher priority
                    $have
                );
            }
        }

        $output->writeln("Added $count items to queue for ".count($queryIds).' queries');

        return 0;
    }

    private function getQueryIds(OutputInterface $output, $wiki, $query)
    {
        if ($query !== null) {
            if ($wiki === null) {
                $output->writeln('wiki is required.');

                return;
            }

            $maybeQueryId = $this->queriesRepo->findQueryId($wiki, $query);
            if ($maybeQueryId->isEmpty()) {
                $output->writeln('Unknown query');

                return;
            }
            $maybeQuery = $this->queriesRepo->getQuery($maybeQueryId->get());
            if ($maybeQuery->isEmpty()) {
                $output->writeln('Found query id but no query?!?');

                return;
            }
            $query = $maybeQuery->get();
            if (!$query['imported']) {
                $output->writeln('Query has not been imported yet');

                return;
            }

            return [$maybeQueryId->get()];
        } elseif ($wiki !== null) {
            return $this->queriesRepo->findImportedQueryIdsForWiki($wiki);
        } else {
            return $this->queriesRepo->findAllImportedQueryIds();
        }
    }
}
