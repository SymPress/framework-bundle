<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use Symfony\Component\Config\Definition\Dumper\XmlReferenceDumper;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config:dump-reference', description: 'Dump the default configuration for an extension')]
final class ConfigDumpReferenceCommand extends AbstractConfigCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The bundle name or the extension alias')
            ->addArgument('path', InputArgument::OPTIONAL, 'The configuration option path')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format ("yaml", "xml")', 'yaml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (!is_string($name) || $name === '') {
            $this->listBundles($io->getErrorStyle());
            $this->listNonBundleExtensions($io->getErrorStyle());
            $io->getErrorStyle()->comment('Provide the name of a bundle or extension alias. (e.g. <comment>config:dump-reference framework</comment>)');

            return Command::SUCCESS;
        }

        $builder = $this->containerBuilder();
        $extension = $this->findExtension($name);
        $configuration = $this->configuration($extension, $builder);
        $format = (string) $input->getOption('format');
        $path = $input->getArgument('path');

        if (is_string($path) && $path !== '' && $format !== 'yaml') {
            $io->getErrorStyle()->error('The "path" argument is only available for the "yaml" format.');

            return Command::FAILURE;
        }

        $message = $name === $extension->getAlias()
            ? sprintf('Default configuration for extension with alias: "%s"', $name)
            : sprintf('Default configuration for "%s"', $name);

        if (is_string($path) && $path !== '') {
            $message .= sprintf(' at path "%s"', $path);
        }

        if ($docUrl = $this->docUrl($extension, $builder)) {
            $message .= sprintf(' (see %s)', $docUrl);
        }

        if ($format === 'yaml') {
            $io->writeln(sprintf('# %s', $message));
            $dumper = new YamlReferenceDumper();
            $io->writeln(is_string($path) && $path !== '' ? $dumper->dumpAtPath($configuration, $path) : $dumper->dump($configuration));

            return Command::SUCCESS;
        }

        if ($format === 'xml') {
            $io->writeln(sprintf('<!-- %s -->', $message));
            $io->writeln((new XmlReferenceDumper())->dump($configuration));

            return Command::SUCCESS;
        }

        throw new InvalidArgumentException('Supported formats are "yaml", "xml".');
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues($this->availableExtensions());
            $suggestions->suggestValues($this->availableBundles());

            return;
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues(['yaml', 'xml']);
        }
    }
}
