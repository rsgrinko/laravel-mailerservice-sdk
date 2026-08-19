<?php

declare(strict_types=1);

namespace Rsgrinko\MailService\Facades;

use Illuminate\Support\Facades\Facade;
use Rsgrinko\MailService\Client;
use Rsgrinko\MailService\Message;

/**
 * @method static array send(Message|array $mail)
 * @method static array sendNow(Message|array $mail)
 * @method static array status(string $id)
 * @method static array messages(array $filters = [])
 * @method static array retry(string $id)
 * @method static array cancel(string $id)
 * @method static array templates()
 * @method static array health()
 *
 * @see \Rsgrinko\MailService\Client
 */
class MailService extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}