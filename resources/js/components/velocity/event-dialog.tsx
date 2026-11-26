import type { VelocityShared } from '@/types/velocity';
import { router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';
import { DatePick } from './date-pick';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Icon } from './icon';

/** An event's area, as lists show it. */
export type EventArea = { id: number; name: string; icon: string | null } | null;

/*
 * Events belong to the user's own areas (user, 26 Nov 2026), not the fixed Classes / Work / Personal they started
 * with. The last area picked is remembered on this device for the next new event.
 */
const LAST_AREA = 'velocity.eventArea';
export const lastEventArea = (): number | null => {
    const v = Number(typeof window === 'undefined' ? '' : (localStorage.getItem(LAST_AREA) ?? ''));
    return v > 0 ? v : null;
};
export const rememberEventArea = (id: number | null) => localStorage.setItem(LAST_AREA, id ? String(id) : '');

/** The user's areas (from the Tasks menu's data), in their order. */
export function useAreas() {
    const { props } = usePage<VelocityShared>();
    return (props.nav?.areas ?? []).map((a) => ({ id: a.id, name: a.name, icon: a.icon }));
}

/** Your areas as a row of choices, plus "No area". */
export function AreaPicker({ id, value, onChange }: { id?: string; value: number | null; onChange: (id: number | null) => void }) {
    const areas = useAreas();
    // An area that was removed since: shown as none.
    const current = areas.some((a) => a.id === value) ? value : null;
    return (
        <div className="seg ev-seg" role="radiogroup" id={id} aria-label="Area">
            {areas.map((a) => (
                <button key={a.id} type="button" role="radio" aria-checked={current === a.id} onClick={() => onChange(a.id)}>
                    <Icon name={a.icon ?? 'folder'} className="ic-sm" />
                    {a.name}
                </button>
            ))}
            <button type="button" role="radio" aria-checked={current === null} onClick={() => onChange(null)}>
                No area
            </button>
        </div>
    );
}

export interface EventForm {
    title: string;
    area_id: number | null;
    location: string | null;
    notes: string | null;
    all_day: boolean;
    date: string;
    end_date: string | null;
    start_time: string | null;
    end_time: string | null;
    repeat_weekly: boolean;
    repeat_until: string | null;
    /** Asks before it is dragged to another time. */
    locked?: boolean;
}

const keep = { preserveScroll: true, preserveState: true };
/** "14:30" → 870 (minutes since midnight), and back. */
const mins = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

