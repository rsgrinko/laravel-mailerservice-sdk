# Laravel Mailerservice SDK

Пакет для Laravel: отправляет почту через сервис рассылки по его HTTP API.
Работает и как обычный почтовый транспорт (`config/mail.php`), и как прямой
клиент API — для статусов, шаблонов и отправки по шаблону без Laravel Mail.

Требования: PHP 8.2+, Laravel 11, 12 или 13, Symfony Mailer 6.4+ (тянется Laravel'ом).

## Установка

```
composer require rsgrinko/laravel-mailerservice-sdk
```

Провайдер и алиас `MailService` подхватываются автоматически. Для публикации
конфига:

```
php artisan vendor:publish --tag=mailerservice-config
```

## Настройка

Переменные окружения (ключи совпадают с `config/mailerservice.php`):

```
MAILERSERVICE_URL=http://mail.internal
MAILERSERVICE_KEY=mlr_ваш_ключ
MAILERSERVICE_TIMEOUT=10
MAILERSERVICE_RETRIES=2
MAILERSERVICE_RETRY_DELAY=200
MAILERSERVICE_TAG=      # метка, по которой письма видны в панели
MAILERSERVICE_TRANSPORT= # транспорт сервиса, если не тот, что у проекта по умолчанию
MAILERSERVICE_SYNC=false
MAILERSERVICE_VERIFY=true
```

Ключ проекта выдаётся на стороне сервиса: `php bin/mailer key:create`.

## Проверка

```
php artisan mailerservice:test                      # только связь: настройки, сервис, воркер
php artisan mailerservice:test you@example.com      # плюс два проверочных письма
```

Команда идёт по шагам и показывает, на каком именно всё встало: настройки
(адрес, ключ, отправитель, метка), ответ `/health`, письмо через API синхронно,
письмо через почтовый транспорт Laravel и список последних писем проекта.

Отправка через транспорт отвечает до фактической доставки, поэтому исход письма
команда дожидается отдельно — опрашивает сервис по идентификатору, пока воркер
не отчитается. Отказ SMTP виден прямо в консоли, а не только в панели.

Письма уходят с тем же отправителем, с каким ходит вся почта приложения
(`MAIL_FROM_ADDRESS`) — проверять надо ровно его.

| Ключ | Зачем |
|------|-------|
| `--from=адрес` | другой отправитель, не трогая `.env` — проверить догадку про отказ транспорта |
| `--mailer=имя` | имя мейлера из `config/mail.php`, если он назван не `mailerservice` |
| `--wait=20` | сколько секунд ждать воркер; `0` — не дожидаться доставки |
| `--api` | не трогать Laravel Mail, проверить только API |

Типовые ответы команда объясняет сама: не тот ключ, недоступный адрес сервиса,
лимит проекта, незаявленный мейлер, отвергнутый транспортом отправитель.

### Почтовый транспорт

В `config/mail.php` добавить драйвер и переключить default:

```php
'mailerservice' => [
    'transport' => 'mailerservice',
],

'default' => env('MAIL_MAILER', 'mailerservice'),
```

Дальше почта шлётся как обычно:

```php
Mail::to($user->email)->send(new OrderShipped($order));
```

Письмо принимается сервисом в очередь, доставкой занимается его воркер —
запрос из приложения быстрый и не зависит от состояния почтового сервера.
Тема, отправитель, получатели, копии, тела и вложения из Symfony-письма
раскладываются автоматически; пользовательские заголовки (кроме служебных)
передаются как есть. Приоритет Symfony (1–5) ложится на приоритет очереди
сервиса, обычные письма уходят с 100. Метка и метаданные письма
(`Mailable::tag()`, `Mailable::metadata()`) ложатся в поля `tag` и `meta` —
метка у письма важнее той, что задана в настройках.

Картинки внутри HTML (`$message->embed(...)`, `<img src="cid:...">`) уходят
вложениями с тем же `cid`, на который ссылается разметка, — MIME собирает
сервис.

Если в настройках стоит `MAILERSERVICE_SYNC=true`, транспорт дожидается
фактической отправки: медленнее, зато ошибка доставки падает прямо в
`Mail::send()`. Ошибка сервиса приходит как
`Symfony\Component\Mailer\Exception\TransportException`, поэтому штатный
`failover` Laravel переключается на запасной мейлер.

Идентификатор письма в сервисе доступен приложению в событии `MessageSent`
(`$event->sent->getMessageId()`) — по нему письмо ищется в панели.

Настройки можно задать и на отдельный мейлер — так заводятся несколько
мейлеров с разными метками:

```php
'billing' => [
    'transport'         => 'mailerservice',  // драйвер пакета
    'tag'               => 'billing',
    'service_transport' => 'yandex',         // транспорт на стороне сервиса
    'sync'              => false,
],
```

### Отправитель

Laravel подставляет в каждое письмо `MAIL_FROM_ADDRESS`, и этот адрес должен
принадлежать аккаунту транспорта на стороне сервиса. Транспорт Яндекса шлёт
только со своих адресов и отвергает чужой `From` на этапе `MAIL FROM`:

```
553 5.7.1 Sender address rejected: user not found
```

Письмо при этом доходит до сервиса и честно ложится в очередь, а падает уже на
отправке — в панели у него статус `failed` с этой ошибкой. Лечится адресом:
поставьте в `MAIL_FROM_ADDRESS` почту, заведённую в аккаунте транспорта, и
выполните `php artisan config:clear`.

## Прямой клиент API

Клиент лежит в контейнере, наружу — фасад `MailService`:

```php
use Rsgrinko\MailServiceSdk\Message;

// письмо по шаблону сервиса
$result = MailService::send(
    Message::to($user->email)
        ->template('welcome', ['name' => $user->name])
        ->tag('регистрация')
);

// проверка статуса
$status = MailService::status($result['id']);

// всё остальное
MailService::messages(['status' => 'failed', 'per_page' => 50]);
MailService::retry($result['id']);
MailService::cancel($result['id']);
MailService::templates();
MailService::health();

// стоп-лист проекта
MailService::suppress('ivan@example.com', 'complaint', 'пожаловался на спам');
MailService::suppressions(['reason' => 'bounce']);
MailService::unsuppress('ivan@example.com');
```

Методы клиента:

| Метод | Что делает |
|-------|------------|
| `send($mail)` | ставит письмо в очередь |
| `sendNow($mail)` | отправляет сразу и ждёт результата |
| `status($id)` | состояние письма и его история |
| `messages($filters)` | список писем проекта |
| `retry($id)` | вернуть письмо в очередь |
| `cancel($id)` | отменить письмо |
| `templates()` | список шаблонов |
| `suppressions($filters)` | стоп-лист: кому проект больше не пишет |
| `suppress($email, $reason, $note)` | закрыть адрес |
| `unsuppress($email)` | открыть адрес обратно |
| `health()` | состояние сервиса |

Шаблоны и транспорты у ключа свои: видно то, что принадлежит владельцу проекта,
плюс общие транспорты. Чужого имени для проекта не существует.

Письмо `Message` собирается цепочкой (без Laravel Mail, напрямую в API):

```php
Message::to('user@example.com')
    ->from('noreply@example.com', 'Интернет-магазин')
    ->cc(['manager@example.com'])
    ->replyTo('support@example.com')
    ->subject('Заказ №1024 оформлен')
    ->html('<p>Спасибо за заказ!</p>')
    ->text('Спасибо за заказ!')
    ->attachFile(storage_path('app/order.pdf'))
    ->meta(['order_id' => 1024]);
```

Доступны также `text()`, `template($name, $data)`, `inlineImage($cid, $path)`,
`header()`, `transport($name)`, `priority($n)`, `sendAt($when)`,
`idempotencyKey($key)`, `sync()`.

## Обработка ошибок

Все методы бросают `Rsgrinko\MailServiceSdk\MailServiceException`:

```php
use Rsgrinko\MailServiceSdk\MailServiceException;

try {
    MailService::send(Message::to('user@example.com')->subject('Привет')->text('Тело'));
} catch (MailServiceException $e) {
    // $e->getMessage() — что не так
    // $e->getCode()    — код ответа сервиса (401, 422, 429 …)
    // $e->errors       — список ошибок валидации
    // $e->response     — полный ответ сервиса
}
```

Если сервис не ответил по сети, запрос повторяется (настройка `retries`,
пауза `retry_delay`), после чего бросается исключение с причиной. Ошибка
самого сервиса (неверный ключ, невалидное письмо, 502 при sync) не повторяется.