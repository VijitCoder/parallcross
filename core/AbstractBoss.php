<?php
/**
 * Директор. Абстракт
 */

namespace core;

abstract class AbstractBoss extends App
{
    /**
     * @var array массив, описывающий запущенные подпроцессы. Каждый элемент - массив как минимум с
     * тремя элементами:
     * <ul>
     * <li><b>state</b> - текущее состояние воркера (см. сигналы IDealer). Это состояние можно задать
     * уже на этапе запуска подпроцессов.</li>
     * <li><b>dealer</b> - объект посредника (реализация IDealer)</li>
     * <li><b>worker</b> - указатель на подпроцесс (resource)</li>
     * </ul>
     */
    protected $staff = [];

    /** @var int состояние воркеров при их запуске {@see IDealer} */
    private $_initialState;

    /**
     * Конструктор.
     *
     * Задаем начальное состояние воркеров. Логичны два состояния: IDealer::SUSPEND и IDealer::RESUME.
     * В первом случае гарантируем одновременный старт запущенных воркеров (потребуется отправка
     * соответствующего сигнала). Во втором случае воркер начнет работу самостоятельно, без особого
     * указания директора.
     *
     * @param bool $autostart автозапуск воркера на выполнение задачи
     */
    public function __construct($autostart = true)
    {
        $this->_initialState = $autostart ? IDealer::RESUME : IDealer::SUSPEND;
    }

    /**
     * Запускаем подпроцесс (воркер).
     *
     * Если воркером будет функция вне класса, она должна принимать как минимум один параметр - объект
     * реализации IDealer. Для метода класса это не нужно, но класс должен быть наследником
     * {@see AbstractWorker}.
     *
     * Если для воркера есть параметры, описываем их в массиве. Массив будет передан через посредника
     * в функцию-воркер при ее вызове. Следует учитывать, что если функция-воркер находится в классе,
     * то она должна принимать только один параметр - этот массив. Если воркер - функция вне класса
     * и ожидает параметры, тогда ей передаются два параметра: объект-посредник и массив параметров
     * непосредственно для функции.
     *
     * @param array|string $worker имя функции или массив [класс, метод].
     * @param array $params параметры, которые должен получить воркер.
     * @return null|string текст ошибки или NULL
     */
    public function employ($worker, $params = null)
    {
        $data = compact('worker', 'params');
        $conf = $this->conf('dealer');
        $state = $this->_initialState;

        $dealer = new $conf['class']($conf);
        if (!$key = $dealer->init()) {
            return $dealer->getErrors();
        }
        $dealer->write($state, $data);

        if ($err = $dealer->getErrors()) {
            return $err;
        }

        $this->staff[$key] = [
            'state'  => $state,
            'dealer' => $dealer,
            'worker' => $this->_runWorkerProcess($key, $conf['size']),
        ];
    }

    /**
     * Уволить воркера.
     *
     * Закрываем подпроцесс. Удаляем элемент из массива self::$staff
     *
     * Пишем в посредник IDealer::STOP. После закрытия подпроцесса в нормальном режиме воркер уже не
     * будет работать и читать сообщения. Но если он завис, то вероятно после все же прочитает сигнал
     * и выйдет.
     *
     * @param array $key ключ массива self::$staff
     * @param bool $withSign флаг "писать сигнал в посредник?"
     * @return int статус выхода завершающегося процесса. В случае ошибки возвращается -1.
     */
    protected function dismiss($key, $withSign = false)
    {
        $one = $this->staff[$key];
        unset($this->staff[$key]);
        if ($withSign) {
            $one['dealer']->writeSignal(IDealer::STOP);
        }
        $one['dealer']->close();
        return pclose($one['worker']);
    }

    /**
     * Управление воркерами.
     *
     * Реализация зависит от конкретной задачи. В этом методе организуем общение с воркерами,
     * синхронизацию, останов и др. действия по управлению параллельной работой.
     * @return void
     */
    abstract public function manage();

