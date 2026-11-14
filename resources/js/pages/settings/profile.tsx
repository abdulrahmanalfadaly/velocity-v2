import { Field } from '@/components/velocity/field';
import { Select } from '@/components/velocity/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SaveRow, SettingsCard } from '@/layouts/settings/layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEventHandler, useMemo, useRef, useState } from 'react';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    /** [time zone, offset from UTC in seconds] */
    timezones: [string, number][];
}

const offsetText = (seconds: number) => {
    if (seconds === 0) return 'GMT';
    const a = Math.abs(seconds);
    const h = Math.floor(a / 3600);
    const m = Math.round((a % 3600) / 60);
    return `GMT${seconds < 0 ? '−' : '+'}${h}${m ? `:${String(m).padStart(2, '0')}` : ''}`;
};
const placeName = (id: string) => (id.includes('/') ? id.split('/').slice(1).join(' – ') : id).replaceAll('_', ' ');

export default function Profile({ mustVerifyEmail, status, timezones }: Props) {
    const { auth } = usePage<SharedData>().props;
    const user = auth.user as SharedData['auth']['user'] & { timezone: string };

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: user.name,
        email: user.email,
        timezone: user.timezone,
    });

    // Grouped by region (Africa, America, Asia …) for the picker.
    const groups = useMemo(() => {
        const out = new Map<string, [string, number][]>();
        for (const z of timezones) {
            const region = z[0].includes('/') ? z[0].split('/')[0] : 'Other';
            out.set(region, [...(out.get(region) ?? []), z]);
        }
        return [...out.entries()];
    }, [timezones]);
    const device = useMemo(() => {
        const id = Intl.DateTimeFormat().resolvedOptions().timeZone;
        return timezones.some((z) => z[0] === id) ? id : null;
    }, [timezones]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Profile', href: '/settings/profile' }]}>
            <Head title="Profile" />
            <SettingsLayout>
                <SettingsCard title="Profile">
                    <form onSubmit={submit} className="settings-form">
                        <Field id="name" label="Name" error={errors.name}>
                            <input
                                id="name"
                                className="input"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                aria-describedby={errors.name ? 'name-error' : undefined}
                            />
                        </Field>

                        <Field id="email" label="Email address" error={errors.email}>
                            <input
                                id="email"
                                type="email"
                                className="input"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                aria-describedby={errors.email ? 'email-error' : undefined}
                            />
                        </Field>

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <p className="settings-note">
                                Your email address isn’t verified.{' '}
                                <Link href={route('verification.send')} method="post" as="button" className="link">
                                    Send the link again
                                </Link>
                                {status === 'verification-link-sent' && <span className="settings-saved is-shown"> Sent.</span>}
                            </p>
                        )}

                        <Field id="timezone" label="Time zone" error={errors.timezone}>
                            <Select
                                id="timezone"
                                value={data.timezone}
                                onChange={(v) => setData('timezone', v)}
                                options={groups.flatMap(([region, zones]) =>
                                    zones.map(([id, offset]) => ({ value: id, label: `${placeName(id)} (${offsetText(offset)})`, group: region })),
                                )}
                            />
                            {device && device !== data.timezone && (
                                <button type="button" className="link settings-device-tz" onClick={() => setData('timezone', device)}>
                                    Use this device’s time zone ({placeName(device)})
                                </button>
                            )}
                        </Field>

                        <SaveRow processing={processing} saved={recentlySuccessful} />
                    </form>
                </SettingsCard>

                <DeleteAccount />
            </SettingsLayout>
        </AppLayout>
    );
}

/** Deleting the account asks for the password first, in place. */
function DeleteAccount() {
    const [asking, setAsking] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm({ password: '' });

    const cancel = () => {
        setAsking(false);
        clearErrors();
        reset();
    };
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onError: () => input.current?.focus(),
            onFinish: () => reset(),
        });
    };

    return (
        <SettingsCard title="Delete account">
            {!asking ? (
                <div className="settings-form">
                    <p className="settings-note">This removes your account and everything in it. It can’t be undone.</p>
                    <div className="settings-actions">
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => {
                                setAsking(true);
                                setTimeout(() => input.current?.focus(), 0);
                            }}
                        >
                            Delete account
                        </button>
                    </div>
                </div>
            ) : (
                <form onSubmit={submit} className="settings-form">
                    <Field id="delete-password" label="Your password" error={errors.password}>
                        <input
                            id="delete-password"
                            ref={input}
                            type="password"
                            className="input"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="current-password"
                            aria-describedby={errors.password ? 'delete-password-error' : undefined}
                        />
                    </Field>
                    <div className="settings-actions">
                        <button type="submit" className="btn btn-secondary" disabled={processing}>
                            Delete for good
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={cancel}>
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </SettingsCard>
    );
}
