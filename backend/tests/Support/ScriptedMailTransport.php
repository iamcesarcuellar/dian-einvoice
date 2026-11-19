<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A mail transport that answers as a mail server would, one scripted answer per email: 250 sends
 * it, a 4xx or 5xx code is the reply of the server (UnexpectedResponseException, as Symfony's SMTP
 * transport throws it), and 0 is a server that cannot be reached. After the script, every email is
 * sent.
 */
final class ScriptedMailTransport extends AbstractTransport
{
    /** @var list<int> */
    public static array $script = [];

    /** @var list<SentMessage> */
    public static array $sent = [];

    protected function doSend(SentMessage $message): void
    {
        $code = array_shift(self::$script) ?? 250;

        match (true) {
            $code === 0 => throw new TransportException('Connection could not be established with host "mail:1025": Connection refused.'),
            $code >= 400 => throw new UnexpectedResponseException(sprintf('Expected response code "250" but got code "%d", with message "%d Synthetic reply of the server".', $code, $code), $code),
            default => self::$sent[] = $message,
        };
    }

    public function __toString(): string
    {
        return 'scripted://';
    }
}
