<?php
/**
 * Директор. Расчет числа Pi методом Монте-Карло.
 */

namespace imp;

use \core\IDealer as IDealer;

class PiBoss extends \core\AbstractBoss
{
    /**
     * Управление воркерами.
     *
     * Частная реализация, под конкретную задачу.
     */
    public function manage()
    {
        $msgStatus = $this->prepareMessage([], IDealer::STATUS);
        $idle = $this->conf('PiSetup')['idleMax'];
        do {
            usleep(mt_rand(0, $idle));

            $elapsed = round(microtime(true) - START_AT, 5);
            echo "<hr>Прошло времени: <i>$elapsed</i> сек<br>";

            $result = $this->handleMessages($msgStatus);

            if ($result['lost']) {
                $this->_checkForLost();
            }

            foreach ($result['update'] as $k => $v) {
                echo "воркер <small>$k</small>. {$v['data']}<br>";
                flush();
                if ($v['signal'] === IDealer::STATUS_FINISH) {
                    $this->dismiss($k);
                }
            }

        } while ($this->staff);
    }

    /**
     * Проверка "кто потерялся" с последующим увольнением.
     */
    private function _checkForLost()
    {
        foreach($this->staff as $k => $v) {
            if ($v['state'] === IDealer::STATUS_LOST) {

                $v['dealer']->writeSignal(IDealer::STOP);
                //$this->handleMessages([$k => IDealer::STOP]);

                echo "<hr><span style='color:red;'>Потерялся воркер $k</span><br><br>";

                echo "<b>Выдача воркера $k</b><br>";
                while (!feof($v['worker'])) {
                    echo nl2br(fread($v['worker'], 2096));
                    flush();
                }

                echo '<br><br><br>';

                $st = $this->dismiss($k, true);
                echo "Закрыл процесс со статусом $st<br>";
            }
        }
    }
}
