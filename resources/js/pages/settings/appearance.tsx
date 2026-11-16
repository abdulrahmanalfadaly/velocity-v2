import { Icon } from '@/components/velocity/icon';
import { type Appearance as Theme, useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import type { VelocityShared } from '@/types/velocity';
import { Head, router, usePage } from '@inertiajs/react';
import { type CSSProperties, useRef, useState } from 'react';

const THEMES: { value: Theme; icon: string; label: string }[] = [
    { value: 'light', icon: 'sun', label: 'Light' },
    { value: 'dark', icon: 'moon', label: 'Dark' },
    { value: 'system', icon: 'monitor', label: 'Same as this device' },
];

/** Gradients have a light and a dark look (in the CSS); photos sit under a soft veil so text stays easy to read. */
const WALLPAPERS: { name: string | null; label: string; photo?: string }[] = [
    { name: null, label: 'None' },
    { name: 'g-lavender', label: 'Lavender' },
    { name: 'g-mist', label: 'Mist' },
    { name: 'g-dawn', label: 'Dawn' },
    { name: 'g-sage', label: 'Sage' },
    { name: 'g-dusk', label: 'Dusk' },
    { name: 'p-alpine', label: 'Alpine', photo: '/wallpapers/alpine-stillness.webp' },
    { name: 'p-mist', label: 'Morning mist', photo: '/wallpapers/morning-mist.webp' },
    { name: 'p-dusk', label: 'Open dusk', photo: '/wallpapers/open-dusk.webp' },
    { name: 'p-coast', label: 'Quiet coast', photo: '/wallpapers/quiet-coast.webp' },
];

const keep = { preserveScroll: true, preserveState: true };

export default function Appearance() {
    const { props } = usePage<VelocityShared & { ownWallpaper: string | null }>();
    const { appearance, updateAppearance } = useAppearance();
    const personal = !props.auth.user?.fypMode;

    return (
        <AppLayout breadcrumbs={[{ title: 'Appearance', href: '/settings/appearance' }]}>
            <Head title="Appearance" />
            <SettingsLayout>
                <SettingsCard title="Theme">
                    <div className="settings-form">
                        <div className="seg settings-seg" role="radiogroup" aria-label="Theme">
                            {THEMES.map((t) => (
                                <button
                                    key={t.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={appearance === t.value}
                                    onClick={() => updateAppearance(t.value)}
                                >
                                    <Icon name={t.icon} />
                                    {t.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </SettingsCard>
                {personal && <Wallpaper current={props.wallpaper?.name ?? null} own={props.ownWallpaper} />}
            </SettingsLayout>
        </AppLayout>
    );
}

/** The wallpaper behind the pages: none, a gradient, a photo, or your own picture. Kept on your account. */
function Wallpaper({ current, own }: { current: string | null; own: string | null }) {
    const file = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const choose = (name: string | null) => name !== current && router.put(route('wallpaper.update'), { wallpaper: name }, keep);
    const upload = (f: File | undefined) => {
        if (!f) return;
        setError(null);
        setBusy(true);
        router.post(
            route('wallpaper.upload'),
            { picture: f },
            {
                ...keep,
                forceFormData: true,
                onError: (e) => setError(e.picture ?? 'That picture couldn’t be used.'),
                onFinish: () => {
                    setBusy(false);
                    if (file.current) file.current.value = '';
                },
            },
        );
    };
    const options = [...WALLPAPERS, ...(own ? [{ name: 'own', label: 'Your picture', photo: own }] : [])];

    return (
        <SettingsCard title="Wallpaper">
            <div className="settings-form">
                <div className="wall-grid" role="radiogroup" aria-label="Wallpaper">
                    {options.map((w) => (
                        <button
                            key={w.name ?? 'none'}
                            type="button"
                            role="radio"
                            aria-checked={current === w.name}
                            className="wall-option"
                            onClick={() => choose(w.name)}
                        >
                            <span
                                className={`wall-swatch${w.name ? '' : ' is-none'}`}
                                data-wall={w.name ?? undefined}
                                style={w.photo ? ({ backgroundImage: `url("${w.photo}")` } as CSSProperties) : undefined}
                                aria-hidden="true"
                            >
                                {current === w.name && <Icon name="check" className="ic-sm" />}
                            </span>
                            <span className="wall-label">{w.label}</span>
                        </button>
                    ))}
                </div>
                <div className="wall-own">
                    <input
                        ref={file}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        id="wall-file"
                        tabIndex={-1}
                        aria-label="Picture for the wallpaper"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                    <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => file.current?.click()}>
                        <Icon name="image" className="ic-sm" />
                        {busy ? 'Uploading…' : own ? 'Use another picture' : 'Use your own picture'}
                    </button>
                    {own && (
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm"
                            disabled={busy}
                            onClick={() => router.delete(route('wallpaper.picture.destroy'), keep)}
                        >
                            Remove your picture
                        </button>
                    )}
                    <span className="card-sub">JPG, PNG or WebP, up to 8 MB</span>
                </div>
                {error && <p className="field-error">{error}</p>}
            </div>
        </SettingsCard>
    );
}
