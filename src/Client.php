<?php

declare(strict_types=1);

namespace Rsgrinko\MailServiceSdk;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-клиент API сервиса. Каждый метод — один запрос; ошибки сервиса
 * прилетают как MailServiceException, недоступность сети лечится повторами.
 */
class Client
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private int $retries;
    private int $retryDelay;
    private bool $verify;
    private Factory $http;

    /**
     * @param array<string, mixed> $options Ключи: timeout, retries, retry_delay, verify
     */
    public function __construct(string $baseUrl, string $apiKey, array $options = [], ?Factory $http = null)
    {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->apiKey     = $apiKey;
        $this->timeout    = (int) ($options['timeout'] ?? 10);
        $this->retries    = max(0, (int) ($options['retries'] ?? 2));
        $this->retryDelay = (int) ($options['retry_delay'] ?? 200);
        $this->verify     = (bool) ($options['verify'] ?? true);
        $this->http       = $http ?? (Http::getFacadeRoot() ?? new Factory());
    }

    /**
     * Отправляет письмо: ставит в очередь сервиса и отвечает сразу.
     *
     * @param Message|array<string, mixed> $mail
     * @return array<string, mixed> ответ сервиса: id, status и прочее
     */
    public function send(Message|array $mail): array
    {
        $payload = $mail instanceof Message ? $mail->toArray() : $mail;

        return $this->sendJson('POST', '/api/v1/messages', $payload);
    }

    /**
     * Отправляет письмо и ждёт результата отправки (sync-режим).
     *
     * @param Message|array<string, mixed> $mail
     * @return array<string, mixed>
     */
    public function sendNow(Message|array $mail): array
    {
        $payload         = $mail instanceof Message ? $mail->toArray() : $mail;
        $payload['sync'] = true;

        return $this->sendJson('POST', '/api/v1/messages', $payload);
    }

    /**
     * Состояние письма по его идентификатору.
     *
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        return $this->sendJson('GET', '/api/v1/messages/' . rawurlencode($id));
    }

    /**
     * Список писем проекта.
     *
     * @param array<string, string|int> $filters status, tag, search, page, per_page
     * @return array<string, mixed>
     */
    public function messages(array $filters = []): array
    {
        return $this->sendJson('GET', '/api/v1/messages', $filters);
    }

    /**
     * Повторить неудачное письмо.
     *
     * @return array<string, mixed>
     */
    public function retry(string $id): array
    {
        return $this->sendJson('POST', '/api/v1/messages/' . rawurlencode($id) . '/retry');
    }

    /**
     * Отменить письмо, пока оно в очереди.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->sendJson('DELETE', '/api/v1/messages/' . rawurlencode($id));
    }

    /**
     * Доступные шаблоны писем.
     *
     * @return array<string, mixed>
     */
    public function templates(): array
    {
        return $this->sendJson('GET', '/api/v1/templates');
    }

    /**
     * Состояние сервиса.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->sendJson('GET', '/api/v1/health');
    }

    /**
     * Запрос с повторами: если сервис не ответил (обрыв сети), пробуем ещё раз.
     * Ответ с ошибкой приложения не повторяется — повторять 401 или 422 бессмысленно.
     *
     * Свой цикл, а не retry() из Http-клиента: тот повторяет и на неуспешный ответ.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function sendJson(string $method, string $path, ?array $payload = null): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->execute($method, $path, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->retries) {
                    throw new MailServiceException('Сервис недоступен: ' . $e->getMessage(), 0, [], [], $e);
                }

                $attempt++;

                if ($this->retryDelay > 0) {
                    usleep($this->retryDelay * 1000);
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|null $payload для GET — параметры строки запроса
     * @return array<string, mixed>
     */
    private function execute(string $method, string $path, ?array $payload): array
    {
        $request = $this->pending();

        $response = match ($method) {
            'GET'    => $payload === null || $payload === [] ? $request->get($path) : $request->get($path, $payload),
            'POST'   => $payload === null ? $request->post($path) : $request->post($path, $payload),
            'DELETE' => $request->delete($path),
            default  => throw new MailServiceException('Неподдерживаемый метод: ' . $method),
        };

        return $this->handle($response);
    }

    private function pending(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($this->apiKey)
            ->timeout($this->timeout);

        if (!$this->verify) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    /**
     * Разбирает ответ и превращает ошибку сервиса в исключение.
     *
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        $status  = $response->status();
        $decoded = $response->json();

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        $decoded = is_array($decoded) ? $decoded : [];

        // 502 при sync — это не ошибка формата: письмо принято, но отправить не вышло,
        // причина лежит в info, а блока error в таком ответе нет
        $message = (string) ($decoded['error']['message']
            ?? $decoded['info']
            ?? 'Сервис ответил кодом ' . $status);
        $errors = (array) ($decoded['error']['details']['errors'] ?? []);

        throw new MailServiceException($message, $status, $errors, $decoded);
    }
}