<?php

namespace App\Domain;

final class MailDraft
{
    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $invalid adresy z ustawień, które odrzucono jako niepoprawne
     */
    public function __construct(
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $invalid = [],
    ) {
    }

    public function hasRecipients(): bool
    {
        return [] !== $this->to;
    }

    /** Link mailto: (RFC 6068) – otwiera gotową wiadomość w domyślnym programie pocztowym (Thunderbird, Evolution…). */
    public function mailtoUrl(): string
    {
        $query = [];
        if ([] !== $this->cc) {
            $query[] = 'cc='.implode(',', $this->cc);
        }
        $query[] = 'subject='.rawurlencode($this->subject);
        $query[] = 'body='.rawurlencode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $this->body)));

        return 'mailto:'.implode(',', $this->to).'?'.implode('&', $query);
    }
}
