import { GoalDialog, type GoalValues } from '@/components/velocity/forms';
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
    // null = closed, 'new' = new goal, or the goal being edited.
    const [dialog, setDialog] = useState<null | 'new' | GoalValues>(null);
    const goals = useRef(props.goals);
    goals.current = props.goals;

    useEffect(() => {
        if (!ref.current) return;
        const reload = () => router.reload({ only: ['goals', 'inbox', 'nextId', 'totalRanked', 'nav'] });
        const opts = { preserveScroll: true, preserveState: true, onSuccess: reload };
        return mountGoalsMap(
            ref.current,
            {
                goals: props.goals,
                inbox: props.inbox,
                nextId: props.nextId,
                totalRanked: props.totalRanked,
                focus: props.focus,
                userName: props.auth.user?.name ?? 'You',
            },
            {
                clock: Clock.from(props.clock),
                onDone: (id) => router.post(route('tasks.complete', id), {}, opts),
                onPromote: (id) => router.post(route('steps.promote', id), {}, opts),
                onToggleMenu: () => setMenu((m) => !m),
                onNewGoal: () => setDialog('new'),
                onEditGoal: (id) => {
                    const g = goals.current.find((x) => x.id === id);
                    if (g) setDialog({ id: g.id, title: g.title, why: g.why, target: g.target, color: g.color, icon: g.icon });
                },
            },
        );
        // Rebuild the map whenever the data behind it changes.
    }, [props.goals, props.inbox, props.nextId]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <VelocityShell title="Goals map" section="tasks" active="goals" canvas hideSidebar={!menu}>
            <div className="gm" id="goals-map" ref={ref} aria-label="Goals map" />
            {props.goals.length === 0 && props.inbox.length === 0 && (
                <div className="gm-empty card">
                    <p>No goals yet.</p>
                    <button className="btn btn-primary" onClick={() => setDialog('new')}>
                        New goal
                    </button>
                </div>
            )}
            {dialog && <GoalDialog goal={dialog === 'new' ? undefined : dialog} onClose={() => setDialog(null)} />}
        </VelocityShell>
    );
}
