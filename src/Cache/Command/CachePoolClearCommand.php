<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache\Command;

use SymPress\Framework\Cache\CachePoolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:pool:clear', description: 'Clear cache pools')]
final class CachePoolClearCommand extends Command
{
    public function __construct(
        private readonly CachePoolRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pools', InputArgument::IS_ARRAY, 'Pool names to clear. Clears all pools when omitted.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clear all cache pools.')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pool names to exclude.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pools = $this->selectedPools($input);
        $missing = $this->registry->missing($pools);

        if ($missing !== []) {
            $output->writeln(sprintf('<error>Unknown cache pool(s): %s.</error>', implode(', ', $missing)));

            return self::FAILURE;
        }

        if (!$this->registry->clear($pools)) {
            $output->writeln('<error>One or more cache pools could not be cleared.</error>');

            return self::FAILURE;
        }

        $output->writeln('Cache pools cleared.');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('pools') || $input->mustSuggestOptionValuesFor('exclude')) {
            $suggestions->suggestValues($this->registry->names());
        }
    }

    /**
     * @return array<int, string>
     */
    private function poolNames(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', (array) $value)));
    }

    /**
     * @return array<int, string>
     */
    private function selectedPools(InputInterface $input): array
    {
        $pools = $this->poolNames($input->getArgument('pools'));
        $excluded = array_fill_keys($this->poolNames($input->getOption('exclude')), true);

        if ($input->getOption('all') || $pools === []) {
            $pools = $this->registry->names();
        }

        return array_values(array_filter(
            $pools,
            static fn (string $pool): bool => !isset($excluded[$pool]),
        ));
    }
}
