<?php
/**
 * Расчет числа Pi методом Монте-Карло.
 *
 * Воркер - функция вне класса. Однако общаться с директором она все равно будет через класс,
 * в котором реализован необходимый набор методов.
 *
 * ЭТО ПРИМЕР использования функции вне класса. Не претендует на существование в реальном проекте.
 * Приведенные тут функции - копипаст с методов imp\PiWorker с небольшими изменениями.
 */

namespace imp;

use \core\IDealer as IDealer;

/**
 * Расчет числа Pi.
 *
 * На запрос статуса посылаем текущее расчитанное значение Pi.
 *
 * @param object $dealer объект реализации интерфейса IDealer
 * @param array $params параметры. Ожидаем только iterations - количество итераций расчета.
 * @return void
 */
function piCalc($dealer, $params)
{
    $feedback = new \core\AbstractWorker($dealer);

    extract($params); //$iterations
    $radius = 46340;
    $r2 = pow($radius, 2);
    $match = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $x = mt_rand(1, $radius);
        $y = mt_rand(1, $radius);
        if (pow($x, 2) + pow($y, 2) < $r2) {
            $match++;
        }

        while (IDealer::STATUS === $feedback->processMessages()) {
            $feedback->sendStatus(_currentPi($match, $i));
        }
    }

    $feedback->finish(_currentPi($match, $i) . ' <b>закончил</b>');
}

/**
 * Текущее значение Pi.
 *
 * Расчитываем значение Pi на основании числа попыток и попаданий точки к четверть круга.
 * Подробнее см. в описании метода Монте-Карло.
 *
 * @param int $match совпадения
 * @param int $try число попыток
 * @return float
 */
function _currentPi($match, $try)
{
    $s = "шаг = {$try}, pi = " . ($try === 0 ? : $match / $try * 4);
    return $s;
}
