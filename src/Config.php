<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use Composer\InstalledVersions;
use InvalidArgumentException;
use ReflectionClass;

class Config
{
    private $configs = [];

    public function __construct()
    {
        foreach (Framework::getAppList() as $app) {
            $this->configs[$app] = [];
        }
    }

    public function get(string $key = '', $default = null)
    {
        list($path, $package_name) = explode('@', $key);
        $package_name = str_replace('.', '/', $package_name);
        if (!$path) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }

        $paths = array_filter(
            explode('.', $path),
            function ($val) {
                return strlen($val) > 0 ? true : false;
            }
        );
        static $loaded = [];
        if (!isset($loaded[$paths[0] . '@' . $package_name])) {
            $loaded[$paths[0] . '@' . $package_name] = true;
            $this->load($package_name, $paths[0]);
        }

        return $this->getValue($this->configs[$package_name], $paths, $default);
    }

    public function set(string $key, $value = null): self
    {
        list($path, $package_name) = explode('@', $key);
        $package_name = str_replace('.', '/', $package_name);
        if (!$path) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }

        $paths = array_filter(
            explode('.', $path),
            function ($val) {
                return strlen($val) > 0 ? true : false;
            }
        );
        $this->setValue($this->configs[$package_name], $paths, $value);
        return $this;
    }

    private function load(string $package_name, string $key)
    {
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }

        $args = [];

        $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $package_name . '\\App', '/\\-'));
        $reflector = new ReflectionClass($class_name);
        $config_file = dirname(dirname($reflector->getFileName())) . '/config/' . $key . '.php';
        if (is_file($config_file)) {
            $tmp = $this->requireFile($config_file);
            if (!is_null($tmp)) {
                $args[] = $tmp;
            }
        }

        $install_path = InstalledVersions::getRootPackage()['install_path'];
        $config_file = $install_path . '/config/' . $package_name . '/' . $key . '.php';
        if (is_file($config_file)) {
            $tmp = $this->requireFile($config_file);
            if (!is_null($tmp)) {
                $args[] = $tmp;
            }
        }

        if (isset($this->configs[$package_name][$key])) {
            $args[] = $this->configs[$package_name][$key];
        }

        $this->configs[$package_name][$key] = $args ? array_merge(...$args) : null;
    }

    private function getValue($data, $path, $default)
    {
        $key = array_shift($path);
        if (!$path) {
            return isset($data[$key]) ? $data[$key] : $default;
        } else {
            if (isset($data[$key])) {
                return $this->getValue($data[$key], $path, $default);
            } else {
                return $default;
            }
        }
    }

    private function setValue(&$data, $path, $value)
    {
        $key = array_shift($path);
        if ($path) {
            if (!isset($data[$key])) {
                $data[$key] = null;
            }
            $this->setValue($data[$key], $path, $value);
        } else {
            $data[$key] = $value;
        }
    }

    private function requireFile(string $file)
    {
        static $loader;
        if (!$loader) {
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
}
