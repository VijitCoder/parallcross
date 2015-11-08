<?php
/**
 * Некоторые огранизационные моменты, которые на реальном сайте могут быть сложнее или находиться
 * в разных местах движка.
 *
 * Не размещаем в index.php, потому что такая инициализация так же нужна загрузчику дочерних процессов.
 * @see starter.php
 */
mb_internal_encoding('UTF-8');

// Константа с путем к корню сайта. Кроссплатформа
define('ROOT_PATH', realpath(str_replace('\\', '/', __DIR__) . '/../') . '/');

define ('DEBUG', true);

ini_set('display_errors', (int)DEBUG);
ini_set('display_startup_errors', (int)DEBUG);
error_reporting(DEBUG ? E_ALL : 0);

require_once 'interfaces.php';
require 'implementation/exampleFunction.php';

/**
 * Автозагрузчик классов. Кроссплатформа.
 *
 * Работает с пространствами имен. Прогоняет имя через карту замены, в которой прописаны соответствия
 * пространств имен и реальных каталогов от корня сайта.
 *
 * Скрипт с интерфейсами подключен отдельно, автозагрузчик с ним не работает.
 *
 * @param string $class имя класса для загрузки
 */
spl_autoload_register(function($class) {
    $replaces = [
        '\\' => '/',
        'imp/' => 'implementation/',
    ];
    $class = str_replace(array_keys($replaces), $replaces, $class);
    require_once ROOT_PATH . $class . '.php';
});

set_exception_handler(['\core\App', 'exceptionHandler']);

if (!function_exists('popen')) {
    throw new \RuntimeException('Решение основано на функции popen(), которой нет в вашей сборке PHP.');
}
