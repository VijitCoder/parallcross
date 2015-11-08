<?php
/**
 * Посредник "Разделяемая память" (Shared memory)
 *
 * Один объект соответствует одному блоку разделяемой памяти.
 * Мат.часть {@link http://php.net/manual/ru/ref.shmop.php}
 *
 * В данной реализации в конфиге определяется размер блока разделяемой памяти. Так вот первый байт
 * блока будет занят под межпроцессные управляющие сигналы, остальное - "свободная" зона.
 */

namespace core;

class SharedMemory extends \core\App implements \core\IDealer
{
    /** @var int размер разделяемого блока памяти, в байтах */
    private $_size = 1024;

    /** @var string ключ, по которому можно найти блок разделяемой памяти */
    private $_key = '';

    /**
     * @var array ошибки работы с классом. Массив нужен, потому что клиент может не сразу прочитать
     * ошибку, а выполнить сначала несколько операций с посредником.
     */
    private $_errors = [];

    /**
     * @var int идентификатор, который в дальнейшем будем использовать для доступа к зарезервированному
     * участку памяти.
     */
    private $_shmid;

    /**
     * Конструктор.
     *
     * Загружаем параметры конфигурации. Если передан ключ, тогда открываем блок разделяемой памяти
     * с заданными параметрами. Это "если" будет работать в случае запуска воркера.
     *
     * @param array $conf конфигурация объекта класса
     */
    public function __construct($conf)
    {
        foreach ($conf as $param => $value) {
            $param = '_'.$param;
            if (property_exists($this, $param)) {
                $this->$param = $value;
            }
        }

        if ($this->_key) {
            $this->_shmid = shmop_open($this->_key, 'w', 0777, $this->_size);
            if (!$this->_shmid) {
                $this->_errors[] = 'Не удалось открыть блок разделяемой памяти по указанному ключу '
                   . "'{$this->_key}'";
            }
        }
    }

    /**
     * Инициализация посредника по заданным настройкам.
     *
     * Этим должен заниматься директор (сервер). Воркеры (клиенты) будут подключаться к уже созданному
     * посреднику по ключу, переданному директором, и не должны вызывать этот метод.
     *
     * 3 раза (ХАРДКОД) пробуем создать блок памяти. В случае успеха вернем ключ к блоку памяти.
     * Иначе в массиве ошибок будет информация. Несколько попыток нужно, чтоб исключить состояние
     * гонки, когда одновременно могут инициализироваться несколько блоков памяти. Ключ генерируется
     * по значению microtime и гонка маловероятна, но все же.
     *
     * Про ограничение ключа читаем тут {@link http://php.net/manual/ru/function.shmop-open.php#48743}
     *
     * @return string | false ключ созданного посредника или FALSE в случае ошибок
     */
    public function init()
    {
        for ($try = 0; $try < 4; $try++) {
            $key = '0x' . substr(uniqid(), 5);
            if ($shmid = shmop_open($key, 'n', 0777, $this->_size)) {
                break;
            }
            $this->_errors[] = 'Ошибка при создании сегмента памяти. Попытка ' . $try . ' отменена';
            usleep(rand(1, 10000));
            $key = '';
        }

        if (!$key) {
            return false;
        }

        $this->_errors = [];
        $this->_key = $key;
        $this->_shmid = $shmid;

        return $key;
    }

    /**
     * Проверка наличия id для работы с блоком памяти.
     *
     * Прим.: id должен быть в результате работы init() для создания нового блока или при передаче
     * в конструктор ключа для подключения к уже созданному блоку.
     *
     * @return bool
     */
    private function _checkId()
    {
        if (!$r = $this->_shmid) {
            $this->_errors[] = 'Нет id блока разделяемой памяти.';
        }
        return (bool)$r;
    }

