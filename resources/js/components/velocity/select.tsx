import { type KeyboardEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './icon';

export interface SelectOption {
    value: string;
    label: string;
    /** Options with the same group are listed under its name. */
    group?: string;
}

interface Place {
    left: number;
    top?: number;
    bottom?: number;
    width: number;
    maxHeight: number;
}

/**
 * Velocity's dropdown, in place of the browser's own: a button showing the choice,
 * and a short list (with a search box when it's long) that opens above everything,
 * so a panel or dialog never cuts it off. Arrows move, Enter picks, Escape closes,
 * a letter jumps to the first choice starting with it.
 *
 * With a value that isn't one of the options ("Move to…"), it works as a menu:
 * every pick calls onChange.
 */
export function Select({
    id,
    value,
    options,
    onChange,
    label,
    className = 'input',
    placeholder = 'Choose…',
    search,
    trigger,
    title,
    disabled = false,
}: {
    id?: string;
    value: string;
    options: SelectOption[];
    onChange: (value: string) => void;
    /** Read out by screen readers when no <label> points at `id`. */
    label?: string;
    className?: string;
    /** Shown while nothing is chosen ("Move to…"). */
    placeholder?: string;
    /** A search box on top; on by default for lists longer than 8. */
    search?: boolean;
    /** The button's own content instead of the choice (a ▾ next to another button). */
    trigger?: ReactNode;
    title?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [hi, setHi] = useState(0);
    const [place, setPlace] = useState<Place | null>(null);
    const btn = useRef<HTMLButtonElement>(null);
    const pop = useRef<HTMLDivElement>(null);
    const listId = useId();
    const withSearch = search ?? options.length > 8;
    const current = options.find((o) => o.value === value);
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    const shown = words.length ? options.filter((o) => words.every((w) => `${o.label} ${o.group ?? ''}`.toLowerCase().includes(w))) : options;

    const show = () => {
        const r = btn.current?.getBoundingClientRect();
        if (disabled || !r) return;
        const below = window.innerHeight - r.bottom - 8;
        const above = r.top - 8;
        const up = below < 240 && above > below;
        const width = Math.max(r.width, 180);
        setPlace({
            left: Math.max(8, Math.min(r.left, window.innerWidth - width - 8)),
            width,
            ...(up ? { bottom: window.innerHeight - r.top + 4 } : { top: r.bottom + 4 }),
            maxHeight: Math.max(160, Math.min(340, (up ? above : below) - 4)),
        });
        setQ('');
        setHi(
            Math.max(
                0,
                options.findIndex((o) => o.value === value),
            ),
        );
        setOpen(true);
    };
    const close = (refocus = true) => {
        setOpen(false);
        if (refocus) btn.current?.focus();
    };
    const pick = (o: SelectOption | undefined) => {
        if (!o) return;
        close();
        if (o.value !== value || !current) onChange(o.value);
    };

    // Closes on a click elsewhere, and when the page scrolls or resizes under it.
    useEffect(() => {
        if (!open) return;
        const away = (e: Event) => {
            const t = e.target as Node;
            if (!btn.current?.contains(t) && !pop.current?.contains(t)) close(false);
        };
        const gone = () => close(false);
        document.addEventListener('pointerdown', away, true);
        document.addEventListener('scroll', away, true);
        window.addEventListener('resize', gone);
        return () => {
            document.removeEventListener('pointerdown', away, true);
            document.removeEventListener('scroll', away, true);
            window.removeEventListener('resize', gone);
        };
    }, [open]);
    useEffect(() => {
        if (open && withSearch) pop.current?.querySelector('input')?.focus();
    }, [open, withSearch]);
    useEffect(() => {
        if (open) document.getElementById(`${listId}-${hi}`)?.scrollIntoView({ block: 'nearest' });
    }, [open, hi, listId]);

    const onKey = (e: KeyboardEvent) => {
        const move = (to: number) => {
            e.preventDefault();
            if (!open) return show();
            setHi(Math.max(0, Math.min(shown.length - 1, to)));
        };
        if (e.key === 'ArrowDown') move(hi + 1);
        else if (e.key === 'ArrowUp') move(hi - 1);
        else if (e.key === 'Home' && open) move(0);
        else if (e.key === 'End' && open) move(shown.length - 1);
        else if (e.key === 'Enter' || (e.key === ' ' && !withSearch)) {
            e.preventDefault();
            if (open) pick(shown[hi]);
            else show();
        } else if (e.key === 'Escape' && open) {
            // Only the list closes, not the dialog or panel it sits in.
            e.preventDefault();
            e.stopPropagation();
            close();
        } else if (e.key === 'Tab' && open) {
            close(false);
        } else if (open && !withSearch && e.key.length === 1 && /\S/.test(e.key)) {
            const k = e.key.toLowerCase();
            const i = shown.findIndex((o, n) => n > hi && o.label.toLowerCase().startsWith(k));
            const j = i >= 0 ? i : shown.findIndex((o) => o.label.toLowerCase().startsWith(k));
            if (j >= 0) setHi(j);
        }
    };

    let group: string | undefined;
    return (
        <>
            <button
                ref={btn}
                id={id}
                type="button"
                className={`sel ${className}${current ? '' : ' is-placeholder'}${trigger ? ' has-trigger' : ''}`}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                aria-label={label ? (current && !trigger ? `${label}: ${current.label}` : label) : undefined}
                title={title}
                disabled={disabled}
                onClick={() => (open ? close() : show())}
                onKeyDown={onKey}
            >
                {trigger ?? (
                    <>
                        <span className="sel-value">{current?.label ?? placeholder}</span>
                        <Icon name="chevD" className="ic-xs sel-chev" />
                    </>
                )}
            </button>
            {open &&
                place &&
                createPortal(
                    <div
                        ref={pop}
                        className="sel-pop"
                        style={{ left: place.left, top: place.top, bottom: place.bottom, minWidth: place.width, maxHeight: place.maxHeight }}
                    >
                        {withSearch && (
                            <input
                                className="input sel-search"
                                value={q}
                                placeholder="Find…"
                                aria-label={`Find ${label ?? 'a choice'}`}
                                aria-controls={listId}
                                aria-activedescendant={shown[hi] ? `${listId}-${hi}` : undefined}
                                onChange={(e) => {
                                    setQ(e.target.value);
                                    setHi(0);
                                }}
                                onKeyDown={onKey}
                            />
                        )}
                        <ul id={listId} role="listbox" aria-label={label}>
                            {shown.map((o, i) => {
                                const head = o.group && o.group !== group ? o.group : null;
                                group = o.group;
                                return [
                                    head && (
                                        <li key={`g-${head}`} role="presentation" className="sel-group">
                                            {head}
                                        </li>
                                    ),
                                    <li
                                        key={o.value}
                                        id={`${listId}-${i}`}
                                        role="option"
                                        aria-selected={o.value === value}
                                        className={i === hi ? 'is-hi' : undefined}
                                        onMouseEnter={() => setHi(i)}
                                        onMouseDown={(e) => {
                                            e.preventDefault(); // keep focus where it is until the pick
                                            pick(o);
                                        }}
                                    >
                                        <span>{o.label}</span>
                                        {o.value === value && <Icon name="check" className="ic-xs" />}
                                    </li>,
                                ];
                            })}
                            {shown.length === 0 && <li className="sel-none">Nothing matches.</li>}
                        </ul>
                    </div>,
                    document.body,
                )}
        </>
    );
}
