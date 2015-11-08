# Подпроцессы на PHP
## Задача

Написать класс (семейство классов), позволяющий реализовывать параллельные вычисления на РНР.

Объяснение сути задачи: с помощью данного класса или системы классов становится возможным запускать php скрипты или их части в отдельных процессах. Причём взаимодествие между процессами осуществляется также посредством разработанных классов. При этом под процессом понимается каждый запущеный экземпляр php скрипта.

Обязательные требования:
- Кроссплатформа. Скрипт должен функционировать как в Windows так и в Unix системах.
- Должна быть возможность распараллеливать не только скрипт целиком, но и конкретную функцию или метод класса.
- Скрипт запускающий параллельные процессы должен иметь возможность общаться с ними в обоих направлениях. А именно: отправлять данные в дочерние процессы в любое время, получать данные с любого из дочерних процессов в любое время, определять статус процесса (работает, не работает). Дочерние скрипты также могут отправлять данные в порождающий процесс в произвольное время.

##### Тестовый пример
Вычисление числа pi с заданной точностью методом Монте-Карло.

Порождающий скрипт `index.php` запускает несколько дочерних процессов (количество процессов должно быть параметром конфига), каждый из которых начинает вычислять число pi методом Монте-Карло. При запуске параллельного процесса ему передается количество итераций вычислений, определяемое случайным образом. Порождающий скрипт через неравные промежутки времени (определяемые так же случайно) собирает информацию со всех параллельных процессов и выдает совокупный результат (значение числа pi) в браузер с указанием времени, прошедшего с момента запуска главного скрипта.

## Реализация системы

Из-за требования кроссплатформы отброшены многие варианты реализации. В итоге использована функция [popen()](http://php.net/manual/en/function.popen.php) для запуска подпроцесса в фоне и *Shared Memory* для межпроцессного взаимодействия. Семафоры и мьютексы не задействованы, т.к. согласно манула PHP, они не работают в Windows.

Мыслите абстрактно :) В решении применены следующие понятия:

- **boss** - директор. Управляющий процесс. Запуск подпроцессов через него. А так же: синхронизация выборочных/всех, останов выборочных/всех, запрос статуса выборочно/всех. Разница между статусом и синхронизацией: статус просто сообщит, чем занят подпроцесс, на какой он стадии выполнения. Для синхронизации приостанавливаем подпроцесс(ы) до дальшейших распоряжений.
- **worker** - воркер. Подпроцесс. Вот их и будем плодить. Воркером может быть метод класса или функция вне класса.
- **dealer** -посредник. Любой способ обмена информацией между процессами. Задача посредника - только передавать информацию (строки) между директором и воркерами. Содержание информации его не касается.
Сделана реализация через *Shared Memory*. Так же можно создать класс-посредник на основе Redis (или другого кешера), сокетов, именованных каналов, сессий PHP или записи в файлы без оберток (ооочень медленно будет).

Под управлением **одного директора** можно запустить воркеров с разными действиями, а так вместе воркеры-методы класса и внеклассовые функции. В приложении можно создать несколько директорских классов со своими настройками под конкретные задачи.

##### Директор - сигнал, воркер - статус.
Сингалы и статусы перечислены в интерфейсе `core\IDealer`.

Для каждого подпроцесса создается свой канал обмена сообщениями (посредник). Воркеры не могут общаться друг с другом, только с директором.  Директор мониторит все каналы через заданные промежутки времени, воркер проверяет только свой канал так же через промежутки времени.

Стабильная работа основана на строгом порядке обмена сообщениями. Директор пишет управляющие сигналы в посредник, воркер получая сигнал, отвечает статусом. Только так. Нарушение этого принципа приведет ко всем "прелестям" параллельной работы: гонка, взаимная блокировка, уход от родительского контроля и т.д.

Для воркера есть метод проверки сообщений, для директора - метод управления сообщениями. Оба метода характеризуются двумя параметрами: общее время проверки в секундах (таймаут) и частота обращений к посреднику в течение проверки (NN сообщений в секунду).

##### Критические ситуации

Решение проходит синтетические тесты, но проблемы будут, когда потребуется написать что-то сложнее "*hello, world!*".

**Проблема №1.** Директор может получить данные от воркера сигналом STATUS. Может передать данные с сигналом DATA. Это нормальный запланированный обмен данными. А вот другая ситуация: воркер **по своей инициативе** передает данные директору. Возможен конфликт.

Сейчас решение такое: при одновременной попытке директора и воркера передать данные считаем данные воркера важнее. Воркер будет игнорировать любые сигналы (скроме STOP и LISTEN), пока его данные не будут приняты. После успешной передачи своих данных воркер переходит в состояние "в ожидании" до получения указаний директора. Правильные реакции на таймауты проверок сообщений зависят от конкретной задачи и ее реализации.

**Проблема №2.** Допустим, директор запрашивает статус, а воркер проверяет сообщения. При одновременном чтении одного сегмента разделяемой памяти (Shared memory) воркер "глохнет" и не видит сигнала STATUS. На что проверка директора заканчивается по таймауту и объявляет воркера потерянным (STATUS\_LOST). Клиентский код директора должен избавляться от таких воркеров, это уход из-под контроля.

Причину этого бага я не нашел. Функции, работающие с Shared Memory, в этом случае ничего не возвращают. Чтение из памяти просто зависает, пока не получится что-то прочитать. Этого зависание длится, пока директор не закончит мониторить посредника и не выйдет с таймаутом. Обходное решение: добавил случайне число в частоту проверки в методе директора. Это не исключает описанную ситуацию, но делает вероятность меньше. Как вариант, придется сменить посредника, т.е. написать его реализацию, не испольуя Shared Memory (не забываем про кроссплатформу).
