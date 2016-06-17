<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\RelevanceScoring\Repository\ScoringQueueRepository;

class ScoringQueueUnassignOld extends Command
{
    /** @var ScoringQueueRepository */
    private $scoringQueueRepo;

    public function __construct(ScoringQueueRepository $scoringQueueRepo)
    {
        parent::__construct('scoring-queue:unassign-old');
        $this->scoringQueueRepo = $scoringQueueRepo;
    }

    protected function configure()
    {
        $this->setDescription('Unassign abandoned items in the scoring queue');
        $this->addOption(
            'age',
            null,
            InputOption::VALUE_OPTIONAL,
            'Age, in seconds, to consider a queue item old enough to unassign. Defaults to 900'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $age = $input->getOption('age') ?: 900;
        if (!ctype_digit($age)) {
            $output->writeln('age must be a positive integer');

            return 1;
        }

        $count = $this->scoringQueueRepo->unassignOld($age);
        $output->writeln("Unassigned $count items.");

        return 0;
    }
}
