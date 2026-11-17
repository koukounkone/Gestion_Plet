<?php
declare(strict_types=1);

namespace MonElevage\Auth;

interface MailSender
{
    public function send(string $recipient, string $subject, string $body): void;
}
