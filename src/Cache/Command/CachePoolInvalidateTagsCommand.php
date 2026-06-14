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

#[AsCommand(name: 'cache:pool:invalidate-tags', description: 'Invalidate cache tags for all or a specific pool')]
final class CachePoolInvalidateTagsCommand extends Command
{
    public function __construct(
        private readonly CachePoolRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tags', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Tags to invalidate')
            ->addOption('pool', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict invalidation to one or more pools');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tags = array_values(array_filter(array_map('strval', (array) $input->getArgument('tags'))));
        $poolOption = array_values(array_filter(array_map('strval', (array) $input->getOption('pool'))));
        $poolNames = $poolOption === [] ? $this->registry->taggableNames() : $poolOption;
        $invalidated = 0;
        $errors = false;

        foreach ($poolNames as $poolName) {
            $pool = $this->registry->getTaggable($poolName);

            if ($pool === null) {
                $output->writeln(sprintf('<error>Cache pool "%s" is not tag-aware or does not exist.</error>', $poolName));
                $errors = true;

                continue;
            }

            if ($pool->invalidateTags($tags)) {
                $invalidated++;

                continue;
            }

            $output->writeln(sprintf('<error>Cache tags could not be invalidated for pool "%s".</error>', $poolName));
            $errors = true;
        }

        if ($errors) {
            $output->writeln('<error>Cache tag invalidation completed with errors.</error>');

            return self::FAILURE;
        }

        if ($invalidated === 0) {
            $output->writeln('<error>No tag-aware cache pool invalidated the requested tags.</error>');

            return self::FAILURE;
        }

        $output->writeln('Cache tags invalidated.');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('pool')) {
            $suggestions->suggestValues($this->registry->taggableNames());
        }
    }
}
