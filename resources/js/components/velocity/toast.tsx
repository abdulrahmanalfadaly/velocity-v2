import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Undo = NonNullable<VelocityShared['flash']['undo']>;
export type ToastState = { text: string; undo: Undo | null } | null;

/** What each kind of Undo calls. The server names the action; this maps it to a route. */
const UNDO: Record<string, { method: 'post' | 'patch' | 'put' | 'delete'; route: string }> = {
    reopen: { method: 'post', route: 'tasks.reopen' },
    complete: { method: 'post', route: 'tasks.complete' },
    restore: { method: 'post', route: 'tasks.restore' },
    'delete-task': { method: 'delete', route: 'tasks.destroy' },
    'revert-task': { method: 'post', route: 'tasks.revert' },
    unpromote: { method: 'post', route: 'tasks.unpromote' },
    'bulk-undo': { method: 'post', route: 'tasks.bulk-undo' },
    'restore-step': { method: 'post', route: 'steps.restore' },
    'restore-file': { method: 'post', route: 'files.restore' },
    'focus-cancel': { method: 'post', route: 'focus.cancel' },
    'delete-event': { method: 'delete', route: 'events.destroy' },
    'restore-event': { method: 'post', route: 'events.restore' },
    'patch-event': { method: 'patch', route: 'events.update' },
    'unskip-event': { method: 'post', route: 'events.unskip' },
    'focus-resume': { method: 'post', route: 'focus.undo-stop' },
    'remove-files': { method: 'post', route: 'files.remove-many' },
    'restore-milestone': { method: 'post', route: 'milestones.restore' },
    'restore-template': { method: 'post', route: 'templates.restore' },
    'delete-template': { method: 'delete', route: 'templates.destroy' },
    'patch-template': { method: 'patch', route: 'templates.update' },
    'archive-project': { method: 'post', route: 'projects.archive' },
    'unarchive-project': { method: 'post', route: 'projects.unarchive' },
    'delete-project': { method: 'delete', route: 'projects.destroy' },
    'patch-project': { method: 'patch', route: 'projects.update' },
    'archive-goal': { method: 'post', route: 'goals.archive' },
    'unarchive-goal': { method: 'post', route: 'goals.unarchive' },
    'delete-goal': { method: 'delete', route: 'goals.destroy' },
    'patch-goal': { method: 'patch', route: 'goals.update' },
    'habit-count': { method: 'post', route: 'habits.check' },
    'put-weights': { method: 'put', route: 'weights.update' },
    unplan: { method: 'post', route: 'planner.unplan' },
    'remove-block': { method: 'delete', route: 'blocks.destroy' },
    'restore-block': { method: 'post', route: 'blocks.restore' },
    'patch-block': { method: 'patch', route: 'blocks.update' },
    'put-hours': { method: 'put', route: 'planner.hours' },
};

export function runUndo(u: Undo) {
    const target = UNDO[u.action];
    if (!target) return;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- whatever the server asked to get back, sent as it is
    const data = (u.data ?? {}) as Record<string, any>;
    const url = route(target.route, u.id);
    const opts = { preserveScroll: true, preserveState: true };
    if (target.method === 'delete') router.delete(url, { ...opts, data });
    else router[target.method](url, data, opts);
}

/** One-shot messages from the server ("Done: …", with Undo), plus any the screen raises itself. */
export function useToast() {
    const { props } = usePage<VelocityShared>();
    const [toast, setToast] = useState<ToastState>(null);
    useEffect(() => {
        if (props.flash?.toast) setToast({ text: props.flash.toast, undo: props.flash.undo });
    }, [props.flash]);
    // Messages with an Undo stay a little longer.
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), toast.undo ? 8000 : 5000);
        return () => clearTimeout(t);
    }, [toast]);
    // Ctrl+Z (outside a text box) undoes the last thing while its message is still showing.
    useEffect(() => {
        if (!toast?.undo) return;
        const onKey = (e: KeyboardEvent) => {
            if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
            if (e.target instanceof Element && e.target.closest('input, textarea, select, [contenteditable="true"]')) return;
            e.preventDefault();
            const u = toast.undo!;
            setToast(null);
            runUndo(u);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [toast]);
    return [toast, setToast] as const;
}

export function Toast({ toast, onDone }: { toast: ToastState; onDone: () => void }) {
    if (!toast) return null;
    return (
        <div className="toast" role="status">
            <span>{toast.text}</span>
            {toast.undo && (
                <button
                    type="button"
                    title="Undo (Ctrl+Z)"
                    onClick={() => {
                        const u = toast.undo!;
                        onDone();
                        runUndo(u);
                    }}
                >
                    Undo
                </button>
            )}
        </div>
    );
}
