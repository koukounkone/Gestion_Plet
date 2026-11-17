<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpMailSender implements MailSender
{
    public function __construct(private readonly array $config)
    {
    }

    public function send(string $recipient, string $subject, string $body): void
    {
        $smtp = $this->config['smtp'] ?? [];
        $host = $smtp['host'] ?? null;
        $username = $smtp['username'] ?? null;
        $password = $smtp['password'] ?? null;
        $encryption = $smtp['encryption'] ?? null;
        $port = filter_var($smtp['port'] ?? null, FILTER_VALIDATE_INT);
        $from = $this->config['from'] ?? null;

        if (($this->config['transport'] ?? null) !== 'smtp'
            || !is_string($host) || trim($host) === ''
            || !is_string($username) || trim($username) === ''
            || !is_string($password) || $password === ''
            || !is_string($encryption) || !in_array($encryption, ['tls', 'smtps'], true)
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || !class_exists(PHPMailer::class)) {
            throw new RuntimeException('La configuration SMTP sécurisée est incomplète.');
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = $encryption === 'smtps'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $port;
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, (string) ($this->config['from_name'] ?? 'Mon Élevage'));
            $mail->addAddress($recipient);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new RuntimeException(
                'Le serveur SMTP n’a pas accepté le message. Vérifiez le relais et ses identifiants.'
            );
        }
    }
}
