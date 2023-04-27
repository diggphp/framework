<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use Composer\InstalledVersions;
use Exception;
use InvalidArgumentException;
use ReflectionClass;

class Config
{
    private $configs = [];

    public function get(string $key = '', $default = null)
    {
        $parse = $this->parseKey($key);

        if (!isset($this->configs[$parse['key']])) {
            $this->load($parse);
        }

        if (is_null($this->configs[$parse['key']])) {
            return $default;
        } else {
            return $this->getValue($this->configs[$parse['key']], $parse['paths'], $default);
        }
    }

    public function set(string $key, $value = null): self
    {
        $parse = $this->parseKey($key);

        if (!isset($this->configs[$parse['key']])) {
            $this->load($parse);
        }

        if (!$parse['paths'] && !is_array($value)) {
            throw new Exception('the first level:[' . $parse['key'] . '] must be array!');
        }

        $this->setValue($this->configs[$parse['key']], $parse['paths'], $value);
        return $this;
    }

    public function save(string $key, $value): self
    {
        $parse = $this->parseKey($key);

        if (!isset($this->configs[$parse['key']])) {
            $this->load($parse);
        }

        if (!$parse['paths'] && !is_array($value)) {
            throw new Exception('the first level:[' . $parse['key'] . '] must be array!');
        }

        $res = null;
        if (is_file($parse['config_file'])) {
            $tmp = $this->requireFile($parse['config_file']);
            if (is_array($tmp)) {
                $res = $tmp;
            } elseif (!is_null($tmp)) {
                throw new Exception('the config file:[' . $parse['config_file'] . '] must return array!');
            }
        }

        $this->setValue($res, $parse['paths'], $value);

        if (!is_dir(dirname($parse['config_file']))) {
            mkdir(dirname($parse['config_file']), 0755, true);
        }

        file_put_contents($parse['config_file'], '<?php return ' . var_export($res, true) . ';');

        return $this;
    }

    private function load(array $parse)
    {
        $args = [];

        if (isset($parse['default_file']) && is_file($parse['default_file'])) {
            $tmp = $this->requireFile($parse['default_file']);
            if (is_array($tmp)) {
                $args[] = $tmp;
            } elseif (!is_null($tmp)) {
                throw new Exception('the config file:[' . $parse['default_file'] . '] must return array!');
            }
        }

        if (is_file($parse['config_file'])) {
            $tmp = $this->requireFile($parse['config_file']);
            if (is_array($tmp)) {
                $args[] = $tmp;
            } elseif (!is_null($tmp)) {
                throw new Exception('the config file:[' . $parse['default_file'] . '] must return array!');
            }
        }

        $this->configs[$parse['key']] = $args ? array_merge(...$args) : null;
    }

    private function getValue($data, $path, $default)
    {
        $key = array_shift($path);

        if (is_null($key)) {
            return $data;
        }

        if (!isset($data[$key])) {
            return $default;
        }

        return $this->getValue($data[$key], $path, $default);
    }

    private function setValue(&$data, $path, $value)
    {
        $key = array_shift($path);
        if (is_null($key)) {
            $data = $value;
        } else {
            if (!isset($data[$key])) {
                $data[$key] = null;
            }
            $this->setValue($data[$key], $path, $value);
        }
    }

    private function parseKey(string $key): array
    {
        $res = [];

        list($path, $group) = explode('@', $key . '@');

        if (!strlen($path)) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }

        $paths = array_filter(
            explode('.', $path),
            function ($val) {
                return strlen($val) > 0 ? true : false;
            }
        );

        $res['filename'] = array_shift($paths);
        $res['paths'] = $paths;
        $project_dir = dirname(dirname(dirname((new ReflectionClass(InstalledVersions::class))->getFileName())));
        if (!strlen($group)) {
            $res['config_file'] = $project_dir . '/config/' . $res['filename'] . '.php';
            $res['key'] = $res['filename'];
        } else {
            $group = str_replace('.', '/', $group);
            $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $group . '\\Hook', '/\\-'));
            $reflector = new ReflectionClass($class_name);
            $res['default_file'] = dirname(dirname($reflector->getFileName())) . '/config/' . $res['filename'] . '.php';
            $res['config_file'] = $project_dir . '/config/' . $group . '/' . $res['filename'] . '.php';
            $res['key'] = $res['filename'] . '@' . $group;
        }

        return $res;
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
