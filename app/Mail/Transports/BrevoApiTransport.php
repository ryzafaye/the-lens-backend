<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

class BrevoApiTransport extends AbstractTransport
{
    public function __construct(
        protected ?string $apiKey = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $apiKey = trim((string) ($this->apiKey ?: config('services.brevo.key')));
        if ($apiKey === '') {
            throw new RuntimeException('Brevo API key (BREVO_API_KEY) is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $fromAddresses = $email->getFrom();
        $firstFrom = $fromAddresses[0] ?? null;

        $senderEmail = $firstFrom?->getAddress() ?: (string) config('mail.from.address', 'lenspublication.notifications@gmail.com');
        $senderName = $firstFrom?->getName() ?: (string) config('mail.from.name', 'The Lens Publication');

        $payload = [
            'sender' => [
                'email' => $senderEmail,
                'name' => $senderName,
            ],
            'to' => $this->formatAddresses($email->getTo()),
            'subject' => (string) ($email->getSubject() ?? ''),
        ];

        $cc = $this->formatAddresses($email->getCc());
        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->formatAddresses($email->getBcc());
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $this->formatAddresses($email->getReplyTo());
        if ($replyTo !== []) {
            $payload['replyTo'] = $replyTo[0];
        }

        $htmlBody = $email->getHtmlBody();
        if (is_string($htmlBody) && trim($htmlBody) !== '') {
            $payload['htmlContent'] = $htmlBody;
        }

        $textBody = $email->getTextBody();
        if (is_string($textBody) && trim($textBody) !== '') {
            $payload['textContent'] = $textBody;
        } elseif (!empty($payload['htmlContent'])) {
            $payload['textContent'] = trim(preg_replace('/\s+/', ' ', strip_tags((string) $payload['htmlContent'])));
        }

        $response = Http::withHeaders([
            'api-key' => $apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])
            ->timeout(10)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Brevo API email request failed with HTTP %d: %s',
                $response->status(),
                $response->body()
            ));
        }
    }

    /**
     * @param  Address[]  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    protected function formatAddresses(array $addresses): array
    {
        $formatted = [];
        foreach ($addresses as $address) {
            $entry = ['email' => $address->getAddress()];
            $name = trim((string) $address->getName());
            if ($name !== '') {
                $entry['name'] = $name;
            }
            $formatted[] = $entry;
        }

        return $formatted;
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
