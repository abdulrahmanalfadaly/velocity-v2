import { Field } from '@/components/velocity/field';
import { Icon } from '@/components/velocity/icon';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import type { VelocityShared } from '@/types/velocity';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

interface Key {
    id: number;
    name: string;
    access: string;
    createdAt: string | null;
    lastUsedAt: string | null;
    expiresAt: string | null;
    expired: boolean;
}
interface Area {
    key: string;
    label: string;
    writable: boolean;
}
interface Props extends VelocityShared {
    keys: Key[];
    areas: Area[];
    newKey: { id: number; name: string; key: string } | null;
    apiUrl: string;
    github: { login: string | null };
}

type Level = 'none' | 'read' | 'write';
const EXPIRES: [string, string][] = [
    ['never', 'Never'],
    ['30', '30 days'],
    ['90', '90 days'],
    ['365', '1 year'],
];

/** Copies text; on a plain-http address (a phone on the Wi-Fi) the clipboard API is missing, so fall back. */
async function copyText(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // fall through
    }
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand('copy');
    area.remove();
    return ok;
}

function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [done, setDone] = useState(false);
    return (
        <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() =>
                copyText(text).then((ok) => {
                    setDone(ok);
                    if (ok) setTimeout(() => setDone(false), 2000);
                })
            }
        >
            <Icon name={done ? 'check' : 'copy'} />
            {done ? 'Copied' : label}
        </button>
    );
}

/** Settings → Integrations: access keys for Mark. */
export default function Integrations() {
    const { props } = usePage<Props>();
    const [open, setOpen] = useState(props.keys.length === 0 && !props.newKey);

    return (
        <AppLayout breadcrumbs={[{ title: 'Integrations', href: '/settings/integrations' }]}>
            <Head title="Integrations" />
            <SettingsLayout>
                <SettingsCard title="Access keys">
                    <div className="settings-form ik">
                        {props.newKey && (
                            <div className="ik-new" role="status">
                                <p>
                                    <b>{props.newKey.name}</b>: copy the key now. It won’t be shown again.
                                </p>
                                <div className="ik-secret">
                                    <code>{props.newKey.key}</code>
                                    <CopyButton text={props.newKey.key} />
                                </div>
                            </div>
                        )}
                        {props.keys.length > 0 && (
                            <ul className="ik-list">
                                {props.keys.map((k) => (
                                    <KeyRow key={k.id} k={k} />
                                ))}
                            </ul>
                        )}
                        {open ? (
                            <KeyForm
                                areas={props.areas}
                                onCancel={props.keys.length ? () => setOpen(false) : undefined}
                                onMade={() => setOpen(false)}
                            />
                        ) : (
                            <div>
                                <button type="button" className="btn btn-secondary" onClick={() => setOpen(true)}>
                                    <Icon name="plus" />
                                    New key
                                </button>
                            </div>
                        )}
                        <div className="ik-url">
                            <span>API address</span>
                            <code>{props.apiUrl}</code>
                            <CopyButton text={props.apiUrl} />
                        </div>
                    </div>
                </SettingsCard>
                <SettingsCard title="GitHub">
                    <GitHubConnect login={props.github.login} />
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}

/** GitHub with a fine-grained token: only the repositories and permissions chosen on GitHub. */
function GitHubConnect({ login }: { login: string | null }) {
    const form = useForm({ token: '' });
    const [sure, setSure] = useState(false);
    if (login) {
        return (
            <div className="settings-form ik">
                <div className="ik-row ik-solo">
                    <Icon name="github" className="ik-ic" />
                    <div className="ik-main">
                        <b>@{login}</b>
                        <span>Connected</span>
                    </div>
                    {sure ? (
                        <div className="ik-sure">
                            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setSure(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary btn-sm"
                                onClick={() => router.delete(route('github.disconnect'), { preserveScroll: true })}
                            >
                                Disconnect
                            </button>
                        </div>
                    ) : (
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSure(true)}>
                            Disconnect
                        </button>
                    )}
                </div>
                <p className="settings-note">
                    Disconnecting doesn’t delete the token on GitHub:{' '}
                    <a href="https://github.com/settings/personal-access-tokens" target="_blank" rel="noopener noreferrer">
                        delete it there
                    </a>
                    .
                </p>
            </div>
        );
    }
    return (
        <form
            className="settings-form ik"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('github.connect'), { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <ol className="gh-steps">
                <li>
                    <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">
                        Make a fine-grained token on GitHub
                    </a>
                </li>
                <li>
                    Repository access: <b>Only select repositories</b>, then pick the ones to use
                </li>
                <li>
                    Repository permissions: <b>Issues</b> Read and write, <b>Pull requests</b> Read-only (Metadata is added by itself)
                </li>
                <li>Paste the token here</li>
            </ol>
            <Field id="gh-token" label="Token" error={form.errors.token}>
                <input
                    id="gh-token"
                    type="password"
                    className="input"
                    autoComplete="off"
                    value={form.data.token}
                    onChange={(e) => form.setData('token', e.target.value)}
                    aria-describedby={form.errors.token ? 'gh-token-error' : undefined}
                />
            </Field>
            <div className="settings-actions">
                <button type="submit" className="btn btn-primary" disabled={form.processing || !form.data.token.trim()}>
                    {form.processing ? 'Checking…' : 'Connect'}
                </button>
            </div>
        </form>
    );
}

function KeyRow({ k }: { k: Key }) {
    const { props } = usePage<VelocityShared>();
    const [sure, setSure] = useState(false);
    const tz = props.clock.tz;
    const day = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz });
    const ago = (iso: string) => {
        const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000); // keys run on real time
        if (min < 1) return 'just now';
        if (min < 60) return `${min} min ago`;
        if (min < 60 * 24) return `${Math.round(min / 60)} h ago`;
        if (min < 60 * 24 * 14) return `${Math.round(min / 1440)} days ago`;
        return day(iso);
    };
    const when = [
        k.createdAt ? `Made ${day(k.createdAt)}` : null,
        k.lastUsedAt ? `last used ${ago(k.lastUsedAt)}` : 'never used',
        k.expiresAt ? (k.expired ? `expired ${day(k.expiresAt)}` : `expires ${day(k.expiresAt)}`) : 'never expires',
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className={`ik-row${k.expired ? ' is-expired' : ''}`}>
            <Icon name="key" className="ik-ic" />
            <div className="ik-main">
                <b>{k.name}</b>
                <span>{k.access}</span>
                <small>{when}</small>
            </div>
            {sure ? (
                <div className="ik-sure">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => setSure(false)}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        onClick={() => router.delete(route('keys.destroy', k.id), { preserveScroll: true })}
                    >
                        Revoke “{k.name}”
                    </button>
                </div>
            ) : (
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSure(true)}>
                    Revoke
                </button>
            )}
        </li>
    );
}

