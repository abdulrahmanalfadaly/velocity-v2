import { router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { DatePick } from './date-pick';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Icon } from './icon';

export type CalendarKey = 'class' | 'work' | 'personal';
export const CALENDARS: [CalendarKey, string, string][] = [
    ['class', 'Classes', 'cap'],
    ['work', 'Work', 'briefcase'],
    ['personal', 'Personal', 'heart'],
];

export interface EventForm {
    title: string;
    calendar: CalendarKey;
    location: string | null;
    notes: string | null;
    all_day: boolean;
    date: string;
    end_date: string | null;
    start_time: string | null;
    end_time: string | null;
    repeat_weekly: boolean;
    repeat_until: string | null;
}

const keep = { preserveScroll: true, preserveState: true };

/** Add or change a class or event. A weekly one can lose a single week, or go altogether. */
export function EventDialog({
    initial,
    eventId,
    occurrenceDate,
    day,
    onClose,
}: {
    initial: EventForm;
    eventId?: number;
    /** The week the user clicked on, for "Remove this week". */
    occurrenceDate?: string;
    /** The day the user clicked on, for its notes. */
    day?: string;
    onClose: () => void;
}) {
    const form = useForm({
        title: initial.title,
        calendar: initial.calendar,
        location: initial.location ?? '',
        notes: initial.notes ?? '',
        all_day: initial.all_day,
        date: initial.date,
        end_date: initial.end_date ?? '',
        start_time: initial.start_time ?? '09:00',
        end_time: initial.end_time ?? '10:00',
        repeat_weekly: initial.repeat_weekly,
        repeat_until: initial.repeat_until ?? '',
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
        const opts = { ...keep, onSuccess: onClose };
        if (eventId) form.patch(route('events.update', eventId), opts);
        else form.post(route('events.store'), opts);
    };
    const remove = (scope: 'one' | 'all') =>
        router.delete(route('events.destroy', eventId!), { ...keep, data: { scope, date: occurrenceDate }, onSuccess: onClose });

    return (
        <Modal title={eventId ? 'Edit event' : 'New event'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
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
                <Field id="ev-cal" label="Calendar" error={errors.calendar}>
                    <div className="seg ev-seg" role="radiogroup" id="ev-cal">
                        {CALENDARS.map(([k, label, icon]) => (
                            <button key={k} type="button" role="radio" aria-checked={data.calendar === k} onClick={() => setData('calendar', k)}>
                                <Icon name={icon} className="ic-sm" />
                                {label}
                            </button>
                        ))}
                    </div>
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
                                    onChange={(v) => setData('start_time', v)}
                                    clearable={false}
                                    placeholder="Pick a time"
                                />
                            </Field>
                            <Field id="ev-end" label="To" error={errors.end_time}>
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
                        {eventId && initial.repeat_weekly && occurrenceDate && (
                            <button type="button" className="btn btn-ghost" onClick={() => remove('one')}>
                                <Icon name="x" />
                                Remove this week
                            </button>
                        )}
                        {eventId && (
                            <button type="button" className="btn btn-ghost" onClick={() => remove('all')}>
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
