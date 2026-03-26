<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Entity;

enum SupportedLeagueSeason: int
{
    case WORLD_CUPS = 457;
    case GAMES = 318;
    case PARACLIMBING = 438;

    /** @return self[] */
    public static function defaults(): array
    {
        return [
            self::WORLD_CUPS,
            self::GAMES,
            self::PARACLIMBING,
        ];
    }

    public static function fromCliValue(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'world-cups' => self::WORLD_CUPS,
            'games' => self::GAMES,
            'paraclimbing' => self::PARACLIMBING,
            default => null,
        };
    }

    /** @return string[] */
    public static function allowedCliValues(): array
    {
        return array_map(
            static fn (self $leagueSeason): string => $leagueSeason->cliValue(),
            self::defaults(),
        );
    }

    public function cliValue(): string
    {
        return match ($this) {
            self::WORLD_CUPS => 'world-cups',
            self::GAMES => 'games',
            self::PARACLIMBING => 'paraclimbing',
        };
    }
}
