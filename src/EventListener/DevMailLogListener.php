<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * Dev only: the mailer DSN is null://, so the code is written to the app log to make sign-up possible locally.
 */
#[When(env: 'dev')]
final readonly class DevMailLogListener
{
    public function __construct(private LoggerInterface $logger) {}

    #[AsEventListener]
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof TemplatedEmail) {
            return;
        }

        $this->logger->info('Dev mail to {recipient}, subject "{subject}", code {code}', [
            'recipient' => implode(', ', array_map(static fn($a) => $a->getAddress(), $message->getTo())),
            'subject' => $message->getSubject(),
            'code' => $message->getContext()['code'] ?? null,
        ]);
    }
}
