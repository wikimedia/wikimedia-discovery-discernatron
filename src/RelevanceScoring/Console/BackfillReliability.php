<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\RelevanceScoring\QueriesManager;
use WikiMedia\RelevanceScoring\Reliability;
use WikiMedia\RelevanceScoring\Repository\ScoresRepository;

class BackfillReliability extends Command
{
    /** @var ScoresRepository */
    private $scoresRepo;
    /** @var Reliability */
    private $reliability;
    /** @var QueriesManager */
    private $queriesManager;

    public function __construct(ScoresRepository $scoresRepo, Reliability $reliability, QueriesManager $queriesManager)
    {
        parent::__construct('backfill-reliability');
        $this->scoresRepo = $scoresRepo;
        $this->reliability = $reliability;
        $this->queriesManager = $queriesManager;
    }

    protected function configure()
    {
        $this->setDescription('Update all queries reliability based on inter-rater reliability metrics');
        $this->addOption(
            'report',
            null,
            InputOption::VALUE_NONE,
            'Rather than backfilling, report on the agreement level of currently scored queries'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $listOfQueries = $this->scoresRepo->getListOfScoredQueries();

        if ($input->getOption('report')) {
            $output->writeln($this->report($listOfQueries));
        } else {
            $output->writeln($this->update($listOfQueries));
        }

        return 0;
    }

    private function update(array $listOfQueries)
    {
        $pending = $good = $bad = $unknown = 0;
        foreach ($listOfQueries as $row) {
            $status = $this->queriesManager->updateReliability($row['id']);
            $output[] = sprintf("%-8s: %s", $status, $row['query']);
            switch ($status) {
            case QueriesManager::RELIABILITY_PENDING:
                $pending++;
                break;
            case QueriesManager::RELIABILITY_GOOD:
                $good++;
                break;
            case QueriesManager::RELIABILITY_BAD:
                $bad++;
                break;
            default:
                $unknown++;
                break;
            }
        }

        $output[] = '';
        $output[] = "Pending: $pending";
        $output[] = "Good: $good";
        $output[] = "Bad: $bad";
        $output[] = "Unknown: $unknown";

        return implode("\n", $output);
    }

    private function report(array $listOfQueries, $maxLen)
    {
        $output = [
            "  alpha : cnt :      type : query",
            " ---------------------------------",
        ];
        foreach ($listOfQueries as $row) {
            if ($row['count'] < 2) {
                $alpha = null;
                $output[] = sprintf("unknown : %3d :   unknown : %s", $row['count'], $row['query']);
            } else {
                list($acceptable, $alpha, $oddOneOut) = $this->reliability->check(
                    $row['id'],
                    Reliability::DEFAULT_THRESHOLD
                );
                $type = $oddOneOut ? 'oddOneOut' : 'all';
                $output[] = sprintf(" % 6.3f : %3d : %9s : %s", $alpha, $row['count'], $type, $row['query']);
            }
        }

        return implode("\n", $output);
    }
}
