<?php
/**
 * Воркер. Абстракт
 *
 * Собственно абстрактных методов в классе нет. Однако преполагается обязательное наследование
 * классами-воркерами. Поэтому "абстракт".
 *
 * Исключение - функция вне класса в качестве воркера. Вот в ней как раз необходимо создать объект
 * именно этого класса. Поэтому видимость всех методов - <i>public</i>, чтобы функция вне класса
 * могла с ними работать.
 */

namespace core;

class AbstractWorker extends App
{
    /** @var статус воркера. Видимость <i>public</i>, чтобы функция вне класса могла быть воркером. */
    public $status;

    /** @var object объект реализации интерфейса IDealer */
    private $_dealer;

    /**
     * Конструктор.
     *
     * Запоминаем посредника, через которого будем работать с директором.
     * Делаем первую проверку сообщений, обновляем статус воркера.
     *
     * @param object объект реализации интерфейса IDealer
     */
    public function __construct($dealer)
    {
        $this->_dealer = $dealer;

        if (!$this->processMessages()) {
            $this->emergencyExit('сбой инициализации');
        }
    }

    /**
     * Проверка очереди сообщений.
     *
     * В зависимости от управляющих сигналов и статуса проверка может занимать разное время. Если
     * будет достигнут предел времени мониторинга, метод вернет FALSE. Реакция на таймаут зависит
     * от реализации метода воркера.
     *
     * <b>Сигналы и поведение метода.</b>
     *
     * Если проверка посредника вернет значение из STATUS_*, значит распоряжений от директора не было.
     * Исключение в статусах - STATUS_CALLING, см. ниже. При получении управляющих сигналов реакции
     * такие:
     * <ul>
     * <li>SUSPEND, ставим классу-воркеру и в посреднике STATUS_WAITING. Остаемся в цикле, через
     * паузы проверяем посредника до получения RESUME или STOP.</li>
     *
     * <li>RESUME меняем статус воркера и в посреднике на STATUS_WORKING и выходим из метода.</li>
     *
     * <li>STOP завершаем работу приложения, {@see self::emergencyExit()}</li>
     *
     * <li>STATUS ставим воркеру текущий статус (если сейчас в режиме ожидания, то его, иначе -
     * "работаю"), выходим из метода. Клиент должен ответить на запрос и опять вызвать этот метод.
     * Такой ход не позволит воркеру продолжится, если он был в ожидании в момент получения STATUS.
     * {@see self::checkMessages()}
     * </li>
     *
     * <li>DATA передача данных от директора. В посредник - текущий статус и выходим. В клиенте
     * поведение аналогичное реакции на STATUS. {@see self::recieveData()}</li>
     *
     * <li>STATUS_CALLING особый случай. Если при входе в метод был такой статус, тогда ждем от
     * директора либо LISTEN для последующей передачи данных, либо STOP.</li>
     *
     * <li>LISTEN директор готов к приему данных. Ничего не меняя выходим. После передачи данных
     * вернемся сюда с новым статусом, подробности {@see self::sendData()}</li>
     *
     * <li>любой другой сигнал - ничего не меняем и <b>выходим</b>. Т.о. можно непреднамерно выйти
     * из состояния ожидания. Но это исключительный случай. Есть только один такой сигнал STATUS_LOST,
     * который можно получить, если не ответить директору в его период ожидания. Реакция зависит
     * от реализации данного класса.</li>
     * </ul>
     *
     * @param float $timeMax максимальное количество секунд мониторинга очереди. Если не задано,
     * читаем из конфига приложения.
     *
     * @return int | false код сигнала или FALSE, если достигли предела времени мониторинга
     */
    final public function processMessages($timeMax = null)
    {
        $conf = $this->conf('messageQueue');

        if ($timeMax === null) {
            $timeMax = $conf['worker_timeout'];
        }

        if ($timeMax <= 0) {
            throw new \RuntimeException('Недопустимый таймаут');
        }

        $status = $this->status;
        $idleTime = round(1000000 / $conf['worker_MPS']);

        $d = $this->_dealer;
        $start = microtime(true);
        do {
            if (!$signal = $d->readSignal()) {
                throw new \RuntimeException($d->getErrors());
            };

            if ($signal !== $status) {

                if ($status === $d::STATUS_CALLING && !in_array($signal, [$d::STOP, $d::LISTEN])) {
                    $d->writeSignal($status);
                } else {
                    switch ($signal) {

                        case $d::SUSPEND:
                            $status = $this->status = $d::STATUS_WAITING;
                            $d->writeSignal($status);
                            break;

                        case $d::RESUME:
                            $status = $this->status = $d::STATUS_WORKING;
                            $d->writeSignal($status);
                            return $signal;

                        case $d::STOP:
                            $this->emergencyExit('получен сигнал останова');

                        case $d::STATUS:
                            $this->status = $status;
                            return $signal;

                        case $d::DATA:
                            $this->status = $status;
                            return $signal;

                        case $d::LISTEN:
                            return $signal;

                        default:
                            return $signal;
                    }
                }
            }

            if ($status == $d::STATUS_WORKING) {
                return $signal;
            }

            usleep($idleTime);
        } while (microtime(true) - $start <= $timeMax);

        return false;
    }

