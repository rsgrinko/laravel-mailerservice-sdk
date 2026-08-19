<?php

declare(strict_types=1);

return [
    // Адрес сервиса, без /api/v1
    'url' => env('MAILERSERVICE_URL', 'http://127.0.0.1:8080'),

    // Ключ проекта: php bin/mailer key:create на стороне сервиса
    'key' => env('MAILERSERVICE_KEY', ''),

    // Сколько ждать ответа сервиса, секунды
    'timeout' => (int) env('MAILERSERVICE_TIMEOUT', 10),

    // Сколько раз повторить запрос, если сервис не ответил (0 — не повторять)
    'retries' => (int) env('MAILERSERVICE_RETRIES', 2),

    // Пауза между повторами, миллисекунды
    'retry_delay' => (int) env('MAILERSERVICE_RETRY_DELAY', 200),

    // Метка, с которой уходят письма приложения: по ней их видно в панели
    'tag' => env('MAILERSERVICE_TAG'),

    // Транспорт сервиса, если нужен не тот, что у проекта по умолчанию
    'transport' => env('MAILERSERVICE_TRANSPORT'),

    // Ждать ли отправки вместо постановки в очередь. Медленнее, зато сразу видно ошибку
    'sync' => (bool) env('MAILERSERVICE_SYNC', false),

    // Проверять ли сертификат при работе по https
    'verify' => (bool) env('MAILERSERVICE_VERIFY', true),
];