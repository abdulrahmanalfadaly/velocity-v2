import type { Clock } from '@/lib/velocity';
import { router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Pop } from './habit-parts';
import { Icon } from './icon';
import { Select } from './select';

export type ContactKind = 'email' | 'phone' | 'link' | 'other';
export type DateKind = 'birthday' | 'anniversary' | 'other';
export type CatchUpKind = 'message' | 'call' | 'met' | 'other';

export interface PersonDate {
    id: number;
    personId: number;
    name: string;
    kind: DateKind;
    label: string | null;
    title: string;
    month: number;
    day: number;
    year: number | null;
    yearly: boolean;
    remindDays: number | null;
    /** The next time it comes round; null for a one-off date that has passed. */
    next: string | null;
    days: number | null;
    turns: number | null;
}

export interface PersonRow {
    id: number;
    name: string;
    initials: string;
    relationship: string | null;
    organisation: string | null;
    groups: { id: number; name: string }[];
    date: PersonDate | null;
    /** late: days past the catch-up date (0 = today); negative = still to come. */
    catchUp: { next: string; late: number } | null;
    attention: boolean;
    archived: boolean;
}

export interface PersonForm {
    name: string;
    relationship: string | null;
    organisation: string | null;
    catch_up_days: number | null;
    contacts: { kind: ContactKind; label: string | null; value: string }[];
    group_ids: number[];
}

export interface PersonDetail extends PersonRow {
    about: string | null;
    contacts: PersonForm['contacts'];
    dates: PersonDate[];
    keepInTouch: {
        every: number | null;
        next: string | null;
        last: string | null;
        history: { id: number; on: string; kind: CatchUpKind; note: string | null }[];
    };
    tasks: { id: number; title: string; status: string; rank: number | null }[];
    notes: { id: number; title: string }[];
    projects: { id: number; name: string }[];
    form: PersonForm;
}

export const keep = { preserveScroll: true, preserveState: true };
export const DATE_ICON: Record<DateKind, string> = { birthday: 'gift', anniversary: 'heart', other: 'calendar' };
export const CATCH_ICON: Record<CatchUpKind, string> = { message: 'message', call: 'phone', met: 'coffee', other: 'person' };
const CATCH_LABEL: Record<CatchUpKind, string> = { message: 'Message', call: 'Call', met: 'Met up', other: 'Other' };
const CONTACT_ICON: Record<ContactKind, string> = { email: 'mail', phone: 'phone', link: 'link', other: 'info' };
const CONTACT_LABEL: Record<ContactKind, string> = { email: 'Email', phone: 'Phone', link: 'Link', other: 'Other' };
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const EVERY: [number, string][] = [
    [7, 'Every week'],
    [14, 'Every 2 weeks'],
    [30, 'Every month'],
    [90, 'Every 3 months'],
    [180, 'Every 6 months'],
];
const REMIND: [string, string][] = [
    ['', 'No reminder'],
    ['0', 'On the day'],
    ['1', 'The day before'],
    ['3', '3 days before'],
    ['7', 'A week before'],
    ['14', 'Two weeks before'],
];