    /**
     * Запуск подпроцесса в фоновом режиме.
     *
     * Не придумал эффективный контроль над запуском. Поэтому читаем выдачу popen() и ищем в ней
     * "кодовую фразу". По результату либо вернем дескриптор потока либо пробросим исключение.
     *
     * @param string $key ключ к посреднику для межпроцессного взаимодействия
     * @param int $size длина отведенного пространства в посреднике
     * @return resource
     */
    private function _runWorkerProcess($key, $size)
    {
        //прим.: добавляем перенаправление, чтобы прочитать STDERR
        $starter = ROOT_PATH . "core/starter.php {$key} {$size} 2>&1";
        if (!$handle = popen('php ' . $starter, 'r')) {
            throw new \RuntimeException('Не удалось запустить подпроцесс');
        }

        $out = '';
        $codePhrase = $this->conf('codePhrase');
        while (($chunk = fread($handle, 2096))) {
            $out .= $chunk;
            if (strrpos($chunk, $codePhrase) !== false) {
                return $handle;
            }
        }

        pclose($handle);
        $err = "Кодовая фраза не найдена в запущенном подпроцессе. Прочитал следующее: \n" . $out;
        throw new \Exception(nl2br($err));
    }

    /**
     * Управление очередью сообщений.
     *
     * Можно отправить конкретные указания каким-то воркерам или просто проверить всех посредников на
     * предмет изменения состояний воркеров. Возвращаем массив с обновленными данными (сигнал, данные),
     * для каждого воркера - отдельный элемент массива. Если состояниe воркера в ходе проверки
     * не изменилось, в массиве ничего по нему не будет.
     *
     * Каждое сообщение (если они есть) как правило содержит управляющий сигнал для воркера. В таком
     * случае отправляем сигнал и ждем в цикле, пока не получим STATUS_* сообщения от воркеров или
     * до наступления таймаута ожидания.
     *
     * Если вышли из цикла проверки по таймауту, в ответе указываем, сколько воркеров не ответило.
     * Считаем их потерявшимися, ставим состояние таким воркерам STATUS_LOST. Его можно прочитать
     * в self::$staff и в соответствующих посредниках. Реакция на такое состояние должна быть описана
     * в конкретной реализации этого класса.
     *
     * Таймаут ожидания задается настройкой + небольшое случайное число. Это способ избежать бага,
     * который я не смог объяснить: вероятно при одновременном чтении одного сегмента разделяемой
     * памяти подпроцесс "глохнет" и не видит сигнала. На что текущий метод выходит по таймауту и
     * объвляет воркера потерянным. Добавление случайного числа не исключает описанную ситуацию,
     * но делает вероятность меньше. TODO разрулить эту проблему. Хотя вряд ли получится :(
     *
     * @param array $messages сообщения конкретным воркерам. Ключ соответствует ключу self::$staff.
     * Значение либо строка с сигналом либо неассоциативный массив [(int) сигнал, (mixed)данные].
     *
     * @param float $timeMax максимальное количество секунд мониторинга очереди. Если не задано,
     * читаем из конфига приложения.
     *
     * @return array ['update' => array, 'lost' => int] Обновленные данные и количество потерявшихся.
     * Данные - массивы ['signal' => int, 'data' => mixed].
     */
    final protected function handleMessages($messages = [], $timeMax = null)
    {
        $conf = $this->conf('messageQueue');

        if ($timeMax === null) {
            $timeMax = $conf['boss_timeout'];
        }

        if ($timeMax <= 0) {
            throw new \RuntimeException('Недопустимый таймаут');
        }

        $idleTime = round(1000000 / $conf['boss_MPS']);
        $start = microtime(true);
        $update = $ok = [];
        do {
            $repeat = false;
            foreach ($this->staff as $k => &$v) {
                $d = $v['dealer'];

                if (isset($messages[$k])) {
                    $msg = $messages[$k];
                    unset($messages[$k]);
                    if (is_array($msg)) {
                        $signal = $msg[0];
                    } else {
                        $signal = $msg;
                    }
                } else {
                    $signal = $v['state'];
                 }

                if ($needSend = ($signal !== $v['state'])) {
                    $v['state'] = $signal;
                }

                if (!$raw = $d->read()) {
                    throw new \RuntimeException($d->getErrors());
                };
                $status = $raw['signal'];

                if (in_array($status, [$d::STATUS_STOPED, $d::STATUS_FINISH])
                    || $status === $d::STATUS_CALLING && $signal !== $d::LISTEN
                ) {
                    $repeat = $needSend = false;
                } else {
                    //нужно ждать подтверждения на любой посланный сигнал.
                    $actions = [$d::SUSPEND, $d::RESUME, $d::STOP, $d::STATUS, $d::DATA, $d::LISTEN];
                    $repeat = in_array($status, $actions) || $needSend;
                }

                if ($needSend) {
                    if (isset($msg[1])) {
                        $d->write($signal, $msg[1]);
                    } else {
                        $d->writeSignal($signal);
                    }
                }

                if (!$repeat) {
                    if($v['state'] !== $status) {
                        $update[$k] = $raw;
                        $v['state'] = $status;
                    }
                    $ok[] = $k;
                }
            }
            usleep($idleTime + mt_rand(10000, 50000));
        } while (microtime(true) - $start <= $timeMax && $repeat);

        if ($repeat) {
            $keys = array_keys($this->staff);
            $lostWorkers = array_diff($keys, $ok);
            foreach($lostWorkers as $k) {
                $this->staff[$k]['state'] = IDealer::STATUS_LOST;
                $this->staff[$k]['dealer']->write(IDealer::STATUS_LOST, '');
            }
            $lost = count($lostWorkers);
        } else {
            $lost = 0;
        }

        return compact('update', 'lost');
    }

