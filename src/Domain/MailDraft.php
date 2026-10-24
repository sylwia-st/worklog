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

    /**
     * Link mailto: (RFC 6068) – otwiera gotową wiadomość w domyślnym programie pocztowym (Thunderbird, Evolution…).
     * Bez treści ($withBody = false) – dla zbyt długich maili na Windowsie, patrz data-mailto-short w base.html.twig.
     */
    public function mailtoUrl(bool $withBody = true): string
    {
        // adresy też kodowane: „+”, „&”, „?” albo „%” w adresie inaczej psułyby link; „@” może zostać
        $addresses = static fn (array $list): string => implode(',', array_map(static fn (string $a): string => str_replace('%40', '@', rawurlencode($a)), $list));
        $query = [];
        if ([] !== $this->cc) {
            $query[] = 'cc='.$addresses($this->cc);
        }
        $query[] = 'subject='.rawurlencode($this->subject);
        if ($withBody) {
            $query[] = 'body='.rawurlencode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $this->body)));
        }

        return 'mailto:'.$addresses($this->to).'?'.implode('&', $query);
    }
}
