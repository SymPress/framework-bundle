<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'debug:config', description: 'Dump the current configuration for an extension')]
final class ConfigDebugCommand extends AbstractConfigCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The bundle name or the extension alias')
            ->addArgument('path', InputArgument::OPTIONAL, 'The configuration option path')
            ->addOption('resolve-env', null, InputOption::VALUE_NONE, 'Display resolved environment variable values instead of placeholders')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format ("txt", "yaml", "json")', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errorIo = $io->getErrorStyle();
        $name = $input->getArgument('name');

        if (!is_string($name) || $name === '') {
            $this->listBundles($errorIo);
            $this->listNonBundleExtensions($errorIo);
            $errorIo->comment('Provide the name of a bundle or extension alias. (e.g. <comment>debug:config framework</comment>)');

            return Command::SUCCESS;
        }

        $builder = $this->containerBuilder();
        $extension = $this->findExtension($name);
        $config = $builder->resolveEnvPlaceholders(
            $builder->getParameterBag()->resolveValue($this->processedConfig($extension, $builder)),
            $input->getOption('resolve-env') ? true : null,
        );

        $path = $input->getArgument('path');

        if (is_string($path) && $path !== '') {
            try {
                $config = $this->configForPath($config, $path, $extension->getAlias());
            } catch (LogicException $exception) {
                $errorIo->error($exception->getMessage());

                return Command::FAILURE;
            }

            $io->title(sprintf('Current configuration for "%s.%s"', $extension->getAlias(), $path));
        } elseif ($input->getOption('format') === 'txt') {
            $io->title(sprintf('Current configuration for "%s"', $extension->getAlias()));

            if ($docUrl = $this->docUrl($extension, $builder)) {
                $io->comment(sprintf('Documentation at %s', $docUrl));
            }
        }

        $io->writeln($this->convertToFormat([$extension->getAlias() => $config], (string) $input->getOption('format')));

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues($this->availableExtensions());
            $suggestions->suggestValues($this->availableBundles());

            return;
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(['txt', 'yaml', 'json']);
        }
    }

    private function convertToFormat(mixed $config, string $format): string
    {
        return match ($format) {
            'txt', 'yaml' => Yaml::dump($config, 10),
            'json' => json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            default => throw new InvalidArgumentException('Supported formats are "txt", "yaml", "json".'),
        };
    }
}
