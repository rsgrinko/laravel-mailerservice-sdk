<?php

declare(strict_types=1);

namespace Rsgrinko\MailServiceSdk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Rsgrinko\MailServiceSdk\Client;
use Rsgrinko\MailServiceSdk\MailServiceException;
use Rsgrinko\MailServiceSdk\Message;
use Throwable;

/**
 * Проверка связи с сервисом рассылки: настройки, доступность, приём письма
 * через API и через почтовый транспорт Laravel — по шагам, чтобы было видно,
 * на каком именно всё встало.
 */
class TestCommand extends Command
{
    protected $signature = 'mailerservice:test
        {email? : кому слать проверочные письма; без адреса — только проверка связи}
        {--from= : отправитель проверочных писем вместо MAIL_FROM_ADDRESS}
        {--mailer=mailerservice : имя мейлера из config/mail.php}
        {--api : не трогать Laravel Mail, проверить только API}';

    protected $description = 'Проверка сервиса рассылки и отправка тестового письма';

    public function handle(Client $client): int
    {
        $this->settings();

        if (!$this->health($client)) {
            return self::FAILURE;
        }

        $email = (string) ($this->argument('email') ?? '');

        if ($email === '') {
            $this->newLine();
            $this->info('Связь есть. Укажите адрес, чтобы отправить проверочные письма.');

            return self::SUCCESS;
        }

        $ok = $this->viaApi($client, $email);

        if (!$this->option('api')) {
            $ok = $this->viaTransport($email) && $ok;
        }

        $this->recent($client);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * С чем работаем: адрес, ключ, метка. Половина проблем видна уже здесь.
     */
    private function settings(): void
    {
        $config = (array) config('mailerservice', []);
        $key    = (string) ($config['key'] ?? '');
        $url    = (string) ($config['url'] ?? '');

        $this->line('<comment>Настройки</comment>');
        $this->table([], [
            ['Адрес сервиса', $url !== '' ? $url : '<error>не задан</error>'],
            ['Ключ', $key !== '' ? substr($key, 0, 8) . '…' : '<error>не задан</error>'],
            ['Метка писем', ((string) ($config['tag'] ?? '')) ?: '—'],
            ['Транспорт сервиса', ((string) ($config['transport'] ?? '')) ?: 'по умолчанию у проекта'],
            ['Ждать отправки (sync)', ($config['sync'] ?? false) ? 'да' : 'нет'],
            ['Проверять сертификат', ($config['verify'] ?? true) ? 'да' : 'нет'],
        ]);

        if ($url === '' || $key === '') {
            $this->line('  Заполните MAILERSERVICE_URL и MAILERSERVICE_KEY в .env, потом php artisan config:clear');
        }
    }

    /**
     * Сервис на связи, ключ подходит, воркер жив.
     */
    private function health(Client $client): bool
    {
        try {
            $health = $client->health();
        } catch (MailServiceException $e) {
            $this->error('Сервис недоступен: ' . $e->getMessage());
            $this->hint($e);

            return false;
        }

        $queue  = (array) ($health['checks']['queue'] ?? []);
        $worker = (array) ($health['checks']['worker'] ?? []);

        $this->info(sprintf(
            'Сервис отвечает: %s, в очереди %s, неудачных %s, воркер %s',
            (string) ($health['status'] ?? '?'),
            (string) ($queue['ready'] ?? '?'),
            (string) ($queue['failed'] ?? '?'),
            ($worker['ok'] ?? false) ? 'жив' : 'молчит'
        ));

        return true;
    }

    /**
     * Письмо напрямую через API и сразу (sync): ошибка отправки видна здесь же,
     * а не через минуту в панели.
     */
    private function viaApi(Client $client, string $email): bool
    {
        $this->newLine();
        $this->line('<comment>Через API, синхронно — ждём фактической отправки</comment>');

        $mail = Message::to($email)
            ->subject('Проверка сервиса рассылки')
            ->text('Письмо отправлено командой mailerservice:test через HTTP API сервиса.')
            ->html('<p>Письмо отправлено командой <code>mailerservice:test</code> через HTTP API сервиса.</p>');

        $tag = (string) config('mailerservice.tag', '');
        if ($tag !== '') {
            $mail->tag($tag);
        }

        $from = $this->from();
        if ($from !== '') {
            $mail->from($from);
        }

        try {
            $result = $client->sendNow($mail);
        } catch (MailServiceException $e) {
            $this->error('Не вышло: ' . $e->getMessage());

            foreach ($e->errors as $error) {
                $this->line('  - ' . $error);
            }

            $this->hint($e);

            return false;
        }

        $this->info('Отправлено: ' . (string) ($result['id'] ?? '?') . ', статус ' . (string) ($result['status'] ?? '?'));

        return true;
    }

    /**
     * Тот же путь, каким ходит вся почта приложения: Laravel Mail -> транспорт -> API.
     */
    private function viaTransport(string $email): bool
    {
        $name = (string) $this->option('mailer');
        $from = $this->from();

        $this->newLine();
        $this->line('<comment>Через почтовый транспорт Laravel, мейлер ' . $name . '</comment>');

        try {
            Mail::mailer($name)->raw(
                'Письмо отправлено командой mailerservice:test через почтовый транспорт Laravel.',
                static function ($message) use ($email, $from): void {
                    $message->to($email)->subject('Проверка транспорта mailerservice');

                    if ($from !== '') {
                        $message->from($from);
                    }
                }
            );
        } catch (Throwable $e) {
            $this->error('Не вышло: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'is not defined')) {
                $this->line('  Мейлер не объявлен. В config/mail.php, в массив mailers:');
                $this->line("      '" . $name . "' => ['transport' => 'mailerservice'],");
            }

            if (str_contains($e->getMessage(), 'Sender address rejected')) {
                $this->senderHint();
            }

            return false;
        }

        $this->info('Принято сервисом. Доставкой займётся воркер — результат ниже или в панели.');

        return true;
    }

    /**
     * Последние письма проекта: сюда попадает результат отправки через транспорт,
     * которая отвечает до фактической доставки.
     */
    private function recent(Client $client): void
    {
        try {
            $list = $client->messages(['per_page' => 5]);
        } catch (MailServiceException) {
            return;
        }

        $rows = [];
        foreach ((array) ($list['items'] ?? []) as $item) {
            $rows[] = [
                (string) ($item['id'] ?? ''),
                (string) ($item['status'] ?? ''),
                (string) ($item['subject'] ?? ''),
                (string) ($item['error'] ?? ''),
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Последние письма проекта</comment>');
        $this->table(['id', 'статус', 'тема', 'ошибка'], $rows);
    }

    /**
     * Отправитель проверочных писем: свой или из настроек приложения.
     */
    private function from(): string
    {
        return trim((string) ($this->option('from') ?? ''));
    }

    /**
     * Подсказка по типовым ответам сервиса.
     */
    private function hint(MailServiceException $e): void
    {
        if (str_contains($e->getMessage(), 'Sender address rejected')) {
            $this->senderHint();

            return;
        }

        $code = $e->getCode();

        if ($code === 0) {
            $this->line('  Проверьте MAILERSERVICE_URL и что сервис виден с этой машины.');

            return;
        }

        if ($code === 401 || $code === 403) {
            $this->line('  Ключ не подошёл: MAILERSERVICE_KEY. Новый — php bin/mailer key:create на стороне сервиса.');

            return;
        }

        if ($code === 429) {
            $this->line('  Упёрлись в лимит проекта — лимиты видны в панели сервиса.');
        }
    }

    private function senderHint(): void
    {
        $this->line('  Транспорт сервиса не разрешает такой адрес отправителя.');
        $this->line('  Проверить догадку: mailerservice:test с ключом --from=адрес-аккаунта-транспорта.');
        $this->line('  Насовсем — тот же адрес в MAIL_FROM_ADDRESS и php artisan config:clear.');
    }
}
