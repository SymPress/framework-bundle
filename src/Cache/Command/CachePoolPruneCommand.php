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
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:pool:prune', description: 'Prune cache pools')]
final class CachePoolPruneCommand extends Command
{
    public function __construct(
        private readonly CachePoolRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pools', InputArgument::IS_ARRAY, 'Pool names to prune. Prunes all pruneable pools when omitted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pools = array_values(array_filter(array_map('strval', (array) $input->getArgument('pools'))));
        $missing = $this->registry->missing($pools);

        if ($missing !== []) {
            $output->writeln(sprintf('<error>Unknown cache pool(s): %s.</error>', implode(', ', $missing)));

            return self::FAILURE;
        }

        if (!$this->registry->prune($pools)) {
            $output->writeln('<error>One or more cache pools could not be pruned.</error>');

            return self::FAILURE;
        }

        $output->writeln('Cache pools pruned.');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('pools')) {
            $suggestions->suggestValues($this->registry->names());
        }
    }
}
