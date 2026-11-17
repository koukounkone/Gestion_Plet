<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use RuntimeException;

final class NativeMailSender implements MailSender
{
    public function __construct(private readonly ?string $fromAddress)
    {
    }

    public function send(string $recipient, string $subject, string $body): void
    {
        if ($this->fromAddress === null
            || filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresse d’expédition des courriels non configurée.');
        }

        if (mail($recipient, $subject, $body, [
            'From' => $this->fromAddress,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer' => 'Mon Elevage',
        ])) {
            return;
        }

        throw new RuntimeException('Le transport de courrier PHP a refusé le message.');
    }
}
