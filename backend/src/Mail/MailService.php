<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\LoggerService;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    public function __construct(
        private readonly LoggerService $logger,
        private readonly array $env,
        private readonly bool $dependenciesAvailable = true
    ) {
    }

    public function sendReport(string $to, string $subject, string $html): bool
    {
        if (!$this->dependenciesAvailable || !class_exists(PHPMailer::class)) {
            $this->logger->warning('No se envió email porque PHPMailer no está disponible');
            return false;
        }

        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('No se envió email porque no hay destinatario configurado');
            return false;
        }

        if (empty($this->env['SMTP_HOST']) || empty($this->env['SMTP_USERNAME']) || empty($this->env['SMTP_PASSWORD'])) {
            $this->logger->warning('No se envió email porque falta configuración SMTP');
            return false;
        }

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->env['SMTP_HOST'];
            $mailer->Port = (int) ($this->env['SMTP_PORT'] ?? 587);
            $mailer->SMTPAuth = true;
            $mailer->Timeout = 20;
            $mailer->Username = $this->env['SMTP_USERNAME'];
            $mailer->Password = $this->env['SMTP_PASSWORD'];
            $mailer->SMTPSecure = $this->resolveEncryption((string) ($this->env['SMTP_ENCRYPTION'] ?? 'tls'));
            $mailer->setFrom(
                $this->resolveFromEmail(),
                $this->env['SMTP_FROM_NAME'] ?? 'Jobfinder'
            );
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Subject = $subject;
            $mailer->Body = $html;
            $mailer->send();

            $this->logger->info('Email diario enviado', ['to' => $to]);
            return true;
        } catch (Exception $exception) {
            $this->logger->error('Error enviando email', ['message' => $exception->getMessage()]);
            return false;
        }
    }

    private function resolveEncryption(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
    }

    private function resolveFromEmail(): string
    {
        $fromEmail = trim((string) ($this->env['SMTP_FROM_EMAIL'] ?? ''));

        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false) {
            return $fromEmail;
        }

        return (string) ($this->env['SMTP_USERNAME'] ?? '');
    }
}
