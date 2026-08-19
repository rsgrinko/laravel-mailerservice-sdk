# Laravel Mailerservice SDK

Пакет для Laravel: отправляет почту через сервис рассылки по его HTTP API.
Работает и как обычный почтовый транспорт (`config/mail.php`), и как прямой
клиент API — для статусов, шаблонов и отправки по шаблону без Laravel Mail.

Требования: PHP 8.3+, Laravel 13, Symfony Mailer 7 (тянется Laravel'ом).

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
сервиса, обычные письма уходят с 100.

Если в настройках стоит `MAILERSERVICE_SYNC=true`, транспорт дожидается
фактической отправки: медленнее, зато ошибка доставки падает прямо в
`Mail::send()`.

## Прямой клиент API

Клиент лежит в контейнере, наружу — фасад `MailService`:

```php
use Rsgrinko\MailService\Message;

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
| `health()` | состояние сервиса |

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

Все методы бросают `Rsgrinko\MailService\MailServiceException`:

```php
use Rsgrinko\MailService\MailServiceException;

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