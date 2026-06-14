<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheClearer\CacheClearerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;

#[AsCommand(name: 'cache:clear', description: 'Clear the cache')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?CacheClearerInterface $cacheClearer = null,
        private readonly ?CacheWarmerAggregate $cacheWarmer = null,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-warmup', '', InputOption::VALUE_NONE, 'Do not warm up the cache')
            ->addOption('no-optional-warmers', '', InputOption::VALUE_NONE, 'Skip optional cache warmers (faster)')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command clears and optionally warms up the SymPress kernel cache
                for the current environment and debug mode.
                EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cacheDir = $this->kernel->getCacheDir();
        $buildDir = $this->kernel->getBuildDir();
        $directories = array_values(array_unique([$cacheDir, $buildDir]));

        foreach ($directories as $directory) {
            $this->assertSafeCacheDirectory($directory);
        }

        $io->comment(sprintf(
            'Clearing the cache for the <info>%s</info> environment with debug <info>%s</info>',
            $this->kernel->getEnvironment(),
            var_export($this->kernel->isDebug(), true),
        ));

        foreach ($directories as $directory) {
            $this->filesystem->mkdir($directory);
            $this->cacheClearer?->clear($directory);
        }

        $this->filesystem->remove($directories);
        $this->filesystem->mkdir($directories);

        if (!$input->getOption('no-warmup') && $this->cacheWarmer instanceof CacheWarmerAggregate) {
            if (!$input->getOption('no-optional-warmers')) {
                $this->cacheWarmer->enableOptionalWarmers();
            }

            $this->cacheWarmer->warmUp($cacheDir, $buildDir, $io);
        }

        $io->success(sprintf(
            'Cache for the "%s" environment (debug=%s) was successfully cleared.',
            $this->kernel->getEnvironment(),
            var_export($this->kernel->isDebug(), true),
        ));

        return Command::SUCCESS;
    }

    private function assertSafeCacheDirectory(string $directory): void
    {
        $directory = rtrim($directory, '/');
        $projectDir = rtrim($this->kernel->getProjectDir(), '/');

        if ($directory === '' || $directory === '/' || $directory === $projectDir || $directory === dirname($projectDir)) {
            throw new RuntimeException(sprintf('Refusing to clear unsafe cache directory "%s".', $directory));
        }
    }
}
