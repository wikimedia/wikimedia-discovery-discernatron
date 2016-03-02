<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WikiMedia\OAuth\User;
use WikiMedia\RelevanceScoring\Exception;
use WikiMedia\RelevanceScoring\Import\Importer;
use WikiMedia\RelevanceScoring\Repository\QueriesRepository;
use WikiMedia\RelevanceScoring\Repository\UsersRepository;

class Import extends Command
{
    private $importer;
    private $usersRepository;
    private $queriesRepository;

    public function __construct(
        Importer $importer,
        UsersRepository $usersRepository,
        QueriesRepository $queriesRepository
    ) {
        parent::__construct('import');
        $this->importer = $importer;
        $this->usersRepository = $usersRepository;
        $this->queriesRepository = $queriesRepository;
    }

    protected function configure()
    {
        $this->setDescription('Import results for a search query');
        $this->addArgument(
            'username',
            InputArgument::REQUIRED,
            'The username to attribute import to'
        );
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
        $this->addOption(
            'immediate',
            null,
            InputOption::VALUE_NONE,
            'Import now, rather than marking as pending'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('username');
        $maybeUser = $this->usersRepository->getUserByName($username);
        if ($maybeUser->isEmpty()) {
            $output->writeln("Could not locate user named $username");

            return 1;
        }

        $user = $maybeUser->get();
        $wiki = $input->getArgument('wiki');
        $immediate = $input->getOption('immediate');
        $import = $this->makeImporter($immediate, $output, $user, $wiki);

        $query = $input->getArgument('query');
        if ($query === '-') {
            while (!feof(STDIN)) {
                $query = trim(fgets(STDIN));
                if ($query) {
                    $import($query);
                }
            }
        } else {
            $import($query);
        }

        $output->writeln($immediate ? 'Imported.' : 'Inserted pending.');

        return 0;
    }

    /**
     * @param bool            $immediate
     * @param OutputInterface $output
     * @param User            $user
     * @param string          $wiki
     *
     * @return \Closure
     */
    private function makeImporter($immediate, OutputInterface $output, User $user, $wiki)
    {
        if ($immediate) {
            return function ($query) use ($output, $user, $wiki) {
                try {
                    $results = $this->importer->import($user, $wiki, $query);
                    $output->writeln("Imported $results results for '$query'");
                } catch (Exception\DuplicateQueryException $e) {
                    $output->writeln("Previously imported '$query', not re-importing");
                }
            };
        } else {
            return function ($query) use ($output, $user, $wiki) {
                try {
                    $this->queriesRepository->createQuery($user, $wiki, $query);
                    $output->writeln("Created pending query for '$query'");
                } catch (Exception\DuplicateQueryException $e) {
                    $output->writeln("Previously imported '$query', not re-importing");
                }
            };
        }
    }
}
