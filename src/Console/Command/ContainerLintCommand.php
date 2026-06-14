<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Compiler\CheckAliasValidityPass;
use Symfony\Component\DependencyInjection\Compiler\CheckTypeDeclarationsPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

#[AsCommand(name: 'lint:container', description: 'Ensure that arguments injected into services match type declarations')]
final class ContainerLintCommand extends Command
{
    use DebugContainerBuilderTrait;

    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command parses service definitions and ensures that injected values match service class type declarations.')
            ->addOption('resolve-env-vars', null, InputOption::VALUE_NONE, 'Resolve environment variables and fail if one is missing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $builder = $this->debugContainerBuilder($this->container);

        if ($builder->isCompiled()) {
            $io->success('The container was linted successfully: all services are injected with values that are compatible with their type declarations.');

            return Command::SUCCESS;
        }

        $this->prepareLintBuilder($builder);

        try {
            $builder->compile((bool) $input->getOption('resolve-env-vars'));
        } catch (InvalidArgumentException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('The container was linted successfully: all services are injected with values that are compatible with their type declarations.');

        return Command::SUCCESS;
    }

    private function prepareLintBuilder(ContainerBuilder $builder): void
    {
        $builder->setParameter('container.build_hash', 'lint_container');
        $builder->setParameter('container.build_id', 'lint_container');
        $builder->setParameter('container.runtime_mode', 'web=0');
        $builder->setParameter('container.build_time', time());
        $builder->addCompilerPass(new CheckAliasValidityPass(), PassConfig::TYPE_BEFORE_REMOVING, -100);
        $builder->addCompilerPass(new CheckTypeDeclarationsPass(true), PassConfig::TYPE_AFTER_REMOVING, -100);
    }
}
