<?php

namespace App\Command;

use App\Domain\ReminderSchedule;
use App\Domain\TimeFormat;
use App\Repository\WorkDayRepository;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorkCalendar;
use App\Service\WorklogSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wypisuje, czego brakuje w dzisiejszym raporcie – albo nic, gdy wszystko jest domknięte (i w dzień wolny:
 * weekend, święto, urlop).
 * Kontener nie ma dostępu do pulpitu, więc powiadomienie pokazuje skrypt po stronie hosta (scripts/remind.sh).
 *
 *  --scheduled     wywołanie z timera (co minutę): odpowiada tylko raz dziennie, o godzinie z Ustawień,
 *  --set-time=GG:MM  zmienia godzinę przypomnienia (pusta wartość wyłącza) – używa tego install.sh --remind.
 */
#[AsCommand(name: 'app:remind', description: 'Co zostało do zrobienia w dzisiejszym raporcie')]
final class RemindCommand extends Command
{
    public function __construct(
        private readonly WorkDayRepository $days,
        private readonly WorklogSync $sync,
        private readonly ReportBuilder $reports,
        private readonly Settings $settings,
        private readonly WorkCalendar $calendar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scheduled', null, InputOption::VALUE_NONE, 'Tylko o godzinie przypomnienia z Ustawień, raz dziennie')
            ->addOption('set-time', null, InputOption::VALUE_REQUIRED, 'Ustaw godzinę przypomnienia (GG:MM, pusta = wyłączone)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $setTime = $input->getOption('set-time');
        if (null !== $setTime) {
            $time = '' === trim($setTime) ? '' : TimeFormat::normalizeClock($setTime);
            if (null === $time || ('' !== $time && !ReminderSchedule::inTimerWindow($time))) {
                $output->writeln('<error>Godzinę wpisz w formacie GG:MM, między 06:00 a 22:00.</error>');

                return Command::INVALID;
            }
            $this->settings->save(['remind_time' => $time]);
            $output->writeln('' === $time ? 'Przypomnienie wyłączone.' : 'Przypomnienie pon–pt o '.$time.'.');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        if ($input->getOption('scheduled')) {
            $remindTime = $this->settings->get('remind_time');
            if (!ReminderSchedule::isDue($remindTime, $this->settings->get('remind_last_sent'), $now)) {
                return Command::SUCCESS;
            }
            // zapisujemy przed wypisaniem: najwyżej jedno przypomnienie dziennie, nawet gdy raport jest kompletny
            $this->settings->save(['remind_last_sent' => ReminderSchedule::stamp($remindTime, $now)]);
        }

        $today = $now->setTime(0, 0);
        $off = $this->calendar->dayOff($today);
        if (null !== $off) {
            $output->writeln('Dziś wolne ('.$off['label'].') – raport nie jest potrzebny.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $day = $this->days->findByDate($today);
        $todo = [];
        if (null === $day || [] === $day->getActiveEntries()) {
            $todo[] = 'wpisz czas w tickety';
        } else {
            $gap = $this->reports->summary($day)->loggedVsPresence();
            if (null !== $gap && $gap < 0) {
                $todo[] = 'w ticketach brakuje '.TimeFormat::format(-$gap).' względem czasu w pracy';
            }
            if (!$this->sync->isFullySynced($day)) {
                $todo[] = 'wyślij worklogi do Jiry';
            }
            if (null === $day->getSlackDoneAt()) {
                $todo[] = 'wyślij podsumowanie na Slacka';
            }
            if (null !== $this->reports->presenceMail($day) && null === $day->getMailDoneAt()) {
                $todo[] = 'wyślij mail o '.('over' === $this->reports->summary($day)->kind() ? 'nadgodzinach' : 'krótszym dniu');
            }
        }

        if ([] === $todo) {
            $output->writeln('Raport z dzisiaj jest kompletny.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $output->writeln('Zostało: '.implode(', ', $todo).'.');

        return Command::SUCCESS;
    }
}
