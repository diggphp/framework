# framework

PHP应用开发框架

## 安装

``` bash
composer require diggphp/framework
```

然后，需要加上：

``` json
{
    "scripts": {
        "post-package-install": "DiggPHP\\Framework\\Script::onInstall",
        "post-package-update": "DiggPHP\\Framework\\Script::onUpdate",
        "pre-package-uninstall": "DiggPHP\\Framework\\Script::onUnInstall"
    }
}
```

## 用例

``` php
\DiggPHP\Framework\Framework::run();
```
