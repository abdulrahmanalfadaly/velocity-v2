import { mountGoalsMap, type MapData } from '@/features/goals-map/engine';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Props extends VelocityShared, Omit<MapData, 'userName'> {}

/** The whole picture: goals → projects → tasks → steps, as one living mind map. */
export default function GoalsMap() {
    const { props } = usePage<Props>();
    const ref = useRef<HTMLDivElement>(null);
    const [menu, setMenu] = useState(false);

    useEffect(() => {
        if (!ref.current) return;
        const reload = () => router.reload({ only: ['goals', 'inbox', 'nextId', 'totalRanked', 'nav'] });
        return mountGoalsMap(
            ref.current,
            { goals: props.goals, inbox: props.inbox, nextId: props.nextId, totalRanked: props.totalRanked, focus: props.focus, userName: props.auth.user?.name ?? 'You' },
            {
                clock: Clock.from(props.clock),
                onDone: (id) => router.post(route('tasks.complete', id), {}, { preserveScroll: true, onSuccess: reload }),
                onPromote: (id) => router.post(route('steps.promote', id), {}, { preserveScroll: true, onSuccess: reload }),
                onToggleMenu: () => setMenu((m) => !m),
            },
        );
        // Rebuild the map whenever the data behind it changes.
    }, [props.goals, props.inbox, props.nextId]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <VelocityShell title="Goals map" section="tasks" active="goals" canvas hideSidebar={!menu}>
            <div className="gm" id="goals-map" ref={ref} aria-label="Goals map" />
        </VelocityShell>
    );
}
