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

#[AsCommand(name: 'cache:pool:delete', description: 'Delete an item from a cache pool')]
final class CachePoolDeleteCommand extends Command
{
    public function __construct(
        private readonly CachePoolRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pool', InputArgument::REQUIRED, 'Pool name')
            ->addArgument('key', InputArgument::REQUIRED, 'Cache item key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $poolName = (string) $input->getArgument('pool');
        $pool = $this->registry->get($poolName);

        if ($pool === null) {
            $output->writeln(sprintf('<error>Unknown cache pool "%s".</error>', $poolName));

            return self::FAILURE;
        }

        if (!$pool->deleteItem((string) $input->getArgument('key'))) {
            $output->writeln('<error>Cache item could not be deleted.</error>');

            return self::FAILURE;
        }

        $output->writeln('Cache item deleted.');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('pool')) {
            $suggestions->suggestValues($this->registry->names());
        }
    }
}
