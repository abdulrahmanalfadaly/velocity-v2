import { Icon } from '@/components/velocity/icon';
import { type Found, highlight, KIND_ICON } from '@/components/velocity/search-palette';
import VelocityShell from '@/layouts/velocity-shell';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

interface Props extends VelocityShared {
    q: string;
    groups: { label: string; items: Found[] }[];
}

/** Every match for what was typed in Ctrl K, with the matching words of notes. */
export default function SearchResults() {
    const { props } = usePage<Props>();
    const [q, setQ] = useState(props.q);
    const count = props.groups.reduce((n, g) => n + g.items.length, 0);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('search.all', { q: q.trim() }), {}, { preserveState: true });
    };
    return (
        <VelocityShell title={props.q ? `“${props.q}”` : 'Search'} section="search">
            <div className="main-inner sr-page">
                <header className="page-head">
                    <h1 className="h1">
                        Search <span className="count">{props.q.length >= 2 ? `${count} found` : ''}</span>
                    </h1>
                </header>
                <form className="notes-search sr-box" onSubmit={submit} role="search">
                    <Icon name="search" className="ic-sm" />
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search everything"
                        aria-label="Search everything"
                        autoFocus
                    />
                </form>
                {props.q.length >= 2 && count === 0 && <p className="sr-none">Nothing found for “{props.q}”.</p>}
                {props.groups.map((g) => (
                    <section key={g.label} className="card sr-group" aria-label={g.label}>
                        <h2 className="rv-title">
                            {g.label} <span className="rv-count">{g.items.length}</span>
                        </h2>
                        <ul>
                            {g.items.map((f) => (
                                <li key={`${f.kind}${f.id}`}>
                                    <Link href={f.url} className="sr-item">
                                        <Icon name={KIND_ICON[f.kind]} className="ic-sm" />
                                        <span>
                                            <b>{highlight(f.title, props.q)}</b>
                                            <small>{f.sub}</small>
                                            {f.snippet && <em>{highlight(f.snippet, props.q)}</em>}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </VelocityShell>
    );
}
