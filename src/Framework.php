<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use DiggPHP\Database\Db;
use DiggPHP\Psr11\Container;
use DiggPHP\Psr17\Factory;
use DiggPHP\Request\Request;
use DiggPHP\Router\Route;
use DiggPHP\Router\Router;
use DiggPHP\Session\Session;
use DiggPHP\Template\Template;
use DiggPHP\TinyApp\TinyApp;
use ReflectionClass;

class Framework
{

    /**
     * @var TinyApp $tinyapp
     */
    private static $tinyapp;

    public static function run()
    {
        if (!class_exists(InstalledVersions::class)) {
            die('composer 2 is required!');
        }

        $alias_file = self::getRoot() . '/config/alias.php';
        self::$tinyapp = new TinyApp(file_exists($alias_file) ? self::requireFile($alias_file) : []);

        self::initTemplate();

        self::call('onInit');
        self::call('onDispatch');

        self::execute(function (
            Route $route,
            Container $container
        ) {
            if ($route->isFound()) {
                return;
            }
            $path_arr = explode('/', self::getRequestPath());
            array_shift($path_arr);
            if (count($path_arr) <= 2) {
                return;
            }
            array_splice($path_arr, 0, 0, 'App');
            array_splice($path_arr, 3, 0, 'Http');
            $class = str_replace(['-'], [''], ucwords(implode('\\', $path_arr), '\\-'));
            if (!class_exists($class)) {
                return;
            }
            $handler = $container->get($class);
            if (is_callable($handler)) {
                $route->setFound(true);
                $route->setAllowed(true);
                $route->setHandler($class);
            }
        });

        self::execute(function (
            Container $container,
            Route $route
        ) {
            $container->set('request_app', function () use ($route): ?string {
                if (!$route->isFound()) {
                    return null;
                }
                $handler = $route->getHandler();

                if (is_array($handler) && $handler[1] == 'handle') {
                    $cls = $handler[0];
                } elseif (is_string($handler)) {
                    $cls = $handler;
                } else {
                    return null;
                }

                $name_paths = explode('\\', is_object($cls) ? (new ReflectionClass($cls))->getName() : $cls);
                if (isset($name_paths[4]) && $name_paths[0] == 'App' && $name_paths[3] == 'Http') {
                    $camel = function (string $str) {
                        return strtolower(preg_replace('/([A-Z])/', "-$1", lcfirst($str)));
                    };
                    return $camel($name_paths[1]) . '/' . $camel($name_paths[2]);
                }

                return null;
            });
        });

        self::execute(function (
            Route $route,
            $request_app
        ) {
            if ($request_app && !isset(self::getAppList()[$request_app])) {
                $route->setFound(false);
            }
        });

        self::call('onExecute');
        self::$tinyapp->run();
        self::call('onEnd');
    }

    public static function getAppList(): array
    {
        static $list;
        if (is_null($list)) {
            $list = [];
            foreach (array_unique(InstalledVersions::getInstalledPackages()) as $app) {
                $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\App', '/\\-'));
                if (
                    !class_exists($class_name)
                    || !is_subclass_of($class_name, AppInterface::class)
                ) {
                    continue;
                }
                if (file_exists(self::getRoot() . '/config/' . $app . '/disabled.lock')) {
                    continue;
                }
                $list[$app] = $app;
            }

            if (InstalledVersions::isInstalled('diggphp/plugin')) {
                $loader = new ClassLoader();
                foreach (glob(self::getRoot() . '/plugin/*/src/library/App.php') as $file) {
                    $app = substr($file, strlen(self::getRoot() . '/'), -strlen('/src/library/App.php'));

                    require_once $file;

                    $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\App', '/\\-'));
                    if (
                        !class_exists($class_name)
                        || !is_subclass_of($class_name, AppInterface::class)
                    ) {
                        continue;
                    }

                    if (file_exists(self::getRoot() . '/config/' . $app . '/disabled.lock')) {
                        continue;
                    }

                    if (!file_exists(self::getRoot() . '/config/' . $app . '/install.lock')) {
                        continue;
                    }

                    $list[$app] = $app;

                    $loader->addPsr4(
                        str_replace(['-', '/'], ['', '\\'], ucwords('App\\' . $app . '\\', '/\\-')),
                        self::getRoot() . '/' . $app . '/src/library/'
                    );
                }
                $loader->register();
            }
        }
        return $list;
    }

