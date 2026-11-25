import type { Clock } from '@/lib/velocity';
import type { FollowUpTask } from '@/types/velocity';
import { Link, router } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { DatePick } from './date-pick';
import { Pop } from './habit-parts';
import { Icon } from './icon';

/*
 * Follow-ups (user, 24 Nov 2026: "i really need it alot"): Home's own card, and the buttons it shares
 * with Not ready and the task panel. "Done" = you followed up (counted): again on a day, or no next
 * day; or the reply came (it stops waiting). "Later" only moves the day. Everything has Undo (toast).
 */

const keep = { preserveScroll: true, preserveState: true };
const W = 252;

/** The user's own today, "2026-11-24". */
export const localToday = (clock: Clock) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: clock.tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(clock.now);
const addDays = (d: string, n: number) => new Date(Date.parse(`${d}T12:00:00Z`) + n * 864e5).toISOString().slice(0, 10);
/** The Monday after (a week on when today is Monday). */
const nextMonday = (d: string) => addDays(d, (8 - new Date(`${d}T12:00:00Z`).getUTCDay()) % 7 || 7);
const days = (n: number) => (n === 1 ? '1 day' : `${n} days`);

/** "Today", "Tomorrow", "Thu 26 Nov". */
export function dayLabel(d: string, today: string) {
    if (d === today) return 'Today';
    if (d === addDays(today, 1)) return 'Tomorrow';
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(`${d}T12:00:00Z`));
}

/** A pop-up under the button that opened it, kept on the screen. */
function usePop() {
    const [at, setAt] = useState<null | { x: number; y: number }>(null);
    const open = (el: HTMLElement) => {
        if (at) return setAt(null);
        const r = el.getBoundingClientRect();
        setAt({ x: Math.max(8, Math.min(r.left, window.innerWidth - W - 8)), y: Math.min(r.bottom + 6, window.innerHeight - 330) });
    };
    return { at, open, close: () => setAt(null) };
}

const TITLES = ['dr', 'prof', 'professor', 'mr', 'mrs', 'ms', 'miss', 'mx', 'sir', 'madam', 'puan', 'encik', 'cik', 'tuan', 'en', 'pn'];

/** What to call them in a message: "Dr. Lim Wei" → "Dr. Lim", "Aisha Rahman" → "Aisha", "HR" → "HR". */
function callName(who: string) {
    const words = who.trim().split(/\s+/);
    return words.length > 1 && TITLES.includes(words[0].toLowerCase().replace(/\.$/, '')) ? `${words[0]} ${words[1]}` : words[0];
}

/**
 * A short, polite check-in to paste into WhatsApp or an email (no AI: a fixed wording around the task):
 * "Hi Dr. Lim, just following up on the ethics form. Is there any update? Thank you!"
 */
export function nudgeText(title: string, who: string | null | undefined) {
    const t = title.trim().replace(/[.!?]+$/, '');
    // "Email Dr. Lim about the ethics form" → "the ethics form"; "Follow up: the grant" → "the grant".
    const about = t.match(/\b(?:about|regarding|re:?|on)\s+(.+)$/i)?.[1] ?? t.match(/^follow[ -]?up:\s*(.+)$/i)?.[1] ?? null;
    const what = about ?? (/^follow[ -]?up with\b/i.test(t) ? 'my last message' : `“${t}”`);
    return `Hi${who ? ` ${callName(who)}` : ''}, just following up on ${what}. Is there any update? Thank you!`;
}

/** Copies text; the old way when the browser won't let the page use the clipboard. */
export async function copyText(text: string) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const box = document.createElement('textarea');
        box.value = text;
        document.body.appendChild(box);
        box.select();
        document.execCommand('copy');
        box.remove();
    }
}

function Item({ icon, children, onClick }: { icon: string; children: ReactNode; onClick: () => void }) {
    return (
        <button type="button" className="bmenu-item" onClick={onClick}>
            <Icon name={icon} />
            <span className="bmenu-grow">{children}</span>
        </button>
    );
}

