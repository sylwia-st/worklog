<?php

namespace App\Domain;

/**
 * Kiedy pokazać przypomnienie. Timer na hoście pyta co minutę (pon–pt), a o tym, czy już pora,
 * decyduje godzina z Ustawień – dzięki temu zmienia się ją w aplikacji, bez ruszania systemd.
 *
 * Przypomnienie pojawia się raz dziennie: o ustawionej godzinie albo – gdy komputer był wtedy wyłączony
 * lub uśpiony – przy pierwszym sprawdzeniu później tego dnia. Zmiana godziny w ciągu dnia „uzbraja” je od nowa.
 */
final class ReminderSchedule
{
    /** @param string $lastSent znacznik z poprzedniego przypomnienia (stamp()), '' gdy nie było */
    public static function isDue(string $remindTime, string $lastSent, \DateTimeImmutable $now): bool
    {
        $at = TimeFormat::clockToMinutes($remindTime);
        if (null === $at || (int) $now->format('N') >= 6) {
            return false; // wyłączone (puste pole) albo weekend
        }
        if ((int) $now->format('G') * 60 + (int) $now->format('i') < $at) {
            return false;
        }

        return self::stamp($remindTime, $now) !== $lastSent;
    }

    /** Timer na hoście sprawdza co minutę między 06:00 a 21:59 – późniejsza godzina nigdy by nie zadziałała. */
    public static function inTimerWindow(string $remindTime): bool
    {
        $at = TimeFormat::clockToMinutes($remindTime);

        return null !== $at && $at >= 6 * 60 && $at < 22 * 60;
    }

    /** Znacznik zapisywany po przypomnieniu: dzień + godzina, dla której je pokazano. */
    public static function stamp(string $remindTime, \DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d').' '.(TimeFormat::normalizeClock($remindTime) ?? '');
    }
}
