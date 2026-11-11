import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

const PAGES = [
    { title: 'Profile', url: '/settings/profile' },
    { title: 'Password', url: '/settings/password' },
    { title: 'Appearance', url: '/settings/appearance' },
    { title: 'FYP mode', url: '/settings/mode' },
];

/** Settings in the Velocity design: a row of tabs, then the page's cards. */
export default function SettingsLayout({ children }: { children: ReactNode }) {
    const path = usePage().url.split('?')[0];

    return (
        <>
            <header className="page-head">
                <div>
                    <p className="eyebrow">Account</p>
                    <h1 className="h1">Settings</h1>
                </div>
            </header>
            <nav className="filters settings-tabs" aria-label="Settings">
                {PAGES.map((p) => (
                    <Link key={p.url} href={p.url} className="filter" aria-current={path === p.url ? 'page' : undefined} prefetch>
                        {p.title}
                    </Link>
                ))}
            </nav>
            <div className="settings-body">{children}</div>
        </>
    );
}

/** One card of settings with a title. */
export function SettingsCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="card settings-card" aria-label={title}>
            <div className="card-head">
                <h2 className="card-title">{title}</h2>
            </div>
            <div className="settings-card-body">{children}</div>
        </section>
    );
}

/** The save button and a short "Saved" once it worked. */
export function SaveRow({ label = 'Save', processing, saved }: { label?: string; processing: boolean; saved: boolean }) {
    return (
        <div className="settings-actions">
            <button type="submit" className="btn btn-primary" disabled={processing}>
                {label}
            </button>
            <span className={`settings-saved${saved ? ' is-shown' : ''}`} role="status">
                {saved ? 'Saved' : ''}
            </span>
        </div>
    );
}
