import { Icon } from '@/components/velocity/icon';
import { type Appearance as Theme, useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import type { VelocityShared } from '@/types/velocity';
import { Head, router, usePage } from '@inertiajs/react';
import { type CSSProperties, useEffect, useRef, useState } from 'react';

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
                {personal && props.cards && <TaskCards cards={props.cards} wall={props.wallpaper ?? null} />}
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

const CARDS_START = { clear: 6, blur: 8 };
const SAMPLE: [string, string, number][] = [
    ['Method chapter', 'Final Year Project · 3 h', 82.4],
    ['Quiz revision', 'Data Mining · 2 h', 61.0],
    ['Groceries', 'Home · 30 min', 43.1],
];

/**
 * Cards (every card on the pages, task cards first): how much of the wallpaper shows through them, and how blurred it is. The whole app follows as
 * you slide, and the sample shows it over your wallpaper; saved on your account a moment after you stop.
 */
function TaskCards({ cards, wall }: { cards: { clear: number; blur: number }; wall: { name: string; url: string | null } | null }) {
    const [look, setLook] = useState(cards);
    const saved = useRef(cards);
    useEffect(() => {
        const app = document.querySelector<HTMLElement>('.app');
        app?.style.setProperty('--card-clear', String(look.clear / 100));
        app?.style.setProperty('--card-blur', `${look.blur}px`);
        if (look.clear === saved.current.clear && look.blur === saved.current.blur) return;
        const t = setTimeout(() => {
            saved.current = look;
            router.put(route('cards.update'), look, keep);
        }, 400);
        return () => clearTimeout(t);
    }, [look]);
    const atStart = look.clear === CARDS_START.clear && look.blur === CARDS_START.blur;

    return (
        <SettingsCard title="Cards">
            <div className="settings-form cards-look">
                <div className="cards-sliders">
                    <div className="cards-slider">
                        <label className="cards-slider-head" htmlFor="card-clear">
                            <span>See-through</span>
                            <output htmlFor="card-clear">{look.clear}%</output>
                        </label>
                        <input
                            id="card-clear"
                            type="range"
                            min={0}
                            max={80}
                            step={1}
                            value={look.clear}
                            onChange={(e) => setLook({ ...look, clear: Number(e.target.value) })}
                        />
                    </div>
                    <div className="cards-slider">
                        <label className="cards-slider-head" htmlFor="card-blur">
                            <span>Blur</span>
                            <output htmlFor="card-blur">{look.blur} px</output>
                        </label>
                        <input
                            id="card-blur"
                            type="range"
                            min={0}
                            max={30}
                            step={1}
                            value={look.blur}
                            onChange={(e) => setLook({ ...look, blur: Number(e.target.value) })}
                        />
                    </div>
                    <div className="wall-own">
                        <button type="button" className="btn btn-ghost btn-sm" disabled={atStart} onClick={() => setLook(CARDS_START)}>
                            Back to 6% · 8 px
                        </button>
                        {!wall && <span className="card-sub">Shows over a wallpaper</span>}
                    </div>
                </div>
                <div
                    className="cards-preview"
                    data-wall={wall?.name}
                    aria-hidden="true"
                    style={
                        {
                            ...(wall?.url ? { '--wall-img': `url("${wall.url}")` } : {}),
                            '--card-clear': look.clear / 100,
                            '--card-blur': `${look.blur}px`,
                        } as CSSProperties
                    }
                >
                    <ol className="rank-list is-ranked">
                        {SAMPLE.map(([title, sub, score], i) => (
                            <li key={title} className="row">
                                <span className="check" />
                                <div>
                                    <div className="row-title">
                                        {i + 1}. {title}
                                    </div>
                                    <div className="row-sub">
                                        <span>{sub}</span>
                                    </div>
                                </div>
                                <span className="score-num">{score.toFixed(1)}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            </div>
        </SettingsCard>
    );
}