    /**
     * Подготовка сообщения.
     *
     * Обработчику сообщений нужен сложный массив. Этот метод облегчает его сборку.
     *
     * @param array $keys ключи массива self::$staff. Если ничего не указано, сообщение будет для
     * всех воркеров
     *
     * @param int $signal управляющий сигнал в сообщении
     * @param mixed $data данные в сообщении, если есть что передать
     * @return array
     */
    public function prepareMessage($keys, $signal, $data = null)
    {
        if (!$keys) {
            $keys = array_keys($this->staff);
        }
        $msg = [];
        foreach (array_keys($this->staff) as $key) {
            if ($data) {
                $msg[$key] = [$signal, $data];
            } else {
                $msg[$key] = $signal;
            }
        }
        return $msg;
    }

    /**
     * Передача данных воркеру.
     *
     * Передача выполняется через очередь сообщений. Возвращаем ее результат,
     * {@see AbstractBoss::handleMessages()}
     *
     * @param string $key ключ массива self::$staff, определяющий воркера-получателя
     * @param mixed $data данные для передачи
     * @return array
     */
    protected function sendData($key, $data)
    {
        return $this->handleMessages($this->prepareMessage($key, IDealer::DATA, $data));
    }

    /**
     * Получение данных от воркера.
     *
     * Прежде всего проверяется нужный сигнал. Поэтому функцию можно вызывать в любом месте контроля
     * сообщений, без проверки сигнала в клиенте.
     *
     * Это ситуация, когда воркер сам инициирует передачу данных. Обычно директор может их получить
     * через запрос STATUS.
     *
     * Такая передача данных организована через обмен сообщениями. Воркер уставливает статус
     * STATUS_CALLING, ждет ответ LISTEN. Передает данные <b>за один раз</b> (иное непреусмотрено)
     * и переходит в состояние STATUS_WAITING.
     *
     * Полученные данные отправляются в callback-функцию. Формат функции:
     *
     *      function(&$data) return string|array;
     *
     * Она должна принимать данные через один параметр по ссылке(!) и возвращать указание воркеру,
     * что дальше делать. Формат указания: либо сигнал (int), либо массив [сигнал, данные].
     *
     * Метод возвращает полученные данные, после проведения их через callback-функцию.
     *
     * @param string $key ключ массива self::$staff, определяющий воркера-отправителя
     * @param int $state текущее состояние воркера. Ожидаем STATUS_CALLING, остальное игнорируется.
     * @param callback $function функция обратного вызова для обработки данных
     * @return null | mixed обработанные данные или NULL, если сигнал не соответствовал запросу на
     * передачу данных.
     */
    protected function recieveData($key, $state, $function)
    {
        if ($state === IDealer::STATUS_CALLING) {
            $msg = [$key => [IDealer::LISTEN, '']];
            $result = $this->handleMessages($msg);

            $data = $result['update'][$key]['data'];
            $todo = call_user_func_array($function, array(&$data));

            $this->handleMessages([$key => $todo]);
            return $data;
        }
    }

    /**
     * Деструктор.
     *
     * Закрываем дочерние процессы. Дальше они уже не работают, несмотря на фоновый запуск.
     */
    public function __destruct()
    {
        foreach ($this->staff as $one) {
            pclose($one['worker']);
        }
    }
}
