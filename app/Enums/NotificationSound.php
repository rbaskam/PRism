<?php

namespace App\Enums;

/**
 * The sound desktop notifications play, chosen in Settings → Notifications. The named sounds are the ones
 * built into macOS; Windows can only play its default sound or none.
 */
enum NotificationSound: string
{
    case Default = 'default';
    case None = 'none';
    case Basso = 'basso';
    case Blow = 'blow';
    case Bottle = 'bottle';
    case Frog = 'frog';
    case Funk = 'funk';
    case Glass = 'glass';
    case Hero = 'hero';
    case Morse = 'morse';
    case Ping = 'ping';
    case Pop = 'pop';
    case Purr = 'purr';
    case Sosumi = 'sosumi';
    case Submarine = 'submarine';
    case Tink = 'tink';

    /**
     * The sounds that can be chosen on an operating system family, as reported by PHP_OS_FAMILY.
     *
     * @return list<self>
     */
    public static function availableOn(string $osFamily): array
    {
        return $osFamily === 'Darwin' ? self::cases() : [self::Default, self::None];
    }

    public function label(): string
    {
        return match ($this) {
            self::Default => 'System default',
            self::None => 'None',
            default => $this->name,
        };
    }

    /**
     * The macOS system sound to play, or null when the notification plays the default sound or none.
     */
    public function systemSoundName(): ?string
    {
        return in_array($this, [self::Default, self::None], true) ? null : $this->name;
    }
}
