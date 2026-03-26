<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi;

final class OpenAiInfoSheetResponseSchemaFactory
{
    /** @return array<string,mixed> */
    public function buildScheduleSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'rounds' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'starts_at' => ['type' => 'string'],
                            'ends_at' => [
                                'type' => ['string', 'null'],
                            ],
                        ],
                        'required' => ['name', 'starts_at', 'ends_at'],
                    ],
                ],
                'ticket_purchase_url' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_price' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_currency' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_summary' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => ['rounds'],
        ];
    }
}
