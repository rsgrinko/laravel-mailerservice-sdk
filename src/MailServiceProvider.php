<?php

declare(strict_types=1);

namespace Rsgrinko\MailService;

use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Rsgrinko\MailService\Transport\MailServiceTransport;

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
        }

        Mail::extend('mailerservice', function (array $config): MailServiceTransport {
            $settings = (array) $this->app['config']->get('mailerservice', []);

            return new MailServiceTransport(
                $this->app->make(Client::class),
                isset($settings['tag']) && $settings['tag'] !== '' ? (string) $settings['tag'] : null,
                isset($settings['transport']) && $settings['transport'] !== '' ? (string) $settings['transport'] : null,
                (bool) ($settings['sync'] ?? false),
            );
        });
    }
}