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

    private const MONTH_NAMES = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    private const UNITS = ['day' => 'day', 'days' => 'day', 'weekday' => 'weekday', 'weekdays' => 'weekday', 'week' => 'week', 'weeks' => 'week',
        'month' => 'month', 'months' => 'month', 'year' => 'year', 'years' => 'year'];

    /**
     * @param  array<int, string>  $projects  id => name
     * @return array{title: string, due: ?CarbonImmutable, hours: ?float, projectId: ?int, repeat: ?array{unit: string, every: int}, picked: list<array{kind: string, text: string}>}
     */
    public function parse(string $text, CarbonImmutable $nowLocal, array $projects = []): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $keep = [];
        $day = null;
        $time = null;
        $hours = null;
        $projectId = null;
        $repeat = null;
        $repeatDay = null;
        $picked = [];

        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $lw = mb_strtolower(rtrim($w, ',.'));
            $next = mb_strtolower($words[$i + 1] ?? '');
            $w1 = rtrim($next, ',.');
            $w2 = mb_strtolower(rtrim($words[$i + 2] ?? '', ',.'));

            // Repeats: only "every …" counts, so a title like "Weekly report" stays as it is.
            if ($lw === 'every' && $repeat === null && (isset(self::UNITS[$w1]) || isset(self::DAYS[$w1])
                || (in_array($w1, ['other', '2nd'], true) && isset(self::UNITS[$w2])) || (ctype_digit($w1) && (int) $w1 > 0 && isset(self::UNITS[$w2])))) {
                if (isset(self::DAYS[$w1])) {
                    $repeat = ['unit' => 'week', 'every' => 1];
                    $repeatDay = self::DAYS[$w1];
                    $picked[] = ['kind' => 'repeat', 'text' => $w.' '.$words[++$i]];
                } elseif (isset(self::UNITS[$w1])) {
                    $repeat = ['unit' => self::UNITS[$w1], 'every' => 1];
                    $picked[] = ['kind' => 'repeat', 'text' => $w.' '.$words[++$i]];
                } else {
                    $repeat = ['unit' => self::UNITS[$w2], 'every' => ctype_digit($w1) ? min(99, (int) $w1) : 2];
                    $picked[] = ['kind' => 'repeat', 'text' => $w.' '.$words[++$i].' '.$words[++$i]];
                }
                if ($repeat['unit'] === 'weekday') {
                    $repeat['every'] = 1;
                }
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)$/', $lw, $m) && (float) $m[1] >= 0.05 && (float) $m[1] <= 500) {
                $hours = (float) $m[1];
                $picked[] = ['kind' => 'hours', 'text' => $w];
            } elseif (preg_match('/^(\d+)\s*(m|min|mins|minutes)$/', $lw, $m) && (int) $m[1] >= 3 && (int) $m[1] <= 30000) {
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
            } elseif (preg_match('/^(\d{1,2})$/', $lw, $m) && ($month = $this->month($next)) !== null && $this->realDate((int) $m[1], $month)) {
                $day = $this->dateInYear($nowLocal, (int) $m[1], $month);
                $picked[] = ['kind' => 'due', 'text' => $w.' '.$words[++$i]];
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $lw, $m) && $this->realDate((int) $m[1], (int) $m[2])) {
                $day = $this->dateInYear($nowLocal, (int) $m[1], (int) $m[2]);
                $picked[] = ['kind' => 'due', 'text' => $w];
            } elseif ($this->clockTime($lw) !== null) {
                $time = $this->clockTime($lw);
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

        // "every monday" with no other date: the first one is the coming Monday.
        if ($repeatDay !== null && $day === null) {
            $day = $this->nextWeekday($nowLocal, $repeatDay);
        }
        $due = null;
        if ($day || $time) {
            $due = ($day ?? $nowLocal->startOfDay())->setTimeFromTimeString($time ?? '23:59');
            // Only a time ("call mum 9am") that has already passed today means tomorrow.
            if ($day === null && $due->lessThanOrEqualTo($nowLocal)) {
                $due = $due->addDay();
            }
        }

        return ['title' => trim(implode(' ', $keep)), 'due' => $due, 'hours' => $hours, 'projectId' => $projectId, 'repeat' => $repeat, 'picked' => $picked];
    }

    /** A month name or the start of one ("dec", "decem", "december"), not just any word that starts like one ("decks"). */
    private function month(string $word): ?int
    {
        $word = rtrim($word, ',.');
        if (strlen($word) < 3) {
            return null;
        }
        foreach (self::MONTH_NAMES as $i => $name) {
            if (str_starts_with($name, $word)) {
                return $i + 1;
            }
        }

        return null;
    }

    private function realDate(int $day, int $month): bool
    {
        return checkdate($month, $day, 2028); // a leap year, so 29 Feb is allowed
    }

    /** "9am", "at3pm", "9:30pm", "14:00" → "HH:MM"; anything that isn't a real time → null. */
    private function clockTime(string $word): ?string
    {
        if (preg_match('/^(?:at)?(\d{1,2})(?::(\d{2}))?(am|pm)$/', $word, $m)) {
            $h = (int) $m[1];
            if ($h < 1 || $h > 12) {
                return null;
            }
            $h = $h % 12 + ($m[3] === 'pm' ? 12 : 0);
        } elseif (preg_match('/^(\d{1,2}):(\d{2})$/', $word, $m)) {
            $h = (int) $m[1];
            if ($h > 23) {
                return null;
            }
        } else {
            return null;
        }
        $min = (int) ($m[2] ?? 0);

        return $min < 60 ? sprintf('%02d:%02d', $h, $min) : null;
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
