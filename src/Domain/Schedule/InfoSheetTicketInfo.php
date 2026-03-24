<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Schedule;

final readonly class InfoSheetTicketInfo
{
    public function __construct(
        public ?string $purchaseUrl = null,
        public ?string $price = null,
        public ?string $currency = null,
        public ?string $summary = null,
    ) {
    }
}

