<?php

declare(strict_types=1);

namespace Rsgrinko\MailService;

use RuntimeException;

/**
 * Ошибка при обращении к сервису: сеть не ответила либо API вернуло ошибку.
 */
class MailServiceException extends RuntimeException
{
    /** @var array<int, string> Ошибки валидации, если сервис их прислал */
    public array $errors = [];

    /** @var array<string, mixed> Полный ответ сервиса */
    public array $response = [];

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $response
     */
    public function __construct(string $message, int $code = 0, array $errors = [], array $response = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->errors   = $errors;
        $this->response = $response;
    }
}
