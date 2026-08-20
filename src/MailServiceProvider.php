<?php

declare(strict_types=1);

namespace Rsgrinko\MailServiceSdk;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Rsgrinko\MailServiceSdk\Console\TestCommand;
use Rsgrinko\MailServiceSdk\Transport\MailServiceTransport;

/**
 * Регистрирует клиент API и почтовый транспорт mailerservice.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mailerservice.php', 'mailerservice');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = (array) $app['config']->get('mailerservice', []);

            return new Client(
                (string) ($config['url'] ?? ''),
                (string) ($config['key'] ?? ''),
                [
                    'timeout'     => (int) ($config['timeout'] ?? 10),
                    'retries'     => (int) ($config['retries'] ?? 2),
                    'retry_delay' => (int) ($config['retry_delay'] ?? 200),
                    'verify'      => (bool) ($config['verify'] ?? true),
                ],
                $app->bound(Factory::class) ? $app->make(Factory::class) : null
            );
        });

        $this->app->alias(Client::class, 'mailerservice');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mailerservice.php' => $this->app->configPath('mailerservice.php'),
            ], 'mailerservice-config');

            $this->commands([TestCommand::class]);
        }

        // $config — настройки мейлера из config/mail.php. Метку, транспорт сервиса и
        // режим отправки там можно переопределить: так заводятся несколько мейлеров
        // с разными метками. Ключ transport в этом массиве занят именем драйвера,
        // поэтому транспорт сервиса берётся из service_transport.
        Mail::extend('mailerservice', function (array $config): MailServiceTransport {
            $settings = (array) $this->app['config']->get('mailerservice', []);

            return new MailServiceTransport(
                $this->app->make(Client::class),
                $this->text($config['tag'] ?? null) ?? $this->text($settings['tag'] ?? null),
                $this->text($config['service_transport'] ?? null) ?? $this->text($settings['transport'] ?? null),
                (bool) ($config['sync'] ?? $settings['sync'] ?? false),
            );
        });
    }

    /**
     * Пустая строка и null для настройки — одно и то же: «не задано».
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
