<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

use ControlPlane\Privacy\ControlProviderEgressPolicy;

/** SMTP delivery via libcurl; no external mail dependency or shell command. */
final readonly class SmtpEmailTransport implements EmailTransport
{
    public function __construct(private string $dsn, private string $fromAddress,
        private ControlProviderEgressPolicy $egress)
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['smtp', 'smtps'], true)
            || !isset($parts['host']) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || !extension_loaded('curl')
        ) {
            throw new \InvalidArgumentException('Commercial SMTP transport is not configured.');
        }
    }

    public function send(
        string $recipient,
        string $subject,
        string $text,
        string $html,
        string $messageId,
        string $purpose = 'control_email_verification',
        array $categories = ['contact_email', 'verification_link'],
    ): void {
        $this->egress->assertAllowed('smtp', $purpose, $categories);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $recipient . $subject . $messageId) === 1
            || preg_match('/^[a-z0-9.-]+@[a-z0-9.-]+$/i', $messageId) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid email message fields.');
        }
        $parts = parse_url($this->dsn);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid SMTP configuration.');
        }
        $boundary = 'platform-' . bin2hex(random_bytes(12));
        $message = implode("\r\n", [
            'From: <' . $this->fromAddress . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $subject,
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($text), 76, "\r\n")),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
            '--' . $boundary . '--',
            '',
        ]);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot prepare email stream.');
        }
        try {
            fwrite($stream, $message);
            rewind($stream);
            $host = (string) $parts['host'];
            if (str_contains($host, ':') && !str_starts_with($host, '[')) {
                $host = '[' . $host . ']';
            }
            $url = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Cannot initialize SMTP transport.');
            }
            try {
                curl_setopt_array($curl, [
                    CURLOPT_MAIL_FROM => $this->fromAddress,
                    CURLOPT_MAIL_RCPT => [$recipient],
                    CURLOPT_UPLOAD => true,
                    CURLOPT_READDATA => $stream,
                    CURLOPT_INFILESIZE => strlen($message),
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                parse_str((string) ($parts['query'] ?? ''), $query);
                if ($parts['scheme'] === 'smtps' || ($query['starttls'] ?? null) === '1') {
                    curl_setopt($curl, CURLOPT_USE_SSL, CURLUSESSL_ALL);
                }
                if (isset($parts['user'], $parts['pass'])) {
                    $username = rawurldecode($parts['user']);
                    $password = rawurldecode($parts['pass']);
                    if ($username === '' || $password === '') {
                        throw new \RuntimeException('Incomplete SMTP credentials.');
                    }
                    curl_setopt($curl, CURLOPT_USERNAME, $username);
                    curl_setopt($curl, CURLOPT_PASSWORD, $password);
                }
                if (curl_exec($curl) === false) {
                    throw new \RuntimeException('SMTP delivery failed.');
                }
            } finally {
                curl_close($curl);
            }
        } finally {
            fclose($stream);
        }
    }
}
