<?php
header('Content-Type: text/html; charset=UTF-8');

require 'core/init.php';

/*
Нижеследующее должно быть не в index.php, а где-то по месту необходимости. Но для тестовой задачи -
нормально. Для примера, запустим одного воркера через функцию вне класса. Делать будет тоже самое, но
через свою реализацию.
*/

define('START_AT', microtime(true));

$conf = core\App::conf('PiSetup');
extract($conf);

echo '<h4>Параллельные вычисления числа Pi методом Монте-Карло</h4>'
    ."<p>Запускаемых подпроцессов: <b>{$workers}</b></p>"
    ."<p>Число шагов вычисления в промежутке <b>[$minIterations, $maxIterations]</b></p>"
    .'<p><i>время выполнения округлено до 5 знаков после запятой (для удобства)</i></p>'
;

$boss = (new imp\PiBoss);

for ($i = 1; $i < $workers; $i++) {
    $p = ['iterations' => mt_rand($minIterations, $maxIterations)];
    if($err = $boss->employ(['imp\PiWorker', 'calc'], $p)) {
        throw new \Exception($err);
    }
}
if($err = $boss->employ('imp\piCalc', ['iterations' => mt_rand($minIterations, $maxIterations)])) {
    throw new \Exception($err);
}

$boss->manage();

echo '<br>Время выполнения ' . round(microtime(true) - START_AT, 5);