    public static function execute(callable $callable, array $default_args = [])
    {
        return TinyApp::execute($callable, $default_args);
    }

    public static function getRoot(): string
    {
        static $root;
        if (is_null($root)) {
            $root = InstalledVersions::getRootPackage()['install_path'];
        }
        return $root;
    }

    public static function bindMiddleware(...$middlewares): TinyApp
    {
        return self::$tinyapp->bindMiddleware(...$middlewares);
    }

    public static function get(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->get($route, $handler, $middlewares, $params, $name);
    }

    public static function post(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->post($route, $handler, $middlewares, $params, $name);
    }

    public static function put(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->put($route, $handler, $middlewares, $params, $name);
    }

    public static function delete(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->delete($route, $handler, $middlewares, $params, $name);
    }

    public static function patch(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->patch($route, $handler, $middlewares, $params, $name);
    }

    public static function head(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->head($route, $handler, $middlewares, $params, $name);
    }

    public static function any(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): TinyApp
    {
        return self::$tinyapp->any($route, $handler, $middlewares, $params, $name);
    }

    public static function addGroup(string $prefix, callable $callback, array $middlewares = [], array $params = []): TinyApp
    {
        return self::$tinyapp->addGroup($prefix, $callback, $middlewares, $params);
    }

    private static function getRequestPath(): string
    {
        $uri = (new Factory)->createUriFromGlobals();
        $paths = explode('/', $uri->getPath());
        $pathx = explode('/', $_SERVER['SCRIPT_NAME']);
        foreach ($pathx as $key => $value) {
            if (isset($paths[$key]) && ($paths[$key] == $value)) {
                unset($paths[$key]);
            }
        }
        return '/' . implode('/', $paths);
    }

    private static function call(string $action, array $args = [])
    {
        foreach (self::getAppList() as $app) {
            $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $app . '\\App', '/\\-'));
            if (method_exists($class_name, $action)) {
                self::execute([$class_name, $action], $args);
            }
        }
    }

    private static function requireFile(string $file)
    {
        static $loader;
        if (is_null($loader)) {
            $loader = new class()
            {
                public function load(string $file)
                {
                    return require $file;
                }
            };
        }
        return $loader->load($file);
    }

    private static function initTemplate()
    {
        self::execute(function (
            Container $container
        ) {
            $container->onInstance(Template::class, function (Template $template) use ($container) {
                $call = function (string $cls) use ($container) {
                    return new class($cls, $container)
                    {
                        private $cls;
                        private $container;

                        public function __construct(string $cls, Container $container)
                        {
                            $this->cls = $cls;
                            $this->container = $container;
                        }

                        public function __get($name)
                        {
                            return $this->getObj()->$name;
                        }

                        public function __set($name, $value)
                        {
                            return $this->getObj()->$name = $value;
                        }

                        public function __isset($name)
                        {
                            return isset($this->getObj()[$name]);
                        }

                        public function __call($name, $arguments)
                        {
                            return $this->getObj()->$name(...$arguments);
                        }

                        public function __invoke()
                        {
                            return $this->getObj();
                        }

                        private function getObj()
                        {
                            return $this->container->get($this->cls);
                        }
                    };
                };

                $template->assign([
                    'container' => $container,
                    'db' => $call(Db::class),
                    'framework' => $call(self::class),
                    'request' => $call(Request::class),
                    'config' => $call(Config::class),
                    'router' => $call(Router::class),
                    'cache' => $call(CacheInterface::class),
                    'logger' => $call(LoggerInterface::class),
                    'session' => $call(Session::class),
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
                                $res = $container->get(\Xhees\Template\Template::class)->renderFromString(base64_decode(\'' . base64_encode($matchs[2]) . '\'), $args, \'__' . $params[1] . '\');
                                $cache->set(\'' . $params[1] . '\', $res, ' . $params[0] . ');
                            }else{
                                $res = $cache->get(\'' . $params[1] . '\');
                            }
                            return $res;
                        }, get_defined_vars());?>';
                });

                foreach (self::getAppList() as $app) {
                    if (InstalledVersions::isInstalled($app)) {
                        $template->addPath($app, InstalledVersions::getInstallPath($app) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template');
                    } else {
                        $template->addPath($app, self::getRoot() . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template');
                    }
                }
            });
        });
    }
}
