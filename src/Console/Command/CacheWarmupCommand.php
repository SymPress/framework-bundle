<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;

#[AsCommand(name: 'cache:warmup', description: 'Warm up an empty cache')]
final class CacheWarmupCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?CacheWarmerAggregate $cacheWarmer = null,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-optional-warmers', '', InputOption::VALUE_NONE, 'Skip optional cache warmers (faster)')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command warms up the cache.

                Before running this command, the cache should be empty.
                EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cacheDir = $this->kernel->getCacheDir();
        $buildDir = $this->kernel->getBuildDir();

        $io->comment(sprintf(
            'Warming up the cache for the <info>%s</info> environment with debug <info>%s</info>',
            $this->kernel->getEnvironment(),
            var_export($this->kernel->isDebug(), true),
        ));

        $this->filesystem->mkdir(array_values(array_unique([$cacheDir, $buildDir])));

        if ($this->cacheWarmer instanceof CacheWarmerAggregate) {
            if (!$input->getOption('no-optional-warmers')) {
                $this->cacheWarmer->enableOptionalWarmers();
            }

            $this->cacheWarmer->warmUp($cacheDir, $buildDir, $io);
        }

        $io->success(sprintf(
            'Cache for the "%s" environment (debug=%s) was successfully warmed.',
            $this->kernel->getEnvironment(),
            var_export($this->kernel->isDebug(), true),
        ));

        return Command::SUCCESS;
    }
}
