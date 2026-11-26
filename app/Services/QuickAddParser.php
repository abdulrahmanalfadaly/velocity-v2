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

    /** "remind 1h before" and the like: the "before due" reminders there are. */
    private const BEFORE = ['15m' => '15m', '15min' => '15m', '1h' => '1h', '1hr' => '1h', '3h' => '3h', '3hr' => '3h', '1d' => '1d', '2d' => '2d', '7d' => '7d', '1w' => '7d'];

    /**
     * $plain: words to keep as words (a chip taken off in quick add), lower case.
     *
     * @param  array<int, string>  $projects  id => name
     * @param  list<string>  $plain
     * @return array{title: string, due: ?CarbonImmutable, hours: ?float, projectId: ?int, repeat: ?array{unit: string, every: int}, start: ?CarbonImmutable, remind: ?array, importance: ?int, picked: list<array{kind: string, text: string}>}
     */
    public function parse(string $text, CarbonImmutable $nowLocal, array $projects = [], array $plain = [], array $people = []): array
    {
        $s = $this->scan($text, $nowLocal, $projects, false, $plain, $people);
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

        // "remind 9am": at nine on the due day, or today (tomorrow once it has passed); "remind fri 9am"; "remind 1h before".
        $remind = null;
        if ($s['remind'] && isset($s['remind']['before'])) {
            $remind = ['kind' => 'before', 'before' => $s['remind']['before']];
        } elseif ($s['remind']) {
            $at = ($s['remind']['day'] ?? $due?->startOfDay() ?? $nowLocal->startOfDay())->setTimeFromTimeString($s['remind']['time'] ?? '09:00');
            if ($s['remind']['day'] === null && $due === null && $at->lessThanOrEqualTo($nowLocal)) {
                $at = $at->addDay();
            }
            $remind = ['kind' => 'at', 'at' => $at];
        }

        return ['title' => $s['title'], 'due' => $due, 'hours' => $s['hours'], 'projectId' => $s['projectId'], 'repeat' => $s['repeat'],
            'start' => $s['start'], 'remind' => $remind, 'importance' => $s['importance'], 'energy' => $s['energy'],
            'waiting' => $s['waiting'], 'followUp' => $s['followUp'], 'picked' => $s['picked']];
    }

    /**
     * An event from one line: "Dentist tomorrow 3pm 1h", "Quiz fri 9-10am #class", "Gym every mon 6pm".
     * No time means all day; a start with no end lasts the hours given, or an hour.
     *
     * @return array{title: string, date: string, start: ?string, end: ?string, allDay: bool, areaId: ?int, weekly: bool, picked: list<array{kind: string, text: string}>}
     */
    public function parseEvent(string $text, CarbonImmutable $nowLocal, array $areas = []): array
    {
        $s = $this->scan($text, $nowLocal, $areas, true);
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
            'areaId' => $s['areaId'],
            'weekly' => $s['repeat'] !== null,
            'until' => $s['repeat']['until'] ?? null,
            'picked' => $s['picked'],
        ];
    }

    /**
     * The words, one by one: what each recognised word means, and the rest as the title.
     * In event mode "#uni" picks the area (its names come in as $projects) and only weekly repeats count.
     *
     * Tasks also take "from mon" (the start date), "remind …" and "!" / "!!" (importance).
     *
     * @param  array<int, string>  $projects
     * @param  list<string>  $plain
     * @return array{title: string, day: ?CarbonImmutable, time: ?string, end: ?string, hours: ?float, projectId: ?int, areaId: ?int, repeat: ?array{unit: string, every: int}, repeatDay: ?int, start: ?CarbonImmutable, remind: ?array, importance: ?int, picked: list<array{kind: string, text: string}>}
     */
    private function scan(string $text, CarbonImmutable $nowLocal, array $projects, bool $event, array $plain = [], array $people = []): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $keep = [];
        $day = null;
        $time = null;
        $end = null;
        $hours = null;
        $hoursAt = null; // where the last "2h" was, so "1h 30m" adds up
        $projectId = null;
        $areaId = null;
        $until = null;
        $repeat = null;
        $repeatDay = null;
        $start = null;
        $remind = null;
        $importance = null;
        $energy = null;
        $waiting = null;
        $followUp = null;
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

            // A chip that was taken off: the word stays in the title.
            if ($plain && in_array($lw, $plain, true)) {
                $keep[] = $w;

                continue;
            }
            // Follow-ups: "follow up fri", "f/u tomorrow" (a day to check on it); "waiting Dr Lim", "waiting for HR", "wait for Aisha".
            $fuAt = $lw === 'follow' && $w1 === 'up' ? $i + 2 : (in_array($lw, ['f/u', 'followup', 'follow-up'], true) ? $i + 1 : null);
            if (! $event && $fuAt !== null && ($fu = $this->dateAt($words, $fuAt, $nowLocal, $plain)) !== null) {
                $followUp = $fu[0];
                $used = $fuAt - $i + $fu[1];
                $picked[] = ['kind' => 'followUp', 'text' => implode(' ', array_slice($words, $i, $used))];
                $i += $used - 1;

                continue;
            }
            if (! $event && $waiting === null && ($lw === 'waiting' || ($lw === 'wait' && in_array($w1, ['for', 'on'], true)))
                && ($wf = $this->waitingFor($words, $i + 1, $nowLocal, $people)) !== null) {
                $waiting = $wf['who'];
                $picked[] = ['kind' => 'waiting', 'text' => implode(' ', array_slice($words, $i, $wf['n'] + 1))];
                $i += $wf['n'];

                continue;
            }
            // Repeats: only "every …" counts, so a title like "Weekly report" stays as it is.
            if ($lw === 'every' && $repeat === null && $repeatWord) {
                if (isset(self::DAYS[$w1])) {
                    $days = [self::DAYS[$w1]];
                    $text = $w.' '.$words[++$i];
                    // Tasks: more weekdays ("every mon and thu", "every mon, wed, fri"). Events repeat on their own day.
                    while (! $event) {
                        $n1 = mb_strtolower(rtrim($words[$i + 1] ?? '', ',.'));
                        $n2 = mb_strtolower(rtrim($words[$i + 2] ?? '', ',.'));
                        if (isset(self::DAYS[$n1])) {
                            $days[] = self::DAYS[$n1];
                            $text .= ' '.$words[++$i];
                        } elseif (in_array($n1, ['and', '&'], true) && isset(self::DAYS[$n2])) {
                            $days[] = self::DAYS[$n2];
                            $text .= ' '.$words[++$i].' '.$words[++$i];
                        } else {
                            break;
                        }
                    }
                    $days = array_values(array_unique($days));
                    sort($days);
                    $repeat = ['unit' => 'week', 'every' => 1] + (count($days) > 1 ? ['days' => $days] : []);
                    // The first one is the soonest of those days (today counts).
                    usort($days, fn ($a, $b) => (($a - $nowLocal->dayOfWeekIso + 7) % 7) <=> (($b - $nowLocal->dayOfWeekIso + 7) % 7));
                    $repeatDay = $days[0];
                    $picked[] = ['kind' => 'repeat', 'text' => $text];
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
            } elseif (preg_match('/^\d+(?:\.\d+)?$/', $lw) && in_array($w1, ['h', 'hr', 'hrs', 'hour', 'hours'], true) && (float) $lw >= 0.05 && (float) $lw <= 500) {
                // "2 hours", "1.5 hr": the number and its unit as two words.
                $hours = (float) $lw;
                $picked[] = ['kind' => 'hours', 'text' => $w.' '.$words[++$i]];
                $hoursAt = $i;
            } elseif (preg_match('/^\d+$/', $lw) && in_array($w1, ['min', 'mins', 'minute', 'minutes'], true) && (int) $lw >= 3 && (int) $lw <= 30000) {
                // "30 min", "45 minutes"; after "1 hour" it adds up ("1 hour 30 min").
                if ($hoursAt === $i - 1 && (int) $lw < 60) {
                    $hours = round($hours + ((int) $lw) / 60, 2);
                    $picked[count($picked) - 1]['text'] .= ' '.$w.' '.$words[++$i];
                } else {
                    $hours = round(((int) $lw) / 60, 2);
                    $picked[] = ['kind' => 'hours', 'text' => $w.' '.$words[++$i]];
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
            } elseif (! $event && preg_match('/^!{1,3}$/', $w)) {
                $importance = $w === '!' ? 4 : 5; // "!" high, "!!" very high
                $picked[] = ['kind' => 'importance', 'text' => $w];
            } elseif (! $event && preg_match('/^~(low|normal|high)$/', $lw, $m)) {
                $energy = $m[1]; // "~low": the energy it needs (a filter only)
                $picked[] = ['kind' => 'energy', 'text' => $w];
            } elseif (! $event && $lw === 'from' && ($first = $this->dateAt($words, $i + 1, $nowLocal, $plain)) !== null) {
                // "from mon": it can start then (until then it isn't ranked).
                $start = $first[0];
                $picked[] = ['kind' => 'start', 'text' => implode(' ', array_slice($words, $i, $first[1] + 1))];
                $i += $first[1];
            } elseif (! $event && in_array($lw, ['remind', 'reminder'], true) && ($r = $this->remindAt($words, $i + 1, $nowLocal, $plain)) !== null) {
                $remind = $r;
                $picked[] = ['kind' => 'remind', 'text' => implode(' ', array_slice($words, $i, $r['n'] + 1))];
                $i += $r['n'];
            } elseif ($lw === 'until' && $repeat !== null && ($last = $this->dateAt($words, $i + 1, $nowLocal)) !== null) {
                // "every mon until 20 dec": the last day it repeats.
                $until = $last[0];
                $picked[] = ['kind' => 'repeat', 'text' => implode(' ', array_slice($words, $i, $last[1] + 1))];
                $i += $last[1];
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
            } elseif ($event && str_starts_with($lw, '#') && ($cal = $this->areaFrom(mb_substr($lw, 1), $projects)) !== null) {
                $areaId = $cal;
                $picked[] = ['kind' => 'area', 'text' => $w];
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
            } elseif (in_array($lw, ['at', 'from'], true) && ! in_array($w1, $plain, true) && ($this->clockAt($words, $i + 1) || $this->rangeWord($w1))) {
                continue; // "at 3pm", "from 9-10am": the time is read on the next word
            } else {
                $keep[] = $w;
            }
        }

        return ['title' => trim(implode(' ', $keep)), 'day' => $day, 'time' => $time, 'end' => $end, 'hours' => $hours, 'projectId' => $projectId,
            'areaId' => $areaId, 'repeat' => $repeat && $until ? $repeat + ['until' => $until->toDateString()] : $repeat, 'repeatDay' => $repeatDay,
            'start' => $start, 'remind' => $remind, 'importance' => $importance, 'energy' => $energy,
            'waiting' => $waiting, 'followUp' => $followUp, 'picked' => $picked];
    }

    /**
     * Who follows "waiting" ("for"/"on" skipped): someone in People when the words spell their name or
     * nickname (the longest one wins; their full name comes back), else the next word and the capitalised
     * ones after it ("Dr Lim", "HR"), up to four, never a date.
     *
     * @param  list<array{name: string, nickname: ?string}>  $people
     * @return array{who: string, n: int}|null n = words used
     */
    private function waitingFor(array $words, int $j, CarbonImmutable $now, array $people): ?array
    {
        $start = $j;
        if (in_array(mb_strtolower(rtrim($words[$j] ?? '', ',.:')), ['for', 'on'], true)) {
            $j++;
        }
        $bare = fn (string $w) => mb_strtolower(trim(preg_replace('/[.,:;]+/u', ' ', $w)));
        $rest = array_slice($words, $j);
        if (! $rest) {
            return null;
        }
        $best = null;
        foreach ($people as $p) {
            foreach (array_filter([$p['name'], $p['nickname'] ?? null]) as $name) {
                $parts = preg_split('/\s+/u', $bare($name)) ?: [];
                $n = count($parts);
                if ($n && $n <= count($rest) && ($best === null || $n > $best['words'])
                    && preg_split('/\s+/u', $bare(implode(' ', array_slice($rest, 0, $n)))) === $parts) {
                    $best = ['who' => $p['name'], 'words' => $n];
                }
            }
        }
        if ($best) {
            return ['who' => $best['who'], 'n' => $j - $start + $best['words']];
        }
        $isDay = fn (int $k) => $this->dateAt($words, $k, $now) !== null || preg_match('/^[#!~]/u', $words[$k] ?? '') === 1;
        if ($isDay($j)) {
            return null;
        }
        $take = [rtrim($words[$j], ',.:;')];
        // "the bank", "my landlord": the word after a little one too.
        if (in_array(mb_strtolower($take[0]), ['the', 'a', 'an', 'my', 'our'], true) && isset($words[$j + 1]) && ! $isDay($j + 1)) {
            $take[] = rtrim($words[$j + 1], ',.:;');
        }
        for ($k = $j + count($take); $k < count($words) && count($take) < 4; $k++) {
            $w = $words[$k];
            $lw = mb_strtolower(rtrim($w, ',.'));
            if ($isDay($k) || in_array($lw, ['follow', 'f/u', 'followup', 'remind', 'from', 'every'], true) || ! preg_match('/^\p{Lu}/u', $w)) {
                break;
            }
            $take[] = rtrim($w, ',.:;');
            if (preg_match('/[,;:]$/u', $w)) {
                break;
            }
        }

        return ['who' => implode(' ', $take), 'n' => $j - $start + count($take)];
    }

    /**
     * What follows "remind": "9am", "at 9am", "fri", "fri 9am", "tomorrow at 8:30", "me 9am", "1h before".
     *
     * @return array{day?: ?CarbonImmutable, time?: ?string, before?: string, n: int}|null n = words used
     */
    private function remindAt(array $words, int $j, CarbonImmutable $now, array $plain): ?array
    {
        $at = $j;
        $word = fn (int $k) => mb_strtolower(rtrim($words[$k] ?? '', ',.'));
        if ($word($at) === 'me') {
            $at++;
        }
        if (isset(self::BEFORE[$word($at)]) && $word($at + 1) === 'before' && ! in_array($word($at), $plain, true)) {
            return ['before' => self::BEFORE[$word($at)], 'n' => $at + 2 - $j];
        }
        $day = $this->dateAt($words, $at, $now, $plain);
        if ($day) {
            $at += $day[1];
        }
        $skip = $word($at) === 'at' ? 1 : 0;
        $clock = in_array($word($at + $skip), $plain, true) ? null : $this->clockAt($words, $at + $skip);
        if ($clock) {
            $at += $skip + $clock['n'];
        }
        if (! $day && ! $clock) {
            return null;
        }

        return ['day' => $day[0] ?? null, 'time' => $clock ? $this->hhmm($clock) : null, 'n' => $at - $j];
    }

    /**
     * A date at word $j for "until …", "from …", "remind …": "20 dec", "dec 20", "20/12", a weekday, "next fri", today, tomorrow.
     *
     * @return array{0: CarbonImmutable, 1: int}|null [day, words used]
     */
    private function dateAt(array $words, int $j, CarbonImmutable $now, array $plain = []): ?array
    {
        $a = mb_strtolower(rtrim($words[$j] ?? '', ',.'));
        $b = mb_strtolower(rtrim($words[$j + 1] ?? '', ',.'));
        if ($a === '' || in_array($a, $plain, true)) {
            return null;
        }
        if (in_array($a, ['today', 'tonight'], true)) {
            return [$now->startOfDay(), 1];
        }
        if (in_array($a, ['tomorrow', 'tmr', 'tmrw', 'tomoro'], true)) {
            return [$now->startOfDay()->addDay(), 1];
        }
        if ($a === 'next' && isset(self::DAYS[$b])) {
            return [$this->nextWeekday($now, self::DAYS[$b])->addWeek(), 2];
        }
        if (preg_match('/^(\d{1,2})$/', $a, $m) && ($month = $this->month($b)) !== null && $this->realDate((int) $m[1], $month)) {
            return [$this->dateInYear($now, (int) $m[1], $month), 2];
        }
        if (($month = $this->month($a)) !== null && preg_match('/^(\d{1,2})$/', $b, $m) && $this->realDate((int) $m[1], $month)) {
            return [$this->dateInYear($now, (int) $m[1], $month), 2];
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $a, $m) && $this->realDate((int) $m[1], (int) $m[2])) {
            return [$this->dateInYear($now, (int) $m[1], (int) $m[2]), 1];
        }
        if (isset(self::DAYS[$a])) {
            return [$this->nextWeekday($now, self::DAYS[$a]), 1];
        }

        return null;
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

    /**
     * "#uni", "#work" → the area whose name starts with those letters (spaces and signs ignored), or whose
     * capitals spell them; null when none does (then the word stays in the title).
     *
     * @param  array<int, string>  $areas  id => name
     */
    private function areaFrom(string $needle, array $areas): ?int
    {
        $needle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $needle) ?? '');
        if ($needle === '') {
            return null;
        }
        foreach ($areas as $id => $name) {
            $slug = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '');
            $initials = mb_strtolower(preg_replace('/[^\p{Lu}]/u', '', $name) ?? '');
            if (str_starts_with($slug, $needle) || ($initials !== '' && $initials === $needle)) {
                return (int) $id;
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
