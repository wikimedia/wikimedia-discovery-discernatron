<?php

namespace WikiMedia\RelevanceScoring\Console;

use Knp\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig_Environment;

class CacheClear extends Command
{
    /** @var Twig_Environment */
    private $twig;

	public function __construct(Twig_Environment $twig)
	{
        parent::__construct('cache:clear');
        $this->twig = $twig;
    }

    protected function configure()
    {
        $this->setDescription('Clear the twig cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
		$this->twig->clearCacheFiles();
        $output->writeln("Cache cleared");

        return 0;
    }
}
