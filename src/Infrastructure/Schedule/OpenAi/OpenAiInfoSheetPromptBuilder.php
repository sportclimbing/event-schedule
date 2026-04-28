<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi;

use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;

final class OpenAiInfoSheetPromptBuilder
{
    public function buildSchedulePrompt(EventInfo $event): string
    {
        return sprintf(
            <<<PROMPT
            Parse the attached IFSC infosheet PDF and extract the competition round schedule/programme.
            Schedule might be split on multiple pages. Output must be deterministic for the same input.

            Event context:
            - Event: %s
            - Local date range: %s to %s
            - Timezone: %s
            - Discipline: %s

            Output rules:
            - Process rows in reading order (top-to-bottom, left-to-right) before building the final output.
            - Return only official competition rounds (Qualification, Semi-Final, Final, etc.).
            - Exclude non-round activities (registration, technical meeting, training, practice, warm-up, isolation opening/closing, ceremony).
            - Every row must include starts_at.
            - Use local venue time in timezone %s.
            - Use YYYY-MM-DD HH:MM format for starts_at and ends_at.
              - If it says "12:00 – 13:00" for example, it means "starts_at" is 12:00 and "ends_at" is 13:00.
              - Sometimes it might say "18.30" for example when it means "18:30"
            - Set ends_at to null when no end time is provided.
            - Also extract ticket info when available:
              - ticket_purchase_url: URL where tickets can be purchased.
              - ticket_price: numeric ticket price only (no currency symbol/code), string format.
              - ticket_currency: ticket price currency as ISO code when possible (e.g. EUR, USD, CHF), otherwise convert symbol to ISO code. Return "null" when no price can be found
              - ticket_summary: concise attendee-facing summary with ticket notes (for example if entry is free, where to buy tickets, notable conditions/restrictions, and any practical attendee hints).
                - Look well, this should never be empty. There is always info regarding tickets, even if it's just TBA or similar
                - Do not include ticket purchase URLs in the summary, and do not make references to `ticket_purchase_url`
            - If no ticket information exists, set ticket_purchase_url, ticket_price, ticket_currency, and ticket_summary to null.
            - Don't use hyphens (—) or emojis
            - Remove exact duplicate rounds using (name, starts_at, ends_at).
            - Sort rounds by starts_at ascending
            - If multiple valid interpretations are possible, choose the earliest valid date/time within the event date range, and keep round names as close to the original as possible.
            
            Round name rules:
             - Use regular single quotes (') instead of fancy quotes
             - They should have the first letter of each word capitalized
             - Keep the words "Final", "Semi-Final", and "Qualification" singular (eg "Final" instead of Finals)
             - Semi Final round names should be spelled as "Semi-Final"
             - They all should include gender (eg "Men's", "Women's" or "Men's & Women's"), followed by the discipline ("Boulder", "Lead", or "Speed"), followed by "Qualification", "Semi-Final" or "Final".
               - Some valid examples:
                 - "Women's Boulder Final"
                 - "Men's & Women's Lead Qualification"
                 - "Men's Speed Semi-Final"
             - If a round name can't be found, take your best guess keeping the above format. Never leave it empty/null
             - If gender is not specified, assume it's "Men's & Women's"
             - Don't merge round lines. Each round is on its own line with its own schedule in the table
             
            No mistakes! 
            PROMPT,
            $event->eventName,
            $event->localStartDate,
            $event->localEndDate,
            $event->timeZone->getName(),
            implode(', ', $event->disciplines),
            $event->timeZone->getName(),
        );
    }
}
