<?php

declare(strict_types=1);

namespace SymPress\Framework\Cache\Command;

use SymPress\Framework\Cache\CachePoolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:pool:list', description: 'List available cache pools')]
final class CachePoolListCommand extends Command
{
    public function __construct(
        private readonly CachePoolRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->registry->names() as $name) {
            $output->writeln($name);
        }

        return self::SUCCESS;
    }
}