/** "Done": you followed up. Check again in 2 days, a week, on a day, or not; or the reply came. */
export function FollowedUp({
    task,
    clock,
    small = true,
}: {
    task: Pick<FollowUpTask, 'id' | 'title' | 'status'> & { who?: string | null; waitingFor?: string | null };
    clock: Clock;
    small?: boolean;
}) {
    const pop = usePop();
    const today = localToday(clock);
    const [copied, setCopied] = useState(false);
    const done = (again: string | null) => {
        pop.close();
        router.post(route('follow-ups.done', task.id), { again }, keep);
    };
    return (
        <>
            <button
                type="button"
                className={`btn btn-primary${small ? ' btn-sm' : ''} pop-anchor`}
                aria-haspopup="menu"
                aria-expanded={!!pop.at}
                onClick={(e) => {
                    e.stopPropagation();
                    pop.open(e.currentTarget);
                }}
            >
                <Icon name="check" />
                Done
            </button>
            {pop.at && (
                <Pop x={pop.at.x} y={pop.at.y} label={`Followed up: ${task.title}`} onClose={pop.close} width={W}>
                    <div className="bmenu-head">
                        <b>Followed up</b>
                        <span>Check again</span>
                    </div>
                    <Item icon="calendar" onClick={() => done(addDays(today, 2))}>
                        In 2 days
                    </Item>
                    <Item icon="calendar" onClick={() => done(addDays(today, 7))}>
                        In a week
                    </Item>
                    <div className="fu-pick">
                        <DatePick
                            label="Again on"
                            placeholder="Pick a day…"
                            value=""
                            min={addDays(today, 1)}
                            clearable={false}
                            onChange={(v) => v && done(v)}
                        />
                    </div>
                    <Item icon="x" onClick={() => done(null)}>
                        No next day
                    </Item>
                    <div className="bmenu-sep" />
                    <Item
                        icon="message"
                        onClick={() => {
                            pop.close();
                            router.post(route('follow-ups.replied', task.id), {}, keep);
                        }}
                    >
                        {task.status === 'waiting' ? 'Got a reply · stop waiting' : 'Got a reply'}
                    </Item>
                    <div className="bmenu-sep" />
                    <Item
                        icon={copied ? 'check' : 'copy'}
                        onClick={async () => {
                            await copyText(nudgeText(task.title, task.who ?? task.waitingFor));
                            setCopied(true);
                            setTimeout(() => setCopied(false), 2000);
                        }}
                    >
                        {copied ? 'Copied' : 'Copy a check-in message'}
                    </Item>
                </Pop>
            )}
        </>
    );
}

/** "Later" (a day that has come) or "Set a day" (waiting with none): only moves the follow-up day. */
export function MoveDay({ task, clock, label = 'Later' }: { task: Pick<FollowUpTask, 'id' | 'title'>; clock: Clock; label?: string }) {
    const pop = usePop();
    const today = localToday(clock);
    const set = (d: string) => {
        pop.close();
        router.patch(route('tasks.update', task.id), { follow_up_on: d }, keep);
    };
    return (
        <>
            <button
                type="button"
                className="btn btn-secondary btn-sm pop-anchor"
                aria-haspopup="menu"
                aria-expanded={!!pop.at}
                onClick={(e) => {
                    e.stopPropagation();
                    pop.open(e.currentTarget);
                }}
            >
                <Icon name={label === 'Later' ? 'clock' : 'calendar'} />
                {label}
            </button>
            {pop.at && (
                <Pop x={pop.at.x} y={pop.at.y} label={`Follow up on: ${task.title}`} onClose={pop.close} width={W}>
                    <div className="bmenu-head">
                        <b>Follow up on</b>
                    </div>
                    <Item icon="calendar" onClick={() => set(addDays(today, 1))}>
                        Tomorrow
                    </Item>
                    <Item icon="calendar" onClick={() => set(addDays(today, 3))}>
                        In 3 days
                    </Item>
                    <Item icon="calendar" onClick={() => set(nextMonday(today))}>
                        Next Monday
                    </Item>
                    <div className="fu-pick">
                        <DatePick
                            label="Follow up on"
                            placeholder="Pick a day…"
                            value=""
                            min={today}
                            clearable={false}
                            onChange={(v) => v && set(v)}
                        />
                    </div>
                </Pop>
            )}
        </>
    );
}

/** "+": a new follow-up — what, who (your People are suggested), which day. */
function NewFollowUp({ clock, people, after }: { clock: Clock; people: string[]; after: number }) {
    const pop = usePop();
    const today = localToday(clock);
    const [title, setTitle] = useState('');
    const [who, setWho] = useState('');
    const [on, setOn] = useState(addDays(today, after || 3));
    const [error, setError] = useState<string | null>(null);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!title.trim()) return setError('Say what you are waiting on.');
        router.post(
            route('follow-ups.store'),
            { title: title.trim(), who: who.trim() || null, on },
            {
                ...keep,
                onSuccess: () => {
                    pop.close();
                    setTitle('');
                    setWho('');
                    setError(null);
                },
                onError: (errs) => setError(Object.values(errs)[0] ?? 'Could not add it.'),
            },
        );
    };
    return (
        <>
            <button
                type="button"
                className="icon-btn pop-anchor"
                aria-label="New follow-up"
                title="New follow-up"
                aria-expanded={!!pop.at}
                onClick={(e) => pop.open(e.currentTarget)}
            >
                <Icon name="plus" />
            </button>
            {pop.at && (
                <Pop
                    x={Math.max(8, Math.min(pop.at.x, window.innerWidth - W - 56))}
                    y={pop.at.y}
                    label="New follow-up"
                    onClose={pop.close}
                    width={W + 48}
                >
                    <form className="fu-new" onSubmit={submit}>
                        <div className="bmenu-head">
                            <b>New follow-up</b>
                        </div>
                        <input
                            className="input"
                            aria-label="What are you waiting on?"
                            placeholder="What are you waiting on?"
                            maxLength={255}
                            autoFocus
                            value={title}
                            onChange={(e) => {
                                setTitle(e.target.value);
                                setError(null);
                            }}
                        />
                        <input
                            className="input"
                            aria-label="Waiting for"
                            placeholder="Who?"
                            maxLength={160}
                            list="fu-people"
                            value={who}
                            onChange={(e) => setWho(e.target.value)}
                        />
                        <datalist id="fu-people">
                            {people.map((p) => (
                                <option key={p} value={p} />
                            ))}
                        </datalist>
                        <DatePick label="Follow up on" value={on} min={today} clearable={false} onChange={(v) => v && setOn(v)} />
                        {error && <p className="field-error">{error}</p>}
                        <button type="submit" className="btn btn-primary btn-sm">
                            Add follow-up
                        </button>
                    </form>
                </Pop>
            )}
        </>
    );
}