function KeyForm({ areas, onCancel, onMade }: { areas: Area[]; onCancel?: () => void; onMade: () => void }) {
    const form = useForm<{ name: string; full: boolean; levels: Record<string, Level>; expires: string }>({
        name: 'Mark',
        full: true,
        levels: Object.fromEntries(areas.map((a) => [a.key, 'none'])) as Record<string, Level>,
        expires: 'never',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('keys.store'), { preserveScroll: true, onSuccess: onMade });
    };
    const seg = (value: string, choices: [string, string][], onPick: (v: string) => void, label: string) => (
        <div className="seg ik-seg" role="radiogroup" aria-label={label}>
            {choices.map(([v, text]) => (
                <button key={v} type="button" role="radio" aria-checked={value === v} onClick={() => onPick(v)}>
                    {text}
                </button>
            ))}
        </div>
    );

    return (
        <form className="ik-form" onSubmit={submit}>
            <Field id="key-name" label="Name" error={errors.name}>
                <input
                    id="key-name"
                    className="input"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    maxLength={60}
                    aria-describedby={errors.name ? 'key-name-error' : undefined}
                />
            </Field>
            <div className="ik-field">
                <span className="ik-label">Access</span>
                {seg(
                    form.data.full ? 'full' : 'some',
                    [
                        ['full', 'Full access'],
                        ['some', 'Choose by area'],
                    ],
                    (v) => form.setData('full', v === 'full'),
                    'Access',
                )}
            </div>
            {!form.data.full && (
                <div className="ik-areas">
                    {areas.map((a) => (
                        <div key={a.key} className="ik-area">
                            <span>{a.label}</span>
                            {seg(
                                form.data.levels[a.key],
                                a.writable
                                    ? [
                                          ['none', 'None'],
                                          ['read', 'Read'],
                                          ['write', 'Read & write'],
                                      ]
                                    : [
                                          ['none', 'None'],
                                          ['read', 'Read'],
                                      ],
                                (v) => form.setData('levels', { ...form.data.levels, [a.key]: v as Level }),
                                a.label,
                            )}
                        </div>
                    ))}
                    {errors.levels && <p className="field-error">{errors.levels}</p>}
                </div>
            )}
            <div className="ik-field">
                <span className="ik-label">Stops working</span>
                {seg(form.data.expires, EXPIRES, (v) => form.setData('expires', v), 'Stops working')}
            </div>
            <div className="settings-actions">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    Make key
                </button>
                {onCancel && (
                    <button type="button" className="btn btn-ghost" onClick={onCancel}>
                        Cancel
                    </button>
                )}
            </div>
        </form>
    );
}
