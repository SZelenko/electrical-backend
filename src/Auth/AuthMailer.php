<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\OneTimeCode;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final readonly class AuthMailer
{
    public function __construct(private MailerInterface $mailer) {}

    public function sendVerificationCode(string $email, string $code): void
    {
        $this->send($email, 'Your email verification code', 'emails/verification_code.txt.twig', $code);
    }

    public function sendLoginCode(string $email, string $code): void
    {
        $this->send($email, 'Your sign-in code', 'emails/login_code.txt.twig', $code);
    }

    private function send(string $email, string $subject, string $template, string $code): void
    {
        $this->mailer->send(
            new TemplatedEmail()
                ->to($email)
                ->subject($subject)
                ->textTemplate($template)
                ->context(['code' => $code, 'ttlMinutes' => intdiv(OneTimeCode::TTL_SECONDS, 60)]),
        );
    }
}
