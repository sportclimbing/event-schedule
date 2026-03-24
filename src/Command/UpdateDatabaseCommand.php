<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Command;

use Psr\Container\ContainerInterface;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateDatabaseCommand extends Command
{
    public const string NAME = 'sportclimbing:generate-schedule';

    public function __construct(
        private readonly ContainerInterface $container,
    )
    {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->setDescription('Generate Schedule');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generate Schedule');

        try {
            /** @var RecentEventsScheduleSyncService $syncService */
            $syncService = $this->container->get(RecentEventsScheduleSyncService::class);
            $events = $syncService->sync(forceRescan: true);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(json_encode($events, JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
