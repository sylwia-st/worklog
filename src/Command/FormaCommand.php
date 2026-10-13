<?php

namespace App\Command;

use App\Service\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Forma w tekstach (f = żeńska, m = męska, n = neutralna). Bez argumentu wypisuje obecną – używa tego install.sh.
 */
#[AsCommand(name: 'app:forma', description: 'Pokaż lub ustaw formę w tekstach: f (żeńska), m (męska), n (neutralna)')]
final class FormaCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('forma', InputArgument::OPTIONAL, 'f, m albo n');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forma = $input->getArgument('forma');
        if (null === $forma) {
            $output->writeln($this->settings->get('forma'));

            return Command::SUCCESS;
        }
        if (!isset(Settings::FORMS[$forma])) {
            $output->writeln('<error>Podaj f (żeńska), m (męska) albo n (neutralna).</error>');

            return Command::INVALID;
        }
        $this->settings->save(['forma' => $forma]);
        $output->writeln('Forma w tekstach: '.Settings::FORMS[$forma].'.');

        return Command::SUCCESS;
    }
}
