<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Container;
use Symfony\Bundle\FrameworkBundle\Console\Descriptor\Descriptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;

#[AsCommand(name: 'debug:autowiring', description: 'List classes/interfaces you can use for autowiring')]
final class DebugAutowiringCommand extends ContainerDebugCommand
{
    public function __construct(
        Container $container,
        private readonly ?FileLinkFormatter $fileLinkFormatter = null,
    ) {
        parent::__construct($container);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::OPTIONAL, 'A search filter')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show also services that are not aliased');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errorIo = $io->getErrorStyle();
        $container = $this->containerBuilder();
        $serviceIds = array_filter($container->getServiceIds(), $this->filterToServiceTypes(...));
        $search = $input->getArgument('search');

        if (is_string($search) && $search !== '') {
            $searchNormalized = preg_replace('/[^a-zA-Z0-9\x7f-\xff $]++/', '', $search) ?? $search;
            $serviceIds = array_filter(
                $serviceIds,
                static fn (string $serviceId): bool => stripos(str_replace('\\', '', $serviceId), $searchNormalized) !== false
                    && !str_starts_with($serviceId, '.'),
            );

            if ($serviceIds === []) {
                $errorIo->error(sprintf('No autowirable classes or interfaces found matching "%s"', $search));

                return Command::FAILURE;
            }
        }

        $reverseAliases = [];

        foreach ($container->getAliases() as $id => $alias) {
            if (($id[0] ?? null) === '.') {
                $reverseAliases[(string) $alias][] = $id;
            }
        }

        uasort($serviceIds, 'strnatcmp');

        $io->title('Autowirable Types');
        $io->text('Use the following classes & interfaces as type-hints in constructor arguments to autowire services.');
        $io->text('Add <fg=magenta>#[Target(\'</><fg=cyan>name</><fg=magenta>\')]</> to the argument to select a specific variant.');

        if (is_string($search) && $search !== '') {
            $io->text(sprintf('(only showing classes/interfaces matching <comment>%s</comment>)', $search));
        }

        $hasAlias = [];
        $all = (bool) $input->getOption('all');
        $previousId = '-';
        $hiddenConcreteServices = 0;

        foreach ($serviceIds as $serviceId) {
            if ($container->hasDefinition($serviceId) && $container->getDefinition($serviceId)->hasTag('container.excluded')) {
                continue;
            }

            $text = [];
            $resolvedServiceId = $serviceId;
            $description = '';
            $isNewGroup = !str_starts_with($serviceId, $previousId . ' $');

            if ($isNewGroup) {
                $text[] = '';
                $previousId = preg_replace('/ \$.*/', '', $serviceId) ?? $serviceId;
                $skipReflection = $container->hasAlias($previousId) && $container->getAlias($previousId)->isDeprecated();
                $description = $skipReflection ? '' : Descriptor::getClassDescription($previousId, $resolvedServiceId);

                if ($description !== '' && isset($hasAlias[$previousId])) {
                    continue;
                }
            }

            if ($container->hasAlias($serviceId)) {
                $hasAlias[$serviceId] = true;
                $serviceAlias = $container->getAlias($serviceId);
                $alias = (string) $serviceAlias;
                $target = null;

                foreach ($reverseAliases[$alias] ?? [] as $id) {
                    if (!str_starts_with($id, '.' . $previousId . ' $') || !str_contains($serviceId, ' $')) {
                        continue;
                    }

                    $target = substr($id, strlen($previousId) + 3);

                    if ($container->findDefinition($id) === $container->findDefinition($serviceId)) {
                        break;
                    }
                }

                if ($container->hasDefinition($alias) && $decorated = $container->getDefinition($alias)->getTag('container.decorator')) {
                    $alias = $decorated[0]['id'];
                }

                if ($isNewGroup) {
                    $typeLine = sprintf('<fg=yellow>%s</>', $previousId);

                    if (!$skipReflection && ($fileLink = $this->getFileLink($previousId)) !== '') {
                        $typeLine = sprintf('<fg=yellow;href=%s>%s</>', $fileLink, $previousId);
                    }

                    if ($target !== null) {
                        $text[] = $typeLine;

                        if ($description !== '') {
                            $text[] = sprintf('  %s', $description);
                        }

                        $targetLine = sprintf('  <fg=magenta>#[Target(\'</><fg=cyan>%s</><fg=magenta>\')]</>', $target);

                        if ($alias !== $target) {
                            $targetLine .= sprintf(' -> <fg=cyan>%s</>', $alias);
                        }

                        if ($serviceAlias->isDeprecated()) {
                            $targetLine .= ' <fg=magenta>[deprecated]</>';
                        }

                        $text[] = $targetLine;
                    } else {
                        $typeLine .= sprintf(' -> <fg=cyan>%s</>', $alias);

                        if ($serviceAlias->isDeprecated()) {
                            $typeLine .= ' <fg=magenta>[deprecated]</>';
                        }

                        $text[] = $typeLine;

                        if ($description !== '') {
                            $text[] = sprintf('  %s', $description);
                        }
                    }
                } else {
                    $variantLine = $target !== null
                        ? sprintf('  <fg=magenta>#[Target(\'</><fg=cyan>%s</><fg=magenta>\')]</>', $target)
                        : sprintf('  <fg=yellow>%s</>', $serviceId);

                    if ($alias !== $target) {
                        $variantLine .= sprintf(' -> <fg=cyan>%s</>', $alias);
                    }

                    if ($serviceAlias->isDeprecated()) {
                        $variantLine .= ' <fg=magenta>[deprecated]</>';
                    }

                    $text[] = $variantLine;
                }
            } elseif (!$all) {
                ++$hiddenConcreteServices;
                continue;
            } elseif ($container->hasDefinition($serviceId)) {
                $serviceLine = sprintf('<fg=yellow>%s</>', $previousId);

                if (($fileLink = $this->getFileLink($previousId)) !== '') {
                    $serviceLine = sprintf('<fg=yellow;href=%s>%s</>', $fileLink, $previousId);
                }

                if ($container->getDefinition($serviceId)->isDeprecated()) {
                    $serviceLine .= ' <fg=magenta>[deprecated]</>';
                }

                $text[] = $serviceLine;

                if ($isNewGroup && $description !== '') {
                    $text[] = sprintf('  %s', $description);
                }
            }

            $io->text($text);
        }

        $io->newLine();

        if ($hiddenConcreteServices > 0) {
            $io->text(sprintf(
                '%d more concrete service%s would be displayed when adding the "--all" option.',
                $hiddenConcreteServices,
                $hiddenConcreteServices > 1 ? 's' : '',
            ));
        }

        if ($all) {
            $io->text('Pro-tip: use interfaces in your type-hints instead of classes to benefit from the dependency inversion principle.');
        }

        $io->newLine();

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('search')) {
            $suggestions->suggestValues(array_filter($this->containerBuilder()->getServiceIds(), $this->filterToServiceTypes(...)));
        }
    }

    private function getFileLink(string $class): string
    {
        $reflection = $this->containerBuilder()->getReflectionClass($class, false);

        if (!$this->fileLinkFormatter instanceof FileLinkFormatter || !$reflection instanceof \ReflectionClass) {
            return '';
        }

        $fileName = $reflection->getFileName();

        if (!is_string($fileName)) {
            return '';
        }

        return $this->fileLinkFormatter->format($fileName, $reflection->getStartLine()) ?: '';
    }
}
