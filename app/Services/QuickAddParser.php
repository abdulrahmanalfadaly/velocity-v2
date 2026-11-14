<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Reads plain words out of a quick-add line, so "Ethics essay fri 3h #fyp"
 * becomes a task due Friday, 3 hours of work, in the matching project, and
 * "Dentist tomorrow 3-4pm #personal" an event.
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
        $s = $this->scan($text, $nowLocal, $projects, false);
        $day = $s['day'];
        $time = $s['time'];

        // "every monday" with no other date: the first one is the coming Monday.
        if ($s['repeatDay'] !== null && $day === null) {
            $day = $this->nextWeekday($nowLocal, $s['repeatDay']);
        }
        $due = null;
        if ($day || $time) {
            $due = ($day ?? $nowLocal->startOfDay())->setTimeFromTimeString($time ?? '23:59');
            // Only a time ("call mum 9am") that has already passed today means tomorrow.
            if ($day === null && $due->lessThanOrEqualTo($nowLocal)) {
                $due = $due->addDay();
            }
        }

        return ['title' => $s['title'], 'due' => $due, 'hours' => $s['hours'], 'projectId' => $s['projectId'], 'repeat' => $s['repeat'], 'picked' => $s['picked']];
    }

    /**
     * An event from one line: "Dentist tomorrow 3pm 1h", "Quiz fri 9-10am #class", "Gym every mon 6pm".
     * No time means all day; a start with no end lasts the hours given, or an hour.
     *
     * @return array{title: string, date: string, start: ?string, end: ?string, allDay: bool, calendar: ?string, weekly: bool, picked: list<array{kind: string, text: string}>}
     */
    public function parseEvent(string $text, CarbonImmutable $nowLocal): array
    {
        $s = $this->scan($text, $nowLocal, [], true);
        $day = $s['day'] ?? ($s['repeatDay'] !== null ? $this->nextWeekday($nowLocal, $s['repeatDay']) : null);
        $start = $s['time'];
        $end = $s['end'];
        if ($day === null) {
            $day = $nowLocal->startOfDay();
            // "Gym 7am" typed at 9am is tomorrow's.
            if ($start !== null && $day->setTimeFromTimeString($start)->lessThanOrEqualTo($nowLocal)) {
                $day = $day->addDay();
            }
        }
        if ($start !== null && $end === null) {
            $end = $day->setTimeFromTimeString($start)->addMinutes((int) round(($s['hours'] ?? 1) * 60))->format('H:i');
            if ($end === $start) {
                $end = $day->setTimeFromTimeString($start)->addHour()->format('H:i'); // 24 hours: a day-long event isn't timed
            }
        }

        return [
            'title' => $s['title'],
            'date' => $day->toDateString(),
            'start' => $start,
            'end' => $start === null ? null : $end,
            'allDay' => $start === null,
            'calendar' => $s['calendar'],
            'weekly' => $s['repeat'] !== null,
            'picked' => $s['picked'],
        ];
    }

    /**
     * The words, one by one: what each recognised word means, and the rest as the title.
     * In event mode "#class / #work / #personal" pick the calendar and only weekly repeats count.
     *
     * @param  array<int, string>  $projects
     * @return array{title: string, day: ?CarbonImmutable, time: ?string, end: ?string, hours: ?float, projectId: ?int, calendar: ?string, repeat: ?array{unit: string, every: int}, repeatDay: ?int, picked: list<array{kind: string, text: string}>}
     */
    private function scan(string $text, CarbonImmutable $nowLocal, array $projects, bool $event): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $keep = [];
        $day = null;
        $time = null;
        $end = null;
        $hours = null;
        $hoursAt = null; // where the last "2h" was, so "1h 30m" adds up
        $projectId = null;
        $calendar = null;
        $repeat = null;
        $repeatDay = null;
        $picked = [];

        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $lw = mb_strtolower(rtrim($w, ',.'));
            $next = mb_strtolower($words[$i + 1] ?? '');
            $w1 = rtrim($next, ',.');
            $w2 = mb_strtolower(rtrim($words[$i + 2] ?? '', ',.'));
            $repeatWord = $event
                ? (isset(self::DAYS[$w1]) || $w1 === 'week')
                : (isset(self::UNITS[$w1]) || isset(self::DAYS[$w1]) || (in_array($w1, ['other', '2nd'], true) && isset(self::UNITS[$w2])) || (ctype_digit($w1) && (int) $w1 > 0 && isset(self::UNITS[$w2])));

            // Repeats: only "every …" counts, so a title like "Weekly report" stays as it is.
            if ($lw === 'every' && $repeat === null && $repeatWord) {
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
            } elseif (preg_match('/^(\d{1,3})h(\d{1,2})m?$/', $lw, $m) && (int) $m[2] < 60 && ((int) $m[1] > 0 || (int) $m[2] >= 3)) {
                $hours = round((int) $m[1] + ((int) $m[2]) / 60, 2); // "1h30m"
                $picked[] = ['kind' => 'hours', 'text' => $w];
                $hoursAt = null;
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)$/', $lw, $m) && (float) $m[1] >= 0.05 && (float) $m[1] <= 500) {
                $hours = (float) $m[1];
                $picked[] = ['kind' => 'hours', 'text' => $w];
                $hoursAt = $i;
            } elseif (preg_match('/^(\d+)\s*(m|min|mins|minutes)$/', $lw, $m) && (int) $m[1] >= 3 && (int) $m[1] <= 30000) {
                if ($hoursAt === $i - 1 && (int) $m[1] < 60) {
                    // "1h 30m": one length, not two.
                    $hours = round($hours + ((int) $m[1]) / 60, 2);
                    $picked[count($picked) - 1]['text'] .= ' '.$w;
                } else {
                    $hours = round(((int) $m[1]) / 60, 2);
                    $picked[] = ['kind' => 'hours', 'text' => $w];
                }
                $hoursAt = null;
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
            } elseif (($range = $this->rangeWord($lw)) !== null) {
                [$time, $end] = $range; // "9-10am", "14:00-15:30"
                $picked[] = ['kind' => 'time', 'text' => $w];
            } elseif (($at = $this->clockAt($words, $i)) !== null) {
                // "3pm", "3 pm", "15:30", "noon", and maybe an end: "3pm to 4:30pm", "15:00 - 16:00".
                $used = $at['n'];
                $j = $i + $used;
                $till = $this->clockAt($words, $j + 1);
                $pair = in_array(mb_strtolower($words[$j] ?? ''), ['-', '–', 'to', 'till', 'until'], true) && $till ? $this->pair($at, $till) : null;
                if ($pair) {
                    [$time, $end] = $pair;
                    $used += 1 + $till['n'];
                } else {
                    $time = $this->hhmm($at);
                }
                $picked[] = ['kind' => 'time', 'text' => implode(' ', array_slice($words, $i, $used))];
                $i += $used - 1;
            } elseif ($event && str_starts_with($lw, '#') && ($cal = $this->calendarFrom(mb_substr($lw, 1))) !== null) {
                $calendar = $cal;
                $picked[] = ['kind' => 'calendar', 'text' => $w];
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
            } elseif (in_array($lw, ['at', 'from'], true) && ($this->clockAt($words, $i + 1) || $this->rangeWord($w1))) {
                continue; // "at 3pm", "from 9-10am": the time is read on the next word
            } else {
                $keep[] = $w;
            }
        }

        return ['title' => trim(implode(' ', $keep)), 'day' => $day, 'time' => $time, 'end' => $end, 'hours' => $hours, 'projectId' => $projectId,
            'calendar' => $calendar, 'repeat' => $repeat, 'repeatDay' => $repeatDay, 'picked' => $picked];
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

    /**
     * A clock time starting at word $j: "3pm", "at3pm", "9:30pm", "14:00", "3 pm", "noon".
     * A bare number ("3") isn't a time.
     *
     * @return array{h: int, m: int, s: ?string, colon: bool, n: int}|null s = "am" / "pm" when given, n = words used
     */
    private function clockAt(array $words, int $j): ?array
    {
        $w = mb_strtolower(rtrim($words[$j] ?? '', ',.'));
        if (in_array($w, ['noon', 'midday'], true)) {
            return ['h' => 12, 'm' => 0, 's' => 'pm', 'colon' => false, 'n' => 1];
        }
        $t = $this->rawTime(preg_replace('/^at(?=\d)/', '', $w));
        if ($t === null) {
            return null;
        }
        $n = 1;
        $after = mb_strtolower(rtrim($words[$j + 1] ?? '', ',.'));
        if ($t['s'] === null && in_array($after, ['am', 'pm'], true)) {
            $t['s'] = $after;
            $n = 2;
        }
        if ($t['s'] === null && ! $t['colon']) {
            return null;
        }

        return $this->validTime($t) ? $t + ['n' => $n] : null;
    }

    /** "9", "9:30", "9am", "14:00" → its parts, or null. */
    private function rawTime(string $w): ?array
    {
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?(am|pm)?$/', $w, $m)) {
            return null;
        }

        return ['h' => (int) $m[1], 'm' => (int) ($m[2] ?? 0), 's' => ($m[3] ?? '') ?: null, 'colon' => ($m[2] ?? '') !== ''];
    }

    private function validTime(array $t): bool
    {
        return $t['m'] < 60 && ($t['s'] !== null ? $t['h'] >= 1 && $t['h'] <= 12 : $t['h'] <= 23);
    }

    /** The parts → "HH:MM" (24-hour). */
    private function hhmm(array $t): string
    {
        $h = $t['s'] !== null ? $t['h'] % 12 + ($t['s'] === 'pm' ? 12 : 0) : $t['h'];

        return sprintf('%02d:%02d', $h, $t['m']);
    }

    /** "9-10am", "11am-1pm", "14:00-15:30" in one word → [start, end]; "2-3" (a chapter range) isn't one. */
    private function rangeWord(string $w): ?array
    {
        if (! preg_match('/^(\d{1,2}(?::\d{2})?(?:am|pm)?)[-–](\d{1,2}(?::\d{2})?(?:am|pm)?)$/u', $w, $m) || ! preg_match('/am|pm|:/', $w)) {
            return null;
        }
        $a = $this->rawTime($m[1]);
        $b = $this->rawTime($m[2]);

        return $a && $b ? $this->pair($a, $b) : null;
    }

    /** A start and an end; a side without am / pm takes the other's ("9-10am"; "11-1pm" is 11 am to 1 pm). */
    private function pair(array $a, array $b): ?array
    {
        $flip = fn (string $s) => $s === 'am' ? 'pm' : 'am';
        $minutes = fn (array $t) => (int) substr($this->hhmm($t), 0, 2) * 60 + $t['m'];
        if ($a['s'] === null && $b['s'] !== null && $a['h'] >= 1 && $a['h'] <= 12) {
            $a['s'] = $b['s'];
            if ($minutes($a) >= $minutes($b)) {
                $a['s'] = $flip($b['s']);
            }
        } elseif ($b['s'] === null && $a['s'] !== null && $b['h'] >= 1 && $b['h'] <= 12) {
            $b['s'] = $a['s'];
            if ($minutes($b) <= $minutes($a)) {
                $b['s'] = $flip($a['s']);
            }
        }
        if (! $this->validTime($a) || ! $this->validTime($b)) {
            return null;
        }
        [$start, $end] = [$this->hhmm($a), $this->hhmm($b)];

        return $start === $end ? null : [$start, $end];
    }

    /** "#class", "#c", "#work", "#personal" → that calendar. */
    private function calendarFrom(string $needle): ?string
    {
        if ($needle === '') {
            return null;
        }
        foreach (['class' => 'class', 'classes' => 'class', 'work' => 'work', 'personal' => 'personal'] as $word => $calendar) {
            if (str_starts_with($word, $needle)) {
                return $calendar;
            }
        }

        return null;
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
