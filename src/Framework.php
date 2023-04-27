<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use DiggPHP\Database\Db;
use DiggPHP\Framework\Route;
use DiggPHP\Psr11\Container;
use DiggPHP\Psr14\Event;
use DiggPHP\Psr15\RequestHandler;
use DiggPHP\Psr16\LocalAdapter;
use DiggPHP\Psr17\Factory;
use DiggPHP\Psr3\LocalLogger;
use DiggPHP\Request\Request;
use DiggPHP\Responser\Emitter;
use DiggPHP\Router\Router;
use DiggPHP\Template\Template;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

class Framework
{
    public static function run()
    {
        if (!class_exists(InstalledVersions::class)) {
            die('composer 2 is required!');
        }

        self::execute(function () {
            $loader = new ClassLoader();
            $project_dir = dirname(dirname(dirname((new ReflectionClass(InstalledVersions::class))->getFileName())));
            foreach (self::getAppList() as $app) {
                if ($app['plugin']) {
                    $loader->addPsr4(
                        str_replace(['-', '/'], ['', '\\'], ucwords('App\\' . $app['name'] . '\\', '/\\-')),
                        $project_dir . '/' . $app['name'] . '/src/library/'
                    );
                }
            }
            $loader->register();
        });

        self::hook('onInit');

        self::execute(function (
            RequestHandler $requestHandler,
            ServerRequestInterface $serverRequest,
            Route $route,
            Emitter $emitter
        ) {
            self::hook('onStart');

            foreach ($route->getMiddleWares() as $middleware) {
                $requestHandler->appendMiddleware(is_string($middleware) ? self::getContainer()->get($middleware) : $middleware);
            }

            $handler = self::renderHandler($route);
            $response = $requestHandler->setHandler($handler)->handle($serverRequest);

            $emitter->emit($response);

            self::hook('onEnd');
        });
    }

