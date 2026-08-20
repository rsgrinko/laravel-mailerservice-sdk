<?php

declare(strict_types=1);

namespace Rsgrinko\MailServiceSdk\Transport;

use Psr\Log\LoggerInterface;
use Rsgrinko\MailServiceSdk\Client;
use Rsgrinko\MailServiceSdk\MailServiceException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Почтовый транспорт: письмо из Laravel Mail раскладывается на JSON
 * и уходит в сервис через его HTTP API. В очереди доставкой занимается воркер.
 */
class MailServiceTransport extends AbstractTransport
{
    /**
     * Приоритет Symfony (1 — самый важный) в приоритет очереди сервиса
     * (меньше число — выше приоритет, обычные письма идут с 100).
     */
    private const PRIORITY_MAP = [
        Email::PRIORITY_HIGHEST => 1,
        Email::PRIORITY_HIGH    => 2,
        Email::PRIORITY_NORMAL  => 100,
        Email::PRIORITY_LOW     => 150,
        Email::PRIORITY_LOWEST  => 200,
    ];

    /** Заголовки, которые сервис заполнит сам, — их не шлём. Имена в нижнем регистре */
    private const SKIP_HEADERS = [
        'to', 'cc', 'bcc', 'from', 'sender', 'reply-to', 'subject',
        'date', 'message-id', 'mime-version', 'content-type',
        'content-transfer-encoding', 'content-disposition', 'content-length',
        'return-path', 'dkim-signature',
    ];

    /** Метка письма: Mailable::tag() кладёт её в этот заголовок */
    private const TAG_HEADER = 'x-tag';

    /** Произвольные данные: Mailable::metadata() кладёт их в X-Metadata-<ключ> */
    private const META_PREFIX = 'x-metadata-';

    private Client $client;
    private ?string $tag;
    private ?string $transport;
    private bool $sync;

    public function __construct(
        Client $client,
        ?string $tag = null,
        ?string $transport = null,
        bool $sync = false,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, $logger);

        $this->client    = $client;
        $this->tag       = $tag;
        $this->transport = $transport;
        $this->sync      = $sync;
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (!$original instanceof Email) {
            $original = MessageConverter::toEmail($original);
        }

        $payload = $this->toPayload($original);

        // Метка из настроек запасная: та, что указана у самого письма, важнее
        if (!isset($payload['tag']) && $this->tag !== null) {
            $payload['tag'] = $this->tag;
        }

        if ($this->transport !== null) {
            $payload['transport'] = $this->transport;
        }

        try {
            $result = $this->sync ? $this->client->sendNow($payload) : $this->client->send($payload);
        } catch (MailServiceException $e) {
            // Symfony и Laravel ждут от транспорта именно TransportExceptionInterface:
            // иначе failover и round-robin не переключатся на следующий транспорт
            throw new TransportException($e->getMessage(), $e->getCode(), $e);
        }

        // Идентификатор письма в сервисе: по нему письмо ищется в панели,
        // приложению он приходит в событии MessageSent
        if (isset($result['id']) && is_string($result['id'])) {
            $message->setMessageId($result['id']);
        }
    }

    public function __toString(): string
    {
        return 'mailerservice';
    }

    /**
     * Письмо из Symfony в формат API сервиса.
     *
     * @return array<string, mixed>
     */
    private function toPayload(Email $email): array
    {
        $payload = ['subject' => (string) $email->getSubject()];

        $from = $email->getFrom();
        if ($from !== []) {
            $payload['from'] = $this->address($from[0]);
        }

        if ($email->getTo() !== []) {
            $payload['to'] = array_map([$this, 'address'], $email->getTo());
        }

        if ($email->getCc() !== []) {
            $payload['cc'] = array_map([$this, 'address'], $email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = array_map([$this, 'address'], $email->getBcc());
        }

        $replyTo = $email->getReplyTo();
        if ($replyTo !== []) {
            $payload['reply_to'] = $this->address($replyTo[0]);
        }

        $text = $this->asString($email->getTextBody());
        if ($text !== '') {
            $payload['text'] = $text;
        }

        $html = $this->asString($email->getHtmlBody());
        if ($html !== '') {
            $payload['html'] = $html;
        }

        foreach ($email->getAttachments() as $part) {
            $payload['attachments'][] = $this->attachment($part);
        }

        $priority = self::PRIORITY_MAP[$email->getPriority()] ?? 100;
        if ($priority !== 100) {
            $payload['priority'] = $priority;
        }

        return array_merge($payload, $this->headers($email));
    }

    /**
     * Адрес: строкой, если без имени, иначе массивом для API.
     */
    private function address(Address $address): array|string
    {
        return $address->getName() === ''
            ? $address->getAddress()
            : ['email' => $address->getAddress(), 'name' => $address->getName()];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(DataPart $part): array
    {
        $item = [
            'name'         => $part->getFilename() ?? 'attachment',
            'content'      => base64_encode($part->getBody()),
            'content_type' => $part->getContentType(),
        ];

        if ($part->getDisposition() !== 'inline') {
            return $item;
        }

        $item['inline'] = true;

        // В HTML картинка помечена как cid:<имя>, а на настоящий Content-ID это имя
        // меняет Symfony при сборке письма. Письмо собирает сервис, поэтому cid берём
        // тот, на который ссылается HTML: имя части, если Content-ID не задан явно.
        $item['cid'] = $part->hasContentId()
            ? $part->getContentId()
            : ($part->getName() ?? $item['name']);

        return $item;
    }

    /**
     * Заголовки письма: служебные отбрасываем, метку и метаданные Laravel
     * (Mailable::tag() и Mailable::metadata()) переносим в поля API.
     *
     * @return array<string, mixed>
     */
    private function headers(Email $email): array
    {
        $result  = [];
        $headers = [];
        $meta    = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name  = $header->getName();
            $lower = strtolower($name);

            if (in_array($lower, self::SKIP_HEADERS, true) || str_starts_with($lower, 'x-symfony')) {
                continue;
            }

            // getBodyAsString() отдаёт значение уже закодированным (=?utf-8?Q?…?=),
            // а кодировать заголовки — дело сервиса, ему нужен исходный текст
            $value = $header instanceof UnstructuredHeader ? $header->getBody() : $header->getBodyAsString();
            if ($value === '') {
                continue;
            }

            if ($lower === self::TAG_HEADER) {
                $result['tag'] = $value;
                continue;
            }

            if (str_starts_with($lower, self::META_PREFIX)) {
                $meta[substr($name, strlen(self::META_PREFIX))] = $value;
                continue;
            }

            $headers[$name] = $value;
        }

        if ($headers !== []) {
            $result['headers'] = $headers;
        }

        if ($meta !== []) {
            $result['meta'] = $meta;
        }

        return $result;
    }

    /**
     * Тело письма Symfony держит строкой или потоком.
     */
    private function asString(mixed $body): string
    {
        if ($body === null) {
            return '';
        }

        if (is_string($body)) {
            return $body;
        }

        if (!is_resource($body)) {
            return '';
        }

        if (stream_get_meta_data($body)['seekable']) {
            rewind($body);
        }

        return (string) stream_get_contents($body);
    }
}
