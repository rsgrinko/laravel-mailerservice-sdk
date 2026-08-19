<?php

declare(strict_types=1);

namespace Rsgrinko\MailService\Transport;

use Psr\Log\LoggerInterface;
use Rsgrinko\MailService\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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

    /** Заголовки, которые сервис заполнит сам, — их не шлём */
    private const SKIP_HEADERS = [
        'To', 'Cc', 'Bcc', 'From', 'Reply-To', 'Subject',
        'Date', 'Message-ID', 'MIME-Version', 'Content-Type',
        'Content-Transfer-Encoding', 'Content-Disposition', 'Return-Path',
    ];

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

        if ($this->tag !== null && $this->tag !== '') {
            $payload['tag'] = $this->tag;
        }

        if ($this->transport !== null && $this->transport !== '') {
            $payload['transport'] = $this->transport;
        }

        if ($this->sync) {
            $this->client->sendNow($payload);

            return;
        }

        $this->client->send($payload);
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

        $headers = $this->customHeaders($email);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $priority = self::PRIORITY_MAP[$email->getPriority()] ?? 100;
        if ($priority !== 100) {
            $payload['priority'] = $priority;
        }

        return $payload;
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
            'name'    => $part->getFilename() ?? 'attachment',
            'content' => base64_encode($part->getBody()),
        ];

        if ($part->getContentType() !== 'application/octet-stream') {
            $item['content_type'] = $part->getContentType();
        }

        if ($part->getDisposition() === 'inline') {
            $item['inline'] = true;
        }

        if ($part->hasContentId()) {
            $item['cid'] = $part->getContentId();
        }

        return $item;
    }

    /**
     * Пользовательские заголовки письма (X-*, кастомные) для передачи в сервис.
     *
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();

            if (in_array($name, self::SKIP_HEADERS, true) || str_starts_with($name, 'X-Symfony')) {
                continue;
            }

            $value = $header->getBodyAsString();
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function asString(mixed $body): string
    {
        if ($body === null) {
            return '';
        }

        return is_string($body) ? $body : (string) stream_get_contents($body);
    }
}