    /**
     * Экстренный выход.
     *
     * Последняя возможность сообщить директору об уходе.
     *
     * Например, выходим по причине таймаута очереди сообщений; воркер был в режиме "приостановлен",
     * и в течение заданного времени так и не получил разрешение продолжить работу. Тогда выходим и
     * отмечаемся директору.
     *
     * @param string $exitStatus доп. сообщение на выход
     */
    public function emergencyExit($exitStatus = '')
    {
        $d = $this->_dealer;
        $d->write(
            $d::STATUS_STOPED,
              "Экстренный выход\n"
            . "Статус воркера на выходе: {$this->status}\n"
            . "Доп.инфромация: {$exitStatus}"
        );
        exit($exitStatus);
    }

    /**
     * Посредник. Геттер.
     *
     * Функция вне класса должна до него дотянуться.
     *
     * @return int
     */
    public function getDealer()
    {
        return $this->_dealer;
    }

    /**
     * Проверяем сообщения.
     *
     * Выйти из этой проверки можно только с определенным статусом или по таймауту. Полученный
     * на выходе сигнал возвращаем в клиентский код.
     *
     * Реализованный тут подход позволяет отвечать данными на запрос STATUS, находясь при этом
     * в состоянии STATUS_WAITING. Чтобы после ответа воркер не продолжил вдруг работу, отвечаем и
     * возвращаемся в обработку сообщений.
     *
     * Прим.: сбор данных для запроса STATUS может оказаться дорогим по времени. В таком случае нужно
     * перести код этого метода прямо в клиентский код и собирать данные внутри описанного тут цикла.
     *
     * @param mixed $data данные, которые можно передать на запрос STATUS
     * @return int сигнал
     */
    public function checkMessages($data)
    {
        while (IDealer::STATUS === ($signal = $this->processMessages())) {
            $this->sendStatus($data);
        }
        return $signal;
    }

    /**
     * Отправка своего статуса.
     *
     * Отправляем текущее состояние воркера и возможно какие-то данные.
     *
     * @param mixed $data данные для передачи вместе со статусом
     * @return bool
     */
    public function sendStatus ($data = null)
    {
        return $this->_dealer->write($this->status, $data);
    }

    /**
     * Получение данных от директора.
     *
     * Проверяем переданный сигнал. Если он не равен IDealer::DATA, возращаем null.
     *
     * При получении сигнала DATA читаем из посредника данные, после чего обязательно пишем в него
     * свой статус (иначе директор не узнает, что данные приняты) и затираем в посреднике полученные
     * данные.
     *
     * Потом еще раз проверяем сообщения, т.к. в зависимости от текущего статуса возможны варианты
     * дальнейшего поведения.
     *
     * @param int $signal сигнал, полученный ранее через self::processMessages()
     *
     * @return null | array массив [(mixed)данные, (int)сигнал]. Можно использовать list() для
     * разбора в переменные.
     */
    public function recieveData($signal)
    {
        if (IDealer::DATA !== $signal) {
            return null;
        }

        $data = $this->_dealer->readData();
        $this->sendStatus('');
        $signal = $this->processMessages();
        return [$data, $signal];
    }

    /**
     * Передача данных директору.
     *
     * Можно написать свою реализацию. В таком случае нужно понять принцип обмена данными.
     *
     * Это частная (исключительная) ситуация, когда воркер по собственной инициативе хочет передать
     * данные директору. Если в методе директора не описан подходящий хендлер, воркер не дождется
     * ответа. Идея такая: воркер ставит свой статус STATUS_CALLING и ждет ответа директора. Когда
     * получит LISTEN, передаст данные <b>за один раз</b> (иное непреусмотрено) и перейдет в состояние
     * STATUS_WAITING. Директор получив данные должен решить, что делать воркеру дальше, и отправить
     * ему соответствующий сигнал.
     *
     * Как это работает: зайдя в обработку сообщений со статусом STATUS_CALLING воркер оттуда не выйдет,
     * пока не получит сигнал директора или не истечет время ожидания. Если директор ответил,
     * произойдет запланированная реакция. Внимание! Директор может ответить только LISTEN | STOP.
     * Другие сигналы будут игнорироваться воркером в состоянии STATUS_CALLING.
     *
     * Если директор не ответил, то статус воркера не изменится, выйдет из обработки по таймауту.
     * Чтобы не получилось непонятных багов, в клиентском коде обязательно проверяем, что возвращает
     * этот метод.
     *
     * Прим.: здесь таймаут ожидания увеличен в два раза.
     *
     * @param mixed $data
     * @return int | false полученный сигнал или FALSE в случае таймаута ожидания
     */
    public function sendData($data)
    {
        $t = $this->conf('messageQueue')['worker_timeout'];
        $this->status = IDealer::STATUS_CALLING;
        if (IDealer::LISTEN === ($signal = $this->processMessages($t * 2))) {
            $this->status = IDealer::STATUS_WAITING;
            $this->_dealer->write($this->status, $data);
        }
        return $signal;
    }

    /**
     * Финиш.
     *
     * Уведомление директору об окончании работы воркера. Отправляем STATUS_FINISH. Можно передать
     * какие-то данные в этом же сообщении.
     *
     * @param mixed $data
     * @return bool
     */
    public function finish ($data = null)
    {
        return $this->_dealer->write(IDealer::STATUS_FINISH, $data);
    }
}
