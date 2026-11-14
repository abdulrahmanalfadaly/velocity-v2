import type { Clock } from '@/lib/velocity';
import { Icon } from './icon';

/** "2026-11-14" moved by n days. */
export const shiftDay = (d: string, n: number) => new Date(Date.parse(`${d}T12:00:00Z`) + n * 864e5).toISOString().slice(0, 10);
const at = (d: string) => new Date(`${d}T12:00:00Z`);

/** A small month in a side menu: dots on days that have something, the chosen days tinted. */
export function MonthCalendar({
    month,
    today,
    marked,
    chosen = [],
    clock,
    onMonth,
    onPick,
    disabled,
}: {
    /** "2026-11" */
    month: string;
    today: string;
    marked: Set<string>;
    chosen?: string[];
    clock: Clock;
    onMonth: (month: string) => void;
    onPick: (date: string) => void;
    /** Days that can't be picked (before or after an allowed range). */
    disabled?: (date: string) => boolean;
}) {
    const first = `${month}-01`;
    const lead = (at(first).getUTCDay() + 6) % 7; // Monday first
    const cells = Array.from({ length: 42 }, (_, i) => shiftDay(first, i - lead));
    const shown = new Set(chosen);
    const name = clock.date(at(first), { month: 'long', year: 'numeric', timeZone: 'UTC' });
    return (
        <div className="mcal" aria-label={name}>
            <div className="mcal-head">
                <b>{name}</b>
                <span>
                    <button type="button" className="icon-btn" aria-label="Previous month" onClick={() => onMonth(shiftDay(first, -1).slice(0, 7))}>
                        <Icon name="chevL" className="ic-sm" />
                    </button>
                    <button type="button" className="icon-btn" aria-label="Next month" onClick={() => onMonth(shiftDay(first, 32).slice(0, 7))}>
                        <Icon name="chevR" className="ic-sm" />
                    </button>
                </span>
            </div>
            <div className="mcal-grid">
                {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d, i) => (
                    <span key={i} className="mcal-dow">
                        {d}
                    </span>
                ))}
                {cells.map((d) => (
                    <button
                        key={d}
                        type="button"
                        className={`mcal-day${d.slice(0, 7) !== month ? ' is-out' : ''}${d === today ? ' is-today' : ''}${marked.has(d) ? ' has-due' : ''}${shown.has(d) ? ' is-shown' : ''}`}
                        disabled={disabled?.(d)}
                        onClick={() => onPick(d)}
                        aria-label={clock.date(at(d), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })}
                        aria-current={shown.has(d) ? 'date' : undefined}
                    >
                        {Number(d.slice(8))}
                    </button>
                ))}
            </div>
        </div>
    );
}
