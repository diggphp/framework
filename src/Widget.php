<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use Composer\InstalledVersions;
use DiggPHP\Template\Template;
use InvalidArgumentException;
use ReflectionClass;

class Widget
{
    public function get(string $key): string
    {
        $widget_file = $this->parseKey($key);
        $string = file_exists($widget_file) ? file_get_contents($widget_file) : '';
        return Framework::execute(function (
            Template $template
        ) use ($string): string {
            return $template->renderFromString($string);
        });
    }

    private function parseKey(string $key): string
    {
        list($filename, $group) = explode('@', $key . '@');

        if (!strlen($filename)) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }

        if (!strlen($group)) {
            $project_dir = dirname(dirname(dirname((new ReflectionClass(InstalledVersions::class))->getFileName())));
            $widget_file = $project_dir . '/widget/' . $filename . '.php';
        } else {
            $group = str_replace('.', '/', $group);
            $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('\\App\\' . $group . '\\App', '/\\-'));
            $reflector = new ReflectionClass($class_name);
            $widget_file = dirname(dirname($reflector->getFileName())) . '/widget/' . $filename . '.php';
        }

        return $widget_file;
    }
}
