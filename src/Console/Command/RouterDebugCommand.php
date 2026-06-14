<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

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
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(name: 'debug:router', description: 'Display current routes for an application')]
final class RouterDebugCommand extends Command
{
    private const SORT_COLUMNS = ['name', 'path', 'method', 'scheme', 'host'];

    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly ?FileLinkFormatter $fileLinkFormatter = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'A route name')
            ->addOption('show-controllers', null, InputOption::VALUE_NONE, 'Show assigned controllers in overview')
            ->addOption('show-aliases', null, InputOption::VALUE_NONE, 'Show aliases in overview')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format ("txt", "xml", "json", "md")', 'txt')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'To output raw route(s)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort routes by name, path, method, scheme, or host')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Filter by HTTP method', '', ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->router instanceof RouterInterface) {
            throw new InvalidArgumentException('The "router" service is not available in this application.');
        }

        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $method = strtoupper((string) $input->getOption('method'));
        $helper = new DescriptorHelper($this->fileLinkFormatter);
        $routes = $this->router->getRouteCollection();

        if (is_string($name) && $name !== '') {
            $route = $routes->get($name);
            $matchingRoutes = $this->findRouteNameContaining($name, $routes, $method);

            if (!$input->isInteractive() && !$route && count($matchingRoutes) > 1) {
                $helper->describe($io, $this->findRouteContaining($name, $routes), $this->options($input, $io, $method));

                return Command::SUCCESS;
            }

            if (!$route && $matchingRoutes !== []) {
                $default = count($matchingRoutes) === 1 ? $matchingRoutes[0] : null;
                $name = $io->choice('Select one of the matching routes', $matchingRoutes, $default);
                $route = $routes->get($name);
            }

            if (!$route) {
                throw new InvalidArgumentException(sprintf('The route "%s" does not exist.', $name));
            }

            $helper->describe($io, $route, [
                'format' => (string) $input->getOption('format'),
                'raw_text' => (bool) $input->getOption('raw'),
                'name' => $name,
                'output' => $io,
            ]);

            return Command::SUCCESS;
        }

        $helper->describe($io, $routes, $this->options($input, $io, $method));

        return Command::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name') && $this->router instanceof RouterInterface) {
            $suggestions->suggestValues(array_keys($this->router->getRouteCollection()->all()));

            return;
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues((new DescriptorHelper())->getFormats());

            return;
        }

        if ($input->mustSuggestOptionValuesFor('sort')) {
            $suggestions->suggestValues(self::SORT_COLUMNS);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(InputInterface $input, SymfonyStyle $io, string $method): array
    {
        return [
            'format' => (string) $input->getOption('format'),
            'raw_text' => (bool) $input->getOption('raw'),
            'show_controllers' => (bool) $input->getOption('show-controllers'),
            'show_aliases' => (bool) $input->getOption('show-aliases'),
            'output' => $io,
            'method' => $method,
            'sort' => $input->getOption('sort'),
        ];
    }

    /**
     * @return list<string>
     */
    private function findRouteNameContaining(string $name, RouteCollection $routes, string $method): array
    {
        $foundRoutesNames = [];

        foreach ($routes as $routeName => $route) {
            if (stripos($routeName, $name) !== false && ($method === '' || !$route->getMethods() || in_array($method, $route->getMethods(), true))) {
                $foundRoutesNames[] = $routeName;
            }
        }

        return $foundRoutesNames;
    }

    private function findRouteContaining(string $name, RouteCollection $routes): RouteCollection
    {
        $foundRoutes = new RouteCollection();

        foreach ($routes as $routeName => $route) {
            if (stripos($routeName, $name) !== false) {
                $foundRoutes->add($routeName, $route);
            }
        }

        return $foundRoutes;
    }
}
