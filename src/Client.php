<?php

declare(strict_types=1);

namespace Rsgrinko\MailService;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

use function retry;

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
        $query = $filters === [] ? '' : '?' . http_build_query($filters);

        return $this->sendJson('GET', '/api/v1/messages' . $query);
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
     * Ответ с ошибкой приложения не повторяется.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function sendJson(string $method, string $path, ?array $payload = null): array
    {
        try {
            return retry(
                $this->retries + 1,
                fn (): array => $this->execute($method, $path, $payload),
                $this->retryDelay,
                static fn (Throwable $e): bool => $e instanceof ConnectionException,
            );
        } catch (ConnectionException $e) {
            throw new MailServiceException('Сервис недоступен: ' . $e->getMessage(), 0, [], [], $e);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function execute(string $method, string $path, ?array $payload): array
    {
        $request = $this->pending();

        $response = match ($method) {
            'GET'    => $request->get($path),
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
        $message = (string) ($decoded['error']['message'] ?? 'Сервис ответил кодом ' . $status);
        $errors  = (array) ($decoded['error']['details']['errors'] ?? []);

        throw new MailServiceException($message, $status, $errors, $decoded);
    }
}