import type { BaseTask, CriterionKey, RankedTask, RepeatRule } from '@/types/velocity';

export const CRIT: { k: CriterionKey; name: string }[] = [
    { k: 'I', name: 'Importance' },
    { k: 'U', name: 'Urgency' },
    { k: 'D', name: 'Deadline' },
    { k: 'P', name: 'Workload' },
];
export const LEVEL = ['', 'Very low', 'Low', 'Medium', 'High', 'Very high'];

export const round1 = (v: number) => Math.round((v + Number.EPSILON) * 10) / 10;
export const fmt1 = (v: number) => round1(v).toFixed(1);
export const signed = (v: number) => (round1(v) > 0 ? '+' : round1(v) < 0 ? '−' : '±') + Math.abs(round1(v)).toFixed(1);

export function fmtHours(h: number): string {
    if (h < 1) return `${Math.round(h * 60)} min`;
    const whole = Math.floor(h);
    const min = Math.round((h - whole) * 60);
    return min ? `${whole} h ${min} min` : `${whole} h`;
}
export const fmtDays = (d: number) => (d < 1 ? `${Math.round(d * 24)} h` : `${round1(d)} days`);

/** "Every day", "Every weekday", "Every 2 weeks" … (same wording as App\Services\Repeat::label). */
export const repeatText = (r: RepeatRule) =>
    r.unit === 'weekday' ? 'Every weekday' : r.every > 1 ? `Every ${r.every} ${r.unit}s` : `Every ${r.unit}`;
/** The choices in the task panel; any other rule (from quick add) is shown as an extra choice. */
export const REPEAT_CHOICES: RepeatRule[] = [
    { unit: 'day', every: 1 },
    { unit: 'weekday', every: 1 },
    { unit: 'week', every: 1 },
    { unit: 'week', every: 2 },
    { unit: 'month', every: 1 },
    { unit: 'year', every: 1 },
];

/** Dates are shown in the user's own time zone, relative to the server's clock. */
export class Clock {
    constructor(
        public readonly now: Date,
        public readonly tz: string,
    ) {}

    static from(shared: { now: string; tz: string }): Clock {
        return new Clock(new Date(shared.now), shared.tz);
    }

