<?php

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Schedule\Port;

use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetParseResult;

interface InfoSheetScheduleParserInterface
{
    public function parseScheduleFromPdf(
        EventInfo $event,
        string $pdfPath,
        string $infoSheetUrl = '',
        array $infoSheetHeaders = [],
        bool $forceRescan = false,
    ): InfoSheetParseResult;

    public function loadCachedSchedule(
        EventInfo $event,
        string $infoSheetUrl,
        array $infoSheetHeaders = [],
    ): ?InfoSheetParseResult;
}
