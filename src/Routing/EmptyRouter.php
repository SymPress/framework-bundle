<?php

declare(strict_types=1);

namespace SymPress\Framework\Routing;

use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class EmptyRouter implements RouterInterface
{
    private RequestContext $context;
    private RouteCollection $routes;

    public function __construct()
    {
        $this->context = new RequestContext();
        $this->routes = new RouteCollection();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    public function match(string $pathinfo): array
    {
        throw new ResourceNotFoundException(sprintf('No routes are configured for path "%s".', $pathinfo));
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        throw new RouteNotFoundException(sprintf('Unable to generate a URL for the named route "%s" because no routes are configured.', $name));
    }
}
