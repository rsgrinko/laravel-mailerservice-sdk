<?php

declare(strict_types=1);

namespace Rsgrinko\MailServiceSdk;

/**
 * Письмо для прямой отправки через клиент, без Laravel Mail.
 * Собирается по цепочке: Message::to('user@example.com')->subject('Привет')->html('<p>Текст</p>').
 */
class Message
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * Начало письма: один или несколько получателей.
     *
     * @param string|array<int, string> $to
     */
    public static function to(string|array $to): self
    {
        $mail             = new self();
        $mail->data['to'] = $to;

        return $mail;
    }

    /**
     * Отправитель. Имя необязательно.
     */
    public function from(string $email, string $name = ''): self
    {
        $this->data['from'] = $name === '' ? $email : ['email' => $email, 'name' => $name];

        return $this;
    }

    /**
     * @param string|array<int, string> $cc
     */
    public function cc(string|array $cc): self
    {
        $this->data['cc'] = $cc;

        return $this;
    }

    /**
     * @param string|array<int, string> $bcc
     */
    public function bcc(string|array $bcc): self
    {
        $this->data['bcc'] = $bcc;

        return $this;
    }

    public function replyTo(string $email): self
    {
        $this->data['reply_to'] = $email;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->data['subject'] = $subject;

        return $this;
    }

    public function text(string $text): self
    {
        $this->data['text'] = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->data['html'] = $html;

        return $this;
    }

    /**
     * Письмо по шаблону из сервиса.
     *
     * @param array<string, mixed> $data
     */
    public function template(string $name, array $data = []): self
    {
        $this->data['template']      = $name;
        $this->data['template_data'] = $data;

        return $this;
    }

    /**
     * Вложение из данных в памяти.
     */
    public function attach(string $fileName, string $content, string $contentType = ''): self
    {
        $attachment = [
            'name'    => $fileName,
            'content' => base64_encode($content),
        ];

        if ($contentType !== '') {
            $attachment['content_type'] = $contentType;
        }

        $this->data['attachments'][] = $attachment;

        return $this;
    }

    /**
     * Вложение из файла на диске.
     */
    public function attachFile(string $path, string $fileName = ''): self
    {
        if (!is_file($path)) {
            throw new MailServiceException('Файл вложения не найден: ' . $path);
        }

        return $this->attach($fileName !== '' ? $fileName : basename($path), (string) file_get_contents($path));
    }

    /**
     * Картинка внутри HTML. В теле письма ссылаться так: <img src="cid:идентификатор">
     */
    public function inlineImage(string $cid, string $path, string $fileName = ''): self
    {
        if (!is_file($path)) {
            throw new MailServiceException('Файл картинки не найден: ' . $path);
        }

        $this->data['attachments'][] = [
            'name'    => $fileName !== '' ? $fileName : basename($path),
            'content' => base64_encode((string) file_get_contents($path)),
            'inline'  => true,
            'cid'     => $cid,
        ];

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->data['headers'][$name] = $value;

        return $this;
    }

    /**
     * Метка — по ней потом удобно искать письма в панели.
     */
    public function tag(string $tag): self
    {
        $this->data['tag'] = $tag;

        return $this;
    }

    /**
     * Произвольные данные, которые вернутся в вебхуке.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): self
    {
        $this->data['meta'] = $meta;

        return $this;
    }

    /**
     * Отправить конкретным транспортом (имя из панели).
     */
    public function transport(string $name): self
    {
        $this->data['transport'] = $name;

        return $this;
    }

    /**
     * Меньше число — выше приоритет в очереди. По умолчанию 100.
     */
    public function priority(int $priority): self
    {
        $this->data['priority'] = $priority;

        return $this;
    }

    /**
     * Отложенная отправка: '2026-01-01 10:00:00' или '+2 hours'.
     */
    public function sendAt(string $when): self
    {
        $this->data['send_at'] = $when;

        return $this;
    }

    /**
     * Защита от повторной отправки при ретраях на стороне приложения.
     */
    public function idempotencyKey(string $key): self
    {
        $this->data['idempotency_key'] = $key;

        return $this;
    }

    /**
     * Дождаться отправки вместо постановки в очередь.
     */
    public function sync(bool $sync = true): self
    {
        $this->data['sync'] = $sync;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}