export type FollowUpCard = { due: FollowUpTask[]; waiting: FollowUpTask[]; soon: FollowUpTask[]; later: number; people: string[]; after: number };

/** The line under a follow-up's title: "2 days late · waiting for Dr. Lim, 9 days · followed up 2×". */
function sub(t: FollowUpTask, today: string, withDay: boolean) {
    const parts: ReactNode[] = [];
    if (withDay && t.late != null) {
        parts.push(
            t.late > 0 ? (
                <span key="l" className="fu-late">
                    {t.late === 1 ? '1 day late' : `${t.late} days late`}
                </span>
            ) : (
                dayLabel(t.followUpOn!, today)
            ),
        );
    }
    if (t.status === 'waiting') parts.push(`waiting${t.who ? ` for ${t.who}` : ''}${t.waitingDays ? `, ${days(t.waitingDays)}` : ''}`);
    else if (t.who) parts.push(`with ${t.who}`);
    if (t.nudges > 0) parts.push(`followed up ${t.nudges}×`);
    return parts.flatMap((p, i) => (i ? [' · ', p] : [p]));
}

/** One follow-up: who, what, how late and how long; its buttons on a line of their own (the card is narrow), or a day on the right. */
function Row({
    t,
    today,
    children,
    side,
    withDay = true,
}: {
    t: FollowUpTask;
    today: string;
    children?: ReactNode;
    side?: ReactNode;
    withDay?: boolean;
}) {
    return (
        <div className="fu-row">
            <span className="fu-av" aria-hidden="true">
                {t.initials ?? <Icon name={t.status === 'waiting' ? 'hourglass' : 'followUp'} />}
            </span>
            <div className="fu-main">
                <Link className="fu-title" href={`/tasks?task=${t.id}`}>
                    {t.title}
                </Link>
                <div className="fu-sub">{sub(t, today, withDay)}</div>
                {children && <div className="fu-acts">{children}</div>}
            </div>
            {side}
        </div>
    );
}

/** Home's Follow-ups card: today and late first, then waiting with no day, then the coming week. */
export function FollowUpsCard({ data, clock }: { data: FollowUpCard; clock: Clock }) {
    const today = localToday(clock);
    const empty = !data.due.length && !data.waiting.length && !data.soon.length;
    return (
        <section className="card fu-card" aria-labelledby="fu-title">
            <div className="card-head">
                <h2 className="card-title" id="fu-title">
                    <Icon name="followUp" />
                    Follow-ups
                </h2>
                <div className="fu-head-end">
                    {data.due.length > 0 && <span className="card-sub">{data.due.length} today</span>}
                    <NewFollowUp clock={clock} people={data.people} after={data.after} />
                </div>
            </div>
            {empty && <p className="card-empty">Nothing to follow up.</p>}
            {data.due.length > 0 && (
                <div className="fu-group">
                    <h3 className="fu-group-title">Today and late</h3>
                    {data.due.map((t) => (
                        <Row key={t.id} t={t} today={today}>
                            <FollowedUp task={t} clock={clock} />
                            <MoveDay task={t} clock={clock} />
                        </Row>
                    ))}
                </div>
            )}
            {data.waiting.length > 0 && (
                <div className="fu-group">
                    <h3 className="fu-group-title">Waiting, no day set</h3>
                    {data.waiting.map((t) => (
                        <Row key={t.id} t={t} today={today}>
                            <MoveDay task={t} clock={clock} label="Set a day" />
                        </Row>
                    ))}
                </div>
            )}
            {data.soon.length > 0 && (
                <div className="fu-group">
                    <h3 className="fu-group-title">Coming up</h3>
                    {data.soon.map((t) => (
                        <Row
                            key={t.id}
                            t={t}
                            today={today}
                            withDay={false}
                            side={<span className="fu-when">{dayLabel(t.followUpOn!, today)}</span>}
                        />
                    ))}
                </div>
            )}
            <div className="card-foot">
                {data.later > 0 && <span className="card-sub">{data.later} more later</span>}
                <Link className="card-link" href="/tasks/waiting">
                    Open Not ready
                    <Icon name="arrowR" className="ic-xs" />
                </Link>
            </div>
        </section>
    );
}
