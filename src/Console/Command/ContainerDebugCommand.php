<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use SymPress\Kernel\Container;
use Symfony\Bundle\FrameworkBundle\Console\Helper\DescriptorHelper;
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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[AsCommand(name: 'debug:container', description: 'Display current services for an application')]
class ContainerDebugCommand extends Command
{
    use DebugContainerBuilderTrait;

    public function __construct(
        protected readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'A service name')
            ->addOption('show-hidden', null, InputOption::VALUE_NONE, 'Show hidden (internal) services')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Show all services with a specific tag')
            ->addOption('tags', null, InputOption::VALUE_NONE, 'Display tagged services for an application')
            ->addOption('parameter', null, InputOption::VALUE_REQUIRED, 'Display a specific parameter for an application')
            ->addOption('parameters', null, InputOption::VALUE_NONE, 'Display parameters for an application')
            ->addOption('types', null, InputOption::VALUE_NONE, 'Display types (classes/interfaces) available in the container')
            ->addOption('env-var', null, InputOption::VALUE_REQUIRED, 'Display a specific environment variable used in the container')
            ->addOption('env-vars', null, InputOption::VALUE_NONE, 'Display environment variables used in the container')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format ("txt", "xml", "json", "md")', 'txt')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'To output raw description')
            ->addOption('deprecations', null, InputOption::VALUE_NONE, 'Display deprecations generated when compiling and warming up the container');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);

        $io = new SymfonyStyle($input, $output);
        $errorIo = $io->getErrorStyle();
        $object = $this->containerBuilder();

        if ($input->getOption('env-vars')) {
            $options = ['env-vars' => true];
        } elseif ($envVar = $input->getOption('env-var')) {
            $options = ['env-vars' => true, 'name' => $envVar];
        } elseif ($input->getOption('types')) {
            $options = ['filter' => $this->filterToServiceTypes(...)];
        } elseif ($input->getOption('parameters')) {
            $parameters = [];

            foreach ($object->getParameterBag()->all() as $key => $value) {
                $parameters[$key] = $object->resolveEnvPlaceholders($value);
            }

            $object = new ParameterBag($parameters);
            $options = [];
        } elseif ($parameter = $input->getOption('parameter')) {
            $options = ['parameter' => $parameter];
        } elseif ($input->getOption('tags')) {
            $options = ['group_by' => 'tags'];
        } elseif ($tag = $input->getOption('tag')) {
            $tag = $this->findProperTagName($input, $errorIo, $object, (string) $tag);
            $options = ['tag' => $tag];
        } elseif ($name = $input->getArgument('name')) {
            $name = $this->findProperServiceName($input, $errorIo, $object, (string) $name, (bool) $input->getOption('show-hidden'));
            $options = ['id' => $name];
        } elseif ($input->getOption('deprecations')) {
            $options = ['deprecations' => true];
        } else {
            $options = [];
        }

        $options['format'] = (string) $input->getOption('format');
        $options['show_hidden'] = (bool) $input->getOption('show-hidden');
        $options['raw_text'] = (bool) $input->getOption('raw');
        $options['output'] = $io;
        $options['is_debug'] = (bool) $this->container->getParameter('kernel.debug');

        try {
            (new DescriptorHelper())->describe($io, $object, $options);
        } catch (ParameterNotFoundException $exception) {
            if (isset($options['parameter']) && $options['parameter'] === $exception->getKey()) {
                throw new InvalidArgumentException(sprintf('You have requested a non-existent parameter "%s".', $options['parameter']));
            }

            throw $exception;
        } catch (ServiceNotFoundException $exception) {
            if ($exception->getId() !== '' && $exception->getId()[0] === '@') {
                throw new ServiceNotFoundException($exception->getId(), $exception->getSourceId(), null, [substr($exception->getId(), 1)]);
            }

            throw $exception;
        }

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues((new DescriptorHelper())->getFormats());

            return;
        }

        $builder = $this->containerBuilder();

        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues($this->findServiceIdsContaining(
                $builder,
                $input->getCompletionValue(),
                (bool) $input->getOption('show-hidden'),
            ));

            return;
        }

        if ($input->mustSuggestOptionValuesFor('tag')) {
            $suggestions->suggestValues($builder->findTags());

            return;
        }

        if ($input->mustSuggestOptionValuesFor('parameter')) {
            $suggestions->suggestValues(array_keys($builder->getParameterBag()->all()));
        }
    }

    protected function containerBuilder(): ContainerBuilder
    {
        return $this->debugContainerBuilder($this->container);
    }

    protected function validateInput(InputInterface $input): void
    {
        $optionsCount = 0;

        foreach (['tags', 'tag', 'parameters', 'parameter'] as $option) {
            if ($input->getOption($option)) {
                ++$optionsCount;
            }
        }

        if ($input->getArgument('name') !== null && $optionsCount > 0) {
            throw new InvalidArgumentException('The options tags, tag, parameters & parameter cannot be combined with the service name argument.');
        }

        if ($input->getArgument('name') === null && $optionsCount > 1) {
            throw new InvalidArgumentException('The options tags, tag, parameters & parameter cannot be combined together.');
        }
    }

    protected function filterToServiceTypes(string $serviceId): bool
    {
        if (!preg_match('/(?(DEFINE)(?<V>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+))^(?&V)(?:\\\\(?&V))*+(?: \$(?&V))?$/', $serviceId)) {
            return false;
        }

        if (str_contains($serviceId, '\\')) {
            return true;
        }

        return class_exists($serviceId) || interface_exists($serviceId, false);
    }

    /**
     * @return list<string>
     */
    private function findServiceIdsContaining(ContainerBuilder $container, string $name, bool $showHidden): array
    {
        $foundServiceIds = [];
        $foundServiceIdsIgnoringBackslashes = [];

        foreach ($container->getServiceIds() as $serviceId) {
            if (!$showHidden && str_starts_with($serviceId, '.')) {
                continue;
            }

            if (!$showHidden && $container->hasDefinition($serviceId) && $container->getDefinition($serviceId)->hasTag('container.excluded')) {
                continue;
            }

            if ($name === '' || stripos($serviceId, $name) !== false) {
                $foundServiceIds[] = $serviceId;
            }

            if ($name !== '' && stripos(str_replace('\\', '', $serviceId), $name) !== false) {
                $foundServiceIdsIgnoringBackslashes[] = $serviceId;
            }
        }

        return $foundServiceIds ?: $foundServiceIdsIgnoringBackslashes;
    }

    /**
     * @return list<string>
     */
    private function findTagsContaining(ContainerBuilder $container, string $tagName): array
    {
        $foundTags = [];

        foreach ($container->findTags() as $tag) {
            if (str_contains($tag, $tagName)) {
                $foundTags[] = $tag;
            }
        }

        return $foundTags;
    }

    private function findProperServiceName(
        InputInterface $input,
        SymfonyStyle $io,
        ContainerBuilder $container,
        string $name,
        bool $showHidden,
    ): string {
        $name = ltrim($name, '\\');

        if ($container->has($name) || !$input->isInteractive()) {
            return $name;
        }

        $matchingServices = $this->findServiceIdsContaining($container, $name, $showHidden);

        if ($matchingServices === []) {
            throw new InvalidArgumentException(sprintf('No services found that match "%s".', $name));
        }

        if (count($matchingServices) === 1) {
            return $matchingServices[0];
        }

        natsort($matchingServices);

        return $io->choice('Select one of the following services to display its information', array_values($matchingServices));
    }

    private function findProperTagName(
        InputInterface $input,
        SymfonyStyle $io,
        ContainerBuilder $container,
        string $tagName,
    ): string {
        if (in_array($tagName, $container->findTags(), true) || !$input->isInteractive()) {
            return $tagName;
        }

        $matchingTags = $this->findTagsContaining($container, $tagName);

        if ($matchingTags === []) {
            throw new InvalidArgumentException(sprintf('No tags found that match "%s".', $tagName));
        }

        if (count($matchingTags) === 1) {
            return $matchingTags[0];
        }

        natsort($matchingTags);

        return $io->choice('Select one of the following tags to display its information', array_values($matchingTags));
    }
}
