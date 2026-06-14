<?php

declare(strict_types=1);

namespace SymPress\Framework\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Component\Routing\Matcher\TraceableUrlMatcher;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(name: 'router:match', description: 'Help debug routes by simulating a path info match')]
final class RouterMatchCommand extends Command
{
    /**
     * @param iterable<mixed, ExpressionFunctionProviderInterface> $expressionLanguageProviders
     */
    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly iterable $expressionLanguageProviders = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path_info', InputArgument::REQUIRED, 'A path info')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Set the HTTP method')
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'Set the URI scheme')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Set the URI host');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->router instanceof RouterInterface) {
            throw new InvalidArgumentException('The "router" service is not available in this application.');
        }

        $io = new SymfonyStyle($input, $output);
        $context = $this->router->getContext();

        if (is_string($method = $input->getOption('method'))) {
            $context->setMethod($method);
        }

        if (is_string($scheme = $input->getOption('scheme'))) {
            $context->setScheme($scheme);
        }

        if (is_string($host = $input->getOption('host'))) {
            $context->setHost($host);
        }

        $matcher = new TraceableUrlMatcher($this->router->getRouteCollection(), $context);

        foreach ($this->expressionLanguageProviders as $provider) {
            $matcher->addExpressionLanguageProvider($provider);
        }

        $pathInfo = (string) $input->getArgument('path_info');
        $traces = $matcher->getTraces($pathInfo);
        $matches = false;
        $io->newLine();

        foreach ($traces as $trace) {
            if ($trace['level'] === TraceableUrlMatcher::ROUTE_ALMOST_MATCHES) {
                $io->text(sprintf('Route <info>"%s"</> almost matches but %s', $trace['name'], lcfirst($trace['log'])));

                continue;
            }

            if ($trace['level'] === TraceableUrlMatcher::ROUTE_MATCHES) {
                $io->success(sprintf('Route "%s" matches', $trace['name']));
                $this->getApplication()?->find('debug:router')->run(new ArrayInput(['name' => $trace['name']]), $output);
                $matches = true;

                continue;
            }

            if ($input->getOption('verbose')) {
                $io->text(sprintf('Route "%s" does not match: %s', $trace['name'], $trace['log']));
            }
        }

        if (!$matches) {
            $io->error(sprintf('None of the routes match the path "%s"', $pathInfo));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
