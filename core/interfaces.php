<?php
/**
 * Все интерфейсы в одном скрипте. Явное подключение скрипта, без автозагрузчика классов. Так проще.
 * Конкретно в этой задаче всего один интерфейс.
 */

namespace core;

/**
 * Посредник.
 *
 * Любой подходящий способ для обмена данными между процессами. Есть реализация через SharedMemory.
 * Можно так же использовать Redis или другой кешер, именованные каналы (pipes), сокеты. Не рекомендую
 * использовать файлы - это ооочень медленно.
 */
interface IDealer
{
    /** Сигналы и статусы. Только целые числа в промежутке [1, 255] */

    /** Управляющие сигналы */
    const SUSPEND = 2;  // приостановить
    const RESUME  = 3;  // продолжить работу
    const STOP    = 4;  // завершиться
    const STATUS  = 5;  // запрос статуса. Можно просить текущие данные у воркера
    const DATA    = 6;  // передача данных от директора воркеру
    const LISTEN  = 7;  // директор в режиме приема данных по требованию воркера

    /** Статусы воркера */
    const STATUS_WAITING = 102; // не работаю, ожидаю команды
    const STATUS_WORKING = 103; // работаю
    const STATUS_STOPED  = 104; // завершился немедленно.
    const STATUS_FINISH  = 105; // закончил выполнение. В посреднике - текущее состояние передаваемых данных.
    const STATUS_LOST    = 106; // воркер не ответил в течении ожидаемого времени, т.е. потерялся.
    const STATUS_CALLING = 107; // вызываю директора

    /** Статусы директора */
    const STATUS_LISTEN  = 108; // ожидаю приема данных (директор ждет по запросу воркера)


    /**
     * Инициализация посредника по заданным настройкам.
     *
     * Этим должен заниматься директор (сервер). Воркеры (клиенты) будут подключаться к уже созданному
     * посреднику по ключу, переданному директором, и не должны вызывать этот метод.
     *
     * Ошибки пишем в private-свойство класса, выдаем отдельным геттером.
     *
     * @return string | false ключ созданного посредника или FALSE в случае ошибок
     */
    public function init();

    /**
     * Получение ошибок класса. Сразу все, в одну строку.
     *
     * @param bool $erase обнулить ошибки после чтения
     * @return string
     */
    public function getErrors($erase = true);

    /**
     * Закрываем связь с посредником.
     */
    public function close();

    /**
     * Запись данных.
     *
     * Каждая запись должна сопровождаться "поясняющим" сигналом, какие данные пишем.
     *
     * Возвращаем число байт, которые пытались записать или FALSE в случае ошибки.
     *
     * @param int $signal управляющий сигнал
     * @param mixed $data данные для записи
     * @return int | false
     */
    public function write($signal, $data);

    /**
     * Запись только данных.
     *
     * Просто данные для передачи, без конкретизации сигнала.
     * Каррирование. В реализации метода должен быть заложен вызов IDealer::write(), управляющий сигнал
     * должен быть Idealer::DATA.
     *
     * @param mixed $data данные для записи
     * @return int | false
     */
    public function writeData($data);

    /**
     * Запись управляющего сигнала.
     *
     * Только сигнал, без передачи дополняющих данных.
     * @param int $signal управляющий сигнал
     * @return int | false
     */
    public function writeSignal($signal);

    /**
     * Чтение данных и сигнала.
     *
     * @param int $len количество байт к прочтению. Если не задано, читаем все.
     * @return array | false массив ['signal' => (int), 'data' => (mixed)] или FALSE
     */
    public function read($len = null);

    /**
     * Прочитать только данные.
     *
     * @param int $len количество байт к прочтению
     * @return mixed | false
     */
    public function readData($len = null);

    /**
     * Прочитать только сигнал.
     *
     * @return int | false
     */
    public function readSignal();
}