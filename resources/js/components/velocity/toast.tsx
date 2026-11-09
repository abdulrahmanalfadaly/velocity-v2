import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type ToastState = { text: string; undo: VelocityShared['flash']['undo'] } | null;

/** One-shot messages from the server ("Done: …", with Undo), plus any the screen raises itself. */
export function useToast() {
    const { props } = usePage<VelocityShared>();
    const [toast, setToast] = useState<ToastState>(null);
    useEffect(() => {
        if (props.flash?.toast) setToast({ text: props.flash.toast, undo: props.flash.undo });
    }, [props.flash]);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), 5000);
        return () => clearTimeout(t);
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
                    onClick={() => {
                        const u = toast.undo!;
                        onDone();
                        router.post(route(u.action === 'reopen' ? 'tasks.reopen' : 'tasks.restore', u.id), {}, { preserveScroll: true });
                    }}
                >
                    Undo
                </button>
            )}
        </div>
    );
}
