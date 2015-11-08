<?php
/**
 * Супер-класс всего приложения.
 *
 * Все наследования рекомендуется вести от него.
 */
namespace core;

class App
{
    /** @var array конфигурация приложения */
    private static $_config;

    private static $_signals;

    /**
     * Трассировка.
     *
     * Служебная функция, специально для отслеживания сигналов. Но можно и просто для вывода
     * отладки использовать :)
     *
     * Символ '`' нужен только для того, чтобы обозначить конечные числа для замены. Смысловой
     * нагрузки он не несет и можно использовать любое сочетание символов.
     *
     * Чтобы число превращалось в текст сигнала, ставь "!" перед числом.
     *
     * @param string $msg соообщение
     * @param bool $replaceSign искать значения констант сигналов?
     * @param bool $ts предворять сообщение значением времени?
     * @return void
     */
    public static function trace($msg = '', $replaceSign = true, $ts = false)
    {
        if (!self::$_signals) {
            // $constants = get_defined_constants(true); //не работает с интерфейсами
            $reflect = new \ReflectionClass('core\IDealer');
            $consts = $reflect->getConstants();
            array_walk($consts, function(&$i){ $i = "`$i`"; });
            self::$_signals = $consts;
        }

        if ($ts) {
            list($sec, $usec) = explode('.', microtime(true));
            printf('<b>%s.%04d</b> ', date('H:i:s', $sec), $usec);
        }

        if ($replaceSign && $msg) {
            $msg = preg_replace('~!(\d+)~', '`$1`', $msg);
            $msg = str_replace(self::$_signals, array_keys(self::$_signals), $msg);
        }

        echo $msg;
    }

    /**
     * Получаем параметр конфигурации.
     *
     * @param string $p параметр
     * @return mixed значение из конфига
     */
    public static function conf($p)
    {
        if (!self::$_config) {
            self::$_config = require ROOT_PATH . 'conf.php';
        }

        if (isset(self::$_config[$p])) {
            return self::$_config[$p];
        } else {
            throw new \RuntimeException('Не найден параметр конфига ' . $p);
        }
    }


    /**
     * Перехватчик исключений.
     *
     * Ловит исключения, которые не были пойманы ранее. Последний шанс обработать ошибку. Например,
     * записать в лог или намылить админу. Можно так же вежливо откланяться юзеру.
     *
     * После выполнения этого обработчика программа остановится, обеспечено PHP.
     *
     * @param Exception $ex
     */
    public static function exceptionHandler($ex)
    {
        $wrapper = "<html>\n<head>\n<meta charset='utf-8'>\n</head>\n\n<body>\n%s\n</body>\n\n</html>";
        if (DEBUG) {
            $err = '<h3>'.get_class($ex)."</h3>\n"
                 . sprintf("<p><i>%s</i></p>\n", $ex->getMessage())
                 . sprintf("<p>%s:%d</p>\n", str_replace(ROOT_PATH, '/', $ex->getFile()), $ex->getLine())
                 . '<pre>' . $ex->getTraceAsString() . '</pre>';
            printf($wrapper, $err);
        } else  {
            $err = "<h3>Упс! Произошла ошибка</h3>\n"
                 //. '<p><i>' . $ex->getMessage() . "</i></p>\n"
                 . '<p>Зайдите позже, пожалуйста.</p>';

            printf($wrapper, $err);
            //логирование ...
            //письмо/смс/звонок :) админу ...
        }
    }
}