    /**
     * Запись данных.
     *
     * Каждая запись должна сопровождаться "поясняющим" сигналом, какие данные пишем.
     *
     * При записи свободная память добивается нулями во избежание получения "остаточных" данных. Это
     * несколько расточительная процедура, но так поддерживается единый интерфейс посредников.
     *
     * Возвращаем число байт, которые пытались записать (без учета нулей в конце) или FALSE в случае
     * отказа.
     *
     * @param int $signal управляющий сигнал
     * @param mixed $data данные для записи
     * @return int | false
     */
    public function write($signal, $data)
    {
        if (!$this->_checkId()) return;

        if (!is_int($signal) || $signal < 1 || $signal > 255) {
            $this->_errors[] = "Недопустимый код сигнала - '$signal'";
            return false;
        }

        $maxSize = $this->_size - 1;
        if ($data !== null) {
            $data = serialize($data);
            $len = $this->_countBytes($data);
            if ($len > $maxSize) {
                $this->_errors[] = "Недостаточно выделенной памяти ($maxSize байт) "
                    . "для записи данных ($len байт).";
                return false;
            }
        } else {
            $len = 0;
        }

        $zeros = $maxSize - $len;
        $data .= sprintf("%{$zeros}s", chr(32));

        if (shmop_write($this->_shmid, chr($signal) . $data, 0) === false) {
            $this->_errors[] = 'не удалось записать данные';
            return false;
        }
        return $len;
    }

    /**
     * Запись только данных.
     *
     * Просто данные для передачи, без конкретизации сигнала.
     * Каррирование. Управляющий сигнал подставится соответствующий.
     *
     * @param mixed $data данные для записи
     * @return int | false
     */
    public function writeData($data)
    {
        return $this->write(self::DATA, $data);
    }

    /**
     * Запись управляющего сигнала.
     *
     * Только сигнал, без передачи дополняющих данных.
     * @param int $signal управляющий сигнал
     * @return int | false
     */
    public function writeSignal($signal)
    {
        return $this->write($signal, null);
    }

    /**
     * Чтение данных и сигнала.
     *
     * Прим.: нулевой байт занят под управляющие сигналы. Можно запросить только его.
     *
     * @param int $len количество байт к прочтению. Если не задано, читаем все.
     * @return array | false массив ['signal' => (int), 'data' => (mixed)] или FALSE
     */
    public function read($len = null)
    {
        if (!$this->_checkId()) return;

        $max = shmop_size($this->_shmid) - 1;

        if (!$len) {
            $len = $max;
        }elseif ($len > $max) {
            $this->_errors[] = "Указанная длина ($len байт) превышает допустимый размер блока "
                . "разделяемой памяти ($max байт).";
            return false;
        }

        if (($raw = shmop_read($this->_shmid, 0, $len)) === false) {
            $this->_errors[] = 'Не удалось прочитать данные';
            return false;
        }

        $signal = ord($raw[0]);        //именно нулевой байт, не символ
        $raw = substr(trim($raw), 1);  //тут с первого байта, не символа
        $data = $raw ? unserialize($raw) : null;

        return compact('signal', 'data');
    }

    /**
     * Прочитать только данные. Сигнал не важен.
     *
     * @param int $len количество байт к прочтению
     * @return mixed | false
     */
    public function readData($len = null)
    {
        $raw = $this->read($len);
        return $raw === false ? false : $raw['data'];
    }

    /**
     * Прочитать только сигнал.
     *
     * @return int | false
     */
    public function readSignal()
    {
        return $this->read(1)['signal'];
    }

    /**
     * Получение ошибок класса. Сразу все, в одну строку.
     *
     * @param bool $erase обнулить ошибки после чтения
     * @return string
     */
    public function getErrors($erase = true)
    {
        if (!$this->_errors) {
            return '';
        }

        $err = implode('<br>', $this->_errors);
        if ($erase) {
            $this->_errors = [];
        }

        return $err;
    }

    /**
     * Считаем количество байт в строке.
     *
     * Вынес отдельно, чтоб четко обозначить проблему. Из-за перегрузки функций, strlen() уже
     * не работает, ее подменяет mb_strlen(). Это был странный баг, {@see http://waredom.ru/170}
     * @param string
     * @return int
     */
    private function _countBytes($str)
    {
        return mb_strlen($str, '8bit');
    }

    /**
     * Закрываем связь с посредником.
     *
     * Если был создан блок разделяемой памяти, освождаем его.
     */
    public function close()
    {
        if ($this->_shmid) {
            shmop_delete($this->_shmid);
            shmop_close($this->_shmid);
            $this->_shmid = null;
        }
    }

    /**
     * Деструктор.
     *
     * Если был создан блок разделяемой памяти, освождаем его.
     */
    public function __destruct()
    {
        $this->close();
    }
}