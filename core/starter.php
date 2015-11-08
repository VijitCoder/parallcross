<?php
/**
 * Консольный скрипт для запуска воркеров.
 *
 * Параметры функции воркера директор передает через посредника, читаем в нем.
 *
 * Скрипт ожидает два параметра:
 * <ul>
 * <li>ключ для подключения к посреднику</li>
 * <li>длину данных для чтения из посредника</li>
 * </ul>
 *
 * Скрипт не предназначен для прямого запуска. Это часть инициализации многопроцессной работы. С ним
 * должен работать управляющий класс ("директор").
 * @see AbstractBoss::employ() комментарий и реализацию.
 * @see AbstractBoss::_runWorkerProcess() в дополнение.
 *
 * Прим.: не задаем время выполнения через set_time_limit(), т.к. для консольных скриптов оно
 * по умолчанию равно 0.
 */

namespace core;

require 'init.php';

if ($argc < 3) {
    echo "\nНеверные параметры запуска. Первым параметром должен быть указан ключ блока\n".
         "разделяемой памяти. Второй параметр - длина блока.\n\n";
    exit(0);
}
$key = $argv[1];
$size = $argv[2];
echo "Ключ : $key\n".
     "Длина блока: $size\n";

$conf = App::conf('dealer');
$conf['size'] = $size;
$conf['key'] = $key;
$dealer = new $conf['class']($conf);

if (!$data = $dealer->readData()) {
    throw new \Exception($dealer->getErrors());
}

echo "Данные:\n";
var_dump($data);

$worker = $data['worker'];
if ($isClass = is_array($worker)) {
    $class = new $worker[0]($dealer);
    $worker[0] = $class;
}

echo App::conf('codePhrase') . "\n";

if ($isClass) {
    $result = is_null($data['params'])
        ? call_user_func($worker)
        : call_user_func($worker, $data['params']);
//функция вне класса
} else {
    $result = is_null($data['params'])
        ? call_user_func($worker, $dealer)
        : call_user_func($worker, $dealer, $data['params']);
}

//TODO что делать с $result? Оно никому не нужно.
echo "Starter.Done\n";
