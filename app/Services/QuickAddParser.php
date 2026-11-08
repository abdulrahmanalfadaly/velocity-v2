<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Reads plain words out of a quick-add line, so "Ethics essay fri 3h #fyp"
 * becomes a task due Friday, 3 hours of work, in the matching project.
 * Deterministic: it only picks up what it clearly recognises and leaves the
 * rest of the words as the title.
 */
class QuickAddParser
{
    private const DAYS = ['mon' => 1, 'monday' => 1, 'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4, 'fri' => 5, 'friday' => 5, 'sat' => 6, 'saturday' => 6, 'sun' => 7, 'sunday' => 7];
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * @param  array<int, string>  $projects  id => name
     * @return array{title: string, due: ?CarbonImmutable, hours: ?float, projectId: ?int, picked: list<array{kind: string, text: string}>}
     */
    public function parse(string $text, CarbonImmutable $nowLocal, array $projects = []): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $keep = [];
        $day = null;
        $time = null;
        $hours = null;
        $projectId = null;
        $picked = [];

        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $lw = mb_strtolower(rtrim($w, ',.'));
            $next = mb_strtolower($words[$i + 1] ?? '');

            if (preg_match('/^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)$/', $lw, $m)) {
                $hours = (float) $m[1];
                $picked[] = ['kind' => 'hours', 'text' => $w];
            } elseif (preg_match('/^(\d+)\s*(m|min|mins|minutes)$/', $lw, $m)) {
                $hours = round(((int) $m[1]) / 60, 2);
                $picked[] = ['kind' => 'hours', 'text' => $w];
            } elseif (in_array($lw, ['today', 'tonight'], true)) {
                $day = $nowLocal->startOfDay();
                $picked[] = ['kind' => 'due', 'text' => $w];
            } elseif (in_array($lw, ['tomorrow', 'tmr', 'tmrw', 'tomoro'], true)) {
                $day = $nowLocal->startOfDay()->addDay();
                $picked[] = ['kind' => 'due', 'text' => $w];
            } elseif ($lw === 'next' && isset(self::DAYS[$next])) {
                $day = $this->nextWeekday($nowLocal, self::DAYS[$next])->addWeek();
                $picked[] = ['kind' => 'due', 'text' => $w.' '.$words[++$i]];
            } elseif (isset(self::DAYS[$lw])) {
                $day = $this->nextWeekday($nowLocal, self::DAYS[$lw]);
                $picked[] = ['kind' => 'due', 'text' => $w];
            } elseif (preg_match('/^(\d{1,2})$/', $lw, $m) && $this->month($next) !== null) {
                $day = $this->dateInYear($nowLocal, (int) $m[1], $this->month($next));
                $picked[] = ['kind' => 'due', 'text' => $w.' '.$words[++$i]];
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $lw, $m)) {
                $day = $this->dateInYear($nowLocal, (int) $m[1], (int) $m[2]);
                $picked[] = ['kind' => 'due', 'text' => $w];
            } elseif (preg_match('/^(?:at)?(\d{1,2})(?::(\d{2}))?(am|pm)$/', $lw, $m) || preg_match('/^(\d{1,2}):(\d{2})$/', $lw, $m)) {
                $h = (int) $m[1] % 12 + (($m[3] ?? '') === 'pm' ? 12 : 0);
                if (! isset($m[3])) {
                    $h = (int) $m[1];
                }
                $time = sprintf('%02d:%02d', $h, (int) ($m[2] ?? 0));
                $picked[] = ['kind' => 'time', 'text' => $w];
            } elseif (str_starts_with($lw, '#') && mb_strlen($lw) > 1) {
                $needle = mb_substr($lw, 1);
                foreach ($projects as $id => $name) {
                    $slug = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
                    $initials = mb_strtolower(preg_replace('/[^A-Z]/', '', $name));
                    if (str_starts_with($slug, $needle) || $initials === $needle) {
                        $projectId = (int) $id;
                        $picked[] = ['kind' => 'project', 'text' => $w];
                        continue 2;
                    }
                }
                $keep[] = $w;
            } elseif ($lw === 'at' && preg_match('/^\d{1,2}(:\d{2})?(am|pm)?$/', $next)) {
                continue; // "at 3pm": the time is read on the next word
            } else {
                $keep[] = $w;
            }
        }

        $due = null;
        if ($day || $time) {
            $day ??= $nowLocal->startOfDay();
            $due = $day->setTimeFromTimeString($time ?? '23:59');
        }

        return ['title' => trim(implode(' ', $keep)), 'due' => $due, 'hours' => $hours, 'projectId' => $projectId, 'picked' => $picked];
    }

    private function month(string $word): ?int
    {
        $word = rtrim($word, ',.');

        return self::MONTHS[substr($word, 0, 4)] ?? (strlen($word) >= 3 ? (self::MONTHS[substr($word, 0, 3)] ?? null) : null);
    }

    private function nextWeekday(CarbonImmutable $now, int $iso): CarbonImmutable
    {
        $diff = ($iso - $now->dayOfWeekIso + 7) % 7;

        return $now->startOfDay()->addDays($diff);
    }

    private function dateInYear(CarbonImmutable $now, int $day, int $month): CarbonImmutable
    {
        $date = $now->setDate($now->year, $month, $day)->startOfDay();

        return $date->lessThan($now->startOfDay()) ? $date->addYear() : $date;
    }
}
