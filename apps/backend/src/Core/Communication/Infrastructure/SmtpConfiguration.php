<?php

declare(strict_types=1);

namespace App\Core\Communication\Infrastructure;

use InvalidArgumentException;

final readonly class SmtpConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        #[\SensitiveParameter]
        public ?string $password,
        public string $encryption,
        public string $fromAddress,
        public string $fromName,
    ) {
        if (trim($host) === '') {
            throw new InvalidArgumentException('SMTP host must not be blank.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SMTP port is invalid.');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('SMTP encryption must be none, tls, or ssl.');
        }
        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('SMTP username and password must be configured together.');
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('SMTP sender address is invalid.');
        }
        if (trim($fromName) === '') {
            throw new InvalidArgumentException('SMTP sender name must not be blank.');
        }
    }

    public function dsn(): string
    {
        $scheme = $this->encryption === 'ssl' ? 'smtps' : 'smtp';
        $credentials = '';
        if ($this->username !== null && $this->password !== null) {
            $credentials = rawurlencode($this->username)
                . ':' . rawurlencode($this->password) . '@';
        }

        $options = match ($this->encryption) {
            'none' => '?auto_tls=false',
            'tls' => '?require_tls=true',
            'ssl' => '',
        };

        return sprintf(
            '%s://%s%s:%d%s',
            $scheme,
            $credentials,
            $this->host,
            $this->port,
            $options,
        );
    }
}