/** Add or change a class or event. The day clicked can be cancelled (it stays, crossed out); the event can go altogether. */
export function EventDialog({
    initial,
    eventId,
    occurrenceDate,
    day,
    cancelled = false,
    top,
    onClose,
}: {
    /** Shown first in the form (the Planner's Event / Project time / Reserved switch). */
    top?: ReactNode;
    initial: EventForm;
    eventId?: number;
    /** The week the user clicked on (a weekly event). */
    occurrenceDate?: string;
    /** The day the user clicked on, for its notes and for "Cancel". */
    day?: string;
    /** Whether that day was cancelled. */
    cancelled?: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        title: initial.title,
        area_id: initial.area_id,
        location: initial.location ?? '',
        notes: initial.notes ?? '',
        all_day: initial.all_day,
        date: initial.date,
        end_date: initial.end_date ?? '',
        start_time: initial.start_time ?? '09:00',
        end_time: initial.end_time ?? '10:00',
        repeat_weekly: initial.repeat_weekly,
        repeat_until: initial.repeat_until ?? '',
        locked: initial.locked ?? false,
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            location: d.location || null,
            notes: d.notes || null,
            end_date: d.all_day && d.end_date ? d.end_date : null,
            start_time: d.all_day ? null : d.start_time,
            end_time: d.all_day ? null : d.end_time,
            repeat_until: d.repeat_weekly && d.repeat_until ? d.repeat_until : null,
        }));
        const opts = {
            ...keep,
            onSuccess: () => {
                if (!eventId) rememberEventArea(data.area_id);
                onClose();
            },
        };
        if (eventId) form.patch(route('events.update', eventId), opts);
        else form.post(route('events.store'), opts);
    };
    const remove = () => router.delete(route('events.destroy', eventId!), { ...keep, data: { scope: 'all' }, onSuccess: onClose });
    const cancelDay = (value: boolean) =>
        router.post(route('events.cancel', eventId!), { date: occurrenceDate ?? day, cancelled: value }, { ...keep, onSuccess: onClose });

    return (
        <Modal title={eventId ? 'Edit event' : 'New event'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                {top}
                {eventId && day && (
                    <button type="button" className="ev-notes" onClick={() => router.post(route('notes.event'), { event_id: eventId, date: day })}>
                        <Icon name="notes" />
                        <span>
                            Notes for{' '}
                            {new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
                                new Date(`${day}T12:00:00Z`),
                            )}
                        </span>
                        <Icon name="chevR" className="ic-sm" />
                    </button>
                )}
                <TextField
                    id="ev-title"
                    label="Title"
                    value={data.title}
                    onValue={(v) => setData('title', v)}
                    error={errors.title}
                    required
                    maxLength={160}
                    placeholder="Data Mining lecture"
                />
                <Field id="ev-area" label="Area" error={errors.area_id}>
                    <AreaPicker id="ev-area" value={data.area_id} onChange={(v) => setData('area_id', v)} />
                </Field>
                <div className="vform-row">
                    <Field id="ev-date" label={data.repeat_weekly ? 'First week' : data.all_day ? 'First day' : 'Date'} error={errors.date}>
                        <DatePick id="ev-date" value={data.date} onChange={(v) => setData('date', v)} clearable={false} placeholder="Pick a day" />
                    </Field>
                    {data.all_day ? (
                        <Field id="ev-end-date" label="Last day" error={errors.end_date}>
                            <DatePick
                                id="ev-end-date"
                                value={data.end_date}
                                onChange={(v) => setData('end_date', v)}
                                placeholder="Same day"
                                min={data.date}
                            />
                        </Field>
                    ) : (
                        <div className="vform-row ev-times">
                            <Field id="ev-start" label="From" error={errors.start_time}>
                                <DatePick
                                    id="ev-start"
                                    mode="time"
                                    value={data.start_time}
                                    onChange={(v) => {
                                        // The end moves with the start, so the event keeps its length.
                                        const length = (mins(data.end_time) - mins(data.start_time) + 1440) % 1440 || 60;
                                        setData((d) => ({ ...d, start_time: v, end_time: hhmm((mins(v) + length) % 1440) }));
                                    }}
                                    clearable={false}
                                    placeholder="Pick a time"
                                />
                            </Field>
                            <Field id="ev-end" label={mins(data.end_time) <= mins(data.start_time) ? 'To (next day)' : 'To'} error={errors.end_time}>
                                <DatePick
                                    id="ev-end"
                                    mode="time"
                                    value={data.end_time}
                                    onChange={(v) => setData('end_time', v)}
                                    clearable={false}
                                    placeholder="Pick a time"
                                />
                            </Field>
                        </div>
                    )}
                </div>
                <div className="ev-checks">
                    <label className="auth-check">
                        <input type="checkbox" checked={data.all_day} onChange={(e) => setData('all_day', e.target.checked)} />
                        All day
                    </label>
                    <label className="auth-check">
                        <input type="checkbox" checked={data.repeat_weekly} onChange={(e) => setData('repeat_weekly', e.target.checked)} />
                        Every week
                    </label>
                    {data.repeat_weekly && (
                        <span className="ev-until">
                            until
                            <DatePick
                                label="Last week"
                                value={data.repeat_until}
                                onChange={(v) => setData('repeat_until', v)}
                                placeholder="No end"
                                min={data.date}
                            />
                        </span>
                    )}
                    {!data.all_day && (
                        <label className="auth-check" title="Asks before it is dragged to another time">
                            <input type="checkbox" checked={data.locked} onChange={(e) => setData('locked', e.target.checked)} />
                            Locked
                        </label>
                    )}
                </div>
                {errors.repeat_until && <p className="field-error">{errors.repeat_until}</p>}
                <TextField
                    id="ev-location"
                    label="Where"
                    value={data.location}
                    onValue={(v) => setData('location', v)}
                    error={errors.location}
                    maxLength={160}
                    placeholder="Room B2-04"
                />
                <Field id="ev-notes" label="Notes" error={errors.notes}>
                    <textarea
                        id="ev-notes"
                        className="input vform-text"
                        rows={2}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                    />
                </Field>
                <div className="vform-foot">
                    <span className="vform-foot-left">
                        {eventId && (occurrenceDate ?? day) && (
                            <button type="button" className="btn btn-ghost" onClick={() => cancelDay(!cancelled)}>
                                <Icon name={cancelled ? 'refresh' : 'ban'} />
                                {cancelled ? 'Not cancelled' : initial.repeat_weekly ? 'Cancel this week' : 'Mark as cancelled'}
                            </button>
                        )}
                        {eventId && (
                            <button type="button" className="btn btn-ghost" onClick={remove}>
                                <Icon name="archive" />
                                {initial.repeat_weekly ? 'Remove every week' : 'Remove'}
                            </button>
                        )}
                    </span>
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {eventId ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}
