<?php

declare(strict_types=1);

namespace DiggPHP\Framework;

use DiggPHP\Psr11\Container;
use Composer\Installer\PackageEvent;
use Exception;
use Throwable;

class Script
{
    public static function __callStatic($name, $arguments)
    {
        if (!in_array($name, ['onInstall', 'onUpdate', 'onUnInstall'])) {
            return;
        }

        /**
         * @var PackageEvent $event
         */
        $event = $arguments[0];

        Framework::execute(function (
            Container $container
        ) use ($event) {
            $container->set(PackageEvent::class, function () use ($event): PackageEvent {
                return $event;
            });
        });

        $operation = $event->getOperation();

        if (in_array($name, ['onUpdate'])) {
            $package_name = $operation->getTargetPackage()->getName();
        } else {
            $package_name = $operation->getPackage()->getName();
        }
        $class_name = str_replace(['-', '/'], ['', '\\'], ucwords('App\\' . $package_name . '\\App', '/\\-'));

        if (!class_exists($class_name) || !is_subclass_of($class_name, AppInterface::class)) {
            return;
        }

        if (!method_exists($class_name, $name)) {
            return;
        }

        start:
        try {
            Framework::execute([$class_name, $name]);
        } catch (Throwable $th) {
            fwrite(STDOUT, "发生错误：" . $th->getMessage() . "\n");
            fwrite(STDOUT, "重试请输[r] 忽略请输[y] 终止请输[q]：");
            $input = trim((string) fgets(STDIN));
            switch ($input) {
                case '':
                case 'r':
                    goto start;
                    break;

                case 'y':
                    fwrite(STDOUT, "已忽略该错误~\n");
                    break;

                default:
                    throw new Exception("发生错误，终止！");
                    break;
            }
        }
    }
}