export const at = (d: string) => new Date(`${d}T12:00:00Z`);
export const dayLabel = (d: string, clock: Clock, opts: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' }) =>
    clock.date(at(d), { ...opts, timeZone: 'UTC' });
export const inDays = (n: number) => (n === 0 ? 'today' : n === 1 ? 'tomorrow' : `in ${n} days`);
export const everyText = (n: number) => EVERY.find(([d]) => d === n)?.[1] ?? `Every ${n} days`;
export const firstName = (name: string) => name.split(/\s+/)[0];

/** "Birthday · today · turns 22" */
export function dateLine(d: PersonDate, clock: Clock, withName = false): string {
    const what = withName
        ? d.title
        : d.kind === 'birthday'
          ? 'Birthday'
          : d.kind === 'anniversary'
            ? (d.label ?? 'Anniversary')
            : (d.label ?? 'Date');
    const when = d.next ? (d.days !== null && d.days <= 14 ? inDays(d.days) : dayLabel(d.next, clock)) : 'passed';
    const turns = d.turns ? (d.kind === 'birthday' ? ` · turns ${d.turns}` : ` · ${d.turns} years`) : '';
    return `${what} · ${when}${turns}`;
}

/** What is next with someone, for their row: a date soon, or a catch-up that is due. */
export function nextLine(p: PersonRow, clock: Clock): { icon: string; text: string; due: boolean } | null {
    const late = p.catchUp && p.catchUp.late >= 0 ? p.catchUp.late : null;
    if (p.date && p.date.days !== null && p.date.days <= 14 && (late === null || p.date.days <= 1))
        return { icon: DATE_ICON[p.date.kind], text: dateLine(p.date, clock), due: p.date.days <= 7 };
    if (late !== null)
        return { icon: 'message', text: late === 0 ? 'Catch up today' : `Catch up · ${late} ${late === 1 ? 'day' : 'days'} late`, due: true };
    return null;
}

export function Avatar({ initials, big = false }: { initials: string; big?: boolean }) {
    return (
        <span className={`pp-av${big ? ' is-big' : ''}`} aria-hidden="true">
            {initials}
        </span>
    );
}

/** How to reach them, as links where that makes sense. */
export function ContactLine({ c }: { c: PersonForm['contacts'][number] }) {
    const href =
        c.kind === 'email' ? `mailto:${c.value}` : c.kind === 'phone' ? `tel:${c.value.replace(/[^\d+]/g, '')}` : c.kind === 'link' ? c.value : null;
    const body = (
        <>
            <Icon name={CONTACT_ICON[c.kind]} className="ic-sm" />
            <span className="pp-contact-value">{c.kind === 'link' ? c.value.replace(/^https?:\/\//, '') : c.value}</span>
            {c.label && <small>{c.label}</small>}
        </>
    );
    return href ? (
        <a className="pp-contact" href={href} target={c.kind === 'link' ? '_blank' : undefined} rel="noopener noreferrer">
            {body}
        </a>
    ) : (
        <span className="pp-contact">{body}</span>
    );
}

/** New person, or change one: name, how they fit in, how to reach them, groups, keeping in touch. */
export function PersonDialog({
    personId,
    initial,
    groups,
    view,
    onClose,
}: {
    personId?: number;
    initial?: PersonForm;
    groups: { id: number; name: string }[];
    view?: string;
    onClose: () => void;
}) {
    const form = useForm({
        name: initial?.name ?? '',
        relationship: initial?.relationship ?? '',
        organisation: initial?.organisation ?? '',
        every: initial?.catch_up_days ? String(initial.catch_up_days) : '',
        contacts: initial?.contacts ?? [],
        group_ids: initial?.group_ids ?? (view?.startsWith('g') ? [Number(view.slice(1))] : []),
        new_groups: [] as string[],
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const [newGroup, setNewGroup] = useState('');
    const custom = data.every !== '' && !EVERY.some(([d]) => String(d) === data.every);
    const [customOn, setCustomOn] = useState(custom);
    const setContact = (i: number, patch: Partial<PersonForm['contacts'][number]>) =>
        setData(
            'contacts',
            data.contacts.map((c, j) => (j === i ? { ...c, ...patch } : c)),
        );
    const addGroup = () => {
        const name = newGroup.trim();
        if (!name) return;
        const same = groups.find((g) => g.name.toLowerCase() === name.toLowerCase());
        if (same) setData('group_ids', [...new Set([...data.group_ids, same.id])]);
        else if (!data.new_groups.includes(name)) setData('new_groups', [...data.new_groups, name]);
        setNewGroup('');
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            name: d.name,
            relationship: d.relationship || null,
            organisation: d.organisation || null,
            catch_up_days: d.every ? Number(d.every) : null,
            contacts: d.contacts.filter((c) => c.value.trim() !== ''),
            group_ids: d.group_ids,
            new_groups: [...d.new_groups, ...(newGroup.trim() ? [newGroup.trim()] : [])],
            view,
        }));
        if (personId) form.patch(route('people.update', personId), { ...keep, onSuccess: onClose });
        else form.post(route('people.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal title={personId ? 'Edit person' : 'New person'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <TextField
                    id="pp-name"
                    label="Name"
                    value={data.name}
                    onValue={(v) => setData('name', v)}
                    error={errors.name}
                    required
                    maxLength={120}
                />
                <div className="vform-row">
                    <TextField
                        id="pp-rel"
                        label="Who they are"
                        value={data.relationship}
                        onValue={(v) => setData('relationship', v)}
                        error={errors.relationship}
                        placeholder="Supervisor, sister, friend"
                        maxLength={80}
                    />
                    <TextField
                        id="pp-org"
                        label="Where"
                        value={data.organisation}
                        onValue={(v) => setData('organisation', v)}
                        error={errors.organisation}
                        placeholder="University, company"
                        maxLength={120}
                    />
                </div>
                <Field id="pp-contact-0" label="How to reach them">
                    <div className="pp-contacts-edit">
                        {data.contacts.map((c, i) => (
                            <div key={i} className="pp-contact-row">
                                <Select
                                    value={c.kind}
                                    label="Kind"
                                    onChange={(v) => setContact(i, { kind: v as ContactKind })}
                                    options={(Object.keys(CONTACT_LABEL) as ContactKind[]).map((k) => ({ value: k, label: CONTACT_LABEL[k] }))}
                                />
                                <input
                                    id={`pp-contact-${i}`}
                                    className="input"
                                    value={c.value}
                                    aria-label={CONTACT_LABEL[c.kind]}
                                    type={c.kind === 'email' ? 'email' : c.kind === 'phone' ? 'tel' : 'text'}
                                    placeholder={
                                        c.kind === 'email'
                                            ? 'name@example.com'
                                            : c.kind === 'phone'
                                              ? '+60 12 345 6789'
                                              : c.kind === 'link'
                                                ? 'linkedin.com/in/…'
                                                : ''
                                    }
                                    onChange={(e) => setContact(i, { value: e.target.value })}
                                    maxLength={255}
                                />
                                <input
                                    className="input pp-contact-label"
                                    value={c.label ?? ''}
                                    aria-label="Label"
                                    placeholder="Label"
                                    onChange={(e) => setContact(i, { label: e.target.value || null })}
                                    maxLength={40}
                                />
                                <button
                                    type="button"
                                    className="icon-btn"
                                    aria-label="Remove"
                                    onClick={() =>
                                        setData(
                                            'contacts',
                                            data.contacts.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Icon name="x" className="ic-sm" />
                                </button>
                                {errors[`contacts.${i}.value`] && <p className="field-error pp-contact-error">{errors[`contacts.${i}.value`]}</p>}
                            </div>
                        ))}
                        <div className="pp-add-row">
                            {(Object.keys(CONTACT_LABEL) as ContactKind[]).map((k) => (
                                <button
                                    key={k}
                                    type="button"
                                    className="note-add"
                                    onClick={() => setData('contacts', [...data.contacts, { kind: k, label: null, value: '' }])}
                                >
                                    <Icon name="plus" className="ic-xs" />
                                    {CONTACT_LABEL[k]}
                                </button>
                            ))}
                        </div>
                    </div>
                </Field>
                <Field id="pp-group-new" label="Groups" error={errors.group_ids}>
                    <div className="pp-groups-edit">
                        {groups.map((g) => (
                            <button
                                key={g.id}
                                type="button"
                                aria-pressed={data.group_ids.includes(g.id)}
                                onClick={() =>
                                    setData(
                                        'group_ids',
                                        data.group_ids.includes(g.id) ? data.group_ids.filter((x) => x !== g.id) : [...data.group_ids, g.id],
                                    )
                                }
                            >
                                {g.name}
                            </button>
                        ))}
                        {data.new_groups.map((n) => (
                            <button
                                key={n}
                                type="button"
                                aria-pressed="true"
                                onClick={() =>
                                    setData(
                                        'new_groups',
                                        data.new_groups.filter((x) => x !== n),
                                    )
                                }
                            >
                                {n}
                            </button>
                        ))}
                        <input
                            id="pp-group-new"
                            className="input"
                            value={newGroup}
                            placeholder="New group"
                            maxLength={60}
                            onChange={(e) => setNewGroup(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    addGroup();
                                }
                            }}
                            onBlur={addGroup}
                        />
                    </div>
                </Field>
                <Field id="pp-every" label="Keep in touch" error={errors.catch_up_days}>
                    <div className="hb-inline">
                        <Select
                            id="pp-every"
                            className="input pp-every"
                            value={customOn ? 'custom' : String(data.every)}
                            onChange={(v) => {
                                setCustomOn(v === 'custom');
                                setData('every', v === 'custom' ? data.every || '21' : v);
                            }}
                            options={[
                                { value: '', label: 'No reminder to catch up' },
                                ...EVERY.map(([d, label]) => ({ value: String(d), label })),
                                { value: 'custom', label: 'Every … days' },
                            ]}
                        />
                        {customOn && (
                            <input
                                className="input"
                                type="number"
                                min={1}
                                max={365}
                                value={data.every}
                                onChange={(e) => setData('every', e.target.value)}
                                aria-label="Days between catch-ups"
                            />
                        )}
                    </div>
                </Field>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {personId ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

/** A birthday, an anniversary or another date, with an optional reminder. */
export function DateDialog({ personId, date, onClose }: { personId: number; date?: PersonDate; onClose: () => void }) {
    const form = useForm({
        kind: date?.kind ?? ('birthday' as DateKind),
        label: date?.label ?? '',
        day: String(date?.day ?? ''),
        month: String(date?.month ?? ''),
        year: date?.year ? String(date.year) : '',
        yearly: date?.yearly ?? true,
        remind: date?.remindDays != null ? String(date.remindDays) : '0',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            kind: d.kind,
            label: d.label || null,
            day: Number(d.day),
            month: Number(d.month),
            year: d.year ? Number(d.year) : null,
            yearly: d.kind !== 'other' || d.yearly,
            remind_days: d.remind === '' ? null : Number(d.remind),
        }));
        const opts = { ...keep, onSuccess: onClose };
        if (date) form.patch(route('person-dates.update', date.id), opts);
        else form.post(route('person-dates.store', personId), opts);
    };
    return (
        <Modal title={date ? 'Edit date' : 'Add a date'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <div className="seg hb-seg" role="radiogroup" aria-label="Kind of date">
                    {(
                        [
                            ['birthday', 'Birthday'],
                            ['anniversary', 'Anniversary'],
                            ['other', 'Other'],
                        ] as const
                    ).map(([k, label]) => (
                        <button key={k} type="button" role="radio" aria-checked={data.kind === k} onClick={() => setData('kind', k)}>
                            {label}
                        </button>
                    ))}
                </div>
                {data.kind !== 'birthday' && (
                    <TextField
                        id="pd-label"
                        label={data.kind === 'other' ? 'What it is' : 'What it is (optional)'}
                        value={data.label}
                        onValue={(v) => setData('label', v)}
                        error={errors.label}
                        placeholder={data.kind === 'other' ? 'Viva, graduation, moving day' : 'Wedding anniversary'}
                        maxLength={80}
                        required={data.kind === 'other'}
                    />
                )}
                <div className="pd-when">
                    <Field id="pd-day" label="Day" error={errors.day}>
                        <Select
                            id="pd-day"
                            value={String(data.day)}
                            placeholder="Day"
                            search={false}
                            onChange={(v) => setData('day', v)}
                            options={Array.from({ length: 31 }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))}
                        />
                    </Field>
                    <Field id="pd-month" label="Month" error={errors.month}>
                        <Select
                            id="pd-month"
                            value={String(data.month)}
                            placeholder="Month"
                            search={false}
                            onChange={(v) => setData('month', v)}
                            options={MONTHS.map((m, i) => ({ value: String(i + 1), label: m }))}
                        />
                    </Field>
                    <Field id="pd-year" label={data.kind === 'other' && !data.yearly ? 'Year' : 'Year (if known)'} error={errors.year}>
                        <input
                            id="pd-year"
                            className="input"
                            type="number"
                            min={1900}
                            max={2100}
                            value={data.year}
                            onChange={(e) => setData('year', e.target.value)}
                        />
                    </Field>
                </div>
                {data.kind === 'other' && (
                    <label className="auth-check">
                        <input type="checkbox" checked={data.yearly} onChange={(e) => setData('yearly', e.target.checked)} />
                        Every year
                    </label>
                )}
                <Field id="pd-remind" label="Remind me" error={errors.remind_days}>
                    <Select
                        id="pd-remind"
                        value={String(data.remind)}
                        onChange={(v) => setData('remind', v)}
                        options={REMIND.map(([v, label]) => ({ value: String(v), label }))}
                    />
                </Field>
                <div className="vform-foot">
                    {date ? (
                        <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={() => router.delete(route('person-dates.destroy', date.id), { ...keep, onSuccess: onClose })}
                        >
                            <Icon name="trash" />
                            Remove
                        </button>
                    ) : (
                        <span />
                    )}
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {date ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

/** "Talked today": how, which day, and a line about it. */
export function CatchUpPop({ personId, today, x, y, onClose }: { personId: number; today: string; x: number; y: number; onClose: () => void }) {
    const [kind, setKind] = useState<CatchUpKind>('message');
    const [on, setOn] = useState(today);
    const [note, setNote] = useState('');
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post(route('catch-ups.store', personId), { kind, on, note: note || null }, keep);
        onClose();
    };
    return (
        <Pop x={x} y={y} label="In touch" onClose={onClose} width={300}>
            <form className="pp-catch" onSubmit={submit}>
                <div className="pp-catch-kinds" role="radiogroup" aria-label="How">
                    {(Object.keys(CATCH_LABEL) as CatchUpKind[]).map((k) => (
                        <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => setKind(k)}>
                            <Icon name={CATCH_ICON[k]} className="ic-sm" />
                            {CATCH_LABEL[k]}
                        </button>
                    ))}
                </div>
                <input className="input" type="date" value={on} max={today} onChange={(e) => setOn(e.target.value)} aria-label="Day" required />
                <input
                    className="input"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="A line about it (optional)"
                    maxLength={500}
                    aria-label="Note"
                />
                <button type="submit" className="btn btn-primary btn-sm">
                    Save
                </button>
            </form>
        </Pop>
    );
}
