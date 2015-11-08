<?php
/**
 * Воркер. Расчет числа Pi методом Монте-Карло.
 *
 * Суть метода тут:
 * @link http://habrahabr.ru/post/128454/
 * @link http://algolist.manual.ru/maths/count_fast/pi.php
 */

namespace imp;

use \core\IDealer as IDealer;

class PiWorker extends \core\AbstractWorker
{
    /**
     * Расчет числа Pi.
     *
     * На запрос статуса посылаем текущее расчитанное значение Pi.
     *
     * @param array $params параметры. Ожидаем только iterations - количество итераций расчета
     * @return void
     */
    public function calc($params)
    {
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

            while (IDealer::STATUS === $this->processMessages()) {
                $this->sendStatus($this->_currentPi($match, $i));
            }
        }

        $this->finish($this->_currentPi($match, $i) . ' <b>закончил</b>');
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
    private function _currentPi($match, $try)
    {
        $s = "шаг = {$try}, pi = " . ($try === 0 ? : $match / $try * 4);
        return $s;
    }
}