    public static function execute(callable $callable, array $default_args = [])
    {
        if (is_array($callable)) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod(...$callable), $default_args);
        } elseif (is_object($callable)) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod($callable, '__invoke'), $default_args);
        } elseif (is_string($callable) && strpos($callable, '::')) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod($callable), $default_args);
        } else {
            $args = self::getContainer()->reflectArguments(new ReflectionFunction($callable), $default_args);
        }
        return call_user_func($callable, ...$args);
    }

    public static function getContainer(): Container
    {
        static $container;
        if ($container == null) {
            $container = new Container;
            $config = new Config;
            foreach (array_merge([
                ContainerInterface::class => $container,
                LoggerInterface::class => LocalLogger::class,
                CacheInterface::class => LocalAdapter::class,
                RequestHandlerInterface::class => RequestHandler::class,
                ResponseFactoryInterface::class => Factory::class,
                UriFactoryInterface::class => Factory::class,
                ServerRequestFactoryInterface::class => Factory::class,
                RequestFactoryInterface::class => Factory::class,
                StreamFactoryInterface::class => Factory::class,
                UploadedFileFactoryInterface::class => Factory::class,
                EventDispatcherInterface::class => Event::class,
                ListenerProviderInterface::class => Event::class,
            ], $config->get('alias', []), [
                Container::class => $container,
                Config::class => $config,
            ]) as $key => $obj) {
                $container->set($key, function () use ($obj, $container) {
                    return is_string($obj) ? $container->get($obj) : $obj;
                });
            }

            $container->set(ServerRequestInterface::class, function (
                Factory $factory,
                Route $route
            ): ServerRequestInterface {
                $server_request = $factory->createServerRequestFromGlobals();
                return $server_request
                    ->withQueryParams(array_merge($server_request->getQueryParams(), $route->getParams()));
            });

            $container->set(Template::class, function (
                Db $db,
                Request $request,
                Config $config,
                Router $router,
                Container $container,
                LoggerInterface $logger,
                CacheInterface $cache
            ): Template {
                $template = new Template($cache);
                $template->assign([
                    'db' => $db,
                    'cache' => $cache,
                    'logger' => $logger,
                    'router' => $router,
                    'config' => $config,
                    'request' => $request,
                    'template' => $template,
                    'container' => $container,
                ]);

                $template->extend('/\{cache\s*(.*)\s*\}([\s\S]*)\{\/cache\}/Ui', function ($matchs) {
                    $params = array_filter(explode(',', trim($matchs[1])));
                    if (!isset($params[0])) {
                        $params[0] = 3600;
                    }
                    if (!isset($params[1])) {
                        $params[1] = 'tpl_extend_cache_' . md5($matchs[2]);
                    }
                    return '<?php echo call_user_func(function($args){
                            extract($args);
                            if (!$cache->has(\'' . $params[1] . '\')) {
                                $res = $template->renderFromString(base64_decode(\'' . base64_encode($matchs[2]) . '\'), $args, \'__' . $params[1] . '\');
                                $cache->set(\'' . $params[1] . '\', $res, ' . $params[0] . ');
                            }else{
                                $res = $cache->get(\'' . $params[1] . '\');
                            }
                            return $res;
                        }, get_defined_vars());?>';
                });

                foreach (self::getAppList() as $app) {
                    $template->addPath($app['name'], $app['dir'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template');
                }

                return $template;
            });
        }
        return $container;
    }

    public static function getAppList(): array
    {
        return self::execute(function (
            CacheInterface $cache
        ): array {
            if (null == $list = $cache->get('applist!system')) {
                $list = [];
                foreach (array_unique(InstalledVersions::getInstalledPackages()) as $app) {
                    $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\Hook', '/\\-'));
                    if (
                        !class_exists($class_name)
                        || !is_subclass_of($class_name, HookInterface::class)
                    ) {
                        continue;
                    }
                    $list[$app] = [
                        'name' => $app,
                        'plugin' => false,
                        'dir' => dirname(dirname(dirname((new ReflectionClass($class_name))->getFileName()))),
                    ];
                }

                $project_dir = dirname(dirname(dirname((new ReflectionClass(InstalledVersions::class))->getFileName())));
                foreach (glob($project_dir . '/plugin/*/src/library/Hook.php') as $file) {
                    $app = substr($file, strlen($project_dir . '/'), -strlen('/src/library/Hook.php'));

                    $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\Hook', '/\\-'));
                    if (
                        !class_exists($class_name)
                        || !is_subclass_of($class_name, HookInterface::class)
                    ) {
                        continue;
                    }

                    if (file_exists($project_dir . '/config/' . $app . '/disabled.lock')) {
                        continue;
                    }

                    if (!file_exists($project_dir . '/config/' . $app . '/install.lock')) {
                        continue;
                    }

                    $list[$app] = [
                        'name' => $app,
                        'plugin' => true,
                        'dir' => $project_dir . '/' . $app,
                    ];
                }
                $cache->set('applist!system', $list, 86400);
            }
            return $list;
        });
    }

    public static function hook(string $action, array $args = [])
    {
        foreach (array_keys(self::getAppList()) as $app) {
            $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\Hook', '/\\-'));
            if (method_exists($class_name, $action)) {
                self::execute([$class_name, $action], $args);
            }
        }
    }

    private static function renderHandler(Route $route): callable
    {
        if (!$route->isFound()) {
            return function (): ResponseInterface {
                return self::execute(function (
                    Factory $factory
                ) {
                    return $factory->createResponse(404);
                });
            };
        }

        if ($route->getApp() && !isset(self::getAppList()[$route->getApp()])) {
            return function (): ResponseInterface {
                return self::execute(function (
                    Factory $factory
                ) {
                    return $factory->createResponse(404);
                });
            };
        }

        if (!$route->isAllowed()) {
            return function (): ResponseInterface {
                return self::execute(function (
                    Factory $factory
                ) {
                    return $factory->createResponse(405);
                });
            };
        }

        $handler = $route->getHandler();

        if (is_array($handler)) {
            if (false === (new ReflectionMethod(...$handler))->isStatic()) {
                if (!is_object($handler[0])) {
                    $handler[0] = self::getContainer()->get($handler[0]);
                }
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            $handler = self::getContainer()->get($handler);
        }

        if (!is_callable($handler)) {
            return function (): ResponseInterface {
                return self::execute(function (
                    Factory $factory
                ) {
                    return $factory->createResponse(404);
                });
            };
        }

        return function () use ($handler): ResponseInterface {
            return self::execute(function (
                Factory $factory
            ) use ($handler): ResponseInterface {

                $resp = self::execute($handler);

                if (is_null($resp)) {
                    return $factory->createResponse(200);
                }

                if ($resp instanceof ResponseInterface) {
                    return $resp;
                }

                if (is_scalar($resp) || (is_object($resp) && method_exists($resp, '__toString'))) {
                    $response = $factory->createResponse(200);
                    $response->getBody()->write('' . $resp);
                    return $response;
                } else {
                    throw new Exception('Unrecognized Response');
                }
            });
        };
    }
}
