import { Select } from '@/components/velocity/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { adjustWeights, CRIT, type WeightSet } from '@/lib/velocity';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const CHOICES = [
    { value: '', label: 'Never' },
    { value: '1', label: 'After 1 day' },
    { value: '7', label: 'After 7 days' },
    { value: '30', label: 'After 30 days' },
];

const FOLLOW = [
    { value: '0', label: 'Off' },
    { value: '1', label: 'After 1 day' },
    { value: '2', label: 'After 2 days' },
    { value: '3', label: 'After 3 days' },
    { value: '5', label: 'After 5 days' },
    { value: '7', label: 'After a week' },
    { value: '14', label: 'After 2 weeks' },
];

type Props = {
    autoArchiveDays: number | null;
    followUpDays: number;
    weights: WeightSet;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
};

/** Settings → Tasks: the weights, horizon and hours a day the ranking uses, and when finished tasks go to the archive. */
export default function TaskSettings() {
    const { props } = usePage<Props>();

    return (
        <AppLayout breadcrumbs={[{ title: 'Tasks', href: '/settings/tasks' }]}>
            <Head title="Task settings" />
            <SettingsLayout>
                <SettingsCard title="Weights">
                    <WeightsForm {...props} />
                </SettingsCard>
                <SettingsCard title="Follow-ups">
                    <div className="settings-form">
                        <div className="field">
                            <div className="field-label">
                                <label htmlFor="follow-up-days">When a task starts waiting, follow up</label>
                            </div>
                            <Select
                                id="follow-up-days"
                                value={String(props.followUpDays)}
                                options={FOLLOW}
                                onChange={(v) => router.patch(route('task-settings.update'), { follow_up_days: Number(v) }, { preserveScroll: true })}
                            />
                        </div>
                    </div>
                </SettingsCard>
                <SettingsCard title="Finished tasks">
                    <div className="settings-form">
                        <div className="field">
                            <div className="field-label">
                                <label htmlFor="auto-archive">Archive finished tasks</label>
                            </div>
                            <Select
                                id="auto-archive"
                                value={props.autoArchiveDays ? String(props.autoArchiveDays) : ''}
                                options={CHOICES}
                                onChange={(v) =>
                                    router.patch(route('task-settings.update'), { auto_archive_days: v ? Number(v) : null }, { preserveScroll: true })
                                }
                            />
                        </div>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}

/** The four weights (they always add up to 100), the horizon and hours a day; saved a moment after a change. */
function WeightsForm(props: Props) {
    const [weights, setWeights] = useState(props.weights);
    const [h, setH] = useState(props.horizonDays);
    const [cap, setCap] = useState(props.hoursPerDay);
    const snapshot = useRef(weights);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.weights), [props.weights]);
    useEffect(() => setH(props.horizonDays), [props.horizonDays]);
    useEffect(() => setCap(props.hoursPerDay), [props.hoursPerDay]);

    // A number outside its range isn't saved: it says so, and goes back when you leave the box.
    const okH = h >= 1 && h <= 30;
    const okCap = cap >= 0.5 && cap <= 16;
    const save = (w: WeightSet, horizon = h, hours = cap) => {
        clearTimeout(timer.current);
        if (!(horizon >= 1 && horizon <= 30 && hours >= 0.5 && hours <= 16)) return;
        timer.current = setTimeout(
            () =>
                router.put(
                    route('weights.update'),
                    { weights: w, horizonDays: horizon, hoursPerDay: hours },
                    { preserveScroll: true, preserveState: true },
                ),
            450,
        );
    };

    return (
        <div className="w-settings">
            {CRIT.map((c) => (
                <div className="w-row" key={c.k}>
                    <label htmlFor={`w-${c.k}`}>
                        <i className={`sw sw-${c.k}`} />
                        {c.name}
                    </label>
                    <input
                        type="range"
                        id={`w-${c.k}`}
                        min={0}
                        max={100}
                        value={weights[c.k]}
                        onPointerDown={() => (snapshot.current = weights)}
                        onFocus={() => (snapshot.current = weights)}
                        onChange={(e) => {
                            const next = adjustWeights(snapshot.current, c.k, Number(e.target.value));
                            setWeights(next);
                            save(next);
                        }}
                    />
                    <output htmlFor={`w-${c.k}`}>{weights[c.k]}%</output>
                </div>
            ))}
            <div className="w-row w-num is-first">
                <label htmlFor="horizon">Horizon</label>
                <span>
                    <input
                        id="horizon"
                        className="prop-input"
                        type="number"
                        min={1}
                        max={30}
                        step={0.5}
                        value={h}
                        onChange={(e) => (setH(Number(e.target.value)), save(weights, Number(e.target.value)))}
                        onBlur={() => !okH && setH(props.horizonDays)}
                        aria-invalid={!okH}
                        aria-describedby={okH ? undefined : 'horizon-error'}
                    />{' '}
                    <span className="muted">days</span>
                    {!okH && (
                        <span className="field-error w-error" id="horizon-error">
                            1 to 30 days
                        </span>
                    )}
                </span>
            </div>
            <div className="w-row w-num">
                <label htmlFor="cap">Hours a day</label>
                <span>
                    <input
                        id="cap"
                        className="prop-input"
                        type="number"
                        min={0.5}
                        max={16}
                        step={0.5}
                        value={cap}
                        onChange={(e) => (setCap(Number(e.target.value)), save(weights, h, Number(e.target.value)))}
                        onBlur={() => !okCap && setCap(props.hoursPerDay)}
                        aria-invalid={!okCap}
                        aria-describedby={okCap ? undefined : 'cap-error'}
                    />{' '}
                    <span className="muted">hours</span>
                    {!okCap && (
                        <span className="field-error w-error" id="cap-error">
                            0.5 to 16 hours
                        </span>
                    )}
                </span>
            </div>
            <div className="pop-foot">
                <span />
                <button
                    className="link"
                    disabled={props.isDefault && h === 7 && cap === 4}
                    onClick={() => {
                        clearTimeout(timer.current);
                        // The boxes go back too, even one holding a number that was never saved.
                        router.delete(route('weights.reset'), { preserveScroll: true, onSuccess: () => (setH(7), setCap(4)) });
                    }}
                >
                    Reset
                </button>
            </div>
        </div>
    );
}
