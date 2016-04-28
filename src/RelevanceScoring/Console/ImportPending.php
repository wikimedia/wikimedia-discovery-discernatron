<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\RelevanceScoring\Import\Importer;
use WikiMedia\RelevanceScoring\Repository\UsersRepository;

class ImportPending extends Command
{
    /** @var UsersRepository */
    private $usersRepository;
    /** @var Importer */
    private $importer;

    public function __construct(
        Importer $importer,
        UsersRepository $usersRepository
    ) {
        parent::__construct('import-pending');
        $this->importer = $importer;
        $this->usersRepository = $usersRepository;
    }

    protected function configure()
    {
        $this->setDescription('Import results for a search query');
        $this->addArgument('user', InputArgument::OPTIONAL, 'Limit updates to the specified user');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('user');
        $userId = null;
        if ($username !== null) {
            $maybeUser = $this->usersRepository->getUserByName($username);
            if ($maybeUser->isEmpty()) {
                $output->writeln('Unknown user');

                return 1;
            }
            $userId = $maybeUser->get()->uid;
        }
        $this->importer->setOutput(function ($line) use ($output) {
            $output->writeln($line);
        });
        list($queries, $results) = $this->importer->importPending(1, $userId);
        $output->writeln("Imported $queries queries with $results results");

        return 0;
    }
}
