import { useEffect, useRef, useState } from 'react';
import { Icon } from './icon';

/** A "+ Link …" button that opens a small search list: type a few letters, pick one. */
export function Picker({
    label,
    find,
    options,
    taken = [],
    onPick,
    none = 'Nothing matches.',
}: {
    /** The button: "Link task", "Add person" */
    label: string;
    /** The search box's placeholder: "Find a task" */
    find: string;
    options: { id: number; title: string }[];
    /** Already linked: left out of the list. */
    taken?: number[];
    onPick: (id: number) => void;
    none?: string;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [hi, setHi] = useState(0);
    const box = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (!open) return;
        input.current?.focus();
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('pointerdown', onDown, true);
        return () => document.removeEventListener('pointerdown', onDown, true);
    }, [open]);
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    const matches = options.filter((o) => !taken.includes(o.id) && words.every((w) => o.title.toLowerCase().includes(w))).slice(0, 8);
    const pick = (id: number) => {
        onPick(id);
        setOpen(false);
        setQ('');
    };
    return (
        <div className="note-pick" ref={box}>
            <button type="button" className="note-add" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
                <Icon name="plus" className="ic-xs" />
                {label}
            </button>
            {open && (
                <div className="note-pick-pop" role="dialog" aria-label={label}>
                    <input
                        ref={input}
                        className="input"
                        value={q}
                        placeholder={find}
                        aria-label={find}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setHi(0);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                                e.stopPropagation();
                                return setOpen(false);
                            }
                            if (e.key === 'ArrowDown') setHi((h) => Math.min(h + 1, matches.length - 1));
                            else if (e.key === 'ArrowUp') setHi((h) => Math.max(h - 1, 0));
                            else if (e.key === 'Enter' && matches[hi]) pick(matches[hi].id);
                            else return;
                            e.preventDefault();
                        }}
                    />
                    <ul role="listbox" aria-label={label}>
                        {matches.length === 0 && <li className="note-pick-none">{none}</li>}
                        {matches.map((m, i) => (
                            <li key={m.id} role="option" aria-selected={i === hi}>
                                <button type="button" className={i === hi ? 'is-hi' : ''} onMouseEnter={() => setHi(i)} onClick={() => pick(m.id)}>
                                    {m.title}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