    private parts(d: Date) {
        const p = new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d);
        const get = (t: string) => Number(p.find((x) => x.type === t)?.value);
        return Date.UTC(get('year'), get('month') - 1, get('day'));
    }

    /** Whole calendar days from today to the date (negative = in the past). */
    dayDiff(iso: string | Date): number {
        const d = typeof iso === 'string' ? new Date(iso) : iso;
        return Math.round((this.parts(d) - this.parts(this.now)) / 864e5);
    }

    time(iso: string | Date): string {
        const d = typeof iso === 'string' ? new Date(iso) : iso;
        return new Intl.DateTimeFormat('en-US', { timeZone: this.tz, hour: 'numeric', minute: '2-digit', hour12: true })
            .format(d)
            .toLowerCase()
            .replace(' ', ' ');
    }

    weekday(iso: string | Date, style: 'short' | 'long' = 'short'): string {
        const d = typeof iso === 'string' ? new Date(iso) : iso;
        return new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, weekday: style }).format(d);
    }

    date(iso: string | Date, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' }): string {
        const d = typeof iso === 'string' ? new Date(iso) : iso;
        return new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, ...opts }).format(d);
    }

    /** "Monday, 9 November" */
    today(): string {
        return this.date(this.now, { weekday: 'long', day: 'numeric', month: 'long' });
    }

    greeting(): string {
        const h = Number(new Intl.DateTimeFormat('en-GB', { timeZone: this.tz, hour: 'numeric', hourCycle: 'h23' }).format(this.now));
        return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
    }

    /** For <input type="datetime-local">: "2026-11-11T23:59" in the user's zone. */
    localInput(iso: string | null): string {
        if (!iso) return '';
        const p = new Intl.DateTimeFormat('en-CA', {
            timeZone: this.tz,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(new Date(iso));
        const g = (t: string) => p.find((x) => x.type === t)?.value ?? '00';
        return `${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}`;
    }
}

export interface DueInfo {
    text: string;
    cls: '' | 'is-overdue' | 'is-today';
    long: string;
    late: boolean;
}

export function dueInfo(task: Pick<BaseTask, 'dueAt'> & { days?: number | null }, clock: Clock): DueInfo {
    if (!task.dueAt) return { text: 'No due date', cls: '', long: 'No due date', late: false };
    const d = clock.dayDiff(task.dueAt);
    const time = clock.time(task.dueAt);
    const full = `${clock.weekday(task.dueAt)}, ${clock.date(task.dueAt)} · ${time}`;
    const overdue = task.days != null ? task.days <= 0 : new Date(task.dueAt) <= clock.now;
    if (overdue)
        return {
            text: d === -1 ? 'Overdue · yesterday' : d === 0 ? 'Overdue · today' : `Overdue · ${-d} days`,
            cls: 'is-overdue',
            long: `Overdue · was ${full}`,
            late: true,
        };
    if (d === 0) return { text: `Today, ${time}`, cls: 'is-today', long: `Today · ${time}`, late: false };
    if (d === 1) return { text: `Tomorrow, ${time}`, cls: '', long: `Tomorrow · ${time}`, late: false };
    if (d < 7) return { text: `${clock.weekday(task.dueAt)}, ${time}`, cls: '', long: full, late: false };
    return { text: `${clock.weekday(task.dueAt)} ${clock.date(task.dueAt)}`, cls: '', long: full, late: false };
}

export const goalVar = (color: number | null | undefined) => (color ? `var(--goal-${color})` : 'var(--goal-0)');

export type WeightSet = Record<CriterionKey, number>;
export const DEFAULT_WEIGHTS: WeightSet = { I: 42, U: 23, D: 23, P: 12 };
export const sameWeights = (a: WeightSet, b: WeightSet) => CRIT.every((c) => a[c.k] === b[c.k]);

/** Moving one weight shares the rest among the other three in proportion, rounded so the total stays 100 (FR9). */
export function adjustWeights(base: WeightSet, key: CriterionKey, v: number): WeightSet {
    const others = CRIT.map((c) => c.k).filter((k) => k !== key);
    const rest = 100 - v;
    const sum = others.reduce((s, k) => s + base[k], 0);
    const raw = Object.fromEntries(others.map((k) => [k, sum > 0 ? (base[k] / sum) * rest : rest / others.length])) as WeightSet;
    const out = { [key]: v } as WeightSet;
    let used = v;
    others.forEach((k) => {
        out[k] = Math.floor(raw[k]);
        used += out[k];
    });
    others
        .slice()
        .sort((a, b) => raw[b] - out[b] - (raw[a] - out[a]))
        .forEach((k) => {
            if (used < 100) {
                out[k] += 1;
                used += 1;
            }
        });
    return out;
}

/** Re-ranks in the browser with S = Σ w·c while a slider moves; the server confirms when the weights are saved. */
export function rerank<T extends RankedTask>(tasks: T[], weights: WeightSet): T[] {
    const score = (t: T) => CRIT.reduce((s, c) => s + weights[c.k] * t.criteria[c.k], 0);
    return tasks
        .map((t) => ({ ...t, score: score(t), contrib: Object.fromEntries(CRIT.map((c) => [c.k, weights[c.k] * t.criteria[c.k]])) as WeightSet }))
        .sort((a, b) => b.score - a.score || (a.dueAt ?? '9999').localeCompare(b.dueAt ?? '9999') || a.estHours - b.estHours || a.id - b.id)
        .map((t, i, all) => ({ ...t, rank: i + 1, nearTie: i < all.length - 1 && round1(t.score - all[i + 1].score) < 1 }));
}
