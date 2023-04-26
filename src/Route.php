<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use DiggPHP\Psr17\Factory;
use DiggPHP\Router\Router;
use ReflectionClass;

class Route
{
    private $found = false;
    private $allowed = false;
    private $handler = null;
    private $middlewares = [];
    private $params = [];
    private $query = [];
    private $app = null;
    private $path = '';

    public function __construct(
        Factory $factory,
        Router $router
    ) {
        $uri = $factory->createUriFromGlobals();
        $res = $router->dispatch(
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            $uri->getScheme() . '://' . $uri->getHost() . (in_array($uri->getPort(), [null, 80, 443]) ? '' : ':' . $uri->getPort()) . $uri->getPath()
        );
        $this->found = $res[0] ?? false;
        $this->allowed = $res[1] ?? false;
        $this->handler = $res[2] ?? null;
        $this->middlewares = $res[3] ?? [];
        $this->params = $res[4] ?? [];
        $this->query = $res[5] ?? [];

        $paths = explode('/', $uri->getPath());
        $pathx = explode('/', $_SERVER['SCRIPT_NAME']);
        foreach ($pathx as $key => $value) {
            if (isset($paths[$key]) && ($paths[$key] == $value)) {
                unset($paths[$key]);
            }
        }

        $this->path = '/' . implode('/', $paths);

        if (!$this->isFound()) {
            if (count($paths) <= 2) {
                return;
            }
            array_splice($paths, 0, 0, 'App');
            array_splice($paths, 3, 0, 'Http');
            $class = str_replace(['-'], [''], ucwords(implode('\\', $paths), '\\-'));
            $this->setFound(true);
            $this->setAllowed(true);
            $this->setHandler($class);
        }

        if ($this->isFound()) {
            $handler = $this->getHandler();
            $cls = null;
            if (is_array($handler) && $handler[1] == 'handle') {
                $cls = $handler[0];
            } elseif (is_string($handler)) {
                $cls = $handler;
            }

            if ($cls) {
                $name_paths = explode('\\', is_object($cls) ? (new ReflectionClass($cls))->getName() : $cls);
                if (isset($name_paths[4]) && $name_paths[0] == 'App' && $name_paths[3] == 'Http') {
                    $camel = function (string $str): string {
                        return strtolower(preg_replace('/([A-Z])/', "-$1", lcfirst($str)));
                    };
                    $this->app = $camel($name_paths[1]) . '/' . $camel($name_paths[2]);
                }
            }
        }

        if ($this->isFound()) {
            if ($this->app && !isset(Framework::getAppList()[$this->app])) {
                $this->setFound(false);
            }
        }
    }

    public function setFound(bool $found): self
    {
        $this->found = $found;
        return $this;
    }

    public function setApp(string $app): self
    {
        $this->app = $app;
        return $this;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function setAllowed(bool $allowed): self
    {
        $this->allowed = $allowed;
        return $this;
    }

    public function setHandler($handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function setMiddlewares(array $middlewares): self
    {
        $this->middlewares = $middlewares;
        return $this;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    public function setQuery(array $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function getMiddleWares(): array
    {
        return $this->middlewares;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function getPath()
    {
        return $this->path;
    }
}
